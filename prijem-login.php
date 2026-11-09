<?php
require __DIR__ . '/prijem-auth.php';
prijem_nocache();

if (isset($_GET['odhlasit'])) { prijem_logout(); header('Location: prijem-login.php'); exit; }
if (prijem_logged()) { header('Location: prijem.php'); exit; }

$err = '';
if (!prijem_secret()) $err = 'Přístup zatím není nastavený.';
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (prijem_login((string) ($_POST['u'] ?? ''), (string) ($_POST['p'] ?? ''))) { header('Location: prijem.php'); exit; }
  sleep(2);                                   // zpomalí zkoušení hesel
  $err = 'Nesprávný login nebo heslo.';
}
?><!doctype html>
<html lang="cs" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Příjem materiálu – přihlášení</title>
<script>try{var t=localStorage.getItem('prijemTheme')||(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
<style>
:root{--bg:#242528;--panel:#393B44;--txt:#9E9FA2;--txt-hi:#C8C9CC;--txt-dim:#939497;--txt-strong:#FFFFFF;--bd:#939497;--ok:#3614C4;--ok-txt:#E6E9FF;--bad:#E2686B;--focus:#FFFFFF;--page:#1D1E21;color-scheme:dark}
html[data-theme="light"]{--bg:#FFFFFF;--panel:#F0F1F3;--txt:#172B4D;--txt-hi:#091E42;--txt-dim:#626F86;--txt-strong:#091E42;--bd:#626F86;--bad:#C9372C;--focus:#0C66E4;--page:#E9EBEE;color-scheme:light}
*{box-sizing:border-box}
body{margin:0;background:var(--page);font-family:Arial,"Helvetica Neue",-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--txt);-webkit-font-smoothing:antialiased}
.box{max-width:420px;margin:12vh auto 0;background:var(--bg);padding:28px 26px 30px}
h1{margin:0 0 6px;font-size:22px;color:var(--txt-strong)}
p{margin:0 0 20px;font-size:14px;line-height:20px;color:var(--txt-dim)}
input{display:block;width:100%;height:44px;margin:0 0 10px;border:0;border-radius:7px;box-shadow:inset 0 0 0 1.4px var(--bd);background:var(--bg);color:var(--txt-hi);font:inherit;font-size:17px;padding:0 14px}
input:focus{outline:none;box-shadow:inset 0 0 0 1.4px var(--focus)}
button{width:100%;height:44px;margin-top:6px;border:0;border-radius:7px;background:var(--ok);color:var(--ok-txt);font:inherit;font-size:17px;cursor:pointer}
.err{margin:0 0 14px;padding:9px 12px;border-radius:6px;font-size:14px;color:var(--bad);box-shadow:inset 0 0 0 1.3px var(--bad)}
</style>
</head>
<body>
<form class="box" method="post" action="prijem-login.php">
  <h1>Příjem materiálu</h1>
  <p>Přihlas se společným loginem laboratoře.</p>
  <?php if ($err): ?><div class="err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  <input name="u" placeholder="login" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
  <input name="p" type="password" placeholder="heslo" autocomplete="current-password" required>
  <button type="submit">přihlásit</button>
</form>
</body>
</html>
