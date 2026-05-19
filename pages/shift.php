<?php
require_once __DIR__ . '/../includes/auth.php';
Auth::requireLogin();
if (!Auth::can('shift')) {
    setFlash('error', 'Недостаточно прав для просмотра смены');
    redirect(Auth::homeUrl());
}
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pageTitle = 'Смена';
$mechanics = Database::fetchAll(
    "SELECT u.full_name, u.department, u.position, u.phone
     FROM users u
     JOIN roles r ON u.role_id = r.id
     WHERE r.name = 'mechanic' AND u.is_active = 1
     ORDER BY u.full_name"
);
if (!$mechanics) {
    $mechanics = [[
        'full_name' => rolePersonName('mechanic'),
        'department' => 'Техническое обслуживание',
        'position' => 'Механик',
        'phone' => null,
    ]];
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
  <div class="card-header"><div class="card-title">Механики на смене</div></div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>ФИО</th>
          <th>Отдел</th>
          <th>Должность</th>
          <th>Телефон</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($mechanics as $i => $person): ?>
        <tr>
          <td class="mono" style="color:var(--text-muted)"><?= $i + 1 ?></td>
          <td><?= h(displayPersonName($person['full_name'] ?? '')) ?></td>
          <td><?= h($person['department'] ?? '—') ?></td>
          <td><?= h($person['position'] ?? 'Механик') ?></td>
          <td class="mono"><?= h($person['phone'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
