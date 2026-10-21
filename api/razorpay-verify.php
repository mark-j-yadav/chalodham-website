<?php
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");
header("X-Content-Type-Options: nosniff");
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false]);
  exit;
}

require __DIR__ . "/guard.php";
if (!cd_allow_origin()) {
  http_response_code(403);
  echo json_encode(["ok" => false, "error" => "origin"]);
  exit;
}
if (!cd_rate_limit("verify", 20, 600)) {
  http_response_code(429);
  echo json_encode(["ok" => false, "error" => "slow"]);
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

$ch = curl_init("https://api.razorpay.com/v1/payments/" . rawurlencode($payment));
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_USERPWD => $cfg["key_id"] . ":" . $cfg["key_secret"],
  CURLOPT_TIMEOUT => 20,
]);
$body = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$pay = json_decode($body ?: "{}", true);
$amount = is_array($pay) ? (int) ($pay["amount"] ?? 0) : 0;
$status = is_array($pay) ? (string) ($pay["status"] ?? "") : "";
$paidOrder = is_array($pay) ? (string) ($pay["order_id"] ?? "") : "";
$currency = is_array($pay) ? (string) ($pay["currency"] ?? "") : "";
$notes = is_array($pay) && isset($pay["notes"]) && is_array($pay["notes"]) ? $pay["notes"] : [];
$product = (string) ($notes["product"] ?? "");
if ($product === "" && $paidOrder !== "") {
  $och = curl_init("https://api.razorpay.com/v1/orders/" . rawurlencode($paidOrder));
  curl_setopt_array($och, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD => $cfg["key_id"] . ":" . $cfg["key_secret"],
    CURLOPT_TIMEOUT => 20,
  ]);
  $obody = curl_exec($och);
  $ocode = (int) curl_getinfo($och, CURLINFO_HTTP_CODE);
  curl_close($och);
  $orderRow = json_decode($obody ?: "{}", true);
  $orderNotes = is_array($orderRow) && isset($orderRow["notes"]) && is_array($orderRow["notes"]) ? $orderRow["notes"] : [];
  if ($obody !== false && $ocode >= 200 && $ocode < 300) {
    $product = (string) ($orderNotes["product"] ?? "");
  }
}
$amountOk = $product === "donation"
  ? ($amount >= 100 && $amount <= 20000000 && $amount % 100 === 0)
  : cd_amount_allowed($amount);
if (
  $body === false
  || $code < 200
  || $code >= 300
  || $paidOrder !== $order
  || $currency !== "INR"
  || $status !== "captured"
  || !$amountOk
) {
  http_response_code($status === "authorized" ? 409 : 400);
  echo json_encode(["ok" => false, "error" => $status === "authorized" ? "pending" : "unpaid"]);
  exit;
}

echo json_encode([
  "ok" => true,
  "paymentId" => $payment,
  "orderId" => $order,
  "amountInr" => (int) ($amount / 100),
  "currency" => "INR",
  "eventId" => $payment,
]);
