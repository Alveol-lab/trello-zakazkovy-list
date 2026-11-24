<?php
/* =========================================================
   AutoLAB – karta materiálu v SQL (etapa 2a, krok 5)
   Jedno místo pro pravidla karty MAT. Volají ho:
     prijem-api.php  – ozubené kolo (matEdit, matNew) a příjem (matNew, matFill)
     api.php         – zakázkový list (nová karta spolu s balením)
     mat-api.php     – prohlížeč tabulek (úpravy v buňce, hromadně, ceny, stav)
     mat-propis.php  – Timer: dočerpání fronty a denní kontrola shody
   Po přepnutí je zdrojem pravdy SQL. List MAT dostává změny hned
   přes Apps Script matSync; když propis selže, zůstane ve frontě.
   Přepínač: soubor autolab-mat.json mimo web, {"sql": true, "tab": "mat"}.
   Bez souboru (nebo "sql": false) jde všechno postaru do Apps Scriptu.
   Pravidla a texty chyb odpovídají Lot.gs (matValid_, matEdit_,
   matNewOnly_, matFill_), aby se formuláře chovaly stejně.
   ========================================================= */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }

const MAT_CFG_FILE = __DIR__ . '/../../autolab-mat.json';
const MAT_DB_CNF   = __DIR__ . '/../../autolab-db.cnf';
const MAT_LOG      = __DIR__ . '/../../autolab-api.log';
const MAT_GAS_URL  = 'https://script.google.com/macros/s/AKfycbyoF5oQtNIh8YktGX9lNcwj1OwSKbthQovlYIr-vbi2fJ6CN1heJYJxnB96zTVZDQpz/exec';
const MAT_ODPIS    = ['auto', 'ruční'];
const MAT_POLE_UI  = ['nazev', 'kat', 'forma', 'jednotek', 'vyrobce', 'dodavatel', 'skupina', 'odpis',
                      'cena_nakup', 'navyseni_pct', 'stav', 'trvanlivost_mes'];
const MAT_TRV_DEF  = 24;     // trvanlivost balení bez expirace na obalu (měsíce), karta ji může přepsat (24. 11.)
/* Trello účet → technik (list v EVIDENCE). Kopie MEMBER_SHEET_MAP z Apps Scriptu
   a MEMBERS ze section3.html – při změně technika upravit všechna tři místa. */
const MAT_TECHNIK = [
  '588fa9e7e5db691bfe653bce' => 'H', '611cc39d9beb8e6028ba3ae4' => 'J', '63cd0523a27f8901400a6dda' => 'Z',
  '63ea51fbb262b19c0189e9b8' => 'P', '686d1aa4bc747a96bb0fd585' => 'U', '63e911bcb90ab76e81ad4e6a' => 'I',
];

function mat_cfg(): array {
  static $c = null;
  if ($c === null) {
    $j = is_file(MAT_CFG_FILE) ? json_decode((string) file_get_contents(MAT_CFG_FILE), true) : null;
    $c = is_array($j) ? $j : [];
    $c['sql'] = ($c['sql'] ?? false) === true;
    $c['tab'] = in_array($c['tab'] ?? '', ['mat', 't2a_mat'], true) ? $c['tab'] : 'mat';
    if (defined('MAT_TAB')) $c['tab'] = MAT_TAB;      // kontrola před přepnutím: sada tabulek bez ohledu na přepínač
  }
  return $c;
}
function mat_on(): bool { return mat_cfg()['sql']; }
/* názvy tabulek: mat, mat_zmena, mat_propis, mat_skupina, mat_alias (test: t2a_…) */
function mat_t(string $k): string { return (mat_cfg()['tab'] === 't2a_mat' ? 't2a_' : '') . $k; }

function mat_log(string $kdo, string $co, string $vysl): void {
  @file_put_contents(MAT_LOG, date('c') . "\t" . $kdo . "\tmat " . $co . "\t" . $vysl . "\n", FILE_APPEND | LOCK_EX);
}

function mat_pdo(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $cfg = @parse_ini_file(MAT_DB_CNF, true, INI_SCANNER_RAW);
  $c = $cfg['client'] ?? null;
  if (!$c || empty($c['host']) || empty($c['user']) || !isset($c['password'])) throw new RuntimeException('server: chybí konfigurace databáze');
  $db = $c['database'] ?? $c['user'];
  $pdo = new PDO("mysql:host={$c['host']};dbname={$db};charset=utf8mb4", $c['user'], trim($c['password'], "\"'"), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_TIMEOUT => 5, PDO::ATTR_EMULATE_PREPARES => false,
  ]);
  return $pdo;
}

function mat_txt($v): string { return trim(str_replace("\u{00A0}", ' ', (string) ($v ?? ''))); }
function mat_num($v): ?float {
  if ($v === null || $v === '') return null;
  $s = str_replace([' ', ','], ['', '.'], (string) $v);
  return is_numeric($s) ? (float) $s : null;
}
function mat_ref_key($v): string { return mb_strtoupper(mat_txt($v)); }
function mat_now(): string { return (new DateTime('now', new DateTimeZone('Europe/Prague')))->format('Y-m-d H:i:s'); }
function mat_today(): string { return substr(mat_now(), 0, 10); }

