<?php
/* =========================================================
   VÝKAZ (krok 41) – přehled práce a odměn technika
   Výpočet z řádků EXP a MZDY, které vrací Apps Script
   (sestavaData typ „vykaz“). Nic nezapisuje do Sheets.
   Pravidla srážek a bonusů odpovídají METRICE (listopad 2026).
   ========================================================= */

const VYK_CACHE_DIR = __DIR__ . '/../../tmp/vykaz';
const VYK_ARCHIV    = __DIR__ . '/../../vykaz-archiv';
const VYK_CFG       = __DIR__ . '/../../autolab-vykaz.json';   // {"pro": "test" | "vsichni"}
const VYK_LOG       = __DIR__ . '/../../autolab-vykaz.log';
const VYK_TTL       = 900;                                      // mezipaměť dat z Apps Scriptu (s)

/* Trello účet → technik (stejné jako MAT_TECHNIK) */
const VYK_TECH = [
  '588fa9e7e5db691bfe653bce' => 'H', '611cc39d9beb8e6028ba3ae4' => 'J', '63cd0523a27f8901400a6dda' => 'Z',
  '63ea51fbb262b19c0189e9b8' => 'P', '686d1aa4bc747a96bb0fd585' => 'U', '63e911bcb90ab76e81ad4e6a' => 'I',
];
/* všechny bilance vidí H, Jana, Marie (recepce) a Brigit (rozhodnuto 28. 11. 2026) */
const VYK_ADMIN = ['588fa9e7e5db691bfe653bce', '611cc39d9beb8e6028ba3ae4', '655329144f809c15a1896437', '6b00d5c2c5be2a396f89949e'];
/* testovací provoz: jen H a Brigit, dokud „pro“ v konfiguraci není „vsichni“ */
const VYK_TEST = ['588fa9e7e5db691bfe653bce', '6b00d5c2c5be2a396f89949e'];
const VYK_KDO  = ['H', 'J', 'Z', 'P', 'U', 'I'];

/* srážka z výměru (zaměstnanec U): od června 2026 10 %, od 2027 5 % (podle METRIKY) */
function vyk_srazka(string $kdo, int $rok, int $mes = 12): float {
  if ($kdo !== 'U' || $rok * 100 + $mes < 2606) return 0.0;
  return $rok <= 26 ? 0.10 : 0.05;
}
/* mentorský bonus: komu, z čího výměru po srážce, %, od–do (RRMM včetně) */
const VYK_BONUS = [
  ['komu' => 'P', 'z' => 'U', 'pct' => 0.05, 'od' => 2601, 'do' => 9912],
  ['komu' => 'Z', 'z' => 'U', 'pct' => 0.05, 'od' => 2601, 'do' => 2610],   // Zdeněk ukončil v říjnu 2026
];
/* příspěvek na daně a pojištění společníků (mimo nárok, jen kontrola rovného dílu) – sazby z METRIKY B3:D3 */
const VYK_PRISP_KDO = ['H', 'J'];
const VYK_PRISP = ['soc' => 0.35 / 2, 'zdr' => 0.135 / 2, 'dan' => 0.15];
/* zaměstnanec – výplata čistou mzdou, „zbývá doplatit“ se nepočítá */
function vyk_zamestnanec(string $kdo, int $rok): bool { return $kdo === 'U' && $rok <= 26; }

/* oblast podle kat. W: F fixní, R snímatelná, O ortodoncie, U jiné */
function vyk_oblast(string $w): string {
  $c = strtoupper(substr(trim($w), 0, 1));
  return in_array($c, ['F', 'R', 'O'], true) ? $c : 'U';
}

function vyk_cfg(): array {
  $j = is_file(VYK_CFG) ? json_decode((string) file_get_contents(VYK_CFG), true) : null;
  return is_array($j) ? $j : [];
}
function vyk_smi(string $actor): bool {
  if (!isset(VYK_TECH[$actor]) && !in_array($actor, VYK_ADMIN, true)) return false;
  return (vyk_cfg()['pro'] ?? 'test') === 'vsichni' || in_array($actor, VYK_TEST, true);
}
function vyk_admin(string $actor): bool { return in_array($actor, VYK_ADMIN, true); }

