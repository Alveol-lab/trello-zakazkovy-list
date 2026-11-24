<?php
/* =========================================================
   Tiskové sestavy AutoLAB (krok 32)
   - Šablony podle listů EVIDENCE: F-Stitek II. (prohlášení výrobce),
     F-NAB (kalkulace), E-MZD (odměny technika), E-PVF (příloha faktury).
   - Data zatím dodává Apps Script (akce „sestava“) ze stejných listů
     jako dnešní vzorce. Po migraci výkonů do SQL se změní jen zdroj
     dat (sest_data), šablony zůstávají.
   - Každá šablona má verzi; archiv si ji ukládá k dokladu.
   - PDF vytváří mPDF (home/vendor, mimo veřejný repozitář).
   ========================================================= */
require_once __DIR__ . '/archiv-lib.php';          // ARCH_VENDOR, arch_gas(), arch_e()

const SEST_CFG   = __DIR__ . '/../../autolab-sestavy.json';      // {"prohlaseni": "sheets" | "srovnani" | "server"}
const SEST_FONTS = __DIR__ . '/../../vendor/fonts-sestavy';      // Liberation Sans / Mono (SIL OFL)
const SEST_IMG   = __DIR__ . '/sestavy-img';
const SEST_SROVNANI = __DIR__ . '/../../sestavy-srovnani';       // PDF ze šablony vedle PDF ze Sheets (jen režim srovnani)
const SEST_LOG   = __DIR__ . '/../../autolab-sestavy.log';
const SEST_VERZE = ['prohlaseni' => 'prohlaseni-1', 'nabidka' => 'nabidka-1', 'mzd' => 'mzd-1', 'pvf' => 'pvf-1'];
const SEST_FIRMA = 'Denteo v.o.s. | Koželská 209 / U Prádelny 851, 584 01 Ledeč nad Sázavou | IČ:06149928 | +420 721 066 100';

function sest_cfg(): array { static $c = null; if ($c === null) { $c = is_file(SEST_CFG) ? (json_decode((string) file_get_contents(SEST_CFG), true) ?: []) : []; } return $c; }
function sest_rezim(): string { $r = (string) (sest_cfg()['prohlaseni'] ?? 'sheets'); return in_array($r, ['sheets', 'srovnani', 'server'], true) ? $r : 'sheets'; }
function sest_log(string $kdo, string $co, string $vysledek): void {
  if (is_file(SEST_LOG) && filesize(SEST_LOG) > 1048576) @rename(SEST_LOG, SEST_LOG . '.1');
  @file_put_contents(SEST_LOG, date('c') . "\t" . $kdo . "\t" . $co . "\t" . $vysledek . "\n", FILE_APPEND | LOCK_EX);
}

/* data sestavy – zatím z Apps Scriptu (listy EXP, STP, LOT) */
function sest_data(string $typ, array $par): array {
  $r = arch_gas(['action' => 'sestava', 'typ' => $typ] + $par, 90);
  if (empty($r['ok']) || !is_array($r['data'] ?? null)) return ['ok' => false, 'error' => (string) ($r['error'] ?? 'Apps Script nevrátil data')];
  return ['ok' => true, 'data' => $r['data']];
}

