<?php
require_once __DIR__ . '/../includes/auth.php';
Auth::requireLogin();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pageTitle = 'Локомотивный парк';

$status = $_GET['status'] ?? '';
$search = trim($_GET['q'] ?? '');
$page   = max(1,(int)($_GET['page'] ?? 1));

$where  = ['1=1'];
$params = [];
if ($status) { $where[] = 'l.status = ?'; $params[] = $status; }
if ($search) {
    $where[] = '(l.inventory_number LIKE ? OR l.series LIKE ? OR lt.name LIKE ?)';
    $s = "%$search%"; $params = array_merge($params, [$s,$s,$s]);
}
$whereStr = implode(' AND ', $where);
$total = Database::fetchOne("SELECT COUNT(*) as c FROM locomotives l JOIN locomotive_types lt ON l.type_id=lt.id WHERE $whereStr", $params)['c'];
$pag = paginate($total, $page, 20);

$locos = Database::fetchAll(
    "SELECT l.*, lt.name as type_name
     FROM locomotives l
     JOIN locomotive_types lt ON l.type_id = lt.id
     WHERE $whereStr ORDER BY l.inventory_number LIMIT 20 OFFSET {$pag['offset']}",
    $params
);

$statusCounts = [];
foreach (['operational','maintenance','repair','decommissioned'] as $s) {
    $statusCounts[$s] = Database::count('locomotives', "status='$s'");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::can('locomotives')) {
    if (!verifyCsrf()) { setFlash('error','CSRF error'); redirect('/pages/locomotives.php'); }
    $action = $_POST['action'];
    if ($action === 'create') {
        Database::insert(
            "INSERT INTO locomotives (type_id,inventory_number,series,manufacture_year,last_to_date,next_to_date,mileage_km,status,location,notes)
             VALUES (?,?,?,?,?,?,?,?,?,?)",
            [$_POST['type_id'],$_POST['inventory_number'],$_POST['series'],
             ($_POST['manufacture_year']?:null),($_POST['last_to_date']?:null),($_POST['next_to_date']?:null),
             (int)$_POST['mileage_km'],$_POST['status'],$_POST['location'],$_POST['notes']]
        );
        setFlash('success','Локомотив добавлен');
    } elseif ($action === 'edit') {
        $lid = (int)$_POST['loco_id'];
        Database::query(
            "UPDATE locomotives SET type_id=?,series=?,manufacture_year=?,last_to_date=?,next_to_date=?,mileage_km=?,status=?,location=?,notes=? WHERE id=?",
            [$_POST['type_id'],$_POST['series'],
             ($_POST['manufacture_year']?:null),($_POST['last_to_date']?:null),($_POST['next_to_date']?:null),
             (int)$_POST['mileage_km'],$_POST['status'],$_POST['location'],$_POST['notes'],$lid]
        );
        setFlash('success','Данные локомотива обновлены');
    }
    redirect('/pages/locomotives.php');
}

$types = Database::fetchAll("SELECT id, name FROM locomotive_types ORDER BY name");
require_once __DIR__ . '/../includes/header.php';
?>

<div class="stats-grid" style="grid-template-columns:repeat(4,1fr)">
  <?php
  $statusInfo = [
      'operational'    => ['Рабочих',   '#10B981'],
      'maintenance'    => ['На ТО',      '#F59E0B'],
      'repair'         => ['В ремонте',  '#EF4444'],
      'decommissioned' => ['Списано',    '#6B7280'],
  ];
  foreach ($statusInfo as $sk => [$label, $color]): ?>
  <div class="stat-card" style="--accent:<?= $color ?>" onclick="filterStatus('<?= $sk ?>')" style="cursor:pointer">
    <div class="stat-label"><?= $label ?></div>
    <div class="stat-value"><?= $statusCounts[$sk] ?></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="filters-bar">
    <div class="form-group">
      <label>Поиск</label>
      <input type="text" id="q" value="<?= h($search) ?>" placeholder="Номер, серия…">
    </div>
    <div class="form-group">
      <label>Статус</label>
      <select id="status-sel">
        <option value="">— Все —</option>
        <?php foreach (LOCO_STATUS_LABELS as $v => $l): ?>
        <option value="<?= $v ?>" <?= $status===$v?'selected':'' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn btn-secondary" onclick="applyFilters()">Применить</button>
    <a href="/pages/locomotives.php" class="btn btn-secondary">Сбросить</a>
    <?php if (Auth::can('locomotives') && in_array(Auth::role(),['admin','mechanic'])): ?>
    <div style="margin-left:auto">
      <button class="btn btn-primary" onclick="openModal('modal-loco-add')">+ Добавить локомотив</button>
    </div>
    <?php endif; ?>
  </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Инв. номер</th>
          <th>Серия / Тип</th>
          <th>Год выпуска</th>
          <th>Пробег / наработка</th>
          <th>Последнее ТО</th>
          <th>Следующее ТО</th>
          <th>Местонахождение</th>
          <th>Статус</th>
          <?php if (in_array(Auth::role(),['admin','mechanic'])): ?>
          <th></th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($locos as $l):
          $toOverdue = $l['next_to_date'] && strtotime($l['next_to_date']) < time();
        ?>
        <tr>
          <td class="mono" style="font-weight:600"><?= h($l['inventory_number']) ?></td>
          <td>
            <div><?= h($l['series']) ?></div>
            <div style="font-size:11px;color:var(--text-muted)"><?= h($l['type_name']) ?></div>
          </td>
          <td class="mono"><?= $l['manufacture_year'] ?? '—' ?></td>
          <td class="mono"><?= formatLocoUsage($l['series'], $l['mileage_km']) ?></td>
          <td class="mono" style="font-size:12px"><?= formatDate($l['last_to_date']) ?></td>
          <td class="mono <?= $toOverdue ? 'text-red' : '' ?>" style="font-size:12px">
            <?= formatDate($l['next_to_date']) ?>
            <?php if ($toOverdue): ?><span style="font-size:10px"> ⚠</span><?php endif; ?>
          </td>
          <td style="font-size:12px"><?= h($l['location'] ?? '—') ?></td>
          <td><?= statusBadge($l['status'], LOCO_STATUS_LABELS) ?></td>
          <?php if (in_array(Auth::role(),['admin','mechanic'])): ?>
          <td>
            <button class="btn btn-secondary btn-sm"
              onclick="openEditModal(<?= htmlspecialchars(json_encode($l), ENT_QUOTES) ?>)">Ред.</button>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        <?php if (!$locos): ?>
        <tr><td colspan="9"><div class="empty-state"><div class="empty-icon">◧</div>Локомотивов не найдено</div></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= paginationLinks($pag, '/pages/locomotives.php?'.http_build_query(['status'=>$status,'q'=>$search])) ?>
