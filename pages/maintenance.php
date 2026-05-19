<?php
require_once __DIR__ . '/../includes/auth.php';
Auth::requireLogin();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pageTitle = 'Техническое обслуживание';
$status = $_GET['status'] ?? '';
$locoId = (int)($_GET['loco_id'] ?? 0);
$page   = max(1,(int)($_GET['page'] ?? 1));

$allowedTypeSql = "UPPER(REPLACE(mt.code,' ','')) IN ('ТО-3','ТО3','ТО-5А','ТО5А','ТО-5Б','ТО5Б','ТО-5В','ТО5В','ТО-5Г','ТО5Г','ТО-5Д','ТО5Д','ТО-5Е','ТО5Е','ТО-5Ж','ТО5Ж','ТР-1','ТР1','ТР-2','ТР2','ТР-3','ТР3','СР','КР')";
$where = ["m.status <> 'cancelled'", $allowedTypeSql]; $params = [];
if ($status) { $where[] = 'm.status = ?'; $params[] = $status; }
if ($locoId) { $where[] = 'm.locomotive_id = ?'; $params[] = $locoId; }
$whereStr = implode(' AND ', $where);

$total = Database::fetchOne(
    "SELECT COUNT(*) as c
     FROM maintenance_records m
     JOIN maintenance_types mt ON m.maintenance_type_id = mt.id
     WHERE $whereStr", $params
)['c'];
$pag = paginate($total, $page, 20);

$records = Database::fetchAll(
    "SELECT m.*, l.inventory_number, l.series,
            mt.name as mtype_name, mt.code as mtype_code,
            u.full_name as mechanic_name, ur.name as mechanic_role
     FROM maintenance_records m
     JOIN locomotives l ON m.locomotive_id = l.id
     JOIN maintenance_types mt ON m.maintenance_type_id = mt.id
     LEFT JOIN users u ON m.mechanic_id = u.id
     LEFT JOIN roles ur ON u.role_id = ur.id
     WHERE $whereStr
     ORDER BY m.planned_date DESC
     LIMIT 20 OFFSET {$pag['offset']}",
    $params
);

$statusCounts = [];
foreach (['planned','in_progress','completed'] as $s) {
    $row = Database::fetchOne(
        "SELECT COUNT(*) as c
         FROM maintenance_records m
         JOIN maintenance_types mt ON m.maintenance_type_id = mt.id
         WHERE m.status=? AND $allowedTypeSql",
        [$s]
    );
    $statusCounts[$s] = (int)($row['c'] ?? 0);
}
$overdueCount = Database::fetchOne(
    "SELECT COUNT(*) as c
     FROM maintenance_records m
     JOIN maintenance_types mt ON m.maintenance_type_id = mt.id
     WHERE m.status='planned' AND m.planned_date < CURDATE()
       AND $allowedTypeSql"
)['c'];

