<?php
/* =========================================================
   Tiskové sestavy – PDF pro recepci a vedení (krok 32)
   GET ?typ=mzd&kdo=I&mesic=10&rok=26     odměny technika (E-MZD)
   GET ?typ=pvf&kdo=OPL&mesic=10&rok=26   příloha faktury (E-PVF)
   GET ?typ=nabidka&tag=DTO…              kalkulace (F-NAB)
   Přístup: přihlášení formuláře příjmu (cookie), jen čtení.
   Prohlášení výrobce jde dál přes api.php (Trello JWT + archiv).
   ========================================================= */
require __DIR__ . '/prijem-auth.php';
require __DIR__ . '/sestavy-lib.php';
prijem_nocache();
header('X-Content-Type-Options: nosniff');

function sest_chyba(string $m, int $code = 400): void {
  http_response_code($code); header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => false, 'error' => $m], JSON_UNESCAPED_UNICODE); exit;
}

if (!prijem_logged()) sest_chyba('nepřihlášeno', 401);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') sest_chyba('nepovolená metoda', 405);

$typ = (string) ($_GET['typ'] ?? '');
$par = [];
if ($typ === 'mzd' || $typ === 'pvf') {
  $kdo = trim((string) ($_GET['kdo'] ?? ''));
  $ok  = $typ === 'mzd' ? preg_match('/^[A-Z]$/', $kdo) : preg_match('/^[A-Za-z0-9]{3}$/', $kdo);   // technik = písmeno listu, ordinace = 3 znaky
  if (!$ok) sest_chyba($typ === 'mzd' ? 'technik je jedno písmeno' : 'ordinace má 3 znaky');
  $m = (int) ($_GET['mesic'] ?? 0); $r = (int) ($_GET['rok'] ?? 0);
  if ($m < 1 || $m > 12 || $r < 20 || $r > 99) sest_chyba('neplatný měsíc nebo rok (rok dvojmístně)');
  $par = ['kdo' => $kdo, 'mesic' => $m, 'rok' => $r];
  $nazev = 'E-' . strtoupper($typ) . ' ' . $kdo . ' ' . $m . '-' . $r;
} elseif ($typ === 'nabidka') {
  $tag = trim((string) ($_GET['tag'] ?? ''));
  if (!preg_match('/^[A-Za-z0-9]{6,20}$/', $tag)) sest_chyba('neplatný kód zakázky');
  $par = ['tag' => $tag];
  $nazev = 'F-NAB ' . $tag;
} else sest_chyba('neznámá sestava');

$d = sest_data($typ, $par);
if (!$d['ok']) { sest_log('prijem', $typ . ' ' . json_encode($par), 'CHYBA ' . $d['error']); sest_chyba($d['error'], 502); }
try { $pdf = sest_pdf($typ, $d['data']); }
catch (Throwable $e) { sest_log('prijem', $typ, 'PDF ' . substr($e->getMessage(), 0, 200)); sest_chyba('PDF se nepodařilo vytvořit', 500); }
sest_log('prijem', $typ . ' ' . json_encode($par), 'ok ' . strlen($pdf));
header('Content-Type: application/pdf');
header('Content-Disposition: ' . (isset($_GET['dl']) ? 'attachment' : 'inline') . '; filename="' . preg_replace('/[^A-Za-z0-9 ._-]/', '', $nazev) . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
