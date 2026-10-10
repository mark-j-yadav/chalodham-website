<?php

function cd_allow_origin(): bool
{
  $origin = $_SERVER["HTTP_ORIGIN"] ?? "";
  if ($origin === "") {
    return true;
  }
  $host = parse_url($origin, PHP_URL_HOST);
  return in_array($host, ["chalodham.in", "www.chalodham.in", "localhost", "127.0.0.1"], true);
}

function cd_rate_limit(string $bucket, int $max, int $window): bool
{
  $ip = $_SERVER["REMOTE_ADDR"] ?? "x";
  $file = sys_get_temp_dir() . "/cd_" . $bucket . "_" . hash("sha256", $ip);
  $now = time();
  $hits = [];
  if (is_file($file)) {
    $decoded = json_decode((string) file_get_contents($file), true);
    if (is_array($decoded)) {
      $hits = $decoded;
    }
  }
  $hits = array_values(array_filter($hits, function ($t) use ($now, $window) {
    return is_int($t) && $t > $now - $window;
  }));
  if (count($hits) >= $max) {
    return false;
  }
  $hits[] = $now;
  file_put_contents($file, json_encode($hits), LOCK_EX);
  return true;
}

function cd_amount_allowed(int $paise): bool
{
  if ($paise === 2900 || $paise === 9900) {
    return true;
  }
  return $paise >= 49900 && $paise <= 49900 * 5 && $paise % 49900 === 0;
}