function mat_by_ref(string $ref, bool $lock = false): ?array {
  $st = mat_pdo()->prepare('SELECT * FROM ' . mat_t('mat') . ' WHERE ref = ?' . ($lock ? ' FOR UPDATE' : ''));
  $st->execute([mat_txt($ref)]);                       // kolace _ai_ci: bez ohledu na velikost písmen
  return $st->fetch() ?: null;
}
function mat_by_id(int $id, bool $lock = false): ?array {
  $st = mat_pdo()->prepare('SELECT * FROM ' . mat_t('mat') . ' WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
  $st->execute([$id]);
  return $st->fetch() ?: null;
}
function mat_skupina_ok(string $kod): bool {
  $st = mat_pdo()->prepare('SELECT 1 FROM ' . mat_t('mat_skupina') . ' WHERE kod = ?');
  $st->execute([$kod]);
  return (bool) $st->fetchColumn();
}

/* Krok 36b: typ šarže je vlastnost skupiny (IDID = ruční DID, ADID = AutoDID). Platí vždy:
   odpis skladové karty ve skupině s typem se odvodí ze skupiny. MD karta skupinu nemá.
   Bez migrace 093 (sloupec mat_skupina.typ_sarze) vrací prázdno a nic se nevynucuje. */
const MAT_TYP_ODPIS = ['IDID' => 'ruční', 'ADID' => 'auto'];
function mat_sk_typy(bool $znovu = false): array {
  static $t = null;
  if ($t === null || $znovu) {
    $t = [];
    try { foreach (mat_pdo()->query('SELECT kod, typ_sarze FROM ' . mat_t('mat_skupina') . ' WHERE typ_sarze IS NOT NULL') as $r) $t[$r['kod']] = $r['typ_sarze']; }
    catch (Throwable $e) {}
  }
  return $t;
}
function mat_sk_odpis(?string $sk): ?string { $t = mat_sk_typy(); return $sk !== null && isset($t[$sk]) ? MAT_TYP_ODPIS[$t[$sk]] : null; }

/* ---------------------------------------------------------------------
   Kontrola a normalizace hodnot. $z = změny {pole: hodnota}; vrací
   [hodnoty, chyba]. Pole, která v $z nejsou, se nekontrolují (úprava),
   u nové karty se volá s úplnou sadou.
   --------------------------------------------------------------------- */
function mat_check(array $z, bool $ui = false): array {
  $v = [];
  foreach ($z as $k => $x) {
    if (!in_array($k, MAT_POLE_UI, true)) continue;
    if (!$ui && in_array($k, ['cena_nakup', 'navyseni_pct', 'stav', 'trvanlivost_mes'], true)) continue;   // ceny a stav jen z prohlížeče
    $v[$k] = is_string($x) || is_numeric($x) ? mat_txt($x) : ($x === null ? '' : '');
  }
  if (array_key_exists('nazev', $v) && $v['nazev'] === '') return [null, 'název nesmí být prázdný'];
  if (array_key_exists('kat', $v) && !preg_match('/^[FROU][MS]$/', $v['kat'])) return [null, 'kategorie musí být F / R / O / U + M / S'];
  if (array_key_exists('vyrobce', $v) && $v['vyrobce'] === '') return [null, 'výrobce nesmí být prázdný'];
  if (array_key_exists('forma', $v)) {
    $f = ['ks' => 'kus', 'bal' => 'balení', 'kus' => 'kus', 'balení' => 'balení'][$v['forma']] ?? null;
    if (!$f) return [null, 'forma musí být kus / balení'];
    $v['forma'] = $f;
    if ($f === 'kus') $v['jednotek'] = '1';
  }
  if (array_key_exists('jednotek', $v)) {
    $j = mat_num($v['jednotek']);
    if (!($j > 0)) return [null, 'počet ks v 1 balení musí být kladné číslo'];
    $v['jednotek'] = $j;
  }
  if (array_key_exists('skupina', $v)) {
    $v['skupina'] = mb_strtoupper($v['skupina']);
    if ($v['skupina'] === '' || $v['skupina'] === 'SAMOSTATNÝ') $v['skupina'] = null;
    elseif (!preg_match('/^SK-[A-Z0-9-]+$/', $v['skupina'])) return [null, 'skupina musí mít tvar SK-…'];
    elseif (!mat_skupina_ok($v['skupina'])) return [null, 'skupina ' . $v['skupina'] . ' neexistuje – nové skupiny zakládá jen Honza'];
  }
  if (array_key_exists('odpis', $v) && !in_array($v['odpis'], MAT_ODPIS, true)) return [null, 'odpis musí být auto / ruční'];
  if (array_key_exists('dodavatel', $v) && $v['dodavatel'] === '') $v['dodavatel'] = null;
  if (array_key_exists('cena_nakup', $v)) {
    if ($v['cena_nakup'] === '') $v['cena_nakup'] = null;
    else { $c = mat_num($v['cena_nakup']); if ($c === null || $c < 0) return [null, 'cena musí být číslo ≥ 0']; $v['cena_nakup'] = round($c, 2); }
  }
  if (array_key_exists('navyseni_pct', $v)) {
    $n = mat_num($v['navyseni_pct']);
    if ($n === null || $n < 0 || $n > 100) return [null, 'navýšení musí být 0 až 100 %'];
    $v['navyseni_pct'] = round($n, 2);
  }
  if (array_key_exists('trvanlivost_mes', $v)) {     // prázdné = výchozích MAT_TRV_DEF měsíců
    if ($v['trvanlivost_mes'] === '') $v['trvanlivost_mes'] = null;
    else { $t = mat_num($v['trvanlivost_mes']);
      if ($t === null || $t != floor($t) || $t < 1 || $t > 120) return [null, 'trvanlivost musí být celý počet měsíců 1 až 120 (prázdné = ' . MAT_TRV_DEF . ')'];
      $v['trvanlivost_mes'] = (int) $t; }
  }
  if (array_key_exists('stav', $v) && !in_array($v['stav'], ['aktivní', 'neaktivní'], true)) return [null, 'stav musí být aktivní / neaktivní'];
  return [$v, ''];
}

/* matValid_ z Lot.gs – nová karta z příjmu, ze zakázkového listu i z ozubeného kola */
function mat_valid_new(array $mn, bool $plna): string {
  if (mat_txt($mn['nazev'] ?? '') === '') return 'chybí název';
  if (!preg_match('/^[FROU][MS]$/', (string) ($mn['kat'] ?? ''))) return 'chybí kategorie';
  if (mat_txt($mn['vyrobce'] ?? '') === '') return 'chybí výrobce';
  if (array_key_exists('odpis', $mn) && $mn['odpis'] !== null && !in_array((string) $mn['odpis'], MAT_ODPIS, true)) return 'chybí odpis (auto / ruční)';
  if (!empty($mn['skupina']) && !preg_match('/^SK-[A-Z0-9-]+$/', mb_strtoupper((string) $mn['skupina']))) return 'skupina musí mít tvar SK-…';
  if ($plna) {                                         // matNewOnly_: ozubené kolo / prohlížeč
    if (!in_array($mn['forma'] ?? '', ['ks', 'bal', 'kus', 'balení'], true)) return 'vyber formu kus / balení';
    if (in_array($mn['forma'], ['bal', 'balení'], true) && !(mat_num($mn['jednotek'] ?? null) > 0)) return 'doplň počet kusů v 1 balení';
    if (!in_array((string) ($mn['odpis'] ?? ''), MAT_ODPIS, true) && mat_sk_odpis(empty($mn['skupina']) ? null : mb_strtoupper((string) $mn['skupina'])) === null) return 'chybí odpis (auto / ruční)';
  }
  return '';
}

/* ---------------------------------------------------------------------
   Zápis: úprava karty v jedné transakci – řádek se zamkne, porovná se
   verze (jen u prohlížeče), zapíší se jen opravdu změněná pole, do
   deníku změn a do fronty propisu. Vrací [karta, změněná pole, konflikt].
   --------------------------------------------------------------------- */
function mat_same(string $k, $a, $b): bool {
  if (in_array($k, ['jednotek', 'cena_nakup', 'navyseni_pct'], true)) {
    $x = mat_num($a); $y = mat_num($b);
    return $x === null || $y === null ? $x === $y : abs($x - $y) < 0.0005;
  }
  return (string) ($a ?? '') === (string) ($b ?? '');
}
function mat_apply(int $id, array $v, string $kdo, string $zdroj, ?int $verze = null, ?int $davka = null): array {
  $pdo = mat_pdo(); $T = mat_t('mat');
  $pdo->beginTransaction();
  try {
    $m = mat_by_id($id, true);
    if (!$m) { $pdo->rollBack(); return [null, [], null, 'karta neexistuje']; }
    if ($verze !== null && (int) $m['verze'] !== $verze) {
      $z = $pdo->prepare('SELECT kdo, cas, pole, nova FROM ' . mat_t('mat_zmena') . ' WHERE mat_id = ? ORDER BY id DESC LIMIT 1');
      $z->execute([$id]); $z = $z->fetch() ?: ['kdo' => $m['upravil'] ?? '?', 'cas' => $m['upraveno'], 'pole' => '?', 'nova' => ''];
      $pdo->rollBack();
      return [$m, [], ['kdo' => $z['kdo'], 'kdy' => $z['cas'], 'pole' => $z['pole'], 'hodnota' => $z['nova']], ''];
    }
    if (($v['forma'] ?? null) === 'kus') $v['jednotek'] = 1.0;
    if (($v['stav'] ?? null) === 'neaktivní' && $m['stav'] !== 'neaktivní' && mat_otevrena($m['ref']) > 0) {
      $pdo->rollBack(); return [$m, [], null, 'vyřadit nejde: na kartě jsou otevřená balení (ZALOŽENO / NASAZENO)'];
    }
    $sk = array_key_exists('skupina', $v) ? $v['skupina'] : $m['skupina'];
    if ($m['druh'] === 'MD' && array_key_exists('skupina', $v) && $v['skupina'] !== null) {
      $pdo->rollBack(); return [$m, [], null, 'MD karta (zakázkový materiál) nemá skupinu – MDID se na skupinu neváže'];
    }
    if ($m['druh'] !== 'MD' && ($od = mat_sk_odpis($sk)) !== null) {
      if (array_key_exists('odpis', $v) && $v['odpis'] !== $od && !array_key_exists('skupina', $v)) {
        $pdo->rollBack(); return [$m, [], null, 'odpis určuje skupina ' . $sk . ' (' . mat_sk_typy()[$sk] . ' = ' . $od . ') – změň typ skupiny nebo kartu přeřaď'];
      }
      $v['odpis'] = $od;                              // přeřazení do skupiny = odpis podle jejího typu
    }
    $zm = [];
    foreach ($v as $k => $x) if (!mat_same($k, $m[$k], $x)) $zm[$k] = $x;
    if (isset($zm['cena_nakup'])) $zm['cena_datum'] = mat_today();
    if (!$zm) { $pdo->commit(); return [$m, [], null, '']; }
    $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($zm)));
    $now = mat_now();
    $pdo->prepare("UPDATE $T SET $set, upravil = ?, upraveno = ?, verze = verze + 1 WHERE id = ?")
        ->execute([...array_values($zm), $kdo, $now, $id]);
    $sD = $davka !== null && mat_ma_davku();
    $ins = $pdo->prepare('INSERT INTO ' . mat_t('mat_zmena') . ' (mat_id, cas, kdo, zdroj, pole, stara, nova' . ($sD ? ', davka' : '') . ') VALUES (?, ?, ?, ?, ?, ?, ?' . ($sD ? ', ?' : '') . ')');
    foreach ($zm as $k => $x) {
      if ($k === 'cena_datum') continue;
      $r = [$id, $now, $kdo, $zdroj, $k, $m[$k] === null ? null : mb_substr((string) $m[$k], 0, 200), $x === null ? null : mb_substr((string) $x, 0, 200)];
      if ($sD) $r[] = $davka;
      $ins->execute($r);
    }
    if (array_diff(array_keys($zm), ['cena_nakup', 'cena_datum', 'navyseni_pct', 'stav'])) mat_queue([$id]);  // ceny a stav list MAT nenese
    $pdo->commit();
    return [mat_by_id($id), array_values(array_diff(array_keys($zm), ['cena_datum'])), null, ''];
  } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function mat_create(array $mn, string $ref, string $kdo, string $zdroj, string $druh = 'sklad'): array {
  $druh = $druh === 'MD' ? 'MD' : 'sklad';
  $pdo = mat_pdo(); $T = mat_t('mat');
  $mn = array_filter(array_intersect_key($mn, array_flip(['nazev', 'kat', 'forma', 'jednotek', 'vyrobce', 'dodavatel', 'skupina', 'odpis'])),
                     fn($x, $k) => !in_array($k, ['jednotek', 'forma', 'odpis'], true) || ($x !== null && $x !== ''), ARRAY_FILTER_USE_BOTH);
  [$v, $err] = mat_check($mn);
  if ($err) return [null, $err];
  if ($druh === 'MD') $v['skupina'] = null;
  elseif (($od = mat_sk_odpis($v['skupina'] ?? null)) !== null) $v['odpis'] = $od;
  if (!array_key_exists('jednotek', $v) || $v['jednotek'] === null) $v['jednotek'] = 1.0;
  if (!array_key_exists('forma', $v)) { $v['forma'] = 'balení'; $v['jednotek'] = mat_num($mn['jednotek'] ?? null) ?: 1.0; }  // jako matCreate_: bez formy = balení
  $pdo->beginTransaction();
  try {
    $ex = mat_by_ref($ref, true);
    if ($ex) { $pdo->commit(); return [$ex, '']; }      // mezitím ji někdo založil → existující karta
    $now = mat_now();
    $pdo->prepare("INSERT INTO $T (ref, nazev, kat, forma, jednotek, vyrobce, dodavatel, skupina, odpis, druh, zalozil, zalozeno, upravil, upraveno)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([mat_txt($ref), $v['nazev'], $v['kat'] ?? null, $v['forma'], $v['jednotek'] ?? null, $v['vyrobce'] ?? null,
                   $v['dodavatel'] ?? null, $v['skupina'] ?? null, $v['odpis'] ?? null, $druh, $kdo, substr($now, 0, 10), $kdo, $now]);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO ' . mat_t('mat_zmena') . ' (mat_id, cas, kdo, zdroj, pole, stara, nova) VALUES (?, ?, ?, ?, ?, NULL, ?)')
        ->execute([$id, $now, $kdo, $zdroj, 'karta', 'založena']);
    mat_queue([$id]);
    $pdo->commit();
    return [mat_by_id($id), ''];
  } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

