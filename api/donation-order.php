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
if (!cd_rate_limit("donation_order", 8, 600)) {
  http_response_code(429);
  echo json_encode(["ok" => false, "error" => "slow"]);
  exit;
}

$rashis = [
  "mesh", "vrishabh", "mithun", "karka", "simha", "kanya",
  "tula", "vrishchik", "dhanu", "makar", "kumbh", "meen",
];

$json = json_decode(file_get_contents("php://input") ?: "{}", true);
if (!is_array($json)) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "bad"]);
  exit;
}

$name = preg_replace("/\s+/", " ", trim((string) ($json["name"] ?? "")));
$phone = preg_replace("/\D/", "", (string) ($json["phone"] ?? ""));
$rashi = (string) ($json["rashi"] ?? "");
$rupees = (int) ($json["amount"] ?? 0);

if (
  strlen($name) < 2
  || strlen($name) > 60
  || !preg_match("/^[6-9][0-9]{9}$/", $phone)
  || !in_array($rashi, $rashis, true)
  || $rupees < 1
  || $rupees > 200000
) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "bad"]);
  exit;
}

$cfg = require __DIR__ . "/razorpay-config.php";
$payload = json_encode([
  "amount" => $rupees * 100,
  "currency" => "INR",
  "receipt" => "daan_" . time(),
  "payment_capture" => 1,
  "notes" => [
    "product" => "donation",
    "temple" => "panchmukhi-balaji",
    "name" => substr($name, 0, 60),
    "phone" => $phone,
    "rashi" => $rashi,
    "amount" => (string) $rupees,
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
