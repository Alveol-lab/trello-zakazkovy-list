<?php
/* =========================================================
   AutoLAB – karta výrobku (PRODB) v SQL (etapa 2b, krok P3)
   Jedno místo pro pravidla výrobku a skladby. Volá ho produkt-api.php
   (Katalog, záložka Výrobky); později i propis a denní kontrola.
   Tabulky t2p_produkt, t2p_produkt_mat, t2p_produkt_zmena, t2p_produkt_propis.
   Přepínač: soubor autolab-prodb.json mimo web,
     {"cteni": true, "zapis": false, "propis": false, "pravidla": false}
   pravidla = úprava pravidel sledovatelnosti u položek skladby (krok P6).
   Do listu PRODB se nepropisují, proto smějí běžet i bez zapis/propis.
   Bez souboru je všechno vypnuté a Katalog ukazuje kopii sešitu jako dosud.
   zapis bez propis: změny zůstanou ve frontě, list PRODB se nemění –
   proto se zapis zapíná až spolu s propisem (prodbSync v Apps Scriptu).
   ========================================================= */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }
require_once __DIR__ . '/mat-lib.php';                // mat_pdo, mat_txt, mat_now, mat_gas, mat_log

const PR_CFG_FILE = __DIR__ . '/../../autolab-prodb.json';
const PR_KAT  = ['OR', 'FP', 'RP', 'DTO'];
const PR_POLE = ['nazev', 'ku', 'kat', 'kzp', 'hs', 'vzp', 'gmdn', 'udi_pi', 'trvanlivost', 'eudamed', 'stary_kod', 'poznamka', 'technologie'];
const PR_TECH = ['Frézování', '3D print', 'Press'];   // krok F2: výchozí technologie, do listu PRODB se nepropisuje
const PR_DELKY = ['nazev' => 300, 'ku' => 20, 'gmdn' => 40, 'udi_pi' => 60, 'eudamed' => 60, 'stary_kod' => 200, 'poznamka' => 500];
/* Editoři výrobků (Trello účet → zkratka). Technici z MAT_TECHNIK smějí jen navrhovat. */
const PR_EDIT = ['588fa9e7e5db691bfe653bce' => 'H', '6b00d5c2c5be2a396f89949e' => 'BRIGIT'];

