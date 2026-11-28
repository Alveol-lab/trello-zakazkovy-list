<?php
/* Ověření podepsaného Trello JWT (X-Trello-JWT) – sdílené pro fio-api.php.
   Kopie logiky z vykaz-api.php / api.php (stejná keš veřejných klíčů). */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }
const TJ_KEYS_URL  = 'https://api.trello.com/1/resource/jwt-public-keys';
const TJ_KEYS_FILE = __DIR__ . '/../../autolab-trello-keys.json';   // sdílená keš s api.php
const TJ_PLUGIN    = '6a7f5eb6817f162662936fff';                    // Zakázkový list

function tj_b64(string $s): string|false { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true); }
function tj_keys(bool $fresh = false): array {
  if (!$fresh && is_file(TJ_KEYS_FILE) && filemtime(TJ_KEYS_FILE) > time() - 4 * 3600) {
    $k = json_decode((string) file_get_contents(TJ_KEYS_FILE), true);
    if (!empty($k['keys'])) return $k['keys'];
  }
  $c = curl_init(TJ_KEYS_URL);
  curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10]);
  $r = curl_exec($c); $k = is_string($r) ? json_decode($r, true) : null;
  if (!empty($k['keys']) && is_array($k['keys'])) { @file_put_contents(TJ_KEYS_FILE, $r, LOCK_EX); return $k['keys']; }
  $old = is_file(TJ_KEYS_FILE) ? json_decode((string) file_get_contents(TJ_KEYS_FILE), true) : null;
  return $old['keys'] ?? [];
}
function tj_jwt(string $tok, ?string &$why): ?array {
  $p = explode('.', $tok);
  if (count($p) !== 3) { $why = 'tvar'; return null; }
  $h = json_decode((string) tj_b64($p[0]), true); $c = json_decode((string) tj_b64($p[1]), true); $sig = tj_b64($p[2]);
  if (($h['alg'] ?? '') !== 'RS256' || !is_array($c) || $sig === false) { $why = 'hlavička'; return null; }
  $ok = false;
  foreach ([false, true] as $fresh) foreach (tj_keys($fresh) as $pem) if (openssl_verify($p[0] . '.' . $p[1], $sig, $pem, OPENSSL_ALGO_SHA256) === 1) { $ok = true; break 2; }
  if (!$ok) { $why = 'podpis'; return null; }
  $now = time();
  if (($c['iss'] ?? '') !== 'trello') { $why = 'iss'; return null; }
  if ((int) ($c['exp'] ?? 0) < $now - 30 || (int) ($c['iat'] ?? 0) > $now + 60) { $why = 'platnost'; return null; }
  return $c;
}

