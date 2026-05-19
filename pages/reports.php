<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
Auth::requireLogin();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$canViewReports = Auth::can('reports') || Auth::can('reports.financial') || Auth::can('reports.gov');
if (!$canViewReports) {
    setFlash('error', 'Недостаточно прав для просмотра отчётов');
    redirect(BASE_URL . 'rzd_ais/index.php');
}

$pageTitle = 'Отчёты и аналитика';

$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');

$tripStats = Database::fetchOne(
    "SELECT
       COUNT(*) as total,
       SUM(CASE WHEN t.status='completed' THEN 1 ELSE 0 END) as completed,
       SUM(CASE WHEN t.status='cancelled' THEN 1 ELSE 0 END) as cancelled,
       SUM(CASE WHEN t.status='delayed'   THEN 1 ELSE 0 END) as delayed_count,
       SUM(COALESCE(t.cargo_weight_tons,0)) as total_weight
     FROM trips t
     LEFT JOIN routes r ON t.route_id = r.id
     WHERE DATE(t.planned_departure) BETWEEN ? AND ?
       AND (r.route_type = 'freight' OR r.route_type IS NULL)",
    [$dateFrom, $dateTo]
);

$appStats = Database::fetchAll(
    "SELECT status, COUNT(*) as cnt FROM applications
     WHERE DATE(created_at) BETWEEN ? AND ?
     GROUP BY status ORDER BY cnt DESC",
    [$dateFrom, $dateTo]
);

$revenueStats = Database::fetchOne(
    "SELECT
       SUM(CASE WHEN status='paid'    THEN total_amount ELSE 0 END) as paid,
       SUM(CASE WHEN status='issued'  THEN total_amount ELSE 0 END) as issued,
       SUM(CASE WHEN status='overdue' THEN total_amount ELSE 0 END) as overdue,
       COUNT(*) as total_invoices
     FROM invoices
     WHERE issue_date BETWEEN ? AND ?",
    [$dateFrom, $dateTo]
);

$topClients = Database::fetchAll(
    "SELECT c.company_name, COUNT(a.id) as app_count,
            SUM(COALESCE(i.total_amount,0)) as revenue
     FROM clients c
     LEFT JOIN applications a ON a.client_id = c.id AND DATE(a.created_at) BETWEEN ? AND ?
     LEFT JOIN invoices i ON i.client_id = c.id AND i.status='paid' AND i.issue_date BETWEEN ? AND ?
     GROUP BY c.id, c.company_name
     HAVING app_count > 0
     ORDER BY revenue DESC LIMIT 10",
    [$dateFrom, $dateTo, $dateFrom, $dateTo]
);

$routeStats = Database::fetchAll(
    "SELECT r.name, r.departure_station, r.arrival_station,
            COUNT(t.id) as trip_count,
            SUM(CASE WHEN t.status='completed' THEN 1 ELSE 0 END) as completed
     FROM routes r
     LEFT JOIN trips t ON t.route_id = r.id AND DATE(t.planned_departure) BETWEEN ? AND ?
     WHERE r.route_type = 'freight' OR r.route_type IS NULL
     GROUP BY r.id, r.name, r.departure_station, r.arrival_station
     HAVING trip_count > 0
     ORDER BY trip_count DESC LIMIT 8",
    [$dateFrom, $dateTo]
);

$locoStats = Database::fetchAll(
    "SELECT l.inventory_number, l.series, l.status,
            COUNT(t.id) as trip_count,
            SUM(COALESCE(t.cargo_weight_tons,0)) as total_weight
     FROM locomotives l
     LEFT JOIN trips t ON t.locomotive_id = l.id
       AND DATE(t.planned_departure) BETWEEN ? AND ?
       AND (t.route_id IS NULL OR t.route_id IN (SELECT id FROM routes WHERE route_type = 'freight' OR route_type IS NULL))
     GROUP BY l.id, l.inventory_number, l.series, l.status
     ORDER BY trip_count DESC LIMIT 8",
    [$dateFrom, $dateTo]
);

$maintCost = Database::fetchOne(
    "SELECT SUM(COALESCE(cost,0)) as total_cost, COUNT(*) as total_records
     FROM maintenance_records WHERE status='completed' AND completion_date BETWEEN ? AND ?",
    [$dateFrom, $dateTo]
);