function vyk_log(string $actor, string $what, string $res): void {
  if (is_file(VYK_LOG) && filesize(VYK_LOG) > 1048576) @rename(VYK_LOG, VYK_LOG . '.1');
  @file_put_contents(VYK_LOG, date('c') . "\t" . $actor . "\t" . $what . "\t" . $res . "\n", FILE_APPEND | LOCK_EX);
}

/* surová data roku (a předchozího) z Apps Scriptu, s mezipamětí */
function vyk_raw(int $rok, bool $obnovit = false): array {
  if (!is_dir(VYK_CACHE_DIR)) @mkdir(VYK_CACHE_DIR, 0750, true);
  $f = VYK_CACHE_DIR . '/raw-' . $rok . '.json';
  if (!$obnovit && is_file($f) && filemtime($f) > time() - VYK_TTL) {
    $j = json_decode((string) file_get_contents($f), true);
    if (is_array($j)) return $j;
  }
  $r = arch_gas(['action' => 'sestava', 'typ' => 'vykaz', 'rok' => $rok], 120);
  if (empty($r['ok']) || !is_array($r['data'] ?? null)) {
    if (is_file($f)) { $j = json_decode((string) file_get_contents($f), true); if (is_array($j)) { $j['zastarale'] = true; return $j; } }
    throw new RuntimeException((string) ($r['error'] ?? 'Apps Script nevrátil data'));
  }
  $d = $r['data']; $d['nacteno'] = time();
  @file_put_contents($f, json_encode($d, JSON_UNESCAPED_UNICODE), LOCK_EX);
  return $d;
}

/* výměr po srážce za technika a měsíc */
function vyk_vymery(array $rows, int $rok): array {
  $q = [];
  foreach ($rows as $r) if ((int) $r[2] === $rok) $q[$r[0]][(int) $r[1]] = ($q[$r[0]][(int) $r[1]] ?? 0) + (float) $r[7];
  return $q;
}

