<?php
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");
header("X-Content-Type-Options: nosniff");
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false, "error" => "method"]);
  exit;
}

require __DIR__ . "/guard.php";
if (!cd_allow_origin()) {
  http_response_code(403);
  echo json_encode(["ok" => false, "error" => "origin"]);
  exit;
}
if (!cd_rate_limit("store_order", 8, 600)) {
  http_response_code(429);
  echo json_encode(["ok" => false, "error" => "slow"]);
  exit;
}

$prices = [
  "7-mukhi-rudraksha-108" => 499,
];

$json = json_decode(file_get_contents("php://input") ?: "{}", true);
$items = is_array($json) && isset($json["items"]) && is_array($json["items"]) ? $json["items"] : [];
if (count($items) < 1 || count($items) > 12) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "bad_cart"]);
  exit;
}

$rupees = 0;
$itemNote = [];
foreach ($items as $item) {
  $slug = is_array($item) ? (string) ($item["slug"] ?? "") : "";
  $qty = is_array($item) ? (int) ($item["qty"] ?? 0) : 0;
  if (!isset($prices[$slug]) || $qty < 1 || $qty > 5) {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "bad_cart"]);
    exit;
  }
  $rupees += $prices[$slug] * $qty;
  $itemNote[] = $slug . ":" . $qty;
}

$pin = preg_replace("/\D/", "", (string) ($json["pin"] ?? ""));
$house = trim((string) ($json["house"] ?? ""));
$city = trim((string) ($json["city"] ?? ""));
$state = trim((string) ($json["state"] ?? ""));
if (strlen($pin) !== 6 || strlen(trim((string) ($json["name"] ?? ""))) < 2 || strlen($house) < 3 || strlen($city) < 2 || strlen($state) < 2) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "bad_address"]);
  exit;
}

$clip = function ($value, $max) {
  $value = preg_replace("/\s+/", " ", trim((string) $value));
  return substr($value, 0, $max);
};

$cfg = require __DIR__ . "/razorpay-config.php";
$payload = json_encode([
  "amount" => $rupees * 100,
  "currency" => "INR",
  "receipt" => "store_" . time(),
  "payment_capture" => 1,
  "notes" => [
    "product" => "store",
    "name" => $clip($json["name"] ?? "", 60),
    "phone" => $clip($json["phone"] ?? "", 20),
    "house" => $clip($house, 120),
    "area" => $clip(trim(($json["area"] ?? "") . " " . ($json["landmark"] ?? "")), 120),
    "place" => $clip($city . ", " . $state . " " . $pin, 80),
    "items" => $clip(implode(",", $itemNote), 240),
  ],
]);

$ch = curl_init("https://api.razorpay.com/v1/orders");
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST => true,
  CURLOPT_POSTFIELDS => $payload,
  CURLOPT_USERPWD => $cfg["key_id"] . ":" . $cfg["key_secret"],
  CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
  CURLOPT_TIMEOUT => 20,
]);
$body = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($body === false || $code < 200 || $code >= 300) {
  http_response_code(502);
  echo json_encode(["ok" => false, "error" => "order_failed"]);
  exit;
}

$data = json_decode($body, true);
$id = is_array($data) ? ($data["id"] ?? "") : "";
if (!$id) {
  http_response_code(502);
  echo json_encode(["ok" => false, "error" => "order_failed"]);
  exit;
}

echo json_encode(["ok" => true, "orderId" => $id, "amount" => $rupees * 100]);
