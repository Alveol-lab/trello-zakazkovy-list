<?php
/* =========================================================
   Technologie výrobku a deník frézy VHF K5 (krok F2)
   - technologie: výchozí hodnota u výrobku (t2p_produkt.technologie),
     volba u výkonu (vykon_technologie), trend 3× shodná volba → nová výchozí
   - fréza: výpočty z DentalCAM (frez_calc) a obrábění z DentalCNC (frez_cnc),
     posílá je sběrač z PC frézy přes frez-api.php
   Přepínač a klíče: autolab-frez.json mimo web
     {"tech": true, "frez": true, "klic_sber": "…", "klic_aes": "base64 32 B", "klic_hmac": "…"}
   Jméno pacienta: jen šifrovaně (AES-256-GCM) + HMAC otisk pro párování,
   do prohlížeče ani do logu nejde.
   ========================================================= */
require_once __DIR__ . '/mat-lib.php';

const FREZ_CFG_FILE = __DIR__ . '/../../autolab-frez.json';
const FREZ_LOG      = __DIR__ . '/../../autolab-frez.log';
const TECH_HODNOTY  = ['Frézování', '3D print', 'Press'];
const TECH_TREND    = 3;          // kolik posledních shodných voleb přepíše výchozí hodnotu
const FREZ_OKNO_S   = 180;        // obrábění začne do 3 min po výpočtu (vzorky: 5–70 s)
const FREZ_SKUPINA  = [            // materiál v DentalCAM → přípustné skupiny karty MAT
  'zirconia' => ['SK-ZR-BLOK'], 'cercon' => ['SK-ZR-BLOK'],
  'pmma'     => ['SK-PMMA-BLOK', 'SK-PROTEZ-DISK'],   // Ivotion Dent Multi (Ivoclar, BEM „ID-…“) je v SK-PROTEZ-DISK
  'peek'     => ['SK-PEEK-BLOK'],
];

function frez_cfg(): array {
  static $c = null;
  if ($c === null) {
    $j = is_file(FREZ_CFG_FILE) ? json_decode((string) file_get_contents(FREZ_CFG_FILE), true) : null;
    $c = is_array($j) ? $j : [];
    $c['tech'] = ($c['tech'] ?? false) === true;
    $c['frez'] = ($c['frez'] ?? false) === true;
  }
  return $c;
}
function tech_on(): bool { return frez_cfg()['tech']; }
function frez_on(): bool { return frez_cfg()['frez']; }
function frez_log(string $kdo, string $co, string $vysl): void {
  @file_put_contents(FREZ_LOG, date('c') . "\t" . $kdo . "\t" . $co . "\t" . $vysl . "\n", FILE_APPEND | LOCK_EX);
}
function frez_tag_ok(string $t): bool { return (bool) preg_match('/^[A-Za-z0-9]{6,20}$/', $t); }

/* ---------------------------------------------------------------------
   Technologie
   --------------------------------------------------------------------- */
/* výchozí technologie pro kódy výrobků → [kód => technologie|null] */
function tech_vychozi(array $kody): array {
  $kody = array_values(array_unique(array_filter(array_map(fn($k) => mb_substr(mat_txt($k), 0, 20), $kody), 'strlen')));
  if (!$kody) return [];
  $st = mat_pdo()->prepare('SELECT kod, technologie FROM t2p_produkt WHERE kod IN (' . implode(',', array_fill(0, count($kody), '?')) . ')');
  $st->execute($kody);
  $out = array_fill_keys($kody, null);
  foreach ($st as $r) foreach ($kody as $k) if (mb_strtoupper($k) === mb_strtoupper($r['kod'])) $out[$k] = $r['technologie'];
  return $out;
}
/* volby u výkonů jedné zakázky → [kód => technologie] */
function tech_zakazka(string $tag): array {
  if (!frez_tag_ok($tag)) return [];
  $st = mat_pdo()->prepare('SELECT kod, technologie FROM vykon_technologie WHERE tag = ?');
  $st->execute([$tag]);
  $out = []; foreach ($st as $r) $out[$r['kod']] = $r['technologie'];
  return $out;
}
/* Volba technologie u výkonu. Uloží (tag, kód) a když posledních TECH_TREND voleb
   u výrobku je shodných a liší se od výchozí, přepíše výchozí v t2p_produkt (historie zdroj trello).
   Vrací ['ok', 'vychozi', 'zmena' => bool] nebo ['ok' => false, 'error']. */
