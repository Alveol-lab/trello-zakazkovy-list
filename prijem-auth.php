<?php
/* =========================================================
   Přihlášení k formuláři příjmu materiálu – společná knihovna
   Tajné údaje (login, hash hesla, klíč pro Apps Script) leží
   MIMO webovou složku i mimo veřejné repo:
     /web/htdocs/www.denteo.space/home/autolab-prijem.json
   Přihlášení drží podepsaná cookie (HMAC), bez PHP session.
   Změna hesla tím odhlásí všechna zařízení.
   ========================================================= */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }

const PRIJEM_SECRET_FILE = __DIR__ . '/../../autolab-prijem.json';
const PRIJEM_SETUP_FILE  = __DIR__ . '/../../autolab-prijem-setup.txt';
const PRIJEM_COOKIE      = 'prijem_auth';
const PRIJEM_DNI         = 30;   // jak dlouho přihlášení vydrží na jednom zařízení

function prijem_secret(): ?array {
  if (!is_file(PRIJEM_SECRET_FILE)) return null;
  $s = json_decode((string) file_get_contents(PRIJEM_SECRET_FILE), true);
  return is_array($s) && !empty($s['user']) && !empty($s['hash']) && !empty($s['key']) && !empty($s['csecret']) ? $s : null;
}

function prijem_sign(array $s, int $exp): string {
  return hash_hmac('sha256', $s['user'] . '|' . $exp, $s['csecret'] . $s['hash']);
}

function prijem_cookie_set(string $val, int $exp): void {
  setcookie(PRIJEM_COOKIE, $val, [
    'expires' => $exp, 'path' => '/zakazkovy-list/', 'secure' => true,
    'httponly' => true, 'samesite' => 'Strict',
  ]);
}

function prijem_logged(): bool {
  $s = prijem_secret();
  $c = $_COOKIE[PRIJEM_COOKIE] ?? '';
  if (!$s || !preg_match('/^(\d{10})\.([0-9a-f]{64})$/', $c, $m)) return false;
  if ((int) $m[1] < time()) return false;
  return hash_equals(prijem_sign($s, (int) $m[1]), $m[2]);
}

function prijem_login(string $user, string $pass): bool {
  $s = prijem_secret();
  if (!$s) return false;
  $ok = hash_equals(mb_strtolower($s['user']), mb_strtolower(trim($user))) & password_verify($pass, $s['hash']);
  if (!$ok) return false;
  $exp = time() + PRIJEM_DNI * 86400;
  prijem_cookie_set($exp . '.' . prijem_sign($s, $exp), $exp);
  return true;
}

function prijem_logout(): void { prijem_cookie_set('', time() - 3600); }

function prijem_nocache(): void {
  header('Cache-Control: no-cache, no-store, must-revalidate');
  header('Pragma: no-cache');
  header('Expires: 0');
  header('X-Frame-Options: DENY');
  header('Referrer-Policy: same-origin');
}
