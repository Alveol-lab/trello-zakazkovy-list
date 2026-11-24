<?php
/* =========================================================
   Záložka Výrobky v Katalogu – živá data ze SQL (etapa 2b, krok P3)
   GET                              → výrobky, skladba, karty MAT, historie, práva
   POST {id, verze, pole, hodnota}  → úprava jednoho pole / skladby / stavu
   POST {novy: {kod, nazev, ku, kat, kzp, hs}} → nový výrobek (editor) nebo návrh (technik)
   Kdo volá: Trello JWT (zakázkový list) nebo cookie formuláře příjmu (jen čtení).
   Editoři: PR_EDIT v produkt-lib.php. Technici: jen vlastní návrhy.
   Přepínač autolab-prodb.json; bez něj odpoví „zatím vypnuto“ a Katalog vezme kopii sešitu.
   Pozn.: ověření JWT je kopie z mat-api.php (ten se teď nesmí měnit) – sloučit do společného souboru.
   ========================================================= */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
require __DIR__ . '/prijem-auth.php';
require __DIR__ . '/produkt-lib.php';

const PA_KEYS_URL  = 'https://api.trello.com/1/resource/jwt-public-keys';
const PA_KEYS_FILE = __DIR__ . '/../../autolab-trello-keys.json';
const PA_PLUGIN    = '6a7f5eb6817f162662936fff';
const PA_USERS = [                                   // čtení: stejné jako MA_USERS / TB_USERS
  '588fa9e7e5db691bfe653bce', '611cc39d9beb8e6028ba3ae4', '63cd0523a27f8901400a6dda',
  '63ea51fbb262b19c0189e9b8', '686d1aa4bc747a96bb0fd585', '63e911bcb90ab76e81ad4e6a',
  '655329144f809c15a1896437', '6b00d5c2c5be2a396f89949e',
];
const PA_JMENA = ['655329144f809c15a1896437' => 'M'];   // čte, výrobky neupravuje (zatím)

function pa_out(array $o, int $code = 200): void { http_response_code($code); echo json_encode($o, JSON_UNESCAPED_UNICODE); exit; }
function pa_b64(string $s): string|false { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true); }
function pa_keys(bool $fresh): array {
  if (!$fresh && is_file(PA_KEYS_FILE) && filemtime(PA_KEYS_FILE) > time() - 4 * 3600) {
    $k = json_decode((string) file_get_contents(PA_KEYS_FILE), true); if (!empty($k['keys'])) return $k['keys'];
  }
  $c = curl_init(PA_KEYS_URL); curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10]);
  $r = curl_exec($c); $k = is_string($r) ? json_decode($r, true) : null;
  if (!empty($k['keys'])) { @file_put_contents(PA_KEYS_FILE, $r, LOCK_EX); return $k['keys']; }
  $old = is_file(PA_KEYS_FILE) ? json_decode((string) file_get_contents(PA_KEYS_FILE), true) : null;
  return $old['keys'] ?? [];
}
function pa_jwt(string $tok, ?string &$why): ?array {
  $p = explode('.', $tok); if (count($p) !== 3) { $why = 'tvar'; return null; }
  $h = json_decode((string) pa_b64($p[0]), true); $c = json_decode((string) pa_b64($p[1]), true); $sig = pa_b64($p[2]);
  if (($h['alg'] ?? '') !== 'RS256' || !is_array($c) || $sig === false) { $why = 'hlavička'; return null; }
  $ok = false;
  foreach ([false, true] as $f) foreach (pa_keys($f) as $pem) if (openssl_verify($p[0] . '.' . $p[1], $sig, $pem, OPENSSL_ALGO_SHA256) === 1) { $ok = true; break 2; }
  if (!$ok) { $why = 'podpis'; return null; }
  if (($c['iss'] ?? '') !== 'trello') { $why = 'iss'; return null; }
  if ((int) ($c['exp'] ?? 0) < time() - 30 || (int) ($c['iat'] ?? 0) > time() + 60) { $why = 'platnost'; return null; }
  return $c;
}

