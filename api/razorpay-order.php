<?php
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false, "error" => "method"]);
  exit;
}

$cfg = require __DIR__ . "/razorpay-config.php";
$payload = json_encode([
  "amount" => (int) $cfg["amount"],
  "currency" => $cfg["currency"],
  "receipt" => "kundli_" . time(),
  "payment_capture" => 1,
  "notes" => ["product" => "kundli_pro"],
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
$err = curl_error($ch);
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

echo json_encode(["ok" => true, "orderId" => $id, "amount" => (int) $cfg["amount"]]);
