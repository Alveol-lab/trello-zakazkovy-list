<?php
/* =========================================================
   Archiv vydaných dokumentů (krok 31)
   - Spouštěčem je vydání prohlášení výrobce (compliancePdf).
     Apps Script vrátí proxy (api.php) kromě výsledku i originální
     PDF a otisk dat sledovatelnosti; proxy je uloží sem a klientovi
     je nepošle.
   - Hned se uloží: originál prohlášení, data (JSON) a soupis šarží
     (PDF/A). Jednou týdně (archiv-tyden.php) se doplní snímek karty
     a fotky žádanky z Trella a týdenní manifest.
   - Nic se nepřepisuje: každé vydání je nová verze, soubory jsou jen
     pro čtení, tabulky chrání triggery, řádky drží řetěz otisků.
   - Soubory leží mimo web v home/archiv/RRRR/MM/.
   - Přepínač home/autolab-archiv.json {"zapis": true}; bez něj se
     nic neukládá a prohlášení funguje jako dřív.
   ========================================================= */
require_once __DIR__ . '/mat-lib.php';              // mat_pdo(), mat_now()

const ARCH_CFG     = __DIR__ . '/../../autolab-archiv.json';
const ARCH_DIR     = __DIR__ . '/../../archiv';
const ARCH_VENDOR  = __DIR__ . '/../../vendor/autoload.php';   // mPDF (GPL) – mimo veřejný repozitář
const ARCH_LOG     = __DIR__ . '/../../autolab-archiv.log';
const ARCH_GAS_URL = 'https://script.google.com/macros/s/AKfycbyoF5oQtNIh8YktGX9lNcwj1OwSKbthQovlYIr-vbi2fJ6CN1heJYJxnB96zTVZDQpz/exec';
const ARCH_MAX_PRILOHA = 30 * 1024 * 1024;           // větší přílohu Apps Script nevrátí
const ARCH_FOTO_HRANA  = 2480;                       // delší hrana fotky v PDF (A4 při 300 dpi)
const ARCH_POKUSY      = 8;
const ARCH_NULA        = '0000000000000000000000000000000000000000000000000000000000000000';

function arch_cfg(): array {
  static $c = null;
  if ($c === null) $c = (is_file(ARCH_CFG) ? json_decode((string) file_get_contents(ARCH_CFG), true) : null) ?: [];
  return $c;
}
function arch_on(): bool { return !empty(arch_cfg()['zapis']); }
function arch_log(string $kdo, string $co, string $vysledek): void {
  @file_put_contents(ARCH_LOG, date('c') . "\t" . $kdo . "\t" . $co . "\t" . $vysledek . "\n", FILE_APPEND | LOCK_EX);
}