$locos    = Database::fetchAll("SELECT id,inventory_number,series FROM locomotives ORDER BY inventory_number");
$mtypes   = Database::fetchAll(
    "SELECT id,name,code
     FROM maintenance_types
     WHERE UPPER(REPLACE(code,' ','')) IN ('ТО-3','ТО3','ТО-5А','ТО5А','ТО-5Б','ТО5Б','ТО-5В','ТО5В','ТО-5Г','ТО5Г','ТО-5Д','ТО5Д','ТО-5Е','ТО5Е','ТО-5Ж','ТО5Ж','ТР-1','ТР1','ТР-2','ТР2','ТР-3','ТР3','СР','КР')
     ORDER BY FIELD(UPPER(REPLACE(code,' ','')),'ТО-3','ТО3','ТО-5А','ТО5А','ТО-5Б','ТО5Б','ТО-5В','ТО5В','ТО-5Г','ТО5Г','ТО-5Д','ТО5Д','ТО-5Е','ТО5Е','ТО-5Ж','ТО5Ж','ТР-1','ТР1','ТР-2','ТР2','ТР-3','ТР3','СР','КР'), name"
);
$mechanics= Database::fetchAll("SELECT u.id,u.full_name,r.name as role_name FROM users u JOIN roles r ON u.role_id=r.id WHERE r.name IN('mechanic','admin') AND u.is_active=1");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrf()) { setFlash('error','CSRF error'); redirect(BASE_URL . 'pages/maintenance.php'); }
    $action = $_POST['action'];

    if ($action === 'create') {
        Database::insert(
            "INSERT INTO maintenance_records (locomotive_id,maintenance_type_id,planned_date,status,mechanic_id,mileage_at_maintenance,work_performed,parts_replaced,cost,next_maintenance_date,notes)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)",
            [
                (int)$_POST['locomotive_id'],
                (int)$_POST['maintenance_type_id'],
                $_POST['planned_date'],
                $_POST['status'],
                ($_POST['mechanic_id'] ?: null),
                ($_POST['mileage_at_maintenance'] ?: null),
                $_POST['work_performed'] ?? '',
                $_POST['parts_replaced'] ?? '',
                null,
                ($_POST['next_maintenance_date'] ?: null),
                $_POST['notes']
            ]
        );
        Auth::auditLog('create','maintenance','maintenance_record',null,[]);
        setFlash('success','Запись о ТО создана');
    } elseif ($action === 'update_status') {
        $mid = (int)$_POST['record_id'];
        $newStatus = $_POST['new_status'];
        $allowed = ['planned','in_progress','completed'];
        if (in_array($newStatus, $allowed)) {
            $updateFields = "status=?";
            $updateParams = [$newStatus];
            if ($newStatus === 'in_progress') {
                $updateFields .= ",actual_date=CURDATE()";
            } elseif ($newStatus === 'completed') {
                $updateFields .= ",completion_date=CURDATE()";
                if (!empty($_POST['work_performed'])) {
                    $updateFields .= ",work_performed=?";
                    $updateParams[] = $_POST['work_performed'];
                }
            }
            $updateParams[] = $mid;
            Database::query("UPDATE maintenance_records SET $updateFields WHERE id=?", $updateParams);
            setFlash('success','Статус ТО обновлён');
        }
    }
    redirect(BASE_URL . 'pages/maintenance.php');
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="stats-grid" style="grid-template-columns:repeat(5,1fr);margin-bottom:20px">
  <div class="stat-card" style="--accent:#F59E0B;cursor:pointer" onclick="filterSt('planned')">
    <div class="stat-label">Запланировано</div>
    <div class="stat-value"><?= $statusCounts['planned'] ?></div>
  </div>
  <div class="stat-card" style="--accent:#EF4444;cursor:pointer" onclick="filterSt('planned_overdue')">
    <div class="stat-label">Просрочено</div>
    <div class="stat-value text-red"><?= $overdueCount ?></div>
  </div>
  <div class="stat-card" style="--accent:#8B5CF6;cursor:pointer" onclick="filterSt('in_progress')">
    <div class="stat-label">В процессе</div>
    <div class="stat-value"><?= $statusCounts['in_progress'] ?></div>
  </div>
  <div class="stat-card" style="--accent:#10B981;cursor:pointer" onclick="filterSt('completed')">
    <div class="stat-label">Завершено</div>
    <div class="stat-value"><?= $statusCounts['completed'] ?></div>
  </div>
  <div class="stat-card" style="--accent:#D41E2C">
    <div class="stat-label">Всего записей</div>
    <div class="stat-value"><?= $total ?></div>
  </div>
</div>

<div class="flex-between mb-2">
  <div class="btn-group">
    <button class="btn <?= !$status?'btn-primary':'btn-secondary' ?> btn-sm" data-filter-status="">Все</button>
    <?php foreach (['planned'=>'Запланировано','in_progress'=>'В процессе','completed'=>'Завершено'] as $v=>$l): ?>
    <button class="btn <?= $status===$v?'btn-primary':'btn-secondary' ?> btn-sm" data-filter-status="<?= $v ?>"><?= $l ?></button>
    <?php endforeach; ?>
  </div>
  <?php if (in_array(Auth::role(),['admin','mechanic'])): ?>
  <button class="btn btn-primary" onclick="openModal('modal-maint-add')">+ Добавить запись ТО</button>
  <?php endif; ?>
</div>

