<?php
require_once __DIR__ . '/../includes/auth.php';
Auth::requireLogin();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$id = (int)($_GET['id'] ?? 0);
$app = Database::fetchOne(
    "SELECT a.*, c.company_name, c.contact_person, c.phone as client_phone, c.email as client_email,
            r.name as route_name, r.distance_km,
            u.full_name as dispatcher_name,
            cb.full_name as created_by_name
     FROM applications a
     JOIN clients c ON a.client_id = c.id
     LEFT JOIN routes r ON a.route_id = r.id
     LEFT JOIN users u ON a.dispatcher_id = u.id
     LEFT JOIN users cb ON a.created_by = cb.id
     WHERE a.id = ?", [$id]
);

if (!$app) { setFlash('error', 'Заявка не найдена'); redirect('/pages/applications.php'); }

$statusLog = Database::fetchAll(
    "SELECT l.*, u.full_name FROM application_status_log l LEFT JOIN users u ON l.changed_by = u.id
     WHERE l.application_id = ? ORDER BY l.changed_at DESC", [$id]
);

$trips = Database::fetchAll(
    "SELECT t.*, l.inventory_number, l.series FROM trips t
     LEFT JOIN locomotives l ON t.locomotive_id = l.id
     WHERE t.application_id = ?", [$id]
);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrf()) { setFlash('error', 'Ошибка CSRF'); redirect("/pages/application_view.php?id=$id"); }

    if ($_POST['action'] === 'status_change' && Auth::can('applications')) {
        $newStatus = $_POST['new_status'];
        $comment = trim($_POST['comment'] ?? '');
        $allowed = ['new','processing','approved','rejected','completed','cancelled'];
        if (in_array($newStatus, $allowed)) {
            Database::query("UPDATE applications SET status=?, dispatcher_id=?, updated_at=NOW() WHERE id=?",
                [$newStatus, Auth::userId(), $id]);
            Database::insert("INSERT INTO application_status_log (application_id,old_status,new_status,changed_by,comment) VALUES (?,?,?,?,?)",
                [$id, $app['status'], $newStatus, Auth::userId(), $comment]);
            Auth::auditLog('status_change', 'applications', 'application', $id, ['status'=>$newStatus]);
            setFlash('success', 'Статус заявки изменён');
            redirect("/pages/application_view.php?id=$id");
        }
    }
}

$pageTitle = 'Заявка ' . $app['application_number'];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex-between mb-2">
  <a href="/pages/applications.php" class="btn btn-secondary btn-sm">← Назад</a>
  <div class="btn-group">
    <?php if (Auth::can('applications')): ?>
    <button class="btn btn-secondary btn-sm" onclick="openModal('modal-status')">Изменить статус</button>
    <?php endif; ?>
    <button class="btn btn-secondary btn-sm" onclick="printPage()">🖨 Печать</button>
  </div>
</div>

