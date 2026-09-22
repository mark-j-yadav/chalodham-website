<?php
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false]);
  exit;
}

$cfg = require __DIR__ . "/razorpay-config.php";
$raw = file_get_contents("php://input");
$json = json_decode($raw ?: "{}", true);
$order = is_array($json) ? (string) ($json["razorpay_order_id"] ?? "") : "";
$payment = is_array($json) ? (string) ($json["razorpay_payment_id"] ?? "") : "";
$signature = is_array($json) ? (string) ($json["razorpay_signature"] ?? "") : "";

if ($order === "" || $payment === "" || $signature === "") {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "missing"]);
  exit;
}

$expected = hash_hmac("sha256", $order . "|" . $payment, $cfg["key_secret"]);
$ok = hash_equals($expected, $signature);
if (!$ok) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "bad_signature"]);
  exit;
}

echo json_encode(["ok" => true]);