/* otevřená balení podle stínu s1_baleni (obnova stínu je zatím ruční, viz doc 16) */
function mat_otevrena(string $ref): int {
  try {
    $st = mat_pdo()->prepare("SELECT COUNT(*) FROM s1_baleni WHERE ref = ? AND status IN ('ZALOŽENO', 'NASAZENO')");
    $st->execute([$ref]); return (int) $st->fetchColumn();
  } catch (Throwable $e) { return 0; }
}

/* ---------------------------------------------------------------------
   Propis do listu MAT – fronta + Apps Script matSync
   --------------------------------------------------------------------- */
function mat_queue(array $ids): void {
  $P = mat_t('mat_propis');
  $sel = mat_pdo()->prepare("SELECT 1 FROM $P WHERE mat_id = ? AND stav IN ('čeká', 'chyba') LIMIT 1");
  $ins = mat_pdo()->prepare("INSERT INTO $P (mat_id, cas, stav) VALUES (?, ?, 'čeká')");
  foreach (array_unique($ids) as $id) { $sel->execute([$id]); if (!$sel->fetchColumn()) $ins->execute([$id, mat_now()]); }
}

function mat_gas(array $body, int $timeout = 60): array {
  $s = function_exists('prijem_secret') ? prijem_secret() : null;
  if (empty($s['key'])) return ['ok' => false, 'error' => 'server: chybí klíč pro Apps Script'];
  $body = ['action' => 'lotCreate', 'key' => $s['key']] + $body;
  $url = getenv('MAT_GAS_URL') ?: MAT_GAS_URL;        // test: mock Apps Scriptu
  $c = curl_init($url);
  curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_POSTREDIR => 0,
    CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => $timeout, CURLOPT_HTTPHEADER => ['Content-Type: text/plain;charset=utf-8']]);
  $r = curl_exec($c); $code = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
  $j = is_string($r) ? json_decode($r, true) : null;
  if ($code !== 200 || !is_array($j)) return ['ok' => false, 'error' => 'Apps Script neodpověděl (HTTP ' . $code . ')'];
  return $j;
}

function mat_karta_sync(array $m): array {           // řádek SQL → karta pro matSync
  return ['ref' => $m['ref'], 'ref_list' => $m['ref_list'], 'radek' => $m['list_radek'] === null ? null : (int) $m['list_radek'],
          'stav' => $m['stav'], 'nazev' => $m['nazev'], 'kat' => $m['kat'], 'forma' => $m['forma'],
          'jednotek' => $m['jednotek'] === null ? null : (float) $m['jednotek'], 'vyrobce' => $m['vyrobce'],
          'dodavatel' => $m['dodavatel'], 'zalozil' => $m['zalozil'], 'zalozeno' => $m['zalozeno'],
          'skupina' => $m['skupina'], 'odpis' => $m['odpis'], 'druh' => $m['druh']];
}

/* Propíše karty (id) do listu. Vrací {id: 'hotovo' | 'chyba: …'}. Fronta se
   aktualizuje pro všechny čekající záznamy daných karet. */
function mat_sync(array $ids): array {
  $ids = array_values(array_unique(array_map('intval', $ids)));
  if (!$ids) return [];
  $pdo = mat_pdo(); $T = mat_t('mat'); $P = mat_t('mat_propis');
  $in = implode(',', array_fill(0, count($ids), '?'));
  $st = $pdo->prepare("SELECT * FROM $T WHERE id IN ($in)"); $st->execute($ids);
  $rows = []; foreach ($st->fetchAll() as $m) $rows[(int) $m['id']] = $m;
  $out = [];
  foreach (array_chunk(array_keys($rows), 200) as $chunk) {
    $karty = array_map(fn($i) => mat_karta_sync($rows[$i]), $chunk);
    $r = mat_gas(['op' => 'matSync', 'karty' => $karty]);
    $vys = ($r['ok'] ?? false) ? ($r['vysledky'] ?? []) : [];
    foreach ($chunk as $n => $id) {
      $x = $vys[$n] ?? null;
      if ($x && mat_ref_key($x['ref'] ?? '') !== mat_ref_key($rows[$id]['ref'])) $x = null;   // pořadí musí sedět
      if ($x && empty($x['error'])) {
        $out[$id] = 'hotovo';
        if (!empty($x['row']) && (int) $x['row'] !== (int) $rows[$id]['list_radek'])
          $pdo->prepare("UPDATE $T SET list_radek = ?, ref_list = COALESCE(ref_list, ref) WHERE id = ?")->execute([(int) $x['row'], $id]);
        elseif (!empty($x['row']) && $rows[$id]['ref_list'] === null)
          $pdo->prepare("UPDATE $T SET ref_list = ref WHERE id = ?")->execute([$id]);
        $pdo->prepare("UPDATE $P SET stav = 'hotovo', pokusu = pokusu + 1, chyba = NULL WHERE mat_id = ? AND stav IN ('čeká', 'chyba')")->execute([$id]);
      } else {
        $e = $x['error'] ?? ($r['error'] ?? 'neznámá odpověď');
        $out[$id] = 'chyba: ' . $e;
        $pdo->prepare("UPDATE $P SET stav = 'chyba', pokusu = pokusu + 1, chyba = ? WHERE mat_id = ? AND stav IN ('čeká', 'chyba')")
            ->execute([mb_substr($e, 0, 300), $id]);
      }
    }
  }
  return $out;
}