/* ---- kdo volá ---- */
if (PHP_SAPI === 'cli') {                            // test: php produkt-api.php GET|POST [actor|prijem] < tělo
  $_SERVER['REQUEST_METHOD'] = $argv[1] ?? 'GET';
  $actor = $argv[2] ?? '588fa9e7e5db691bfe653bce';
} elseif (empty($_SERVER['HTTP_X_TRELLO_JWT']) && !empty($_COOKIE['prijem_auth'])) {
  if (!prijem_logged()) pa_out(['ok' => false, 'error' => 'přihlášení k příjmu vypršelo, přihlas se znovu'], 401);
  $actor = 'prijem';
} else {
  $why = '';
  $c = pa_jwt((string) ($_SERVER['HTTP_X_TRELLO_JWT'] ?? ''), $why);
  if (!$c) pa_out(['ok' => false, 'error' => 'neověřený přístup (Trello JWT: ' . $why . ')'], 401);
  $actor = (string) ($c['idMember'] ?? '');
  if (!hash_equals(PA_PLUGIN, (string) ($c['idPlugin'] ?? ''))) pa_out(['ok' => false, 'error' => 'cizí Power-Up'], 403);
  if (!in_array($actor, PA_USERS, true)) pa_out(['ok' => false, 'error' => 'tento Trello účet nemá ke katalogu přístup'], 403);
}
$cfg = pr_cfg();
if (!$cfg['cteni']) pa_out(['ok' => false, 'error' => 'záložka Výrobky je zatím kopie sešitu – SQL ještě není zapnuté']);
$role = isset(PR_EDIT[$actor]) ? 'editor' : (isset(MAT_TECHNIK[$actor]) ? 'technik' : 'cteni');
$kdo = $actor === 'prijem' ? 'PRIJEM' : (PR_EDIT[$actor] ?? (MAT_TECHNIK[$actor] ?? (PA_JMENA[$actor] ?? '?')));
$smiU = $cfg['zapis'] && $role === 'editor';
$smiN = $cfg['zapis'] && $role !== 'cteni';

