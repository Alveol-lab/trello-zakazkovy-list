<?php
/* =========================================================
   Dopropis karet MAT do listu (krok 5)
   Volá ho Timer Forpsi každých 15 minut:
     https://www.denteo.space/zakazkovy-list/mat-propis.php?t=<token>
   Token = HMAC-SHA256('mat-propis', klíč Apps Scriptu) – zjistí se
   příkazem  php mat-propis.php token  na serveru.
   1) fronta: karty ve stavu čeká / chyba (max. 10 pokusů) → matSync
   2) jednou denně (první běh po 3:00, nebo ?kontrola=1): suchý matSync
      všech aktivních karet + matCheck; rozdíly jen do logu, nic nepřepisuje
   ========================================================= */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require __DIR__ . '/prijem-auth.php';
require __DIR__ . '/mat-lib.php';

const MP_STAV = __DIR__ . '/../../autolab-mat-propis.json';     // čas poslední denní kontroly
function mp_out(array $o, int $code = 200): void { http_response_code($code); echo json_encode($o, JSON_UNESCAPED_UNICODE), "\n"; exit; }

$s = prijem_secret();
$tok = hash_hmac('sha256', 'mat-propis', (string) ($s['key'] ?? ''));
$cli = PHP_SAPI === 'cli';
if ($cli && ($argv[1] ?? '') === 'token') { echo $tok, "\n"; exit; }
if (!$cli && !hash_equals($tok, (string) ($_GET['t'] ?? ''))) mp_out(['ok' => false, 'error' => 'neplatný token'], 403);
if (!mat_on()) mp_out(['ok' => true, 'vypnuto' => true]);

$kontrola = $cli ? in_array('kontrola', $argv, true) : !empty($_GET['kontrola']);
$st = is_file(MP_STAV) ? (json_decode((string) file_get_contents(MP_STAV), true) ?: []) : [];
$ted = new DateTime('now', new DateTimeZone('Europe/Prague'));
if (!$kontrola && (int) $ted->format('G') >= 3 && ($st['kontrola'] ?? '') !== $ted->format('Y-m-d')) $kontrola = true;

try {
  $pdo = mat_pdo(); $P = mat_t('mat_propis'); $T = mat_t('mat');
  $ids = $pdo->query("SELECT DISTINCT mat_id FROM $P WHERE stav IN ('čeká', 'chyba') AND pokusu < 10 ORDER BY mat_id LIMIT 1000")->fetchAll(PDO::FETCH_COLUMN);
  $r = $ids ? mat_sync($ids) : [];
  $ok = count(array_filter($r, fn($x) => $x === 'hotovo'));
  $vzdal = (int) $pdo->query("SELECT COUNT(DISTINCT mat_id) FROM $P WHERE stav = 'chyba' AND pokusu >= 10")->fetchColumn();
  $o = ['ok' => true, 'fronta' => count($ids), 'propsano' => $ok, 'chyba' => count($ids) - $ok, 'vzdano' => $vzdal];
  if ($ids || $vzdal) mat_log('TIMER', 'propis ' . count($ids), $ok . ' hotovo, ' . (count($ids) - $ok) . ' chyba, ' . $vzdal . ' vzdáno');

  if ($kontrola) {
    $k = mat_kontrola();
    $o['kontrola'] = $k;
    $st['kontrola'] = $ted->format('Y-m-d'); $st['vysledek'] = $k;
    @file_put_contents(MP_STAV, json_encode($st, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    mat_log('TIMER', 'kontrola shody', $k['ok'] ? 'shoda' : json_encode($k['souhrn'], JSON_UNESCAPED_UNICODE));
  }
  mp_out($o);
} catch (Throwable $e) {
  mat_log('TIMER', 'chyba', substr($e->getMessage(), 0, 200));
  mp_out(['ok' => false, 'error' => 'databáze neodpověděla'], 502);
}