function mat_out_karta(array $m): array {             // tvar odpovědi matEdit_ z Lot.gs
  return ['ref' => $m['ref'], 'nazev' => $m['nazev'], 'kat' => (string) $m['kat'],
          'jednotek' => $m['jednotek'] === null ? '' : (float) $m['jednotek'], 'vyrobce' => (string) $m['vyrobce'],
          'dodavatel' => (string) $m['dodavatel'], 'forma' => (string) $m['forma'],
          'skupina' => (string) $m['skupina'], 'odpis' => (string) $m['odpis']];
}

/* ---------------------------------------------------------------------
   Operace formuláře příjmu (ozubené kolo) – stejné vstupy a odpovědi
   jako matEdit_ a matNewOnly_ v Lot.gs
   --------------------------------------------------------------------- */
function mat_op_edit(array $b, string $kdo): array {
  $ref = mat_txt($b['ref'] ?? '');
  if ($ref === '') return ['ok' => false, 'error' => 'chybí REF'];
  $z = is_array($b['zmeny'] ?? null) ? array_intersect_key($b['zmeny'], array_flip(['nazev', 'kat', 'forma', 'jednotek', 'vyrobce', 'dodavatel', 'skupina', 'odpis'])) : [];
  if (!$z) return ['ok' => false, 'error' => 'žádná změna'];
  [$v, $err] = mat_check($z);
  if ($err) return ['ok' => false, 'error' => $err];
  $m = mat_by_ref($ref);
  if (!$m) return ['ok' => false, 'error' => 'REF ' . mb_strtoupper($ref) . ' v MAT není'];
  if ($m['stav'] === 'neaktivní') return ['ok' => false, 'error' => 'karta ' . $m['ref'] . ' je vyřazená – vrátit ji jde v prohlížeči tabulek'];
  [$m, $zm, , $e2] = mat_apply((int) $m['id'], $v, $kdo, 'prijem');
  if ($e2) return ['ok' => false, 'error' => $e2];
  $p = $zm ? (mat_sync([(int) $m['id']])[(int) $m['id']] ?? 'hotovo') : 'hotovo';
  mat_log($kdo, 'matEdit ' . $m['ref'], 'ok ' . implode(',', $zm) . ' propis ' . $p);
  $m = mat_by_id((int) $m['id']);
  return ['ok' => true, 'row' => $m['list_radek'] === null ? null : (int) $m['list_radek'], 'zmeneno' => $zm,
          'karta' => mat_out_karta($m), 'propis' => $p === 'hotovo' ? 'hotovo' : 'čeká'];
}

function mat_op_new(array $b, string $kdo, string $zdroj = 'prijem'): array {
  $mn = is_array($b['matNew'] ?? null) ? $b['matNew'] : [];
  $ref = mat_txt($mn['ref'] ?? '');
  if ($ref === '') return ['ok' => false, 'error' => 'chybí REF'];
  $err = mat_valid_new($mn, true);
  if ($err) return ['ok' => false, 'error' => 'nová karta MAT: ' . $err];
  $ex = mat_by_ref($ref);
  if ($ex) return ['ok' => false, 'error' => 'REF ' . mb_strtoupper($ref) . ' už v MAT je' . ($ex['list_radek'] ? ' (řádek ' . $ex['list_radek'] . ')' : '')
                   . ($ex['stav'] === 'neaktivní' ? ' – karta je vyřazená, vrátit ji jde v prohlížeči' : ''), 'row' => $ex['list_radek']];
  [$m, $e2] = mat_create($mn, $ref, $kdo, $zdroj);
  if ($e2) return ['ok' => false, 'error' => 'nová karta MAT: ' . $e2];
  $p = mat_sync([(int) $m['id']])[(int) $m['id']] ?? 'chyba';
  $m = mat_by_id((int) $m['id']);
  mat_log($kdo, 'matNew ' . $m['ref'], 'ok propis ' . $p);
  return ['ok' => true, 'matRow' => $m['list_radek'] === null ? null : (int) $m['list_radek'], 'ref' => mb_strtoupper($m['ref']),
          'propis' => $p === 'hotovo' ? 'hotovo' : 'čeká'];
}

/* ---------------------------------------------------------------------
   Před lotCreate (příjem i zakázkový list): nová karta / doplnění formy
   jde do SQL a hned do listu; Apps Script pak dostane balení už bez
   matNew / matFill. Když se nová karta nepropíše, balení se nezaloží
   (AutoDID a matInfo čtou list) – formulář ukáže chybu, opakování
   kartu nezaloží znovu, jen ji dopropíše.
   Vrací ['ok' => true, 'body' => upravené tělo, 'matRow' => …] nebo chybu.
   --------------------------------------------------------------------- */
/* Expirace balení, když ji obal neuvádí: dnes + trvanlivost karty (výchozí
   MAT_TRV_DEF měsíců). 29.–31. den bez protějšku → poslední den měsíce,
   stejně jako mdExpDefault v section3.html. MD položky se nechávají být. */