/* bilance technika za rok */
function vyk_bilance(array $raw, string $kdo, int $rok): array {
  $rows = $raw['rows'] ?? []; $mz = $raw['mzdy'] ?? [];
  $Q = vyk_vymery($rows, $rok); $Qp = vyk_vymery($rows, $rok - 1);
  $m = [];
  for ($i = 1; $i <= 12; $i++) $m[$i] = ['q' => 0.0, 'obrat' => 0.0, 'n' => 0, 'ord' => [], 'obl' => ['F' => 0.0, 'R' => 0.0, 'O' => 0.0, 'U' => 0.0],
    'lab' => ['F' => 0.0, 'R' => 0.0, 'O' => 0.0, 'U' => 0.0], 'vykony' => [], 'platby' => [], 'uhrDenteo' => 0.0, 'uhrLekari' => 0.0];
  $prev = array_fill(1, 12, ['narok' => 0.0, 'obrat' => 0.0]);
  foreach ($rows as $r) {
    [$c, $mes, $k, $ord, $kod, $qty, $obrat, $odm, $w, $tag, $pac, $dat, $ref] = $r;
    $mes = (int) $mes; $k = (int) $k; $ob = vyk_oblast((string) $w); $sr = vyk_srazka($kdo, $k, $mes);
    if ($k === $rok) {
      $m[$mes]['lab'][$ob] += (float) $obrat;
      if ($c !== $kdo) continue;
      $x = &$m[$mes];
      $x['q'] += (float) $odm; $x['obrat'] += (float) $obrat; $x['n']++; $x['obl'][$ob] += (float) $obrat;
      $o = $ord !== '' ? $ord : '–';
      if (!isset($x['ord'][$o])) $x['ord'][$o] = ['n' => 0, 'obrat' => 0.0, 'vymer' => 0.0];
      $x['ord'][$o]['n']++; $x['ord'][$o]['obrat'] += (float) $obrat; $x['ord'][$o]['vymer'] += (float) $odm * (1 - $sr);
      $x['vykony'][] = ['tag' => $tag, 'dat' => $dat, 'pac' => $pac, 'kod' => $kod, 'q' => $qty, 'ord' => $ord, 'obrat' => (float) $obrat, 'odm' => round((float) $odm * (1 - $sr), 2), 'ref' => $ref, 'obl' => $ob];
      unset($x);
    } elseif ($k === $rok - 1 && $c === $kdo) {
      $prev[$mes]['narok'] += (float) $odm * (1 - $sr); $prev[$mes]['obrat'] += (float) $obrat;
    }
  }
  foreach ($mz as $p) {
    [$os, $cast, $mes, $k, $uhr, $zp, $vs, $pozn] = $p;
    if ($os !== $kdo || (int) $k !== $rok) continue;
    $lek = preg_match('/^VF/i', (string) $vs) && stripos((string) $pozn, 'denteo') === false;
    $m[(int) $mes][$lek ? 'uhrLekari' : 'uhrDenteo'] += (float) $cast;
    $m[(int) $mes]['platby'][] = ['castka' => (float) $cast, 'kdy' => $uhr, 'zpusob' => $zp, 'vs' => $vs, 'pozn' => $pozn, 'lekar' => $lek];
  }
  $zam = vyk_zamestnanec($kdo, $rok);
  $banka = vyk_sparuj($m, $kdo, $rok);
  /* Bonus ABX – Addon Benefit Extra (H, licence za SW upgrade): kusy celé laboratoře s ročníkem ceníku (EXP L) = rok × CEN!CR, jako METRIKA/ABX */
  $abx = array_fill(1, 12, ['ks' => 0.0, 'castka' => 0.0]);
  $A = $raw['abx'] ?? null;
  if (is_array($A) && ($A['kdo'] ?? '') === $kdo) foreach ($rows as $r) {
    $kod = (string) $r[4];
    if ((string) ($r[13] ?? '') !== (string) $rok || !isset($A['ceny'][$kod])) continue;
    $k = (int) $r[2]; $mm = $k === $rok ? (int) $r[1] : ($k < $rok ? 1 : 12);
    $abx[$mm]['ks'] += (float) $r[5]; $abx[$mm]['castka'] += (float) $r[5] * (float) $A['ceny'][$kod];
  }
  $out = []; $sum = ['q' => 0, 'srazka' => 0, 'vymer' => 0, 'bonus' => 0, 'narok' => 0, 'obrat' => 0, 'uhrazeno' => 0, 'n' => 0];
  $saldo = 0;
  foreach ($m as $i => $x) {
    $yymm = $rok * 100 + $i; $bon = [];
    foreach (VYK_BONUS as $b) if ($b['komu'] === $kdo && $yymm >= $b['od'] && $yymm <= $b['do']) {
      $base = ($Q[$b['z']][$i] ?? 0) * (1 - vyk_srazka($b['z'], $rok, $i));
      if ($base > 0) $bon[] = ['z' => $b['z'], 'pct' => $b['pct'], 'zaklad' => round($base), 'castka' => round($base * $b['pct'])];
    }
    if ($rok - 1 >= 26) foreach (VYK_BONUS as $b) if ($b['komu'] === $kdo && ($rok - 1) * 100 + $i >= $b['od'] && ($rok - 1) * 100 + $i <= $b['do'])
      $prev[$i]['narok'] += round(($Qp[$b['z']][$i] ?? 0) * (1 - vyk_srazka($b['z'], $rok - 1, $i)) * $b['pct']);
    if ($abx[$i]['ks'] > 0) $bon[] = ['typ' => 'abx', 'z' => 'ABX', 'pct' => 0, 'ks' => $abx[$i]['ks'], 'zaklad' => 0, 'castka' => round($abx[$i]['castka'])];
    $sr = vyk_srazka($kdo, $rok, $i); $vym = round($x['q'] * (1 - $sr)); $bs = array_sum(array_column($bon, 'castka'));
    $uhr = $x['uhrDenteo'] + $x['uhrLekari']; $nar = $vym + $bs; $saldo += round($nar - $uhr);
    uasort($x['ord'], fn($a, $b) => $b['obrat'] <=> $a['obrat']);
    $labG = array_sum($x['lab']);
    $out[$i] = ['mesic' => $i, 'q' => round($x['q']), 'srazka' => round($x['q'] * $sr), 'srazkaPct' => $sr, 'vymer' => $vym, 'bonus' => $bon, 'bonusSum' => $bs,
      'narok' => $nar, 'obrat' => round($x['obrat']), 'n' => $x['n'], 'uhrDenteo' => round($x['uhrDenteo']), 'uhrLekari' => round($x['uhrLekari']),
      'uhrazeno' => round($uhr), 'zbyva' => $zam ? null : round($nar - $uhr), 'saldo' => $zam ? null : $saldo, 'ord' => $x['ord'], 'obl' => array_map('round', $x['obl']),
      'lab' => array_map('round', $x['lab']) + ['G' => round($labG)], 'vykony' => $x['vykony'], 'platby' => $x['platby'], 'bankaNavic' => $x['bankaNavic'] ?? [],
      'loni' => ['narok' => round($prev[$i]['narok']), 'obrat' => round($prev[$i]['obrat'])],
      'prispevek' => in_array($kdo, VYK_PRISP_KDO, true) ? array_map(fn($p) => round($nar * $p), VYK_PRISP) + ['celkem' => round($nar * array_sum(VYK_PRISP))] : null];
    foreach (['q', 'srazka', 'vymer', 'narok', 'obrat', 'uhrazeno', 'n'] as $k) $sum[$k] += $out[$i][$k];
    $sum['bonus'] += $bs;
  }
  /* nedoplatky měsíců se vyrovnávají pozdějšími přeplatky (nejstarší první) */
  if (!$zam) {
    $fronta = []; $kredit = 0;
    foreach ($out as $i => &$o) {
      $o['otevreno'] = 0; $o['vyrovnal'] = null; $z = (int) $o['zbyva'];
      if ($z > 0) { $u = min($kredit, $z); $kredit -= $u; $z -= $u; if ($z > 0) $fronta[] = [$i, $z]; elseif ($u > 0) $o['vyrovnal'] = $i; }
      elseif ($z < 0) {
        $k = -$z;
        while ($k > 0 && $fronta) { $u = min($k, $fronta[0][1]); $k -= $u; $fronta[0][1] -= $u; if ($fronta[0][1] <= 0) { $out[$fronta[0][0]]['vyrovnal'] = $i; array_shift($fronta); } }
        $kredit += $k;
      }
    }
    unset($o);
    foreach ($fronta as $f) $out[$f[0]]['otevreno'] = $f[1];
  }
  $sum['zbyva'] = $zam ? null : $sum['narok'] - $sum['uhrazeno'];
  $sum['prispevek'] = in_array($kdo, VYK_PRISP_KDO, true) ? array_map(fn($p) => round($sum['narok'] * $p), VYK_PRISP) + ['celkem' => round($sum['narok'] * array_sum(VYK_PRISP))] : null;
  return ['kdo' => $kdo, 'jmeno' => (string) ($raw['jmena'][$kdo] ?? $kdo), 'rok' => $rok, 'zamestnanec' => $zam,
          'mesice' => array_values($out), 'soucet' => $sum, 'jmenaOrd' => array_filter($raw['jmena'] ?? [], fn($k) => strlen((string) $k) === 3, ARRAY_FILTER_USE_KEY),
          'cas' => $raw['cas'] ?? '', 'zastarale' => !empty($raw['zastarale']), 'archiv' => vyk_archiv_seznam($kdo, $rok), 'banka' => $banka];
}

