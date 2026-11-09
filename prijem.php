<?php
require __DIR__ . '/prijem-auth.php';
prijem_nocache();
if (!prijem_logged()) { header('Location: prijem-login.php'); exit; }
?><!doctype html>
<html lang="cs" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Příjem materiálu</title>
<style>
/* =========================================================
   PŘÍJEM MATERIÁLU – samostatný formulář (denteo.space)
   Vizuální jazyk převzatý z formuláře 3.1 (section3.html):
   mřížka 0–340, 1rem = šířka formuláře / 340 (nastavuje JS).
   ========================================================= */
:root{
  --bg:#242528; --panel:#393B44; --head:#1A1A1A; --chip:#242528; --btn:#3D4451;
  --txt:#9E9FA2; --txt-hi:#C8C9CC; --txt-dim:#939497; --txt-strong:#FFFFFF;
  --bd-hi:#9E9FA2; --bd:#939497; --bd-soft:#555555; --rule:#575E6E;
  --ok:#3614C4; --ok-txt:#E6E9FF; --del:#6B2C31; --del-txt:#F2D3D5;
  --bad:#E2686B; --warn:#E0A526; --good:#3FA36B; --wait:#6E6F72;
  --menu-shadow:rgba(0,0,0,.45); --focus:#FFFFFF; --page:#1D1E21;
  color-scheme:dark;
}
html[data-theme="light"]{
  --bg:#FFFFFF; --panel:#F0F1F3; --head:#DCDFE4; --chip:#FFFFFF; --btn:#DCDFE4;
  --txt:#172B4D; --txt-hi:#091E42; --txt-dim:#626F86; --txt-strong:#091E42;
  --bd-hi:#172B4D; --bd:#626F86; --bd-soft:#B3BAC5; --rule:#C1C7D0;
  --ok:#3614C4; --ok-txt:#FFFFFF; --del:#F4D6D8; --del-txt:#AE2E24;
  --warn:#B65C02; --good:#216E4E; --wait:#8993A4; --bad:#C9372C;
  --menu-shadow:rgba(9,30,66,.25); --focus:#0C66E4; --page:#E9EBEE;
  color-scheme:light;
}
*{box-sizing:border-box}
html{font-size:2px}
body{margin:0;background:var(--page);font-family:Arial,"Helvetica Neue",-apple-system,"Segoe UI",Roboto,sans-serif;
     font-size:8.333rem;color:var(--txt);-webkit-font-smoothing:antialiased}
#app{position:relative;width:100%;max-width:720px;margin:0 auto;background:var(--bg);padding:12rem 0 14rem;min-height:100vh}
[hidden]{display:none!important}

/* hlavička */
.top .seg{width:40rem;height:22rem}
.top .seg button{font-size:8rem}
.top{display:flex;align-items:center;gap:4rem;padding:0 5rem;height:22rem}
.top h1{flex:1;margin:0;font-size:10rem;font-weight:700;color:var(--txt-strong);white-space:nowrap}
.top .btn{width:22rem;height:22rem}
.sub{padding:2rem 5rem 0;font-size:7.1rem;line-height:9rem;color:var(--txt-dim)}

