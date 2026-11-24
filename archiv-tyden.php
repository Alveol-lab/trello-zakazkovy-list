<?php
/* =========================================================
   Týdenní doplnění archivu (krok 31)
   Timer Forpsi denně v noci:
     https://www.denteo.space/zakazkovy-list/archiv-tyden.php?t=<token>
   Token = HMAC-SHA256('archiv-tyden', klíč Apps Scriptu) – zjistí se
   příkazem  php archiv-tyden.php token  na serveru.
   Skutečná práce běží jednou týdně (den v autolab-archiv.json, výchozí
   neděle); další dny téhož týdne jen dokončí, co se nevešlo do limitu.
   1) fronta: snímek karty + originály fotek a PDF příloh z Trella
   2) po vyprázdnění fronty týdenní manifest (seznam souborů a SHA-256)
   CLI: php archiv-tyden.php [ted] [kontrola]
   ========================================================= */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require __DIR__ . '/prijem-auth.php';
require __DIR__ . '/archiv-lib.php';

const AT_STAV = __DIR__ . '/../../autolab-archiv-stav.json';
const AT_LIMIT = 600;                                // s; max_execution_time je 900
function at_out(array $o, int $code = 200): void { http_response_code($code); echo json_encode($o, JSON_UNESCAPED_UNICODE), "\n"; exit; }

$s = prijem_secret();
$tok = hash_hmac('sha256', 'archiv-tyden', (string) ($s['key'] ?? ''));
$cli = PHP_SAPI === 'cli';
$arg = $cli ? array_slice($argv, 1) : [];
if ($cli && ($arg[0] ?? '') === 'token') { echo $tok, "\n"; exit; }
if (!$cli && !hash_equals($tok, (string) ($_GET['t'] ?? ''))) at_out(['ok' => false, 'error' => 'neplatný token'], 403);
if ($cli && in_array('kontrola', $arg, true)) { $ch = arch_kontrola(); at_out(['ok' => !$ch, 'chyby' => $ch]); }
if (!arch_on()) at_out(['ok' => true, 'vypnuto' => true]);

@set_time_limit(AT_LIMIT + 120);
$start = time();
$ted = new DateTime('now', new DateTimeZone('Europe/Prague'));
$tyden = $ted->format('o-\WW');
$st = is_file(AT_STAV) ? (json_decode((string) file_get_contents(AT_STAV), true) ?: []) : [];
$den = (int) (arch_cfg()['den'] ?? 7);              // 1 = pondělí … 7 = neděle
$vynutit = $cli && in_array('ted', $arg, true);
if (!$vynutit && (($st['tyden'] ?? '') === $tyden && !empty($st['hotovo']) || (int) $ted->format('N') < $den))
  at_out(['ok' => true, 'ceka' => true, 'tyden' => $tyden]);

$db = mat_pdo();
$q = $db->prepare("SELECT pripad_id, pokusy FROM archiv_fronta WHERE krok = 'karta' AND (dalsi_pokus IS NULL OR dalsi_pokus <= ?) ORDER BY pripad_id LIMIT 200");
$q->execute([mat_now()]);
$ok = 0; $chyb = 0; $zbyva = false;
foreach ($q->fetchAll() as $r) {
  if (time() - $start > AT_LIMIT) { $zbyva = true; break; }
  try { $v = arch_karta((int) $r['pripad_id']); } catch (Throwable $e) { $v = ['ok' => false, 'error' => substr($e->getMessage(), 0, 200)]; }
  if (!empty($v['ok'])) { $ok++; continue; }
  $chyb++;
  $p = (int) $r['pokusy'] + 1;
  $db->prepare('UPDATE archiv_fronta SET pokusy = ?, krok = ?, chyba = ?, dalsi_pokus = ?, upraveno = ? WHERE pripad_id = ?')
     ->execute([$p, $p >= ARCH_POKUSY ? 'vzdáno' : 'karta', mb_substr((string) $v['error'], 0, 255),
                (new DateTime('+1 day', new DateTimeZone('Europe/Prague')))->format('Y-m-d H:i:s'), mat_now(), $r['pripad_id']]);
}
if (!$zbyva) { $n = $db->prepare("SELECT COUNT(*) FROM archiv_fronta WHERE krok = 'karta' AND (dalsi_pokus IS NULL OR dalsi_pokus <= ?)"); $n->execute([mat_now()]); $zbyva = (int) $n->fetchColumn() > 0; }
$man = null;
if (!$zbyva) { try { $man = arch_manifest(); } catch (Throwable $e) { $man = ['chyba' => substr($e->getMessage(), 0, 200)]; } }
@file_put_contents(AT_STAV, json_encode(['tyden' => $tyden, 'hotovo' => !$zbyva, 'beh' => mat_now(), 'ok' => $ok, 'chyb' => $chyb], JSON_UNESCAPED_UNICODE), LOCK_EX);
if ($ok || $chyb || $man) arch_log('TIMER', 'týden ' . $tyden, $ok . ' hotovo, ' . $chyb . ' chyba' . ($zbyva ? ', pokračuje' : '') . ($man ? ', manifest ' . json_encode($man, JSON_UNESCAPED_UNICODE) : ''));
at_out(['ok' => true, 'tyden' => $tyden, 'hotovo' => $ok, 'chyb' => $chyb, 'pokracuje' => $zbyva, 'manifest' => $man]);