/* ---------- archiv uzavřených měsíců ---------- */
function vyk_archiv_cesta(string $kdo, int $rok, int $mes): string { return VYK_ARCHIV . '/20' . $rok . '/' . $kdo . '-' . sprintf('%02d', $mes); }
function vyk_archiv_seznam(string $kdo, int $rok): array {
  $o = [];
  for ($i = 1; $i <= 12; $i++) {
    $f = vyk_archiv_cesta($kdo, $rok, $i) . '.json';
    if (is_file($f)) { $j = json_decode((string) file_get_contents($f), true); if (is_array($j)) $o[(string) $i] = ['kdy' => $j['kdy'] ?? '', 'kdo' => $j['uzavrel'] ?? '', 'sha256' => $j['sha256'] ?? '', 'narok' => $j['narok'] ?? null]; }
  }
  return $o;
}

/* ---------- PDF výkazu za měsíc ---------- */
function vyk_pdf(array $b, int $mes, bool $uzavreno, string $kdoUzavrel = ''): string {
  $e = 'arch_e'; $x = $b['mesice'][$mes - 1]; $rok = 2000 + $b['rok'];
  $MES = ['', 'leden', 'únor', 'březen', 'duben', 'květen', 'červen', 'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'];
  $h = sest_hlava('Výkaz práce technika', ($uzavreno ? 'uzavřený výkaz' : 'PRŮBĚŽNÝ výkaz – měsíc není uzavřený') . ' · ' . $MES[$mes] . ' ' . $rok, 'font-size:19pt');
  $h .= '<table style="width:100%;margin-top:4mm"><tr><td class="b" style="font-size:14pt">' . $e($b['jmeno']) . ' (' . $e($b['kdo']) . ')</td>'
      . '<td class="r"><div class="xs">nárok za měsíc</div><div class="big">' . sest_kc($x['narok']) . '</div></td></tr></table>';
  $r = fn($l, $v, $st = '') => '<tr class="it"><td style="' . $st . '">' . $e($l) . '</td><td class="r" style="' . $st . '">' . $v . '</td></tr>';
  $h .= '<div class="sec" style="margin-top:4mm">Bilance</div><table style="width:110mm;margin-top:1mm">';
  if ($x['srazka'] > 0) $h .= $r('Odměna podle evidence', sest_kc($x['q'])) . $r('srážka ' . round($x['srazkaPct'] * 100) . ' %', '− ' . sest_kc($x['srazka']));
  $h .= $r('Vyděláno (výměr)', sest_kc($x['vymer']), 'font-weight:bold');
  foreach ($x['bonus'] as $bo) $h .= $r(($bo['typ'] ?? '') === 'abx' ? '+ Bonus ABX (Addon Benefit Extra, ' . round($bo['ks']) . ' ks)' : '+ mentorský bonus ' . round($bo['pct'] * 100) . ' % z výměru ' . $bo['z'], sest_kc($bo['castka']), 'color:#7a3fb5');
  $h .= $r('= nárok', sest_kc($x['narok']), 'font-weight:bold;border-top:0.3mm solid #000');
  $h .= $r('Uhrazeno od Dentea', sest_kc($x['uhrDenteo']));
  if ($x['uhrLekari'] > 0) $h .= $r('Uhrazeno přímo lékaři (vlastní faktury)', sest_kc($x['uhrLekari']));
  if ($x['zbyva'] !== null) {
    $h .= $r($x['zbyva'] >= 0 ? 'Zbývá doplatit za měsíc' : 'Přeplaceno za měsíc', sest_kc(abs($x['zbyva'])), 'font-weight:bold');
    $MESx = ['', 'leden', 'únor', 'březen', 'duben', 'květen', 'červen', 'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'];
    if ($x['zbyva'] > 0) $h .= $r(!empty($x['vyrovnal']) ? ($x['vyrovnal'] === $x['mesic'] ? 'Vyrovnáno dřívějším přeplatkem' : 'Vyrovnáno pozdější platbou (' . $MESx[$x['vyrovnal']] . ')') : 'Po všech platbách roku zbývá', !empty($x['vyrovnal']) ? '0 Kč' : sest_kc($x['otevreno']));
  }
  else $h .= $r('Zaměstnanec – výplata mzdou, doplatek se nepočítá', '');
  if (!empty($x['prispevek'])) {
    $pp = $x['prispevek'];
    $h .= $r('Příspěvek na daně a pojištění (mimo nárok)', sest_kc($pp['celkem']), 'border-top:0.3mm solid #999');
    $h .= $r('  sociální 17,5 % · zdravotní 6,75 % · daň 15 % z nároku', sest_kc($pp['soc']) . ' · ' . sest_kc($pp['zdr']) . ' · ' . sest_kc($pp['dan']), 'color:#666;font-size:7.5pt');
  }
  $h .= '</table>';
  $h .= '<div class="sec" style="margin-top:4mm">Podle ordinací</div><table style="width:100%;margin-top:1mm"><tr class="gh"><td>ordinace</td><td class="r">výkonů</td><td class="r">obrat</td><td class="r">výměr</td></tr>';
  foreach ($x['ord'] as $o => $v) $h .= '<tr class="it"><td>' . $e($o) . ' ' . $e($b['jmenaOrd'][$o] ?? '') . '</td><td class="r">' . $v['n'] . '</td><td class="r">' . sest_kc($v['obrat']) . '</td><td class="r">' . sest_kc($v['vymer']) . '</td></tr>';
  $h .= '</table>';
  $h .= '<div class="sec" style="margin-top:4mm">Výkony</div><table style="width:100%;margin-top:1mm"><tr class="gh"><td>datum</td><td>zakázka</td><td>pacient</td><td>kód</td><td>ord.</td><td class="r">Q</td><td class="r">obrat</td><td class="r">odměna</td></tr>';
  foreach ($x['vykony'] as $v) $h .= '<tr class="it"><td>' . $e($v['dat']) . '</td><td>' . $e($v['tag']) . '</td><td>' . $e($v['pac']) . '</td><td>' . $e($v['kod']) . '</td><td>' . $e($v['ord']) . '</td><td class="r">' . $e((string) $v['q']) . '</td><td class="r">' . sest_kc($v['obrat']) . '</td><td class="r b">' . sest_kc($v['odm']) . '</td></tr>';
  $h .= '</table>';
  if ($uzavreno) $h .= '<p class="xs" style="margin-top:4mm">Měsíc uzavřel(a) ' . $e($kdoUzavrel) . ' ' . date('j. n. Y H:i') . '. Data z EVIDENCE k ' . $e($b['cas']) . '.</p>';
  else $h .= '<p class="xs" style="margin-top:4mm">Průběžný výkaz – čísla se mohou do uzavření měsíce změnit. Data z EVIDENCE k ' . $e($b['cas']) . '.</p>';
  $id = 'VÝKAZ ' . $b['kdo'] . ' ' . $mes . '/' . $rok;
  $p = sest_mpdf('Výkaz ' . $b['kdo'] . ' ' . $mes . '/' . $rok, 'vykaz', $id, false);
  $p->WriteHTML($h, \Mpdf\HTMLParserMode::HTML_BODY);
  return $p->Output('', 'S');
}