function sest_mpdf(string $titulek, string $typ, string $id, bool $pdfa): \Mpdf\Mpdf {
  require_once ARCH_VENDOR;
  $tmp = __DIR__ . '/../../tmp/mpdf-sestavy';
  if (!is_dir($tmp)) @mkdir($tmp, 0750, true);
  $dc = (new \Mpdf\Config\ConfigVariables())->getDefaults();
  $fc = (new \Mpdf\Config\FontVariables())->getDefaults();
  $p = new \Mpdf\Mpdf([
    'format' => 'A4', 'margin_left' => 6, 'margin_right' => 6, 'margin_top' => 6, 'margin_bottom' => 10, 'margin_footer' => 4,
    'tempDir' => $tmp, 'PDFA' => $pdfa, 'PDFAauto' => $pdfa, 'shrink_tables_to_fit' => 1,
    'fontDir' => array_merge($dc['fontDir'], [SEST_FONTS]),
    'fontdata' => $fc['fontdata'] + [
      'lsans' => ['R' => 'LiberationSans-Regular.ttf', 'B' => 'LiberationSans-Bold.ttf'],
      'lmono' => ['R' => 'LiberationMono-Regular.ttf', 'B' => 'LiberationMono-Bold.ttf'],
    ],
    'default_font' => 'lsans',
  ]);
  $p->SetTitle($titulek); $p->SetAuthor('Denteo v.o.s.'); $p->SetCreator('AutoLAB · ' . SEST_VERZE[$typ]);
  $p->SetHTMLFooter('<table class="ft"><tr><td>' . arch_e($id) . ' · ' . SEST_VERZE[$typ] . ' · vytištěno ' . date('j. n. Y H:i') . '</td><td class="r">{PAGENO} / {nbpg}</td></tr></table>');
  $p->WriteHTML(SEST_CSS, \Mpdf\HTMLParserMode::HEADER_CSS);
  return $p;
}
const SEST_CSS = 'body{font-family:lsans;font-size:9pt;color:#000}'
  . 'table{border-collapse:collapse}td,th{vertical-align:top;padding:0}'
  . '.ft td{font-size:6pt;color:#777}.ft{width:100%}.r{text-align:right}.c{text-align:center}.b{font-weight:bold}'
  . '.box{border:0.5mm solid #000;padding:0.6mm 2mm;font-weight:bold}.lbl{font-size:8pt}'
  . '.t1{font-size:15pt;font-weight:bold}.t2{font-size:15pt}.sub{font-size:7.5pt;font-weight:bold}'
  . '.big{font-size:19pt;font-weight:bold}.huge{font-size:27pt;font-weight:bold}.mid{font-size:15pt;font-weight:bold}'
  . '.sm{font-size:7pt;line-height:1.25}.xs{font-size:6pt}'
  . '.sec{font-size:12pt;font-weight:bold;border-top:0.8mm solid #000;padding-top:2mm}'
  . '.gh td{background:#d9d9d9;font-size:7.5pt;font-weight:bold;padding:1mm 1mm}'
  . '.it td{font-size:7.5pt;padding:0.8mm 1mm;border-bottom:0.1mm solid #e3e3e3}'
  . '.mono,.mono td{font-family:lmono}'
  . '.sm p{margin:0 0 1.8mm}.inf p{margin:0 0 2.2mm}.colL{float:left;width:111mm}.colR{float:right;width:80mm;border-left:0.3mm dotted #000;padding-left:3mm;min-height:150mm}';

function sest_img(string $f, string $styl): string { return '<img src="' . SEST_IMG . '/' . $f . '" style="' . $styl . '">'; }
/* rámeček kolem hodnoty – mPDF neumí rámeček u řádkového prvku spolehlivě, proto buňka */
function sest_box(string $t, string $styl = '', string $barva = '#000'): string {
  return '<table><tr><td style="border:0.6mm solid ' . $barva . ';padding:1mm 3mm;font-weight:bold;' . $styl . '">' . arch_e($t) . '</td></tr></table>';
}
function sest_kc($x, int $des = 0): string { return number_format((float) $x, $des, ',', "\u{00A0}") . "\u{00A0}Kč"; }
/* datum z listu LOT (2028-11-13) → 13.11.2028, jako v prohlášení dnes */
function sest_dat($s): string { $s = trim((string) $s); return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) ? (int) $m[3] . '.' . (int) $m[2] . '.' . $m[1] : $s; }

