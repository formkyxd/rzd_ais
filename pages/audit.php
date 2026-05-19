<?php
require_once __DIR__ . '/../includes/auth.php';
Auth::requireLogin();
if (Auth::role() !== 'admin') { setFlash('error','Доступ запрещён'); redirect('/index.php'); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pageTitle = 'Журнал событий безопасности';
$page = max(1,(int)($_GET['page'] ?? 1));
$module = $_GET['module'] ?? '';
$userId = (int)($_GET['user_id'] ?? 0);

$where = ['1=1']; $params = [];
if ($module) { $where[] = 'l.module = ?'; $params[] = $module; }
if ($userId) { $where[] = 'l.user_id = ?'; $params[] = $userId; }
$whereStr = implode(' AND ', $where);

$total = Database::fetchOne("SELECT COUNT(*) as c FROM audit_log l WHERE $whereStr",$params)['c'];
$pag = paginate($total,$page,50);

$logs = Database::fetchAll(
    "SELECT l.*, u.full_name, u.username
     FROM audit_log l LEFT JOIN users u ON l.user_id = u.id
     WHERE $whereStr ORDER BY l.created_at DESC LIMIT 50 OFFSET {$pag['offset']}",
    $params
);

$modules = Database::fetchAll("SELECT DISTINCT module FROM audit_log ORDER BY module");
$users   = Database::fetchAll("SELECT id,full_name,username FROM users ORDER BY full_name");

require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
  <div class="filters-bar">
    <div class="form-group">
      <label>Модуль</label>
      <select id="mod-f" onchange="applyFilter()">
        <option value="">— Все модули —</option>
        <?php foreach ($modules as $m): ?>
        <option value="<?= h($m['module']) ?>" <?= $module===$m['module']?'selected':'' ?>><?= h($m['module']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Пользователь</label>
      <select id="usr-f" onchange="applyFilter()">
        <option value="">— Все пользователи —</option>
        <?php foreach ($users as $u): ?>
        <option value="<?= $u['id'] ?>" <?= $userId===$u['id']?'selected':'' ?>><?= h($u['full_name']) ?> (<?= h($u['username']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <a href="/pages/audit.php" class="btn btn-secondary">Сбросить</a>
    <span style="margin-left:auto;font-size:12px;color:var(--text-muted);align-self:center">Записей: <?= $total ?></span>
  </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Время</th>
          <th>Пользователь</th>
          <th>Действие</th>
          <th>Модуль</th>
          <th>Объект</th>
          <th>IP-адрес</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($logs as $log): ?>
        <tr>
          <td class="mono" style="color:var(--text-muted);font-size:11px"><?= $log['id'] ?></td>
          <td class="mono" style="font-size:11px"><?= formatDateTime($log['created_at']) ?></td>
          <td style="font-size:12px">
            <?php if ($log['full_name']): ?>
            <div style="font-weight:500"><?= h($log['full_name']) ?></div>
            <div class="mono" style="font-size:10px;color:var(--text-muted)"><?= h($log['username']) ?></div>
            <?php else: ?>
            <span style="color:var(--text-muted)">Система</span>
            <?php endif; ?>
          </td>
          <td>
            <?php
            $actionColors = [
                'login' => '#10B981', 'logout' => '#6B7280',
                'create' => '#3B82F6', 'update' => '#F59E0B',
                'delete' => '#EF4444', 'status_change' => '#8B5CF6',
            ];
            $color = $actionColors[$log['action']] ?? '#6B7280';
            ?>
            <span class="badge" style="background:<?= $color ?>"><?= h($log['action']) ?></span>
          </td>
          <td class="mono" style="font-size:12px;color:var(--text-secondary)"><?= h($log['module'] ?? '—') ?></td>
          <td class="mono" style="font-size:11px;color:var(--text-muted)">
            <?= h($log['entity_type'] ?? '') ?><?= $log['entity_id'] ? ' #'.$log['entity_id'] : '' ?>
          </td>
          <td class="mono" style="font-size:11px;color:var(--text-muted)"><?= h($log['ip_address'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$logs): ?>
        <tr><td colspan="7"><div class="empty-state"><div class="empty-icon">▣</div>Записей в журнале нет</div></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= paginationLinks($pag, '/pages/audit.php?'.http_build_query(['module'=>$module,'user_id'=>$userId])) ?>
</div>

<script>
function applyFilter() {
  const url = new URL(window.location.href);
  url.searchParams.set('module', document.getElementById('mod-f').value);
  url.searchParams.set('user_id', document.getElementById('usr-f').value);
  url.searchParams.delete('page');
  window.location.href = url.toString();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
