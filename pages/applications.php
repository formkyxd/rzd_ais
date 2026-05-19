<?php
require_once __DIR__ . '/../includes/auth.php';
Auth::requireLogin();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pageTitle = 'Заявки на перевозку';
$role = Auth::role();

$status   = $_GET['status'] ?? '';
$clientId = (int)($_GET['client_id'] ?? 0);
$search   = trim($_GET['q'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 20;

$where  = ['1=1'];
$params = [];

if ($role === 'client') {
    $clientRecord = Database::fetchOne("SELECT id FROM clients WHERE user_id = ?", [Auth::userId()]);
    if ($clientRecord) {
        $where[] = 'a.client_id = ?';
        $params[] = $clientRecord['id'];
    }
}

if ($status) { $where[] = 'a.status = ?'; $params[] = $status; }
if ($clientId) { $where[] = 'a.client_id = ?'; $params[] = $clientId; }
if ($search) {
    $where[] = '(a.application_number LIKE ? OR c.company_name LIKE ? OR r.name LIKE ? OR a.cargo_type LIKE ?)';
    $s = "%$search%";
    $params = array_merge($params, [$s, $s, $s, $s]);
}

$whereStr = implode(' AND ', $where);
$total = Database::count('applications a JOIN clients c ON a.client_id = c.id LEFT JOIN routes r ON a.route_id = r.id', $whereStr, $params);
$pag = paginate($total, $page, $perPage);

$applications = Database::fetchAll(
    "SELECT a.*, c.company_name, r.name as route_name,
            COALESCE(u.full_name,'—') as dispatcher_name
     FROM applications a
     JOIN clients c ON a.client_id = c.id
     LEFT JOIN routes r ON a.route_id = r.id
     LEFT JOIN users u ON a.dispatcher_id = u.id
     WHERE $whereStr
     ORDER BY a.created_at DESC
     LIMIT $perPage OFFSET {$pag['offset']}",
    $params
);

$clients = Database::fetchAll("SELECT id, company_name FROM clients WHERE is_active=1 ORDER BY company_name");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    if (!verifyCsrf()) { setFlash('error', 'Ошибка CSRF'); redirect('/pages/applications.php'); }

    $num = 'ЗАЯ-' . date('Y') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
    $cId = (int)$_POST['client_id'];

    Database::insert(
        "INSERT INTO applications
         (application_number,client_id,route_id,departure_station,arrival_station,
          cargo_type,cargo_weight_tons,transport_type,requested_date,requested_time,
          additional_conditions,priority,status,created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'new',?)",
        [
            $num, $cId,
            ($_POST['route_id'] ?: null),
            $_POST['departure_station'] ?: 'Не указано', $_POST['arrival_station'] ?: 'Не указано',
            $_POST['cargo_type'], ($_POST['cargo_weight_tons'] ?: null),
            'freight', $_POST['requested_date'], null,
            $_POST['additional_conditions'], 'normal',
            Auth::userId()
        ]
    );
    Auth::auditLog('create', 'applications', 'application', null, ['number' => $num]);
    setFlash('success', "Заявка $num зарегистрирована");
    redirect('/pages/applications.php');
}

$routes = Database::fetchAll(
    "SELECT id, name, departure_station, arrival_station
     FROM routes
     WHERE is_active=1
       AND (route_type = 'freight' OR route_type IS NULL)
       AND name NOT LIKE '%Ханты%'
       AND departure_station NOT LIKE '%Ханты%'
       AND arrival_station NOT LIKE '%Ханты%'
     ORDER BY name"
);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex-between mb-2">
  <div class="btn-group">
    <?php foreach (array_merge([''=>'Все'], STATUS_LABELS) as $val => $lbl):
      $active = ($status === $val) ? 'btn-primary' : 'btn-secondary';
    ?>
    <button class="btn <?= $active ?> btn-sm" data-filter-status="<?= $val ?>"><?= $lbl ?></button>
    <?php endforeach; ?>
  </div>
  <?php if (Auth::can('applications')): ?>
  <button class="btn btn-primary" onclick="openModal('modal-new-app')">+ Новая заявка</button>
  <?php endif; ?>
</div>

<div class="card">
  <div class="filters-bar">
    <div class="form-group">
      <label>Поиск</label>
      <input type="text" id="search-input" placeholder="Номер, клиент, маршрут…" value="<?= h($search) ?>">
    </div>
    <div class="form-group">
      <label>Клиент</label>
      <select id="client-filter">
        <option value="">— Все клиенты —</option>
        <?php foreach ($clients as $c): ?>
        <option value="<?= $c['id'] ?>" <?= $clientId == $c['id'] ? 'selected' : '' ?>>
          <?= h($c['company_name']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn btn-secondary" onclick="applyFilters()">Применить</button>
    <a href="<?= BASE_URL ?>pages/applications.php" class="btn btn-secondary">Сбросить</a>
    <div class="form-group" style="margin-left:auto;min-width:auto;">
      <label style="opacity:0">.</label>
      <span style="color:var(--text-muted);font-size:12px;padding:9px 0;">Найдено: <?= $total ?></span>
    </div>
  </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Номер</th>
          <th>Клиент</th>
          <th>Маршрут</th>
          <th>Груз</th>
          <th>Дата</th>
          <th>Статус</th>
          <th>Диспетчер</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($applications as $app): ?>
        <tr data-href="/pages/application_view.php?id=<?= $app['id'] ?>">
          <td class="mono"><?= h($app['application_number']) ?></td>
          <td><?= h($app['company_name']) ?></td>
          <td><?= h($app['route_name'] ?? '—') ?></td>
          <td>
            <div style="font-size:12px;"><?= h($app['cargo_type'] ?: '—') ?></div>
            <?php if ($app['cargo_weight_tons']): ?>
            <div class="mono" style="font-size:11px;color:var(--text-muted)"><?= formatWeight($app['cargo_weight_tons']) ?></div>
            <?php endif; ?>
          </td>
          <td class="mono" style="font-size:12px;"><?= formatDate($app['requested_date']) ?></td>
          <td><?= statusBadge($app['status'], STATUS_LABELS) ?></td>
          <td style="font-size:12px;"><?= h(displayPersonName($app['dispatcher_name'], 'dispatcher')) ?></td>
          <td onclick="event.stopPropagation()">
            <a href="/pages/application_view.php?id=<?= $app['id'] ?>" class="btn btn-secondary btn-sm">→</a>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$applications): ?>
        <tr><td colspan="8"><div class="empty-state"><div class="empty-icon">⊞</div>Заявок не найдено</div></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= paginationLinks($pag, '/pages/applications.php?' . http_build_query(['status'=>$status,'client_id'=>$clientId,'q'=>$search])) ?>
</div>

<div class="modal-overlay" id="modal-new-app">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Регистрация заявки</div>
      <button class="modal-close" onclick="closeModal('modal-new-app')">×</button>
    </div>
    <div class="modal-body">
      <form method="POST" action="">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">
        <div class="form-grid">
          <div class="form-group">
            <label>Клиент *</label>
            <select name="client_id" required>
              <option value="">— Выберите клиента —</option>
              <?php foreach ($clients as $c): ?>
              <option value="<?= $c['id'] ?>"><?= h($c['company_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Маршрут</label>
            <select name="route_id" id="route-select" onchange="fillRoute(this)">
              <option value="">— Свободный маршрут —</option>
              <?php foreach ($routes as $r): ?>
              <option value="<?= $r['id'] ?>"
                data-dep="<?= h($r['departure_station']) ?>"
                data-arr="<?= h($r['arrival_station']) ?>">
                <?= h($r['name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <input type="hidden" name="departure_station" id="dep-station" value="Не указано">
          <input type="hidden" name="arrival_station" id="arr-station" value="Не указано">
          <div class="form-group">
            <label>Вид груза</label>
            <input type="text" name="cargo_type" placeholder="Нефтепродукты">
          </div>
          <div class="form-group">
            <label>Вес груза, тонн</label>
            <input type="number" name="cargo_weight_tons" step="0.001" min="0" placeholder="0.000">
          </div>
          <div class="form-group">
            <label>Желаемая дата *</label>
            <input type="date" name="requested_date" required value="<?= date('Y-m-d') ?>">
          </div>
          <div class="form-group form-full">
            <label>Дополнительные условия</label>
            <textarea name="additional_conditions" rows="3" placeholder="Особые требования к перевозке…"></textarea>
          </div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Зарегистрировать заявку</button>
          <button type="button" class="btn btn-secondary" onclick="closeModal('modal-new-app')">Отмена</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function applyFilters() {
  const q = document.getElementById('search-input').value;
  const cid = document.getElementById('client-filter').value;
  const url = new URL(window.location.href);
  url.searchParams.set('q', q);
  url.searchParams.set('client_id', cid);
  url.searchParams.delete('page');
  window.location.href = url.toString();
}
document.getElementById('search-input').addEventListener('keydown', e => { if(e.key==='Enter') applyFilters(); });

function fillRoute(sel) {
  const opt = sel.options[sel.selectedIndex];
  if (opt && opt.dataset.dep) {
    document.getElementById('dep-station').value = opt.dataset.dep;
    document.getElementById('arr-station').value = opt.dataset.arr;
  }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
