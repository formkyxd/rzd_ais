<?php
require_once __DIR__ . '/../includes/auth.php';
Auth::requireLogin();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pageTitle = 'Финансовый учёт — Счета';
$status   = $_GET['status'] ?? '';
$clientId = (int)($_GET['client_id'] ?? 0);
$page     = max(1,(int)($_GET['page'] ?? 1));

$where = ['1=1']; $params = [];
if ($status)   { $where[] = 'i.status = ?';    $params[] = $status; }
if ($clientId) { $where[] = 'i.client_id = ?'; $params[] = $clientId; }
$whereStr = implode(' AND ', $where);

$total = Database::fetchOne(
    "SELECT COUNT(*) as c FROM invoices i WHERE $whereStr", $params
)['c'];
$pag = paginate($total,$page,20);

$invoices = Database::fetchAll(
    "SELECT i.*, c.company_name,
            t.trip_number, a.application_number,
            COALESCE(SUM(p.amount),0) as paid_amount
     FROM invoices i
     JOIN clients c ON i.client_id = c.id
     LEFT JOIN trips t ON i.trip_id = t.id
     LEFT JOIN applications a ON i.application_id = a.id
     LEFT JOIN payments p ON p.invoice_id = i.id
     WHERE $whereStr
     GROUP BY i.id
     ORDER BY i.created_at DESC
     LIMIT 20 OFFSET {$pag['offset']}",
    $params
);

$summary = Database::fetchOne(
    "SELECT
       SUM(CASE WHEN status='issued'    THEN total_amount ELSE 0 END) as issued,
       SUM(CASE WHEN status='paid'      THEN total_amount ELSE 0 END) as paid,
       SUM(CASE WHEN status='overdue'   THEN total_amount ELSE 0 END) as overdue,
       SUM(CASE WHEN status='draft'     THEN total_amount ELSE 0 END) as draft,
       COUNT(*) as total_count
     FROM invoices"
);

$clients = Database::fetchAll("SELECT id,company_name FROM clients WHERE is_active=1 ORDER BY company_name");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrf()) { setFlash('error','CSRF error'); redirect('/pages/invoices.php'); }
    $action = $_POST['action'];

    if ($action === 'create_invoice') {
        $amount = (float)$_POST['amount'];
        $tax    = $amount * 0.18;
        $total  = $amount + $tax;
        $num    = 'СЧТ-' . date('Y') . '-' . str_pad(mt_rand(1,9999),3,'0',STR_PAD_LEFT);
        Database::insert(
            "INSERT INTO invoices (invoice_number,client_id,trip_id,application_id,issue_date,due_date,amount,tax_amount,total_amount,status,service_description,created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
            [
                $num, (int)$_POST['client_id'],
                ($_POST['trip_id'] ?: null), ($_POST['application_id'] ?: null),
                $_POST['issue_date'], $_POST['due_date'],
                $amount, $tax, $total,
                'issued',
                $_POST['service_description'],
                Auth::userId()
            ]
        );
        setFlash('success',"Счёт $num выставлен");
    } elseif ($action === 'add_payment') {
        $invId = (int)$_POST['invoice_id'];
        Database::insert(
            "INSERT INTO payments (invoice_id,payment_date,amount,payment_method,transaction_id,recorded_by) VALUES (?,?,?,?,?,?)",
            [$invId, $_POST['payment_date'], (float)$_POST['amount'], $_POST['payment_method'], $_POST['transaction_id'], Auth::userId()]
        );

        $inv = Database::fetchOne("SELECT total_amount FROM invoices WHERE id=?",[$invId]);
        $paid = Database::fetchOne("SELECT SUM(amount) as s FROM payments WHERE invoice_id=?",[$invId])['s'] ?? 0;
        if ($paid >= $inv['total_amount']) {
            Database::query("UPDATE invoices SET status='paid' WHERE id=?",[$invId]);
        }
        setFlash('success','Платёж зафиксирован');
    } elseif ($action === 'mark_overdue') {
        Database::query("UPDATE invoices SET status='overdue' WHERE status='issued' AND due_date < CURDATE()");
        setFlash('info','Просроченные счета обновлены');
    }
    redirect('/pages/invoices.php');
}

$trips   = Database::fetchAll("SELECT id,trip_number FROM trips ORDER BY created_at DESC LIMIT 50");
$appList = Database::fetchAll("SELECT id,application_number FROM applications WHERE status='approved' ORDER BY application_number");

require_once __DIR__ . '/../includes/header.php';
?>

