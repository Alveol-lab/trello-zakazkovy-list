<?php
/* =========================================================
   Prohlížeč tabulek AutoLAB (E1) – jen čtení ze stínu s1_*
   - Kdo volá, dokazuje podepsaný JWT od Trella (t.jwt()) v hlavičce
     X-Trello-JWT – stejně jako api.php. Z formuláře příjmu
     (tabulky.html?z=prijem) stačí platná cookie přihlášení k příjmu.
   - Pevný seznam tabulek a dotazů, prohlížeč neposílá žádné SQL.
   - V E1 jen sklad a výrobky. Výkony, ceny a odměny se tudy nečtou.
   - Přístup k databázi: autolab-db.cnf mimo web (práva 600).
   ========================================================= */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

const TB_DB_CNF    = __DIR__ . '/../../autolab-db.cnf';
const TB_KEYS_URL  = 'https://api.trello.com/1/resource/jwt-public-keys';
const TB_KEYS_FILE = __DIR__ . '/../../autolab-trello-keys.json';   // sdílená keš s api.php
const TB_LOG       = __DIR__ . '/../../autolab-api.log';
const TB_KEYS_TTL  = 4 * 3600;
const TB_PLUGIN    = '6a7f5eb6817f162662936fff';                    // Zakázkový list

/* technici H J Z P U I + recepce (Marie Vosiková) + Brigit Denteo – stejné jako API_USERS v api.php */
const TB_USERS = [
  '588fa9e7e5db691bfe653bce', '611cc39d9beb8e6028ba3ae4', '63cd0523a27f8901400a6dda',
  '63ea51fbb262b19c0189e9b8', '686d1aa4bc747a96bb0fd585', '63e911bcb90ab76e81ad4e6a',
  '655329144f809c15a1896437', '6b00d5c2c5be2a396f89949e',
];

/* Tabulky: titulek, sloupce [klíč, popisek, typ], dotaz. Typy: t text, n číslo, d datum. */
const TB_TABLES = [
  'mat' => [
    'title' => 'Materiál (MAT)',
    'cols'  => [['ref','REF','t'],['nazev','Název','t'],['kat','Kat.','t'],['forma','Forma','t'],
                ['jednotek','Ks v bal.','t'],['odpis','Odpis','t'],['skupina','Skupina','t'],
                ['vyrobce','Výrobce','t'],['dodavatel','Dodavatel','t'],
                ['sklad','Skladem','n'],['nasazeno','Nasazeno','n'],['zalozeno','Karta založena','t'],
                ['radek','Řádek','n']],
    'sql'   => "SELECT m.ref, m.nazev, m.kat, m.forma, m.jednotek, m.odpis, m.skupina, m.vyrobce, m.dodavatel,
                       COALESCE(b.sklad,0) AS sklad, COALESCE(b.nasazeno,0) AS nasazeno, m.zalozeno, m.radek
                  FROM s1_mat m
                  LEFT JOIN (SELECT ref, SUM(status='ZALOŽENO') AS sklad, SUM(status='NASAZENO') AS nasazeno
                               FROM s1_baleni GROUP BY ref) b ON b.ref = m.ref
                 ORDER BY m.radek",
  ],
  'baleni' => [
    'title' => 'Balení (LOT)',
    'cols'  => [['did','DID','t'],['ref','REF','t'],['nazev','Materiál','t'],['lot','Šarže','t'],
                ['status','Stav','t'],['zalozeno','Založeno','d'],['nasazeno','Nasazeno','d'],
                ['spotrebovano','Spotřebováno','d'],['expirace','Expirace','t'],['mnozstvi','Množství','t'],
                ['jednotka','Jedn.','t'],['nasadil','Nasadil','t'],['pouziti','Použití','t'],
                ['ukoncil','Ukončil','t'],['zbytek','Zbytek','n'],['case_kod','Zakázka (CASE)','t'],
                ['duvod','Důvod','t'],['radek','Řádek','n']],
    'sql'   => "SELECT b.did, b.ref, m.nazev, b.lot, b.status, b.zalozeno, b.nasazeno, b.spotrebovano, b.expirace,
                       b.mnozstvi, b.jednotka, b.nasadil, b.pouziti, b.ukoncil, b.zbytek, b.case_kod, b.duvod, b.radek
                  FROM s1_baleni b
                  LEFT JOIN (SELECT ref, MIN(nazev) AS nazev FROM s1_mat GROUP BY ref) m ON m.ref = b.ref
                 ORDER BY b.radek DESC",
  ],
  'lotlog' => [
    'title' => 'Deník odpisů (LOTLOG)',
    'cols'  => [['datum','Datum','d'],['vykon','Výkon (UID)','t'],['kod','Kód','t'],['ord','ORD','t'],
                ['q','Q','n'],['did','DID','t'],['ref','REF','t'],['jednotek','Jednotek','n'],
                ['stav','Stav','t'],['technik','Technik','t'],['uzavrel','Uzavřel','t'],['otevrel','Otevřel','t'],
                ['radek','Řádek','n']],
    'sql'   => "SELECT datum, vykon, kod, ord, q, did, ref, jednotek, stav, technik, uzavrel, otevrel, radek
                  FROM s1_lotlog ORDER BY radek DESC",
  ],
  'produkt' => [
    'title' => 'Výrobky (PRODB)',
    'cols'  => [['kod','Kód','t'],['nazev','Název','t'],['pt','PT','t'],['kat','Kat.','t'],['kzp','KZP','t'],
                ['hs','HS','t'],['vzp','VZP','t'],['mat_hlavni','Hlavní materiál','t'],
                ['mat_pomoc','Pomocný materiál','t'],['gmdn','GMDN','t'],['udi_pi','UDI-PI','t'],
                ['trvanlivost','Trvanlivost','t'],['poznamka','Poznámka','t'],['radek','Řádek','n']],
    'sql'   => "SELECT kod, nazev, pt, kat, kzp, hs, vzp, mat_hlavni, mat_pomoc, gmdn, udi_pi, trvanlivost, poznamka, radek
                  FROM s1_produkt ORDER BY radek",
  ],
];

