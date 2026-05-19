<?php
require_once __DIR__ . '/includes/auth.php';
Auth::requireLogin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';

$pageTitle = 'Дашборд';
$role = Auth::role();

if (!Auth::can('dashboard')) {
    redirect(Auth::homeUrl());
}

$clientRecord = null;
$clientWhere = '';
$clientParams = [];
$tripClientWhere = '';
$tripClientParams = [];
if ($role === 'client') {
    $clientRecord = Database::fetchOne("SELECT id FROM clients WHERE user_id = ?", [Auth::userId()]);
    if ($clientRecord) {
        $clientWhere = ' AND a.client_id = ?';
        $clientParams[] = $clientRecord['id'];
        $tripClientWhere = ' AND (a.client_id = ? OR t.application_id IS NULL)';
        $tripClientParams[] = $clientRecord['id'];
    } else {
        $clientWhere = ' AND 1=0';
        $tripClientWhere = ' AND 1=0';
    }
}

$stats = [
    'apps_total'   => Database::count('applications'),
    'apps_new'     => Database::count('applications', "status = 'new'"),
    'apps_proc'    => Database::count('applications', "status = 'processing'"),
    'trips_active' => Database::count('trips', "status IN ('planned','in_progress')"),
    'locos_ok'     => Database::count('locomotives', "status = 'operational'"),
    'locos_repair' => Database::count('locomotives', "status IN ('maintenance','repair')"),
    'clients_total'=> Database::count('clients', 'is_active = 1'),
];

$recentApps = Database::fetchAll(
    "SELECT a.*, c.company_name, r.name as route_name
     FROM applications a
     JOIN clients c ON a.client_id = c.id
     LEFT JOIN routes r ON a.route_id = r.id
     WHERE 1=1 $clientWhere
     ORDER BY a.created_at DESC LIMIT 8",
    $clientParams
);

$upcomingTrips = Database::fetchAll(
    "SELECT t.*, r.name as route_name, a.client_id
     FROM trips t
     LEFT JOIN routes r ON t.route_id = r.id
     LEFT JOIN applications a ON t.application_id = a.id
     WHERE t.status IN ('planned','in_progress')
       AND (r.route_type = 'freight' OR r.route_type IS NULL) $tripClientWhere
     ORDER BY
       CASE WHEN t.status='in_progress' THEN 0 WHEN t.planned_departure >= NOW() THEN 1 ELSE 2 END,
       ABS(TIMESTAMPDIFF(MINUTE, NOW(), t.planned_departure))
     LIMIT 8",
    $tripClientParams
);

$popularApps = Database::fetchAll(
    "SELECT COALESCE(r.name, a.cargo_type, 'Свободная заявка') as title,
            COUNT(*) as app_count,
            SUM(COALESCE(a.cargo_weight_tons,0)) as total_weight
     FROM applications a
     LEFT JOIN routes r ON a.route_id = r.id
     WHERE 1=1 $clientWhere
     GROUP BY title
     ORDER BY app_count DESC, total_weight DESC
     LIMIT 5",
    $clientParams
);

$calcRoutes = Database::fetchAll(
    "SELECT id, name, distance_km, estimated_duration_hours
     FROM routes
     WHERE is_active = 1
       AND (route_type = 'freight' OR route_type IS NULL)
       AND name NOT LIKE '%Ханты%'
     ORDER BY name"
);

require_once __DIR__ . '/includes/header.php';
?>

<?php if ($role !== 'client'): ?>
<div class="stats-grid">
  <div class="stat-card" style="--accent:#3B82F6">
    <div class="stat-label">Заявки — Новые</div>
    <div class="stat-value"><?= $stats['apps_new'] ?></div>
    <div class="stat-sub">Всего: <?= $stats['apps_total'] ?></div>
    <div class="stat-icon">⊞</div>
  </div>
  <div class="stat-card" style="--accent:#F59E0B">
    <div class="stat-label">В обработке</div>
    <div class="stat-value"><?= $stats['apps_proc'] ?></div>
    <div class="stat-sub">Требуют внимания</div>
    <div class="stat-icon">⊳</div>
  </div>
  <div class="stat-card" style="--accent:#8B5CF6">
    <div class="stat-label">Активных рейсов</div>
    <div class="stat-value"><?= $stats['trips_active'] ?></div>
    <div class="stat-sub">Запланировано и в пути</div>
    <div class="stat-icon">◧</div>
  </div>
  <div class="stat-card" style="--accent:#10B981">
    <div class="stat-label">Локомотивы</div>
    <div class="stat-value"><?= $stats['locos_ok'] ?></div>
    <div class="stat-sub"><?= $stats['locos_repair'] ?> на ТО/ремонте</div>
    <div class="stat-icon">◉</div>
  </div>
  <div class="stat-card" style="--accent:#0FA86E">
    <div class="stat-label">Клиентов</div>
    <div class="stat-value"><?= $stats['clients_total'] ?></div>
    <div class="stat-sub">Активных договоров</div>
    <div class="stat-icon">◎</div>
  </div>
</div>
<?php endif; ?>

