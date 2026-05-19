<?php
require_once __DIR__ . '/../includes/auth.php';
Auth::requireLogin();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';


$pageTitle = 'Рейсы';
$status = $_GET['status'] ?? '';
$search = trim($_GET['q'] ?? '');
$page   = max(1,(int)($_GET['page'] ?? 1));

$where = ["(r.route_type = 'freight' OR r.route_type IS NULL)"]; $params = [];
if ($status) { $where[] = 't.status = ?'; $params[] = $status; }
if ($search) {
    $where[] = '(t.trip_number LIKE ? OR r.name LIKE ?)';
    $s = "%$search%"; $params = array_merge($params,[$s,$s]);
}
$whereStr = implode(' AND ', $where);
$total = Database::fetchOne("SELECT COUNT(*) as c FROM trips t LEFT JOIN routes r ON t.route_id=r.id WHERE $whereStr",$params)['c'];
$pag = paginate($total,$page,20);

$trips = Database::fetchAll(
    "SELECT t.*, r.name as route_name,
            l.inventory_number as loco_num, l.series as loco_series,
            u.full_name as driver_name,
            a.application_number,
            (SELECT e.description FROM trip_events e
             WHERE e.trip_id=t.id AND e.event_type IN ('delay','incident')
             ORDER BY e.event_time DESC LIMIT 1) as delay_reason
     FROM trips t
     LEFT JOIN routes r ON t.route_id = r.id
     LEFT JOIN locomotives l ON t.locomotive_id = l.id
     LEFT JOIN users u ON t.driver_user_id = u.id
     LEFT JOIN applications a ON t.application_id = a.id
     WHERE $whereStr ORDER BY t.planned_departure DESC LIMIT 20 OFFSET {$pag['offset']}",
    $params
);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::can('trips') && isset($_POST['action']) && $_POST['action']==='create') {
    if (!verifyCsrf()) { setFlash('error','CSRF'); redirect('/pages/trips.php'); }
    $num = 'РЕЙ-'.date('Y').'-'.str_pad(mt_rand(1,9999),3,'0',STR_PAD_LEFT);
    Database::insert(
        "INSERT INTO trips (trip_number,application_id,route_id,locomotive_id,driver_user_id,departure_station,arrival_station,planned_departure,planned_arrival,status,cargo_description,cargo_weight_tons,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
        [$num,($_POST['application_id']?:null),($_POST['route_id']?:null),($_POST['locomotive_id']?:null),
         ($_POST['driver_user_id']?:null),
         $_POST['departure_station'] ?: 'Не указано',$_POST['arrival_station'] ?: 'Не указано',
         $_POST['planned_departure'],$_POST['planned_arrival'],
         'planned',$_POST['cargo_description'],($_POST['cargo_weight_tons']?:null),$_POST['notes']]
    );
    setFlash('success',"Рейс $num создан");
    redirect('/pages/trips.php');
}

$applications = Database::fetchAll("SELECT id,application_number,departure_station,arrival_station FROM applications WHERE status='approved' ORDER BY application_number");
$routes = Database::fetchAll(
    "SELECT id,name,departure_station,arrival_station
     FROM routes
     WHERE is_active=1
       AND (route_type = 'freight' OR route_type IS NULL)
       AND name NOT LIKE '%Ханты%'
       AND departure_station NOT LIKE '%Ханты%'
       AND arrival_station NOT LIKE '%Ханты%'
     ORDER BY name"
);
$locos  = Database::fetchAll("SELECT id,inventory_number,series FROM locomotives WHERE status='operational' ORDER BY inventory_number");
$drivers= Database::fetchAll("SELECT u.id,u.full_name FROM users u JOIN roles r ON u.role_id=r.id WHERE r.name='dispatcher' AND u.is_active=1");

require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex-between mb-2">
  <div class="btn-group">
    <?php foreach (array_merge([''=>'Все'], TRIP_STATUS_LABELS) as $v=>$l): ?>
    <button class="btn <?= ($status===$v)?'btn-primary':'btn-secondary' ?> btn-sm" data-filter-status="<?= $v ?>"><?= $l ?></button>
    <?php endforeach; ?>
  </div>
  <?php if (Auth::can('trips')): ?>
  <button class="btn btn-primary" onclick="openModal('modal-trip')">+ Создать рейс</button>
  <?php endif; ?>
</div>

