<?php
/* =========================================================
   Záložka Materiál v prohlížeči tabulek – živá data ze SQL (krok 5)
   GET            → karty, skupiny, historie, práva (tvar jako náhled krok 3)
   POST {id, verze, pole, hodnota, odlozit?}  → úprava jednoho pole
   POST {propis: [id, …]}                     → propis po hromadné úpravě
   Kdo volá: Trello JWT (zakázkový list) nebo cookie formuláře příjmu.
   Upravovat smí H, Marie, Brigit a formulář příjmu; ostatní jen čtou.
   Bez zapnutého přepínače (autolab-mat.json) odpoví „zatím vypnuto“.
   ========================================================= */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
require __DIR__ . '/prijem-auth.php';
require __DIR__ . '/mat-lib.php';

const MA_KEYS_URL  = 'https://api.trello.com/1/resource/jwt-public-keys';
const MA_KEYS_FILE = __DIR__ . '/../../autolab-trello-keys.json';
const MA_PLUGIN    = '6a7f5eb6817f162662936fff';
const MA_USERS = [                                   // čtení: stejné jako TB_USERS v tabulky-api.php
  '588fa9e7e5db691bfe653bce', '611cc39d9beb8e6028ba3ae4', '63cd0523a27f8901400a6dda',
  '63ea51fbb262b19c0189e9b8', '686d1aa4bc747a96bb0fd585', '63e911bcb90ab76e81ad4e6a',
  '655329144f809c15a1896437', '6b00d5c2c5be2a396f89949e',
];
const MA_EDIT = ['588fa9e7e5db691bfe653bce' => 'H', '655329144f809c15a1896437' => 'M', '6b00d5c2c5be2a396f89949e' => 'BRIGIT'];

function ma_out(array $o, int $code = 200): void { http_response_code($code); echo json_encode($o, JSON_UNESCAPED_UNICODE); exit; }
function ma_b64(string $s): string|false { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true); }
function ma_keys(bool $fresh): array {
  if (!$fresh && is_file(MA_KEYS_FILE) && filemtime(MA_KEYS_FILE) > time() - 4 * 3600) {
    $k = json_decode((string) file_get_contents(MA_KEYS_FILE), true); if (!empty($k['keys'])) return $k['keys'];
  }
  $c = curl_init(MA_KEYS_URL); curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10]);
  $r = curl_exec($c); $k = is_string($r) ? json_decode($r, true) : null;
  if (!empty($k['keys'])) { @file_put_contents(MA_KEYS_FILE, $r, LOCK_EX); return $k['keys']; }
  $old = is_file(MA_KEYS_FILE) ? json_decode((string) file_get_contents(MA_KEYS_FILE), true) : null;
  return $old['keys'] ?? [];
}
function ma_jwt(string $tok, ?string &$why): ?array {
  $p = explode('.', $tok); if (count($p) !== 3) { $why = 'tvar'; return null; }
  $h = json_decode((string) ma_b64($p[0]), true); $c = json_decode((string) ma_b64($p[1]), true); $sig = ma_b64($p[2]);
  if (($h['alg'] ?? '') !== 'RS256' || !is_array($c) || $sig === false) { $why = 'hlavička'; return null; }
  $ok = false;
  foreach ([false, true] as $f) foreach (ma_keys($f) as $pem) if (openssl_verify($p[0] . '.' . $p[1], $sig, $pem, OPENSSL_ALGO_SHA256) === 1) { $ok = true; break 2; }
  if (!$ok) { $why = 'podpis'; return null; }
  if (($c['iss'] ?? '') !== 'trello') { $why = 'iss'; return null; }
  if ((int) ($c['exp'] ?? 0) < time() - 30 || (int) ($c['iat'] ?? 0) > time() + 60) { $why = 'platnost'; return null; }
  return $c;
}

/* ---- kdo volá ---- */
if (PHP_SAPI === 'cli') {                            // test: php mat-api.php GET|POST [actor] < tělo
  $_SERVER['REQUEST_METHOD'] = $argv[1] ?? 'GET';
  $actor = $argv[2] ?? '588fa9e7e5db691bfe653bce';
} elseif (empty($_SERVER['HTTP_X_TRELLO_JWT']) && !empty($_COOKIE['prijem_auth'])) {
  if (!prijem_logged()) ma_out(['ok' => false, 'error' => 'přihlášení k příjmu vypršelo, přihlas se znovu'], 401);
  $actor = 'prijem';
} else {
  $why = '';
  $c = ma_jwt((string) ($_SERVER['HTTP_X_TRELLO_JWT'] ?? ''), $why);
  if (!$c) ma_out(['ok' => false, 'error' => 'neověřený přístup (Trello JWT: ' . $why . ')'], 401);
  $actor = (string) ($c['idMember'] ?? '');
  if (!hash_equals(MA_PLUGIN, (string) ($c['idPlugin'] ?? ''))) ma_out(['ok' => false, 'error' => 'cizí Power-Up'], 403);
  if (!in_array($actor, MA_USERS, true)) ma_out(['ok' => false, 'error' => 'tento Trello účet nemá ke katalogu přístup'], 403);
}
$kdo = $actor === 'prijem' ? 'PRIJEM' : (MA_EDIT[$actor] ?? (MAT_TECHNIK[$actor] ?? '?'));
$smi = $actor === 'prijem' || isset(MA_EDIT[$actor]);
if (!mat_on()) ma_out(['ok' => false, 'error' => 'záložka Materiál je zatím kopie sešitu – zápis do SQL ještě není zapnutý']);