function mat_exp_auto(?int $mes): string {
  $mes = $mes ?: MAT_TRV_DEF;
  $d = new DateTimeImmutable('today', new DateTimeZone('Europe/Prague'));
  $t = $d->modify('first day of this month')->modify('+' . $mes . ' months');
  $den = min((int) $d->format('j'), (int) $t->format('t'));
  return $t->format('Y-m-') . str_pad((string) $den, 2, '0', STR_PAD_LEFT);
}
function mat_pre_lot(array $b, string $kdo, string $zdroj): array {
  $r = mat_pre_lot_($b, $kdo, $zdroj);
  if (!$r['ok'] || !is_array($r['body']['items'] ?? null)) return $r;
  $auto = []; $mes = [];
  foreach ($r['body']['items'] as $i => $it) {
    if (!is_array($it) || !empty($it['md']) || mat_txt($it['exp'] ?? '') !== '') continue;
    $ref = mat_txt($it['ref'] ?? '');
    if (!array_key_exists($ref, $mes)) { $m = mat_by_ref($ref); $mes[$ref] = $m && $m['trvanlivost_mes'] !== null ? (int) $m['trvanlivost_mes'] : null; }
    $r['body']['items'][$i]['exp'] = $auto[$ref] = mat_exp_auto($mes[$ref]);
  }
  if ($auto) $r['expAuto'] = $auto;
  return $r;
}
function mat_pre_lot_(array $b, string $kdo, string $zdroj): array {
  $items = is_array($b['items'] ?? null) ? array_values($b['items']) : [];
  /* stejné předběžné kontroly jako lotCreate v Lot.gs – kartu nezakládat, když balení stejně neprojde */
  if (!$items) return ['ok' => false, 'error' => 'žádná položka'];
  foreach ($items as $i => $it) {
    if (mat_txt($it['ref'] ?? '') === '') return ['ok' => false, 'error' => 'položka ' . ($i + 1) . ': chybí REF'];
    if (empty($it['md']) && mat_txt($it['lot'] ?? '') === '') return ['ok' => false, 'error' => 'položka ' . ($i + 1) . ': chybí LOT'];
  }
  if (array_filter($items, fn($it) => !empty($it['md'])) && mat_txt($b['tag'] ?? '') === '')
    return ['ok' => false, 'error' => 'MD se váže k zakázce – chybí kód zakázky'];
  foreach ($items as $it) {                            // vyřazená karta nepřijímá nová balení
    $m = mat_by_ref((string) ($it['ref'] ?? ''));
    if ($m && empty($it['md']) && $m['druh'] === 'MD')
      return ['ok' => false, 'error' => 'REF ' . $m['ref'] . ' je MD (materiál dodaný k zakázce) – '
              . ($zdroj === 'prijem' ? 'na sklad se nepřijímá, založí ho technik v zakázkovém listu úkonem MD' : 'založ ho úkonem MD')];
    if ($m && $m['stav'] === 'neaktivní') return ['ok' => false, 'error' => 'REF ' . $m['ref'] . ' je vyřazená karta MAT – nová balení nejdou založit (vrátit ji jde v prohlížeči tabulek)'];
  }
  $mn = is_array($b['matNew'] ?? null) ? $b['matNew'] : null;
  $mf = is_array($b['matFill'] ?? null) ? $b['matFill'] : null;
  unset($b['matNew'], $b['matFill']);
  if (!$items || (!$mn && !$mf)) return ['ok' => true, 'body' => $b, 'matRow' => null];
  $ref = mat_txt($items[0]['ref'] ?? '');
  if ($ref === '') return ['ok' => true, 'body' => $b, 'matRow' => null];   // chybu „chybí REF“ vrátí Apps Script
  $id = 0; $m = null;
  if ($mn) {
    $err = mat_valid_new($mn, false);
    if ($err) return ['ok' => false, 'error' => 'nová karta MAT: ' . $err];
    /* karta založená úkonem MD k zakázce je MD (24. 11. 2026) – příjem ji pak na sklad nepustí */
    $vseMd = !array_filter($items, fn($it) => empty($it['md']));
    [$m, $e2] = mat_create($mn, $ref, $kdo, $zdroj, $vseMd ? 'MD' : 'sklad');
    if ($e2) return ['ok' => false, 'error' => 'nová karta MAT: ' . $e2];
    $id = (int) $m['id'];
  } elseif ($mf) {                                     // matFill_: jen karta bez formy, vyplněnou nepřepisuje
    $m = mat_by_ref($ref);
    $f = ['ks' => 'kus', 'bal' => 'balení'][$mf['forma'] ?? ''] ?? null;
    if ($m && $f && $m['forma'] === null) {
      [$v, $err] = mat_check(['forma' => $f, 'jednotek' => $f === 'kus' ? 1 : (mat_num($mf['jednotek'] ?? null) ?: 1)]);
      if ($err) return ['ok' => false, 'error' => $err];
      [$m, $zm] = mat_apply((int) $m['id'], $v, $kdo, $zdroj);
      if ($zm) $id = (int) $m['id'];
    }
  }
  if (!$id) return ['ok' => true, 'body' => $b, 'matRow' => $m['list_radek'] ?? null];
  $cek = mat_pdo()->prepare('SELECT COUNT(*) FROM ' . mat_t('mat_propis') . " WHERE mat_id = ? AND stav IN ('čeká', 'chyba')");
  $cek->execute([$id]);
  if ((int) $cek->fetchColumn() > 0 || !mat_by_id($id)['list_radek']) {
    $p = mat_sync([$id])[$id] ?? 'chyba';
    if ($p !== 'hotovo') { mat_log($kdo, 'pre-lot ' . $ref, $p); return ['ok' => false, 'error' => 'karta ' . $ref . ' je uložená, ale do listu MAT se nepropsala (' . substr($p, 7) . ') – zkus to za chvilku znovu']; }
  }
  $m = mat_by_id($id);
  return ['ok' => true, 'body' => $b, 'matRow' => $m['list_radek'] === null ? null : (int) $m['list_radek']];
}

/* ---------------------------------------------------------------------
   Kontrola shody SQL ↔ list MAT (jen čte): suchý matSync všech aktivních
   karet + matCheck. Používá ji denní běh mat-propis.php i kontrola
   před přepnutím. Vrací souhrn a prvních 50 nálezů.
   --------------------------------------------------------------------- */
function mat_kontrola(): array {
  $pdo = mat_pdo(); $T = mat_t('mat');
  $rows = $pdo->query("SELECT * FROM $T WHERE stav = 'aktivní' ORDER BY id")->fetchAll();
  $sum = ['karet' => count($rows), 'rozdily' => 0, 'chybi_v_listu' => 0, 'chyby' => 0, 'navic_v_listu' => 0, 'duplicity' => 0, 'radku_listu' => 0];
  $det = [];
  foreach (array_chunk($rows, 200) as $ch) {
    $r = mat_gas(['op' => 'matSync', 'dry' => true, 'karty' => array_map('mat_karta_sync', $ch)], 120);
    if (empty($r['ok'])) { $sum['chyby'] += count($ch); $det[] = ['chyba' => $r['error'] ?? '?']; continue; }
    foreach ($r['vysledky'] as $i => $x) {
      $ref = $ch[$i]['ref'] ?? '?';
      if (!empty($x['error'])) { $sum['chyby']++; $det[] = ['ref' => $ref, 'chyba' => $x['error']]; }
      elseif (!empty($x['novy'])) { $sum['chybi_v_listu']++; $det[] = ['ref' => $ref, 'chybi_v_listu' => true]; }
      elseif (!empty($x['zmeneno'])) { $sum['rozdily']++; $det[] = ['ref' => $ref, 'radek' => $x['row'] ?? null, 'pole' => $x['zmeneno']]; }
    }
  }
  $c = mat_gas(['op' => 'matCheck'], 120);
  if (empty($c['ok'])) { $sum['chyby']++; $det[] = ['matCheck' => $c['error'] ?? '?']; }
  else {
    $zname = [];
    foreach ($pdo->query("SELECT ref, ref_list FROM $T") as $m) { $zname[mat_ref_key($m['ref'])] = 1; if ($m['ref_list'] !== null) $zname[mat_ref_key($m['ref_list'])] = 1; }
    $vid = [];
    foreach ($c['radky'] as $l) {
      $sum['radku_listu']++;
      $k = mat_ref_key($l['ref_list'] ?? '');
      if ($k === '') continue;                         // řádky bez REF (rozpracované) se neřeší
      if (isset($vid[$k])) { $sum['duplicity']++; $det[] = ['ref' => $l['ref_list'], 'duplicita' => [$vid[$k], $l['radek']]]; }
      $vid[$k] = $l['radek'];
      if (!isset($zname[$k])) { $sum['navic_v_listu']++; $det[] = ['ref' => $l['ref_list'], 'radek' => $l['radek'], 'navic_v_listu' => true]; }
    }
  }
  $ok = !$sum['rozdily'] && !$sum['chybi_v_listu'] && !$sum['chyby'] && !$sum['navic_v_listu'] && !$sum['duplicity'];
  return ['ok' => $ok, 'souhrn' => $sum, 'detail' => array_slice($det, 0, 50)];
}

/* =====================================================================
   Hromadné úpravy, verze 2 (krok 35)
   - víc polí najednou, režim přepsat / jen prázdné / vymazat
   - každá karta ve vlastní transakci s kontrolou verze (mat_apply)
   - celá úprava = jedna dávka (mat_davka), jde vrátit
   - jeden společný propis do listu MAT na konci
   ===================================================================== */
const MAT_HROM_POLE  = ['vyrobce', 'dodavatel', 'skupina', 'odpis', 'kat', 'trvanlivost_mes', 'forma', 'jednotek', 'navyseni_pct', 'stav'];
const MAT_HROM_VYMAZ = ['dodavatel', 'skupina', 'trvanlivost_mes'];          // jen tato pole jde hromadně vyprázdnit
const MAT_PROPIS_NE  = ['cena_nakup', 'cena_datum', 'navyseni_pct', 'stav', 'trvanlivost_mes'];   // list MAT je nenese

function mat_ma_davku(): bool {                      // sloupec mat_zmena.davka (migrace 091) – kód funguje i bez něj
  static $x = null;
  if ($x === null) {
    try { $x = (bool) mat_pdo()->query("SHOW COLUMNS FROM " . mat_t('mat_zmena') . " LIKE 'davka'")->fetch(); } catch (Throwable $e) { $x = false; }
  }
  return $x;
}
function mat_prazdne($k, $v): bool { return $v === null || (string) $v === ''; }

