<?php
require_once __DIR__ . '/../includes/auth.php';
Auth::requireLogin();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$id = (int)($_GET['id'] ?? 0);
$trip = Database::fetchOne(
    "SELECT t.*, r.name as route_name, r.distance_km,
            l.inventory_number as loco_num, l.series as loco_series, l.status as loco_status,
            u.full_name as driver_name,
            a.application_number
     FROM trips t
     LEFT JOIN routes r ON t.route_id = r.id
     LEFT JOIN locomotives l ON t.locomotive_id = l.id
     LEFT JOIN users u ON t.driver_user_id = u.id
     LEFT JOIN applications a ON t.application_id = a.id
     WHERE t.id = ?", [$id]
);
if (!$trip) { setFlash('error','Рейс не найден'); redirect('/pages/trips.php'); }

$events = Database::fetchAll(
    "SELECT e.*, u.full_name FROM trip_events e LEFT JOIN users u ON e.recorded_by=u.id
     WHERE e.trip_id=? ORDER BY e.event_time ASC", [$id]
);

$latestReason = Database::fetchOne(
    "SELECT event_type, description, event_time
     FROM trip_events
     WHERE trip_id=? AND event_type IN ('delay','incident')
     ORDER BY event_time DESC LIMIT 1", [$id]
);

$invoice = Database::fetchOne("SELECT * FROM invoices WHERE trip_id=?",[$id]);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrf()) { setFlash('error','CSRF'); redirect("/pages/trip_view.php?id=$id"); }

    if ($_POST['action'] === 'update_status' && Auth::can('trips')) {
        $newStatus = $_POST['new_status'];
        $sql = "UPDATE trips SET status=?";
        $params = [$newStatus];
        if ($newStatus === 'in_progress' && !$trip['actual_departure']) {
            $sql .= ",actual_departure=NOW()";
        } elseif ($newStatus === 'completed') {
            $sql .= ",actual_arrival=NOW()";
        }
        $params[] = $id;
        Database::query("$sql WHERE id=?", $params);
        $reason = trim($_POST['reason'] ?? '');
        if ($reason && in_array($newStatus, ['delayed', 'cancelled'])) {
            Database::insert(
                "INSERT INTO trip_events (trip_id,event_type,station,event_time,description,recorded_by) VALUES (?,?,?,?,?,?)",
                [$id, $newStatus === 'delayed' ? 'delay' : 'incident', null, date('Y-m-d H:i:s'), $reason, Auth::userId()]
            );
        }
        setFlash('success','Статус рейса обновлён');
    } elseif ($_POST['action'] === 'add_event' && Auth::can('trips')) {
        Database::insert(
            "INSERT INTO trip_events (trip_id,event_type,station,event_time,description,recorded_by) VALUES (?,?,?,?,?,?)",
            [$id,$_POST['event_type'],$_POST['station'],$_POST['event_time'],$_POST['description'],Auth::userId()]
        );
        setFlash('success','Событие добавлено');
    }
    redirect("/pages/trip_view.php?id=$id");
}

$pageTitle = 'Рейс ' . $trip['trip_number'];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex-between mb-2">
  <a href="/pages/trips.php" class="btn btn-secondary btn-sm">← Назад</a>
  <div class="btn-group">
    <?php if (Auth::can('trips') && !in_array($trip['status'],['completed','cancelled'])): ?>
    <button class="btn btn-secondary" onclick="openModal('modal-status')">Изменить статус</button>
    <?php endif; ?>
    <button class="btn btn-secondary btn-sm" onclick="printPage()">🖨 Печать</button>
  </div>
</div>