require_once __DIR__ . '/../includes/header.php';
?>

<form method="GET" class="card" style="padding:16px 20px;margin-bottom:20px">
  <div style="display:flex;gap:16px;align-items:flex-end;flex-wrap:wrap">
    <div class="form-group" style="min-width:160px">
      <label>Период с</label>
      <input type="date" name="date_from" value="<?= h($dateFrom) ?>">
    </div>
    <div class="form-group" style="min-width:160px">
      <label>Период по</label>
      <input type="date" name="date_to" value="<?= h($dateTo) ?>">
    </div>
    <button type="submit" class="btn btn-primary">Применить</button>
    <a href="?date_from=<?= date('Y-m-01') ?>&date_to=<?= date('Y-m-d') ?>" class="btn btn-secondary">Текущий месяц</a>
    <a href="?date_from=<?= date('Y-01-01') ?>&date_to=<?= date('Y-m-d') ?>" class="btn btn-secondary">С начала года</a>
    <button type="button" class="btn btn-secondary" onclick="printPage()">🖨 Печать отчёта</button>
    <div style="margin-left:auto;font-size:12px;color:var(--text-muted);align-self:center">
      Период: <?= formatDate($dateFrom) ?> — <?= formatDate($dateTo) ?>
    </div>
  </div>
</form>

<div class="stats-grid" style="grid-template-columns:repeat(6,1fr);margin-bottom:20px">
  <div class="stat-card" style="--accent:#8B5CF6">
    <div class="stat-label">Рейсов всего</div>
    <div class="stat-value"><?= $tripStats['total'] ?? 0 ?></div>
  </div>
  <div class="stat-card" style="--accent:#10B981">
    <div class="stat-label">Завершено</div>
    <div class="stat-value"><?= $tripStats['completed'] ?? 0 ?></div>
  </div>
  <div class="stat-card" style="--accent:#EF4444">
    <div class="stat-label">Отменено</div>
    <div class="stat-value"><?= $tripStats['cancelled'] ?? 0 ?></div>
  </div>
  <div class="stat-card" style="--accent:#F59E0B">
    <div class="stat-label">Перевезено, т</div>
    <div class="stat-value" style="font-size:20px"><?= number_format($tripStats['total_weight']??0,0,',',' ') ?></div>
  </div>
  <div class="stat-card" style="--accent:#10B981">
    <div class="stat-label">Доходы (оплач.)</div>
    <div class="stat-value" style="font-size:16px"><?= formatMoney($revenueStats['paid']??0) ?></div>
  </div>
  <div class="stat-card" style="--accent:#EF4444">
    <div class="stat-label">Расходы ТО</div>
    <div class="stat-value" style="font-size:16px"><?= formatMoney($maintCost['total_cost']??0) ?></div>
  </div>
</div>

<div class="grid-2">

  <div class="card">
    <div class="card-header"><div class="card-title">Заявки по статусам</div></div>
    <div class="card-body">
      <?php
      $totalApps = array_sum(array_column($appStats,'cnt'));
      $maxApp    = max(array_column($appStats,'cnt') ?: [1]);
      ?>
      <?php foreach ($appStats as $row): ?>
      <div style="margin-bottom:12px">
        <div class="flex-between" style="margin-bottom:4px">
          <span><?= STATUS_LABELS[$row['status']] ?? $row['status'] ?></span>
          <span class="mono" style="font-size:13px"><?= $row['cnt'] ?> (<?= $totalApps ? round($row['cnt']/$totalApps*100) : 0 ?>%)</span>
        </div>
        <div class="progress-bar">
          <div class="progress-fill" style="width:<?= $maxApp ? round($row['cnt']/$maxApp*100) : 0 ?>%;background:<?= STATUS_COLORS[$row['status']] ?? '#666' ?>"></div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (!$appStats): ?><div class="text-muted text-center">Нет данных за период</div><?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><div class="card-title">Финансовый отчёт за период</div></div>
    <div class="card-body">
      <?php
      $finItems = [
          ['Выставлено счетов',   $revenueStats['issued']         ?? 0, '#3B82F6'],
          ['Оплачено',            $revenueStats['paid']           ?? 0, '#10B981'],
          ['Просрочено',          $revenueStats['overdue']        ?? 0, '#EF4444'],
          ['Затраты на ТО',       $maintCost['total_cost']        ?? 0, '#F59E0B'],
      ];
      $maxFin = max(array_column($finItems, 1) ?: [1]);
      foreach ($finItems as [$label, $val, $color]):
      ?>
      <div style="margin-bottom:14px">
        <div class="flex-between" style="margin-bottom:4px">
          <span style="font-size:13px"><?= $label ?></span>
          <span class="mono" style="font-size:13px;color:<?= $color ?>"><?= formatMoney($val) ?></span>
        </div>
        <div class="progress-bar">
          <div class="progress-fill" style="width:<?= $maxFin ? round($val/$maxFin*100) : 0 ?>%;background:<?= $color ?>"></div>
        </div>
      </div>
      <?php endforeach; ?>
      <div style="margin-top:16px;padding-top:12px;border-top:1px solid var(--rzd-border);font-size:12px;color:var(--text-muted)">
        Всего счетов за период: <?= $revenueStats['total_invoices'] ?? 0 ?>
      </div>
    </div>
  </div>