<div class="grid-2">

  <div>
    <div class="card">
      <div class="card-header">
        <div class="card-title">Сведения о заявке</div>
        <?= statusBadge($app['status'], STATUS_LABELS) ?>
      </div>
      <div class="info-grid">
        <div class="info-item">
          <div class="info-label">Номер заявки</div>
          <div class="info-value mono"><?= h($app['application_number']) ?></div>
        </div>
        <div class="info-item">
          <div class="info-label">Дата создания</div>
          <div class="info-value mono"><?= formatDateTime($app['created_at']) ?></div>
        </div>
        <div class="info-item">
          <div class="info-label">Маршрут</div>
          <div class="info-value"><?= h($app['route_name'] ?? '— не назначен —') ?></div>
        </div>
        <?php if ($app['distance_km']): ?>
        <div class="info-item">
          <div class="info-label">Расстояние</div>
          <div class="info-value mono"><?= number_format($app['distance_km'],1,',',' ') ?> км</div>
        </div>
        <?php endif; ?>
        <div class="info-item">
          <div class="info-label">Желаемая дата</div>
          <div class="info-value mono"><?= formatDate($app['requested_date']) ?></div>
        </div>
        <div class="info-item">
          <div class="info-label">Диспетчер</div>
          <div class="info-value"><?= h(displayPersonName($app['dispatcher_name'] ?? '— не назначен —', 'dispatcher')) ?></div>
        </div>
      </div>
      <?php if ($app['cargo_type'] || $app['cargo_weight_tons']): ?>
      <div style="padding:14px 18px;border-top:1px solid var(--rzd-border)">
        <div class="info-label">Груз</div>
        <div style="margin-top:4px">
          <?= h($app['cargo_type'] ?? '—') ?>
          <?php if ($app['cargo_weight_tons']): ?>
          <span class="mono" style="color:var(--text-muted);margin-left:8px"><?= formatWeight($app['cargo_weight_tons']) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
      <?php if ($app['additional_conditions']): ?>
      <div style="padding:14px 18px;border-top:1px solid var(--rzd-border)">
        <div class="info-label">Дополнительные условия</div>
        <div style="margin-top:4px;font-size:13px;color:var(--text-secondary)"><?= nl2br(h($app['additional_conditions'])) ?></div>
      </div>
      <?php endif; ?>
    </div>

    <?php if ($trips): ?>
    <div class="card">
      <div class="card-header"><div class="card-title">Рейсы по заявке</div></div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Номер рейса</th><th>Отправление</th><th>Статус</th></tr></thead>
          <tbody>
            <?php foreach ($trips as $t): ?>
            <tr data-href="/pages/trip_view.php?id=<?= $t['id'] ?>">
              <td class="mono"><?= h($t['trip_number']) ?></td>
              <td class="mono" style="font-size:12px;"><?= formatDateTime($t['planned_departure']) ?></td>
              <td><?= statusBadge($t['status'], TRIP_STATUS_LABELS) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div>

    <div class="card">
      <div class="card-header"><div class="card-title">Клиент</div></div>
      <div class="info-grid">
        <div class="info-item" style="grid-column:1/-1">
          <div class="info-label">Организация</div>
          <div class="info-value"><?= h($app['company_name']) ?></div>
        </div>
        <div class="info-item">
          <div class="info-label">Контактное лицо</div>
          <div class="info-value"><?= h($app['contact_person'] ?? '—') ?></div>
        </div>
        <div class="info-item">
          <div class="info-label">Телефон</div>
          <div class="info-value mono"><?= h($app['client_phone'] ?? '—') ?></div>
        </div>
        <div class="info-item" style="grid-column:1/-1">
          <div class="info-label">E-mail</div>
          <div class="info-value"><?= h($app['client_email'] ?? '—') ?></div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><div class="card-title">История статусов</div></div>
      <div class="card-body">
        <?php if ($statusLog): ?>
        <ul class="timeline">
          <?php foreach ($statusLog as $log): ?>
          <li class="timeline-item">
            <div class="timeline-time"><?= formatDateTime($log['changed_at']) ?> — <?= h($log['full_name'] ?? 'Система') ?></div>
            <div class="timeline-text">
              <?php if ($log['old_status']): ?>
              <span style="color:var(--text-muted)"><?= STATUS_LABELS[$log['old_status']] ?? $log['old_status'] ?></span> →
              <?php endif; ?>
              <?= statusBadge($log['new_status'], STATUS_LABELS) ?>
              <?php if ($log['comment']): ?><br><span style="color:var(--text-secondary);font-size:12px"><?= h($log['comment']) ?></span><?php endif; ?>
            </div>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <div class="text-muted text-center" style="padding:20px">История изменений пуста</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if (Auth::can('applications')): ?>
<div class="modal-overlay" id="modal-status">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Изменить статус заявки</div>
      <button class="modal-close" onclick="closeModal('modal-status')">×</button>
    </div>
    <div class="modal-body">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="status_change">
        <div class="form-group" style="margin-bottom:16px">
          <label>Новый статус</label>
          <select name="new_status" id="app-status-select" required onchange="toggleAppComment()">
            <?php foreach (STATUS_LABELS as $val => $lbl): ?>
            <option value="<?= $val ?>" <?= $app['status'] === $val ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" id="app-comment-group" style="margin-bottom:20px">
          <label>Комментарий</label>
          <textarea name="comment" rows="3" placeholder="Причина изменения…"></textarea>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Сохранить</button>
          <button type="button" class="btn btn-secondary" onclick="closeModal('modal-status')">Отмена</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
function toggleAppComment() {
  const status = document.getElementById('app-status-select')?.value;
  const group = document.getElementById('app-comment-group');
  if (group) {
    const show = status === 'rejected' || status === 'cancelled';
    group.style.display = show ? '' : 'none';
    group.querySelector('textarea').disabled = !show;
  }
}
toggleAppComment();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