function mat_hromadne(array $b, string $kdo): array {
  $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($b['ids'] ?? [])))));
  $ver = is_array($b['verze'] ?? null) ? $b['verze'] : [];
  $pole = is_array($b['pole'] ?? null) ? $b['pole'] : [];
  if (!$ids) return ['ok' => false, 'error' => 'nejsou vybrané žádné karty'];
  if (count($ids) > 1000) return ['ok' => false, 'error' => 'najednou jde upravit nejvýš 1000 karet'];
  if (!mat_ma_davku()) return ['ok' => false, 'error' => 'server: chybí migrace 091 (dávky)'];

  /* pole → [hodnota, režim]; kontrola hodnot stejnými pravidly jako v buňce */
  $set = []; $rez = [];
  foreach ($pole as $k => $p) {
    if (!in_array($k, MAT_HROM_POLE, true)) return ['ok' => false, 'error' => 'pole ' . $k . ' nejde upravit hromadně'];
    $r = (string) ($p['rezim'] ?? 'prazdne');
    if (!in_array($r, ['prepsat', 'prazdne', 'vymazat'], true)) return ['ok' => false, 'error' => 'neznámý režim'];
    if ($r === 'vymazat' && !in_array($k, MAT_HROM_VYMAZ, true)) return ['ok' => false, 'error' => 'pole ' . $k . ' nejde vyprázdnit'];
    $set[$k] = $r === 'vymazat' ? '' : ($p['hodnota'] ?? null); $rez[$k] = $r;
  }
  if (!$set) return ['ok' => false, 'error' => 'není vyplněné žádné pole'];
  if (isset($set['jednotek']) && ($set['forma'] ?? null) !== 'balení') return ['ok' => false, 'error' => 'počet jednotek jde hromadně nastavit jen spolu s formou balení'];
  if (($set['forma'] ?? null) === 'balení' && !isset($set['jednotek'])) return ['ok' => false, 'error' => 'u formy balení vyplň počet jednotek'];
  if (isset($set['stav']) && $set['stav'] !== 'neaktivní') return ['ok' => false, 'error' => 'hromadně jde karty jen vyřadit, vrátit je jde vrácením dávky nebo po jedné'];
  foreach (['vyrobce', 'odpis', 'kat', 'forma'] as $k) if (isset($set[$k]) && mat_txt($set[$k]) === '') return ['ok' => false, 'error' => 'pole ' . $k . ' nejde vyprázdnit'];
  $chk = array_filter($set, fn($x, $k) => !($rez[$k] === 'vymazat'), ARRAY_FILTER_USE_BOTH);
  [$v, $err] = mat_check($chk, true);
  if ($err) return ['ok' => false, 'error' => $err];
  foreach ($rez as $k => $r) if ($r === 'vymazat') $v[$k] = null;
  if (($v['forma'] ?? null) === 'kus') { $v['jednotek'] = 1.0; $rez['jednotek'] = $rez['forma']; }

  $pdo = mat_pdo();
  $pdo->prepare('INSERT INTO ' . mat_t('mat_davka') . ' (cas, kdo, pole, karet) VALUES (?, ?, ?, 0)')
      ->execute([mat_now(), $kdo, json_encode(array_map(fn($k) => [$k, $rez[$k], $v[$k] ?? null], array_keys($v)), JSON_UNESCAPED_UNICODE)]);
  $davka = (int) $pdo->lastInsertId();

  $out = []; $sync = []; $zmen = 0;
  foreach ($ids as $id) {
    $m = mat_by_id($id);
    if (!$m) { $out[$id] = ['v' => 'odmítnuto', 'proc' => 'karta neexistuje']; continue; }
    if ($m['stav'] === 'neaktivní') { $out[$id] = ['v' => 'odmítnuto', 'proc' => 'karta je vyřazená']; continue; }
    $w = [];
    foreach ($v as $k => $x) if ($rez[$k] !== 'prazdne' || mat_prazdne($k, $m[$k])) $w[$k] = $x;
    if (isset($w['jednotek']) && !isset($w['forma'])) unset($w['jednotek']);        // jednotek jen s formou
    if ((isset($w['forma']) && $w['forma'] !== $m['forma']) && mat_otevrena($m['ref']) > 0) {
      $out[$id] = ['v' => 'odmítnuto', 'proc' => 'formu nejde změnit: na kartě jsou otevřená balení']; continue;
    }
    if (!$w) { $out[$id] = ['v' => 'beze změny', 'proc' => 'pole už má hodnotu']; continue; }
    [$m2, $zm, $konf, $e2] = mat_apply($id, $w, $kdo, 'prohlizec', isset($ver[$id]) ? (int) $ver[$id] : (isset($ver[(string) $id]) ? (int) $ver[(string) $id] : null), $davka);
    if ($konf) { $out[$id] = ['v' => 'konflikt', 'proc' => 'kartu mezitím změnil(a) ' . $konf['kdo'] . ' (' . $konf['pole'] . ')']; continue; }
    if ($e2) { $out[$id] = ['v' => 'odmítnuto', 'proc' => $e2]; continue; }
    if (!$zm) { $out[$id] = ['v' => 'beze změny', 'proc' => 'stejná hodnota']; continue; }
    $zmen++;
    $out[$id] = ['v' => 'uloženo', 'pole' => $zm, 'verze' => (int) $m2['verze'], 'odpis' => $m2['odpis']];
    if (array_diff($zm, MAT_PROPIS_NE)) $sync[] = $id;
  }
  if ($zmen) $pdo->prepare('UPDATE ' . mat_t('mat_davka') . ' SET karet = ? WHERE id = ?')->execute([$zmen, $davka]);
  else { $pdo->prepare('DELETE FROM ' . mat_t('mat_davka') . ' WHERE id = ?')->execute([$davka]); $davka = 0; }
  $pr = $sync ? mat_sync($sync) : [];
  foreach ($sync as $id) $out[$id]['propis'] = ($pr[$id] ?? '') === 'hotovo' ? 'hotovo' : 'čeká';
  mat_log($kdo, 'hromadně ' . count($ids) . ' karet, dávka ' . $davka, $zmen . ' uloženo');
  return ['ok' => true, 'davka' => $davka, 'vysledky' => $out];
}

/* Vrácení dávky: jen karty, na kterých od dávky nikdo neměnil stejné pole.
   Hodnoty se berou z historie (stara), takže se nekontrolují pravidly pro nové hodnoty. */
function mat_vratit_davku(int $d, string $kdo): array {
  if (!mat_ma_davku()) return ['ok' => false, 'error' => 'server: chybí migrace 091 (dávky)'];
  $pdo = mat_pdo(); $Z = mat_t('mat_zmena'); $DV = mat_t('mat_davka');
  $dv = $pdo->prepare("SELECT * FROM $DV WHERE id = ?"); $dv->execute([$d]); $dv = $dv->fetch();
  if (!$dv) return ['ok' => false, 'error' => 'dávka neexistuje'];
  if (!empty($dv['vraceno'])) return ['ok' => false, 'error' => 'dávka už byla vrácena'];
  $st = $pdo->prepare("SELECT id, mat_id, pole, stara FROM $Z WHERE davka = ? ORDER BY id"); $st->execute([$d]);
  $po = [];
  foreach ($st->fetchAll() as $z) $po[(int) $z['mat_id']][$z['pole']] = ['id' => (int) $z['id'], 'stara' => $z['stara']];
  $pdo->prepare("INSERT INTO $DV (cas, kdo, pole, karet, vraci) VALUES (?, ?, ?, 0, ?)")->execute([mat_now(), $kdo, $dv['pole'], $d]);
  $nova = (int) $pdo->lastInsertId();
  $later = $pdo->prepare("SELECT kdo, pole FROM $Z WHERE mat_id = ? AND id > ? AND pole IN (" . implode(',', array_fill(0, 12, '?')) . ") LIMIT 1");
  $out = []; $sync = []; $n = 0;
  foreach ($po as $id => $pp) {
    $max = max(array_column($pp, 'id')); $pl = array_keys($pp);
    $later->execute([$id, $max, ...array_pad($pl, 12, '')]);
    if ($x = $later->fetch()) { $out[$id] = ['v' => 'přeskočeno', 'proc' => 'pole ' . $x['pole'] . ' mezitím změnil(a) ' . $x['kdo']]; continue; }
    $w = [];
    foreach ($pp as $k => $z) $w[$k] = in_array($k, ['jednotek', 'navyseni_pct'], true) ? mat_num($z['stara']) : ($k === 'trvanlivost_mes' ? ($z['stara'] === null ? null : (int) $z['stara']) : $z['stara']);
    [$m2, $zm, $konf, $e2] = mat_apply($id, $w, $kdo, 'prohlizec', null, $nova);
    if ($e2) { $out[$id] = ['v' => 'odmítnuto', 'proc' => $e2]; continue; }
    if (!$zm) { $out[$id] = ['v' => 'beze změny', 'proc' => 'hodnota už je původní']; continue; }
    $n++; $out[$id] = ['v' => 'vráceno', 'pole' => $zm, 'verze' => (int) $m2['verze']];
    if (array_diff($zm, MAT_PROPIS_NE)) $sync[] = $id;
  }
  $pdo->prepare("UPDATE $DV SET karet = ? WHERE id = ?")->execute([$n, $nova]);
  $pdo->prepare("UPDATE $DV SET vraceno = ?, vratil = ? WHERE id = ?")->execute([mat_now(), $kdo, $d]);
  $pr = $sync ? mat_sync($sync) : [];
  foreach ($sync as $id) $out[$id]['propis'] = ($pr[$id] ?? '') === 'hotovo' ? 'hotovo' : 'čeká';
  mat_log($kdo, 'vrácení dávky ' . $d, $n . ' vráceno');
  return ['ok' => true, 'davka' => $nova, 'vysledky' => $out];
}