/* Názvy výrobků z SQL (t2p_produkt) pro rozpad podle typu práce.
   Kód se zkusí přesně, jinak bez koncového písmenného přípony (110411D → 110411).
   Při nedostupném SQL vrátí prázdné pole – okno pak ukáže kódy.            */
function vyk_nazvy(array $kody): array {
  $kody = array_values(array_unique(array_filter(array_map('strval', $kody), 'strlen')));
  if (!$kody) return [];
  try {
    require_once __DIR__ . '/mat-lib.php';
    $zk = $kody;
    foreach ($kody as $k) { $z = preg_replace('~[A-Za-z/]+$~', '', $k); if ($z !== '' && $z !== $k) $zk[] = $z; }
    $zk = array_values(array_unique($zk));
    $st = mat_pdo()->prepare('SELECT kod, nazev FROM t2p_produkt WHERE kod IN (' . implode(',', array_fill(0, count($zk), '?')) . ')');
    $st->execute($zk);
    $m = [];
    foreach ($st->fetchAll(PDO::FETCH_NUM) as $r) $m[mb_strtoupper((string) $r[0])] = trim((string) $r[1]);
    $out = [];
    foreach ($kody as $k) {
      $u = mb_strtoupper($k); $z = preg_replace('~[A-Z/]+$~', '', $u);
      if (isset($m[$u])) $out[$k] = $m[$u];
      elseif ($z !== '' && isset($m[$z])) $out[$k] = $m[$z];
    }
    return $out;
  } catch (Throwable $e) { return []; }
}

