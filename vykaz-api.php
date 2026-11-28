<?php
/* =========================================================
   VÝKAZ (krok 41) – API okna vykaz.html
   - Kdo volá, dokazuje podepsaný JWT od Trella (X-Trello-JWT),
     stejně jako api.php a tabulky-api.php.
   - Technik dostane jen svou bilanci. H, Jana, Marie a Brigit
     přepínají mezi všemi techniky.
   - Jen čte (data z Apps Scriptu). Zapisuje jen do archivu
     uzavřených měsíců mimo web (vykaz-archiv/).
   ========================================================= */
require __DIR__ . '/prijem-auth.php';           // prijem_secret() – klíč pro Apps Script
require_once __DIR__ . '/sestavy-lib.php';      // sest_mpdf(), arch_gas(), arch_e()
require_once __DIR__ . '/vykaz-lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

const VA_KEYS_URL  = 'https://api.trello.com/1/resource/jwt-public-keys';
const VA_KEYS_FILE = __DIR__ . '/../../autolab-trello-keys.json';   // sdílená keš s api.php
const VA_PLUGIN    = '6a7f5eb6817f162662936fff';                    // Zakázkový list

function va_out(array $o, int $code = 200): void { http_response_code($code); echo json_encode($o, JSON_UNESCAPED_UNICODE); exit; }
function va_b64(string $s): string|false { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true); }
function va_keys(bool $fresh = false): array {
  if (!$fresh && is_file(VA_KEYS_FILE) && filemtime(VA_KEYS_FILE) > time() - 4 * 3600) {
    $k = json_decode((string) file_get_contents(VA_KEYS_FILE), true);
    if (!empty($k['keys'])) return $k['keys'];
  }
  $c = curl_init(VA_KEYS_URL);
  curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10]);
  $r = curl_exec($c); $k = is_string($r) ? json_decode($r, true) : null;
  if (!empty($k['keys']) && is_array($k['keys'])) { @file_put_contents(VA_KEYS_FILE, $r, LOCK_EX); return $k['keys']; }
  $old = is_file(VA_KEYS_FILE) ? json_decode((string) file_get_contents(VA_KEYS_FILE), true) : null;
  return $old['keys'] ?? [];
}
function va_jwt(string $tok, ?string &$why): ?array {
  $p = explode('.', $tok);
  if (count($p) !== 3) { $why = 'tvar'; return null; }
  $h = json_decode((string) va_b64($p[0]), true); $c = json_decode((string) va_b64($p[1]), true); $sig = va_b64($p[2]);
  if (($h['alg'] ?? '') !== 'RS256' || !is_array($c) || $sig === false) { $why = 'hlavička'; return null; }
  $ok = false;
  foreach ([false, true] as $fresh) foreach (va_keys($fresh) as $pem) if (openssl_verify($p[0] . '.' . $p[1], $sig, $pem, OPENSSL_ALGO_SHA256) === 1) { $ok = true; break 2; }
  if (!$ok) { $why = 'podpis'; return null; }
  $now = time();
  if (($c['iss'] ?? '') !== 'trello') { $why = 'iss'; return null; }
  if ((int) ($c['exp'] ?? 0) < $now - 30 || (int) ($c['iat'] ?? 0) > $now + 60) { $why = 'platnost'; return null; }
  return $c;
}

/* ---- ověření volajícího ---- */
if (PHP_SAPI === 'cli') {                                 // test na serveru: php vykaz-api.php kdo=P rok=26
  $actor = '6b00d5c2c5be2a396f89949e';
  parse_str(implode('&', array_slice($argv, 1)), $_GET);
  $_SERVER['REQUEST_METHOD'] = 'GET';
} else {
  $why = '';
  $c = va_jwt((string) ($_SERVER['HTTP_X_TRELLO_JWT'] ?? ''), $why);
  if (!$c) { vyk_log('-', 'jwt', 'odmítnuto: ' . $why); va_out(['ok' => false, 'error' => 'neověřený přístup (Trello JWT: ' . $why . ')'], 401); }
  $actor = (string) ($c['idMember'] ?? '');
  if (!hash_equals(VA_PLUGIN, (string) ($c['idPlugin'] ?? ''))) va_out(['ok' => false, 'error' => 'cizí Power-Up'], 403);
}
if (!vyk_smi($actor)) { vyk_log($actor, 'přístup', 'odmítnuto'); va_out(['ok' => false, 'error' => 'výkaz je zatím ve zkušebním provozu nebo tento účet nemá přístup'], 403); }
$admin = vyk_admin($actor);
$ja = VYK_TECH[$actor] ?? '';
$KDO_JM = ['655329144f809c15a1896437' => 'Marie', '6b00d5c2c5be2a396f89949e' => 'Brigit'];
$jaJm = $ja !== '' ? $ja : ($KDO_JM[$actor] ?? '?');

