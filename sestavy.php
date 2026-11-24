<?php
/* Tiskové sestavy – jednoduchá stránka pro recepci (krok 32). Vzhled dodá uživatel. */
require __DIR__ . '/prijem-auth.php';
prijem_nocache();
if (!prijem_logged()) { header('Location: prijem-login.php'); exit; }
$m = (int) date('n') - 1; $r = (int) date('y'); if ($m < 1) { $m = 12; $r--; }   // výchozí = minulý měsíc
?><!doctype html>
<html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Tiskové sestavy</title>
<style>
body{font:15px/1.4 system-ui,sans-serif;max-width:560px;margin:24px auto;padding:0 12px}
fieldset{border:1px solid #ccc;margin:0 0 16px;padding:10px 12px}
label{display:inline-block;margin:4px 12px 4px 0}
input{font:inherit;padding:4px 6px}
input.k{width:4em;text-transform:uppercase}
button{font:inherit;padding:5px 14px}
.foot{color:#777;font-size:13px}
</style></head><body>
<h1>Tiskové sestavy</h1>
<form action="sestavy-api.php" target="_blank">
  <fieldset><legend>Odměny technika (E-MZD)</legend>
    <input type="hidden" name="typ" value="mzd">
    <label>Technik <input class="k" name="kdo" maxlength="1" required pattern="[A-Za-z]"></label>
    <label>Měsíc <input name="mesic" type="number" min="1" max="12" value="<?= $m ?>" required></label>
    <label>Rok <input name="rok" type="number" min="20" max="99" value="<?= $r ?>" required></label>
    <button>PDF</button>
  </fieldset>
</form>
<form action="sestavy-api.php" target="_blank">
  <fieldset><legend>Příloha faktury (E-PVF)</legend>
    <input type="hidden" name="typ" value="pvf">
    <label>Ordinace <input class="k" name="kdo" maxlength="3" required pattern="[A-Za-z0-9]{3}"></label>
    <label>Měsíc <input name="mesic" type="number" min="1" max="12" value="<?= $m ?>" required></label>
    <label>Rok <input name="rok" type="number" min="20" max="99" value="<?= $r ?>" required></label>
    <button>PDF</button>
  </fieldset>
</form>
<form action="sestavy-api.php" target="_blank">
  <fieldset><legend>Kalkulace (F-NAB)</legend>
    <input type="hidden" name="typ" value="nabidka">
    <label>Kód zakázky <input name="tag" required pattern="[A-Za-z0-9]{6,20}"></label>
    <button>PDF</button>
  </fieldset>
</form>
<div class="foot">Data se berou z EVIDENCE v okamžiku tisku. Řádky označené jako nabídka se do odměn ani přílohy faktury nepočítají. · <a href="prijem-login.php?odhlasit=1">odhlásit</a></div>
<script>document.querySelectorAll('input.k').forEach(i=>i.addEventListener('input',()=>i.value=i.value.toUpperCase()));</script>
</body></html>
