<?php
/* =========================================================
   Proxy zakázkového listu (Power-Up v Trellu) → Apps Script
   - Kdo volá, dokazuje podepsaný JWT od Trella (t.jwt()),
     posílaný v hlavičce X-Trello-JWT. Podpis RS256 se ověří
     veřejným klíčem Trella, takže se nedá podvrhnout.
   - Pustí jen členy z ACTORS, jen povolené akce a parametry.
   - Ke každému požadavku přidá tajný klíč (PRIJEM_KEY), bez
     kterého Apps Script po zapnutí kontroly nic neudělá.
   - Prohlížeč adresu Apps Scriptu ani klíč nevidí.
   ========================================================= */
require __DIR__ . '/prijem-auth.php';          // prijem_secret() – klíč pro Apps Script
require __DIR__ . '/mat-lib.php';             // krok 5: karty MAT v SQL (přepínač autolab-mat.json)
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

const API_GAS_URL = 'https://script.google.com/macros/s/AKfycbyoF5oQtNIh8YktGX9lNcwj1OwSKbthQovlYIr-vbi2fJ6CN1heJYJxnB96zTVZDQpz/exec';
const API_KEYS_URL  = 'https://api.trello.com/1/resource/jwt-public-keys';
const API_KEYS_FILE = __DIR__ . '/../../autolab-trello-keys.json';   // keš klíčů mimo web
const API_LOG       = __DIR__ . '/../../autolab-api.log';            // kdo / co / výsledek, bez obsahu zakázky
const API_KEYS_TTL  = 4 * 3600;

/* ID Power-Upu v Trellu – JWT jiného Power-Upu se odmítne. */
const API_PLUGIN = '6a7f5eb6817f162662936fff';   // Zakázkový list (zjištěno z logu 23. 11. 2026)

/* technici: Trello účet = list v EVIDENCE (stejné jako MEMBERS v section3.html) */
const API_ACTORS = [
  '588fa9e7e5db691bfe653bce', '611cc39d9beb8e6028ba3ae4', '63cd0523a27f8901400a6dda',
  '63ea51fbb262b19c0189e9b8', '686d1aa4bc747a96bb0fd585', '63e911bcb90ab76e81ad4e6a',
];
/* kdo smí formulář používat: technici + recepce (Marie Vosiková) + Brigit Denteo */
const API_USERS = [...API_ACTORS, '655329144f809c15a1896437', '6b00d5c2c5be2a396f89949e'];

const API_POST_OK = ['writeEvidence', 'clearEvidence', 'rewardSum', 'compliancePdf',
                     'autoDidPreview', 'lotCreate', 'lotCheck', 'lotEnd'];
const API_GET_OK  = ['code', 'ver', 'lot', 'lotq', 'matref', 'matlists',
                     'suggest', 'search', 'typ', 'ord', 'limit_sady', 'limit_kody'];

function api_out(array $o, int $code = 200): void { http_response_code($code); echo json_encode($o, JSON_UNESCAPED_UNICODE); exit; }

function api_log(string $actor, string $what, string $res): void {
  if (is_file(API_LOG) && filesize(API_LOG) > 1048576) @rename(API_LOG, API_LOG . '.1');
  @file_put_contents(API_LOG, date('c') . "\t" . $actor . "\t" . $what . "\t" . $res . "\n", FILE_APPEND | LOCK_EX);
}

function api_b64(string $s): string|false { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true); }

function api_keys(bool $fresh = false): array {
  if (!$fresh && is_file(API_KEYS_FILE) && filemtime(API_KEYS_FILE) > time() - API_KEYS_TTL) {
    $k = json_decode((string) file_get_contents(API_KEYS_FILE), true);
    if (!empty($k['keys'])) return $k['keys'];
  }
  $c = curl_init(API_KEYS_URL);
  curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10]);
  $r = curl_exec($c);
  $k = is_string($r) ? json_decode($r, true) : null;
  if (!empty($k['keys']) && is_array($k['keys'])) { @file_put_contents(API_KEYS_FILE, $r, LOCK_EX); return $k['keys']; }
  $old = is_file(API_KEYS_FILE) ? json_decode((string) file_get_contents(API_KEYS_FILE), true) : null;
  return $old['keys'] ?? [];                   // Trello nedostupné → poslední známé klíče
}

/* vrátí ověřený obsah JWT, jinak null (důvod v $why) */
function api_jwt(string $tok, ?string &$why): ?array {
  $p = explode('.', $tok);
  if (count($p) !== 3) { $why = 'tvar'; return null; }
  $h = json_decode((string) api_b64($p[0]), true);
  $c = json_decode((string) api_b64($p[1]), true);
  $sig = api_b64($p[2]);
  if (($h['alg'] ?? '') !== 'RS256' || !is_array($c) || $sig === false) { $why = 'hlavička'; return null; }
  $ok = false;
  foreach ([false, true] as $fresh) {           // při neúspěchu jednou znovu stáhnout klíče (rotace)
    foreach (api_keys($fresh) as $pem) if (openssl_verify($p[0] . '.' . $p[1], $sig, $pem, OPENSSL_ALGO_SHA256) === 1) { $ok = true; break 2; }
  }
  if (!$ok) { $why = 'podpis'; return null; }
  $now = time();
  if (($c['iss'] ?? '') !== 'trello') { $why = 'iss'; return null; }
  if ((int) ($c['exp'] ?? 0) < $now - 30 || (int) ($c['iat'] ?? 0) > $now + 60) { $why = 'platnost'; return null; }
  return $c;
}

