<?php
/* =========================================================
   BANKA FIO (krok 42) – jen čtení pohybů (token „Pouze sledovat účet“)
   - Tokeny leží jen na serveru mimo web (autolab-fio.json, práva 600).
     Do prohlížeče ani do odpovědí API se nikdy nevracejí.
   - Pohyby se ukládají do fio-data/{účet}.json (mimo web), klíč = ID pohybu.
   - Fio pustí jeden dotaz na token za 30 s; starší data než 90 dní jen
     do 10 minut po odemčení historie v internetbankingu.
   - Nic neplatí a nic nezapisuje do Sheets.
   ========================================================= */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }

const FIO_CFG  = __DIR__ . '/../../autolab-fio.json';
const FIO_DATA = __DIR__ . '/../../fio-data';
const FIO_LOG  = __DIR__ . '/../../autolab-fio.log';
const FIO_URL  = 'https://fioapi.fio.cz/v1/rest/';
const FIO_AUTO = 6 * 3600;           // automatické dotažení nových pohybů nejvýš jednou za 6 h

function fio_log(string $kdo, string $co, string $vysl): void {
  @file_put_contents(FIO_LOG, date('c') . "\t" . $kdo . "\t" . $co . "\t" . $vysl . "\n", FILE_APPEND | LOCK_EX);
}
function fio_cfg(): array {
  $j = is_file(FIO_CFG) ? json_decode((string) file_get_contents(FIO_CFG), true) : null;
  return is_array($j) && isset($j['ucty']) ? $j : ['ucty' => []];
}
function fio_cfg_save(array $c): void {
  file_put_contents(FIO_CFG, json_encode($c, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
  @chmod(FIO_CFG, 0600);
}
function fio_data_file(string $id): string { return FIO_DATA . '/' . preg_replace('~[^0-9A-Za-z_-]~', '_', $id) . '.json'; }
function fio_data(string $id): array {
  $f = fio_data_file($id);
  $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
  return is_array($j) ? $j : ['pohyby' => []];
}
function fio_data_save(string $id, array $d): void {
  if (!is_dir(FIO_DATA)) { @mkdir(FIO_DATA, 0700, true); }
  $f = fio_data_file($id);
  file_put_contents($f, json_encode($d, JSON_UNESCAPED_UNICODE), LOCK_EX);
  @chmod($f, 0600);
}

/* jeden dotaz na Fio; vrací [http kód, pole|null]; token se nikdy neloguje */
function fio_get(string $token, string $od, string $do): array {
  $u = FIO_URL . 'periods/' . rawurlencode($token) . '/' . $od . '/' . $do . '/transactions.json';
  $c = curl_init($u);
  curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60]);
  $r = curl_exec($c); $code = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
  return [$code, is_string($r) ? json_decode($r, true) : null];
}
function fio_chyba(int $code): string {
  return [409 => 'Fio pouští jeden dotaz za 30 s – zkus to za chvíli', 500 => 'token neexistuje nebo není aktivní',
          422 => 'data starší než 90 dní – nejdřív v internetbankingu odemkni historii (platí 10 minut)',
          413 => 'příliš mnoho pohybů – zkrať období', 404 => 'chybný dotaz'][$code] ?? ('Fio vrátilo HTTP ' . $code);
}

/* sloupce Fio → náš pohyb */
function fio_pohyb(array $t): array {
  $v = fn($n) => isset($t['column' . $n]['value']) ? $t['column' . $n]['value'] : null;
  return ['id' => (string) $v(22), 'datum' => substr((string) $v(0), 0, 10), 'castka' => (float) $v(1), 'mena' => (string) $v(14),
          'protiucet' => trim((string) $v(2) . ((string) $v(3) !== '' ? '/' . $v(3) : ''), '/'), 'nazev' => (string) $v(10),
          'vs' => (string) $v(5), 'ks' => (string) $v(4), 'ss' => (string) $v(6), 'ident' => (string) $v(7), 'zprava' => (string) $v(16),
          'typ' => (string) $v(8), 'provedl' => (string) $v(9), 'komentar' => (string) $v(25)];
}