$rokNow = (int) date('y');
$par = $_SERVER['REQUEST_METHOD'] === 'POST' ? (json_decode((string) file_get_contents('php://input'), true) ?: []) : $_GET;
$kdo = strtoupper(trim((string) ($par['kdo'] ?? $ja)));
if ($kdo === '') $kdo = 'H';
if (!in_array($kdo, VYK_KDO, true)) va_out(['ok' => false, 'error' => 'neznámý technik'], 400);
if (!$admin && $kdo !== $ja) va_out(['ok' => false, 'error' => 'vidíš jen svůj výkaz'], 403);
$rok = (int) ($par['rok'] ?? $rokNow);
if ($rok < 25 || $rok > $rokNow) va_out(['ok' => false, 'error' => 'neplatný rok'], 400);
$mes = (int) ($par['mesic'] ?? 0);

try {
  /* uzavření měsíce: PDF se zamkne do archivu (jen H, Jana, Marie, Brigit) */
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($par['action'] ?? '') !== 'uzavrit') va_out(['ok' => false, 'error' => 'nepovolená akce'], 400);
    if (!$admin) va_out(['ok' => false, 'error' => 'měsíc uzavírá H, Jana nebo Marie'], 403);
    if ($mes < 1 || $mes > 12) va_out(['ok' => false, 'error' => 'neplatný měsíc'], 400);
    if ($rok * 100 + $mes >= (int) date('ym')) va_out(['ok' => false, 'error' => 'běžící měsíc ještě nejde uzavřít'], 400);
    $cesta = vyk_archiv_cesta($kdo, $rok, $mes);
    if (is_file($cesta . '.pdf')) va_out(['ok' => false, 'error' => 'měsíc už je uzavřený'], 409);
    $b = vyk_bilance(vyk_raw($rok, true), $kdo, $rok);
    $pdf = vyk_pdf($b, $mes, true, $jaJm);
    if (!is_dir(dirname($cesta))) @mkdir(dirname($cesta), 0750, true);
    file_put_contents($cesta . '.pdf', $pdf, LOCK_EX);
    $x = $b['mesice'][$mes - 1]; unset($x['vykony'], $x['platby']);
    file_put_contents($cesta . '.json', json_encode(['kdy' => date('c'), 'uzavrel' => $jaJm, 'actor' => $actor, 'sha256' => hash('sha256', $pdf),
      'narok' => $x['narok'], 'mesic' => $x, 'data' => $b['cas']], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    @chmod($cesta . '.pdf', 0440); @chmod($cesta . '.json', 0440);
    vyk_log($actor, 'uzavřeno ' . $kdo . ' ' . $mes . '/' . $rok, 'ok');
    va_out(['ok' => true, 'archiv' => vyk_archiv_seznam($kdo, $rok)]);
  }

  /* PDF: uzavřený měsíc z archivu, jinak průběžný */
  if (isset($par['pdf'])) {
    if ($mes < 1 || $mes > 12) va_out(['ok' => false, 'error' => 'neplatný měsíc'], 400);
    $cesta = vyk_archiv_cesta($kdo, $rok, $mes) . '.pdf';
    $MES = ['', '01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12'];
    $nazev = 'VYKAZ ' . $kdo . ' 20' . $rok . '-' . $MES[$mes] . '.pdf';
    if (is_file($cesta)) { vyk_log($actor, 'pdf archiv ' . $kdo . ' ' . $mes . '/' . $rok, 'ok'); va_out(['ok' => true, 'nazev' => $nazev, 'uzavreno' => true, 'pdf' => base64_encode((string) file_get_contents($cesta))]); }
    $b = vyk_bilance(vyk_raw($rok), $kdo, $rok);
    $pdf = vyk_pdf($b, $mes, false);
    vyk_log($actor, 'pdf ' . $kdo . ' ' . $mes . '/' . $rok, 'ok');
    va_out(['ok' => true, 'nazev' => str_replace('.pdf', ' prubezny.pdf', $nazev), 'uzavreno' => false, 'pdf' => base64_encode($pdf)]);
  }

  /* data okna */
  $raw = vyk_raw($rok, !empty($par['obnovit']));
  $b = vyk_bilance($raw, $kdo, $rok);
  $b['ok'] = true; $b['admin'] = $admin; $b['ja'] = $ja;
  $b['technici'] = $admin ? array_map(fn($k) => ['kdo' => $k, 'jmeno' => (string) ($raw['jmena'][$k] ?? $k)], VYK_KDO) : [];
  $b['roky'] = range(25, $rokNow);
  $b['banka'] = in_array($actor, ['588fa9e7e5db691bfe653bce', '6b00d5c2c5be2a396f89949e'], true);   // záložka Banka (krok 42)
  $kd = [];
  foreach ($b['mesice'] as $mm) foreach ($mm['vykony'] as $v) $kd[] = $v['kod'];
  $b['nazvy'] = (object) vyk_nazvy($kd);
  vyk_log($actor, 'data ' . $kdo . ' ' . $rok, 'ok');
  va_out($b);
} catch (Throwable $e) {
  vyk_log($actor, 'chyba', substr($e->getMessage(), 0, 200));
  va_out(['ok' => false, 'error' => 'výkaz se nepodařilo připravit: ' . $e->getMessage()], 502);
}