function tech_volba(string $tag, string $kod, string $tech, string $kdo): array {
  $kod = mb_substr(mat_txt($kod), 0, 20);
  if (!frez_tag_ok($tag) || $kod === '') return ['ok' => false, 'error' => 'chybí kód zakázky nebo výrobku'];
  if (!in_array($tech, TECH_HODNOTY, true)) return ['ok' => false, 'error' => 'neznámá technologie'];
  $pdo = mat_pdo();
  $p = $pdo->prepare('SELECT id, kod, technologie, verze FROM t2p_produkt WHERE kod = ?');
  $p->execute([$kod]);
  $p = $p->fetch();
  if (!$p) return ['ok' => false, 'error' => 'výrobek ' . $kod . ' není v katalogu'];
  $pdo->beginTransaction();
  try {
    $now = mat_now();
    $pdo->prepare('INSERT INTO vykon_technologie (tag, kod, technologie, kdo, cas) VALUES (?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE technologie = VALUES(technologie), kdo = VALUES(kdo), cas = VALUES(cas)')
        ->execute([$tag, $p['kod'], $tech, $kdo, $now]);
    $st = $pdo->prepare('SELECT technologie FROM vykon_technologie WHERE kod = ? ORDER BY cas DESC, id DESC LIMIT ' . TECH_TREND);
    $st->execute([$p['kod']]);
    $posl = $st->fetchAll(PDO::FETCH_COLUMN);
    $zmena = false; $vych = $p['technologie'];
    if (count($posl) === TECH_TREND && count(array_unique($posl)) === 1 && $posl[0] !== $vych) {
      $pdo->prepare('UPDATE t2p_produkt SET technologie = ?, upravil = ?, upraveno = ?, verze = verze + 1 WHERE id = ?')
          ->execute([$posl[0], $kdo, $now, $p['id']]);
      $pdo->prepare("INSERT INTO t2p_produkt_zmena (produkt_id, cas, kdo, zdroj, pole, stara, nova) VALUES (?, ?, ?, 'trello', 'technologie', ?, ?)")
          ->execute([$p['id'], $now, $kdo, $vych, $posl[0]]);
      $zmena = true; $vych = $posl[0];
    }
    $pdo->commit();
    return ['ok' => true, 'vychozi' => $vych, 'zmena' => $zmena];
  } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

/* ---------------------------------------------------------------------
   Jméno pacienta: šifra + otisk
   --------------------------------------------------------------------- */
function frez_jmeno_norm(string $s): string {
  $s = mb_strtolower(trim(preg_replace('/[\s_]+/u', ' ', $s)));
  $t = function_exists('transliterator_transliterate') ? transliterator_transliterate('Any-Latin; Latin-ASCII', $s) : iconv('UTF-8', 'ASCII//TRANSLIT', $s);
  return preg_replace('/[^a-z ]/', '', (string) $t);
}
function frez_klic(string $k): string {
  $v = (string) (frez_cfg()[$k] ?? '');
  if ($v === '') throw new RuntimeException('server: chybí ' . $k);
  return $k === 'klic_aes' ? (string) base64_decode($v, true) : $v;
}
function frez_enc(string $s): string {
  $iv = random_bytes(12); $tag = '';
  $c = openssl_encrypt($s, 'aes-256-gcm', frez_klic('klic_aes'), OPENSSL_RAW_DATA, $iv, $tag);
  if ($c === false) throw new RuntimeException('šifrování selhalo');
  return $iv . $tag . $c;
}
function frez_dec(?string $b): ?string {
  if ($b === null || strlen($b) < 29) return null;
  $r = openssl_decrypt(substr($b, 28), 'aes-256-gcm', frez_klic('klic_aes'), OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16));
  return $r === false ? null : $r;
}
function frez_hash(string $jmeno): string { return hash_hmac('sha256', frez_jmeno_norm($jmeno), frez_klic('klic_hmac')); }