<div class="card">
  <div class="filters-bar">
    <div class="form-group">
      <label>Локомотив</label>
      <select id="loco-filter" onchange="filterLoco(this.value)">
        <option value="">— Все локомотивы —</option>
        <?php foreach ($locos as $l): ?>
        <option value="<?= $l['id'] ?>" <?= $locoId===$l['id']?'selected':'' ?>>
          <?= h($l['series'].' '.$l['inventory_number']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Локомотив</th>
          <th>Вид ТО/Ремонта</th>
          <th>Плановая дата</th>
          <th>Фактическая дата</th>
          <th>Дата завершения</th>
          <th>Механик</th>
          <th>Статус</th>
          <?php if (in_array(Auth::role(),['admin','mechanic'])): ?>
          <th></th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($records as $r):
          $isOverdue = $r['status']==='planned' && strtotime($r['planned_date']) < time();
        ?>
        <tr>
          <td>
            <div class="mono" style="font-weight:600"><?= h($r['inventory_number']) ?></div>
            <div style="font-size:11px;color:var(--text-muted)"><?= h($r['series']) ?></div>
          </td>
          <td>
            <div><?= h($r['mtype_name']) ?></div>
            <div class="mono" style="font-size:11px;color:var(--text-muted)"><?= h($r['mtype_code']) ?></div>
          </td>
          <td class="mono <?= $isOverdue?'text-red':'' ?>" style="font-size:12px">
            <?= formatDate($r['planned_date']) ?>
            <?= $isOverdue ? '<span title="Просрочено"> ⚠</span>' : '' ?>
          </td>
          <td class="mono" style="font-size:12px"><?= formatDate($r['actual_date']) ?></td>
          <td class="mono" style="font-size:12px"><?= formatDate($r['completion_date']) ?></td>
          <td style="font-size:12px"><?= h(displayPersonName($r['mechanic_name'] ?? '—', $r['mechanic_role'] ?? null)) ?></td>
          <td><?= statusBadge($r['status'], ['planned'=>'Запланировано','in_progress'=>'В процессе','completed'=>'Завершено']) ?></td>
          <?php if (in_array(Auth::role(),['admin','mechanic'])): ?>
          <td>
            <?php if ($r['status'] !== 'completed'): ?>
            <button class="btn btn-secondary btn-sm"
              onclick="openStatusModal(<?= $r['id'] ?>,'<?= $r['status'] ?>')">Обновить</button>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
        <?php if ($r['work_performed'] || $r['notes']): ?>
        <tr style="background:rgba(255,255,255,0.01)">
          <td colspan="<?= in_array(Auth::role(),['admin','mechanic'])?8:7 ?>" style="padding:6px 14px;font-size:12px;color:var(--text-secondary)">
            <strong style="color:var(--text-muted)">Выполненные работы:</strong> <?= h($r['work_performed']) ?>
            <?php if ($r['parts_replaced']): ?> | <strong style="color:var(--text-muted)">Заменено:</strong> <?= h($r['parts_replaced']) ?><?php endif; ?>
            <?php if ($r['notes']): ?> | <strong style="color:var(--text-muted)">Город ремонта:</strong> <?= h($r['notes']) ?><?php endif; ?>
          </td>
        </tr>
        <?php endif; ?>
        <?php endforeach; ?>
        <?php if (!$records): ?>
        <tr><td colspan="8"><div class="empty-state"><div class="empty-icon">⊛</div>Записей не найдено</div></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= paginationLinks($pag, 'pages/maintenance.php?'.http_build_query(['status'=>$status,'loco_id'=>$locoId])) ?>
</div>

<div class="modal-overlay" id="modal-maint-add">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Добавить запись ТО/Ремонта</div>
      <button class="modal-close" onclick="closeModal('modal-maint-add')">×</button>
    </div>
    <div class="modal-body">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">
        <div class="form-grid">
          <div class="form-group">
            <label>Локомотив *</label>
            <select name="locomotive_id" required>
              <option value="">— Выберите —</option>
              <?php foreach ($locos as $l): ?><option value="<?= $l['id'] ?>"><?= h($l['series'].' '.$l['inventory_number']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Вид ТО/Ремонта *</label>
            <select name="maintenance_type_id" id="maintenance-type" required onchange="toggleRepairCity()">
              <option value="">— Выберите —</option>
              <?php foreach ($mtypes as $mt): ?><option value="<?= $mt['id'] ?>" data-code="<?= h($mt['code']) ?>"><?= h($mt['code'].' — '.$mt['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Плановая дата *</label>
            <input type="date" name="planned_date" required value="<?= date('Y-m-d') ?>">
          </div>
          <div class="form-group">
            <label>Статус</label>
            <select name="status" id="maint-create-status" onchange="toggleCreateWorkFields()">
              <option value="planned">Запланировано</option>
              <option value="in_progress">В процессе</option>
              <option value="completed">Завершено</option>
            </select>
          </div>
          <div class="form-group">
            <label>Механик</label>
            <select name="mechanic_id">
              <option value="">— Не назначен —</option>
              <?php foreach ($mechanics as $m): ?><option value="<?= $m['id'] ?>"><?= h(displayPersonName($m['full_name'], $m['role_name'])) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Пробег / наработка при ТО</label>
            <input type="number" name="mileage_at_maintenance" min="0" placeholder="0">
          </div>
          <div class="form-group">
            <label>Дата след. ТО</label>
            <input type="date" name="next_maintenance_date">
          </div>
          <div class="form-group form-full" id="repair-city-group" style="display:none">
            <label>Город ремонта</label>
            <select name="notes" id="repair-city">
              <option value="">— Выберите город —</option>
              <?php foreach (maintenanceCityOptions() as $city): ?>
              <option value="<?= h($city) ?>"><?= h($city) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group form-full" id="create-work-group">
            <label>Выполненные работы</label>
            <textarea name="work_performed" rows="2" placeholder="Описание выполненных работ…"></textarea>
          </div>
          <div class="form-group form-full" id="create-parts-group">
            <label>Заменённые детали</label>
            <textarea name="parts_replaced" rows="2" placeholder="Перечень заменённых деталей…"></textarea>
          </div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Сохранить</button>
          <button type="button" class="btn btn-secondary" onclick="closeModal('modal-maint-add')">Отмена</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal-overlay" id="modal-status-upd">
  <div class="modal" style="max-width:440px">
    <div class="modal-header">
      <div class="modal-title">Обновить статус ТО</div>
      <button class="modal-close" onclick="closeModal('modal-status-upd')">×</button>
    </div>
    <div class="modal-body">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="update_status">
        <input type="hidden" name="record_id" id="upd-record-id">
        <div class="form-group" style="margin-bottom:16px">
          <label>Новый статус</label>
          <select name="new_status" id="upd-status" onchange="toggleUpdateWorkFields()">
            <option value="planned">Запланировано</option>
            <option value="in_progress">В процессе</option>
            <option value="completed">Завершено</option>
          </select>
        </div>
        <div class="form-group" id="upd-work-group" style="margin-bottom:16px">
          <label>Что сделано</label>
          <textarea name="work_performed" rows="3" placeholder="Описание выполненных работ…"></textarea>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Сохранить</button>
          <button type="button" class="btn btn-secondary" onclick="closeModal('modal-status-upd')">Отмена</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function filterSt(s) {
  const url = new URL(window.location.href);
  if (s === 'planned_overdue') {
    url.searchParams.set('status', 'planned');
  } else {
    url.searchParams.set('status', s);
  }
  window.location.href = url.toString();
}
function filterLoco(v) {
  const url = new URL(window.location.href);
  url.searchParams.set('loco_id', v);
  window.location.href = url.toString();
}
function openStatusModal(id, currentStatus) {
  document.getElementById('upd-record-id').value = id;
  document.getElementById('upd-status').value = currentStatus;
  toggleUpdateWorkFields();
  openModal('modal-status-upd');
}
function toggleRepairCity() {
  const sel = document.getElementById('maintenance-type');
  const opt = sel.options[sel.selectedIndex];
  const code = opt ? (opt.dataset.code || '').toUpperCase() : '';
  document.getElementById('repair-city-group').style.display = code === 'КР' ? '' : 'none';
}
function toggleCreateWorkFields() {
  const done = document.getElementById('maint-create-status').value === 'completed';
  document.getElementById('create-work-group').style.display = done ? '' : 'none';
  document.getElementById('create-parts-group').style.display = done ? '' : 'none';
  document.querySelector('[name="work_performed"]').disabled = !done;
  document.querySelector('[name="parts_replaced"]').disabled = !done;
}
function toggleUpdateWorkFields() {
  const done = document.getElementById('upd-status').value === 'completed';
  const group = document.getElementById('upd-work-group');
  group.style.display = done ? '' : 'none';
  group.querySelector('textarea').disabled = !done;
}
toggleCreateWorkFields();
toggleUpdateWorkFields();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