</div>

<div class="grid-2">

  <div class="card">
    <div class="card-header"><div class="card-title">Топ клиентов по выручке</div></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>#</th><th>Клиент</th><th>Заявок</th><th>Выручка</th></tr></thead>
        <tbody>
          <?php foreach ($topClients as $i => $c): ?>
          <tr>
            <td class="mono" style="color:var(--text-muted)"><?= $i+1 ?></td>
            <td><?= h($c['company_name']) ?></td>
            <td class="mono" style="text-align:center"><?= $c['app_count'] ?></td>
            <td class="mono" style="color:var(--accent-green)"><?= formatMoney($c['revenue']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$topClients): ?><tr><td colspan="4" class="text-center text-muted">Нет данных</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><div class="card-title">Статистика по маршрутам</div></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Маршрут</th><th>Рейсов</th><th>Завершено</th><th>%</th></tr></thead>
        <tbody>
          <?php foreach ($routeStats as $r):
            $pct = $r['trip_count'] > 0 ? round($r['completed']/$r['trip_count']*100) : 0;
          ?>
          <tr>
            <td style="font-size:12px"><?= h($r['name']) ?></td>
            <td class="mono" style="text-align:center"><?= $r['trip_count'] ?></td>
            <td class="mono" style="text-align:center"><?= $r['completed'] ?></td>
            <td>
              <div style="display:flex;align-items:center;gap:6px">
                <div class="progress-bar" style="width:60px">
                  <div class="progress-fill" style="width:<?= $pct ?>%;background:<?= $pct>=80?'#10B981':($pct>=60?'#F59E0B':'#EF4444') ?>"></div>
                </div>
                <span class="mono" style="font-size:11px"><?= $pct ?>%</span>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$routeStats): ?><tr><td colspan="4" class="text-center text-muted">Нет данных</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header"><div class="card-title">Использование локомотивов за период</div></div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Локомотив</th>
          <th>Серия</th>
          <th>Тех. статус</th>
          <th>Рейсов за период</th>
          <th>Перевезено груза, т</th>
          <th>Загрузка</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $maxTrips = max(array_column($locoStats,'trip_count') ?: [1]);
        foreach ($locoStats as $l):
          $pct = $maxTrips > 0 ? round($l['trip_count']/$maxTrips*100) : 0;
        ?>
        <tr>
          <td class="mono" style="font-weight:600"><?= h($l['inventory_number']) ?></td>
          <td><?= h($l['series']) ?></td>
          <td><?= statusBadge($l['status'], LOCO_STATUS_LABELS) ?></td>
          <td class="mono" style="text-align:center"><?= $l['trip_count'] ?></td>
          <td class="mono"><?= number_format($l['total_weight'],1,',',' ') ?> т</td>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <div class="progress-bar" style="width:100px">
                <div class="progress-fill" style="width:<?= $pct ?>%"></div>
              </div>
              <span class="mono" style="font-size:11px;min-width:30px"><?= $pct ?>%</span>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$locoStats): ?><tr><td colspan="6" class="text-center text-muted">Нет данных за период</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div style="font-size:11px;color:var(--text-muted);text-align:right;padding:8px 0">
  Отчёт сформирован: <?= date('d.m.Y H:i') ?> | Пользователь: <?= h(displayPersonName(Auth::user()['full_name'] ?? '', Auth::user()['role_name'] ?? '')) ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
