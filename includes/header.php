<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
$user = Auth::user();
$flash = getFlash();
$currentPath = $_SERVER['PHP_SELF'] ?? '';
function isActive(string $path): string {
    global $currentPath;
    return strpos($currentPath, $path) !== false ? 'active' : '';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($pageTitle ?? APP_NAME) ?> — АИС РЖД</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&family=IBM+Plex+Sans:wght@300;400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>css/style.css">
</head>
<body>

<aside class="sidebar">
  <div class="sidebar-logo">
    <div class="logo-emblem">РЖД</div>
    <div class="logo-text">
      <div class="logo-title">АИС</div>
      <div class="logo-sub">Перевозки РЖД</div>
    </div>
  </div>

  <nav class="sidebar-nav">
    <?php if (Auth::can('dashboard')): ?>
    <a href="/index.php" class="nav-item <?= isActive('index.php') ?>">
      <span class="nav-icon">◈</span><span>Дашборд</span>
    </a>
    <?php endif; ?>

    <?php if (Auth::can('applications')): ?>
    <div class="nav-group-label">Перевозки</div>
    <a href="/pages/applications.php" class="nav-item <?= isActive('applications') ?>">
      <span class="nav-icon">⊞</span><span>Заявки</span>
    </a>
    <a href="/pages/trips.php" class="nav-item <?= isActive('trips') ?>">
      <span class="nav-icon">⊳</span><span>Рейсы</span>
    </a>
    <a href="/pages/routes.php" class="nav-item <?= isActive('routes') ?>">
      <span class="nav-icon">⊸</span><span>Маршруты</span>
    </a>
    <?php endif; ?>

    <?php if (Auth::can('clients')): ?>
    <div class="nav-group-label">Клиенты</div>
    <a href="/pages/clients.php" class="nav-item <?= isActive('clients') ?>">
      <span class="nav-icon">◎</span><span>Клиенты</span>
    </a>
    <?php endif; ?>

    <?php if (Auth::can('locomotives') || Auth::can('maintenance') || Auth::can('shift')): ?>
    <div class="nav-group-label">Техника</div>
    <?php if (Auth::can('shift')): ?>
    <a href="/pages/shift.php" class="nav-item <?= isActive('shift') ?>">
      <span class="nav-icon">◌</span><span>Механики на смене</span>
    </a>
    <?php endif; ?>
    <?php if (Auth::can('locomotives')): ?>
    <a href="/pages/locomotives.php" class="nav-item <?= isActive('locomotives') ?>">
      <span class="nav-icon">◧</span><span>Локомотивы</span>
    </a>
    <?php endif; ?>
    <?php if (Auth::can('maintenance')): ?>
    <a href="/pages/maintenance.php" class="nav-item <?= isActive('maintenance') ?>">
      <span class="nav-icon">⊛</span><span>Техобслуживание</span>
    </a>
    <?php endif; ?>
    <?php endif; ?>

    <?php if (Auth::can('finances')): ?>
    <div class="nav-group-label">Финансы</div>
    <a href="/pages/invoices.php" class="nav-item <?= isActive('invoices') ?>">
      <span class="nav-icon">◉</span><span>Счета</span>
    </a>
    <?php endif; ?>

    <?php if (Auth::can('reports') || Auth::can('reports.financial') || Auth::can('reports.gov')): ?>
    <div class="nav-group-label">Аналитика</div>
    <a href="/pages/reports.php" class="nav-item <?= isActive('reports') ?>">
      <span class="nav-icon">◈</span><span>Отчёты</span>
    </a>
    <?php endif; ?>

    <?php if (Auth::role() === 'admin'): ?>
    <div class="nav-group-label">Администрирование</div>
    <a href="/pages/users.php" class="nav-item <?= isActive('users') ?>">
      <span class="nav-icon">◯</span><span>Пользователи</span>
    </a>
    <a href="/pages/audit.php" class="nav-item <?= isActive('audit') ?>">
      <span class="nav-icon">▣</span><span>Журнал событий</span>
    </a>
    <?php endif; ?>
  </nav>

  <div class="sidebar-user">
    <div class="user-avatar"><?= mb_strtoupper(mb_substr($user['full_name'] ?? 'A', 0, 1)) ?></div>
    <div class="user-info">
      <div class="user-name"><?= h(mb_substr(displayPersonName($user['full_name'] ?? '', $user['role_name'] ?? ''), 0, 20)) ?></div>
      <div class="user-role"><?= h($user['role_label'] ?? '') ?></div>
    </div>
    <a href="<?= BASE_URL ?>logout.php" class="logout-btn">⏻</a>
  </div>
</aside>

<main class="main-content">
  <header class="top-bar">
    <div class="page-title-wrap">
      <h1 class="page-heading"><?= h($pageTitle ?? 'Дашборд') ?></h1>
      <div class="depot-label"><?= h(APP_DEPOT) ?></div>
    </div>
    <div class="top-bar-right">
      <div class="clock" id="clock"></div>
    </div>
  </header>

  <?php if ($flash): ?>
  <div class="flash flash-<?= h($flash['type']) ?>">
    <?= h($flash['msg']) ?>
    <button onclick="this.parentNode.remove()">×</button>
  </div>
  <?php endif; ?>

  <div class="content-body">
