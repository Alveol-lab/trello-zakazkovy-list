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
require __DIR__ . '/archiv-lib.php';          // krok 31: archiv vydaných prohlášení (přepínač autolab-archiv.json)
require __DIR__ . '/sestavy-lib.php';
require_once __DIR__ . '/frez-lib.php';             // krok F2: technologie výkonu a deník frézy (přepínač autolab-frez.json)         // krok 32: tiskové sestavy ze šablon (přepínač autolab-sestavy.json)
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
                     'autoDidPreview', 'lotCreate', 'lotCheck', 'lotEnd', 'lotNasad', 'nabidkaPdf', 'techVolba'];
const API_GET_OK  = ['code', 'ver', 'lot', 'lotq', 'matref', 'matlists',
                     'suggest', 'search', 'typ', 'ord', 'limit_sady', 'limit_kody', 'tech', 'frez'];

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
if (!in_array($actor, API_USERS, true)) {
  /* i u odmítnutí zapsat, o jakou akci a zakázku šlo (jen kód 🏷️, bez obsahu zakázky) */
  $co = $_SERVER['REQUEST_METHOD'];
  if ($co === 'POST') {
    $b0 = json_decode((string) file_get_contents('php://input'), true);
    $a0 = is_array($b0) ? (string) ($b0['action'] ?? 'writeEvidence') : '?';
    $co .= ' ' . (in_array($a0, API_POST_OK, true) ? $a0 : 'jiná');
    $t0 = is_array($b0) ? trim((string) ($b0['tag'] ?? '')) : '';
    if (preg_match('/^[A-Za-z0-9]{6,20}$/', $t0)) $co .= ' ' . $t0;
  } else {
    $co .= ' ' . implode(',', array_intersect(array_keys($_GET), API_GET_OK));
  }
  api_log($actor, 'člen ' . $co, 'odmítnuto');
  api_out(['ok' => false, 'error' => 'tento Trello účet nemá do EVIDENCE přístup – přepni se na svůj účet'], 403);
}

function api_gas(string $url, ?string $post, string $actor, string $what, ?array $pridej = null, ?callable $po = null): void {
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
  if ($po) { $j = json_decode($r, true); if (is_array($j)) { echo json_encode($po($j), JSON_UNESCAPED_UNICODE); exit; } }
  if ($pridej) { $j = json_decode($r, true); if (is_array($j)) { foreach ($pridej as $k => $v) if (!isset($j[$k])) $j[$k] = $v; echo json_encode($j, JSON_UNESCAPED_UNICODE); exit; } }
  echo $r; exit;
}

$what0 = API_PLUGIN === '' ? ' [plugin ' . $plug . ']' : '';   // dočasně: zjištění ID Power-Upu