<div class="grid-2">
  <div>
    <div class="card">
      <div class="card-header">
        <div class="card-title">Сведения о рейсе</div>
        <?= statusBadge($trip['status'], TRIP_STATUS_LABELS) ?>
      </div>
      <div class="info-grid">
        <div class="info-item">
          <div class="info-label">Номер рейса</div>
          <div class="info-value mono"><?= h($trip['trip_number']) ?></div>
        </div>
        <div class="info-item">
          <div class="info-label">Заявка</div>
          <div class="info-value mono">
            <?php if ($trip['application_number']): ?>
            <a href="/pages/application_view.php?id=<?= $trip['application_id'] ?>" style="color:var(--rzd-red)"><?= h($trip['application_number']) ?></a>
            <?php else: ?> — <?php endif; ?>
          </div>
        </div>
        <div class="info-item">
          <div class="info-label">Маршрут</div>
          <div class="info-value"><?= h($trip['route_name'] ?? '—') ?></div>
        </div>
        <div class="info-item">
          <div class="info-label">Расстояние</div>
          <div class="info-value mono"><?= $trip['distance_km'] ? number_format($trip['distance_km'],1,',',' ').' км' : '—' ?></div>
        </div>
        <div class="info-item">
          <div class="info-label">Плановое отправление</div>
          <div class="info-value mono"><?= formatDateTime($trip['planned_departure']) ?></div>
        </div>
        <div class="info-item">
          <div class="info-label">Плановое прибытие</div>
          <div class="info-value mono"><?= formatDateTime($trip['planned_arrival']) ?></div>
        </div>
        <div class="info-item">
          <div class="info-label">Фактическое отправление</div>
          <div class="info-value mono <?= !$trip['actual_departure']?'text-muted':'' ?>">
            <?= $trip['actual_departure'] ? formatDateTime($trip['actual_departure']) : '—' ?>
          </div>
        </div>
        <div class="info-item">
          <div class="info-label">Фактическое прибытие</div>
          <div class="info-value mono <?= !$trip['actual_arrival']?'text-muted':'' ?>">
            <?= $trip['actual_arrival'] ? formatDateTime($trip['actual_arrival']) : '—' ?>
          </div>
        </div>
        <?php if ($trip['cargo_description'] || $trip['cargo_weight_tons']): ?>
        <div class="info-item" style="grid-column:1/-1">
          <div class="info-label">Груз</div>
          <div class="info-value"><?= h($trip['cargo_description'] ?? '—') ?> <?= $trip['cargo_weight_tons'] ? '— '.formatWeight($trip['cargo_weight_tons']) : '' ?></div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($invoice && Auth::can('finances')): ?>
    <div class="card">
      <div class="card-header"><div class="card-title">Счёт по рейсу</div></div>
      <div class="info-grid">
        <div class="info-item">
          <div class="info-label">Номер счёта</div>
          <div class="info-value mono"><?= h($invoice['invoice_number']) ?></div>
        </div>
        <div class="info-item">
          <div class="info-label">Сумма</div>
          <div class="info-value mono"><?= formatMoney($invoice['total_amount']) ?></div>
        </div>
        <div class="info-item">
          <div class="info-label">Статус оплаты</div>
          <div class="info-value"><?= statusBadge($invoice['status'], INVOICE_STATUS_LABELS) ?></div>
        </div>
        <div class="info-item">
          <div class="info-label">Оплатить до</div>
          <div class="info-value mono"><?= formatDate($invoice['due_date']) ?></div>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div>

    <?php if ($latestReason && $latestReason['description']): ?>
    <div class="card">
      <div class="card-header"><div class="card-title">Причина задержки / отмены</div></div>
      <div class="card-body">
        <div style="font-size:13px;color:var(--text-secondary)"><?= nl2br(h($latestReason['description'])) ?></div>
        <div class="mono" style="font-size:11px;color:var(--text-muted);margin-top:8px"><?= formatDateTime($latestReason['event_time']) ?></div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($trip['notes']): ?>
    <div class="card">
      <div class="card-header"><div class="card-title">Примечания</div></div>
      <div class="card-body">
        <p style="color:var(--text-secondary);font-size:13px"><?= nl2br(h($trip['notes'])) ?></p>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if (Auth::can('trips')): ?>
<div class="modal-overlay" id="modal-status">
  <div class="modal" style="max-width:400px">
    <div class="modal-header">
      <div class="modal-title">Изменить статус рейса</div>
      <button class="modal-close" onclick="closeModal('modal-status')">×</button>
    </div>
    <div class="modal-body">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="update_status">
        <div class="form-group" style="margin-bottom:20px">
          <label>Новый статус</label>
          <select name="new_status" id="trip-status-select" onchange="toggleTripReason()">
            <?php foreach (TRIP_STATUS_LABELS as $v=>$l): ?>
            <option value="<?= $v ?>" <?= $trip['status']===$v?'selected':'' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" id="trip-reason-group" style="margin-bottom:20px">
          <label>Причина задержки / отмены</label>
          <textarea name="reason" rows="3" placeholder="Заполняется для статусов «Задержка» и «Отменён»"></textarea>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Обновить</button>
          <button type="button" class="btn btn-secondary" onclick="closeModal('modal-status')">Отмена</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php endif; ?>

<script>
function toggleTripReason() {
  const status = document.getElementById('trip-status-select')?.value;
  const group = document.getElementById('trip-reason-group');
  if (group) {
    const show = status === 'delayed' || status === 'cancelled';
    group.style.display = show ? '' : 'none';
    group.querySelector('textarea').disabled = !show;
  }
}
toggleTripReason();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