/* ---------- řetěz otisků ---------- */
function arch_hash(string $prev, array $pole): string {
  return hash('sha256', $prev . '|' . json_encode($pole, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
function arch_posledni(PDO $db, string $tab): string {     // v transakci, zamyká poslední řádek
  $h = $db->query("SELECT row_hash FROM $tab ORDER BY id DESC LIMIT 1 FOR UPDATE")->fetchColumn();
  return $h ?: ARCH_NULA;
}
function arch_insert(PDO $db, string $tab, array $r): int {
  $r['prev_hash'] = arch_posledni($db, $tab);
  $r['row_hash']  = arch_hash($r['prev_hash'], array_diff_key($r, ['prev_hash' => 1]));
  $k = array_keys($r);
  $db->prepare("INSERT INTO $tab (" . implode(',', $k) . ') VALUES (' . implode(',', array_fill(0, count($k), '?')) . ')')
     ->execute(array_values($r));
  return (int) $db->lastInsertId();
}

/* ---------- soubory ---------- */
function arch_nazev_bezpecny(string $s): string {
  $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: 'soubor';
  $s = preg_replace('/[^A-Za-z0-9._-]+/', '_', $s);
  return substr(trim($s, '._'), 0, 60) ?: 'soubor';
}
/* Uloží bajty do home/archiv/RRRR/MM/, zapíše řádek archiv_soubor. Soubor
   už existující cestu nikdy nepřepíše. */
function arch_soubor(PDO $db, int $pripad, string $uid, int $verze, string $typ, string $bytes, string $mime,
                     string $nazev, ?string $zdroj = null): int {
  $ted = new DateTime('now', new DateTimeZone('Europe/Prague'));
  $ext = ['application/pdf' => 'pdf', 'application/json' => 'json', 'image/jpeg' => 'jpg', 'image/png' => 'png',
          'image/heic' => 'heic', 'image/webp' => 'webp', 'message/rfc822' => 'eml'][$mime] ?? (pathinfo($nazev, PATHINFO_EXTENSION) ?: 'bin');
  $sha = hash('sha256', $bytes);
  $rel = $ted->format('Y/m') . '/' . arch_nazev_bezpecny($uid) . '_v' . $verze . '_' . $typ . '_' . substr($sha, 0, 12) . '.' . strtolower($ext);
  $abs = ARCH_DIR . '/' . $rel;
  if (!is_dir(dirname($abs)) && !mkdir(dirname($abs), 0750, true) && !is_dir(dirname($abs))) throw new RuntimeException('archiv: nelze vytvořit složku');
  if (file_exists($abs)) {                           // stejný obsah téhož případu už leží – nový řádek nezakládat
    $x = $db->prepare('SELECT id FROM archiv_soubor WHERE cesta = ?'); $x->execute([$rel]);
    if ($id = $x->fetchColumn()) return (int) $id;
    throw new RuntimeException('archiv: soubor existuje bez záznamu ' . $rel);
  }
  $tmp = $abs . '.tmp' . getmypid();
  if (file_put_contents($tmp, $bytes, LOCK_EX) !== strlen($bytes) || !rename($tmp, $abs)) { @unlink($tmp); throw new RuntimeException('archiv: zápis souboru selhal'); }
  @chmod($abs, 0440);
  return arch_insert($db, 'archiv_soubor', [
    'pripad_id' => $pripad, 'typ' => $typ, 'nazev' => mb_substr($nazev, 0, 255), 'cesta' => $rel, 'mime' => $mime,
    'velikost' => strlen($bytes), 'sha256' => $sha, 'zdroj_id' => $zdroj, 'vytvoreno' => mat_now(),
  ]);
}

/* ---------- vydání prohlášení (volá api.php po compliancePdf) ---------- */
/* $a = { pdf: base64, tag, cardId, vydano?: ISO, trace?: {...} | null, traceError? } */
function arch_vydani(array $a, string $actor, ?string $technik = null): array {
  $uid = trim((string) ($a['tag'] ?? ''));
  $card = preg_match('/^[0-9a-f]{24}$/', (string) ($a['cardId'] ?? '')) ? (string) $a['cardId'] : null;
  $pdf = base64_decode((string) ($a['pdf'] ?? ''), true);
  if ($uid === '' || mb_strlen($uid) > 40) throw new InvalidArgumentException('archiv: chybí kód zakázky');
  if ($pdf === false || strncmp($pdf, '%PDF', 4) !== 0) throw new InvalidArgumentException('archiv: chybí PDF prohlášení');
  $trace = is_array($a['trace'] ?? null) && !empty($a['trace']['ok']) ? $a['trace'] : null;
  if ($trace && function_exists('frez_cdid_trace')) $trace = frez_cdid_trace($trace);   // puky z frézy (CDID), mimo transakci
  $vydano = mat_now();
  if (!empty($a['vydano'])) try {                    // ISO z Apps Scriptu (UTC) → čas v Praze
    $vydano = (new DateTime((string) $a['vydano']))->setTimezone(new DateTimeZone('Europe/Prague'))->format('Y-m-d H:i:s');
  } catch (Throwable $e) {}

  $db = mat_pdo();
  $db->beginTransaction();
  try {
    $v = $db->prepare('SELECT COALESCE(MAX(verze), 0) + 1 FROM archiv_pripad WHERE uid = ? FOR UPDATE'); $v->execute([$uid]);
    $verze = (int) $v->fetchColumn();
    $pripad = arch_insert($db, 'archiv_pripad', [
      'uid' => $uid, 'verze' => $verze, 'card_id' => $card, 'puvod' => 'vydání', 'stav' => 'platný', 'vydano' => $vydano,
      'vydal' => $actor ?: null, 'technik' => $technik, 'uplnost' => arch_uplnost($trace), 'pozn' => null, 'vytvoreno' => mat_now(),
    ]);
    $soubory = [];
    $soubory[] = arch_soubor($db, $pripad, $uid, $verze, 'prohlaseni', $pdf, 'application/pdf', 'prohlášení výrobce ' . $uid . '.pdf');
    $data = ['uid' => $uid, 'cardId' => $card, 'vydano' => $vydano, 'vydal' => $actor, 'prohlaseni_sha256' => hash('sha256', $pdf),
             'trace' => $trace, 'traceError' => $trace ? null : (string) ($a['traceError'] ?? ($a['trace']['error'] ?? 'chybí'))];
    $soubory[] = arch_soubor($db, $pripad, $uid, $verze, 'data_vydani', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), 'application/json', 'data vydání ' . $uid . '.json');
    if ($trace && is_file(ARCH_VENDOR)) {
      $soubory[] = arch_soubor($db, $pripad, $uid, $verze, 'soupis', arch_soupis_pdf($trace, $uid, $verze, $vydano), 'application/pdf', 'soupis šarží ' . $uid . '.pdf');
    }
    $db->prepare('INSERT INTO archiv_fronta (pripad_id, krok, pokusy, dalsi_pokus, chyba, upraveno) VALUES (?, ?, 0, NULL, NULL, ?)')
       ->execute([$pripad, $card ? 'karta' : 'vzdáno', mat_now()]);
    $db->commit();
    arch_log($actor, 'vydání ' . $uid . ' v' . $verze, 'ok ' . count($soubory) . ' souborů');
    return ['ok' => true, 'pripad' => $pripad, 'verze' => $verze];
  } catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
  }
}

/* příznaky neúplnosti evidence (případ se archivuje i neúplný) */
function arch_uplnost(?array $t): ?string {
  if (!$t) return 'bez_dat_sledovatelnosti';
  $f = [];
  $V = $t['vykony'] ?? [];
  if (!$V) $f[] = 'bez_vykonu';
  foreach ($V as $v) {
    if (trim((string) ($v['datum'] ?? '')) === '') $f['bez_data'] = 'bez_data';
    $M = $v['mat'] ?? [];
    if (!$M) $f['vykon_bez_materialu'] = 'vykon_bez_materialu';
    foreach ($M as $m) if (empty($m['did']) || empty($t['baleni'][$m['did']])) $f['sarze_bez_did'] = 'sarze_bez_did';
  }
  foreach ($t['baleni'] ?? [] as $b) if (trim((string) ($b['lot'] ?? '')) === '') $f['baleni_bez_lot'] = 'baleni_bez_lot';
  return $f ? implode(',', array_values($f)) : null;
}

/* ---------- PDF ---------- */
function arch_mpdf(string $format, string $titulek, string $uid): \Mpdf\Mpdf {
  require_once ARCH_VENDOR;
  $tmp = __DIR__ . '/../../tmp/mpdf-archiv';          // mimo web; /tmp může být za open_basedir nedostupné
  if (!is_dir($tmp)) @mkdir($tmp, 0750, true);
  $p = new \Mpdf\Mpdf(['format' => $format, 'margin_left' => 12, 'margin_right' => 12, 'margin_top' => 12, 'margin_bottom' => 14,
    'PDFA' => true, 'PDFAauto' => true, 'default_font' => 'dejavusans', 'tempDir' => $tmp]);
  $p->SetTitle($titulek); $p->SetAuthor('Denteo v.o.s. – AutoLAB'); $p->SetCreator('AutoLAB archiv');
  $p->SetFooter('AutoLAB archiv · ' . arch_e($uid) . '||{PAGENO} / {nbpg}');
  $p->WriteHTML(ARCH_CSS, \Mpdf\HTMLParserMode::HEADER_CSS);
  return $p;
}
const ARCH_CSS = 'body{font-family:dejavusans;font-size:9pt;color:#000}h1{font-size:14pt;margin:0 0 2mm}.sub{color:#444;font-size:8.5pt;margin-bottom:3mm}'
 . 'table{border-collapse:collapse;width:100%;margin-bottom:3mm}th,td{border:0.3mm solid #999;padding:1mm 1.5mm;text-align:left;vertical-align:top;font-size:8pt}'
 . 'th{background:#eee}table.hd td{border:0}.sec{font-weight:bold;margin:2mm 0 1mm;text-transform:uppercase;font-size:8pt}.md{border:0.3mm solid #000;font-size:7pt;font-weight:bold}'
 . '.lowc{color:#8a4b00}.ro{color:#666}.n{text-align:right}.foot{margin-top:3mm;font-size:7.5pt;color:#444}.pre{white-space:pre-wrap}';
/* emoji (🦷, 🚩…) písmo DejaVu nemá a v PDF/A by zůstal prázdný čtvereček */
function arch_e($s): string {
  $s = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE00}-\x{FE0F}\x{200D}]/u', '', (string) $s) ?? (string) $s;
  return htmlspecialchars(trim($s), ENT_QUOTES, 'UTF-8');
}