/* stáhne období a sloučí do uložených pohybů */
function fio_stahni(string $id, string $od, string $do, string $kdo): array {
  $cfg = fio_cfg(); $u = $cfg['ucty'][$id] ?? null;
  if (!$u) return ['ok' => false, 'error' => 'neznámý účet'];
  $wait = (int) ($u['posledni_dotaz'] ?? 0) + 31 - time();
  if ($wait > 0) return ['ok' => false, 'error' => 'Fio pouští jeden dotaz za 30 s – zkus to za ' . $wait . ' s'];
  $cfg['ucty'][$id]['posledni_dotaz'] = time(); fio_cfg_save($cfg);
  [$code, $j] = fio_get((string) $u['token'], $od, $do);
  $d = fio_data($id);
  if ($code !== 200 || !isset($j['accountStatement'])) {
    $d['chyba'] = fio_chyba($code); $d['chyba_kdy'] = date('c'); fio_data_save($id, $d);
    fio_log($kdo, 'stažení ' . $id . ' ' . $od . '..' . $do, 'chyba ' . $code);
    return ['ok' => false, 'error' => $d['chyba']];
  }
  $info = $j['accountStatement']['info'] ?? [];
  $n = 0;
  foreach (($j['accountStatement']['transactionList']['transaction'] ?? []) as $t) {
    $p = fio_pohyb($t); if ($p['id'] === '') continue;
    if (!isset($d['pohyby'][$p['id']])) $n++;
    $d['pohyby'][$p['id']] = $p;
  }
  uasort($d['pohyby'], fn($a, $b) => strcmp($a['datum'] . $a['id'], $b['datum'] . $b['id']));
  $d['ucet'] = trim(($info['accountId'] ?? '') . '/' . ($info['bankId'] ?? ''), '/'); $d['iban'] = (string) ($info['iban'] ?? '');
  $d['mena'] = (string) ($info['currency'] ?? ''); $d['zustatek'] = $info['closingBalance'] ?? null; $d['zustatek_k'] = $do;
  $d['od'] = min($d['od'] ?? $od, $od); $d['do'] = max($d['do'] ?? $do, $do);
  $d['stazeno'] = date('c'); unset($d['chyba'], $d['chyba_kdy']);
  fio_data_save($id, $d);
  fio_log($kdo, 'stažení ' . $id . ' ' . $od . '..' . $do, 'ok +' . $n);
  return ['ok' => true, 'nove' => $n, 'celkem' => count($d['pohyby'])];
}

/* nový token: ověří ho dotazem na posledních 7 dní a uloží (token se už nikdy nevrací) */
function fio_pridat(string $nazev, string $token, string $kdo): array {
  $token = trim($token);
  if (!preg_match('~^[A-Za-z0-9]{64}$~', $token)) return ['ok' => false, 'error' => 'token Fio má 64 písmen a číslic'];
  $cfg = fio_cfg();
  foreach ($cfg['ucty'] as $u) if (hash_equals((string) $u['token'], $token)) return ['ok' => false, 'error' => 'tento token už je uložený'];
  [$code, $j] = fio_get($token, date('Y-m-d', strtotime('-7 days')), date('Y-m-d'));
  if ($code !== 200 || !isset($j['accountStatement']['info'])) { fio_log($kdo, 'nový token', 'chyba ' . $code); return ['ok' => false, 'error' => fio_chyba($code)]; }
  $info = $j['accountStatement']['info'];
  $id = preg_replace('~\D~', '', (string) ($info['accountId'] ?? '')) ?: substr(hash('sha256', $token), 0, 12);
  $cfg['ucty'][$id] = ['nazev' => trim($nazev) !== '' ? trim($nazev) : ('Fio ' . $id), 'token' => $token,
                       'ucet' => trim(($info['accountId'] ?? '') . '/' . ($info['bankId'] ?? ''), '/'),
                       'pridano' => date('c'), 'kdo' => $kdo, 'posledni_dotaz' => time()];
  fio_cfg_save($cfg);
  /* pohyby z ověřovacího dotazu rovnou uložit */
  $d = fio_data($id);
  foreach (($j['accountStatement']['transactionList']['transaction'] ?? []) as $t) { $p = fio_pohyb($t); if ($p['id'] !== '') $d['pohyby'][$p['id']] = $p; }
  $d['ucet'] = $cfg['ucty'][$id]['ucet']; $d['iban'] = (string) ($info['iban'] ?? ''); $d['mena'] = (string) ($info['currency'] ?? '');
  $d['zustatek'] = $info['closingBalance'] ?? null; $d['zustatek_k'] = date('Y-m-d');
  $d['od'] = min($d['od'] ?? '9999', date('Y-m-d', strtotime('-7 days'))); $d['do'] = date('Y-m-d'); $d['stazeno'] = date('c');
  fio_data_save($id, $d);
  fio_log($kdo, 'nový token ' . $id, 'ok');
  return ['ok' => true, 'id' => $id];
}

/* přehled účtů bez tokenů */
function fio_stav(): array {
  $o = [];
  foreach (fio_cfg()['ucty'] as $id => $u) {
    $d = fio_data((string) $id);
    $o[] = ['id' => (string) $id, 'nazev' => $u['nazev'], 'ucet' => $u['ucet'], 'pridano' => $u['pridano'] ?? '',
            'pohybu' => count($d['pohyby'] ?? []), 'od' => $d['od'] ?? null, 'do' => $d['do'] ?? null, 'stazeno' => $d['stazeno'] ?? null,
            'zustatek' => $d['zustatek'] ?? null, 'mena' => $d['mena'] ?? '', 'chyba' => $d['chyba'] ?? null];
  }
  return $o;
}

/* dotáhne nové pohyby, pokud je poslední stažení starší než 6 h (posledních 5 dní s překryvem, nejvýš 89 dní zpět) */
function fio_auto(string $kdo): void {
  foreach (fio_cfg()['ucty'] as $id => $u) {
    $d = fio_data((string) $id);
    if (!empty($d['stazeno']) && strtotime($d['stazeno']) > time() - FIO_AUTO) continue;
    $od = max(date('Y-m-d', strtotime(($d['do'] ?? date('Y-m-d')) . ' -5 days')), date('Y-m-d', strtotime('-89 days')));
    fio_stahni((string) $id, $od, date('Y-m-d'), $kdo);
  }
}