</div>

<div class="modal-overlay" id="modal-loco-add">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Добавить локомотив</div>
      <button class="modal-close" onclick="closeModal('modal-loco-add')">×</button>
    </div>
    <div class="modal-body">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">
        <div class="form-grid">
          <div class="form-group">
            <label>Тип *</label>
            <select name="type_id" required>
              <?php foreach ($types as $t): ?><option value="<?= $t['id'] ?>"><?= h($t['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Инв. номер *</label>
            <input type="text" name="inventory_number" required placeholder="ВЛ80С-1234">
          </div>
          <div class="form-group">
            <label>Серия *</label>
            <input type="text" name="series" required placeholder="ВЛ80С">
          </div>
          <div class="form-group">
            <label>Год выпуска</label>
            <input type="number" name="manufacture_year" min="1950" max="<?= date('Y') ?>" placeholder="2020">
          </div>
          <div class="form-group">
            <label>Последнее ТО</label>
            <input type="date" name="last_to_date">
          </div>
          <div class="form-group">
            <label>Следующее ТО</label>
            <input type="date" name="next_to_date">
          </div>
          <div class="form-group">
            <label>Пробег / наработка</label>
            <input type="number" name="mileage_km" value="0" min="0">
          </div>
          <div class="form-group">
            <label>Статус</label>
            <select name="status">
              <?php foreach (LOCO_STATUS_LABELS as $v=>$l): ?><option value="<?= $v ?>"><?= $l ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group form-full">
            <label>Местонахождение</label>
            <input type="text" name="location" value="Депо Сургут">
          </div>
          <div class="form-group form-full">
            <label>Примечания</label>
            <textarea name="notes" rows="2"></textarea>
          </div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Добавить</button>
          <button type="button" class="btn btn-secondary" onclick="closeModal('modal-loco-add')">Отмена</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal-overlay" id="modal-loco-edit">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title" id="edit-modal-title">Редактировать локомотив</div>
      <button class="modal-close" onclick="closeModal('modal-loco-edit')">×</button>
    </div>
    <div class="modal-body">
      <form method="POST" id="edit-form">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="loco_id" id="edit-loco-id">
        <div class="form-grid">
          <div class="form-group">
            <label>Тип</label>
            <select name="type_id" id="edit-type">
              <?php foreach ($types as $t): ?><option value="<?= $t['id'] ?>"><?= h($t['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Серия</label>
            <input type="text" name="series" id="edit-series">
          </div>
          <div class="form-group">
            <label>Год выпуска</label>
            <input type="number" name="manufacture_year" id="edit-year">
          </div>
          <div class="form-group">
            <label>Пробег / наработка</label>
            <input type="number" name="mileage_km" id="edit-mileage">
          </div>
          <div class="form-group">
            <label>Последнее ТО</label>
            <input type="date" name="last_to_date" id="edit-last-to">
          </div>
          <div class="form-group">
            <label>Следующее ТО</label>
            <input type="date" name="next_to_date" id="edit-next-to">
          </div>
          <div class="form-group">
            <label>Статус</label>
            <select name="status" id="edit-status">
              <?php foreach (LOCO_STATUS_LABELS as $v=>$l): ?><option value="<?= $v ?>"><?= $l ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Местонахождение</label>
            <input type="text" name="location" id="edit-location">
          </div>
          <div class="form-group form-full">
            <label>Примечания</label>
            <textarea name="notes" id="edit-notes" rows="2"></textarea>
          </div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Сохранить</button>
          <button type="button" class="btn btn-secondary" onclick="closeModal('modal-loco-edit')">Отмена</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function applyFilters() {
  const url = new URL(window.location.href);
  url.searchParams.set('q', document.getElementById('q').value);
  url.searchParams.set('status', document.getElementById('status-sel').value);
  window.location.href = url.toString();
}
function filterStatus(s) {
  const url = new URL(window.location.href);
  url.searchParams.set('status', s);
  window.location.href = url.toString();
}
function openEditModal(loco) {
  document.getElementById('edit-loco-id').value = loco.id;
  document.getElementById('edit-modal-title').textContent = 'Редактировать: ' + loco.inventory_number;
  document.getElementById('edit-type').value = loco.type_id;
  document.getElementById('edit-series').value = loco.series;
  document.getElementById('edit-year').value = loco.manufacture_year || '';
  document.getElementById('edit-mileage').value = loco.mileage_km;
  document.getElementById('edit-last-to').value = loco.last_to_date || '';
  document.getElementById('edit-next-to').value = loco.next_to_date || '';
  document.getElementById('edit-status').value = loco.status;
  document.getElementById('edit-location').value = loco.location || '';
  document.getElementById('edit-notes').value = loco.notes || '';
  openModal('modal-loco-edit');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
