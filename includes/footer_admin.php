<?php
// Admin Footer Partial — closes admin-content, admin-main, body, html
?>
  </div><!-- /admin-content -->
</div><!-- /admin-main -->

<script>
// ---- Global search quick filter for data tables ----
const globalSearch = document.getElementById('global-search');
if (globalSearch) {
  globalSearch.addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('tbody tr').forEach(row => {
      row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
  });
}

// ---- Toast utility for admin ----
window.showToast = function(msg, type = 'info') {
  const t = document.createElement('div');
  const color = type === 'success' ? 'var(--primary)' : type === 'error' ? 'var(--error)' : 'var(--secondary)';
  const icon  = type === 'success' ? 'check_circle' : type === 'error' ? 'error' : 'info';
  t.style.cssText = `position:fixed;bottom:24px;right:24px;background:var(--inverse-surface);color:var(--inverse-on-surface);
    display:flex;align-items:center;gap:10px;padding:12px 20px;border-radius:12px;font-size:14px;font-weight:600;
    box-shadow:0 8px 24px rgba(0,0,0,0.2);z-index:9999;animation:slideUp 0.3s ease both;`;
  t.innerHTML = `<span class="material-symbols-outlined" style="color:${color}">${icon}</span><span>${msg}</span>`;
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 3500);
};

// ---- Modal helpers ----
window.openModal = function(id) {
  const m = document.getElementById(id);
  if (m) { m.classList.add('open'); document.body.style.overflow = 'hidden'; }
};
window.closeModal = function(id) {
  const m = document.getElementById(id);
  if (m) { m.classList.remove('open'); document.body.style.overflow = ''; }
};
document.querySelectorAll('.modal-overlay').forEach(m => {
  m.addEventListener('click', e => { if (e.target === m) closeModal(m.id); });
});

// Flash message auto dismiss
document.querySelectorAll('.alert').forEach(a => {
  setTimeout(() => a.style.display = 'none', 4000);
});

// ---- Notification dropdown ----
const notifBtn = document.getElementById('notif-btn');
const notifDropdown = document.getElementById('notif-dropdown');
if (notifBtn && notifDropdown) {
  notifBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    const open = notifDropdown.hasAttribute('hidden');
    notifDropdown.toggleAttribute('hidden', !open);
    notifBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
  document.addEventListener('click', () => {
    notifDropdown.setAttribute('hidden', '');
    notifBtn.setAttribute('aria-expanded', 'false');
  });
  notifDropdown.addEventListener('click', (e) => e.stopPropagation());
}
</script>
<script src="<?= APP_BASE ?>/assets/js/queue.js"></script>
<?php if (isset($extra_js)) echo $extra_js; ?>
</body>
</html>