<?php if ($role === 'client'): ?>
<div class="grid-2">
  <div class="card">
    <div class="card-header"><div class="card-title">Популярные заявки</div></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Заявка</th><th>Кол-во</th><th>Тоннаж</th></tr></thead>
        <tbody>
          <?php foreach ($popularApps as $row): ?>
          <tr>
            <td><?= h($row['title']) ?></td>
            <td class="mono" style="text-align:center"><?= (int)$row['app_count'] ?></td>
            <td class="mono"><?= formatWeight((float)$row['total_weight']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$popularApps): ?><tr><td colspan="3" class="text-center text-muted">Нет данных</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><div class="card-title">Расчет стоимости рейса</div></div>
    <div class="card-body">
      <div class="form-grid">
        <div class="form-group">
          <label>Расстояние, км</label>
          <input type="number" id="cost-distance" min="0" step="1" value="500">
        </div>
        <div class="form-group">
          <label>Вес груза, т</label>
          <input type="number" id="cost-weight" min="0" step="0.1" value="20">
        </div>
        <div class="form-group">
          <label>Тариф, ₽/т-км</label>
          <input type="number" id="cost-rate" min="0" step="0.01" value="18">
        </div>
        <div class="form-group">
          <label>Итого</label>
          <input type="text" id="cost-result" readonly>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <div class="card-title">Расчет времени рейса</div>
  </div>

  <div class="card-body">
    <div class="form-grid">

      <div class="form-group">
        <label>Маршрут</label>

        <select id="time-route">
          <?php foreach ($calcRoutes as $route): ?>
          <option
            value="<?= $route['id'] ?>"
            data-distance="<?= h((string)($route['distance_km'] ?? '0')) ?>"
            data-duration="<?= h((string)($route['estimated_duration_hours'] ?? '0')) ?>"
          >
            <?= h($route['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label>Расстояние</label>
        <input type="text" id="time-distance" readonly>
      </div>

      <div class="form-group">
        <label>Время в пути</label>
        <input type="text" id="time-result" readonly>
      </div>

    </div>
  </div>
</div>
<?php endif; ?>

<div class="grid-2">
  <div class="card">
    <div class="card-header">
      <div class="card-title">Последние заявки</div>
      <a href="/pages/applications.php" class="btn btn-secondary btn-sm">Все заявки →</a>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Номер</th><th>Клиент</th><th>Маршрут</th><th>Статус</th></tr></thead>
        <tbody>
          <?php foreach ($recentApps as $app): ?>
          <tr data-href="/pages/application_view.php?id=<?= $app['id'] ?>">
            <td class="mono"><?= h($app['application_number']) ?></td>
            <td><?= h(mb_substr($app['company_name'], 0, 22)) ?></td>
            <td style="font-size:12px;"><?= h($app['route_name'] ?? '—') ?></td>
            <td><?= statusBadge($app['status'], STATUS_LABELS) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$recentApps): ?><tr><td colspan="4" class="text-center text-muted">Нет данных</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <div class="card-title">Предстоящие рейсы</div>
      <a href="/pages/trips.php" class="btn btn-secondary btn-sm">Все рейсы →</a>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Рейс</th><th>Маршрут</th><th>Отправление</th><th>Статус</th></tr></thead>
        <tbody>
          <?php foreach ($upcomingTrips as $trip): ?>
          <tr data-href="/pages/trip_view.php?id=<?= $trip['id'] ?>">
            <td class="mono"><?= h($trip['trip_number']) ?></td>
            <td style="font-size:12px;"><?= h($trip['route_name'] ?? '—') ?></td>
            <td class="mono" style="font-size:12px;"><?= formatDateTime($trip['planned_departure']) ?></td>
            <td><?= statusBadge($trip['status'], TRIP_STATUS_LABELS) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$upcomingTrips): ?><tr><td colspan="4" class="text-center text-muted">Нет данных</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if ($role === 'client'): ?>
<script>
function money(v) {
  return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB' }).format(v || 0);
}
function calcCost() {
  const d = parseFloat(document.getElementById('cost-distance').value) || 0;
  const w = parseFloat(document.getElementById('cost-weight').value) || 0;
  const r = parseFloat(document.getElementById('cost-rate').value) || 0;
  document.getElementById('cost-result').value = money(d * w * r);
}
function calcTime() {
  const route = document.getElementById('time-route');
  const opt = route ? route.options[route.selectedIndex] : null;

  const distance = opt ? (parseFloat(opt.dataset.distance) || 0) : 0;
  const duration = opt ? (parseFloat(opt.dataset.duration) || 0) : 0;

  const h = Math.floor(duration);
  const m = Math.round((duration - h) * 60);

  document.getElementById('time-distance').value = distance + ' км';
  document.getElementById('time-result').value = h + ' ч ' + m + ' мин';
}
['cost-distance','cost-weight','cost-rate'].forEach(id => document.getElementById(id).addEventListener('input', calcCost));
document.getElementById('time-route')?.addEventListener('change', calcTime);
document.getElementById('time-route')?.addEventListener('change', calcTime);
calcCost();
calcTime();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