/* ---------- F-Stitek II. – prohlášení výrobce (příloha XIII MDR) ---------- */
const SEST_PRO_INFO = '<p>Výrobek byl zhotoven na zakázku z materiálů určených pro použití v zubním lékařství. Níže uvedený přehled uvádí látky, které mohou být u citlivých osob potenciálně dráždivé nebo alergenní. Pokud přehled žádnou látku neuvádí, výrobek podle dostupných údajů od výrobců materiálů neobsahuje látky, které by bylo nutné zvlášť označit. Ani tak nelze individuální přecitlivělost zcela vyloučit. Při zdravotních potížích kontaktujte bez zbytečného odkladu svého ošetřujícího zubního lékaře. Týká se to především projevů trvajících déle než 48 h, pokud jsou mírnější. U výrazných projevů vyhledejte lékařskou pomoc okamžitě!</p>'
  . '<p class="b">- pálení, zarudnutí nebo otok sliznice,</p><p class="b">- vyrážky, svědění nebo změny chuti,</p><p class="b">- bolesti, bolestivá, světle ohraničená tkáň, změna barvy tkáně</p><p class="b">- jiných potíží, které se objeví po nasazení výrobku.</p>'
  . '<p>Snímatelný výrobek do vyšetření nenoste, pokud lékař nerozhodne jinak.</p>'
  . '<p>Při podezření na citlivost na některou z obsažených látek kontaktujte laboratoř a uveďte číslo zakázky UIDN z tohoto prohlášení. Podle něj dohledáme všechny použité materiály, jejich šarže a bezpečnostní údaje výrobců a poskytneme je vám nebo vašemu lékaři.</p>'
  . '<p>Denteo v.o.s., Koželská 209 / U Prádelny 851, 584 01 Ledeč nad Sázavou, tel. +420 721 066 100, www.denteo.cz</p>'
  . '<p>Podezření na závažnou nežádoucí příhodu zdravotnického prostředku můžete oznámit také Státnímu ústavu pro kontrolu léčiv (SÚKL):</p>'
  . '<p>Státní ústav pro kontrolu léčiv, Šrobárova 49/48, 100 00 Praha 10, infolinka 272 185 333 (po–pá 9:00–14:00), e-mail infs@sukl.gov.cz. Hlášení lze podat přes web sukl.gov.cz v části Veřejnost → Zdravotnické prostředky → Hlášení podezření na závažnou nežádoucí příhodu pacientem.</p>'
  . '<p>SEZNAMTE SE DŮKLADNĚ S PODMÍNKAMI UŽÍVÁNÍ ZDRAVOTNICKÉHO PROSTŘEDKU, KTERÉ JSOU UVEDENY NA ZADNÍ STRANĚ TOHOTO PROHLÁŠENÍ</p>';
const SEST_PRO_PROHL = '<p>Výrobce prohlašuje, že zdravotnický prostředek na zakázku, popřípadě soubor komponentů uvedený v seznamu níže, je ve shodě s obecnými požadavky na bezpečnost a účinnost stanovenými v příloze I nařízení Evropského parlamentu a Rady (EU) 2017/745 o zdravotnických prostředcích. Prostředek byl vyroben podle lékařského předpisu (žádanky) uvedeného předepisujícího a je určen výhradně k použití uvedeným pacientem.</p>'
  . '<p>Výrobce uchovává toto prohlášení a dokumentaci k prostředku nejméně 10 let, u implantabilních prostředků nejméně 15 let, a na vyžádání je zpřístupní Státnímu ústavu pro kontrolu léčiv (příloha XIII nařízení (EU) 2017/745).</p>'
  . '<p>Poskytovatel zdravotních služeb (zubní lékař), kterému byl prostředek předán pro použití u konkrétního pacienta, je podle § 39 odst. 1 písm. f) zákona č. 375/2022 Sb. povinen předat pacientovi toto prohlášení. Převzetím prostředku se zubní lékař zavazuje prohlášení pacientovi předat spolu s prostředkem.</p>';

