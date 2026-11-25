<?php
/* =========================================================
   Dopropis výrobků do listu PRODB (krok 38/5)
   Volá ho Timer Forpsi každých 15 minut:
     https://www.denteo.space/zakazkovy-list/produkt-propis.php?t=<token>
   Token = HMAC-SHA256('produkt-propis', klíč Apps Scriptu) – zjistí se
   příkazem  php produkt-propis.php token  na serveru.
   1) fronta: výrobky ve stavu čeká / chyba (max. 10 pokusů) → prodbSync.
      Do fronty je dává Katalog (úprava výrobku) a od kroku 38/5 i změna
      členství karty MAT ve skupině (mat_pr_dotcene v mat-lib).
   2) jednou denně (první běh po 3:00, nebo ?kontrola=1): suchý prodbSync
      všech výrobků v listu. Rozdíl jen v H / I (rozvinuté skupiny) se zařadí
      do fronty a dopropíše při dalším běhu, ostatní rozdíly jdou jen do logu.
   ========================================================= */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require __DIR__ . '/prijem-auth.php';
require __DIR__ . '/produkt-lib.php';

const PP_STAV = __DIR__ . '/../../autolab-produkt-propis.json';   // čas poslední denní kontroly
function pp_out(array $o, int $code = 200): void { http_response_code($code); echo json_encode($o, JSON_UNESCAPED_UNICODE), "\n"; exit; }

$s = prijem_secret();
$tok = hash_hmac('sha256', 'produkt-propis', (string) ($s['key'] ?? ''));
$cli = PHP_SAPI === 'cli';
if ($cli && ($argv[1] ?? '') === 'token') { echo $tok, "\n"; exit; }
if (!$cli && !hash_equals($tok, (string) ($_GET['t'] ?? ''))) pp_out(['ok' => false, 'error' => 'neplatný token'], 403);
if (!pr_cfg()['propis']) pp_out(['ok' => true, 'vypnuto' => true]);

$kontrola = $cli ? in_array('kontrola', $argv, true) : !empty($_GET['kontrola']);
$st = is_file(PP_STAV) ? (json_decode((string) file_get_contents(PP_STAV), true) ?: []) : [];
$ted = new DateTime('now', new DateTimeZone('Europe/Prague'));
if (!$kontrola && (int) $ted->format('G') >= 3 && ($st['kontrola'] ?? '') !== $ted->format('Y-m-d')) $kontrola = true;

/* suchý prodbSync všech výrobků v listu; H / I rozdíly → fronta */
function pp_kontrola(): array {
  $pdo = mat_pdo();
  $ids = $pdo->query("SELECT id FROM t2p_produkt WHERE stav = 'aktivní' OR list_radek IS NOT NULL ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
  $sum = ['vyrobku' => count($ids), 'rozdily' => 0, 'jen_skladba' => 0, 'chyby' => 0, 'radku_listu' => null];
  $det = []; $doFronty = [];
  foreach (array_chunk($ids, 100) as $ch) {
    $r = mat_gas(['op' => 'prodbSync', 'dry' => true, 'vyrobky' => array_map(fn($id) => pr_karta_sync(pr_by_id((int) $id), pr_skl((int) $id)), $ch)], 120);
    if (empty($r['ok'])) { $sum['chyby'] += count($ch); $det[] = ['chyba' => $r['error'] ?? '?']; continue; }
    foreach ($r['vysledky'] as $i => $x) {
      if (!empty($x['error'])) { $sum['chyby']++; if (count($det) < 50) $det[] = ['kod' => $x['kod'] ?? '?', 'chyba' => $x['error']]; continue; }
      $z = $x['zmeneno'] ?? [];
      if (!$z) continue;
      $sum['rozdily']++;
      if (!array_diff($z, ['h', 'i'])) { $sum['jen_skladba']++; $doFronty[] = (int) $ch[$i]; }
      if (count($det) < 50) $det[] = ['kod' => $x['kod'] ?? '?', 'pole' => $z];
    }
  }
  if ($doFronty) pr_queue($doFronty);
  $c = mat_gas(['op' => 'prodbCheck'], 120);
  if (empty($c['ok'])) { $sum['chyby']++; $det[] = ['prodbCheck' => $c['error'] ?? '?']; }
  else $sum['radku_listu'] = count($c['radky'] ?? []);
  return ['ok' => !$sum['rozdily'] && !$sum['chyby'], 'souhrn' => $sum, 'nalezy' => $det];
}

try {
  $pdo = mat_pdo();
  $ids = $pdo->query("SELECT DISTINCT produkt_id FROM t2p_produkt_propis WHERE stav IN ('čeká', 'chyba') AND pokusu < 10 ORDER BY produkt_id LIMIT 1000")->fetchAll(PDO::FETCH_COLUMN);
  $r = $ids ? pr_sync($ids) : [];
  $ok = count(array_filter($r, fn($x) => $x === 'hotovo'));
  $vzdal = (int) $pdo->query("SELECT COUNT(DISTINCT produkt_id) FROM t2p_produkt_propis WHERE stav = 'chyba' AND pokusu >= 10")->fetchColumn();
  $o = ['ok' => true, 'fronta' => count($ids), 'propsano' => $ok, 'chyba' => count($ids) - $ok, 'vzdano' => $vzdal];
  if ($ids || $vzdal) pr_log('TIMER', 'propis ' . count($ids), $ok . ' hotovo, ' . (count($ids) - $ok) . ' chyba, ' . $vzdal . ' vzdáno');

  if ($kontrola) {
    $k = pp_kontrola();
    $o['kontrola'] = $k;
    $st['kontrola'] = $ted->format('Y-m-d'); $st['vysledek'] = $k;
    @file_put_contents(PP_STAV, json_encode($st, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    pr_log('TIMER', 'kontrola shody PRODB', $k['ok'] ? 'shoda' : json_encode($k['souhrn'], JSON_UNESCAPED_UNICODE));
  }
  pp_out($o);
} catch (Throwable $e) {
  pr_log('TIMER', 'chyba', substr($e->getMessage(), 0, 200));
  pp_out(['ok' => false, 'error' => 'databáze neodpověděla'], 502);
}