<div class="card">
  <div class="filters-bar">
    <div class="form-group">
      <label>Поиск</label>
      <input type="text" id="q" value="<?= h($search) ?>" placeholder="Номер рейса, маршрут…">
    </div>
    <button class="btn btn-secondary" onclick="applyFilters()">Применить</button>
    <a href="/pages/trips.php" class="btn btn-secondary">Сбросить</a>
    <span style="margin-left:auto;color:var(--text-muted);font-size:12px;align-self:flex-end;padding-bottom:9px">Найдено: <?= $total ?></span>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Номер рейса</th>
          <th>Заявка</th>
          <th>Маршрут</th>
          <th>Отправление план.</th>
          <th>Прибытие план.</th>
          <th>Факт. отправление</th>
          <th>Статус</th>
          <th>Причина задержки</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($trips as $t): ?>
        <tr data-href="/pages/trip_view.php?id=<?= $t['id'] ?>">
          <td class="mono" style="font-weight:600"><?= h($t['trip_number']) ?></td>
          <td class="mono" style="font-size:12px"><?= h($t['application_number'] ?? '—') ?></td>
          <td style="font-size:12px"><?= h($t['route_name'] ?? '—') ?></td>
          <td class="mono" style="font-size:12px"><?= formatDateTime($t['planned_departure']) ?></td>
          <td class="mono" style="font-size:12px"><?= formatDateTime($t['planned_arrival']) ?></td>
          <td class="mono" style="font-size:12px"><?= $t['actual_departure'] ? formatDateTime($t['actual_departure']) : '—' ?></td>
          <td><?= statusBadge($t['status'], TRIP_STATUS_LABELS) ?></td>
          <td style="font-size:12px;color:var(--text-secondary)"><?= h($t['delay_reason'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$trips): ?>
        <tr><td colspan="8"><div class="empty-state"><div class="empty-icon">⊳</div>Рейсов не найдено</div></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= paginationLinks($pag, '/pages/trips.php?'.http_build_query(['status'=>$status,'q'=>$search])) ?>
</div>

<div class="modal-overlay" id="modal-trip">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Создать рейс</div>
      <button class="modal-close" onclick="closeModal('modal-trip')">×</button>
    </div>
    <div class="modal-body">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">
        <div class="form-grid">
          <div class="form-group">
            <label>Заявка</label>
            <select name="application_id" id="app-sel" onchange="fillFromApp(this)">
              <option value="">— без заявки —</option>
              <?php foreach ($applications as $a): ?>
              <option value="<?= $a['id'] ?>" data-dep="<?= h($a['departure_station']) ?>" data-arr="<?= h($a['arrival_station']) ?>">
                <?= h($a['application_number']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Маршрут</label>
            <select name="route_id" id="route-sel" onchange="fillFromRoute(this)">
              <option value="">— Выберите —</option>
              <?php foreach ($routes as $r): ?>
              <option value="<?= $r['id'] ?>" data-dep="<?= h($r['departure_station']) ?>" data-arr="<?= h($r['arrival_station']) ?>"><?= h($r['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <input type="hidden" name="departure_station" id="trip-dep" value="Не указано">
          <input type="hidden" name="arrival_station" id="trip-arr" value="Не указано">
          <input type="hidden" name="locomotive_id" value="">
          <input type="hidden" name="driver_user_id" value="">
          <div class="form-group">
            <label>Плановое отправление *</label>
            <input type="datetime-local" name="planned_departure" required>
          </div>
          <div class="form-group">
            <label>Плановое прибытие *</label>
            <input type="datetime-local" name="planned_arrival" required>
          </div>
          <div class="form-group">
            <label>Вес груза, т</label>
            <input type="number" name="cargo_weight_tons" step="0.001" min="0">
          </div>
          <div class="form-group form-full">
            <label>Описание груза</label>
            <input type="text" name="cargo_description" placeholder="Нефтепродукты…">
          </div>
          <div class="form-group form-full">
            <label>Примечания</label>
            <textarea name="notes" rows="2"></textarea>
          </div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Создать рейс</button>
          <button type="button" class="btn btn-secondary" onclick="closeModal('modal-trip')">Отмена</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function applyFilters() {
  const url = new URL(window.location.href);
  url.searchParams.set('q', document.getElementById('q').value);
  window.location.href = url.toString();
}
function fillFromApp(sel) {
  const opt = sel.options[sel.selectedIndex];
  if (opt && opt.dataset.dep) {
    document.getElementById('trip-dep').value = opt.dataset.dep;
    document.getElementById('trip-arr').value = opt.dataset.arr;
  }
}
function fillFromRoute(sel) {
  const opt = sel.options[sel.selectedIndex];
  if (opt && opt.dataset.dep) {
    document.getElementById('trip-dep').value = opt.dataset.dep;
    document.getElementById('trip-arr').value = opt.dataset.arr;
  }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