/* krok F2: technologie a fréza – jen SQL, Apps Script se neptá */
function api_kdo(string $actor): string { return MAT_TECHNIK[$actor] ?? ($actor === '655329144f809c15a1896437' ? 'M' : ($actor === '6b00d5c2c5be2a396f89949e' ? 'BRIGIT' : '?')); }
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (isset($_GET['tech']) || isset($_GET['frez']))) {
  $chceTech = isset($_GET['tech']) && tech_on();      // technologie a panel Z frézy mají každý svůj přepínač
  if (!$chceTech && !isset($_GET['frez'])) api_out(['ok' => false, 'vypnuto' => true]);
  try {
    $o = ['ok' => true];
    if ($chceTech) {                         // ?tech=kód,kód[&tag=🏷️] → výchozí + volby zakázky
      $o['vychozi'] = (object) tech_vychozi(array_slice(explode(',', (string) $_GET['tech']), 0, 60));
      $o['volby'] = (object) (isset($_GET['tag']) ? tech_zakazka((string) $_GET['tag']) : []);
    }
    if (isset($_GET['frez'])) $o['frez'] = frez_on() ? frez_zakazka((string) $_GET['frez']) : null;   // null = panel se neukáže
    api_out($o);
  } catch (Throwable $e) {
    api_log($actor, 'GET tech/frez', 'DB ' . substr($e->getMessage(), 0, 150));
    api_out(['ok' => false, 'error' => 'databáze neodpověděla'], 502);
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $q = [];
  foreach (API_GET_OK as $k) if (isset($_GET[$k]) && is_string($_GET[$k])) $q[$k] = $_GET[$k];
  if (!$q) api_out(['ok' => false, 'error' => 'nepovolený dotaz'], 400);
  if (isset($q['matlists'])) $q['matlists'] = '1';      // úplný seznam karet MAT je jen pro formulář příjmu
  $pridej = null;
  if (isset($q['matref']) && mat_on()) { try {        // kód z krabice (EAN) navázaný v mat_alias → dotaz na skutečnou kartu
    $m = mat_by_ref($q['matref']);
    if (!$m || $m['stav'] === 'neaktivní') {
      $al = mat_pdo()->prepare('SELECT m.ref FROM ' . mat_t('mat_alias') . ' a JOIN ' . mat_t('mat') . " m ON m.id = a.mat_id WHERE a.ref_alias = ? AND m.stav = 'aktivní' LIMIT 1");
      $al->execute([mat_txt($q['matref'])]);
      if ($x = $al->fetch()) { $pridej = ['alias' => ['kod' => mat_txt($q['matref']), 'ref' => $x['ref']]]; $q['matref'] = $x['ref']; }
    }
  } catch (Throwable $e) {} }
  $q['key'] = $s['key'];
  api_gas(API_GAS_URL . '?' . http_build_query($q), null, $actor, 'GET ' . implode(',', array_diff(array_keys($q), ['key'])) . $what0, $pridej);
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
  unset($b['pdfServer'], $b['pacient'], $b['archiv']);  // krok 32: PDF ze šablony a archiv doplňuje jen server, nikdy prohlížeč
  unset($b['skVolba']);                                 // krok 39: skupiny „alternativy“ doplňuje jen server (SQL mat_skupina.vyber)
  if (($a === 'writeEvidence' || ($a === 'autoDidPreview' && !empty($b['grp']))) && mat_on()) {
    try { $b['skVolba'] = mat_sk_volba(); } catch (Throwable $e) { api_log($actor, 'POST ' . $a, 'skVolba ' . substr($e->getMessage(), 0, 120)); }
  }
  if ($a === 'lotCreate' && mat_on()) {                 // krok 5: karta MAT do SQL, list dostane propis
    if (!isset(MAT_TECHNIK[(string) ($b['memberId'] ?? '')])) {   // bez technika by kartu založil Apps Script jen v listu
      if (isset($b['matNew']) || isset($b['matFill'])) api_out(['ok' => false, 'error' => 'neznámý Trello účet']);
    } else try {
      /* varianta B (24. 11. 2026): novou kartu MAT ze zakázkového listu jen pro MD k zakázce, sklad zakládá příjem */
      if (isset($b['matNew']) && array_filter((array) ($b['items'] ?? []), fn($it) => is_array($it) && empty($it['md'])))
        api_out(['ok' => false, 'error' => 'novou kartu materiálu zakládá jen příjem materiálu – vyber existující kartu, nebo balení předej na příjem']);
      $r = mat_pre_lot($b, MAT_TECHNIK[(string) $b['memberId']], 'trello');
      if (!$r['ok']) { api_log($actor, 'POST lotCreate', 'MAT: ' . $r['error']); api_out($r); }
      $b = $r['body'];
      if (!empty($r['expAuto'])) $pridej = ['expAuto' => $r['expAuto']];
    } catch (Throwable $e) {
      api_log($actor, 'POST lotCreate', 'MAT DB ' . substr($e->getMessage(), 0, 150));
      api_out(['ok' => false, 'error' => 'databáze karet MAT neodpověděla – nic se neuložilo, zkus to znovu'], 502);
    }
  }
  if ($a === 'techVolba') {                            // krok F2: volba technologie u výkonu, trend 3× → výchozí
    if (!tech_on()) api_out(['ok' => false, 'vypnuto' => true]);
    if (!isset(MAT_TECHNIK[$actor]) && $actor !== '6b00d5c2c5be2a396f89949e') api_out(['ok' => false, 'error' => 'technologii volí technik'], 403);
    try { $r = tech_volba(trim((string) ($b['tag'] ?? '')), (string) ($b['kod'] ?? ''), (string) ($b['technologie'] ?? ''), api_kdo($actor)); }
    catch (Throwable $e) { api_log($actor, 'POST techVolba', 'DB ' . substr($e->getMessage(), 0, 150)); api_out(['ok' => false, 'error' => 'databáze neodpověděla'], 502); }
    api_log($actor, 'POST techVolba', $r['ok'] ? 'ok' . (!empty($r['zmena']) ? ' trend→' . $r['vychozi'] : '') : $r['error']);
    api_out($r);
  }
  if ($a === 'nabidkaPdf') {                            // krok 32: kalkulace (F-NAB) ze šablony, jen čtení
    $tag = trim((string) ($b['tag'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9]{6,20}$/', $tag)) api_out(['ok' => false, 'error' => 'neplatný kód zakázky'], 400);
    $d = sest_data('nabidka', ['tag' => $tag]);
    if (!$d['ok']) { api_log($actor, 'POST nabidkaPdf', $d['error']); api_out($d); }
    try { $pdf = sest_pdf('nabidka', $d['data']); }
    catch (Throwable $e) { api_log($actor, 'POST nabidkaPdf', 'PDF ' . substr($e->getMessage(), 0, 150)); api_out(['ok' => false, 'error' => 'PDF se nepodařilo vytvořit'], 500); }
    $card = trim((string) ($b['cardId'] ?? ''));
    if ($card !== '') {                                 // tlačítko „kalkulace“: PDF do přílohy karty přes Apps Script (token Trella je jen tam)
      if (!preg_match('/^[0-9a-f]{24}$/', $card)) api_out(['ok' => false, 'error' => 'neplatná karta'], 400);
      $kl = trim((string) ($d['data']['klient'] ?? ''));
      if ($kl === '') { api_log($actor, 'POST nabidkaPdf ' . $tag, 'bez klienta'); api_out(['ok' => false, 'error' => 'případ ' . $tag . ' nemá v EVIDENCE jméno pacienta']); }
      $r = arch_gas(['action' => 'nabidkaAttach', 'cardId' => $card, 'tag' => $tag, 'pacient' => $kl, 'pdfServer' => base64_encode($pdf)], 90);
      api_log($actor, 'POST nabidkaPdf ' . $tag, !empty($r['ok']) ? 'příloha ok' : 'příloha: ' . substr((string) ($r['error'] ?? '?'), 0, 120));
      api_out(!empty($r['ok']) ? ['ok' => true, 'name' => (string) ($r['name'] ?? ''), 'replaced' => (int) ($r['replaced'] ?? 0)]
                               : ['ok' => false, 'error' => (string) ($r['error'] ?? 'příloha se nepovedla')]);
    }
    api_log($actor, 'POST nabidkaPdf', 'ok');
    api_out(['ok' => true, 'nazev' => 'F-NAB ' . $tag . '.pdf', 'pdf' => base64_encode($pdf)]);
  }
  $po = null;
  $srovnani = null;
  if ($a === 'compliancePdf' && sest_rezim() !== 'sheets') {   // krok 32: prohlášení ze šablony místo listu F-Stitek II.
    $tag = trim((string) ($b['tag'] ?? ''));
    $t0 = microtime(true);
    $d = $tag !== '' ? sest_data('prohlaseni', ['tag' => $tag]) : ['ok' => false, 'error' => 'chybí kód'];
    $pdf = null;
    if ($d['ok']) { try { $pdf = sest_pdf('prohlaseni', $d['data']); } catch (Throwable $e) { $d = ['ok' => false, 'error' => 'PDF ' . substr($e->getMessage(), 0, 150)]; } }
    if (sest_rezim() === 'server' && $pdf !== null && trim((string) ($d['data']['klient'] ?? '')) !== '') {
      $b['pdfServer'] = base64_encode($pdf);
      $b['pacient']   = (string) $d['data']['klient'];
      sest_log($actor, 'prohlaseni ' . $tag, 'server ' . SEST_VERZE['prohlaseni'] . sprintf(' %.1fs', microtime(true) - $t0));
    } elseif (sest_rezim() === 'srovnani' && $pdf !== null) {
      $srovnani = [$tag, $pdf];                         // uloží se až po úspěchu původní cesty
    } else {
      sest_log($actor, 'prohlaseni ' . $tag, 'šablona selhala, jede list: ' . ($d['error'] ?? 'bez PDF'));   // záloha: původní cesta přes list
    }
  }
  if ($a === 'compliancePdf') {                         // krok 31: Apps Script vrátí i originál PDF a data pro archiv
    if (arch_on()) $b['archiv'] = true;
    $po = function (array $j) use ($actor, $srovnani): array {
      if ($srovnani && !empty($j['ok'])) {              // režim srovnani: PDF ze šablony vedle PDF z listu
        if (!is_dir(SEST_SROVNANI)) @mkdir(SEST_SROVNANI, 0700, true);
        $f = SEST_SROVNANI . '/' . date('Ymd-His') . '-' . preg_replace('/[^A-Za-z0-9]/', '', $srovnani[0]);
        @file_put_contents($f . '-sablona.pdf', $srovnani[1]);
        $x0 = $j['archiv']['pdf'] ?? null;              // originál z listu, pokud ho Apps Script poslal pro archiv
        if (is_string($x0)) @file_put_contents($f . '-list.pdf', base64_decode($x0));
        sest_log($actor, 'prohlaseni ' . $srovnani[0], 'srovnani uloženo');
      }
      $x = $j['archiv'] ?? null;
      unset($j['archiv']);                              // do prohlížeče archivní data nejdou
      if (is_array($x) && !empty($j['ok']) && arch_on()) {
        try { arch_vydani($x, $actor, isset($x['technik']) ? substr((string) $x['technik'], 0, 1) : null); }
        catch (Throwable $e) { arch_log($actor, 'vydání ' . substr((string) ($x['tag'] ?? ''), 0, 40), 'CHYBA ' . substr($e->getMessage(), 0, 200)); }
      }
      return $j;                                        // chyba archivu prohlášení nikdy nezastaví
    };
  }
  $b['action'] = $a;
  $b['actor']  = $actor;                                // kdo skutečně zapisoval (pro audit)
  $b['key']    = $s['key'];
  api_gas(API_GAS_URL, json_encode($b, JSON_UNESCAPED_UNICODE), $actor, 'POST ' . $a . (isset($b['memberId']) ? ' →' . $b['memberId'] : '') . $what0, $pridej ?? null, $po);
}

api_out(['ok' => false, 'error' => 'nepovolená metoda'], 405);
