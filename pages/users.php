<?php
require_once __DIR__ . '/../includes/auth.php';
Auth::requireLogin();
if (Auth::role() !== 'admin') { setFlash('error','Доступ запрещён'); redirect('/index.php'); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pageTitle = 'Управление пользователями';

$users = Database::fetchAll(
    "SELECT u.*, r.display_name as role_label, r.name as role_name
     FROM users u JOIN roles r ON u.role_id = r.id
     WHERE r.name <> 'accountant'
     ORDER BY r.id, u.full_name"
);
$roles = Database::fetchAll("SELECT id, name, display_name FROM roles WHERE name <> 'accountant' ORDER BY id");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrf()) { setFlash('error','CSRF error'); redirect('/pages/users.php'); }
    $action = $_POST['action'];

    if ($action === 'create') {
        $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
        Database::insert(
            "INSERT INTO users (role_id,username,password_hash,full_name,email,phone,department,position) VALUES (?,?,?,?,?,?,?,?)",
            [(int)$_POST['role_id'],$_POST['username'],$hash,$_POST['full_name'],$_POST['email'],$_POST['phone'],$_POST['department'],$_POST['position']]
        );
        setFlash('success','Пользователь создан');
    } elseif ($action === 'toggle') {
        $uid = (int)$_POST['user_id'];
        if ($uid !== Auth::userId()) {
            Database::query("UPDATE users SET is_active = NOT is_active WHERE id=?",[$uid]);
            setFlash('info','Статус пользователя изменён');
        }
    } elseif ($action === 'reset_pass') {
        $uid = (int)$_POST['user_id'];
        $hash = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
        Database::query("UPDATE users SET password_hash=? WHERE id=?",[$hash,$uid]);
        setFlash('success','Пароль изменён');
    }
    redirect('pages/users.php');
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex-between mb-2">
  <div style="color:var(--text-muted);font-size:13px">Всего пользователей: <strong style="color:#fff"><?= count($users) ?></strong></div>
  <button class="btn btn-primary" onclick="openModal('modal-user-add')">+ Создать пользователя</button>
</div>

<div class="card">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Логин</th>
          <th>ФИО</th>
          <th>Роль</th>
          <th>Отдел / Должность</th>
          <th>E-mail</th>
          <th>Телефон</th>
          <th>Последний вход</th>
          <th>Статус</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
          <td class="mono" style="color:var(--text-muted)"><?= $u['id'] ?></td>
          <td class="mono" style="font-weight:600"><?= h($u['username']) ?></td>
          <td><?= h(displayPersonName($u['full_name'], $u['role_name'])) ?></td>
          <td>
            <span class="badge" style="background:<?php
              $roleColors = ['admin'=>'#D41E2C','manager'=>'#8B5CF6','dispatcher'=>'#3B82F6','mechanic'=>'#F59E0B','client'=>'#6B7280','gov_rep'=>'#374151'];
              echo $roleColors[$u['role_name']] ?? '#6B7280';
            ?>"><?= h($u['role_label']) ?></span>
          </td>
          <td style="font-size:12px">
            <?php if ($u['department']): ?><div><?= h($u['department']) ?></div><?php endif; ?>
            <?php if ($u['position']): ?><div style="color:var(--text-muted)"><?= h($u['position']) ?></div><?php endif; ?>
          </td>
          <td style="font-size:12px"><?= h($u['email'] ?? '—') ?></td>
          <td class="mono" style="font-size:12px"><?= h($u['phone'] ?? '—') ?></td>
          <td class="mono" style="font-size:11px;color:var(--text-muted)"><?= $u['last_login'] ? formatDateTime($u['last_login']) : 'Никогда' ?></td>
          <td>
            <?php if ($u['is_active']): ?>
            <span class="badge" style="background:#10B981">Активен</span>
            <?php else: ?>
            <span class="badge" style="background:#6B7280">Отключён</span>
            <?php endif; ?>
          </td>
          <td>
            <div class="btn-group">
              <button class="btn btn-secondary btn-sm" onclick="openPassModal(<?= $u['id'] ?>,'<?= h($u['username']) ?>')">Пароль</button>
              <?php if ($u['id'] !== Auth::userId()): ?>
              <form method="POST" style="display:inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                <button type="submit" class="btn <?= $u['is_active']?'btn-danger':'btn-success' ?> btn-sm">
                  <?= $u['is_active']?'Откл.':'Вкл.' ?>
                </button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-overlay" id="modal-user-add">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Создать пользователя</div>
      <button class="modal-close" onclick="closeModal('modal-user-add')">×</button>
    </div>
    <div class="modal-body">
      <form method="POST" autocomplete="off">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">
        <div class="form-grid">
          <div class="form-group">
            <label>Логин *</label>
            <input type="text" name="username" required autocomplete="off" placeholder="ivanov_petr">
          </div>
          <div class="form-group">
            <label>Пароль *</label>
            <input type="password" name="password" required autocomplete="new-password" placeholder="Минимум 6 символов" minlength="6">
          </div>
          <div class="form-group form-full">
            <label>ФИО *</label>
            <input type="text" name="full_name" required placeholder="Шуляк Александр Сергеевич">
          </div>
          <div class="form-group">
            <label>Роль *</label>
            <select name="role_id" required>
              <?php foreach ($roles as $r): ?><option value="<?= $r['id'] ?>"><?= h($r['display_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>E-mail</label>
            <input type="email" name="email" placeholder="ivanov@rzd-surgut.ru">
          </div>
          <div class="form-group">
            <label>Телефон</label>
            <input type="tel" name="phone" placeholder="+7-3462-00-00-00">
          </div>
          <div class="form-group">
            <label>Отдел</label>
            <input type="text" name="department" placeholder="Диспетчерская">
          </div>
          <div class="form-group">
            <label>Должность</label>
            <input type="text" name="position" placeholder="Диспетчер">
          </div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Создать пользователя</button>
          <button type="button" class="btn btn-secondary" onclick="closeModal('modal-user-add')">Отмена</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal-overlay" id="modal-pass">
  <div class="modal" style="max-width:400px">
    <div class="modal-header">
      <div class="modal-title" id="pass-modal-title">Сменить пароль</div>
      <button class="modal-close" onclick="closeModal('modal-pass')">×</button>
    </div>
    <div class="modal-body">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="reset_pass">
        <input type="hidden" name="user_id" id="pass-user-id">
        <div class="form-group" style="margin-bottom:20px">
          <label>Новый пароль *</label>
          <input type="password" name="new_password" required minlength="6" placeholder="Минимум 6 символов">
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Изменить пароль</button>
          <button type="button" class="btn btn-secondary" onclick="closeModal('modal-pass')">Отмена</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openPassModal(id, username) {
  document.getElementById('pass-user-id').value = id;
  document.getElementById('pass-modal-title').textContent = 'Пароль: ' + username;
  openModal('modal-pass');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