function mat_davky(int $lim = 40): array {
  if (!mat_ma_davku()) return [];
  $q = mat_pdo()->query('SELECT id, DATE_FORMAT(cas, "%Y-%m-%d %H:%i") cas, kdo, pole, karet, vraci, DATE_FORMAT(vraceno, "%Y-%m-%d %H:%i") vraceno, vratil FROM '
                        . mat_t('mat_davka') . ' ORDER BY id DESC LIMIT ' . (int) $lim);
  return array_map(fn($r) => ['id' => (int) $r['id'], 'cas' => $r['cas'], 'kdo' => $r['kdo'], 'pole' => json_decode((string) $r['pole'], true) ?: [],
                              'karet' => (int) $r['karet'], 'vraci' => $r['vraci'] === null ? null : (int) $r['vraci'], 'vraceno' => $r['vraceno'], 'vratil' => $r['vratil']], $q->fetchAll());
}


/* ---------- Krok 36: číselník skupin (záložka Skupiny v Katalogu) ----------
   Kód skupiny je identita v mat.skupina, t2p_produkt_mat.skupina (obojí FK RESTRICT) a v listu MAT!O.
   Přejmenování = nový kód, karty se přeřadí přes mat_apply (historie + propis do MAT!O), skladby výrobků
   se přepíšou, starý kód se smaže. Skupiny jmenovitě v Apps Scriptu (AUTODID.GRP_FULL_SK, verze 70)
   se přejmenovat ani smazat nedají, dokud se nezmění i skript. */
const MAT_SK_GAS = ['SK-MODEL-DIGITAL', 'SK-IZOLACE', 'SK-ORTO-AKRYLAT', 'SK-LZICE', 'SK-SILIKON-LAB'];

function mat_sk_log(string $kdo, string $akce, string $kod, ?string $novy, ?string $pozn): void {
  try {
    mat_pdo()->prepare('INSERT INTO ' . mat_t('mat_skupina_zmena') . ' (cas, kdo, akce, kod, novy, pozn) VALUES (?, ?, ?, ?, ?, ?)')
      ->execute([mat_now(), $kdo, $akce, $kod, $novy, $pozn === null ? null : mb_substr($pozn, 0, 250)]);
  } catch (Throwable $e) {}                               // bez migrace 092 jen log souboru
  mat_log($kdo, 'skupina ' . $akce . ' ' . $kod . ($novy ? ' → ' . $novy : ''), (string) $pozn);
}
function mat_sk_kod($k): string { return mb_strtoupper(trim((string) $k)); }
function mat_sk_chyba_kodu(string $k): string {
  if (!preg_match('/^SK-[A-Z0-9]+(-[A-Z0-9]+)*$/', $k)) return 'kód skupiny musí mít tvar SK-… (velká písmena bez diakritiky, číslice, pomlčky)';
  if (strlen($k) > 40) return 'kód skupiny je delší než 40 znaků';
  return '';
}