function arch_soupis_pdf(array $d, string $uid, int $verze, string $vydano): string {
  $ZP = ['auto' => 'AutoDID', 'ruční' => 'ruční', 'MD' => 'MD', 'lotlog' => 'odpis LOTLOG'];
  $B = $d['baleni'] ?? []; $V = $d['vykony'] ?? []; $v0 = $V[0] ?? [];
  $nahr = []; foreach (($d['cdid'] ?? []) as $c) foreach (($c['nahrazuje'] ?? []) as $x) $nahr[$x] = $c['did'];
  $tit = 'Soupis materiálů a šarží zakázky ' . $uid;
  $h = '<h1>' . arch_e($tit) . '</h1><div class="sub">Denteo v.o.s. · AutoLAB archiv · vydáno ' . arch_e(date('j. n. Y H:i', strtotime($vydano)))
     . ' · verze ' . $verze . ' · příloha k prohlášení výrobce (nenahrazuje ho)</div>';
  $h .= '<table class="hd"><tr><td>Zakázka <b>' . arch_e($uid) . '</b></td><td>Ordinace <b>' . arch_e($v0['ord'] ?? '') . '</b></td><td>Pacient <b>' . arch_e($v0['pacient'] ?? '') . '</b></td></tr></table>';
  $h .= '<div class="sec">Výkony a použitý materiál</div><table><thead><tr><th>Datum</th><th>Výkon</th><th>Popis</th><th>Q</th><th>Materiál</th><th>REF</th><th>Výrobce</th><th>Šarže (LOT)</th><th>DID</th><th>Exp.</th><th>Způsob</th></tr></thead><tbody>';
  if (!$V) $h .= '<tr><td colspan="11" class="ro">v EVIDENCE nejsou k zakázce zapsané výkony</td></tr>';
  foreach ($V as $v) {
    $M = ($v['mat'] ?? []) ?: [null];
    foreach ($M as $i => $m) {
      $h .= '<tr>' . ($i ? '<td></td><td></td><td></td><td></td>' : '<td>' . arch_e($v['datum'] ?? '') . '</td><td>' . arch_e($v['kod'] ?? '') . '</td><td>' . arch_e($v['popis'] ?? '') . '</td><td class="n">' . arch_e($v['q'] ?? '') . '</td>');
      $b = $m && !empty($m['did']) ? ($B[$m['did']] ?? null) : null;
      $zp = $m ? ($ZP[$m['zdroj'] ?? ''] ?? ($m['zdroj'] ?? '')) : '';
      if ($m && !empty($m['did']) && isset($nahr[$m['did']])) $zp .= ' · fréza použila ' . $nahr[$m['did']];
      if (!$m) $h .= '<td colspan="7" class="ro">bez materiálu se šarží</td>';
      elseif ($b) $h .= '<td>' . arch_e($b['popis'] ?? '') . (($m['zdroj'] ?? '') === 'MD' ? ' <span class="md">MD</span>' : '') . '</td><td>' . arch_e($b['ref'] ?? '') . '</td><td>' . arch_e($b['vyrobce'] ?? '') . '</td><td>' . arch_e($b['lot'] ?? '') . '</td><td>' . arch_e($b['did'] ?? '') . '</td><td>' . arch_e($b['exp'] ?? '') . '</td><td>' . arch_e($zp) . '</td>';
      else $h .= '<td colspan="3" class="lowc">šarže bez DID (text ze zápisu)</td><td>' . arch_e($m['text'] ?? $m['did'] ?? '') . '</td><td></td><td></td><td>' . arch_e($zp) . '</td>';
      $h .= '</tr>';
    }
  }
  $h .= '</tbody></table>';
  $h .= arch_soupis_cdid($d);
  $snap = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $h .= '<div class="foot">Otisk dat SHA-256: ' . hash('sha256', $snap) . ' · data ze zápisů EVIDENCE v okamžiku vydání prohlášení</div>';
  $p = arch_mpdf('A4-L', $tit, $uid);
  $p->WriteHTML($h, \Mpdf\HTMLParserMode::HTML_BODY);
  return $p->Output('', 'S');
}