/* pole */
.row{display:flex;align-items:stretch;gap:4rem;padding:0 5rem}
.row+.row{margin-top:4rem}
.f{display:block;flex:none;min-width:0;height:20rem;margin:0;border:0;
   --c:var(--bd);box-shadow:inset 0 0 0 .6rem var(--c);border-radius:3.2rem;
   background:var(--bg);color:var(--txt-hi);font:inherit;font-size:8.333rem;
   line-height:20rem;padding:0 4rem 0 6rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.f::placeholder{color:var(--txt-dim);opacity:1}
.f:focus{outline:none;--c:var(--focus)}
.f.hi{--c:var(--bd-hi)}
.f.ro{background:transparent;--c:var(--bd-soft);color:var(--txt-dim);font-size:7.083rem}
.f.ro.got{color:var(--txt-hi)}
.f.ctr{text-align:center;padding:0 2rem}
.f.bad{--c:var(--bad)}
.f.warn,.f.newm{--c:var(--warn)}
.f.wait{color:var(--wait)}
.grow{flex:1 1 0!important}
input[type=date].f{padding-right:2rem;font-size:7.6rem}
input[type=date].f::-webkit-calendar-picker-indicator{opacity:.6;cursor:pointer;width:9rem;height:9rem;margin:0}
.c1{width:66rem} .c2{width:30rem} .c4{width:70rem}
.lab{flex:none;width:44rem;height:20rem;line-height:20rem;font-size:7.4rem;color:var(--txt-dim)}

/* tlačítka */
.btn{flex:none;min-width:0;height:20rem;border:0;border-radius:3.2rem;padding:0;cursor:pointer;
     background:var(--btn);color:var(--txt);font:inherit;font-size:8.333rem;line-height:1;
     display:flex;align-items:center;justify-content:center;gap:3rem}
.btn:hover{filter:brightness(1.12)}
.btn:focus-visible{outline:.8rem solid var(--focus);outline-offset:1rem}
.btn:disabled{opacity:.5;cursor:default;filter:none}
.btn.pri{background:var(--ok);color:var(--ok-txt)}
.btn.ghost{background:transparent;box-shadow:inset 0 0 0 .6rem var(--bd-soft)}
.ic12{width:11rem;height:11rem;display:block}

/* pás */
.band{margin-top:10rem;background:var(--panel);padding:6rem 0 7rem}
.bhead{display:flex;align-items:center;gap:4rem;padding:0 5rem;height:16rem;margin-bottom:4rem;
       color:var(--txt-strong);font-size:8.333rem}
.bhead .n{flex:none;width:14rem;height:14rem;border-radius:7rem;background:var(--ok);color:var(--ok-txt);
          font-size:7.4rem;font-weight:700;display:flex;align-items:center;justify-content:center}
.bhead .s{margin-left:auto;font-size:7.1rem;color:var(--txt-dim)}

/* přepínač */
.seg{flex:none;display:flex;height:20rem;border-radius:3.2rem;background:var(--chip);
     box-shadow:inset 0 0 0 .6rem var(--bd-soft);padding:1.6rem;gap:1.6rem}
.seg button{flex:1;min-width:0;border:0;border-radius:2.2rem;background:none;cursor:pointer;
            color:var(--txt-dim);font:inherit;font-size:7.4rem;padding:0 1.2rem;white-space:nowrap}
.seg button[aria-pressed="true"]{background:var(--btn);color:var(--txt-hi);font-weight:700}
html[data-theme="light"] .seg button[aria-pressed="true"]{background:#0C66E4;color:#fff}
.seg.bad{box-shadow:inset 0 0 0 .6rem var(--bad)}
.seg.off{opacity:.45;pointer-events:none}

/* pole s popiskem uvnitř */
.fsx{position:relative;flex:none;display:block}
.fsx .f{width:100%;padding:0 34rem 0 6rem;text-align:left}
.fsx span{position:absolute;right:5rem;top:0;line-height:20rem;font-size:7.1rem;color:var(--txt-dim);pointer-events:none}
.fsx .f[disabled]{opacity:.55}
.fsx .f[disabled]+span{opacity:.55}

/* štítky */
.chips{flex:1;min-width:0;display:flex;gap:3rem;align-items:center;flex-wrap:wrap}
.chip{flex:0 1 auto;min-width:0;height:16rem;line-height:16rem;border-radius:3.2rem;background:var(--chip);
      color:var(--txt-dim);font-size:7.1rem;padding:0 5rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.chip b{color:var(--txt-hi);font-weight:700}
.chip.good{color:var(--good);box-shadow:inset 0 0 0 .6rem var(--good)}
.chip.warn{color:var(--warn);box-shadow:inset 0 0 0 .6rem var(--warn);background:transparent}
.chip.bad{color:var(--bad);box-shadow:inset 0 0 0 .6rem var(--bad);background:transparent}

/* nápověda a hlášky */
.hint{padding:3rem 5rem 0;font-size:7.1rem;line-height:9rem;color:var(--txt-dim);min-height:6rem}
.hint.warn{color:var(--warn)} .hint.bad{color:var(--bad)} .hint.good{color:var(--good)}
.hint button{border:0;background:var(--chip);color:var(--txt-hi);font:inherit;font-size:7.1rem;font-weight:700;
             height:13rem;padding:0 5rem;margin:1.5rem 2rem 0 0;border-radius:2.5rem;cursor:pointer;
             box-shadow:inset 0 0 0 .5rem var(--warn)}

/* našeptávač */
.sugg{position:absolute;z-index:41;max-height:130rem;overflow:auto;padding:2.5rem;border-radius:3.2rem;background:var(--panel);
      box-shadow:0 2rem 8rem var(--menu-shadow),inset 0 0 0 .5rem var(--rule)}
.sugg button{display:flex;gap:5rem;width:100%;height:14rem;align-items:center;padding:0 5rem;border:0;border-radius:2.5rem;background:transparent;
             color:var(--txt);font:inherit;font-size:7.4rem;text-align:left;white-space:nowrap;overflow:hidden;cursor:pointer}
.sugg button b{color:var(--txt-hi);font-weight:700}
.sugg button small{margin-left:auto;font-size:6.7rem;color:var(--txt-dim)}
.sugg button.on,.sugg button:hover{background:var(--chip);color:var(--txt-hi)}
.sugg .nw{padding:2rem 5rem 1rem;font-size:6.7rem;color:var(--warn)}

/* kontrola před založením */
.need{margin:8rem 5rem 0;border-radius:3.2rem;background:var(--bg);box-shadow:inset 0 0 0 .8rem var(--rule);padding:7rem}
.need h4{margin:0 0 5rem;font-size:8.333rem;font-weight:700;color:var(--txt-strong)}
.sumt{width:100%;border-collapse:collapse;font-size:7.4rem}
.sumt td{padding:2.6rem 0;vertical-align:top;color:var(--txt-hi);border-top:.5rem solid var(--rule)}
.sumt td:first-child{width:62rem;color:var(--txt-dim)}
.sumt tr:first-child td{border-top:0}
.msgs{margin-top:6rem}
.msg{display:flex;gap:4rem;align-items:flex-start;font-size:7.4rem;line-height:9.5rem;padding:3rem 4rem;border-radius:2.5rem}
.msg+.msg{margin-top:3rem}
.msg.bad{color:var(--bad);box-shadow:inset 0 0 0 .6rem var(--bad)}
.msg.warn{color:var(--txt-hi);box-shadow:inset 0 0 0 .6rem var(--warn);cursor:pointer}
.msg input{flex:none;width:9rem;height:9rem;margin:.3rem 0 0;accent-color:var(--warn)}
.msg .x{flex:none;font-weight:700}
.nacts{display:flex;justify-content:flex-end;gap:4rem;margin-top:7rem}
.nacts .btn{padding:0 9rem}

/* výsledek */
.created{margin:8rem 5rem 0;border-radius:3.2rem;background:var(--bg);padding:7rem;box-shadow:inset 0 0 0 .8rem var(--good)}
.created .t{font-size:8.333rem;font-weight:700;color:var(--good);line-height:11rem}
.created .s{font-size:7.1rem;color:var(--txt-dim);line-height:9rem;margin-top:1rem}
.dids{display:flex;flex-wrap:wrap;gap:4rem;margin-top:5rem}
.dids b{font-size:12rem;line-height:22rem;padding:0 8rem;border-radius:3.2rem;background:var(--panel);color:var(--txt-strong);letter-spacing:.5rem}
.created .nacts{margin-top:7rem}

/* štítky Brother P-touch */
.lblbar{display:flex;align-items:center;gap:4rem;margin-top:6rem}
.lblbar .lab{width:auto;margin-right:2rem}
.lab.exl{width:auto}
.lblbar .seg{width:84rem}
.lblbar .btn{padding:0 9rem}
.lblprev{margin-top:5rem;padding:5rem;border-radius:3.2rem;background:var(--panel);overflow-x:auto}
.lblprev .lbl{background:#fff;box-shadow:0 0 0 .5px #8993A4;margin:0 auto}
#print{display:none}
.lbl{position:relative;display:flex;align-items:center;overflow:hidden;background:#fff;color:#000;
     font-family:Arial,"Helvetica Neue",sans-serif;box-sizing:border-box}
.lbl .ld{flex:none;font-weight:700;letter-spacing:.02em;white-space:nowrap}
.lbl .lb{flex:none;display:block}
.lbl .lt{flex:1;min-width:0;overflow:hidden}
.lbl .lt div{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
@media print{
  html,body{background:#fff!important;margin:0!important;padding:0!important}
  body>*:not(#print){display:none!important}
  #print{display:block!important}
  #print .lbl{break-after:page;page-break-after:always}
  #print .lbl:last-child{break-after:auto;page-break-after:auto}
}
/* seznam zapsaných příjmů */
.zl{padding:0 5rem}
.zr{border-radius:3.2rem;background:var(--bg);padding:4rem 5rem;box-shadow:inset 0 0 0 .5rem var(--rule)}
.zr+.zr{margin-top:3rem}
.zr.new{box-shadow:inset 0 0 0 .7rem var(--good)}
.zr .l1{display:flex;align-items:center;gap:4rem;min-height:16rem}
.zr .did{flex:none;font-size:9.5rem;font-weight:700;color:var(--txt-strong);letter-spacing:.4rem;min-width:30rem}
.zr .nm{flex:1;min-width:0;font-size:7.6rem;color:var(--txt-hi);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.zr .l2{font-size:6.9rem;line-height:9rem;color:var(--txt-dim);padding-left:34rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.zr .acts{flex:none;display:flex;gap:2.5rem}
.zr .acts .btn{height:15rem;font-size:7.1rem;padding:0 5rem}
.zr .btn.del{background:var(--del);color:var(--del-txt)}
.zr .lock{font-size:6.7rem;color:var(--txt-dim);align-self:center;padding:0 2rem}
.zr .ed{display:flex;gap:3rem;align-items:center;margin-top:4rem;flex-wrap:wrap}
.zr .ed .f{height:17rem;line-height:17rem;font-size:7.6rem}
.zr .ed .btn{height:17rem;padding:0 7rem;font-size:7.4rem}
.zr .er{font-size:7.1rem;color:var(--bad);margin-top:3rem}
.zr .ask{margin-top:4rem;display:flex;align-items:center;gap:4rem;font-size:7.4rem;color:var(--bad)}
.zr .ask .btn{height:15rem;padding:0 7rem;font-size:7.1rem}
.zempty{padding:2rem 5rem;font-size:7.1rem;color:var(--txt-dim)}
.loadbad{margin:6rem 5rem 0;padding:4rem 5rem;border-radius:2.5rem;font-size:7.4rem;line-height:9.5rem;color:var(--bad);box-shadow:inset 0 0 0 .6rem var(--bad)}
.btn.sq{width:20rem}
.btn[aria-pressed="true"]{box-shadow:inset 0 0 0 .7rem var(--focus)}
.band .nacts{padding:0 5rem}
.f[readonly].lk{background:transparent;--c:var(--bd-soft);color:var(--txt-dim)}
.sr{display:block;width:100%;text-align:left;font:inherit;border:0;cursor:pointer;color:inherit}
.sr:hover,.sr.on{box-shadow:inset 0 0 0 .7rem var(--focus)}
.sr .did{min-width:56rem;font-size:8.333rem;letter-spacing:0}
.sr .l2{padding-left:60rem}
.foot a{color:var(--txt-dim)}
.foot{padding:10rem 5rem 0;font-size:6.7rem;line-height:8.5rem;color:var(--txt-dim)}
.demo{margin:0 5rem 6rem;padding:3rem 5rem;border-radius:2.5rem;font-size:7.1rem;color:var(--warn);box-shadow:inset 0 0 0 .6rem var(--warn)}
</style>
</head>
<body>
<div id="app">
  <div class="demo" id="demoBar" hidden>Ukázkový režim – data nejsou skutečná a nic se nezapisuje.</div>
  <div class="loadbad" id="loadBad" hidden></div>

  <div class="top">
    <h1>Příjem materiálu</h1>
    <div class="seg" id="oper" title="kdo zapisuje">
      <button type="button" data-v="H" aria-pressed="true" title="Jan Novák">H</button>
      <button type="button" data-v="M" aria-pressed="false" title="Marie Vosiková">M</button>
    </div>
    <button type="button" class="btn ghost" id="gear" title="nastavení materiálu – hledání a úprava karet MAT" aria-pressed="false">
      <svg class="ic12" viewBox="0 0 24 24" fill="currentColor"><path d="M19.14 12.94c.04-.3.06-.61.06-.94s-.02-.64-.07-.94l2.03-1.58a.49.49 0 0 0 .12-.61l-1.92-3.32a.49.49 0 0 0-.59-.22l-2.39.96a7 7 0 0 0-1.62-.94l-.36-2.54a.48.48 0 0 0-.48-.41h-3.84a.47.47 0 0 0-.47.41l-.36 2.54a7.4 7.4 0 0 0-1.62.94l-2.39-.96a.48.48 0 0 0-.59.22L2.74 8.87a.47.47 0 0 0 .12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58a.49.49 0 0 0-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54a7 7 0 0 0 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32a.48.48 0 0 0-.12-.61zM12 15.6A3.6 3.6 0 1 1 15.6 12 3.6 3.6 0 0 1 12 15.6z"/></svg>
    </button>
    <button type="button" class="btn ghost" id="theme" title="přepnout světlý / tmavý motiv">
      <svg class="ic12" viewBox="0 0 24 24" fill="currentColor"><path d="M12 3a9 9 0 1 0 9 9A9 9 0 0 0 12 3zm0 16V5a7 7 0 0 1 0 14z"/></svg>
    </button>
  </div>
  <div class="sub">Založení dodaného materiálu na sklad. Každé balení (u kusových položek každý kus) dostane vlastní DID.</div>

  <div id="formView">
  <!-- 2 materiál -->
  <div class="band">
    <div class="bhead"><span class="n">1</span>Materiál<span class="s" id="matState"></span></div>
    <div class="row">
      <input class="f hi c1" id="ref" placeholder="REF" autocomplete="off" spellcheck="false" title="REF (katalogové číslo) z obalu">
      <input class="f ro grow" id="nam" placeholder="název z číselníku MAT" readonly tabindex="-1">
    </div>
    <div class="hint" id="refHint">Opiš REF z obalu. Formulář najde kartu materiálu, nebo nabídne založení nové.</div>

    <div id="newCard" hidden>
      <div class="row">
        <div class="seg c1" id="kat1" title="legislativní kategorie">
          <button type="button" data-v="F" aria-pressed="false" title="fixní protetika">F</button>
          <button type="button" data-v="R" aria-pressed="false" title="snímatelná protetika">R</button>
          <button type="button" data-v="O" aria-pressed="false" title="ortodoncie">O</button>
          <button type="button" data-v="U" aria-pressed="false" title="univerzální">U</button>
        </div>
        <div class="seg c2" id="kat2" title="hlavní / pomocný materiál">
          <button type="button" data-v="M" aria-pressed="false" title="hlavní materiál – jde do úst pacienta">M</button>
          <button type="button" data-v="S" aria-pressed="false" title="pomocný materiál">S</button>
        </div>
        <input class="f hi grow" id="vyr" placeholder="výrobce" autocomplete="off">
      </div>
      <div class="row">
        <input class="f grow" id="dod" placeholder="dodavatel (víc dodavatelů odděl čárkou)" autocomplete="off">
      </div>
      <div class="row">
        <div class="seg grow" id="odpis" title="jak se materiál odepisuje do zakázek">
          <button type="button" data-v="auto" aria-pressed="false" title="odepisuje se automaticky podle výrobku (MAT-SET / PRODB)">odpis auto</button>
          <button type="button" data-v="ruční" aria-pressed="false" title="technik zapisuje DID ručně k zakázce (šrouby, implantáty, kusové komponenty)">odpis ruční</button>
        </div>
      </div>
      <div class="row">
        <input class="f grow" id="skup" placeholder="skupina SK-… (nepovinné – jen když materiál patří do skupiny)" autocomplete="off" spellcheck="false">
      </div>
      <div class="hint warn" id="newHint">REF není v číselníku – vyplň kartu nového materiálu.</div>
    </div>
    <div id="formaBox" hidden>
      <div class="row">
        <div class="seg c1" id="forma" title="forma evidence">
          <button type="button" data-v="ks" aria-pressed="false">kus</button>
          <button type="button" data-v="bal" aria-pressed="false">balení</button>
        </div>
        <label class="fsx c1" title="počet jednotek v jednom balení"><input class="f hi" id="jed" autocomplete="off" inputmode="decimal" disabled><span>ks v 1 bal.</span></label>
      </div>
      <div class="hint warn" id="formaHint" hidden>Karta zatím nemá formu – vyber kus / balení (u balení i počet kusů). Doplní se do karty.</div>
    </div>
  </div>

  <!-- 3 dodávka -->
  <div class="band">
    <div class="bhead"><span class="n">2</span>Dodávka</div>
    <div class="row">
      <input class="f hi grow" id="lot" placeholder="LOT (šarže z obalu)" autocomplete="off" spellcheck="false">
      <span class="lab exl">EXP</span>
      <input class="f hi c4" id="exp" type="date" title="expirace z obalu">
    </div>
    <div class="row">
      <span class="lab" id="qLab">počet balení</span>
      <input class="f hi ctr c2" id="q" value="1" autocomplete="off" inputmode="numeric" title="každé balení / kus dostane vlastní DID">
      <div class="chips" id="chips"></div>
      <button type="button" class="btn pri c4" id="check">zkontrolovat</button>
    </div>
    <div class="hint" id="dHint"></div>
  </div>

  <!-- kontrola -->
  <div class="need" id="review" hidden>
    <h4>Zkontroluj údaje před založením</h4>
    <table class="sumt" id="sumt"></table>
    <div class="msgs" id="msgs"></div>
    <div class="nacts">
      <button type="button" class="btn ghost" id="back">opravit</button>
      <button type="button" class="btn pri" id="go">založit na sklad</button>
    </div>
  </div>

  <!-- výsledek -->
  <div class="created" id="done" hidden></div>

  <!-- zapsané příjmy -->
  <div class="band" id="zBand">
    <div class="bhead">Zapsané příjmy<span class="s" id="zState">posledních 30 dní · na skladě</span></div>
    <div class="zl" id="zList"></div>
  </div>
  </div>

  <!-- nastavení materiálu (ozubené kolo) -->
  <div id="setView" hidden>
    <div class="band">
      <div class="bhead">Nastavení materiálu<span class="s" id="sState"></span></div>
      <div class="row">
        <input class="f hi grow" id="sQ" placeholder="hledat podle REF nebo názvu" autocomplete="off" spellcheck="false">
        <button type="button" class="btn ghost sq" id="sNew" title="nová karta materiálu (nový REF)">
          <svg class="ic12" viewBox="0 0 24 24" fill="currentColor"><path d="M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6z"/></svg>
        </button>
      </div>
      <div class="hint" id="sHint">Hledá v REF i v názvu, bez ohledu na diakritiku a velikost písmen. Víc slov = musí sedět všechna.</div>
      <div class="zl" id="sList"></div>
    </div>

    <div class="band" id="eBand" hidden>
      <div class="bhead"><span id="eTitle">Karta materiálu</span><span class="s" id="eState"></span></div>
      <div class="row">
        <input class="f c1" id="eRef" placeholder="REF" autocomplete="off" spellcheck="false" title="REF (katalogové číslo) – u existující karty se nemění">
        <input class="f hi grow" id="eNam" placeholder="název materiálu" autocomplete="off">
      </div>
      <div class="row">
        <div class="seg c1" id="eKat1" title="legislativní kategorie">
          <button type="button" data-v="F" aria-pressed="false" title="fixní protetika">F</button>
          <button type="button" data-v="R" aria-pressed="false" title="snímatelná protetika">R</button>
          <button type="button" data-v="O" aria-pressed="false" title="ortodoncie">O</button>
          <button type="button" data-v="U" aria-pressed="false" title="univerzální">U</button>
        </div>
        <div class="seg c2" id="eKat2" title="hlavní / pomocný materiál">
          <button type="button" data-v="M" aria-pressed="false" title="hlavní materiál – jde do úst pacienta">M</button>
          <button type="button" data-v="S" aria-pressed="false" title="pomocný materiál">S</button>
        </div>
        <input class="f hi grow" id="eVyr" data-sg="vyr" placeholder="výrobce" autocomplete="off">
      </div>
      <div class="row">
        <input class="f grow" id="eDod" data-sg="dod" placeholder="dodavatel (víc dodavatelů odděl čárkou)" autocomplete="off">
      </div>
      <div class="row">
        <div class="seg c1" id="eForma" title="forma evidence">
          <button type="button" data-v="ks" aria-pressed="false">kus</button>
          <button type="button" data-v="bal" aria-pressed="false">balení</button>
        </div>
        <label class="fsx c1" title="počet jednotek v jednom balení"><input class="f hi" id="eJed" autocomplete="off" inputmode="decimal" disabled><span>ks v 1 bal.</span></label>
      </div>
      <div class="row">
        <div class="seg grow" id="eOdpis" title="jak se materiál odepisuje do zakázek">
          <button type="button" data-v="auto" aria-pressed="false" title="odepisuje se automaticky podle výrobku (MAT-SET / PRODB)">odpis auto</button>
          <button type="button" data-v="ruční" aria-pressed="false" title="technik zapisuje DID ručně k zakázce (šrouby, implantáty, kusové komponenty)">odpis ruční</button>
        </div>
      </div>
      <div class="row">
        <input class="f grow" id="eSkup" data-sg="skup" placeholder="skupina SK-… (prázdné = samostatný materiál)" autocomplete="off" spellcheck="false">
      </div>
      <div class="hint" id="eHint"></div>
      <div class="nacts">
        <button type="button" class="btn ghost" id="eCancel">zavřít</button>
        <button type="button" class="btn pri" id="eSave" disabled>uložit</button>
      </div>
    </div>
  </div>

  <div class="foot">Materiál se zakládá jako ZALOŽENO (na skladě). Nasazení do výroby dělá technik ve formuláři 3.1 v Trellu. · <a href="prijem-login.php?odhlasit=1">odhlásit</a></div>
  <div class="sugg" id="sugg" hidden></div>
</div>
<div id="print"></div>
<style id="pageSize">@page{size:80mm 9mm;margin:0}</style>

<script>
(function(){
  'use strict';
  var SCRIPT_URL = 'prijem-api.php';               /* proxy na denteo.space: přihlášení + klíč pro Apps Script */
  var DEMO = /[?&]demo=1/.test(location.search);
  function $(id){ return document.getElementById(id); }
  function toLogin(){ location.href = 'prijem-login.php'; }
  function esc(s){ return String(s == null ? '' : s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }

  /* ---- motiv ---- */
  function applyTheme(m){ document.documentElement.setAttribute('data-theme', m); try { localStorage.setItem('prijemTheme', m); } catch(e){} }
  (function(){
    var q = (location.search.match(/[?&]theme=(light|dark)/) || [])[1], s = null;
    try { s = localStorage.getItem('prijemTheme'); } catch(e){}
    applyTheme(q || s || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
  })();
  $('theme').addEventListener('click', function(){ applyTheme(document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark'); });

  /* ---- měřítko: 1rem = šířka / 340 ---- */
  function scale(){ var w = $('app').clientWidth || 340; document.documentElement.style.fontSize = (w / 340).toFixed(4) + 'px'; }
  window.addEventListener('resize', scale); scale();

  /* ---- data ze serveru ---- */
  var DATA = { karty:[], skupiny:[], osoby:[['H', 'Jan Novák'], ['M', 'Marie Vosiková']], vyrobci:[], dodavatele:[], zapsane:[] }, DEMO_DB = null;
  function getJSON(qs){
    if (DEMO) return demoGet(qs);
    return fetch(SCRIPT_URL + qs, { cache:'no-store', credentials:'same-origin' }).then(function(r){ if (r.status === 401) { toLogin(); return null; } return r.ok ? r.json() : null; }).catch(function(){ return null; });
  }
  function post(payload){
    if (DEMO) return demoPost(payload);
    return fetch(SCRIPT_URL, { method:'POST', credentials:'same-origin', headers:{ 'Content-Type':'text/plain;charset=utf-8' }, body: JSON.stringify(payload) })
      .then(function(r){ if (r.status === 401) { toLogin(); return { ok:false, error:'přihlášení vypršelo' }; } return r.json().catch(function(){ return { ok:false, error:'server odmítl požadavek (HTTP ' + r.status + ')' }; }); })
      .catch(function(e){ return { ok:false, error:'nedostupné spojení (' + (e && e.message ? e.message : e) + ')' }; });
  }

  /* ---- normalizace REF a podobnost ---- */
  function refClean(v){ return String(v || '').replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim().toUpperCase(); }
  function refKey(v){
    return refClean(v).split(/[^0-9A-Z]+/).filter(Boolean).map(function(t){ return t.replace(/^0+(?=\d)/, ''); }).join('');
  }
  function lev1(a, b){                          /* liší se nejvýš jedním znakem */
    if (a === b) return true;
    var la = a.length, lb = b.length; if (Math.abs(la - lb) > 1) return false;
    var i = 0, j = 0, d = 0;
    while (i < la && j < lb) {
      if (a[i] === b[j]) { i++; j++; continue; }
      if (++d > 1) return false;
      if (la > lb) i++; else if (lb > la) j++; else { i++; j++; }
    }
    return d + (la - i) + (lb - j) <= 1;
  }
  function nameKey(v){ return String(v || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]/g, ''); }
  function similarRefs(ref){
    var k = refKey(ref), c = refClean(ref), same = [], near = [];
    DATA.karty.forEach(function(x){
      if (refClean(x[0]) === c) return;
      var kk = refKey(x[0]);
      if (kk === k) same.push(x);
      else if (k.length >= 5 && lev1(kk, k)) near.push(x);
    });
    return { same:same, near:near.slice(0, 4) };
  }

  /* ---- přepínače ---- */
  function segVal(id){ var b = $(id).querySelector('[aria-pressed="true"]'); return b ? b.getAttribute('data-v') : ''; }
  function segSet(id, v){
    $(id).classList.remove('bad');
    Array.prototype.forEach.call($(id).querySelectorAll('button'), function(b){ b.setAttribute('aria-pressed', b.getAttribute('data-v') === v ? 'true' : 'false'); });
  }
  ['oper','kat1','kat2','forma','odpis'].forEach(function(id){
    $(id).addEventListener('click', function(e){
      var b = e.target.closest('button'); if (!b) return;
      segSet(id, b.getAttribute('data-v'));
      if (id === 'oper') { try { localStorage.setItem('prijemOper', b.getAttribute('data-v')); } catch(er){} if (E.mode === 'new') $('eState').textContent = 'zakládá: ' + b.getAttribute('title'); }
      if (id === 'forma') formaSync();
      invalidate();
    });
  });

  /* ---- stav formuláře ---- */
  var S = { mat:null, newMat:false, seq:0, busy:false };
  function matFormaKnown(){ return S.mat && formaOf(S.mat.forma); }
  function formaSync(){
    var ask = S.newMat || (S.mat && !formaOf(S.mat.forma));
    $('formaBox').hidden = !ask; $('formaHint').hidden = !(S.mat && ask);
    var f = matFormaKnown() ? formaOf(S.mat.forma) : segVal('forma'), bal = f === 'bal';
    $('jed').disabled = !bal;
    if (!bal) $('jed').value = '';
    $('qLab').textContent = f === 'ks' ? 'počet kusů' : 'počet balení';
    chips();
  }
  function formaOf(v){ v = String(v || '').toLowerCase(); return /^k/.test(v) ? 'ks' : (/bal/.test(v) ? 'bal' : ''); }
  function n2(v){ var n = parseFloat(String(v).replace(',', '.')); return isNaN(n) ? '' : (n % 1 === 0 ? String(n) : String(Math.round(n * 100) / 100).replace('.', ',')); }
  function chips(){
    var h = [];
    if (S.mat) {
      var f = formaOf(S.mat.forma);
      if (f) h.push('<span class="chip"><b>' + (f === 'ks' ? 'kus' : n2(S.mat.jednotek) + ' ks v bal.') + '</b></span>');
      if (S.mat.skupina) h.push('<span class="chip">skupina <b>' + esc(S.mat.skupina) + '</b></span>');
      h.push(S.mat.odpis ? '<span class="chip">odpis <b>' + esc(S.mat.odpis) + '</b></span>' : '<span class="chip warn">odpis nenastaven</span>');
    } else if (S.newMat) h.push('<span class="chip warn">nová karta MAT</span>');
    $('chips').innerHTML = h.join('');
  }
  function invalidate(){ $('review').hidden = true; }
  function mark(el, cls){ el.classList.remove('bad', 'warn'); if (cls) el.classList.add(cls); }

  /* ---- REF ---- */
  function refHint(cls, html){ $('refHint').className = 'hint' + (cls ? ' ' + cls : ''); $('refHint').innerHTML = html; }
  function newMatOn(){
    S.newMat = true; $('newCard').hidden = false;
    var n = $('nam'); n.readOnly = false; n.tabIndex = 0; n.classList.remove('ro', 'got'); n.classList.add('hi', 'newm');
    n.placeholder = 'název materiálu – nová karta'; $('matState').textContent = 'nová karta';
    formaSync();
  }
  function newMatOff(){
    S.newMat = false; $('newCard').hidden = true;
    var n = $('nam'); n.readOnly = true; n.tabIndex = -1; n.classList.add('ro'); n.classList.remove('hi', 'newm', 'bad');
    n.placeholder = 'název z číselníku MAT'; $('matState').textContent = '';
  }
  function refResolve(){
    var raw = $('ref').value, ref = refClean(raw), seq = ++S.seq;
    if (ref !== raw) $('ref').value = ref;
    S.mat = null; $('nam').value = ''; newMatOff(); mark($('ref'), ''); invalidate();
    if (!ref) { refHint('', 'Opiš REF z obalu. Formulář najde kartu materiálu, nebo nabídne založení nové.'); formaSync(); return; }
    $('nam').classList.add('wait'); $('nam').value = 'hledám v číselníku…';
    getJSON('?matref=' + encodeURIComponent(ref)).then(function(d){
      if (seq !== S.seq) return;
      $('nam').classList.remove('wait'); $('nam').value = '';
      if (d && d.nazev != null) {
        S.mat = d; $('nam').value = d.nazev; $('nam').classList.add('got'); mark($('ref'), '');
        segSet('forma', ''); $('jed').value = '';
        if (!formaOf(d.forma) && parseFloat(d.jednotek) > 1) { segSet('forma', 'bal'); $('jed').disabled = false; $('jed').value = n2(d.jednotek).replace(',', '.'); }
        $('matState').textContent = 'karta nalezena';
        refHint('good', 'Materiál je v číselníku' + (d.vyrobce ? ' – ' + esc(d.vyrobce) : '') + (d.dodavatel ? ' / ' + esc(d.dodavatel) : '') + '.');
        formaSync(); return;
      }
      var sim = similarRefs(ref), list = sim.same.concat(sim.near);
      newMatOn(); mark($('ref'), 'warn');
      if (list.length) {
        refHint('warn', (sim.same.length ? 'Tento REF je v číselníku zapsaný jinak – nejspíš jde o stejný materiál:' : 'REF v číselníku není. Podobné REF (možný překlep):') + '<br>'
          + list.map(function(x){ return '<button type="button" data-ref="' + esc(x[0]) + '">' + esc(x[0]) + ' – ' + esc(String(x[1]).slice(0, 40)) + '</button>'; }).join('')
          + '<br>Pokud jde opravdu o jiný materiál, vyplň kartu níže.');
      } else refHint('warn', 'REF v číselníku není – zkontroluj ho znovu podle obalu. Pokud sedí, vyplň kartu nového materiálu.');
    });
  }
  $('ref').addEventListener('change', refResolve);
  $('ref').addEventListener('keydown', function(e){ if (e.key === 'Enter') { e.preventDefault(); this.blur(); } });
  $('refHint').addEventListener('click', function(e){
    var b = e.target.closest('[data-ref]'); if (!b) return;
    $('ref').value = b.getAttribute('data-ref'); refResolve();
  });

  /* ---- název nové karty: upozornění na podobnou existující kartu ---- */
  $('nam').addEventListener('input', function(){ mark(this, ''); this.classList.add('newm'); nameCheck(); invalidate(); });
  function nameCheck(){
    var k = nameKey($('nam').value), h = $('newHint');
    if (k.length < 5) { h.className = 'hint warn'; h.textContent = 'REF není v číselníku – vyplň kartu nového materiálu.'; return; }
    var hit = DATA.karty.filter(function(x){ return nameKey(x[1]).indexOf(k) >= 0; }).slice(0, 3);
    if (!hit.length) { h.className = 'hint warn'; h.textContent = 'REF není v číselníku – vyplň kartu nového materiálu.'; return; }
    h.className = 'hint warn';
    h.innerHTML = 'Podobný název už v číselníku je – není to stejný materiál?<br>'
      + hit.map(function(x){ return '<button type="button" data-ref="' + esc(x[0]) + '">' + esc(x[0]) + ' – ' + esc(String(x[1]).slice(0, 40)) + '</button>'; }).join('');
  }
  $('newHint').addEventListener('click', function(e){
    var b = e.target.closest('[data-ref]'); if (!b) return;
    $('ref').value = b.getAttribute('data-ref'); refResolve();
  });

  /* ---- našeptávač výrobce / dodavatele / skupiny ---- */
  var SG = { inp:null, list:[], idx:-1 };
  function sgK(inp){ return inp.getAttribute('data-sg') || inp.id; }
  function sgSource(id){
    if (id === 'vyr') return DATA.vyrobci.map(function(x){ return [x, '']; });
    if (id === 'dod') return DATA.dodavatele.map(function(x){ return [x, '']; });
    return DATA.skupiny.map(function(x){ return [x[0], x[1] + ' karet']; });
  }
  function sgPart(inp){
    var v = inp.value; if (sgK(inp) !== 'dod') return { pre:'', q:v };
    var p = v.lastIndexOf(','); return p < 0 ? { pre:'', q:v } : { pre:v.slice(0, p + 1) + ' ', q:v.slice(p + 1) };
  }
  function sgOpen(inp){
    var part = sgPart(inp), k = nameKey(part.q), el = $('sugg');
    var hit = sgSource(sgK(inp)).filter(function(x){ return !k || nameKey(x[0]).indexOf(k) >= 0; });
    hit.sort(function(a, b){ return (nameKey(a[0]).indexOf(k) === 0 ? 0 : 1) - (nameKey(b[0]).indexOf(k) === 0 ? 0 : 1) || a[0].localeCompare(b[0], 'cs'); });
    SG.inp = inp; SG.list = hit.slice(0, 12); SG.idx = SG.list.length && k ? 0 : -1;
    if (!SG.list.length && !k) { sgClose(); return; }
    var what = sgK(inp) === 'vyr' ? 'výrobce' : (sgK(inp) === 'dod' ? 'dodavatel' : 'skupina');
    el.innerHTML = SG.list.map(function(x, i){ return '<button type="button" data-i="' + i + '"' + (i === SG.idx ? ' class="on"' : '') + '>' + esc(x[0]) + (x[1] ? '<small>' + esc(x[1]) + '</small>' : '') + '</button>'; }).join('')
      + (k && !SG.list.length ? '<div class="nw">' + (what === 'skupina' ? 'nová skupina – založ ji jen po domluvě' : 'nový ' + what + ' – zkontroluj zápis') + '</div>' : '');
    el.hidden = false;
    var a = $('app').getBoundingClientRect(), b = inp.getBoundingClientRect();
    el.style.width = b.width + 'px'; el.style.top = (b.bottom - a.top + 2) + 'px'; el.style.left = (b.left - a.left) + 'px';
  }
  function sgClose(){ $('sugg').hidden = true; SG.inp = null; SG.list = []; SG.idx = -1; }
  function sgPick(i){ var inp = SG.inp, x = SG.list[i]; if (!inp || !x) return; inp.value = sgPart(inp).pre + x[0]; mark(inp, ''); sgClose(); invalidate(); }
  function sgCanon(inp){
    if (sgK(inp) === 'skup') { inp.value = inp.value.toUpperCase().replace(/\s+/g, '-').replace(/[^A-Z0-9-]/g, ''); return; }
    var src = sgSource(sgK(inp)).map(function(x){ return x[0]; });
    var parts = sgK(inp) === 'dod' ? inp.value.split(',') : [inp.value];
    inp.value = parts.map(function(x){
      var k = nameKey(x); if (!k) return '';
      for (var i = 0; i < src.length; i++) if (nameKey(src[i]) === k) return src[i];
      return x.trim().replace(/\s+/g, ' ');
    }).filter(Boolean).join(', ');
  }
  ['vyr','dod','skup','eVyr','eDod','eSkup'].forEach(function(id){
    var inp = $(id), ed = id.charAt(0) === 'e';
    inp.addEventListener('focus', function(){ sgOpen(inp); });
    inp.addEventListener('input', function(){ mark(inp, ''); sgOpen(inp); if (ed) eSync(); else invalidate(); });
    inp.addEventListener('keydown', function(e){
      if ($('sugg').hidden || SG.inp !== inp) return;
      var n = SG.list.length;
      if (e.key === 'ArrowDown' && n) { e.preventDefault(); SG.idx = (SG.idx + 1) % n; }
      else if (e.key === 'ArrowUp' && n) { e.preventDefault(); SG.idx = (SG.idx - 1 + n) % n; }
      else if ((e.key === 'Enter' || e.key === 'Tab') && SG.idx >= 0) { if (e.key === 'Enter') e.preventDefault(); sgPick(SG.idx); return; }
      else if (e.key === 'Escape') { sgClose(); return; }
      else return;
      Array.prototype.forEach.call($('sugg').querySelectorAll('button'), function(b, i){ b.classList.toggle('on', i === SG.idx); });
    });
    inp.addEventListener('blur', function(){ setTimeout(function(){ if (SG.inp === inp) sgClose(); sgCanon(inp); if (ed) eSync(); }, 150); });
  });
  $('sugg').addEventListener('mousedown', function(e){ e.preventDefault(); });
  $('sugg').addEventListener('click', function(e){ var b = e.target.closest('[data-i]'); if (b) sgPick(parseInt(b.getAttribute('data-i'), 10)); });

  /* ---- dodávka ---- */
  $('lot').addEventListener('input', function(){ mark(this, ''); invalidate(); });
  $('lot').addEventListener('blur', function(){ this.value = this.value.replace(/\u00a0/g, ' ').trim(); });
  $('exp').addEventListener('input', function(){ mark(this, ''); invalidate(); });
  $('q').addEventListener('input', function(){ this.value = this.value.replace(/[^0-9]/g, '').slice(0, 2); mark(this, ''); invalidate(); });
  $('jed').addEventListener('input', function(){ this.value = this.value.replace(/,/g, '.').replace(/[^0-9.]/g, ''); mark(this, ''); invalidate(); });

  /* ---- kontrola ---- */
  function today(){ var d = new Date(); return new Date(d.getFullYear(), d.getMonth(), d.getDate()); }
  function dParse(s){ var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s || ''); return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null; }
  function dFmt(d){ return d ? d.getDate() + '. ' + (d.getMonth() + 1) + '. ' + d.getFullYear() : '—'; }
  function collect(){
    var r = { oper:segVal('oper'), ref:refClean($('ref').value), lot:$('lot').value.replace(/\u00a0/g, ' ').trim(),
              exp:$('exp').value, q:parseInt($('q').value, 10) || 0, err:[], warn:[] };
    if (S.newMat) {
      sgCanon($('vyr')); sgCanon($('dod')); sgCanon($('skup'));
      r.mn = { ref:r.ref, nazev:$('nam').value.trim(), kat:segVal('kat1') + segVal('kat2'), vyrobce:$('vyr').value.trim(),
               dodavatel:$('dod').value.trim(), forma:segVal('forma'), jednotek:segVal('forma') === 'ks' ? '1' : $('jed').value,
               skupina:$('skup').value.trim(), odpis:segVal('odpis') };
      r.forma = r.mn.forma; r.jed = r.mn.jednotek; r.nazev = r.mn.nazev;
    } else if (S.mat) {
      r.nazev = S.mat.nazev;
      if (formaOf(S.mat.forma)) { r.forma = formaOf(S.mat.forma); r.jed = r.forma === 'ks' ? '1' : String(S.mat.jednotek || ''); }
      else { r.forma = segVal('forma'); r.jed = r.forma === 'ks' ? '1' : $('jed').value; r.matFill = { forma:r.forma, jednotek:r.jed }; }
    }
    return r;
  }
  function validate(r){
    var E = r.err, W = r.warn;
    if (!r.oper) { E.push('Nahoře přepínačem H / M vyber, kdo materiál zakládá.'); $('oper').classList.add('bad'); }
    if (!r.ref) { E.push('Chybí REF.'); mark($('ref'), 'bad'); }
    else if (!S.mat && !S.newMat) E.push('REF ještě nebyl ověřen v číselníku.');
    if (S.newMat && r.mn) {
      var mn = r.mn;
      if (!mn.nazev) { E.push('Nová karta: chybí název.'); mark($('nam'), 'bad'); }
      if (!segVal('kat1')) { E.push('Nová karta: vyber kategorii F / R / O / U.'); $('kat1').classList.add('bad'); }
      if (!segVal('kat2')) { E.push('Nová karta: vyber hlavní (M) nebo pomocný (S) materiál.'); $('kat2').classList.add('bad'); }
      if (!mn.vyrobce) { E.push('Nová karta: chybí výrobce.'); mark($('vyr'), 'bad'); }
      if (!mn.forma) { E.push('Nová karta: vyber formu kus / balení.'); $('forma').classList.add('bad'); }
      if (mn.forma === 'bal' && !(parseFloat(mn.jednotek) > 0)) { E.push('Nová karta: doplň počet kusů v 1 balení.'); mark($('jed'), 'bad'); }
      if (!mn.odpis) { E.push('Nová karta: vyber odpis auto / ruční.'); $('odpis').classList.add('bad'); }
      if (mn.skupina && !/^SK-[A-Z0-9-]+$/.test(mn.skupina)) { E.push('Skupina musí začínat SK- (např. SK-ORTO-POLYMER).'); mark($('skup'), 'bad'); }
      if (!mn.dodavatel) W.push('Nová karta nemá dodavatele.');
      if (mn.vyrobce && DATA.vyrobci.length && !DATA.vyrobci.some(function(v){ return nameKey(v) === nameKey(mn.vyrobce); })) W.push('Výrobce „' + mn.vyrobce + '“ zatím v číselníku není – je zapsaný správně?');
      if (mn.skupina && !DATA.skupiny.some(function(s){ return s[0] === mn.skupina; })) W.push('Skupina ' + mn.skupina + ' zatím neexistuje – zakládá se nová skupina.');
      if (mn.odpis === 'auto' && mn.forma === 'ks') W.push('Kusový materiál s automatickým odpisem – kusové komponenty (šrouby, implantáty) se odepisují ručně.');
      var sim = similarRefs(r.ref);
      if (sim.same.length) W.push('REF ' + sim.same[0][0] + ' už v číselníku je zapsaný jinak (' + sim.same[0][1] + ') – zakládá se druhá karta.');
      else if (sim.near.length) W.push('Podobný REF ' + sim.near[0][0] + ' (' + sim.near[0][1] + ') už v číselníku je – nejde o překlep?');
    }
    if (r.matFill) {
      if (!r.forma) { E.push('Vyber formu kus / balení – karta ji zatím nemá.'); $('forma').classList.add('bad'); }
      else if (r.forma === 'bal' && !(parseFloat(r.jed) > 0)) { E.push('Doplň počet kusů v 1 balení.'); mark($('jed'), 'bad'); }
      else W.push('Do karty materiálu se doplní forma: ' + (r.forma === 'ks' ? 'kus' : 'balení po ' + n2(r.jed) + ' ks') + '.');
    }
    if (S.mat && formaOf(S.mat.forma) === 'bal' && !(parseFloat(S.mat.jednotek) > 0)) E.push('Karta materiálu nemá počet kusů v balení – dej vědět vedoucímu.');
    if (S.mat && !S.mat.odpis) W.push('Karta materiálu nemá nastavený odpis (auto / ruční) – dej vědět vedoucímu, materiál se ale založí.');
    if (!r.lot) { E.push('Chybí LOT (šarže z obalu).'); mark($('lot'), 'bad'); }
    else {
      if (refKey(r.lot) === refKey(r.ref)) { W.push('LOT je stejný jako REF – nepřepsal se omylem REF?'); mark($('lot'), 'warn'); }
      if (r.lot.length < 3) { W.push('LOT je nezvykle krátký.'); mark($('lot'), 'warn'); }
      if (/\s/.test(r.lot)) W.push('LOT obsahuje mezeru – opiš ho přesně jako na obalu.');
    }
    var ex = dParse(r.exp), t = today();
    if (!r.exp) { W.push('Není vyplněná expirace – materiál bez expirace?'); mark($('exp'), 'warn'); }
    else if (!ex) { E.push('Expirace má neplatné datum.'); mark($('exp'), 'bad'); }
    else if (ex < t) { E.push('Expirace ' + dFmt(ex) + ' už uplynula – prošlý materiál se nezakládá.'); mark($('exp'), 'bad'); }
    else if ((ex - t) / 864e5 < 90) { W.push('Expirace ' + dFmt(ex) + ' je za méně než 3 měsíce.'); mark($('exp'), 'warn'); }
    else if (ex.getFullYear() > t.getFullYear() + 15) { W.push('Expirace ' + dFmt(ex) + ' je víc než 15 let dopředu – je rok zadaný správně?'); mark($('exp'), 'warn'); }
    if (r.q < 1 || r.q > 20) { E.push('Počet musí být 1–20 (víc najednou rozděl do dvou příjmů).'); mark($('q'), 'bad'); }
    else if (r.q > 10) W.push('Zakládá se ' + r.q + ' DID najednou – sedí počet?');
  }
  function lotDup(r){                               /* stejný REF + LOT už na skladě / ve výrobě */
    if (!r.lot || !r.ref) return Promise.resolve();
    return getJSON('?lotq=' + encodeURIComponent(r.lot)).then(function(d){
      var hit = ((d && d.items) || []).filter(function(x){ return refClean(x.ref) === r.ref && String(x.lot).trim().toUpperCase() === r.lot.toUpperCase(); });
      if (hit.length) r.warn.push('Šarže ' + r.lot + ' tohoto materiálu už je založená (' + hit.map(function(x){ return x.did + ' ' + String(x.status).toLowerCase(); }).join(', ') + '). Jde o novou dodávku, ne o opakované založení?');
    });
  }
  function review(r){
    var rows = [
      ['zakládá', (DATA.osoby.filter(function(o){ return o[0] === r.oper; })[0] || [r.oper, r.oper])[1]],
      ['REF', r.ref + (S.newMat ? ' (nová karta)' : '')],
      ['název', r.nazev || '—']
    ];
    if (S.newMat && r.mn) {
      rows.push(['kategorie', r.mn.kat || '—']);
      rows.push(['výrobce / dodavatel', (r.mn.vyrobce || '—') + ' / ' + (r.mn.dodavatel || '—')]);
      rows.push(['skupina / odpis', (r.mn.skupina || 'samostatný') + ' / ' + (r.mn.odpis || '—')]);
    }
    rows.push(['LOT', r.lot || '—']);
    rows.push(['expirace', r.exp ? dFmt(dParse(r.exp)) : 'bez expirace']);
    rows.push(['založí se', r.q + ' × ' + (r.forma === 'ks' ? 'kus' : 'balení po ' + n2(r.jed) + ' ks') + ' = ' + r.q + ' DID']);
    $('sumt').innerHTML = rows.map(function(x){ return '<tr><td>' + esc(x[0]) + '</td><td>' + esc(x[1]) + '</td></tr>'; }).join('');
    $('msgs').innerHTML = r.err.map(function(m){ return '<div class="msg bad"><span class="x">!</span><span>' + esc(m) + '</span></div>'; }).join('')
      + r.warn.map(function(m, i){ return '<label class="msg warn"><input type="checkbox" data-w="' + i + '"><span>' + esc(m) + ' <b>Potvrzuji.</b></span></label>'; }).join('');
    $('review').hidden = false; goSync();
    $('review').scrollIntoView({ behavior:'smooth', block:'nearest' });
  }
  function goSync(){
    var bad = $('msgs').querySelector('.msg.bad'), open = $('msgs').querySelectorAll('input[data-w]:not(:checked)').length;
    $('go').disabled = !!bad || open > 0 || S.busy;
    $('go').textContent = bad ? 'nejdřív oprav chyby' : (open ? 'potvrď upozornění (' + open + ')' : 'založit na sklad');
  }
  $('msgs').addEventListener('change', goSync);
  var LAST = null;
  $('check').addEventListener('click', function(){
    sgClose(); $('done').hidden = true;
    var r = collect(); validate(r); LAST = r;
    $('check').disabled = true; $('check').textContent = 'kontroluji…';
    lotDup(r).then(function(){ $('check').disabled = false; $('check').textContent = 'zkontrolovat'; review(r); });
  });
  $('back').addEventListener('click', function(){ invalidate(); window.scrollTo({ top:0, behavior:'smooth' }); });

  /* ---- založení ---- */
  $('go').addEventListener('click', function(){
    var r = LAST; if (!r || S.busy) return;
    S.busy = true; goSync(); $('go').textContent = 'zakládám…';
    var items = [];
    for (var i = 0; i < r.q; i++) items.push({ ref:r.ref, q:r.forma === 'ks' ? '1' : r.jed, lot:r.lot, exp:r.exp, forma:r.forma, tuse:'', stav:'ZALOŽENO', md:false });
    post({ action:'lotCreate', operator:r.oper, src:'prijem', items:items, matNew:S.newMat ? r.mn : null, matFill:r.matFill || null }).then(function(d){
      S.busy = false;
      if (!d || !d.ok) { $('msgs').insertAdjacentHTML('afterbegin', '<div class="msg bad"><span class="x">!</span><span>Založení se nepovedlo – ' + esc((d && d.error) || 'server neodpověděl') + '. Nic se nezapsalo, zkus to znovu.</span></div>'); goSync(); return; }
      if (S.newMat && r.mn) DATA.karty.push([r.ref, r.mn.nazev, r.mn.skupina, r.mn.odpis]);
      if (r.matFill && S.mat) { S.mat.forma = r.forma === 'ks' ? 'kus' : 'balení'; S.mat.jednotek = r.jed; }
      var ds = (d.items || []).map(function(x){ return x.did; });
      $('done').innerHTML = '<div class="t">Založeno na sklad – ' + ds.length + ' DID</div>'
        + '<div class="s">' + esc(r.nazev) + ' · REF ' + esc(r.ref) + ' · LOT ' + esc(r.lot) + (r.exp ? ' · exp. ' + esc(dFmt(dParse(r.exp))) : '') + (d.matRow && S.newMat ? ' · karta MAT řádek ' + d.matRow : '') + '</div>'
        + '<div class="dids">' + ds.map(function(x){ return '<b>' + esc(x) + '</b>'; }).join('') + '</div>'
        + '<div class="lblbar"><span class="lab">páska</span><div class="seg" id="tape">'
        + [9, 12, 24].map(function(w){ return '<button type="button" data-v="' + w + '" aria-pressed="' + (w === LBL.tape ? 'true' : 'false') + '">' + w + ' mm</button>'; }).join('')
        + '</div><button type="button" class="btn pri" id="lblPrint">tisk štítků (' + ds.length + ')</button></div>'
        + '<div class="lblprev" id="lblPrev"></div>'
        + '<div class="s">Štítek nalep na každé balení (kus). Čárový kód obsahuje DID.</div>'
        + '<div class="nacts"><button type="button" class="btn ghost" id="again">další šarže stejného materiálu</button><button type="button" class="btn pri" id="fresh">nový materiál</button></div>';
      LBL.items = ds.map(function(x){ return { did:x, nazev:r.nazev, ref:r.ref, lot:r.lot, exp:r.exp ? dFmt(dParse(r.exp)) : '' }; });
      var nowS = new Date(), zs = nowS.getDate() + '.' + (nowS.getMonth() + 1) + '.' + nowS.getFullYear() + ' ' + nowS.getHours() + ':' + ('0' + nowS.getMinutes()).slice(-2);
      (d.items || []).slice().reverse().forEach(function(x){
        DATA.zapsane.unshift({ did:x.did, ref:r.ref, nazev:r.nazev, lot:r.lot, exp:r.exp || '', q:x.q, jed:r.forma === 'ks' ? 'ks' : 'bal', zal:zs, kdo:r.oper, lze:true, _new:true });
      });
      zRender();
      lblPreview();
      $('review').hidden = true; $('done').hidden = false;
      $('done').scrollIntoView({ behavior:'smooth', block:'nearest' });
    });
  });
  function resetDelivery(){ $('lot').value = ''; $('exp').value = ''; $('q').value = '1'; ['lot','exp','q'].forEach(function(id){ mark($(id), ''); }); }
  $('done').addEventListener('click', function(e){
    var tb = e.target.closest('#tape button');
    if (tb) { LBL.tape = +tb.getAttribute('data-v'); segSet('tape', String(LBL.tape)); try { localStorage.setItem('prijemTape', String(LBL.tape)); } catch(er){} lblPreview(); return; }
    if (e.target.id === 'lblPrint') { lblPrint(); return; }
    if (e.target.id === 'again') { $('done').hidden = true; resetDelivery(); refResolve(); $('lot').focus(); }
    if (e.target.id === 'fresh') {
      $('done').hidden = true; resetDelivery(); $('ref').value = '';
      ['vyr','dod','skup','jed'].forEach(function(id){ $(id).value = ''; }); ['kat1','kat2','forma','odpis'].forEach(function(id){ segSet(id, ''); });
      refResolve(); window.scrollTo({ top:0, behavior:'smooth' }); $('ref').focus();
    }
  });

  /* ---- štítky Brother P-touch ----
     Tiskne se přes ovladač tiskárny (P-touch připojená k počítači): v dialogu tisku zvol tiskárnu Brother,
     velikost stránky = šířka pásky. Každé DID = jedna stránka = jeden štítek.
     Rozměr štítku: výška = šířka pásky, délka 80 mm (9 mm páska) / 70 mm. Čárový kód Code 128 (sada B) s DID. */
  var LBL = { tape:9, items:[], len:70 };
  try { var tp = +localStorage.getItem('prijemTape'); if ([9, 12, 24].indexOf(tp) >= 0) LBL.tape = tp; } catch(e){}
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
  function lblLen(){ return LBL.tape <= 9 ? 80 : 70; }
  function lblHtml(it){
    LBL.len = lblLen();
    /* tisková výška PT-P750W: 9 mm páska 7,1 mm, 12 mm 9,9 mm, 24 mm 18 mm */
    var H = LBL.tape, pad = H <= 9 ? 1.0 : (H <= 12 ? 1.2 : 3.0), inner = H - 2 * pad, narrow = H <= 12;
    var did = H <= 9 ? 4.2 : (H <= 12 ? 4.6 : 6.4), small = H <= 9 ? 1.8 : (H <= 12 ? 1.75 : 2.7);
    var lines = H <= 9 ? [it.nazev, 'LOT ' + it.lot + (it.exp ? ' · EXP ' + it.exp : '')]
              : narrow ? [it.nazev, 'REF ' + it.ref + ' · LOT ' + it.lot, it.exp ? 'EXP ' + it.exp : '']
                       : [it.nazev, 'REF ' + it.ref, 'LOT ' + it.lot, it.exp ? 'EXP ' + it.exp : ''];
    var txt = '<div class="lt" style="font-size:' + small + 'mm;line-height:1.2">' + lines.filter(Boolean).map(function(l, i){ return '<div' + (i === 0 ? ' style="font-weight:700"' : '') + '>' + esc(l) + '</div>'; }).join('') + '</div>';
    var box = 'width:' + LBL.len + 'mm;height:' + H + 'mm;padding:' + pad + 'mm ' + (pad + 0.8) + 'mm;gap:' + (narrow ? 1.6 : 2.6) + 'mm';
    if (narrow) return '<div class="lbl" style="' + box + '"><div class="ld" style="font-size:' + did + 'mm;line-height:1">' + esc(it.did) + '</div>'
      + c128svg(it.did, inner, MOD) + txt + '</div>';
    var bcH = inner - did * 1.05 - 0.8;                /* širší páska: DID nad čárovým kódem, text vpravo */
    return '<div class="lbl" style="' + box + '"><div style="flex:none;display:flex;flex-direction:column;align-items:center;gap:0.8mm">'
      + '<div class="ld" style="font-size:' + did + 'mm;line-height:1.05">' + esc(it.did) + '</div>'
      + c128svg(it.did, bcH, MOD) + '</div>' + txt + '</div>';
  }
  function lblPreview(){
    var el = $('lblPrev'); if (!el || !LBL.items.length) return;
    el.innerHTML = lblHtml(LBL.items[0]);
  }
  function lblPrint(){
    if (!LBL.items.length) return;
    LBL.len = lblLen();
    $('pageSize').textContent = '@page{size:' + LBL.len + 'mm ' + LBL.tape + 'mm;margin:0}';
    $('print').innerHTML = LBL.items.map(lblHtml).join('');
    window.print();
  }

  /* ---- seznam zapsaných příjmů: štítek / upravit / smazat ----
     Upravit a smazat jde jen u balení na skladě (ZALOŽENO), které ještě nikdo nepoužil.
     Smazání vymaže obsah řádku v LOT, řádek zůstává; DID se znovu nepřidělí. */
  var Z = { edit:'', ask:'', err:{}, busy:'' };
  function zFind(did){ return DATA.zapsane.filter(function(x){ return x.did === did; })[0]; }
  function zName(it){
    if (it.nazev) return it.nazev;
    var k = DATA.karty.filter(function(c){ return refClean(c[0]) === refClean(it.ref); })[0];
    return k ? k[1] : it.ref;
  }
  function zRender(){
    var L = DATA.zapsane;
    $('zState').textContent = 'posledních 30 dní · na skladě' + (L.length ? ' · ' + L.length : '');
    if (!L.length) { $('zList').innerHTML = '<div class="zempty">Zatím nic. Po založení se tu balení objeví i s DID a tiskem štítku.</div>'; return; }
    $('zList').innerHTML = L.map(function(it){
      var d = it.did, busy = Z.busy === d, kdo = (DATA.osoby.filter(function(o){ return o[0] === it.kdo; })[0] || [0, it.kdo || ''])[1];
      var l2 = ['LOT ' + it.lot, 'EXP ' + (it.exp ? dFmt(dParse(it.exp)) : '—'),
                it.jed === 'ks' ? 'kus' : 'bal. ' + (it.q == null ? '?' : it.q) + ' ks', it.zal, kdo, 'REF ' + it.ref].filter(Boolean).join(' · ');
      var acts = '<button type="button" class="btn" data-a="lbl" data-d="' + esc(d) + '" title="vytisknout štítek pro toto balení">štítek</button>'
        + (it.lze ? '<button type="button" class="btn" data-a="edit" data-d="' + esc(d) + '"' + (busy ? ' disabled' : '') + '>upravit</button>'
                  + '<button type="button" class="btn del" data-a="ask" data-d="' + esc(d) + '"' + (busy ? ' disabled' : '') + '>smazat</button>'
                  : '<span class="lock" title="balení už bylo použito – opravu udělá technik v Trellu">použito</span>');
      var h = '<div class="zr' + (it._new ? ' new' : '') + '" data-d="' + esc(d) + '"><div class="l1"><span class="did">' + esc(d) + '</span><span class="nm">' + esc(zName(it)) + '</span><span class="acts">' + acts + '</span></div>'
        + '<div class="l2">' + esc(l2) + '</div>';
      if (Z.edit === d) {
        h += '<div class="ed"><input class="f hi grow" id="zLot" value="' + esc(it.lot) + '" placeholder="LOT" autocomplete="off" spellcheck="false">'
          + '<input class="f hi c4" id="zExp" type="date" value="' + esc(it.exp || '') + '">'
          + (it.jed === 'ks' ? '' : '<label class="fsx c1"><input class="f hi" id="zQ" value="' + esc(it.q == null ? '' : it.q) + '" inputmode="decimal" autocomplete="off"><span>ks v 1 bal.</span></label>')
          + '<button type="button" class="btn ghost" data-a="cancel" data-d="' + esc(d) + '">zrušit</button>'
          + '<button type="button" class="btn pri" data-a="save" data-d="' + esc(d) + '"' + (busy ? ' disabled' : '') + '>' + (busy ? 'ukládám…' : 'uložit') + '</button></div>';
      }
      if (Z.ask === d) {
        h += '<div class="ask"><span>Smazat ' + esc(d) + '? Řádek v LOT se vyprázdní a DID se už nepoužije. Vyhoď i vytištěný štítek.</span>'
          + '<button type="button" class="btn ghost" data-a="cancel" data-d="' + esc(d) + '">ne</button>'
          + '<button type="button" class="btn del" data-a="del" data-d="' + esc(d) + '"' + (busy ? ' disabled' : '') + '>' + (busy ? 'mažu…' : 'ano, smazat') + '</button></div>';
      }
      if (Z.err[d]) h += '<div class="er">' + esc(Z.err[d]) + '</div>';
      return h + '</div>';
    }).join('');
  }
  function zLabel(it){ return { did:it.did, nazev:zName(it), ref:it.ref, lot:it.lot, exp:it.exp ? dFmt(dParse(it.exp)) : '' }; }
  $('zList').addEventListener('click', function(e){
    var b = e.target.closest('button[data-a]'); if (!b) return;
    var a = b.getAttribute('data-a'), d = b.getAttribute('data-d'), it = zFind(d); if (!it) return;
    delete Z.err[d];
    if (a === 'lbl') { LBL.items = [zLabel(it)]; lblPrint(); return; }
    if (a === 'edit') { Z.edit = d; Z.ask = ''; zRender(); var f = $('zLot'); if (f) f.focus(); return; }
    if (a === 'ask') { Z.ask = d; Z.edit = ''; zRender(); return; }
    if (a === 'cancel') { Z.edit = ''; Z.ask = ''; zRender(); return; }
    var oper = segVal('oper');
    if (!oper) { Z.err[d] = 'Nahoře přepínačem H / M vyber, kdo opravu dělá.'; zRender(); return; }
    var payload;
    if (a === 'save') {
      var lot = $('zLot').value.replace(/\u00a0/g, ' ').trim(), exp = $('zExp').value, q = $('zQ') ? $('zQ').value.replace(',', '.').trim() : '1';
      if (!lot) { Z.err[d] = 'LOT nesmí být prázdný.'; zRender(); return; }
      if (it.jed !== 'ks' && !(+q > 0)) { Z.err[d] = 'Počet ks v balení musí být kladné číslo.'; zRender(); return; }
      payload = { action:'lotCreate', op:'edit', operator:oper, did:d, lot:lot, exp:exp, q:q };
    } else if (a === 'del') {
      payload = { action:'lotCreate', op:'delete', operator:oper, did:d };
    } else return;
    Z.busy = d; zRender();
    post(payload).then(function(r){
      Z.busy = '';
      if (!r || !r.ok) { Z.err[d] = 'Nepovedlo se – ' + ((r && r.error) || 'server neodpověděl') + '.'; zRender(); return; }
      if (a === 'del') DATA.zapsane = DATA.zapsane.filter(function(x){ return x.did !== d; });
      else { it.lot = r.lot || lot; it.exp = exp; if (it.jed !== 'ks') it.q = r.q != null ? r.q : +q; }
      Z.edit = ''; Z.ask = ''; zRender();
    });
  });

  /* ---- nastavení materiálu (ozubené kolo) ----
     Hledání karet MAT podle REF a názvu (fulltext, bez diakritiky, víc slov = všechna musí sedět).
     Úprava přednastavení: název, kategorie, výrobce, dodavatel, forma + ks v 1 bal., odpis, skupina.
     REF existující karty se nemění. Cena, vzorce a údaj, kdo kartu založil, se v nastavení neukazují ani nemění.
     Ukládají se jen změněné údaje. Ikona + založí novou kartu bez příjmu balení. */
  var E = { mode:'', orig:null, busy:false };
  var E_POLE = { nazev:'název', kat:'kategorie', vyrobce:'výrobce', dodavatel:'dodavatel', forma:'forma', jednotek:'ks v 1 bal.', odpis:'odpis', skupina:'skupina' };
  function setOpen(on){
    $('setView').hidden = !on; $('formView').hidden = on; $('gear').setAttribute('aria-pressed', on ? 'true' : 'false');
    sgClose();
    if (on) { sSearch(); setTimeout(function(){ $('sQ').focus(); }, 0); }
  }
  $('gear').addEventListener('click', function(){ setOpen($('setView').hidden); });

  function sSearch(){
    var words = $('sQ').value.split(' ').map(nameKey).filter(Boolean);
    if (!words.length) { $('sList').innerHTML = ''; $('sState').textContent = DATA.karty.length ? DATA.karty.length + ' karet' : ''; return; }
    var q1 = refKey($('sQ').value), hit = [];
    DATA.karty.forEach(function(x){
      var hay = nameKey(x[0]) + ' ' + nameKey(x[1]);
      if (!words.every(function(w){ return hay.indexOf(w) >= 0; })) return;
      var rk = refKey(x[0]);
      hit.push({ x:x, s: rk === q1 ? 0 : (rk.indexOf(q1) === 0 ? 1 : (nameKey(x[0]).indexOf(words[0]) >= 0 ? 2 : 3)) });
    });
    hit.sort(function(a, b){ return a.s - b.s || String(a.x[1]).localeCompare(String(b.x[1]), 'cs'); });
    $('sState').textContent = hit.length + ' z ' + DATA.karty.length;
    if (!hit.length) { $('sList').innerHTML = '<div class="zempty">Nic nenalezeno. Novou kartu založíš ikonou +.</div>'; return; }
    var cur = E.orig ? refClean(E.orig.ref) : '';
    $('sList').innerHTML = hit.slice(0, 40).map(function(h){
      var x = h.x, l2 = [x[2] ? 'skupina ' + x[2] : 'samostatný', x[3] ? 'odpis ' + x[3] : 'odpis nenastaven'].join(' · ');
      return '<button type="button" class="zr sr' + (refClean(x[0]) === cur ? ' on' : '') + '" data-ref="' + esc(x[0]) + '"><div class="l1"><span class="did">' + esc(x[0]) + '</span><span class="nm">' + esc(x[1]) + '</span></div>'
        + '<div class="l2">' + esc(l2) + '</div></button>';
    }).join('') + (hit.length > 40 ? '<div class="zempty">Zobrazeno prvních 40 – upřesni hledání.</div>' : '');
  }
  $('sQ').addEventListener('input', sSearch);
  $('sList').addEventListener('click', function(e){ var b = e.target.closest('[data-ref]'); if (b) eOpen(b.getAttribute('data-ref')); });

  function eHint(cls, html){ $('eHint').className = 'hint' + (cls ? ' ' + cls : ''); $('eHint').innerHTML = html; }
  function eFill(c){
    $('eRef').value = c.ref || ''; $('eNam').value = c.nazev || '';
    var k = String(c.kat || '').toUpperCase();
    segSet('eKat1', /^[FROU]/.test(k) ? k.charAt(0) : ''); segSet('eKat2', /^.[MS]$/.test(k) ? k.charAt(1) : '');
    $('eVyr').value = c.vyrobce || ''; $('eDod').value = c.dodavatel || '';
    segSet('eForma', c.forma || ''); $('eJed').value = c.forma === 'bal' ? c.jednotek : '';
    segSet('eOdpis', c.odpis || ''); $('eSkup').value = c.skupina || '';
    ['eRef','eNam','eVyr','eDod','eJed','eSkup'].forEach(function(id){ mark($(id), ''); });
  }
  function eNorm(d){                                /* karta ze serveru → hodnoty editoru */
    var f = formaOf(d.forma), j = parseFloat(String(d.jednotek == null ? '' : d.jednotek).replace(',', '.'));
    return { ref:String(d.ref || '').trim(), nazev:String(d.nazev == null ? '' : d.nazev).trim(), kat:String(d.kat || '').trim().toUpperCase(),
             vyrobce:String(d.vyrobce || '').trim(), dodavatel:String(d.dodavatel || '').trim(), forma:f,
             jednotek:f === 'bal' && !isNaN(j) ? String(j) : (f === 'ks' ? '1' : ''), odpis:String(d.odpis || '').trim(), skupina:String(d.skupina || '').trim().toUpperCase() };
  }
  function eVals(){
    var f = segVal('eForma');
    return { ref:refClean($('eRef').value), nazev:$('eNam').value.trim(), kat:segVal('eKat1') + segVal('eKat2'), vyrobce:$('eVyr').value.trim(),
             dodavatel:$('eDod').value.trim(), forma:f, jednotek:f === 'ks' ? '1' : $('eJed').value.replace(',', '.').trim(),
             odpis:segVal('eOdpis'), skupina:$('eSkup').value.trim().toUpperCase() };
  }
  function eDiff(){                                 /* jen opravdu změněné údaje */
    var o = E.orig, v = eVals(), z = {};
    if (!o) return z;
    if (v.nazev !== o.nazev) z.nazev = v.nazev;
    if (v.kat.length === 2 && v.kat !== o.kat) z.kat = v.kat;
    if (v.vyrobce !== o.vyrobce) z.vyrobce = v.vyrobce;
    if (v.dodavatel !== o.dodavatel) z.dodavatel = v.dodavatel;
    if (v.forma && v.forma !== o.forma) z.forma = v.forma;
    if (v.forma === 'bal' && parseFloat(v.jednotek) !== parseFloat(o.jednotek)) z.jednotek = v.jednotek;
    if (v.odpis && v.odpis !== o.odpis) z.odpis = v.odpis;
    if (v.skupina !== o.skupina) z.skupina = v.skupina;
    return z;
  }
  function eErrors(v, z){
    var E2 = [], chk = function(k){ return E.mode === 'new' || z.hasOwnProperty(k); };
    if (E.mode === 'new') {
      if (!v.ref) { E2.push('Chybí REF.'); mark($('eRef'), 'bad'); }
      else if (DATA.karty.some(function(x){ return refClean(x[0]) === v.ref; })) { E2.push('REF ' + v.ref + ' už v číselníku je – otevři existující kartu.'); mark($('eRef'), 'bad'); }
      if (!v.kat || v.kat.length < 2) E2.push('Vyber kategorii F / R / O / U a M / S.');
      if (!v.forma) E2.push('Vyber formu kus / balení.');
      if (!v.odpis) E2.push('Vyber odpis auto / ruční.');
      if (!segVal('oper')) E2.push('Nahoře přepínačem H / M vyber, kdo kartu zakládá.');
    }
    if (chk('nazev') && !v.nazev) { E2.push('Název nesmí být prázdný.'); mark($('eNam'), 'bad'); }
    if (chk('vyrobce') && !v.vyrobce) { E2.push('Výrobce nesmí být prázdný.'); mark($('eVyr'), 'bad'); }
    if (v.forma === 'bal' && (chk('jednotek') || chk('forma')) && !(parseFloat(v.jednotek) > 0)) { E2.push('Doplň počet kusů v 1 balení.'); mark($('eJed'), 'bad'); }
    if (chk('skupina') && v.skupina && !/^SK-[A-Z0-9-]+$/.test(v.skupina)) { E2.push('Skupina musí začínat SK- (např. SK-ORTO-POLYMER).'); mark($('eSkup'), 'bad'); }
    return E2;
  }
  function eSync(){
    $('eJed').disabled = segVal('eForma') !== 'bal';
    if ($('eJed').disabled) $('eJed').value = '';
    if (E.busy) return;
    if (E.mode === 'new') { $('eSave').disabled = false; $('eSave').textContent = 'založit kartu'; return; }
    var z = Object.keys(eDiff());
    $('eSave').disabled = !z.length; $('eSave').textContent = z.length ? 'uložit (' + z.length + ')' : 'uložit';
    if (z.length) eHint('warn', 'Změněno: ' + z.map(function(k){ return E_POLE[k]; }).join(', ') + '. Ulož, nebo zavři bez uložení.' + (z.indexOf('jednotek') >= 0 ? '<br>Nový počet ks v balení platí pro nově zakládaná balení, už zapsaná se nemění.' : ''));
    else if (/warn/.test($('eHint').className)) eHint('', '');
  }
  ['eKat1','eKat2','eForma','eOdpis'].forEach(function(id){
    $(id).addEventListener('click', function(e){ var b = e.target.closest('button'); if (!b) return; segSet(id, b.getAttribute('data-v')); eSync(); });
  });
  ['eRef','eNam','eJed'].forEach(function(id){ $(id).addEventListener('input', function(){ mark(this, ''); if (id === 'eJed') this.value = this.value.replace(/,/g, '.').replace(/[^0-9.]/g, ''); eSync(); }); });
  $('eRef').addEventListener('blur', function(){ if (!this.readOnly) this.value = refClean(this.value); });

  function eShow(mode){
    E.mode = mode; $('eBand').hidden = false;
    var ro = mode === 'edit', r = $('eRef');
    r.readOnly = ro; r.tabIndex = ro ? -1 : 0; r.classList.toggle('lk', ro); r.classList.toggle('hi', !ro);
    $('eTitle').textContent = mode === 'new' ? 'Nová karta materiálu' : 'Karta materiálu';
  }
  function eOpen(ref){
    eShow('edit'); E.orig = null; E.busy = false;
    eFill({ ref:ref, nazev:'načítám kartu…' }); $('eState').textContent = ''; eHint('', ''); $('eSave').disabled = true;
    sSearch();
    getJSON('?matref=' + encodeURIComponent(ref)).then(function(d){
      if (!d || d.nazev == null) { eHint('bad', 'Kartu se nepodařilo načíst.'); return; }
      E.orig = eNorm(d); eFill(E.orig); sSearch();
      $('eState').textContent = 'REF se nemění';
      eSync();
      $('eBand').scrollIntoView({ behavior:'smooth', block:'nearest' });
    });
  }
  $('sNew').addEventListener('click', function(){
    eShow('new'); E.orig = null; E.busy = false;
    var q = refClean($('sQ').value);
    eFill({ ref: q && /[0-9]/.test(q) && q.indexOf(' ') < 0 ? q : '' });
    $('eState').textContent = 'zakládá: ' + ((DATA.osoby.filter(function(o){ return o[0] === segVal('oper'); })[0] || [0, 'vyber H / M nahoře'])[1]);
    eHint('', 'Vyplň kartu nového materiálu. Balení se tu nezakládají – to dělá příjem.');
    eSync(); $('eBand').scrollIntoView({ behavior:'smooth', block:'nearest' }); $($('eRef').value ? 'eNam' : 'eRef').focus();
  });
  $('eCancel').addEventListener('click', function(){ $('eBand').hidden = true; E.mode = ''; E.orig = null; sgClose(); sSearch(); });

  function listAdd(arr, v){ v = String(v || '').trim(); if (v && !arr.some(function(x){ return nameKey(x) === nameKey(v); })) { arr.push(v); arr.sort(function(a, b){ return a.localeCompare(b, 'cs'); }); } }
  $('eSave').addEventListener('click', function(){
    if (E.busy) return;
    sgCanon($('eVyr')); sgCanon($('eDod')); sgCanon($('eSkup'));
    var v = eVals(), z = E.mode === 'edit' ? eDiff() : null, err = eErrors(v, z || {});
    if (err.length) { eHint('bad', err.map(esc).join('<br>')); return; }
    if (E.mode === 'edit' && !Object.keys(z).length) return;
    var payload = E.mode === 'new'
      ? { action:'lotCreate', op:'matNew', operator:segVal('oper'), matNew:{ ref:v.ref, nazev:v.nazev, kat:v.kat, vyrobce:v.vyrobce, dodavatel:v.dodavatel, forma:v.forma, jednotek:v.jednotek, skupina:v.skupina, odpis:v.odpis } }
      : { action:'lotCreate', op:'matEdit', ref:E.orig.ref, zmeny:z };
    E.busy = true; $('eSave').disabled = true; $('eSave').textContent = 'ukládám…';
    post(payload).then(function(r){
      E.busy = false;
      if (!r || !r.ok) { eHint('bad', 'Nepovedlo se – ' + esc((r && r.error) || 'server neodpověděl') + '. Nic se nezměnilo.'); eSync(); return; }
      listAdd(DATA.vyrobci, v.vyrobce); v.dodavatel.split(',').forEach(function(d){ listAdd(DATA.dodavatele, d); });
      if (v.skupina && !DATA.skupiny.some(function(g){ return g[0] === v.skupina; })) DATA.skupiny.push([v.skupina, 1]);
      if (E.mode === 'new') {
        DATA.karty.push([v.ref, v.nazev, v.skupina, v.odpis]);
        $('sQ').value = v.ref; eOpen(v.ref);
        setTimeout(function(){ eHint('good', 'Karta založena' + (r.matRow ? ' (MAT řádek ' + r.matRow + ')' : '') + '.'); }, 0);
        return;
      }
      var k = eNorm(r.karta || merge(E.orig, z));
      DATA.karty.forEach(function(x){ if (refClean(x[0]) === refClean(k.ref)) { x[1] = k.nazev; x[2] = k.skupina; x[3] = k.odpis; } });
      E.orig = k; eFill(k); sSearch();
      eHint('good', (r.zmeneno && r.zmeneno.length ? 'Uloženo: ' + r.zmeneno.map(function(f){ return E_POLE[f] || f; }).join(', ') : 'Beze změny – hodnoty už v kartě byly') + (r.row ? ' (MAT řádek ' + r.row + ').' : '.'));
      eSync();
      if (refClean($('ref').value) === refClean(k.ref)) refResolve();
    });
  });
  function merge(a, b){ var o = {}; Object.keys(a).forEach(function(k){ o[k] = a[k]; }); Object.keys(b).forEach(function(k){ o[k] = b[k]; }); return o; }

  /* ---- start ---- */
  function renderOper(){
    var saved = 'H'; try { saved = localStorage.getItem('prijemOper') || 'H'; } catch(e){}   /* výchozí H (Novák) */
    if (saved !== 'H' && saved !== 'M') saved = 'H';
    segSet('oper', saved);
  }
  function init(){
    if (DEMO) $('demoBar').hidden = false;
    getJSON('?matlists=prijem').then(function(d){
      d = d || {};
      ['karty','skupiny','osoby','vyrobci','dodavatele','zapsane'].forEach(function(k){ if (Array.isArray(d[k])) DATA[k] = d[k]; });
      renderOper(); chips(); zRender();
      var lb = $('loadBad');
      if (!d.vyrobci) { lb.textContent = 'Nepodařilo se spojit se serverem (Apps Script). Zkontroluj připojení a obnov stránku.'; lb.hidden = false; }
      else if (d.auth === false) { lb.textContent = 'Apps Script nezná klíč formuláře – ve Vlastnostech skriptu chybí nebo nesedí PRIJEM_KEY.'; lb.hidden = false; }
      else if (!Array.isArray(d.osoby) || !Array.isArray(d.zapsane)) { lb.textContent = 'Server běží se starou verzí Lot.gs – chybí seznam osob, karet a zapsaných příjmů. Vlož nový Lot.gs a nasaď novou verzi (Nasadit → Spravovat nasazení → Nová verze).'; lb.hidden = false; }
    });
  }

  /* ---- ukázkový režim (?demo=1): data z demo.json, nic se nezapisuje ---- */
  function demoLoad(){ return DEMO_DB ? Promise.resolve(DEMO_DB) : fetch('demo.json').then(function(r){ return r.json(); }).then(function(d){ DEMO_DB = d; return d; }); }
  function demoGet(qs){
    return demoLoad().then(function(db){
      var m;
      if (/^\?matlists=/.test(qs)) return db.lists;
      if ((m = /^\?matref=(.*)$/.exec(qs))) {
        var ref = refClean(decodeURIComponent(m[1]));
        var c = db.cards.filter(function(x){ return refClean(x.ref) === ref; })[0];
        return c || {};
      }
      if ((m = /^\?lotq=(.*)$/.exec(qs))) {
        var q = decodeURIComponent(m[1]).toUpperCase();
        return { items: db.lots.filter(function(x){ return String(x.lot).toUpperCase() === q; }) };
      }
      return null;
    });
  }
  var demoN = 0;
  function demoPost(p){
    if (p.op === 'matEdit') return demoLoad().then(function(db){
      var c = db.cards.filter(function(x){ return refClean(x.ref) === refClean(p.ref); })[0] || {};
      Object.keys(p.zmeny).forEach(function(k){ c[k] = k === 'forma' ? (p.zmeny[k] === 'ks' ? 'kus' : 'balení') : p.zmeny[k]; });
      if (c.forma === 'kus') c.jednotek = 1;
      return new Promise(function(res){ setTimeout(function(){ res({ ok:true, row:120, zmeneno:Object.keys(p.zmeny), karta:c }); }, 500); });
    });
    if (p.op === 'matNew') return demoLoad().then(function(db){
      var m = p.matNew; db.cards.push({ ref:m.ref, nazev:m.nazev, kat:m.kat, vyrobce:m.vyrobce, dodavatel:m.dodavatel, forma:m.forma === 'ks' ? 'kus' : 'balení', jednotek:m.jednotek, skupina:m.skupina, odpis:m.odpis });
      return new Promise(function(res){ setTimeout(function(){ res({ ok:true, matRow:463, ref:m.ref }); }, 500); });
    });
    if (p.op) return new Promise(function(res){ setTimeout(function(){ res({ ok:true, did:p.did, lot:p.lot, q:+p.q || 1 }); }, 500); });
    return new Promise(function(res){ setTimeout(function(){
      res({ ok:true, matRow: p.matNew ? 462 : null, items: p.items.map(function(x){ demoN++; return { did:'D' + (3600 + demoN).toString(36).toUpperCase(), q:x.forma === 'ks' ? 1 : (+x.q || 1) }; }) });
    }, 600); });
  }
  init();
})();
</script>
</body>
</html>