/* ---------- párování plateb MZDY s bankou Fio (krok 43) ----------
   Účty lidí jsou jen na serveru v autolab-fio-osoby.json (repozitář je veřejný):
   {"H": {"ucty": ["číslo/kód"], "text": []}, "U": {"ucty": [...], "text": ["Urbánek"]}, ...,
    "_vyradit": [{"id": "<ID pohybu>", "duvod": "…"}]}   ← pohyby vyřazené z párování
   Nic nezapisuje – jen označí platby v okně výkazu.                                    */
const VYK_FIO_OSOBY = __DIR__ . '/../../autolab-fio-osoby.json';

function vyk_bank_pohyby(string $kdo): array {
  if (!is_file(VYK_FIO_OSOBY)) return ['tx' => [], 'od' => null, 'do' => null];
  require_once __DIR__ . '/fio-lib.php';
  $cfgO = json_decode((string) file_get_contents(VYK_FIO_OSOBY), true) ?: [];
  $os = $cfgO[$kdo] ?? null;
  $vyrad = array_map(fn($v) => (string) $v['id'], $cfgO['_vyradit'] ?? []);   // pohyby, které nejsou mzda (např. proplacený nákup)
  if (!$os) return ['tx' => [], 'od' => null, 'do' => null];
  $ucty = array_map('strval', $os['ucty'] ?? []); $txt = $os['text'] ?? [];
  $tx = []; $od = null; $do = null;
  foreach (glob(FIO_DATA . '/*.json') ?: [] as $f) {
    $d = json_decode((string) file_get_contents($f), true); if (!is_array($d)) continue;
    if (!empty($d['od']) && count($d['pohyby'] ?? []) > 20) { $od = $od === null ? $d['od'] : min($od, $d['od']); $do = $do === null ? $d['do'] : max($do, $d['do']); }
    foreach ($d['pohyby'] ?? [] as $p) {
      if ($p['castka'] >= 0 || in_array((string) $p['id'], $vyrad, true)) continue;
      $zp = trim($p['zprava'] === $p['ident'] ? $p['zprava'] : $p['zprava'] . ' ' . $p['ident']);
      $moje = in_array($p['protiucet'], $ucty, true);
      foreach ($txt as $t) if ($t !== '' && mb_stripos($zp, $t) !== false) $moje = true;
      if (!$moje) continue;
      if (preg_match('~z[aá]loh~iu', $zp) && in_array($kdo, VYK_PRISP_KDO, true)) continue;   // zálohy SP/ZP společníků nejsou mzda
      $tag = preg_match('~\b(\d{1,2})\s*/\s*(?:20)?(\d{2})\b~', $zp, $mm) ? [(int) $mm[2], (int) $mm[1]] : null;
      $tx[] = ['id' => $p['id'], 'datum' => $p['datum'], 'castka' => round(-$p['castka'], 2), 'zprava' => $zp, 'tag' => $tag, 'ucet' => (string) ($d['ucet'] ?? '')];
    }
  }
  usort($tx, fn($a, $b) => strcmp($a['datum'], $b['datum']));
  return ['tx' => $tx, 'od' => $od, 'do' => $do];
}
function vyk_dat(string $s): ?string { return preg_match('~^(\d{1,2})\.(\d{1,2})\.(\d{4})$~', trim($s), $m) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null; }