/* puky z frézy (CDID) – co skutečně prošlo frézou; přednost před ručním zápisem stejné skupiny */
function arch_soupis_cdid(array $d): string {
  $C = $d['cdid'] ?? []; if (!$C) return '';
  $B = $d['baleni'] ?? [];
  $h = '<div class="sec">Puky z frézy (CDID)</div><table><thead><tr><th>Materiál</th><th>REF</th><th>Výrobce</th><th>Šarže (LOT)</th><th>DID</th><th>Exp.</th><th>Zuby</th><th>Frézováno</th><th>Poznámka</th></tr></thead><tbody>';
  foreach ($C as $c) {
    $kdy = arch_e($c['prvni'] ?? '') . (($c['posledni'] ?? '') !== ($c['prvni'] ?? '') ? ' – ' . arch_e($c['posledni']) : '');
    if (($c['stav'] ?? '') === 'nepřiřazeno') {
      $h .= '<tr><td colspan="4" class="lowc">fréza: puk ' . arch_e($c['did_frez'] ?? '') . ' nejde přiřadit k balení</td><td></td><td></td><td>' . arch_e($c['zuby'] ?? '') . '</td><td>' . $kdy . '</td><td class="lowc">' . arch_e($c['duvod'] ?? '') . '</td></tr>';
      continue;
    }
    $b = $B[$c['did']] ?? [];
    $po = $c['nahrazuje'] ? 'místo ručně zapsaného ' . implode(', ', $c['nahrazuje']) : '';
    $h .= '<tr><td>' . arch_e($b['nazev'] ?? $b['popis'] ?? '') . '</td><td>' . arch_e($b['ref'] ?? $c['ref'] ?? '') . '</td><td>' . arch_e($b['vyrobce'] ?? '') . '</td><td>' . arch_e($b['lot'] ?? '') . '</td><td>' . arch_e($c['did']) . '</td><td>' . arch_e($b['exp'] ?? '') . '</td><td>' . arch_e($c['zuby'] ?? '') . '</td><td>' . $kdy . '</td><td>' . arch_e($po) . '</td></tr>';
  }
  return $h . '</tbody></table>';
}