/* přehled pro záložku: popis, výrobky (přes REF i přímo skupinou), historie číselníku */
function mat_skupiny_info(): array {
  $pdo = mat_pdo(); $S = mat_t('mat_skupina'); $T = mat_t('mat');
  $out = [];
  $typy = mat_sk_typy(true);
  foreach ($pdo->query("SELECT kod, popis FROM $S ORDER BY kod") as $r)
    $out[$r['kod']] = ['kod' => $r['kod'], 'popis' => $r['popis'], 'typ' => $typy[$r['kod']] ?? null, 'gas' => in_array($r['kod'], MAT_SK_GAS, true), 'vyrobky' => []];
  try {
    $q = $pdo->query("SELECT DISTINCT COALESCE(pm.skupina, m.skupina) sk, p.kod FROM t2p_produkt_mat pm JOIN t2p_produkt p ON p.id = pm.produkt_id
                      LEFT JOIN $T m ON m.ref = pm.ref WHERE COALESCE(pm.skupina, m.skupina) IS NOT NULL ORDER BY p.kod");
    foreach ($q as $r) if (isset($out[$r['sk']])) $out[$r['sk']]['vyrobky'][] = $r['kod'];
  } catch (Throwable $e) {}                               // bez tabulek výrobků jen bez seznamu
  $hist = [];
  try {
    $hist = $pdo->query('SELECT DATE_FORMAT(cas, "%Y-%m-%d %H:%i") cas, kdo, akce, kod, novy, pozn FROM ' . mat_t('mat_skupina_zmena') . ' ORDER BY id DESC LIMIT 200')->fetchAll(PDO::FETCH_NUM);
  } catch (Throwable $e) {}
  return ['skupiny' => array_values($out), 'historie' => $hist];
}

function mat_skupina_nova(array $b, string $kdo): array {
  $k = mat_sk_kod($b['kod'] ?? ''); $p = trim((string) ($b['popis'] ?? ''));
  if ($e = mat_sk_chyba_kodu($k)) return ['ok' => false, 'error' => $e];
  if (mat_skupina_ok($k)) return ['ok' => false, 'error' => 'skupina ' . $k . ' už existuje'];
  $t = mat_sk_kod($b['typ'] ?? '');
  if (mat_ma_typ() && !isset(MAT_TYP_ODPIS[$t])) return ['ok' => false, 'error' => 'vyber typ šarže skupiny: IDID (ruční DID) nebo ADID (AutoDID)'];
  if (mat_ma_typ()) mat_pdo()->prepare('INSERT INTO ' . mat_t('mat_skupina') . ' (kod, popis, typ_sarze) VALUES (?, ?, ?)')->execute([$k, $p === '' ? null : mb_substr($p, 0, 200), $t]);
  else mat_pdo()->prepare('INSERT INTO ' . mat_t('mat_skupina') . ' (kod, popis) VALUES (?, ?)')->execute([$k, $p === '' ? null : mb_substr($p, 0, 200)]);
  mat_sk_typy(true);
  mat_sk_log($kdo, 'nová', $k, null, trim(($t ? $t . ' ' : '') . $p));
  return ['ok' => true, 'kod' => $k];
}

function mat_skupina_uprav(array $b, string $kdo): array {
  $pdo = mat_pdo(); $S = mat_t('mat_skupina'); $T = mat_t('mat');
  $k = mat_sk_kod($b['kod'] ?? '');
  $st = $pdo->prepare("SELECT kod, popis, vyber FROM $S WHERE kod = ?"); $st->execute([$k]); $sk = $st->fetch();
  if (!$sk) return ['ok' => false, 'error' => 'skupina ' . $k . ' neexistuje'];
  if (array_key_exists('popis', $b)) {
    $p = trim((string) $b['popis']); $p = $p === '' ? null : mb_substr($p, 0, 200);
    if ($p !== $sk['popis']) { $pdo->prepare("UPDATE $S SET popis = ? WHERE kod = ?")->execute([$p, $k]); mat_sk_log($kdo, 'popis', $k, null, $p); }
  }
  $typ = null;
  if (array_key_exists('typ', $b) && mat_ma_typ()) {
    $t = mat_sk_kod($b['typ']); $st0 = mat_sk_typy(true)[$k] ?? null;
    if ($t !== '' && $t !== $st0) {
      if (!isset(MAT_TYP_ODPIS[$t])) return ['ok' => false, 'error' => 'typ šarže musí být IDID nebo ADID'];
      if (in_array($k, MAT_SK_GAS, true) && $t !== 'ADID') return ['ok' => false, 'error' => $k . ' je v Apps Scriptu jako AutoDID celá skupina – na IDID ji změnit nejde bez změny skriptu'];
      $typ = mat_sk_typ_nastav($k, $t, $kdo);
      if (!$typ['ok']) return $typ;
    }
  }
  $n = isset($b['novy_kod']) ? mat_sk_kod($b['novy_kod']) : $k;
  if ($n === $k) return ['ok' => true, 'kod' => $k, 'karet' => $typ['karet'] ?? 0, 'typ_karet' => $typ['karet'] ?? 0, 'propis_ceka' => $typ['propis_ceka'] ?? 0];
  if (in_array($k, MAT_SK_GAS, true)) return ['ok' => false, 'error' => $k . ' je jmenovitě v Apps Scriptu (AutoDID, celá skupina) – přejmenování nejdřív se změnou skriptu'];
  if ($e = mat_sk_chyba_kodu($n)) return ['ok' => false, 'error' => $e];
  if (mat_skupina_ok($n)) return ['ok' => false, 'error' => 'skupina ' . $n . ' už existuje – sloučení skupin dělej hromadnou úpravou v Materiálu'];

  /* 1) nový kód, 2) karty přes editor (historie, verze, propis), 3) skladby výrobků, 4) smazat starý */
  if (mat_ma_typ()) $pdo->prepare("INSERT INTO $S (kod, popis, vyber, typ_sarze) VALUES (?, ?, ?, ?)")->execute([$n, $b['popis'] ?? $sk['popis'], $sk['vyber'], mat_sk_typy(true)[$k] ?? null]);
  else $pdo->prepare("INSERT INTO $S (kod, popis, vyber) VALUES (?, ?, ?)")->execute([$n, $b['popis'] ?? $sk['popis'], $sk['vyber']]);
  mat_sk_typy(true);
  $ids = $pdo->prepare("SELECT id FROM $T WHERE skupina = ? ORDER BY id"); $ids->execute([$k]); $ids = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
  $ok = []; $chyby = [];
  foreach ($ids as $id) {
    [$m, $zm, $konf, $e2] = mat_apply($id, ['skupina' => $n], $kdo, 'prohlizec');
    if ($e2 || $konf) $chyby[$id] = $e2 ?: 'konflikt'; elseif ($zm) $ok[] = $id;
  }
  $vyr = 0;
  try { $u = $pdo->prepare('UPDATE t2p_produkt_mat SET skupina = ? WHERE skupina = ?'); $u->execute([$n, $k]); $vyr = $u->rowCount(); } catch (Throwable $e) {}
  $zbyva = $pdo->prepare("SELECT COUNT(*) FROM $T WHERE skupina = ?"); $zbyva->execute([$k]);
  $smazano = false;
  if (!(int) $zbyva->fetchColumn()) { try { $pdo->prepare("DELETE FROM $S WHERE kod = ?")->execute([$k]); $smazano = true; } catch (Throwable $e) {} }
  $pr = $ok ? mat_sync($ok) : [];
  $ceka = count(array_filter($ok, fn($i) => ($pr[$i] ?? '') !== 'hotovo'));
  mat_sk_log($kdo, 'přejmenování', $k, $n, count($ok) . ' karet, ' . $vyr . ' položek skladby' . ($chyby ? ', ' . count($chyby) . ' chyb' : '') . ($smazano ? '' : ', starý kód zůstal'));
  return ['ok' => !$chyby, 'kod' => $n, 'karet' => count($ok), 'skladba' => $vyr, 'propis_ceka' => $ceka, 'stary_smazan' => $smazano,
          'chyby' => $chyby, 'error' => $chyby ? 'část karet se nepřeřadila – starý kód zůstal, oprav je a přejmenuj znovu' : null];
}

function mat_skupina_smaz(array $b, string $kdo): array {
  $pdo = mat_pdo(); $k = mat_sk_kod($b['kod'] ?? '');
  if (!mat_skupina_ok($k)) return ['ok' => false, 'error' => 'skupina ' . $k . ' neexistuje'];
  if (in_array($k, MAT_SK_GAS, true)) return ['ok' => false, 'error' => $k . ' je jmenovitě v Apps Scriptu (AutoDID) – smazání nejdřív se změnou skriptu'];
  $st = $pdo->prepare('SELECT COUNT(*) FROM ' . mat_t('mat') . ' WHERE skupina = ?'); $st->execute([$k]);
  if ($n = (int) $st->fetchColumn()) return ['ok' => false, 'error' => 've skupině je ' . $n . ' karet (i vyřazených) – nejdřív je přeřaď hromadnou úpravou v Materiálu'];
  try { $st = $pdo->prepare('SELECT COUNT(*) FROM t2p_produkt_mat WHERE skupina = ?'); $st->execute([$k]); $n = (int) $st->fetchColumn(); } catch (Throwable $e) { $n = 0; }
  if ($n) return ['ok' => false, 'error' => 'skupinu má ve skladbě ' . $n . ' výrobků'];
  $pdo->prepare('DELETE FROM ' . mat_t('mat_skupina') . ' WHERE kod = ?')->execute([$k]);
  mat_sk_log($kdo, 'smazání', $k, null, null);
  return ['ok' => true];
}

function mat_ma_typ(): bool {                        // sloupec mat_skupina.typ_sarze (migrace 093)
  static $x = null;
  if ($x === null) { try { $x = (bool) mat_pdo()->query("SHOW COLUMNS FROM " . mat_t('mat_skupina') . " LIKE 'typ_sarze'")->fetch(); } catch (Throwable $e) { $x = false; } }
  return $x;
}
/* nastaví typ skupiny a srovná odpis všech jejích skladových karet (i vyřazených); MD karty skupinu ztratí */
function mat_sk_typ_nastav(string $k, string $t, string $kdo): array {
  $pdo = mat_pdo(); $T = mat_t('mat'); $od = MAT_TYP_ODPIS[$t];
  $pdo->prepare('UPDATE ' . mat_t('mat_skupina') . ' SET typ_sarze = ? WHERE kod = ?')->execute([$t, $k]);
  mat_sk_typy(true);
  $st = $pdo->prepare("SELECT id, druh FROM $T WHERE skupina = ? ORDER BY id"); $st->execute([$k]);
  $ok = []; $chyby = [];
  foreach ($st->fetchAll() as $r) {
    $w = $r['druh'] === 'MD' ? ['skupina' => null] : ['odpis' => $od];
    [$m, $zm, $konf, $e2] = mat_apply((int) $r['id'], $w, $kdo, 'prohlizec');
    if ($e2 || $konf) $chyby[(int) $r['id']] = $e2 ?: 'konflikt'; elseif ($zm) $ok[] = (int) $r['id'];
  }
  $pr = $ok ? mat_sync($ok) : [];
  $ceka = count(array_filter($ok, fn($i) => ($pr[$i] ?? '') !== 'hotovo'));
  mat_sk_log($kdo, 'typ', $k, $t, count($ok) . ' karet srovnáno na ' . $od . ($chyby ? ', ' . count($chyby) . ' chyb' : ''));
  return ['ok' => !$chyby, 'karet' => count($ok), 'propis_ceka' => $ceka, 'chyby' => $chyby, 'error' => $chyby ? 'část karet se nesrovnala' : null];
}
