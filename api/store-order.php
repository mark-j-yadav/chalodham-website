<?php
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false, "error" => "method"]);
  exit;
}

$prices = [
  "shri-yantra" => 1499,
  "kuber-yantra" => 899,
  "mahalakshmi-yantra" => 999,
  "ganesh-yantra" => 749,
  "hanuman-yantra" => 799,
  "navgraha-yantra" => 1299,
  "mahamrityunjaya-yantra" => 1099,
  "saraswati-yantra" => 849,
  "gayatri-yantra" => 849,
  "baglamukhi-yantra" => 1199,
  "vastu-yantra" => 999,
  "durga-yantra" => 949,
  "rudraksha-mala" => 1299,
  "tulsi-mala" => 349,
  "sphatik-mala" => 799,
  "chandan-mala" => 899,
  "haldi-mala" => 399,
  "kamalgatta-mala" => 549,
  "moti-mala" => 1499,
  "karungali-mala" => 1099,
  "vaijanti-mala" => 649,
  "lal-chandan-mala" => 1199,
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
    "name" => $clip($json["name"] ?? "", 80),
    "phone" => $clip($json["phone"] ?? "", 20),
    "city" => $clip($json["city"] ?? "", 40),
    "address" => $clip($json["address"] ?? "", 200),
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