/* fotka žádanky → JPEG pro PDF: orientace podle EXIF, delší hrana 2480 px, q85 */
function arch_foto_pro_pdf(string $bytes): ?string {
  if (!class_exists('Imagick')) return null;
  try {
    $im = new Imagick(); $im->readImageBlob($bytes);
    if ($im->getNumberImages() > 1) { $im->setIteratorIndex(0); $im = $im->getImage(); }
    $im->autoOrient();
    $w = $im->getImageWidth(); $h = $im->getImageHeight();
    if (max($w, $h) > ARCH_FOTO_HRANA) $im->resizeImage($w >= $h ? ARCH_FOTO_HRANA : 0, $h > $w ? ARCH_FOTO_HRANA : 0, Imagick::FILTER_LANCZOS, 1);
    $im->setImageColorspace(Imagick::COLORSPACE_SRGB);
    $im->setImageFormat('jpeg'); $im->setImageCompressionQuality(85); $im->stripImage();
    return $im->getImageBlob();
  } catch (Throwable $e) { return null; }
}

/* snímek karty: stránka z dat karty + každá fotka na samostatné stránce A4 */
function arch_karta_pdf(array $k, array $fotky, string $uid, int $verze): string {
  $tit = 'Snímek karty Trello – zakázka ' . $uid;
  $p = arch_mpdf('A4', $tit, $uid);
  $d = fn($s) => $s ? date('j. n. Y H:i', strtotime((string) $s)) : '';
  $h = '<h1>' . arch_e($tit) . '</h1><div class="sub">Denteo v.o.s. · AutoLAB archiv · pořízeno ' . date('j. n. Y H:i') . ' · k verzi ' . $verze
     . ' · obsah karty v okamžiku pořízení, ne podoba obrazovky</div>';
  $h .= '<table><tr><th style="width:28%">Název karty</th><td>' . arch_e($k['name'] ?? '') . '</td></tr>'
      . '<tr><th>Seznam</th><td>' . arch_e($k['list'] ?? '') . ($k['closed'] ?? false ? ' (archivovaná)' : '') . '</td></tr>'
      . '<tr><th>Štítky</th><td>' . arch_e(implode(', ', array_map(fn($l) => trim(($l['name'] ?? '') . ' ' . (($l['color'] ?? '') ? '(' . $l['color'] . ')' : '')), $k['labels'] ?? []))) . '</td></tr>'
      . '<tr><th>Termín</th><td>' . arch_e($d($k['due'] ?? null)) . (($k['dueComplete'] ?? false) ? ' · splněno' : '') . '</td></tr>'
      . '<tr><th>Členové</th><td>' . arch_e(implode(', ', $k['members'] ?? [])) . '</td></tr>'
      . '<tr><th>Poslední aktivita</th><td>' . arch_e($d($k['dateLastActivity'] ?? null)) . '</td></tr>'
      . '<tr><th>Odkaz</th><td>' . arch_e($k['shortUrl'] ?? '') . '</td></tr></table>';
  if (trim((string) ($k['desc'] ?? '')) !== '') $h .= '<div class="sec">Popis</div><div class="pre">' . nl2br(arch_e($k['desc'])) . '</div>';
  foreach ($k['checklists'] ?? [] as $c) {
    $h .= '<div class="sec">Checklist: ' . arch_e($c['name'] ?? '') . '</div><table>';
    foreach ($c['items'] ?? [] as $i) $h .= '<tr><td style="width:6mm">' . (($i['state'] ?? '') === 'complete' ? '✔' : '☐') . '</td><td>' . arch_e($i['name'] ?? '') . '</td></tr>';
    $h .= '</table>';
  }
  if (!empty($k['custom'])) {
    $h .= '<div class="sec">Vlastní pole</div><table>';
    foreach ($k['custom'] as $n => $v) $h .= '<tr><th style="width:28%">' . arch_e($n) . '</th><td>' . arch_e($v) . '</td></tr>';
    $h .= '</table>';
  }
  if (!empty($k['comments'])) {
    $h .= '<div class="sec">Komentáře (od nejstaršího)</div><table>';
    foreach (array_reverse($k['comments']) as $c) $h .= '<tr><td style="width:30%">' . arch_e($d($c['date'] ?? null)) . '<br>' . arch_e($c['member'] ?? '') . '</td><td class="pre">' . nl2br(arch_e($c['text'] ?? '')) . '</td></tr>';
    $h .= '</table>';
  }
  if (!empty($k['attachments'])) {
    $h .= '<div class="sec">Přílohy karty</div><table><tr><th>Název</th><th>Typ</th><th>Velikost</th><th>Přidáno</th><th>V archivu</th></tr>';
    foreach ($k['attachments'] as $a) $h .= '<tr><td>' . arch_e($a['name'] ?? '') . '</td><td>' . arch_e($a['mimeType'] ?? ($a['isUpload'] ? '' : 'odkaz')) . '</td><td class="n">'
      . ($a['bytes'] ? number_format($a['bytes'] / 1024, 0, ',', ' ') . ' kB' : '') . '</td><td>' . arch_e($d($a['date'] ?? null)) . '</td><td>' . arch_e($a['archiv'] ?? '') . '</td></tr>';
    $h .= '</table>';
  }
  $p->WriteHTML($h, \Mpdf\HTMLParserMode::HTML_BODY);
  foreach ($fotky as $f) {                           // celá strana A4, poměr stran zachovaný
    $p->AddPage();
    $tmp = tempnam(sys_get_temp_dir(), 'arf') . '.jpg';
    file_put_contents($tmp, $f['jpeg']);
    $p->WriteHTML('<div class="sub">Příloha karty: ' . arch_e($f['nazev']) . ' · originál v archivu SHA-256 ' . substr($f['sha256'], 0, 16) . '…</div>'
      . '<div style="text-align:center"><img src="' . $tmp . '" style="max-width:186mm;max-height:255mm"></div>', \Mpdf\HTMLParserMode::HTML_BODY);
    @unlink($tmp);
  }
  return $p->Output('', 'S');
}

