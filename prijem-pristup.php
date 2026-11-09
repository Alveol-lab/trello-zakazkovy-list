<?php
/* =========================================================
   Jednorázové nastavení přístupu k formuláři příjmu
   Funguje jen s platným tokenem ze souboru mimo web
   (autolab-prijem-setup.txt). Po uložení se token smaže.
   Nastaví login a heslo; při prvním nastavení vygeneruje
   i klíč pro Apps Script (zobrazí se jen jednou).
   Při změně hesla zůstává klíč stejný, odhlásí se všechna zařízení.
   ========================================================= */
require __DIR__ . '/prijem-auth.php';
prijem_nocache();
header('X-Robots-Tag: noindex, nofollow');

$tok  = is_file(PRIJEM_SETUP_FILE) ? trim((string) file_get_contents(PRIJEM_SETUP_FILE)) : '';
$t    = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$okT  = strlen($tok) >= 32 && hash_equals($tok, $t);
$err  = ''; $done = false; $newKey = '';

if (!$okT) { http_response_code(404); exit('Nenalezeno.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $u = trim((string) ($_POST['u'] ?? '')); $p = (string) ($_POST['p'] ?? ''); $p2 = (string) ($_POST['p2'] ?? '');
  if (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', $u)) $err = 'Login: 3–40 znaků, jen písmena bez diakritiky, číslice, tečka, pomlčka.';
  elseif (mb_strlen($p) < 10) $err = 'Heslo musí mít aspoň 10 znaků.';
  elseif ($p !== $p2) $err = 'Hesla se neshodují.';
  else {
    $old = prijem_secret();
    $key = $old['key'] ?? '';
    if (!$key) { $key = bin2hex(random_bytes(24)); $newKey = $key; }
    $s = ['user' => $u, 'hash' => password_hash($p, PASSWORD_DEFAULT), 'key' => $key,
          'csecret' => bin2hex(random_bytes(32)), 'zmeneno' => date('c')];
    if (file_put_contents(PRIJEM_SECRET_FILE, json_encode($s, JSON_PRETTY_PRINT), LOCK_EX) === false) $err = 'Soubor s přístupem se nepodařilo uložit.';
    else { @chmod(PRIJEM_SECRET_FILE, 0600); @unlink(PRIJEM_SETUP_FILE); $done = true; }
  }
}
?><!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Příjem materiálu – nastavení přístupu</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#E9EBEE;font-family:Arial,"Helvetica Neue",-apple-system,"Segoe UI",Roboto,sans-serif;color:#172B4D}
.box{max-width:520px;margin:8vh auto 0;background:#fff;padding:28px 26px 30px}
h1{margin:0 0 8px;font-size:22px;color:#091E42}
p,li{font-size:14px;line-height:21px;color:#44546F}
input{display:block;width:100%;height:44px;margin:0 0 10px;border:0;border-radius:7px;box-shadow:inset 0 0 0 1.4px #626F86;font:inherit;font-size:17px;padding:0 14px}
input:focus{outline:none;box-shadow:inset 0 0 0 1.4px #0C66E4}
button{width:100%;height:44px;margin-top:6px;border:0;border-radius:7px;background:#3614C4;color:#fff;font:inherit;font-size:17px;cursor:pointer}
.err{margin:0 0 14px;padding:9px 12px;border-radius:6px;font-size:14px;color:#C9372C;box-shadow:inset 0 0 0 1.3px #C9372C}
.ok{margin:0 0 14px;padding:9px 12px;border-radius:6px;font-size:14px;color:#216E4E;box-shadow:inset 0 0 0 1.3px #216E4E}
code{display:block;margin:10px 0;padding:12px;border-radius:6px;background:#F0F1F3;font-size:15px;word-break:break-all;user-select:all;color:#091E42}
</style>
</head>
<body>
<div class="box">
<?php if ($done): ?>
  <h1>Přístup uložen</h1>
  <div class="ok">Login a heslo jsou nastavené. Tento odkaz už nefunguje.</div>
  <?php if ($newKey): ?>
    <p><b>Klíč pro Apps Script</b> (zobrazí se jen teď):</p>
    <code><?= htmlspecialchars($newKey) ?></code>
    <ol>
      <li>V Apps Scriptu EVIDENCE otevři <b>Nastavení projektu</b> (ozubené kolo vlevo).</li>
      <li>Dole v části <b>Vlastnosti skriptu</b> klikni na <b>Přidat vlastnost skriptu</b>.</li>
      <li>Vlastnost: <b>PRIJEM_KEY</b>, hodnota: klíč výše. Ulož.</li>
    </ol>
    <p>Klíč nikam jinam neukládej ani neposílej.</p>
  <?php else: ?>
    <p>Klíč pro Apps Script zůstal stejný – v Apps Scriptu není potřeba nic měnit. Všechna zařízení se musí přihlásit znovu.</p>
  <?php endif; ?>
  <p><a href="prijem-login.php">Přejít na přihlášení</a></p>
<?php else: ?>
  <h1>Nastavení přístupu</h1>
  <p>Společný login a heslo pro formulář příjmu materiálu.</p>
  <?php if ($err): ?><div class="err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="t" value="<?= htmlspecialchars($t) ?>">
    <input name="u" placeholder="login" autocomplete="username" autocapitalize="none" spellcheck="false" required value="<?= htmlspecialchars((string) ($_POST['u'] ?? '')) ?>">
    <input name="p" type="password" placeholder="heslo (aspoň 10 znaků)" autocomplete="new-password" required>
    <input name="p2" type="password" placeholder="heslo znovu" autocomplete="new-password" required>
    <button type="submit">uložit přístup</button>
  </form>
<?php endif; ?>
</div>
</body>
</html>
