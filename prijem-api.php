<?php
/* =========================================================
   Proxy formuláře příjmu → Apps Script (EVIDENCE)
   Pustí jen přihlášeného, jen povolené dotazy a ke každému
   přidá tajný klíč. Prohlížeč adresu Apps Scriptu nevidí.
   ========================================================= */
require __DIR__ . '/prijem-auth.php';
require __DIR__ . '/mat-lib.php';           // krok 5: karty MAT v SQL (přepínač autolab-mat.json)
prijem_nocache();
header('Content-Type: application/json; charset=utf-8');

function out(array $o, int $code = 200): void { http_response_code($code); echo json_encode($o, JSON_UNESCAPED_UNICODE); exit; }

if (!prijem_logged()) out(['ok' => false, 'error' => 'nepřihlášeno', 'login' => true], 401);
$s = prijem_secret();

const GAS_URL = 'https://script.google.com/macros/s/AKfycbyoF5oQtNIh8YktGX9lNcwj1OwSKbthQovlYIr-vbi2fJ6CN1heJYJxnB96zTVZDQpz/exec';
const GET_OK  = ['matlists', 'matref', 'lotq'];
const OP_OK   = ['edit', 'delete', 'matEdit', 'matNew'];

function gas(string $url, ?string $post = null, ?array $pridej = null): void {
  $c = curl_init($url);
  curl_setopt_array($c, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
    CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60,
  ]);
  if ($post !== null) {
    curl_setopt($c, CURLOPT_POST, true);
    curl_setopt($c, CURLOPT_POSTFIELDS, $post);
    curl_setopt($c, CURLOPT_HTTPHEADER, ['Content-Type: text/plain;charset=utf-8']);
  }
  $r = curl_exec($c);
  $code = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
  if ($r === false || $code !== 200) out(['ok' => false, 'error' => 'Apps Script neodpověděl (HTTP ' . $code . ')'], 502);
  if (json_decode($r) === null) out(['ok' => false, 'error' => 'Apps Script vrátil neplatnou odpověď'], 502);
  if ($pridej) { $j = json_decode($r, true); if (is_array($j)) { foreach ($pridej as $k => $v) if (!isset($j[$k])) $j[$k] = $v; out($j); } }
  echo $r; exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $q = [];
  foreach (GET_OK as $k) if (isset($_GET[$k])) $q[$k] = (string) $_GET[$k];
  if (count($q) !== 1) out(['ok' => false, 'error' => 'nepovolený dotaz'], 400);
  $q['key'] = $s['key'];
  $pridej = null;                             // trvanlivost je jen v SQL (list MAT ji nemá) – formulář z ní ukáže dopočtenou expiraci
  if (isset($q['matref']) && mat_on()) { try { $m = mat_by_ref($q['matref']); if ($m && $m['trvanlivost_mes'] !== null) $pridej = ['trvanlivost_mes' => (int) $m['trvanlivost_mes']]; } catch (Throwable $e) {} }
  gas(GAS_URL . '?' . http_build_query($q), null, $pridej);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $b = json_decode((string) file_get_contents('php://input'), true);
  if (!is_array($b) || ($b['action'] ?? '') !== 'lotCreate') out(['ok' => false, 'error' => 'nepovolená akce'], 400);
  if (isset($b['op'])) { if (!in_array($b['op'], OP_OK, true)) out(['ok' => false, 'error' => 'nepovolená operace'], 400); }
  else $b['src'] = 'prijem';
  unset($b['memberId']);                      // formulář příjmu nikdy nevystupuje za Trello účet
  $pridej = null;
  if (mat_on()) {                             // krok 5: karta MAT se zapisuje do SQL, list dostane propis
    try {
      $op = $b['op'] ?? '';
      $oper = (string) ($b['operator'] ?? '');
      if ($op === 'matEdit') out(mat_op_edit($b, 'PRIJEM'));
      if (($op === 'matNew' || $op === '') && !in_array($oper, ['H', 'M'], true)) out(['ok' => false, 'error' => 'neznámý Trello účet']);
      if ($op === 'matNew') out(mat_op_new($b, $oper));
      if ($op === '') {
        $r = mat_pre_lot($b, $oper, 'prijem');
        if (!$r['ok']) out($r);
        $b = $r['body'];
        if ($r['matRow']) $pridej = ['matRow' => $r['matRow']];
        if (!empty($r['expAuto'])) $pridej = ($pridej ?? []) + ['expAuto' => $r['expAuto']];
      }
    } catch (Throwable $e) {
      mat_log('PRIJEM', 'chyba', substr($e->getMessage(), 0, 200));
      out(['ok' => false, 'error' => 'databáze karet MAT neodpověděla – nic se neuložilo, zkus to znovu'], 502);
    }
  }
  $b['key'] = $s['key'];
  gas(GAS_URL, json_encode($b, JSON_UNESCAPED_UNICODE), $pridej);
}

out(['ok' => false, 'error' => 'nepovolená metoda'], 405);