/* ---------- Apps Script (karta a přílohy z Trella; token Trella zůstává v Apps Scriptu) ---------- */
function arch_gas(array $body, int $timeout = 120): array {
  $s = function_exists('prijem_secret') ? prijem_secret() : null;
  if (empty($s['key'])) return ['ok' => false, 'error' => 'server: chybí klíč pro Apps Script'];
  $body['key'] = $s['key'];
  $c = curl_init(getenv('ARCH_GAS_URL') ?: ARCH_GAS_URL);
  curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_POSTREDIR => 0,
    CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => $timeout, CURLOPT_HTTPHEADER => ['Content-Type: text/plain;charset=utf-8']]);
  $r = curl_exec($c); $code = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
  $j = is_string($r) ? json_decode($r, true) : null;
  if ($code !== 200 || !is_array($j)) return ['ok' => false, 'error' => 'Apps Script neodpověděl (HTTP ' . $code . ')'];
  return $j;
}

/* Doplní k případu snímek karty, originály fotek a PDF příloh. */
function arch_karta(int $pripad): array {
  $db = mat_pdo();
  $p = $db->prepare('SELECT * FROM archiv_pripad WHERE id = ?'); $p->execute([$pripad]); $p = $p->fetch();
  if (!$p || !$p['card_id']) return ['ok' => false, 'error' => 'případ bez karty'];
  $k = arch_gas(['action' => 'archivKarta', 'cardId' => $p['card_id']]);
  if (empty($k['ok'])) return ['ok' => false, 'error' => 'karta: ' . ($k['error'] ?? '?')];
  $karta = $k['karta'];
  /* přílohy už uložené u kteréhokoli případu téže karty se znovu nestahují – jen se na ně odkáže */
  $mam = [];
  $q = $db->prepare('SELECT s.zdroj_id, s.sha256, s.cesta FROM archiv_soubor s JOIN archiv_pripad p ON p.id = s.pripad_id WHERE p.card_id = ? AND s.zdroj_id IS NOT NULL');
  $q->execute([$p['card_id']]);
  foreach ($q as $r) $mam[$r['zdroj_id']] = $r;
  $nove = []; $fotky = [];
  foreach ($karta['attachments'] ?? [] as $i => $a) {
    $mime = (string) ($a['mimeType'] ?? '');
    $jeFoto = str_starts_with($mime, 'image/');
    $jePdf = $mime === 'application/pdf' || $mime === 'message/rfc822';   // PDF a e-mail s objednávkou se ukládají jako originál
    if (empty($a['isUpload']) || (!$jeFoto && !$jePdf)) { $karta['attachments'][$i]['archiv'] = 'ne (odkaz nebo jiný typ)'; continue; }
    if ((int) ($a['bytes'] ?? 0) > ARCH_MAX_PRILOHA) { $karta['attachments'][$i]['archiv'] = 'ne (přes 30 MB)'; continue; }
    if (isset($mam[$a['id']])) { $bytes = null; $sha = $mam[$a['id']]['sha256']; $karta['attachments'][$i]['archiv'] = 'ano (dříve)';
      if ($jeFoto) { $b = @file_get_contents(ARCH_DIR . '/' . $mam[$a['id']]['cesta']); if ($b !== false) $bytes = $b; } }
    else {
      $r = arch_gas(['action' => 'archivPriloha', 'cardId' => $p['card_id'], 'attId' => $a['id']], 180);
      $bytes = !empty($r['ok']) ? base64_decode((string) $r['data'], true) : false;
      if ($bytes === false || $bytes === '') { $karta['attachments'][$i]['archiv'] = 'chyba: ' . ($r['error'] ?? 'prázdná'); continue; }
      $sha = hash('sha256', $bytes);
      $nove[] = ['typ' => $jeFoto ? 'zadanka' : 'priloha', 'bytes' => $bytes, 'mime' => $mime, 'nazev' => (string) $a['name'], 'zdroj' => (string) $a['id']];
      $karta['attachments'][$i]['archiv'] = 'ano';
    }
    if ($jeFoto && $bytes) { $j = arch_foto_pro_pdf($bytes); if ($j) $fotky[] = ['jpeg' => $j, 'nazev' => (string) $a['name'], 'sha256' => $sha]; }
  }
  $pdf = arch_karta_pdf($karta, $fotky, $p['uid'], (int) $p['verze']);
  $db->beginTransaction();
  try {
    foreach ($nove as $n) arch_soubor($db, $pripad, $p['uid'], (int) $p['verze'], $n['typ'], $n['bytes'], $n['mime'], $n['nazev'], $n['zdroj']);
    arch_soubor($db, $pripad, $p['uid'], (int) $p['verze'], 'data_karta', json_encode($karta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), 'application/json', 'data karty ' . $p['uid'] . '.json');
    arch_soubor($db, $pripad, $p['uid'], (int) $p['verze'], 'karta', $pdf, 'application/pdf', 'snímek karty ' . $p['uid'] . '.pdf');
    $db->prepare("UPDATE archiv_fronta SET krok = 'hotovo', chyba = NULL, upraveno = ? WHERE pripad_id = ?")->execute([mat_now(), $pripad]);
    $db->commit();
  } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
  return ['ok' => true, 'nove' => count($nove), 'fotky' => count($fotky)];
}