function sest_prohlaseni_pdf(array $d): string {
  $e = 'arch_e';
  $h = '';
  /* hlavička */
  $h .= '<table style="width:100%"><tr>'
      . '<td style="width:22mm">' . sest_img('md.png', 'width:19mm') . '</td>'
      . '<td style="padding-left:2mm"><div class="t1">PROSTŘEDEK NA ZAKÁZKU</div><div class="t2">PROHLAŠENÍ VÝROBCE</div></td>'
      . '<td class="r" style="width:62mm">' . sest_img('denteo-lab.png', 'width:58mm') . '</td></tr></table>'
      . '<div class="sub" style="margin:1mm 0 3mm">o zdravotnickém prostředku na zakázku podle přílohy XIII nařízení (EU) 2017/745</div>';
  /* levý sloupec: klient, zakázka (okno obálky), technik, lékař, výrobce; pravý: informace pro pacienta */
  $L = '<table style="width:100%"><tr><td class="lbl" style="width:27mm;padding-top:2mm">klient:<br><br>' . sest_img('pacient.png', 'height:5mm') . ' ' . sest_img('jeden-pacient.png', 'height:5mm') . '</td>'
     . '<td class="big" style="padding-top:1mm">' . $e($d['klient'] ?? '') . '</td></tr></table>'
     . '<table style="width:100%;margin-top:1mm"><tr><td class="lbl" style="width:27mm;padding-top:3.5mm">číslo zakázky <b>UIDN</b>:</td>'
     . '<td style="width:46mm"><table><tr><td class="mid" style="border:0.7mm solid #000;padding:1.2mm 3mm">' . $e($d['tag'] ?? '') . '</td></tr></table></td>'
     . '<td class="r"><div class="xs">termín dodání:</div><div class="mid">' . $e($d['termin'] ?? '') . '</div></td></tr></table>'
     . '<table style="width:100%;margin-top:1mm"><tr><td class="huge" style="width:27mm">' . $e($d['ordinace'] ?? '') . '</td>'
     . '<td style="padding-top:1mm"><barcode code="' . $e($d['tag'] ?? '') . '" type="C128B" size="1.05" height="0.8" /></td></tr></table>'
     . '<table style="width:100%;margin-top:4mm"><tr><td class="lbl" style="width:27mm">odpovědný technik:</td><td class="b" style="font-size:10pt">' . $e($d['technik'] ?? '') . '</td></tr>'
     . '<tr><td class="lbl" style="padding-top:3mm">Předepisující lékař:</td><td class="b" style="font-size:11pt;padding-top:2.5mm">' . $e($d['lekar'] ?? '') . '</td></tr></table>'
     . '<table style="width:100%;margin-top:5mm"><tr><td class="lbl" style="width:17mm;padding-top:3mm">vyrobeno:</td><td style="width:10mm">' . sest_img('datum-vyroby.png', 'height:7mm') . '</td>'
     . '<td class="b" style="font-size:10pt;padding-top:3mm">' . $e($d['vyrobeno'] ?? '') . '</td><td class="r">' . sest_img('symboly.png', 'width:58mm') . '</td></tr>'
     . '<tr><td class="lbl" style="padding-top:2mm">výrobce:</td><td style="padding-top:1mm">' . sest_img('vyrobce.png', 'height:6mm') . '</td>'
     . '<td colspan="2" style="padding-top:1mm;font-size:7.5pt"><b>Denteo v.o.s.<br>sídlo: Koželská 209, 584 01, Ledeč nad Sázavou | IČ:06149928 | +420 721 066 100 | www.denteo.cz</b><br>provozovna: U prádelny 851, 584 01, Ledeč nad Sázavou</td></tr></table>'
     . '<div style="margin-top:5mm;margin-bottom:2mm">' . sest_img('denteo.png', 'width:30mm;display:block') . '</div>'
     . '<div class="sm" style="margin-top:2mm">' . SEST_PRO_PROHL . '</div>';
  $R = '<table style="width:100%"><tr><td class="b" style="font-size:11pt">Informace pro pacienta</td><td class="r">' . sest_img('navod.png', 'height:5mm') . '</td></tr></table>'
     . '<div class="sm inf" style="margin-top:3mm">' . SEST_PRO_INFO . '</div>';
  $h .= '<div class="colL">' . $L . '</div><div class="colR">' . $R . '</div><div style="clear:both"></div>';
  /* evidence procesů a protokol materiálů */
  $V = '<table style="width:100%"><tr class="gh"><td>kód produktu</td><td>popis</td><td class="r">Q</td></tr>';
  foreach (($d['vykony'] ?? []) as $v) $V .= '<tr class="it"><td class="b" style="width:22mm">' . $e($v['kod'] ?? '') . '</td><td>' . $e($v['popis'] ?? '') . '</td><td class="r" style="width:8mm">' . $e($v['q'] ?? '') . '</td></tr>';
  $V .= '</table>';
  $M = '<table style="width:100%"><tr class="gh"><td>materiál</td><td>REF</td><td>LOT</td><td>DID</td><td>exp.</td></tr>';
  $MM = $d['materialy'] ?? [];
  if (!$MM) $M .= '<tr class="it"><td colspan="5">bez evidovaných šarží</td></tr>';
  foreach ($MM as $m) $M .= '<tr class="it"><td class="b">' . $e($m['material'] ?? '') . '</td><td style="font-size:6.5pt;padding-right:1.5mm;width:24mm">' . str_replace(['-', '_'], ['-&#8203;', '_&#8203;'], $e($m['ref'] ?? '')) . '</td><td class="b">' . $e($m['lot'] ?? '') . '</td><td>' . $e($m['did'] ?? '') . '</td><td style="white-space:nowrap">' . $e(sest_dat($m['exp'] ?? '')) . '</td></tr>';
  $M .= '</table>';
  $h .= '<table style="width:100%;margin-top:4mm"><tr><td style="width:113mm;padding-right:3mm"><div class="sec">EVIDENCE PROCESŮ</div>' . $V . '</td>'
      . '<td style="padding-left:3mm"><div class="sec">PROTOKOL POUŽITÝCH MATERIÁLŮ</div>' . $M . '</td></tr></table>';
  /* látky */
  $LL = $d['latky'] ?? [];
  $h .= '<div class="b" style="font-size:8pt;margin-top:6mm;border-bottom:0.6mm solid #000;padding-bottom:1mm">POTENCIÁLNĚ NEBEZPEČNÉ A ALERGENNÍ LÁTKY OBSAŽENÉ ČI RIZIKA SPOJENÁ S UŽÍVÁNÍM</div>'
      . '<table style="width:100%"><tr><td><table style="width:100%">';
  if (!$LL) $h .= '<tr class="it"><td style="border:0">bez látek vyžadujících označení</td></tr>';
  foreach ($LL as $l) $h .= '<tr class="it"><td class="b" style="width:22mm">' . $e($l['kod'] ?? '') . '</td><td style="width:70mm">' . $e($l['popis'] ?? '') . '</td><td>' . $e($l['latka'] ?? '') . '</td></tr>';
  $h .= '</table></td><td class="r" style="width:22mm;padding-top:1mm">' . sest_img('varovani.png', 'height:7mm') . ' ' . sest_img('latky.png', 'height:7mm') . '</td></tr></table>';
  $p = sest_mpdf('Prohlášení výrobce ' . ($d['tag'] ?? ''), 'prohlaseni', (string) ($d['tag'] ?? ''), true);
  $p->WriteHTML($h, \Mpdf\HTMLParserMode::HTML_BODY);
  return $p->Output('', 'S');
}