/* ---------------------------------------------------------------------
   Rozklad názvu STL a komentáře puku
   nový:  Příjmení_Jméno_DTOxxxxxxxx_XNIF_2026-11-23-37-crown_cad.stl
   starý: Příjmení_Jméno_001_----15-14-bridge_cad.stl
   --------------------------------------------------------------------- */
function frez_stl(string $cesta): array {
  $f = preg_replace('~^.*[\\\\/]~', '', $cesta);
  if (preg_match('/^(?<jm>.+?)_(?<tag>DTO[A-Za-z0-9]{8})_(?<lek>[A-Z0-9]{4})_(?<dat>\d{4}-\d{2}-\d{2})-(?<zuby>[\d-]*?)-?(?<typ>[A-Za-z][A-Za-z_]*)?_cad\.stl$/u', $f, $m))
    return ['tag' => $m['tag'], 'lekar' => $m['lek'], 'datum' => $m['dat'], 'zuby' => trim($m['zuby'], '-'), 'typ' => $m['typ'] ?? '', 'jmeno' => str_replace('_', ' ', $m['jm'])];
  if (preg_match('/^(?<jm>.+?)_\d{3}_-*(?<zuby>[\d-]*?)-?(?<typ>[A-Za-z][A-Za-z_]*)?_cad\.stl$/u', $f, $m))
    return ['tag' => null, 'lekar' => null, 'datum' => null, 'zuby' => trim($m['zuby'], '-'), 'typ' => $m['typ'] ?? '', 'jmeno' => str_replace('_', ' ', $m['jm'])];
  return ['tag' => null, 'lekar' => null, 'datum' => null, 'zuby' => '', 'typ' => '', 'jmeno' => ''];
}
function frez_bem(string $b): array {          // DT-A3-14 → dodavatel, odstín, výška
  return preg_match('/^([A-Z]{1,6})-([A-Z0-9.]{1,6})-(\d{1,2}(?:[.,]\d)?)$/i', trim($b), $m)
    ? [strtoupper($m[1]), strtoupper($m[2]), (float) str_replace(',', '.', $m[3])] : [null, null, null];
}
function frez_cas(string $s): ?string {        // 26.10.2026 23:10:20 | 10/26/26 23:10:20 | ISO
  foreach (['d.m.Y H:i:s', 'd.m.Y G:i:s', 'm/d/y H:i:s', 'Y-m-d H:i:s', 'Y-m-d\TH:i:s'] as $f) {
    $d = DateTime::createFromFormat($f, trim($s));
    $e = DateTime::getLastErrors();
    if ($d && ($e === false || ($e['warning_count'] === 0 && $e['error_count'] === 0))) return $d->format('Y-m-d H:i:s');
  }
  return null;
}

/* ---------------------------------------------------------------------
   Příjem ze sběrače
   calc: [{fap, TOC, MAT, BID, LOT, BEM, STL, TYP, QOW, MiX, MaX, MiY, MaY}]
   cnc:  [{ID, Start, Ende, DauerReal, DauerCalc, Material, Blank, Hoehe, Fehlercode, ToolsLens, Version}]
   --------------------------------------------------------------------- */