try {
  $pdo = mat_pdo();
  if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $cols = ['id', 'kod', 'nazev', 'ku', 'kat', 'kzp', 'hs', 'vzp', 'gmdn', 'udi_pi', 'trvanlivost', 'eudamed', 'stary_kod', 'poznamka',
             'stav', 'navrhl', 'navrzeno', 'zalozil', 'zalozeno', 'verze', 'vykonu'];
    $vyk = [];                                       // počet výkonů podle kódu ze stínu s1_vykon (když chybí, 0)
    try { foreach ($pdo->query('SELECT kod, COUNT(*) AS n FROM s1_vykon GROUP BY kod') as $r) $vyk[mb_strtoupper(trim((string) $r['kod']))] = (int) $r['n']; }
    catch (Throwable $e) {}
    $rows = [];
    foreach ($pdo->query("SELECT *, DATE_FORMAT(navrzeno, '%Y-%m-%d') AS navrzeno, DATE_FORMAT(zalozeno, '%Y-%m-%d') AS zalozeno FROM t2p_produkt ORDER BY id") as $p) {
      $p['vykonu'] = $vyk[mb_strtoupper($p['kod'])] ?? 0;
      $rows[] = array_map(fn($k) => in_array($k, ['id', 'verze', 'trvanlivost'], true) && $p[$k] !== null ? (int) $p[$k] : $p[$k], $cols);
    }
    $skl = [];
    foreach ($pdo->query('SELECT produkt_id, role, ref, mat_id FROM t2p_produkt_mat ORDER BY produkt_id, role, poradi') as $s)
      $skl[(string) $s['produkt_id']][] = [$s['role'], $s['ref'], $s['mat_id'] === null ? null : (int) $s['mat_id']];
    $mat = array_map(fn($m) => [(int) $m[0], $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]],
                     $pdo->query('SELECT id, ref, nazev, skupina, odpis, stav, druh FROM mat ORDER BY id')->fetchAll(PDO::FETCH_NUM));
    $alias = [];
    foreach ($pdo->query('SELECT ref_alias, mat_id FROM mat_alias') as $a) $alias[$a['ref_alias']] = (int) $a['mat_id'];
    $zm = $pdo->query("SELECT produkt_id, DATE_FORMAT(cas, '%Y-%m-%d %H:%i') AS cas, kdo, zdroj, pole, stara, nova FROM t2p_produkt_zmena ORDER BY id DESC LIMIT 5000")->fetchAll(PDO::FETCH_NUM);
    $cek = $pdo->query("SELECT DISTINCT produkt_id FROM t2p_produkt_propis WHERE stav IN ('čeká', 'chyba')")->fetchAll(PDO::FETCH_COLUMN);
    pa_out(['ok' => true, 'zdroj' => 'sql', 'tabulka' => 'SQL t2p_produkt' . ($cfg['zapis'] ? '' : ' (jen čtení)'), 'kdo' => $kdo,
            'smi_upravovat' => $smiU, 'smi_navrhovat' => $smiN, 'cols' => $cols, 'rows' => $rows, 'skladba' => (object) $skl,
            'mat' => $mat, 'alias' => (object) $alias,
            'zmeny' => array_map(fn($z) => [(int) $z[0], $z[1], $z[2], $z[3], $z[4], $z[5], $z[6]], array_reverse($zm)),
            'cekaji' => array_map('intval', $cek)]);
  }
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') pa_out(['ok' => false, 'error' => 'nepovolená metoda'], 405);
  if (!$cfg['zapis']) pa_out(['ok' => false, 'error' => 'úpravy výrobků zatím nejsou zapnuté']);
  if ($role === 'cteni') pa_out(['ok' => false, 'error' => 'výrobky upravuje jen editor, technik navrhuje nové'], 403);
  $b = json_decode((string) file_get_contents(PHP_SAPI === 'cli' ? 'php://stdin' : 'php://input'), true);
  if (!is_array($b)) pa_out(['ok' => false, 'error' => 'neplatný požadavek'], 400);

  if (isset($b['novy'])) {
    [$p, $err] = pr_create(is_array($b['novy']) ? $b['novy'] : [], $kdo, $role);
    if ($err) pa_out(['ok' => false, 'error' => $err]);
    $ps = $p['stav'] === 'návrh' ? 'ne' : (pr_sync([(int) $p['id']])[(int) $p['id']] === 'hotovo' ? 'hotovo' : 'čeká');
    pr_log($kdo, 'nový ' . $p['kod'] . ' ' . $p['stav'], 'ok propis ' . $ps);
    pa_out(['ok' => true, 'id' => (int) $p['id'], 'verze' => (int) $p['verze'], 'propis' => $ps]);
  }
  $id = (int) ($b['id'] ?? 0); $pole = (string) ($b['pole'] ?? '');
  if (!$id || !in_array($pole, [...PR_POLE, 'skladba', 'stav'], true)) pa_out(['ok' => false, 'error' => 'neplatné pole']);
  [$p, $zm, $konf, $err] = pr_apply($id, $pole, $b['hodnota'] ?? null, $kdo, $role, isset($b['verze']) ? (int) $b['verze'] : null);
  if ($konf) { pr_log($kdo, 'set ' . $id . ' ' . $pole, 'konflikt'); pa_out(['ok' => false, 'konflikt' => $konf]); }
  if ($err) pa_out(['ok' => false, 'error' => $err]);
  $cek = (int) $pdo->query('SELECT COUNT(*) FROM t2p_produkt_propis WHERE produkt_id = ' . $id . " AND stav IN ('čeká', 'chyba')")->fetchColumn();
  $ps = $p['stav'] === 'návrh' || ($p['stav'] === 'neaktivní' && !$cek) ? 'ne' : ($cek ? (pr_sync([$id])[$id] === 'hotovo' ? 'hotovo' : 'čeká') : 'hotovo');
  if ($zm) pr_log($kdo, 'set ' . $p['kod'] . ' ' . $pole, 'ok propis ' . $ps);
  pa_out(['ok' => true, 'verze' => (int) $p['verze'], 'propis' => $ps]);
} catch (Throwable $e) {
  pr_log($kdo ?? '-', 'chyba', substr($e->getMessage(), 0, 200));
  pa_out(['ok' => false, 'error' => 'databáze neodpověděla' . (PHP_SAPI === 'cli' ? ': ' . $e->getMessage() : '')], 502);
}