/* společná hlavička přehledů: titulek, podtitul, logo */
function sest_hlava(string $tit, string $sub, string $titStyl = 'font-size:25pt'): string {
  return '<table style="width:100%"><tr><td><div class="b" style="' . $titStyl . '">' . arch_e($tit) . '</div><div class="sub">' . arch_e($sub) . '</div></td>'
       . '<td class="r" style="width:45mm">' . sest_img('denteo-lab.png', 'width:40mm') . '</td></tr></table>';
}

/* ---------- F-NAB – kalkulace protetiky ---------- */
function sest_nabidka_pdf(array $d): string {
  $e = 'arch_e';
  $h = sest_hlava('Kalkulace protetiky', 'Zubní laboratoř Denteo předpokládá, v rámci léčebného plánu předaného ordinací, následující protetiku a komponenty za nezbytné k realizaci:', 'font-size:17pt');
  $h .= '<table style="width:100%;margin-top:6mm"><tr><td class="xs b">Jméno klienta:</td><td></td><td class="xs" style="width:45mm">celkový rozpočet:</td></tr>'
      . '<tr><td colspan="2" class="big" style="border-bottom:0.3mm solid #000">' . $e($d['klient'] ?? '') . '</td><td style="border-bottom:0.3mm solid #000"></td></tr></table>'
      . '<table style="width:100%;margin-top:3mm"><tr><td class="xs b" style="width:16mm;padding-top:2mm">případ #</td><td style="width:72mm">' . sest_box((string) ($d['tag'] ?? ''), 'font-size:15pt;width:62mm', '#1a3cff') . '</td>'
      . '<td class="xs" style="width:14mm">typ |<br>ordinace:</td><td style="width:35mm">' . sest_box((string) ($d['ord'] ?? ''), 'font-size:11pt;width:22mm;text-align:center') . '</td>'
      . '<td class="r big">' . sest_kc($d['celkem'] ?? 0) . '</td></tr></table>'
      . '<div class="xs b" style="margin:5mm 0 2mm">' . $e(SEST_FIRMA) . '</div>';
  $h .= '<table style="width:100%"><tr class="gh"><td>kód</td><td>popis</td><td class="r">Q</td><td class="r">jedn. cena</td><td class="r">cena</td><td style="padding-left:3mm">pozn.</td></tr>';
  foreach (($d['polozky'] ?? []) as $i) {
    $pz = trim((string) ($i['pozn'] ?? '')); if (in_array(mb_strtolower($pz), ['není', 'nabídka'], true)) $pz = '';
    $h .= '<tr class="it"><td class="b" style="width:22mm">' . $e($i['kod'] ?? '') . '</td><td>' . $e($i['popis'] ?? '') . '</td><td class="r" style="width:8mm">' . $e($i['q'] ?? '') . '</td>'
        . '<td class="r" style="width:22mm">' . sest_kc($i['jedn'] ?? 0) . '</td><td class="r" style="width:22mm">' . sest_kc($i['cena'] ?? 0) . '</td><td style="width:16mm;padding-left:3mm">' . $e($pz) . '</td></tr>';
  }
  $h .= '</table>';
  $p = sest_mpdf('Kalkulace ' . ($d['tag'] ?? ''), 'nabidka', (string) ($d['tag'] ?? ''), false);
  $p->WriteHTML($h, \Mpdf\HTMLParserMode::HTML_BODY);
  return $p->Output('', 'S');
}