function frez_prijem(array $calc, array $cnc): array {
  $pdo = mat_pdo(); $n = ['calc' => 0, 'cnc' => 0, 'chyby' => []];
  $insC = $pdo->prepare('INSERT INTO frez_cnc (cnc_id, start, konec, dauer_s, calc_s, material, blank, vyska, fehlercode, nastroje, verze)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE konec = VALUES(konec), dauer_s = VALUES(dauer_s), fehlercode = VALUES(fehlercode)');
  $dur = fn($v) => preg_match('/(\d{1,2}):(\d{2}):(\d{2})\s*$/', (string) $v, $m) ? $m[1] * 3600 + $m[2] * 60 + $m[3] : null;
  foreach (array_slice($cnc, 0, 2000) as $r) {
    if (!is_array($r) || !isset($r['ID']) || !($st = frez_cas((string) ($r['Start'] ?? '')))) { $n['chyby'][] = 'cnc bez ID/startu'; continue; }
    $insC->execute([(int) $r['ID'], $st, frez_cas((string) ($r['Ende'] ?? '')), $dur($r['DauerReal'] ?? ''), $dur($r['DauerCalc'] ?? ''),
                    mb_substr(mat_txt($r['Material'] ?? ''), 0, 60), mb_substr(mat_txt($r['Blank'] ?? ''), 0, 40),
                    is_numeric($r['Hoehe'] ?? null) ? (float) $r['Hoehe'] : null, is_numeric($r['Fehlercode'] ?? null) ? (int) $r['Fehlercode'] : null,
                    mb_substr(mat_txt($r['ToolsLens'] ?? ''), 0, 255), mb_substr(mat_txt($r['Version'] ?? ''), 0, 40)]);
    $n['cnc']++;
  }
  $insF = $pdo->prepare('INSERT INTO frez_calc (fap_dir, toc, mat, bid, did, bem, dodavatel, odstin, vyska_mm, tag, lekar, datum_exp, zuby, typ_soub, typ_obj, qow, bbox_um, jmeno_enc, jmeno_hash)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE did = VALUES(did), bem = VALUES(bem), dodavatel = VALUES(dodavatel), odstin = VALUES(odstin), vyska_mm = VALUES(vyska_mm)');
  foreach (array_slice($calc, 0, 500) as $r) {
    $fap = preg_replace('/\D/', '', (string) ($r['fap'] ?? ''));
    $toc = frez_cas((string) ($r['TOC'] ?? ''));
    if (strlen($fap) < 1 || strlen($fap) > 7 || !$toc) { $n['chyby'][] = 'calc bez FAP/TOC'; continue; }
    $s = frez_stl((string) ($r['STL'] ?? ''));
    [$dod, $odst, $vys] = frez_bem((string) ($r['BEM'] ?? ''));
    $did = strtoupper(mat_txt($r['LOT'] ?? ''));
    $bbox = implode(',', array_map(fn($k) => is_numeric($r[$k] ?? null) ? (int) $r[$k] : '', ['MiX', 'MaX', 'MiY', 'MaY']));
    $insF->execute([str_pad($fap, 7, '0', STR_PAD_LEFT), $toc, mb_substr(mat_txt($r['MAT'] ?? ''), 0, 60), is_numeric($r['BID'] ?? null) ? (int) $r['BID'] : null,
                    $did === '' ? null : mb_substr($did, 0, 12), mb_substr(mat_txt($r['BEM'] ?? ''), 0, 30), $dod, $odst, $vys,
                    $s['tag'], $s['lekar'], $s['datum'], mb_substr($s['zuby'], 0, 60), mb_substr($s['typ'], 0, 40),
                    mb_substr(mat_txt($r['TYP'] ?? ''), 0, 60), mb_substr(mat_txt($r['QOW'] ?? ''), 0, 20), $bbox,
                    $s['jmeno'] !== '' ? frez_enc($s['jmeno']) : null, $s['jmeno'] !== '' ? frez_hash($s['jmeno']) : null]);
    $n['calc']++;
  }
  $n['sparovano'] = frez_sparuj();
  frez_kontrola();
  return $n;
}