function tb_out(array $o, int $code = 200): void { http_response_code($code); echo json_encode($o, JSON_UNESCAPED_UNICODE); exit; }

function tb_log(string $actor, string $what, string $res): void {
  if (is_file(TB_LOG) && filesize(TB_LOG) > 1048576) @rename(TB_LOG, TB_LOG . '.1');
  @file_put_contents(TB_LOG, date('c') . "\t" . $actor . "\t" . $what . "\t" . $res . "\n", FILE_APPEND | LOCK_EX);
}

function tb_b64(string $s): string|false { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true); }

function tb_keys(bool $fresh = false): array {
  if (!$fresh && is_file(TB_KEYS_FILE) && filemtime(TB_KEYS_FILE) > time() - TB_KEYS_TTL) {
    $k = json_decode((string) file_get_contents(TB_KEYS_FILE), true);
    if (!empty($k['keys'])) return $k['keys'];
  }
  $c = curl_init(TB_KEYS_URL);
  curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10]);
  $r = curl_exec($c);
  $k = is_string($r) ? json_decode($r, true) : null;
  if (!empty($k['keys']) && is_array($k['keys'])) { @file_put_contents(TB_KEYS_FILE, $r, LOCK_EX); return $k['keys']; }
  $old = is_file(TB_KEYS_FILE) ? json_decode((string) file_get_contents(TB_KEYS_FILE), true) : null;
  return $old['keys'] ?? [];
}

function tb_jwt(string $tok, ?string &$why): ?array {
  $p = explode('.', $tok);
  if (count($p) !== 3) { $why = 'tvar'; return null; }
  $h = json_decode((string) tb_b64($p[0]), true);
  $c = json_decode((string) tb_b64($p[1]), true);
  $sig = tb_b64($p[2]);
  if (($h['alg'] ?? '') !== 'RS256' || !is_array($c) || $sig === false) { $why = 'hlavička'; return null; }
  $ok = false;
  foreach ([false, true] as $fresh) {
    foreach (tb_keys($fresh) as $pem) if (openssl_verify($p[0] . '.' . $p[1], $sig, $pem, OPENSSL_ALGO_SHA256) === 1) { $ok = true; break 2; }
  }
  if (!$ok) { $why = 'podpis'; return null; }
  $now = time();
  if (($c['iss'] ?? '') !== 'trello') { $why = 'iss'; return null; }
  if ((int) ($c['exp'] ?? 0) < $now - 30 || (int) ($c['iat'] ?? 0) > $now + 60) { $why = 'platnost'; return null; }
  return $c;
}

