<?php
/* =========================================================
   BANKA FIO (krok 42) – API záložky „Banka“ ve vykaz.html
   - Ověřený Trello JWT. Tokeny přidává a odebírá jen H.
     H a Brigit vidí pohyby. Nikdo jiný nemá přístup.
   - Token se do odpovědi nikdy nevrací.
   CLI (na serveru):  php fio-api.php akce=stav
                      php fio-api.php akce=stahnout id=<účet> od=2026-01-01
                      php fio-api.php akce=pohyby id=<účet> od=2026-11-01
   ========================================================= */
require_once __DIR__ . '/trello-jwt.php';
require_once __DIR__ . '/fio-lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

const FA_H      = '588fa9e7e5db691bfe653bce';
const FA_BRIGIT = '6b00d5c2c5be2a396f89949e';
function fa_out(array $o, int $code = 200): void { http_response_code($code); echo json_encode($o, JSON_UNESCAPED_UNICODE), PHP_SAPI === 'cli' ? "\n" : ''; exit; }

if (PHP_SAPI === 'cli') {
  $actor = FA_BRIGIT;
  parse_str(implode('&', array_slice($argv, 1)), $par);
  $post = in_array($par['akce'] ?? '', ['stahnout'], true);
} else {
  $why = '';
  $c = tj_jwt((string) ($_SERVER['HTTP_X_TRELLO_JWT'] ?? ''), $why);
  if (!$c) { fio_log('-', 'jwt', 'odmítnuto: ' . $why); fa_out(['ok' => false, 'error' => 'neověřený přístup (Trello JWT: ' . $why . ')'], 401); }
  if (!hash_equals(TJ_PLUGIN, (string) ($c['idPlugin'] ?? ''))) fa_out(['ok' => false, 'error' => 'cizí Power-Up'], 403);
  $actor = (string) ($c['idMember'] ?? '');
  $post = $_SERVER['REQUEST_METHOD'] === 'POST';
  $par = $post ? (json_decode((string) file_get_contents('php://input'), true) ?: []) : $_GET;
}
if ($actor !== FA_H && $actor !== FA_BRIGIT) { fio_log($actor, 'přístup', 'odmítnuto'); fa_out(['ok' => false, 'error' => 'banka je jen pro H'], 403); }
$kdo = $actor === FA_H ? 'H' : 'Brigit';
$akce = (string) ($par['akce'] ?? 'stav');
$dat = fn($s, $def) => preg_match('~^\d{4}-\d{2}-\d{2}$~', (string) $s) ? (string) $s : $def;

try {
  if ($akce === 'pridat') {
    if (!$post || $actor !== FA_H) fa_out(['ok' => false, 'error' => 'token přidává jen H'], 403);
    $r = fio_pridat((string) ($par['nazev'] ?? ''), (string) ($par['token'] ?? ''), $kdo);
    fa_out($r + ['ucty' => fio_stav()], $r['ok'] ? 200 : 400);
  }
  if ($akce === 'odebrat') {
    if (!$post || $actor !== FA_H) fa_out(['ok' => false, 'error' => 'token odebírá jen H'], 403);
    $cfg = fio_cfg(); $id = (string) ($par['id'] ?? '');
    if (!isset($cfg['ucty'][$id])) fa_out(['ok' => false, 'error' => 'neznámý účet'], 404);
    unset($cfg['ucty'][$id]); fio_cfg_save($cfg); fio_log($kdo, 'token odebrán ' . $id, 'ok');   // stažené pohyby zůstávají
    fa_out(['ok' => true, 'ucty' => fio_stav()]);
  }
  if ($akce === 'stahnout') {
    if (!$post) fa_out(['ok' => false, 'error' => 'jen POST'], 405);
    $id = (string) ($par['id'] ?? '');
    $od = $dat($par['od'] ?? '', date('Y-m-d', strtotime('-89 days'))); $do = $dat($par['do'] ?? '', date('Y-m-d'));
    $r = fio_stahni($id, $od, $do, $kdo);
    fa_out($r + ['ucty' => fio_stav()], $r['ok'] ? 200 : 400);
  }
  if ($akce === 'pohyby') {
    $id = (string) ($par['id'] ?? '');
    if (!isset(fio_cfg()['ucty'][$id]) && !is_file(fio_data_file($id))) fa_out(['ok' => false, 'error' => 'neznámý účet'], 404);
    $od = $dat($par['od'] ?? '', date('Y-01-01')); $do = $dat($par['do'] ?? '', date('Y-m-d'));
    $p = array_values(array_filter(fio_data($id)['pohyby'] ?? [], fn($x) => $x['datum'] >= $od && $x['datum'] <= $do));
    fa_out(['ok' => true, 'id' => $id, 'od' => $od, 'do' => $do, 'pohyby' => array_reverse($p)]);
  }
  /* stav: přehled účtů (a jednou za 6 h dotažení nových pohybů) */
  if (!empty($par['auto'])) fio_auto($kdo);
  fa_out(['ok' => true, 'ucty' => fio_stav(), 'smi_pridat' => $actor === FA_H]);
} catch (Throwable $e) {
  fio_log($kdo, 'chyba', substr($e->getMessage(), 0, 200));
  fa_out(['ok' => false, 'error' => 'banka: ' . $e->getMessage()], 502);
}
