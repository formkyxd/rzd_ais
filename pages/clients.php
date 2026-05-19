<?php
require_once __DIR__ . '/../includes/auth.php';
Auth::requireLogin();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pageTitle = 'Клиенты';
$search = trim($_GET['q'] ?? '');
$page   = max(1,(int)($_GET['page'] ?? 1));

$where = ['1=1']; $params = [];
if ($search) {
    $where[] = '(c.company_name LIKE ? OR c.inn LIKE ? OR c.contact_person LIKE ? OR c.contract_number LIKE ?)';
    $s = "%$search%"; $params = array_merge($params,[$s,$s,$s,$s]);
}
$whereStr = implode(' AND ', $where);
$total = Database::count('clients c', $whereStr, $params);
$pag = paginate($total,$page,20);

$clients = Database::fetchAll(
    "SELECT c.*,
            (SELECT COUNT(*) FROM applications WHERE client_id=c.id) as app_count,
            (SELECT COUNT(*) FROM invoices WHERE client_id=c.id AND status='overdue') as overdue_count
     FROM clients c WHERE $whereStr ORDER BY c.company_name LIMIT 20 OFFSET {$pag['offset']}",
    $params
);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrf()) { setFlash('error','CSRF'); redirect('/pages/clients.php'); }
    if ($_POST['action'] === 'create') {
        Database::insert(
            "INSERT INTO clients (company_name,inn,contact_person,email,phone,address,contract_number,contract_date,notes) VALUES (?,?,?,?,?,?,?,?,?)",
            [$_POST['company_name'],$_POST['inn'],$_POST['contact_person'],$_POST['email'],
             $_POST['phone'],$_POST['address'],$_POST['contract_number'],
             ($_POST['contract_date']?:null),$_POST['notes']]
        );
        setFlash('success','Клиент добавлен');
        redirect('/pages/clients.php');
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex-between mb-2">
  <form class="filters-bar" style="background:none;border:none;padding:0;flex:1" method="GET">
    <div class="form-group">
      <label>Поиск</label>
      <input type="text" name="q" value="<?= h($search) ?>" placeholder="Название, ИНН, контакт, договор…">
    </div>
    <button type="submit" class="btn btn-secondary">Найти</button>
    <a href="/pages/clients.php" class="btn btn-secondary">Сбросить</a>
    <span style="color:var(--text-muted);font-size:12px;align-self:center">Найдено: <?= $total ?></span>
  </form>
  <?php if (Auth::can('clients') && in_array(Auth::role(),['admin','dispatcher'])): ?>
  <button class="btn btn-primary" onclick="openModal('modal-client')">+ Добавить клиента</button>
  <?php endif; ?>
</div>

<div class="card">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Организация</th>
          <th>ИНН</th>
          <th>Контактное лицо</th>
          <th>Телефон</th>
          <th>E-mail</th>
          <th>Договор</th>
          <th>Дата договора</th>
          <th>Заявок</th>
          <th>Просроч. счета</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($clients as $c): ?>
        <tr>
          <td>
            <div style="font-weight:600"><?= h($c['company_name']) ?></div>
            <?php if (!$c['is_active']): ?><span class="badge" style="background:#6B7280;font-size:10px">Неактивен</span><?php endif; ?>
          </td>
          <td class="mono"><?= h($c['inn'] ?? '—') ?></td>
          <td><?= h($c['contact_person'] ?? '—') ?></td>
          <td class="mono" style="font-size:12px"><?= h($c['phone'] ?? '—') ?></td>
          <td style="font-size:12px"><?= h($c['email'] ?? '—') ?></td>
          <td class="mono" style="font-size:12px"><?= h($c['contract_number'] ?? '—') ?></td>
          <td class="mono" style="font-size:12px"><?= formatDate($c['contract_date']) ?></td>
          <td style="text-align:center">
            <span style="font-family:'IBM Plex Mono';font-size:14px"><?= $c['app_count'] ?></span>
          </td>
          <td style="text-align:center">
            <?php if ($c['overdue_count'] > 0): ?>
            <span class="badge" style="background:#EF4444"><?= $c['overdue_count'] ?></span>
            <?php else: ?>
            <span style="color:var(--text-muted)">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$clients): ?>
        <tr><td colspan="9"><div class="empty-state"><div class="empty-icon">◎</div>Клиентов не найдено</div></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= paginationLinks($pag, '/pages/clients.php?q='.urlencode($search)) ?>
</div>

<div class="modal-overlay" id="modal-client">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Добавить клиента</div>
      <button class="modal-close" onclick="closeModal('modal-client')">×</button>
    </div>
    <div class="modal-body">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">
        <div class="form-grid">
          <div class="form-group form-full">
            <label>Название организации *</label>
            <input type="text" name="company_name" required placeholder="ООО «Название»">
          </div>
          <div class="form-group">
            <label>ИНН</label>
            <input type="text" name="inn" placeholder="1234567890" maxlength="12">
          </div>
          <div class="form-group">
            <label>Контактное лицо</label>
            <input type="text" name="contact_person" placeholder="Мешкова Екатерина Игоревна">
          </div>
          <div class="form-group">
            <label>Телефон</label>
            <input type="tel" name="phone" placeholder="+7-xxx-xxx-xx-xx">
          </div>
          <div class="form-group">
            <label>E-mail</label>
            <input type="email" name="email" placeholder="contact@company.ru">
          </div>
          <div class="form-group">
            <label>Номер договора</label>
            <input type="text" name="contract_number" placeholder="ДГ-2025-001">
          </div>
          <div class="form-group">
            <label>Дата договора</label>
            <input type="date" name="contract_date">
          </div>
          <div class="form-group form-full">
            <label>Адрес</label>
            <input type="text" name="address" placeholder="г. Москва, ул…">
          </div>
          <div class="form-group form-full">
            <label>Примечания</label>
            <textarea name="notes" rows="2"></textarea>
          </div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Добавить клиента</button>
          <button type="button" class="btn btn-secondary" onclick="closeModal('modal-client')">Отмена</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