function pr_cfg(): array {
  static $c = null;
  if ($c === null) {
    $j = is_file(PR_CFG_FILE) ? json_decode((string) file_get_contents(PR_CFG_FILE), true) : null;
    $c = [];
    foreach (['cteni', 'zapis', 'propis', 'pravidla'] as $k) $c[$k] = is_array($j) && ($j[$k] ?? false) === true;
    if (!$c['cteni']) $c['zapis'] = $c['propis'] = $c['pravidla'] = false;
  }
  return $c;
}
function pr_log(string $kdo, string $co, string $vysl): void {
  @file_put_contents(MAT_LOG, date('c') . "\t" . $kdo . "\tprodukt " . $co . "\t" . $vysl . "\n", FILE_APPEND | LOCK_EX);
}
function pr_by_id(int $id, bool $lock = false): ?array {
  $st = mat_pdo()->prepare('SELECT * FROM t2p_produkt WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
  $st->execute([$id]);
  return $st->fetch() ?: null;
}
function pr_by_kod(string $kod): ?array {
  $st = mat_pdo()->prepare('SELECT * FROM t2p_produkt WHERE kod = ?');      // _ai_ci: bez ohledu na velikost písmen
  $st->execute([mat_txt($kod)]);
  return $st->fetch() ?: null;
}
/* Položka skladby = cíl (ref NEBO skupina SK-…) + pravidla sledovatelnosti (krok P6):
   zpusob auto | ruční | MD (NULL = podle karty MAT), povinnost povinné | doporučené | volitelné
   (NULL = nenastaveno, kontrola zakázky položku nehodnotí), varianta = štítek „stačí jedna z“. */
const PR_ZPUSOB = ['auto', 'ruční', 'MD'];
const PR_POVIN  = ['povinné', 'doporučené', 'volitelné'];
const PR_PRAV   = ['zpusob', 'povinnost', 'varianta'];
function pr_skl(int $id): array {
  $st = mat_pdo()->prepare('SELECT role, ref, skupina, mat_id, zpusob, povinnost, varianta FROM t2p_produkt_mat WHERE produkt_id = ? ORDER BY role, poradi');
  $st->execute([$id]);
  return $st->fetchAll();
}
function pr_cil(array $s): string { return (string) ($s['ref'] ?? $s['skupina']); }
/* aktivní členové skupiny (pro sloupec H / I a AutoDID) */
function pr_clenove(string $sk): array {
  static $c = [];
  if (!isset($c[$sk])) {
    $st = mat_pdo()->prepare("SELECT ref FROM mat WHERE skupina = ? AND stav = 'aktivní' ORDER BY ref");
    $st->execute([$sk]); $c[$sk] = $st->fetchAll(PDO::FETCH_COLUMN);
  }
  return $c[$sk];
}
/* řetězec jako sloupec H / I listu PRODB; skupina se rozvine na aktivní členy (bez duplicit) */
function pr_retezec(array $skl, string $role): string {
  $out = []; $vid = [];
  foreach ($skl as $s) {
    if ($s['role'] !== $role) continue;
    foreach ($s['ref'] !== null ? [$s['ref']] : pr_clenove((string) $s['skupina']) as $r) {
      $k = mb_strtoupper($r); if (isset($vid[$k])) continue; $vid[$k] = 1; $out[] = $r;
    }
  }
  return implode(' | ', $out);
}
/* zhuštěný zápis skladby pro historii: cíle tak, jak je editor zadal (REF nebo SK-…),
   bez rozvinutí skupin – krátký a nezávislý na pozdějších změnách členství (krok 38/3) */
function pr_cile(array $skl, string $role): string {
  return implode(' | ', array_map('pr_cil', array_values(array_filter($skl, fn($s) => $s['role'] === $role))));
}
/* pravidla jedné položky jako krátký text do historie: „povinné · ruční · var. barvy“ */
function pr_prav_txt(array $s): string {
  $p = array_filter([$s['povinnost'] ?? null, $s['zpusob'] ?? null, ($s['varianta'] ?? null) !== null ? 'var. ' . $s['varianta'] : null]);
  return $p ? implode(' · ', $p) : 'nenastaveno';
}
/* snímek celé skladby s pravidly – platné znění v čase (kontrola zakázky podle data výkonu) */
function pr_snimek(int $id, int $verze, string $kdo, string $now): void {
  $skl = array_map(fn($s) => ['role' => $s['role'], 'ref' => $s['ref'], 'skupina' => $s['skupina'], 'zpusob' => $s['zpusob'],
                              'povinnost' => $s['povinnost'], 'varianta' => $s['varianta']], pr_skl($id));
  mat_pdo()->prepare('INSERT INTO t2p_produkt_pravidla (produkt_id, verze, platne_od, kdo, pravidla) VALUES (?, ?, ?, ?, ?)')
    ->execute([$id, $verze, $now, $kdo, json_encode($skl, JSON_UNESCAPED_UNICODE)]);
}
/* karta MAT podle REF, jinak přes alias; null = karta chybí */
function pr_karta(string $ref): ?array {
  $st = mat_pdo()->prepare('SELECT m.* FROM mat m WHERE m.ref = ? UNION ALL
                            SELECT m.* FROM mat_alias a JOIN mat m ON m.id = a.mat_id WHERE a.ref_alias = ? LIMIT 1');
  $st->execute([$ref, $ref]);
  return $st->fetch() ?: null;
}

/* ---------------------------------------------------------------------
   Kontrola a normalizace jednoho pole. Vrací [hodnota, chyba].
   Stejná pravidla jako náhled (tabulky.html, modul V) a CHECK ve schématu.
   --------------------------------------------------------------------- */
function pr_check(string $k, $x): array {
  $v = is_string($x) || is_numeric($x) ? mat_txt($x) : '';
  if ($k === 'kzp') $v = mb_strtoupper($v);
  if ($k === 'ku') $v = implode(' | ', array_filter(array_map('trim', explode('|', mb_strtoupper($v))), 'strlen'));
  if ($k === 'kat' || $k === 'hs') $v = mb_strtoupper($v);
  if ($v === '') {
    if (in_array($k, ['nazev', 'ku', 'kat'], true)) return [null, ['nazev' => 'název', 'ku' => 'KU', 'kat' => 'KAT'][$k] . ' nejde vyprázdnit'];
    return [null, ''];
  }
  if (isset(PR_DELKY[$k]) && mb_strlen($v) > PR_DELKY[$k]) return [null, 'text je delší než ' . PR_DELKY[$k] . ' znaků'];
  if ($k === 'ku' && !preg_match('/^[FROU][MST]( \| [FROU][MST])*$/', $v)) return [null, 'KU = dvojice písmen (F/R/O/U + M/S/T), víc oddělit „ | “'];
  if ($k === 'kat' && !in_array($v, PR_KAT, true)) return [null, 'KAT musí být OR / FP / RP / DTO'];
  if ($k === 'kzp' && !preg_match('/^[A-Z]{3}$/', $v)) return [null, 'KZP jsou 3 písmena'];
  if ($k === 'hs' && !preg_match('/^[A-Z]$/', $v)) return [null, 'HS je jedno písmeno'];
  if ($k === 'vzp' && !preg_match('/^[0-9]{4}$/', $v)) return [null, 'VZP jsou 4 číslice'];
  if ($k === 'technologie' && !in_array($v, PR_TECH, true)) return [null, 'technologie: Frézování / 3D print / Press'];
  if ($k === 'trvanlivost') { if (!preg_match('/^[0-9]+$/', $v) || (int) $v < 1 || (int) $v > 600) return [null, 'trvanlivost = celé číslo měsíců 1–600']; $v = (int) $v; }
  return [$v, ''];
}

/* Skladba z prohlížeče: [[role, ref], …] → [položky, chyba]. Nový REF musí mít aktivní kartu MAT;
   položka, která už ve skladbě je (i bez karty), smí zůstat – úprava jinde nenutí opravit staré vady.
   Cíl „SK-…“ = skupina z mat_skupina (stačí jedna z karet skupiny). Pravidla položky (P6) se
   při úpravě skladby zachovají podle cíle; nová položka je má nenastavená. */
function pr_check_skladba($h, array $stara): array {
  if (!is_array($h) || count($h) > 150) return [null, 'neplatná skladba'];
  $mam = []; foreach ($stara as $s) $mam[mb_strtoupper(pr_cil($s))] = $s;
  $prav = function (string $key) use ($mam): array {
    $s = $mam[$key] ?? []; return ['zpusob' => $s['zpusob'] ?? null, 'povinnost' => $s['povinnost'] ?? null, 'varianta' => $s['varianta'] ?? null];
  };
  $out = []; $vid = []; $por = ['hlavní' => 0, 'pomocný' => 0];
  foreach ($h as $x) {
    $role = is_array($x) ? (string) ($x[0] ?? '') : ''; $ref = is_array($x) ? mat_txt($x[1] ?? '') : '';
    if (!isset($por[$role])) return [null, 'role musí být hlavní / pomocný'];
    if ($ref === '' || mb_strlen($ref) > 60 || str_contains($ref, '|')) return [null, 'neplatný REF ' . $ref];
    $key = mb_strtoupper($ref);
    if (isset($vid[$key])) return [null, 'REF ' . $ref . ' je ve skladbě dvakrát'];
    $vid[$key] = 1;
    if (preg_match('/^SK-[A-Z0-9-]+$/i', $ref)) {
      $st = mat_pdo()->prepare('SELECT kod FROM mat_skupina WHERE kod = ?'); $st->execute([$ref]); $sk = $st->fetchColumn();
      if (!$sk) return [null, 'skupina ' . $ref . ' neexistuje'];
      if (!pr_clenove($sk)) return [null, 'skupina ' . $sk . ' nemá žádnou aktivní kartu'];
      $out[] = ['role' => $role, 'poradi' => ++$por[$role], 'ref' => null, 'skupina' => $sk, 'mat_id' => null] + $prav(mb_strtoupper($sk));
      continue;
    }
    if (isset($mam[$key])) { $ref = $mam[$key]['ref']; $mid = $mam[$key]['mat_id']; }
    else {
      $m = pr_karta($ref);
      if (!$m) return [null, 'karta ' . $ref . ' v MAT není – založ ji nejdřív v záložce Materiál'];
      if ($m['stav'] !== 'aktivní') return [null, 'karta ' . $m['ref'] . ' je vyřazená'];
      $ref = $m['ref']; $mid = (int) $m['id'];
      if (isset($vid[mb_strtoupper($ref)]) && mb_strtoupper($ref) !== $key) return [null, 'karta ' . $ref . ' je ve skladbě dvakrát (přes alias)'];
      $vid[mb_strtoupper($ref)] = 1;
    }
    $out[] = ['role' => $role, 'poradi' => ++$por[$role], 'ref' => $ref, 'skupina' => null, 'mat_id' => $mid === null ? null : (int) $mid] + $prav(mb_strtoupper($ref));
  }
  return [$out, ''];
}

/* ---------------------------------------------------------------------
   Zápis jednoho pole (nebo skladby / stavu) v transakci: zámek řádku,
   verze, práva podle role, deník změn, fronta propisu.
   $role = 'editor' | 'technik'. Vrací [výrobek, změny, konflikt, chyba].
   --------------------------------------------------------------------- */
function pr_apply(int $id, string $pole, $hodnota, string $kdo, string $role, ?int $verze): array {
  $pdo = mat_pdo();
  $pdo->beginTransaction();
  try {
    $p = pr_by_id($id, true);
    if (!$p) { $pdo->rollBack(); return [null, [], null, 'výrobek neexistuje']; }
    if ($verze !== null && (int) $p['verze'] !== $verze) {
      $z = $pdo->prepare('SELECT kdo, cas, pole, nova FROM t2p_produkt_zmena WHERE produkt_id = ? ORDER BY id DESC LIMIT 1');
      $z->execute([$id]); $z = $z->fetch() ?: ['kdo' => $p['upravil'] ?? '?', 'cas' => $p['upraveno'], 'pole' => '?', 'nova' => ''];
      $pdo->rollBack();
      return [$p, [], ['kdo' => $z['kdo'], 'kdy' => $z['cas'], 'pole' => $z['pole'], 'hodnota' => $z['nova']], ''];
    }
    $vlastni = $p['stav'] === 'návrh' && $p['navrhl'] === $kdo;
    $err = '';
    if ($role !== 'editor' && !$vlastni) $err = 'technik upravuje jen vlastní návrh';
    elseif ($pole !== 'stav' && $p['stav'] === 'neaktivní') $err = 'výrobek je vyřazený – nejdřív ho vrať do aktivních';
    if ($err) { $pdo->rollBack(); return [$p, [], null, $err]; }

    $now = mat_now(); $zm = []; $log = []; $propis = false; $snimek = false;
    if ($pole === 'skladba') {
      $stara = pr_skl($id);
      [$nova, $err] = pr_check_skladba($hodnota, $stara);
      if ($err) { $pdo->rollBack(); return [$p, [], null, $err]; }
      foreach (['hlavní' => 'skladba_h', 'pomocný' => 'skladba_i'] as $r => $k) {
        $a = pr_cile($stara, $r); $b = pr_cile($nova, $r);
        if ($a !== $b) $log[] = [$k, $a === '' ? null : $a, $b === '' ? null : $b];
      }
      if ($log) {
        $pdo->prepare('DELETE FROM t2p_produkt_mat WHERE produkt_id = ?')->execute([$id]);
        $ins = $pdo->prepare('INSERT INTO t2p_produkt_mat (produkt_id, role, poradi, ref, skupina, mat_id, zpusob, povinnost, varianta) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($nova as $s) $ins->execute([$id, $s['role'], $s['poradi'], $s['ref'], $s['skupina'], $s['mat_id'], $s['zpusob'], $s['povinnost'], $s['varianta']]);
        $propis = true; $snimek = true;
      }
    } elseif ($pole === 'pravidla') {
      /* [[cíl, zpusob, povinnost, varianta], …] – jen položky, které se mění; skladba ani list PRODB se nemění */
      if ($role !== 'editor') { $pdo->rollBack(); return [$p, [], null, 'pravidla nastavuje jen editor']; }
      if (!is_array($hodnota) || !$hodnota || count($hodnota) > 150) { $pdo->rollBack(); return [$p, [], null, 'neplatná pravidla']; }
      $skl = []; foreach (pr_skl($id) as $s) $skl[mb_strtoupper(pr_cil($s))] = $s;
      $upd = $pdo->prepare('UPDATE t2p_produkt_mat SET zpusob = ?, povinnost = ?, varianta = ? WHERE produkt_id = ? AND (ref = ? OR skupina = ?)');
      $a = []; $b = [];
      foreach ($hodnota as $x) {
        if (!is_array($x)) { $pdo->rollBack(); return [$p, [], null, 'neplatná pravidla']; }
        $cil = mat_txt($x[0] ?? ''); $s = $skl[mb_strtoupper($cil)] ?? null;
        if (!$s) { $pdo->rollBack(); return [$p, [], null, $cil . ' ve skladbě není – nejdřív ulož skladbu']; }
        $zp = ($x[1] ?? null) === null || $x[1] === '' ? null : (string) $x[1];
        $po = ($x[2] ?? null) === null || $x[2] === '' ? null : (string) $x[2];
        $va = ($x[3] ?? null) === null || trim((string) $x[3]) === '' ? null : mb_strtolower(trim((string) $x[3]));
        if ($zp !== null && !in_array($zp, PR_ZPUSOB, true)) { $pdo->rollBack(); return [$p, [], null, 'způsob musí být auto / ruční / MD']; }
        if ($po !== null && !in_array($po, PR_POVIN, true)) { $pdo->rollBack(); return [$p, [], null, 'povinnost musí být povinné / doporučené / volitelné']; }
        if ($va !== null && !preg_match('/^[a-z0-9-]{1,20}$/', $va)) { $pdo->rollBack(); return [$p, [], null, 'varianta = krátký štítek (a–z, 0–9, pomlčka, max. 20)']; }
        $n = ['zpusob' => $zp, 'povinnost' => $po, 'varianta' => $va];
        if ($n === ['zpusob' => $s['zpusob'], 'povinnost' => $s['povinnost'], 'varianta' => $s['varianta']]) continue;
        $upd->execute([$zp, $po, $va, $id, $s['ref'], $s['skupina']]);
        $a[] = pr_cil($s) . ': ' . pr_prav_txt($s); $b[] = pr_cil($s) . ': ' . pr_prav_txt($n);
      }
      if ($a) { $log[] = ['pravidla', implode('; ', $a), implode('; ', $b)]; $snimek = true; }
    } elseif ($pole === 'stav') {
      $v = (string) $hodnota; $s = $p['stav'];
      $ok = $role === 'editor'
        ? (($s === 'aktivní' && $v === 'neaktivní') || ($s === 'neaktivní' && $v === 'aktivní') || ($s === 'návrh' && in_array($v, ['aktivní', 'neaktivní'], true)))
        : ($s === 'návrh' && $v === 'neaktivní');
      if (!$ok) { $pdo->rollBack(); return [$p, [], null, 'tuto změnu stavu nelze provést']; }
      if ($v === 'aktivní' && ($p['nazev'] === '' || $p['ku'] === null || $p['kat'] === null)) { $pdo->rollBack(); return [$p, [], null, 'před schválením doplň název, KU a KAT']; }
      $zm['stav'] = $v;
      if ($s === 'návrh' && $v === 'aktivní') { $zm['schvalil'] = $kdo; $zm['schvaleno'] = $now; }
      $log[] = ['stav', $s, $v];
      $propis = $v === 'aktivní' && ($s === 'návrh' || $p['list_radek'] === null);   // nový řádek na konec listu
    } else {
      if (!in_array($pole, PR_POLE, true)) { $pdo->rollBack(); return [$p, [], null, 'neplatné pole']; }
      [$v, $err] = pr_check($pole, $hodnota);
      if ($err) { $pdo->rollBack(); return [$p, [], null, $err]; }
      if ((string) ($p[$pole] ?? '') !== (string) ($v ?? '')) {
        $zm[$pole] = $v; $log[] = [$pole, $p[$pole], $v];
        if ($pole !== 'technologie') $propis = $p['stav'] === 'aktivní';   // technologie v listu PRODB není
      }
    }
    if (!$log) { $pdo->commit(); return [$p, [], null, '']; }
    $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($zm)));
    $pdo->prepare('UPDATE t2p_produkt SET ' . ($set ? $set . ', ' : '') . 'upravil = ?, upraveno = ?, verze = verze + 1 WHERE id = ?')
        ->execute([...array_values($zm), $kdo, $now, $id]);
    $ins = $pdo->prepare("INSERT INTO t2p_produkt_zmena (produkt_id, cas, kdo, zdroj, pole, stara, nova) VALUES (?, ?, ?, 'prohlizec', ?, ?, ?)");
    foreach ($log as [$k, $a, $b]) $ins->execute([$id, $now, $kdo, $k, $a === null ? null : (string) $a, $b === null ? null : (string) $b]);   // stara/nova = TEXT (krok 38/3)
    if ($propis) pr_queue([$id]);
    if ($snimek) pr_snimek($id, (int) $p['verze'] + 1, $kdo, $now);
    $pdo->commit();
    return [pr_by_id($id), array_column($log, 0), null, ''];
  } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

/* Nový výrobek: editor → aktivní (propis na konec listu), technik → návrh (do listu nejde). */
function pr_create(array $n, string $kdo, string $role): array {
  $kod = mat_txt($n['kod'] ?? '');
  if ($kod === '' || mb_strlen($kod) > 20 || preg_match('/[\s|]/u', $kod)) return [null, 'kód je povinný, max. 20 znaků, bez mezer a „|“'];
  $v = [];
  foreach (['nazev', 'ku', 'kat', 'kzp', 'hs'] as $k) {
    [$x, $err] = pr_check($k, $n[$k] ?? '');
    if ($err) return [null, $err];
    $v[$k] = $x;
  }
  $pdo = mat_pdo(); $now = mat_now(); $navrh = $role !== 'editor';
  $pdo->beginTransaction();
  try {
    $ex = pr_by_kod($kod);
    if ($ex) { $pdo->rollBack(); return [null, 'kód ' . $ex['kod'] . ' už v katalogu je' . ($ex['stav'] !== 'aktivní' ? ' (' . $ex['stav'] . ')' : '')]; }
    $pdo->prepare('INSERT INTO t2p_produkt (kod, nazev, ku, kat, kzp, hs, stav, navrhl, navrzeno, zalozil, zalozeno, upravil, upraveno)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$kod, $v['nazev'], $v['ku'], $v['kat'], $v['kzp'], $v['hs'], $navrh ? 'návrh' : 'aktivní',
                   $navrh ? $kdo : null, $navrh ? $now : null, $kdo, substr($now, 0, 10), $kdo, $now]);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO t2p_produkt_zmena (produkt_id, cas, kdo, zdroj, pole, stara, nova) VALUES (?, ?, ?, 'prohlizec', 'vyrobek', NULL, ?)")
        ->execute([$id, $now, $kdo, $navrh ? 'návrh' : 'založen']);
    if (!$navrh) pr_queue([$id]);
    $pdo->commit();
    return [pr_by_id($id), ''];
  } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

/* ---------------------------------------------------------------------
   Propis do listu PRODB – fronta + Apps Script prodbSync (zatím neexistuje).
   Bez "propis": true zůstávají záznamy ve stavu čeká.
   --------------------------------------------------------------------- */
function pr_queue(array $ids): void {
  $sel = mat_pdo()->prepare("SELECT 1 FROM t2p_produkt_propis WHERE produkt_id = ? AND stav IN ('čeká', 'chyba') LIMIT 1");
  $ins = mat_pdo()->prepare("INSERT INTO t2p_produkt_propis (produkt_id, cas, stav) VALUES (?, ?, 'čeká')");
  foreach (array_unique($ids) as $id) { $sel->execute([$id]); if (!$sel->fetchColumn()) $ins->execute([$id, mat_now()]); }
}
/* řádek SQL → řádek listu PRODB (A–P bez J, J nese vzorec v listu) */
function pr_karta_sync(array $p, array $skl): array {
  return ['kod' => $p['kod'], 'radek' => $p['list_radek'] === null ? null : (int) $p['list_radek'], 'nazev' => $p['nazev'],
          'ku' => $p['ku'], 'kat' => $p['kat'], 'kzp' => $p['kzp'], 'hs' => $p['hs'], 'vzp' => $p['vzp'],
          'h' => pr_retezec($skl, 'hlavní'), 'i' => pr_retezec($skl, 'pomocný'), 'gmdn' => $p['gmdn'], 'udi_pi' => $p['udi_pi'],
          'trvanlivost' => $p['trvanlivost'] === null ? null : (int) $p['trvanlivost'], 'eudamed' => $p['eudamed'],
          'stary_kod' => $p['stary_kod'], 'poznamka' => $p['poznamka']];
}
function pr_sync(array $ids): array {
  $ids = array_values(array_unique(array_map('intval', $ids)));
  if (!$ids || !pr_cfg()['propis']) return array_fill_keys($ids, 'čeká');
  $pdo = mat_pdo(); $out = [];
  foreach (array_chunk($ids, 100) as $chunk) {
    $rows = [];
    foreach ($chunk as $id) {
      $p = pr_by_id($id);
      if ($p && ($p['stav'] === 'aktivní' || $p['list_radek'] !== null)) { $rows[$id] = $p; continue; }   // vyřazený řádek v listu zůstává, úpravy dopsat
      $out[$id] = 'hotovo';                        // návrh / vyřazený bez řádku: do listu nepatří
      $pdo->prepare("UPDATE t2p_produkt_propis SET stav = 'hotovo', chyba = 'přeskočeno – není v listu' WHERE produkt_id = ? AND stav IN ('čeká', 'chyba')")->execute([$id]);
    }
    if (!$rows) continue;
    $r = mat_gas(['op' => 'prodbSync', 'vyrobky' => array_map(fn($p) => pr_karta_sync($p, pr_skl((int) $p['id'])), array_values($rows))]);
    $vys = ($r['ok'] ?? false) ? ($r['vysledky'] ?? []) : [];
    foreach (array_keys($rows) as $n => $id) {
      $x = $vys[$n] ?? null;
      if ($x && mb_strtoupper((string) ($x['kod'] ?? '')) !== mb_strtoupper($rows[$id]['kod'])) $x = null;   // pořadí musí sedět
      if ($x && empty($x['error'])) {
        $out[$id] = 'hotovo';
        if (!empty($x['row']) && (int) $x['row'] !== (int) $rows[$id]['list_radek'])
          $pdo->prepare('UPDATE t2p_produkt SET list_radek = ? WHERE id = ?')->execute([(int) $x['row'], $id]);
        $pdo->prepare("UPDATE t2p_produkt_propis SET stav = 'hotovo', pokusu = pokusu + 1, chyba = NULL WHERE produkt_id = ? AND stav IN ('čeká', 'chyba')")->execute([$id]);
      } else {
        $e = $x['error'] ?? ($r['error'] ?? 'neznámá odpověď');
        $out[$id] = 'chyba: ' . $e;
        $pdo->prepare("UPDATE t2p_produkt_propis SET stav = 'chyba', pokusu = pokusu + 1, chyba = ? WHERE produkt_id = ? AND stav IN ('čeká', 'chyba')")
            ->execute([mb_substr($e, 0, 300), $id]);
      }
    }
  }
  return $out + array_fill_keys($ids, 'čeká');
}
