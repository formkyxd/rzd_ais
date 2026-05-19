<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

Auth::startSession();

if (Auth::isLoggedIn()) {
    header('Location: ' . url('index.php'));
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (Auth::login($username, $password)) {
        header('Location: ' . url('index.php'));
    } else {
        $error = 'Неверное имя пользователя или пароль';
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Вход — АИС «Перевозки РЖД»</title>
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;600;700&family=IBM+Plex+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>css/style.css">
</head>
<body class="login-page">
<div class="login-bg"></div>

<div class="login-panel">
  <div class="login-logo">
    <div class="login-emblem">РЖД</div>
    <div class="login-title">АИС</div>
    <div class="login-subtitle">АВТОМАТИЗИРОВАННАЯ ИНФОРМАЦИОННАЯ СИСТЕМА<br>«ПЕРЕВОЗКИ РЖД»</div>
  </div>

  <?php if ($error): ?>
  <div class="flash flash-error" style="margin-bottom:16px;"><?= h($error) ?></div>
  <?php endif; ?>

  <form method="POST" action="">
    <div class="form-group" style="margin-bottom:16px;">
      <label>Имя пользователя</label>
      <input type="text" name="username" required autocomplete="username"
             placeholder="Введите логин"
             value="<?= h($_POST['username'] ?? '') ?>">
    </div>

    <div class="form-group" style="margin-bottom:24px;">
      <label>Пароль</label>
      <input type="password" name="password" required autocomplete="current-password"
             placeholder="••••••••">
    </div>

    <button type="submit" class="btn btn-primary w-full" style="justify-content:center;padding:12px;">
      ВОЙТИ В СИСТЕМУ
    </button>
  </form>

  <div style="margin-top:28px;padding-top:20px;border-top:1px solid var(--rzd-border);">
    <div style="font-size:11px;color:var(--text-muted);margin-bottom:10px;">Демо:</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">
      <?php
      $demos = [
          ['admin', 'Администратор'],
          ['ivanov_director', 'Калесников Артем Сергеевич'],
          ['petrov_disp', 'Шуляк Александр Сергеевич'],
          ['sidorov_mech', 'Бильский Илья Олегович'],
          ['client_lukoil', 'Мешкова Екатерина Игоревна'],
      ];
      foreach ($demos as [$login, $label]): ?>
      <button type="button" onclick="fillLogin('<?= $login ?>')"
              style="background:var(--rzd-panel);border:1px solid var(--rzd-border);
                     color:var(--text-secondary);padding:6px;font-size:11px;cursor:pointer;
                     text-align:left;transition:all 0.15s;"
              onmouseover="this.style.borderColor='var(--rzd-red)'"
              onmouseout="this.style.borderColor='var(--rzd-border)'">
        <div style="font-weight:600;color:var(--text-primary)"><?= $login ?></div>
        <div><?= $label ?></div>
      </button>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script>
function fillLogin(login) {
  document.querySelector('[name=username]').value = login;
  document.querySelector('[name=password]').value = 'password';
}
</script>
</body>
</html>
