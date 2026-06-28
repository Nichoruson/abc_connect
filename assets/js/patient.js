/* ============================================================
   ABC Connect — Patient JS (form validation, interactions)
   ============================================================ */

document.addEventListener('DOMContentLoaded', () => {

  // ---- Booking Form Validation ----
  const triageForm = document.getElementById('triage-form');
  if (triageForm) {
    triageForm.addEventListener('submit', (e) => {
      let valid = true;
      triageForm.querySelectorAll('[required]').forEach(input => {
        if (!input.value.trim()) {
          valid = false;
          input.style.borderColor = 'var(--error)';
          input.addEventListener('input', () => {
            input.style.borderColor = '';
          }, { once: true });
        }
      });
      if (!valid) {
        e.preventDefault();
        showToast('Please fill in all required fields.', 'error');
      }
    });
  }

  // ---- Toast Notifications ----
  window.showToast = function(message, type = 'info') {
    const existing = document.querySelector('.toast');
    if (existing) existing.remove();

    const toast = document.createElement('div');
    toast.className = `toast toast--${type}`;
    toast.innerHTML = `
      <span class="material-symbols-outlined">${type === 'error' ? 'error' : type === 'success' ? 'check_circle' : 'info'}</span>
      <span>${message}</span>`;
    toast.style.cssText = `
      position: fixed; bottom: 100px; left: 50%; transform: translateX(-50%);
      background: var(--inverse-surface); color: var(--inverse-on-surface);
      display: flex; align-items: center; gap: 10px;
      padding: 12px 20px; border-radius: 50px;
      font-size: 14px; font-weight: 600;
      box-shadow: 0 8px 24px rgba(0,0,0,0.2);
      z-index: 999; white-space: nowrap;
      animation: slideUp 0.3s ease both;`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3500);
  };

  // ---- Bottom Nav Active State ----
  const path = window.location.pathname;
  document.querySelectorAll('.bottom-nav__item').forEach(item => {
    const href = item.getAttribute('href') || '';
    if (href && path.includes(href.replace('/abc_connect', '').replace('.php', ''))) {
      item.classList.add('active');
    }
  });

  // ---- Ripple Effect on Buttons ----
  document.querySelectorAll('.booking-btn, .btn').forEach(btn => {
    btn.addEventListener('click', function(e) {
      const ripple = document.createElement('span');
      const rect   = this.getBoundingClientRect();
      const size   = Math.max(rect.width, rect.height);
      ripple.style.cssText = `
        position: absolute;
        width: ${size}px; height: ${size}px;
        left: ${e.clientX - rect.left - size/2}px;
        top: ${e.clientY - rect.top - size/2}px;
        background: rgba(255,255,255,0.25);
        border-radius: 50%;
        transform: scale(0);
        animation: ripple 0.5s ease-out forwards;
        pointer-events: none;`;

      if (!this.style.position || this.style.position === 'static') {
        this.style.position = 'relative';
        this.style.overflow = 'hidden';
      }
      this.appendChild(ripple);
      setTimeout(() => ripple.remove(), 600);
    });
  });

  // ---- Date input auto-fill today if empty ----
  const dateInputs = document.querySelectorAll('input[type="date"]');
  dateInputs.forEach(d => {
    if (!d.value) {
      d.value = new Date().toISOString().split('T')[0];
    }
  });

  // ---- Scroll reveal ----
  const revealEls = document.querySelectorAll('.animate-fade-in');
  const observer = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.style.animationPlayState = 'running';
        observer.unobserve(entry.target);
      }
    });
  }, { threshold: 0.1 });
  revealEls.forEach(el => {
    el.style.animationPlayState = 'paused';
    observer.observe(el);
  });

});

// ---- CSS ripple animation injected ----
const s = document.createElement('style');
s.textContent = `@keyframes ripple { to { transform: scale(2.5); opacity: 0; } }`;
document.head.appendChild(s);