/* ---------- E-MZD – odměny technika za měsíc ---------- */
function sest_mzd_pdf(array $d): string {
  $e = 'arch_e';
  $h = sest_hlava('Odměny technik', 'výkaz prací odepsaný v uvedeném měsíci a roce');
  $h .= '<table style="width:100%;margin-top:5mm"><tr><td class="b" style="font-size:14pt;width:95mm">' . $e($d['jmeno'] ?? '') . '</td><td class="xs r" style="width:12mm;padding-right:2mm">rok:</td><td class="c b" style="width:28mm;font-size:13pt;border:0.4mm solid #000;padding:1mm">' . $e($d['rok'] ?? '') . '</td><td></td></tr>'
      . '<tr><td style="padding-top:2mm"><table><tr><td class="xs" style="padding:3mm 3mm 0 0">T:</td><td>' . sest_box((string) ($d['kdo'] ?? ''), 'font-size:27pt;padding:0 6mm') . '</td></tr></table></td><td class="xs r" style="padding-right:2mm;padding-top:4mm">měsíc:</td><td class="c b" style="font-size:13pt;border:0.4mm solid #000;padding:1mm">' . $e($d['mesic'] ?? '') . '</td>'
      . '<td class="r"><div class="xs" style="text-align:left;padding-left:4mm">celkem:</div><div class="big">' . sest_kc($d['celkem'] ?? 0) . '</div></td></tr></table>';
  $h .= '<table style="width:100%;margin-top:4mm"><tr class="gh"><td>měsíc</td><td>rok</td><td>zakázka</td><td>kód</td><td>pacient</td><td class="r">Q</td><td class="r">odměna</td><td class="r">cena</td></tr>';
  foreach (($d['radky'] ?? []) as $r) $h .= '<tr class="it"><td>' . $e($r['mesic'] ?? '') . '</td><td>' . $e($r['rok'] ?? '') . '</td><td>' . $e($r['tag'] ?? '') . '</td><td>' . $e($r['kod'] ?? '') . '</td><td>' . $e($r['pacient'] ?? '') . '</td>'
      . '<td class="r">' . $e($r['q'] ?? '') . '</td><td class="r b">' . sest_kc($r['odmena'] ?? 0) . '</td><td class="r">' . sest_kc($r['cena'] ?? 0) . '</td></tr>';
  $h .= '</table>';
  $p = sest_mpdf('Odměny ' . ($d['kdo'] ?? '') . ' ' . ($d['mesic'] ?? '') . '/' . ($d['rok'] ?? ''), 'mzd', 'E-MZD ' . ($d['kdo'] ?? '') . ' ' . ($d['mesic'] ?? '') . '/' . ($d['rok'] ?? ''), false);
  $p->WriteHTML($h, \Mpdf\HTMLParserMode::HTML_BODY);
  return $p->Output('', 'S');
}