/* výpočet ↔ obrábění: start do FREZ_OKNO_S po TOC, stejný materiál, nejbližší */
function frez_sparuj(): int {
  $pdo = mat_pdo();
  $q = $pdo->query("SELECT c.id, (SELECT n.cnc_id FROM frez_cnc n WHERE n.material = c.mat AND n.start BETWEEN c.toc AND c.toc + INTERVAL " . FREZ_OKNO_S . " SECOND
                                  ORDER BY n.start LIMIT 1) AS cnc
                    FROM frez_calc c WHERE c.cnc_id IS NULL AND c.toc > NOW() - INTERVAL 60 DAY");
  $up = $pdo->prepare("UPDATE frez_calc c JOIN frez_cnc n ON n.cnc_id = ? SET c.cnc_id = n.cnc_id,
                         c.stav = IF(n.fehlercode = 0, 'vyfrezovano', 'preruseno') WHERE c.id = ?");
  $k = 0;
  foreach ($q->fetchAll() as $r) if ($r['cnc']) { $up->execute([(int) $r['cnc'], (int) $r['id']]); $k++; }
  /* obrábění, které se po přerušení dokončilo později, má jiný řádek – stav se bere z posledního */
  return $k;
}

/* kontrola DID: balení existuje, není ukončené, skupina sedí s materiálem, výška s komentářem */
function frez_kontrola(): void {
  $pdo = mat_pdo();
  $q = $pdo->query("SELECT c.id, c.did, c.mat, c.vyska_mm, n.vyska AS vyska_cnc, b.status, m.skupina
                    FROM frez_calc c LEFT JOIN frez_cnc n ON n.cnc_id = c.cnc_id
                    LEFT JOIN s1_baleni b ON b.did = c.did LEFT JOIN mat m ON m.ref = b.ref
                    WHERE c.kontrola IS NULL AND c.toc > NOW() - INTERVAL 60 DAY
                      AND (c.cnc_id IS NOT NULL OR c.toc < NOW() - INTERVAL 1 DAY)");   // výška až po spárování s obráběním
  $up = $pdo->prepare('UPDATE frez_calc SET kontrola = ? WHERE id = ?');
  foreach ($q->fetchAll() as $r) {
    $v = [];
    if (!$r['did']) $v[] = 'chybí DID';
    elseif ($r['status'] === null) $v[] = 'DID ' . $r['did'] . ' není ve skladu';
    else {
      if ($r['status'] === 'SPOTŘEBOVANÉ') $v[] = 'balení je ukončené';
      elseif ($r['status'] === 'ZALOŽENO') $v[] = 'balení není nasazené';
      $ocek = null; foreach (FREZ_SKUPINA as $k => $sk) if (stripos((string) $r['mat'], $k) !== false) { $ocek = $sk; break; }
      if ($ocek && $r['skupina'] && !in_array($r['skupina'], $ocek, true)) $v[] = 'materiál ' . $r['mat'] . ' × karta ' . $r['skupina'];
    }
    if ($r['vyska_mm'] !== null && $r['vyska_cnc'] !== null && abs((float) $r['vyska_mm'] - (float) $r['vyska_cnc']) > 0.5)
      $v[] = 'výška v komentáři ' . (float) $r['vyska_mm'] . ' mm × puk ' . (float) $r['vyska_cnc'] . ' mm';
    $up->execute([$v ? mb_substr(implode('; ', $v), 0, 200) : '', (int) $r['id']]);
  }
}

/* zápisy z frézy ke kartě pro zakázkový list (OUT = CN) – bez jména */
function frez_zakazka(string $tag): array {
  if (!frez_tag_ok($tag)) return [];
  $st = mat_pdo()->prepare("SELECT c.did, c.mat, c.bem, c.zuby, c.typ_obj, c.bbox_um AS bbox, DATE_FORMAT(c.toc, '%Y-%m-%d %H:%i') AS toc,
                                   DATE_FORMAT(n.konec, '%Y-%m-%d %H:%i') AS konec, c.stav, c.kontrola
                            FROM frez_calc c LEFT JOIN frez_cnc n ON n.cnc_id = c.cnc_id WHERE c.tag = ? ORDER BY c.toc");
  $st->execute([$tag]);
  return $st->fetchAll();
}
