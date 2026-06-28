/* ============================================================
   ABC Connect — Queue AJAX Manager
   Polls /abc_connect/api/queue_fetch.php every 10 seconds
   and re-renders the kanban columns live.
   ============================================================ */

const QueueManager = (() => {
  let pollInterval = null;
  const POLL_MS = 10000;

  // ---- Render a single patient card ----
  function renderCard(patient, col) {
    const waitText = patient.wait_label || '';
    const waitClass = patient.wait_urgent ? 'style="color:var(--error)"' : '';

    const actions = buildActions(patient, col);

    return `
      <div class="q-card ${col === 'in_consultation' ? 'in-consultation' : col === 'vaccinated' ? 'vaccinated' : ''} animate-fade-in"
           data-queue-id="${patient.queue_id}"
           data-patient-id="${patient.patient_id}"
           onclick="QueueManager.openDetail(${patient.patient_id})">
        <div class="q-card__top">
          <span class="q-card__code">#${patient.patient_code}</span>
          <span class="q-card__wait" ${waitClass}>${waitText}</span>
        </div>
        <div class="q-card__name">${escHtml(patient.full_name)}</div>
        <div class="q-card__desc">${escHtml(patient.bite_label)} • ${escHtml(patient.body_location)}</div>
        <div class="q-card__footer">
          <span class="badge badge-cat-${patient.category}">Cat ${escHtml(patient.category)}</span>
          ${patient.assigned_name ? `<span class="label-sm text-muted">Dr. ${escHtml(patient.assigned_name)}</span>` : ''}
        </div>
        ${col !== 'vaccinated' ? `<div class="q-card__actions">${actions}</div>` : ''}
      </div>`;
  }

  function buildActions(p, col) {
    if (col === 'waiting') {
      return `
        <button class="q-btn q-btn-advance" onclick="event.stopPropagation(); QueueManager.advance(${p.queue_id}, 'in_consultation')">
          Call In
        </button>
        <button class="q-btn q-btn-noshow" onclick="event.stopPropagation(); QueueManager.advance(${p.queue_id}, 'no_show')">
          No Show
        </button>`;
    }
    if (col === 'in_consultation') {
      return `
        <button class="q-btn q-btn-advance" onclick="event.stopPropagation(); QueueManager.advance(${p.queue_id}, 'vaccinated')">
          Mark Vaccinated
        </button>`;
    }
    return '';
  }

  // ---- Escape HTML ----
  function escHtml(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  // ---- Update a column ----
  function updateColumn(colId, patients, col) {
    const el = document.getElementById(colId);
    if (!el) return;
    const countEl = document.getElementById(colId + '-count');
    if (countEl) countEl.textContent = String(patients.length).padStart(2, '0');

    if (patients.length === 0) {
      el.innerHTML = `<div class="empty-state"><span class="material-symbols-outlined">inbox</span><p>No patients</p></div>`;
      return;
    }
    el.innerHTML = patients.map(p => renderCard(p, col)).join('');
  }

  // ---- Fetch queue data ----
  async function fetchQueue() {
    try {
      const res = await fetch('/abc_connect/api/queue_fetch.php', { cache: 'no-store' });
      if (!res.ok) return;
      const data = await res.json();
      if (data.error) return;

      updateColumn('col-waiting',    data.waiting,    'waiting');
      updateColumn('col-consulting', data.in_consultation, 'in_consultation');
      updateColumn('col-vaccinated', data.vaccinated, 'vaccinated');

      // Update stat cards if present
      if (data.stats) {
        const el = document.getElementById('stat-today');
        if (el) el.textContent = data.stats.today_total;
        const el2 = document.getElementById('stat-followups');
        if (el2) el2.textContent = data.stats.pending_followups;
      }

      // Last updated indicator
      const ts = document.getElementById('queue-last-update');
      if (ts) {
        const now = new Date();
        ts.textContent = `Updated ${now.toLocaleTimeString()}`;
      }
    } catch (e) {
      console.warn('Queue fetch error:', e);
    }
  }

  // ---- Advance queue status ----
  async function advance(queueId, newStatus) {
    try {
      const formData = new FormData();
      formData.append('queue_id', queueId);
      formData.append('status', newStatus);

      const res = await fetch('/abc_connect/api/queue_update.php', {
        method: 'POST',
        body: formData
      });
      const data = await res.json();
      if (data.success) {
        await fetchQueue(); // refresh immediately
      } else {
        alert(data.message || 'Could not update status.');
      }
    } catch (e) {
      console.error('Queue update error:', e);
    }
  }

  // ---- Open patient detail modal ----
  function openDetail(patientId) {
    // Redirect to patient detail page
    window.location.href = `/abc_connect/admin/patient_detail.php?id=${patientId}`;
  }

  // ---- Start polling ----
  function startPolling() {
    fetchQueue(); // immediate first call
    pollInterval = setInterval(fetchQueue, POLL_MS);
  }

  function stopPolling() {
    if (pollInterval) clearInterval(pollInterval);
  }

  return { startPolling, stopPolling, advance, fetchQueue, openDetail };
})();

// Auto-start on pages that have the queue board
document.addEventListener('DOMContentLoaded', () => {
  if (document.getElementById('col-waiting')) {
    QueueManager.startPolling();
  }
});