/* ---- ověření volajícího ---- */
$s = prijem_secret();
if (!$s) api_out(['ok' => false, 'error' => 'server: chybí konfigurace'], 500);

$why = '';
$c = api_jwt((string) ($_SERVER['HTTP_X_TRELLO_JWT'] ?? ''), $why);
if (!$c) { api_log('-', 'jwt', 'odmítnuto: ' . $why); api_out(['ok' => false, 'error' => 'neověřený přístup (Trello JWT: ' . $why . ')'], 401); }
$actor = (string) ($c['idMember'] ?? '');
$plug  = (string) ($c['idPlugin'] ?? '');
if (API_PLUGIN !== '' && !hash_equals(API_PLUGIN, $plug)) { api_log($actor, 'plugin ' . $plug, 'odmítnuto'); api_out(['ok' => false, 'error' => 'cizí Power-Up'], 403); }
if (!in_array($actor, API_USERS, true)) { api_log($actor, 'člen', 'odmítnuto'); api_out(['ok' => false, 'error' => 'tento Trello účet nemá do EVIDENCE přístup'], 403); }

function api_gas(string $url, ?string $post, string $actor, string $what, ?array $pridej = null): void {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
    CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 120,          // compliancePdf trvá i desítky sekund
  ]);
  if ($post !== null) {
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/plain;charset=utf-8']);
  }
  $r = curl_exec($ch);
  $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  if ($r === false || $code !== 200) { api_log($actor, $what, 'GAS HTTP ' . $code); api_out(['ok' => false, 'error' => 'Apps Script neodpověděl (HTTP ' . $code . ')'], 502); }
  if (json_decode($r) === null) { api_log($actor, $what, 'GAS neplatná odpověď'); api_out(['ok' => false, 'error' => 'Apps Script vrátil neplatnou odpověď'], 502); }
  api_log($actor, $what, 'ok');
  if ($pridej) { $j = json_decode($r, true); if (is_array($j)) { foreach ($pridej as $k => $v) if (!isset($j[$k])) $j[$k] = $v; echo json_encode($j, JSON_UNESCAPED_UNICODE); exit; } }
  echo $r; exit;
}

$what0 = API_PLUGIN === '' ? ' [plugin ' . $plug . ']' : '';   // dočasně: zjištění ID Power-Upu

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $q = [];
  foreach (API_GET_OK as $k) if (isset($_GET[$k]) && is_string($_GET[$k])) $q[$k] = $_GET[$k];
  if (!$q) api_out(['ok' => false, 'error' => 'nepovolený dotaz'], 400);
  if (isset($q['matlists'])) $q['matlists'] = '1';      // úplný seznam karet MAT je jen pro formulář příjmu
  $q['key'] = $s['key'];
  api_gas(API_GAS_URL . '?' . http_build_query($q), null, $actor, 'GET ' . implode(',', array_diff(array_keys($q), ['key'])) . $what0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $b = json_decode((string) file_get_contents('php://input'), true);
  if (!is_array($b)) api_out(['ok' => false, 'error' => 'neplatný požadavek'], 400);
  $a = (string) ($b['action'] ?? 'writeEvidence');
  if (!in_array($a, API_POST_OK, true)) api_out(['ok' => false, 'error' => 'nepovolená akce'], 400);
  /* memberId určuje list (písmeno TID řádku), může to být i jiný technik
     než přihlášený – tak formulář funguje i dnes. Musí to ale být technik. */
  if (isset($b['memberId']) && !in_array((string) $b['memberId'], API_ACTORS, true)) api_out(['ok' => false, 'error' => 'neznámý technik'], 400);
  unset($b['op'], $b['src'], $b['operator']);           // operace formuláře příjmu tudy nejdou
  if ($a === 'lotCreate' && mat_on()) {                 // krok 5: karta MAT do SQL, list dostane propis
    if (!isset(MAT_TECHNIK[(string) ($b['memberId'] ?? '')])) {   // bez technika by kartu založil Apps Script jen v listu
      if (isset($b['matNew']) || isset($b['matFill'])) api_out(['ok' => false, 'error' => 'neznámý Trello účet']);
    } else try {
      $r = mat_pre_lot($b, MAT_TECHNIK[(string) $b['memberId']], 'trello');
      if (!$r['ok']) { api_log($actor, 'POST lotCreate', 'MAT: ' . $r['error']); api_out($r); }
      $b = $r['body'];
      if (!empty($r['expAuto'])) $pridej = ['expAuto' => $r['expAuto']];
    } catch (Throwable $e) {
      api_log($actor, 'POST lotCreate', 'MAT DB ' . substr($e->getMessage(), 0, 150));
      api_out(['ok' => false, 'error' => 'databáze karet MAT neodpověděla – nic se neuložilo, zkus to znovu'], 502);
    }
  }
  $b['action'] = $a;
  $b['actor']  = $actor;                                // kdo skutečně zapisoval (pro audit)
  $b['key']    = $s['key'];
  api_gas(API_GAS_URL, json_encode($b, JSON_UNESCAPED_UNICODE), $actor, 'POST ' . $a . (isset($b['memberId']) ? ' →' . $b['memberId'] : '') . $what0, $pridej ?? null);
}

api_out(['ok' => false, 'error' => 'nepovolená metoda'], 405);