<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:20px">
  <div class="stat-card" style="--accent:#3B82F6">
    <div class="stat-label">Выставлено</div>
    <div class="stat-value" style="font-size:22px"><?= formatMoney($summary['issued'] ?? 0) ?></div>
  </div>
  <div class="stat-card" style="--accent:#10B981">
    <div class="stat-label">Оплачено</div>
    <div class="stat-value" style="font-size:22px"><?= formatMoney($summary['paid'] ?? 0) ?></div>
  </div>
  <div class="stat-card" style="--accent:#EF4444">
    <div class="stat-label">Просрочено</div>
    <div class="stat-value text-red" style="font-size:22px"><?= formatMoney($summary['overdue'] ?? 0) ?></div>
  </div>
  <div class="stat-card" style="--accent:#6B7280">
    <div class="stat-label">Черновики</div>
    <div class="stat-value" style="font-size:22px"><?= formatMoney($summary['draft'] ?? 0) ?></div>
  </div>
</div>

<div class="flex-between mb-2">
  <div class="btn-group">
    <button class="btn <?= !$status?'btn-primary':'btn-secondary' ?> btn-sm" data-filter-status="">Все</button>
    <?php foreach (INVOICE_STATUS_LABELS as $v=>$l): ?>
    <button class="btn <?= $status===$v?'btn-primary':'btn-secondary' ?> btn-sm" data-filter-status="<?= $v ?>"><?= $l ?></button>
    <?php endforeach; ?>
  </div>
  <div class="btn-group">
    <?php if (Auth::can('finances')): ?>
    <form method="POST" style="display:inline">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="mark_overdue">
      <button type="submit" class="btn btn-secondary btn-sm">Обновить просроченные</button>
    </form>
    <button class="btn btn-primary" onclick="openModal('modal-invoice')">+ Выставить счёт</button>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="filters-bar">
    <div class="form-group">
      <label>Клиент</label>
      <select id="client-filter" onchange="filterClient(this.value)">
        <option value="">— Все клиенты —</option>
        <?php foreach ($clients as $c): ?>
        <option value="<?= $c['id'] ?>" <?= $clientId===$c['id']?'selected':'' ?>><?= h($c['company_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Номер счёта</th>
          <th>Клиент</th>
          <th>Рейс / Заявка</th>
          <th>Дата выставления</th>
          <th>Дата оплаты до</th>
          <th>Сумма</th>
          <th>НДС 18%</th>
          <th>Итого</th>
          <th>Оплачено</th>
          <th>Статус</th>
          <?php if (Auth::can('finances')): ?><th></th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($invoices as $inv):
          $isOverdue = $inv['status']==='issued' && strtotime($inv['due_date']) < time();
          $balance = $inv['total_amount'] - $inv['paid_amount'];
        ?>
        <tr>
          <td class="mono" style="font-weight:600"><?= h($inv['invoice_number']) ?></td>
          <td><?= h($inv['company_name']) ?></td>
          <td style="font-size:12px" class="mono">
            <?= h($inv['trip_number'] ?? $inv['application_number'] ?? '—') ?>
          </td>
          <td class="mono" style="font-size:12px"><?= formatDate($inv['issue_date']) ?></td>
          <td class="mono <?= ($isOverdue?'text-red':'') ?>" style="font-size:12px">
            <?= formatDate($inv['due_date']) ?>
          </td>
          <td class="mono" style="font-size:12px"><?= formatMoney($inv['amount']) ?></td>
          <td class="mono" style="font-size:12px;color:var(--text-muted)"><?= formatMoney($inv['tax_amount']) ?></td>
          <td class="mono" style="font-size:13px;font-weight:600"><?= formatMoney($inv['total_amount']) ?></td>
          <td class="mono" style="font-size:12px;color:<?= $inv['paid_amount'] >= $inv['total_amount'] ? 'var(--accent-green)' : 'var(--accent-amber)' ?>">
            <?= formatMoney($inv['paid_amount']) ?>
          </td>
          <td><?= statusBadge($inv['status'], INVOICE_STATUS_LABELS) ?></td>
          <?php if (Auth::can('finances')): ?>
          <td>
            <?php if ($inv['status'] !== 'paid' && $inv['status'] !== 'cancelled'): ?>
            <button class="btn btn-success btn-sm"
              onclick="openPayModal(<?= $inv['id'] ?>,'<?= h($inv['invoice_number']) ?>',<?= $balance ?>)">
              Оплата
            </button>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
        <?php if ($inv['service_description']): ?>
        <tr style="background:rgba(255,255,255,0.01)">
          <td colspan="<?= Auth::can('finances')?11:10 ?>" style="padding:4px 14px 8px;font-size:11px;color:var(--text-muted)">
            <?= h($inv['service_description']) ?>
          </td>
        </tr>
        <?php endif; ?>
        <?php endforeach; ?>
        <?php if (!$invoices): ?>
        <tr><td colspan="11"><div class="empty-state"><div class="empty-icon">◉</div>Счетов не найдено</div></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= paginationLinks($pag, '/pages/invoices.php?'.http_build_query(['status'=>$status,'client_id'=>$clientId])) ?>
</div>

<div class="modal-overlay" id="modal-invoice">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Выставить счёт</div>
      <button class="modal-close" onclick="closeModal('modal-invoice')">×</button>
    </div>
    <div class="modal-body">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create_invoice">
        <div class="form-grid">
          <div class="form-group">
            <label>Клиент *</label>
            <select name="client_id" required>
              <option value="">— Выберите —</option>
              <?php foreach ($clients as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['company_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Рейс</label>
            <select name="trip_id">
              <option value="">— Без рейса —</option>
              <?php foreach ($trips as $t): ?><option value="<?= $t['id'] ?>"><?= h($t['trip_number']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Заявка</label>
            <select name="application_id">
              <option value="">— Без заявки —</option>
              <?php foreach ($appList as $a): ?><option value="<?= $a['id'] ?>"><?= h($a['application_number']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Сумма (без НДС) *</label>
            <input type="number" name="amount" step="0.01" min="0" required placeholder="0.00" id="inv-amount" oninput="calcTotal()">
          </div>
          <div class="form-group">
            <label>Дата выставления *</label>
            <input type="date" name="issue_date" required value="<?= date('Y-m-d') ?>">
          </div>
          <div class="form-group">
            <label>Оплатить до *</label>
            <input type="date" name="due_date" required value="<?= date('Y-m-d', strtotime('+15 days')) ?>">
          </div>
          <div class="form-group form-full">
            <label>Итого с НДС 18%: <span id="total-preview" style="color:var(--accent-green);font-family:'IBM Plex Mono'">—</span></label>
          </div>
          <div class="form-group form-full">
            <label>Описание услуг *</label>
            <textarea name="service_description" rows="3" required placeholder="Грузоперевозка: маршрут, тоннаж…"></textarea>
          </div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Выставить счёт</button>
          <button type="button" class="btn btn-secondary" onclick="closeModal('modal-invoice')">Отмена</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal-overlay" id="modal-payment">
  <div class="modal" style="max-width:480px">
    <div class="modal-header">
      <div class="modal-title" id="pay-modal-title">Внести оплату</div>
      <button class="modal-close" onclick="closeModal('modal-payment')">×</button>
    </div>
    <div class="modal-body">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_payment">
        <input type="hidden" name="invoice_id" id="pay-invoice-id">
        <div class="form-group" style="margin-bottom:14px">
          <label>Сумма платежа, ₽ *</label>
          <input type="number" name="amount" id="pay-amount" step="0.01" min="0.01" required placeholder="0.00">
        </div>
        <div class="form-group" style="margin-bottom:14px">
          <label>Дата платежа *</label>
          <input type="date" name="payment_date" required value="<?= date('Y-m-d') ?>">
        </div>
        <div class="form-group" style="margin-bottom:14px">
          <label>Способ оплаты</label>
          <select name="payment_method">
            <option value="bank_transfer">Банковский перевод</option>
            <option value="cash">Наличные</option>
            <option value="card">Карта</option>
          </select>
        </div>
        <div class="form-group" style="margin-bottom:20px">
          <label>Номер транзакции / Реквизиты</label>
          <input type="text" name="transaction_id" placeholder="P20250101001">
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-success">Зафиксировать оплату</button>
          <button type="button" class="btn btn-secondary" onclick="closeModal('modal-payment')">Отмена</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function filterClient(v) {
  const url = new URL(window.location.href);
  url.searchParams.set('client_id', v);
  window.location.href = url.toString();
}
function calcTotal() {
  const amt = parseFloat(document.getElementById('inv-amount').value) || 0;
  const total = (amt * 1.18).toFixed(2);
  document.getElementById('total-preview').textContent = new Intl.NumberFormat('ru-RU',{style:'currency',currency:'RUB'}).format(total);
}
function openPayModal(id, num, balance) {
  document.getElementById('pay-invoice-id').value = id;
  document.getElementById('pay-modal-title').textContent = 'Оплата счёта ' + num;
  document.getElementById('pay-amount').value = balance.toFixed(2);
  openModal('modal-payment');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
