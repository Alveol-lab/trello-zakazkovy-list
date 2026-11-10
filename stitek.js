/* =========================================================
   Štítky DID pro Brother P-touch (PT-P750W) – sdílený kód
   Stejný vzhled jako formulář příjmu (prijem.php na denteo.space):
   výška stránky = šířka pásky, 9 mm páska: text v tiskové výšce 7 mm,
   čárový kód Code 128 s okrajem 2 mm, délka 80 mm (9 mm) / 70 mm,
   prodlouží se podle nejdelšího textu v dávce (texty se neořezávají).
   Použití: Stitek.print(items) – items: [{ did, nazev, ref, lot, exp }]
   Tisk z iframe Trella: když prohlížeč tisk v iframe zablokuje,
   otevře se stránka stitek.html v novém okně a tiskne odtud.
   ========================================================= */
(function(){
  var LBL = { tape:9, items:[], len:80 };
  try { var tp = +localStorage.getItem('prijemTape'); if ([9, 12, 24].indexOf(tp) >= 0) LBL.tape = tp; } catch(e){}
  function esc(s){ return String(s == null ? '' : s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
  var C128 = ['212222','222122','222221','121223','121322','131222','122213','122312','132212','221213','221312','231212','112232','122132','122231','113222','123122','123221','223211','221132','221231','213212','223112','312131','311222','321122','321221','312212','322112','322211','212123','212321','232121','111323','131123','131321','112313','132113','132311','211313','231113','231311','112133','112331','132131','113123','113321','133121','313121','211331','231131','213113','213311','213131','311123','311321','331121','312113','312311','332111','314111','221411','431111','111224','111422','121124','121421','141122','141221','112214','112412','122114','122411','142112','142211','241211','221114','413111','241112','134111','111242','121142','121241','114212','124112','124211','411212','421112','421211','212141','214121','412121','111143','111341','131141','114113','114311','411113','411311','113141','114131','311141','411131','211412','211214','211232','2331112'];
  function c128(text){                              /* vrátí šířky modulů (pruh, mezera, pruh…) */
    var codes = [104], sum = 104;
    for (var i = 0; i < text.length; i++) { var v = text.charCodeAt(i) - 32; if (v < 0 || v > 94) v = 0; codes.push(v); sum += v * (i + 1); }
    codes.push(sum % 103); codes.push(106);
    return codes.map(function(c){ return C128[c]; }).join('');
  }
  function c128svg(text, hMm, modMm){
    var w = c128(text), q = 10, x = q, rects = '';
    for (var i = 0; i < w.length; i++) { var n = +w[i]; if (i % 2 === 0) rects += '<rect x="' + x + '" y="0" width="' + n + '" height="1"/>'; x += n; }
    var total = x + q;
    return '<svg class="lb" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + total + ' 1" preserveAspectRatio="none" style="width:' + (total * modMm).toFixed(2) + 'mm;height:' + hMm.toFixed(2) + 'mm" shape-rendering="crispEdges" fill="#000">' + rects + '</svg>';
  }
  var MOD = 0.282;                                   /* šířka modulu = 2 body při 180 dpi */
  /* délka štítku: základ 80 mm (9 mm páska) / 70 mm; když se text nevejde, štítek se prodlouží
     podle nejdelšího štítku v tiskové dávce (texty se neořezávají, celá dávka má jednu délku stránky) */
  function lblBase(){ return LBL.tape <= 9 ? 80 : 70; }
  function lblNeed(it){                             /* potřebná délka štítku v mm (měří se skutečné vykreslení) */
    var host = document.createElement('div');
    host.style.cssText = 'position:absolute;left:-9999px;top:0;visibility:hidden';
    host.innerHTML = lblHtml(it, 'max-content');
    document.body.appendChild(host);
    var w = host.firstChild.getBoundingClientRect().width * 25.4 / 96;
    document.body.removeChild(host);
    return w;
  }
  function lblLen(items){
    var len = lblBase();
    (items || []).forEach(function(it){ len = Math.max(len, Math.ceil(lblNeed(it) + 1)); });
    return len;
  }
  /* výška stránky = šířka pásky: ovladač Brother bere jako velikost papíru šířku vložené pásky
     a při nesouladu hlásí chybu. Obsah se drží uvnitř tiskové výšky (9 mm páska max. 7 mm). */
  function lblH(){ return LBL.tape; }
  function lblHtml(it, wAuto){
    /* T = šířka pásky = výška stránky. Svislý okraj = netisknutelný pruh + rezerva:
       9 mm páska: text využívá celou tiskovou výšku 7 mm (okraj 1 mm), čárový kód drží okraj 2 mm (výška 5 mm);
       12 mm obsah 9,5 mm, 24 mm obsah 17 mm */
    var T = LBL.tape, H = lblH(), pad = T <= 9 ? 1.0 : (T <= 12 ? 1.25 : 3.5), padX = T <= 9 ? 2.0 : (T <= 12 ? 2.0 : 3.8);
    var inner = H - 2 * pad, narrow = T <= 12, bcIn = T <= 9 ? H - 2 * 2.0 : inner;
    var did = T <= 9 ? 5.6 : (T <= 12 ? 4.6 : 6.4), small = T <= 9 ? 2.5 : (T <= 12 ? 1.75 : 2.7);
    var lines = T <= 9 ? [it.nazev, 'LOT ' + it.lot + (it.exp ? ' · EXP ' + it.exp : '')]
              : narrow ? [it.nazev, 'REF ' + it.ref + ' · LOT ' + it.lot, it.exp ? 'EXP ' + it.exp : '']
                       : [it.nazev, 'REF ' + it.ref, 'LOT ' + it.lot, it.exp ? 'EXP ' + it.exp : ''];
    var txt = '<div class="lt" style="font-size:' + small + 'mm;line-height:1.2">' + lines.filter(Boolean).map(function(l, i){ return '<div' + (i === 0 ? ' style="font-weight:700"' : '') + '>' + esc(l) + '</div>'; }).join('') + '</div>';
    var box = 'width:' + (wAuto || LBL.len + 'mm') + ';height:' + H + 'mm;padding:' + pad + 'mm ' + padX + 'mm;gap:' + (narrow ? 1.6 : 2.6) + 'mm';
    if (narrow) return '<div class="lbl" style="' + box + '"><div class="ld" style="font-size:' + did + 'mm;line-height:1">' + esc(it.did) + '</div>'
      + c128svg(it.did, bcIn, MOD) + txt + '</div>';
    var bcH = inner - did * 1.05 - 0.8;                /* širší páska: DID nad čárovým kódem, text vpravo */
    return '<div class="lbl" style="' + box + '"><div style="flex:none;display:flex;flex-direction:column;align-items:center;gap:0.8mm">'
      + '<div class="ld" style="font-size:' + did + 'mm;line-height:1.05">' + esc(it.did) + '</div>'
      + c128svg(it.did, bcH, MOD) + '</div>' + txt + '</div>';
  }

  var CSS = '#print{display:none}'
    + '.lbl{position:relative;display:flex;align-items:center;overflow:hidden;background:#fff;color:#000;font-family:Arial,"Helvetica Neue",sans-serif;box-sizing:border-box}'
    + '.lbl .ld{flex:none;font-weight:700;letter-spacing:.02em;white-space:nowrap}'
    + '.lbl .lb{flex:none;display:block}'
    + '.lbl .lt{flex:1;min-width:0;overflow:hidden}'
    + '.lbl .lt div{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
    + '@media print{html,body{background:#fff!important;margin:0!important;padding:0!important}'
    + 'body>*:not(#print){display:none!important}#print{display:block!important}'
    + '#print .lbl{break-after:page;page-break-after:always}#print .lbl:last-child{break-after:auto;page-break-after:auto}}';
  function ensure(){
    if (document.getElementById('lblCss')) return;
    var st = document.createElement('style'); st.id = 'lblCss'; st.textContent = CSS; document.head.appendChild(st);
    var ps = document.createElement('style'); ps.id = 'pageSize'; document.head.appendChild(ps);
    var pr = document.createElement('div'); pr.id = 'print'; document.body.appendChild(pr);
  }
  function setTape(w){ w = +w; if ([9, 12, 24].indexOf(w) < 0) return; LBL.tape = w; try { localStorage.setItem('prijemTape', String(w)); } catch(e){} }
  /* vrací: 'print' = otevřel se dialog tisku, 'window' = tiskne se v novém okně, 'blocked' = prohlížeč zablokoval i okno */
  function print(items, opts){
    if (!items || !items.length) return '';
    ensure();
    LBL.items = items; LBL.len = lblLen(items);
    document.getElementById('pageSize').textContent = '@page{size:' + LBL.len + 'mm ' + lblH() + 'mm;margin:0}';
    document.getElementById('print').innerHTML = items.map(function(it){ return lblHtml(it); }).join('');
    if (opts && opts.inline === false) return fallback(items);
    var fired = false, on = function(){ fired = true; };
    window.addEventListener('beforeprint', on);
    try { window.print(); } catch(e){}
    window.removeEventListener('beforeprint', on);
    return fired ? 'print' : (opts && opts.noFallback ? 'blocked' : fallback(items));
  }
  function fallback(items){
    var data = encodeURIComponent(JSON.stringify({ tape:LBL.tape, items:items.map(function(it){ return { did:it.did, nazev:it.nazev, ref:it.ref, lot:it.lot, exp:it.exp }; }) }));
    var w = null;
    try { w = window.open(new URL('stitek.html', document.baseURI || location.href).href + '#' + data, '_blank'); } catch(e){}
    return w ? 'window' : 'blocked';
  }
  function preview(it){ ensure(); LBL.len = lblLen([it]); return lblHtml(it); }
  window.Stitek = { print:print, preview:preview, setTape:setTape, tape:function(){ return LBL.tape; }, tapes:[9, 12, 24] };
})();
