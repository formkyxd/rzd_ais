

function updateClock() {
  const el = document.getElementById('clock');
  if (!el) return;
  const now = new Date();
  const pad = n => String(n).padStart(2, '0');
  el.textContent = `${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
}
setInterval(updateClock, 1000);
updateClock();

function openModal(id) {
  const modal = document.getElementById(id);
  if (modal) { modal.classList.add('open'); }
}

function closeModal(id) {
  const el = id ? document.getElementById(id) : null;
  if (el) { el.classList.remove('open'); }
  else { document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open')); }
}

document.addEventListener('click', function(e) {
  if (e.target.classList.contains('modal-overlay')) closeModal();
});

document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeModal();
});

function confirmDelete(url, msg) {
  if (confirm(msg || 'Удалить запись? Это действие необратимо.')) {
    window.location.href = url;
  }
}

const flash = document.querySelector('.flash');
if (flash) {
  setTimeout(() => flash.remove(), 5000);
}

document.querySelectorAll('tr[data-href]').forEach(row => {
  row.style.cursor = 'pointer';
  row.addEventListener('click', function(e) {
    if (!e.target.closest('a, button')) {
      window.location.href = this.dataset.href;
    }
  });
});

document.querySelectorAll('.bar-fill').forEach(bar => {
  const h = bar.style.height;
  bar.style.height = '0';
  setTimeout(() => { bar.style.transition = 'height 0.6s ease'; bar.style.height = h; }, 100);
});

document.querySelectorAll('[data-filter-status]').forEach(btn => {
  btn.addEventListener('click', function() {
    const url = new URL(window.location.href);
    const val = this.dataset.filterStatus;
    if (val) url.searchParams.set('status', val);
    else url.searchParams.delete('status');
    window.location.href = url.toString();
  });
});

function printPage() { window.print(); }

document.querySelectorAll('form').forEach(form => {
  form.addEventListener('submit', function() {
    form.querySelectorAll('[required]').forEach(field => {
      if (!field.value.trim()) {
        field.style.borderColor = '#D41E2C';
      } else {
        field.style.borderColor = '';
      }
    });
  });
});

document.querySelectorAll('[title]').forEach(el => {
  el.setAttribute('data-title', el.getAttribute('title'));
});