/* ---------- E-PVF – měsíční přehled pro lékaře (příloha faktury) ---------- */
function sest_pvf_pdf(array $d): string {
  $e = 'arch_e';
  $h = sest_hlava('Měsíční přehled výrobků a výkonů', 'Výrobky produkované v příslušném měsíci a roce zubní laboratoří Denteo', 'font-size:17pt');
  $h .= '<table style="width:100%;margin-top:5mm"><tr><td class="b" style="font-size:11pt;width:70mm">' . $e($d['jmeno'] ?? '') . '</td><td class="xs r" style="width:12mm;padding-right:2mm">rok:</td><td class="c b" style="width:28mm;font-size:13pt;border:0.4mm solid #000;padding:1mm">' . $e($d['rok'] ?? '') . '</td>'
      . '<td class="xs" style="padding-left:5mm;font-size:5.5pt" rowspan="2">Na základě níže uvedeného seznamu prací je vystavena příslušná faktura, jejíž nedílnou součástí je tato příloha. Pokud narazíte na nesrovnalosti tohoto výkazu, neprodleně prosím kontaktujte laboratoř Denteo k nápravě. Děkujeme.<table style="width:100%;margin-top:2mm"><tr><td class="big r">' . sest_kc($d['celkem'] ?? 0) . '</td></tr></table></td></tr>'
      . '<tr><td style="padding-top:2mm"><table><tr><td class="xs" style="padding:3mm 3mm 0 0">Ordinace<br>zkratka:</td><td>' . sest_box((string) ($d['kdo'] ?? ''), 'font-size:19pt;padding:0.5mm 5mm', '#1a3cff') . '</td></tr></table></td><td class="xs r" style="padding-right:2mm;padding-top:4mm">měsíc:</td><td class="c b" style="font-size:13pt;border:0.4mm solid #000;padding:1mm">' . $e($d['mesic'] ?? '') . '</td></tr></table>'
      . '<div class="mono c" style="font-size:6.5pt;margin:4mm 0 1mm;border-bottom:0.3mm solid #000;padding-bottom:1mm">' . $e(SEST_FIRMA) . '</div>';
  $h .= '<table class="mono" style="width:100%"><tr class="gh"><td>měsíc</td><td>rok</td><td>ID zakázky</td><td>KÓD výrobku</td><td>jméno pacienta</td><td class="r" style="padding-right:3mm">množství</td><td>tech.</td><td class="r">cena</td><td style="padding-left:2mm">pozn.</td></tr>';
  foreach (($d['radky'] ?? []) as $r) $h .= '<tr class="it"><td>' . $e($r['mesic'] ?? '') . '</td><td>' . $e($r['rok'] ?? '') . '</td><td>' . $e($r['tag'] ?? '') . '</td><td>' . $e($r['kod'] ?? '') . '</td><td>' . $e($r['pacient'] ?? '') . '</td>'
      . '<td class="r" style="padding-right:3mm">' . $e($r['q'] ?? '') . '</td><td>' . $e($r['tech'] ?? '') . '</td><td class="r">' . sest_kc($r['cena'] ?? 0, 2) . '</td><td style="width:10mm"></td></tr>';
  $h .= '</table>';
  $p = sest_mpdf('Přehled ' . ($d['kdo'] ?? '') . ' ' . ($d['mesic'] ?? '') . '/' . ($d['rok'] ?? ''), 'pvf', 'E-PVF ' . ($d['kdo'] ?? '') . ' ' . ($d['mesic'] ?? '') . '/' . ($d['rok'] ?? ''), false);
  $p->WriteHTML($h, \Mpdf\HTMLParserMode::HTML_BODY);
  return $p->Output('', 'S');
}

function sest_pdf(string $typ, array $d): string {
  return match ($typ) { 'prohlaseni' => sest_prohlaseni_pdf($d), 'nabidka' => sest_nabidka_pdf($d), 'mzd' => sest_mzd_pdf($d), 'pvf' => sest_pvf_pdf($d) };
}
