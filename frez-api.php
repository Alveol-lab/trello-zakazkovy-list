<?php
/* =========================================================
   Příjem dat ze sběrače na PC frézy VHF K5 (krok F2)
   POST JSON {calc: [...], cnc: [...]} s hlavičkou X-Frez-Key
   Klíč sběrače je v autolab-frez.json mimo web (klic_sber).
   Odpověď: {ok, calc, cnc, sparovano} – sběrač si podle ok smaže frontu.
   ========================================================= */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
require __DIR__ . '/frez-lib.php';

function fa_out(array $o, int $code = 200): void { http_response_code($code); echo json_encode($o, JSON_UNESCAPED_UNICODE); exit; }

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
if (PHP_SAPI !== 'cli') {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fa_out(['ok' => false, 'error' => 'nepovolená metoda'], 405);
  $k = (string) (frez_cfg()['klic_sber'] ?? '');
  $h = (string) ($_SERVER['HTTP_X_FREZ_KEY'] ?? '');
  if (strlen($k) < 32 || !hash_equals($k, $h)) { frez_log($ip, 'sber', 'odmítnuto: klíč'); fa_out(['ok' => false, 'error' => 'neověřený přístup'], 401); }
}
if (!frez_sber_on()) fa_out(['ok' => false, 'error' => 'příjem z frézy je vypnutý'], 503);
$raw = (string) file_get_contents(PHP_SAPI === 'cli' ? 'php://stdin' : 'php://input');
if (strlen($raw) > 4_000_000) fa_out(['ok' => false, 'error' => 'příliš velký požadavek'], 413);
$b = json_decode($raw, true);
if (!is_array($b)) fa_out(['ok' => false, 'error' => 'neplatný požadavek'], 400);
try {
  $n = frez_prijem(is_array($b['calc'] ?? null) ? $b['calc'] : [], is_array($b['cnc'] ?? null) ? $b['cnc'] : []);
  frez_log($ip, 'sber', 'ok calc ' . $n['calc'] . ' cnc ' . $n['cnc'] . ' pár ' . $n['sparovano'] . ($n['chyby'] ? ' vadné ' . count($n['chyby']) : ''));
  fa_out(['ok' => true, 'calc' => $n['calc'], 'cnc' => $n['cnc'], 'sparovano' => $n['sparovano'], 'vadne' => count($n['chyby'])]);
} catch (Throwable $e) {
  frez_log($ip, 'sber', 'CHYBA ' . substr($e->getMessage(), 0, 200));
  fa_out(['ok' => false, 'error' => 'databáze neodpověděla' . (PHP_SAPI === 'cli' ? ': ' . $e->getMessage() : '')], 502);
}