try {
  $pdo = mat_pdo(); $T = mat_t('mat');
  if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $cols = ['id', 'ref', 'nazev', 'kat', 'druh', 'forma', 'jednotek', 'vyrobce', 'dodavatel', 'skupina', 'odpis',
             'cena_nakup', 'navyseni_pct', 'otev', 'sarzi', 'stav', 'cena_datum', 'zalozil', 'zalozeno', 'verze'];
    /* balení ze stínu s1_baleni, šarže se zkratkou REF přes alias */
    $bal = [];
    try {
      $q = $pdo->query("SELECT COALESCE(a.mat_id, m.id) AS id, COUNT(*) AS sarzi, SUM(b.status IN ('ZALOŽENO', 'NASAZENO')) AS otev
                        FROM s1_baleni b LEFT JOIN $T m ON m.ref = TRIM(b.ref) LEFT JOIN " . mat_t('mat_alias') . " a ON a.ref_alias = TRIM(b.ref)
                        GROUP BY COALESCE(a.mat_id, m.id)");
      foreach ($q as $r) if ($r['id'] !== null) $bal[(int) $r['id']] = $r;
    } catch (Throwable $e) {}
    $rows = [];
    foreach ($pdo->query("SELECT * FROM $T ORDER BY id") as $m) {
      $i = (int) $m['id'];
      $m['otev'] = (int) ($bal[$i]['otev'] ?? 0); $m['sarzi'] = (int) ($bal[$i]['sarzi'] ?? 0);
      if (!$smi) { $m['cena_nakup'] = null; $m['navyseni_pct'] = null; }
      $rows[] = array_map(fn($k) => in_array($k, ['jednotek', 'cena_nakup', 'navyseni_pct'], true) && $m[$k] !== null ? (float) $m[$k]
                                    : (in_array($k, ['id', 'verze'], true) ? (int) $m[$k] : $m[$k]), $cols);
    }
    $zm = $pdo->query('SELECT mat_id, DATE_FORMAT(cas, "%Y-%m-%d %H:%i:%s") AS cas, kdo, zdroj, pole, stara, nova FROM ' . mat_t('mat_zmena')
                      . ($smi ? '' : " WHERE pole NOT IN ('cena_nakup', 'navyseni_pct')") . ' ORDER BY id DESC LIMIT 5000')->fetchAll(PDO::FETCH_NUM);
    $cek = $pdo->query('SELECT DISTINCT mat_id FROM ' . mat_t('mat_propis') . " WHERE stav IN ('čeká', 'chyba')")->fetchAll(PDO::FETCH_COLUMN);
    ma_out(['ok' => true, 'zdroj' => 'sql', 'tabulka' => 'SQL ' . $T, 'cols' => $cols, 'rows' => $rows,
            'skupiny' => $pdo->query('SELECT kod FROM ' . mat_t('mat_skupina') . ' ORDER BY kod')->fetchAll(PDO::FETCH_COLUMN),
            'zmeny' => array_map(fn($z) => [(int) $z[0], $z[1], $z[2], $z[3], $z[4], $z[5], $z[6]], array_reverse($zm)),
            'cekaji' => array_map('intval', $cek), 'smi_upravovat' => $smi, 'vidi_ceny' => $smi, 'kdo' => $kdo]);
  }
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') ma_out(['ok' => false, 'error' => 'nepovolená metoda'], 405);
  if (!$smi) ma_out(['ok' => false, 'error' => 'kartu MAT může upravit jen Honza, Majda nebo Brigit'], 403);
  $b = json_decode((string) file_get_contents('php://input'), true);
  if (!is_array($b)) ma_out(['ok' => false, 'error' => 'neplatný požadavek'], 400);

  if (isset($b['propis'])) {                         // po hromadné úpravě: jeden propis pro všechny
    $ids = array_slice(array_map('intval', (array) $b['propis']), 0, 1000);
    $r = mat_sync($ids);
    mat_log($kdo, 'propis ' . count($ids), count(array_filter($r, fn($x) => $x === 'hotovo')) . ' hotovo');
    ma_out(['ok' => true, 'vysledky' => array_map(fn($x) => $x === 'hotovo' ? 'hotovo' : 'čeká', $r)]);
  }
  $id = (int) ($b['id'] ?? 0); $pole = (string) ($b['pole'] ?? '');
  if (!$id || !in_array($pole, MAT_POLE_UI, true)) ma_out(['ok' => false, 'error' => 'neplatné pole']);
  [$v, $err] = mat_check([$pole => $b['hodnota'] ?? null], true);
  if ($err) ma_out(['ok' => false, 'error' => $err]);
  if (in_array($pole, ['nazev', 'kat', 'vyrobce', 'odpis', 'forma'], true) && ($v[$pole] ?? '') === '') ma_out(['ok' => false, 'error' => 'pole nejde vyprázdnit']);
  [$m, $zm, $konf, $e2] = mat_apply($id, $v, $kdo, 'prohlizec', isset($b['verze']) ? (int) $b['verze'] : null);
  if ($konf) { mat_log($kdo, 'set ' . $id . ' ' . $pole, 'konflikt'); ma_out(['ok' => false, 'konflikt' => $konf]); }
  if ($e2) ma_out(['ok' => false, 'error' => $e2]);
  $p = 'hotovo';
  if ($zm && array_diff($zm, ['cena_nakup', 'navyseni_pct', 'stav'])) $p = empty($b['odlozit']) ? (mat_sync([$id])[$id] ?? 'chyba') : 'čeká';
  mat_log($kdo, 'set ' . $m['ref'] . ' ' . $pole, 'ok propis ' . $p);
  ma_out(['ok' => true, 'verze' => (int) $m['verze'], 'propis' => $p === 'hotovo' ? 'hotovo' : 'čeká']);
} catch (Throwable $e) {
  mat_log($kdo ?? '-', 'chyba', substr($e->getMessage(), 0, 200));
  ma_out(['ok' => false, 'error' => 'databáze neodpověděla'], 502);
}