function tb_pdo(): PDO {
  $cfg = @parse_ini_file(TB_DB_CNF, true, INI_SCANNER_RAW);
  $c = $cfg['client'] ?? null;
  if (!$c || empty($c['host']) || empty($c['user']) || !isset($c['password'])) tb_out(['ok' => false, 'error' => 'server: chybí konfigurace databáze'], 500);
  $db = $c['database'] ?? $c['user'];
  $pdo = new PDO("mysql:host={$c['host']};dbname={$db};charset=utf8mb4", $c['user'], trim($c['password'], "\"'"), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_NUM,
    PDO::ATTR_TIMEOUT => 5,
  ]);
  $pdo->exec("SET SESSION TRANSACTION READ ONLY");          // pojistka: tudy se nic nezapíše
  return $pdo;
}

/* ---- ověření volajícího (z příkazové řádky jen test na serveru) ---- */
if (PHP_SAPI === 'cli') {
  $actor = 'cli-test';
  parse_str(implode('&', array_slice($argv, 1)), $_GET);
  $_SERVER['REQUEST_METHOD'] = 'GET';
} elseif (empty($_SERVER['HTTP_X_TRELLO_JWT']) && !empty($_COOKIE['prijem_auth'])) {
  /* druhá cesta: formulář příjmu materiálu (přihlášení podepsanou cookie z prijem-auth.php) */
  require __DIR__ . '/prijem-auth.php';
  if (!prijem_logged()) { tb_log('-', 'tabulky prijem', 'odmítnuto'); tb_out(['ok' => false, 'error' => 'přihlášení k příjmu vypršelo, přihlas se znovu'], 401); }
  $actor = 'prijem';
} else {
  $why = '';
  $c = tb_jwt((string) ($_SERVER['HTTP_X_TRELLO_JWT'] ?? ''), $why);
  if (!$c) { tb_log('-', 'tabulky jwt', 'odmítnuto: ' . $why); tb_out(['ok' => false, 'error' => 'neověřený přístup (Trello JWT: ' . $why . ')'], 401); }
  $actor = (string) ($c['idMember'] ?? '');
  if (!hash_equals(TB_PLUGIN, (string) ($c['idPlugin'] ?? ''))) { tb_log($actor, 'tabulky plugin', 'odmítnuto'); tb_out(['ok' => false, 'error' => 'cizí Power-Up'], 403); }
  if (!in_array($actor, TB_USERS, true)) { tb_log($actor, 'tabulky člen', 'odmítnuto'); tb_out(['ok' => false, 'error' => 'tento Trello účet nemá ke katalogu přístup'], 403); }
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') tb_out(['ok' => false, 'error' => 'nepovolená metoda'], 405);

$t = (string) ($_GET['t'] ?? '');
try {
  $pdo = tb_pdo();
  $st = $pdo->query("SELECT cas, sesit_verze FROM s1_import ORDER BY id DESC LIMIT 1")->fetch();
  $stav = null;
  if ($st) {                                                // import ukládá UTC, ukazujeme pražský čas
    $dt = new DateTime($st[0], new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone('Europe/Prague'));
    $stav = ['cas' => $dt->format('j. n. Y H:i'), 'verze' => $st[1]];
  }

  if ($t === '') {                                          // seznam tabulek
    $list = [];
    foreach (TB_TABLES as $k => $d) $list[] = ['id' => $k, 'title' => $d['title']];
    tb_log($actor, 'tabulky seznam', 'ok');
    tb_out(['ok' => true, 'stav' => $stav, 'tables' => $list]);
  }
  if (!isset(TB_TABLES[$t])) tb_out(['ok' => false, 'error' => 'neznámá tabulka'], 400);
  $d = TB_TABLES[$t];
  $rows = $pdo->query($d['sql'])->fetchAll();
  tb_log($actor, 'tabulky ' . $t, 'ok ' . count($rows));
  tb_out(['ok' => true, 'stav' => $stav, 'id' => $t, 'title' => $d['title'],
          'cols' => array_map(fn($c) => ['k' => $c[0], 'label' => $c[1], 'type' => $c[2]], $d['cols']),
          'rows' => $rows]);
} catch (Throwable $e) {
  tb_log($actor, 'tabulky ' . $t, 'chyba DB');
  tb_out(['ok' => false, 'error' => 'databáze neodpověděla'], 502);
}