/* Týdenní manifest: seznam nových souborů od posledního manifestu s jejich SHA-256. */
function arch_manifest(): ?array {
  $db = mat_pdo();
  $ted = new DateTime('now', new DateTimeZone('Europe/Prague'));
  $tyden = $ted->format('o-\WW');
  $x = $db->prepare('SELECT 1 FROM archiv_manifest WHERE tyden = ?'); $x->execute([$tyden]);
  if ($x->fetchColumn()) return null;
  $od = (int) $db->query('SELECT COALESCE(MAX(do_soubor), 0) FROM archiv_manifest')->fetchColumn();
  $S = $db->prepare('SELECT s.id, p.uid, p.verze, s.typ, s.cesta, s.velikost, s.sha256, s.row_hash FROM archiv_soubor s JOIN archiv_pripad p ON p.id = s.pripad_id WHERE s.id > ? ORDER BY s.id');
  $S->execute([$od]); $S = $S->fetchAll();
  if (!$S) return null;
  $db->beginTransaction();
  try {
    $prev = arch_posledni($db, 'archiv_manifest');
    $json = json_encode(['tyden' => $tyden, 'vytvoreno' => $ted->format('c'), 'predchozi_manifest' => $prev, 'soubory' => $S],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $rel = 'manifest/' . $tyden . '.json';
    $abs = ARCH_DIR . '/' . $rel;
    if (!is_dir(dirname($abs))) mkdir(dirname($abs), 0750, true);
    if (file_exists($abs)) throw new RuntimeException('archiv: manifest ' . $tyden . ' už existuje');
    file_put_contents($abs, $json, LOCK_EX); @chmod($abs, 0440);
    arch_insert($db, 'archiv_manifest', ['tyden' => $tyden, 'od_soubor' => (int) $S[0]['id'], 'do_soubor' => (int) end($S)['id'],
      'pocet' => count($S), 'cesta' => $rel, 'sha256' => hash('sha256', $json), 'vytvoreno' => mat_now()]);
    $db->commit();
  } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
  return ['tyden' => $tyden, 'pocet' => count($S)];
}

/* Kontrola celistvosti: řetěz otisků v tabulkách a SHA-256 souborů na disku. */
function arch_kontrola(): array {
  $db = mat_pdo(); $chyby = [];
  foreach (['archiv_pripad', 'archiv_soubor', 'archiv_manifest'] as $t) {
    $prev = ARCH_NULA;
    foreach ($db->query("SELECT * FROM $t ORDER BY id") as $r) {
      $id = $r['id']; $rh = $r['row_hash']; $ph = $r['prev_hash'];
      unset($r['id'], $r['row_hash'], $r['prev_hash']);
      if ($ph !== $prev || arch_hash($ph, arch_typy($t, $r)) !== $rh) $chyby[] = "$t #$id: porušený řetěz";
      $prev = $rh;
    }
  }
  foreach ($db->query('SELECT id, cesta, sha256 FROM archiv_soubor ORDER BY id') as $r) {
    $f = ARCH_DIR . '/' . $r['cesta'];
    if (!is_file($f)) $chyby[] = 'soubor #' . $r['id'] . ': chybí na disku';
    elseif (hash_file('sha256', $f) !== $r['sha256']) $chyby[] = 'soubor #' . $r['id'] . ': změněný obsah';
  }
  return $chyby;
}
/* hodnoty z DB → stejné typy jako při zápisu (int sloupce) */
function arch_typy(string $t, array $r): array {
  $int = ['archiv_pripad' => ['verze'], 'archiv_soubor' => ['pripad_id', 'velikost'], 'archiv_manifest' => ['od_soubor', 'do_soubor', 'pocet']][$t];
  foreach ($int as $k) if ($r[$k] !== null) $r[$k] = (int) $r[$k];
  return $r;
}