/* $m = měsíce výkazu (s 'platby'); doplní ke každé platbě 'banka' a k měsíci 'bankaNavic' */
function vyk_sparuj(array &$m, string $kdo, int $rok): array {
  $B = vyk_bank_pohyby($kdo);
  if (!$B['od']) return ['zapnuto' => false];
  $tx = $B['tx']; $pouzite = [];
  $den = fn($a, $b) => abs((strtotime($a) - strtotime($b)) / 86400);
  $kand = [];
  foreach ($m as $i => &$x) foreach ($x['platby'] as $j => &$p) {
    $kdy = vyk_dat((string) $p['kdy']);
    $odhad = $kdy ?? sprintf('20%02d-%02d-28', $i === 12 ? $rok + 1 : $rok, $i === 12 ? 1 : $i + 1);
    if ($p['lekar']) { $p['banka'] = ['stav' => 'lekar']; continue; }
    if (mb_stripos((string) $p['zpusob'], 'hotov') !== false) { $p['banka'] = ['stav' => 'hotovost']; continue; }
    if ($odhad < $B['od']) { $p['banka'] = ['stav' => 'mimo']; continue; }
    $p['banka'] = ['stav' => 'nesparovano'];
    $kand[] = [$i, $j, $kdy, $odhad];
  }
  unset($x, $p);
  /* průchody od nejjistějšího: stejná částka + měsíc ve zprávě + datum, … , pak součet více plateb téhož měsíce */
  $pruchody = [
    fn($t, $i, $kdy) => $t['tag'] === [$rok, $i] && $kdy !== null && abs(strtotime($t['datum']) - strtotime($kdy)) <= 10 * 86400,
    fn($t, $i, $kdy) => $t['tag'] === [$rok, $i],
    fn($t, $i, $kdy, $odh) => $t['tag'] === null && abs(strtotime($t['datum']) - strtotime($odh)) <= 20 * 86400,
  ];
  foreach ($pruchody as $ok) foreach ($kand as $k => [$i, $j, $kdy, $odh]) {
    $p = &$m[$i]['platby'][$j];
    if ($p['banka']['stav'] !== 'nesparovano') { unset($p); continue; }
    $best = null;
    foreach ($tx as $n => $t) {
      if (isset($pouzite[$n]) || abs($t['castka'] - $p['castka']) > 0.5 || !$ok($t, $i, $kdy, $odh)) continue;
      if ($best === null || $den($t['datum'], $odh) < $den($tx[$best]['datum'], $odh)) $best = $n;
    }
    if ($best !== null) { $pouzite[$best] = 1; $t = $tx[$best]; $p['banka'] = ['stav' => 'banka', 'datum' => $t['datum'], 'zprava' => $t['zprava'], 'ucet' => $t['ucet']]; }
    unset($p);
  }
  /* součet: např. mzda + FÚ + VZP + OSSZ zaměstnance za stejný měsíc */
  foreach ($kand as [$i, $j]) {
    $p = &$m[$i]['platby'][$j];
    if ($p['banka']['stav'] === 'nesparovano') {
      $sk = []; $sum = 0.0;
      foreach ($tx as $n => $t) if (!isset($pouzite[$n]) && $t['tag'] === [$rok, $i]) { $sk[] = $n; $sum += $t['castka']; }
      if (count($sk) > 1 && abs($sum - $p['castka']) <= 0.5) {
        foreach ($sk as $n) $pouzite[$n] = 1;
        $p['banka'] = ['stav' => 'banka', 'datum' => $tx[end($sk)]['datum'], 'zprava' => count($sk) . ' platby: ' . implode(' + ', array_map(fn($n) => $tx[$n]['zprava'], $sk)), 'ucet' => $tx[$sk[0]]['ucet']];
      }
    }
    unset($p);
  }
  /* bez data a z měsíce, který začal před napojením banky → nelze ověřit */
  foreach ($kand as [$i, $j, $kdy]) {
    $p = &$m[$i]['platby'][$j];
    if ($p['banka']['stav'] === 'nesparovano' && $kdy === null && sprintf('20%02d-%02d-01', $rok, $i) < $B['od']) $p['banka'] = ['stav' => 'mimo'];
    unset($p);
  }
  /* bankovní platby, které v MZDY chybí – k měsíci ze zprávy, jinak k měsíci před datem platby */
  foreach ($tx as $n => $t) {
    if (isset($pouzite[$n])) continue;
    if ($t['tag']) { [$ry, $mi] = $t['tag']; } else { $ts = strtotime($t['datum'] . ' -1 month'); $ry = (int) date('y', $ts); $mi = (int) date('n', $ts); }
    if ($ry !== $rok || $mi < 1 || $mi > 12) continue;
    $m[$mi]['bankaNavic'][] = ['datum' => $t['datum'], 'castka' => $t['castka'], 'zprava' => $t['zprava'], 'odhad' => !$t['tag']];
  }
  return ['zapnuto' => true, 'od' => $B['od'], 'do' => $B['do']];
}

/* Bonus ABX zobrazuje rozepsaný jen H a Brigit. Ostatním (Jana, Marie) se přičte do výměru,
   nárok i saldo zůstávají stejné. Uzavřené PDF v archivu je vždy v této sloučené podobě.  */
const VYK_ABX_VIDI = ['588fa9e7e5db691bfe653bce', '6b00d5c2c5be2a396f89949e'];
function vyk_skryj_abx(array $b): array {
  $celkem = 0;
  foreach ($b['mesice'] as &$x) {
    $a = 0;
    $x['bonus'] = array_values(array_filter($x['bonus'], function ($bo) use (&$a) { if (($bo['typ'] ?? '') === 'abx') { $a += $bo['castka']; return false; } return true; }));
    if ($a) { $x['vymer'] += $a; $x['bonusSum'] -= $a; $celkem += $a; }
  }
  unset($x);
  if ($celkem) { $b['soucet']['vymer'] += $celkem; $b['soucet']['bonus'] -= $celkem; }
  return $b;
}
