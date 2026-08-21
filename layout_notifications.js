// layout_notifications.js — the PIC-only global toast/notification system
// embedded in layout.php (rendered only when $pm_role === "pic"). Split
// out of that file's inline <script> block. The one PHP-interpolated
// value, the CSRF token, is exposed as a plain `const PM_LAYOUT_CSRF` in
// a bootstrap <script> right before the <script src> tag, same mechanism
// as pic_groups.js's PM_GROUPS_CSRF.

// ── State ──────────────────────────────────────────────────
let pmPendingIds = [];   // IDs received but not yet marked read

// ── AJAX Polling Connection (Hostinger Safe) ───────────────
// Exponential backoff on failure: stays at 3s while healthy,
// but doubles (capped at 60s) on each consecutive failure so a
// DB hiccup/outage doesn't turn into indefinite full-speed
// hammering. Resets to 3s the moment a request succeeds again.
const PM_NOTIF_BASE_DELAY = 3000;
const PM_NOTIF_MAX_DELAY  = 60000;
let pmNotifFailCount = 0;
let pmNotifTimer = null;

function fetchNotifications() {
    pmFetch('check_notifications.php')
        .then(response => {
            if (!response.ok) throw new Error('Network response was not ok');
            return response.json();
        })
        .then(data => {
            pmNotifFailCount = 0;
            if (!data || !data.new || !data.messages) return;

            // Track IDs so we can mark them read later
            if (data.unread_ids && data.unread_ids.length) {
                pmPendingIds = pmPendingIds.concat(data.unread_ids);
            }

            // Add to bell dropdown
            data.messages.forEach(msg => addToNotifList(msg));
            updateBadge();

            // Catchup (missed while offline): show a single grouped toast
            if (data.is_catchup) {
                const count = data.messages.length;
                spawnNotification(`Terdapat <b>${count}</b> markah yang dimasukkan semasa anda tiada dalam talian.`);
            } else {
                // Live: toast each individually
                data.messages.forEach(spawnNotification);
            }
        })
        .catch(error => {
            // Silently catch errors so we don't spam the console if the network drops temporarily
            // console.log('Notification check failed:', error);
            pmNotifFailCount++;
        })
        .finally(() => {
            const delay = Math.min(PM_NOTIF_BASE_DELAY * Math.pow(2, pmNotifFailCount), PM_NOTIF_MAX_DELAY);
            pmNotifTimer = setTimeout(fetchNotifications, delay);
        });
}

// Pause polling while the tab is hidden, resume (with an
// immediate check) when it becomes visible again — no point
// hitting the DB every few seconds for a tab nobody is looking at.
document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        if (pmNotifTimer) clearTimeout(pmNotifTimer);
    } else {
        if (pmNotifTimer) clearTimeout(pmNotifTimer);
        fetchNotifications();
    }
});

// Start polling when the script loads
fetchNotifications();

// ── Bell dropdown ──────────────────────────────────────────
function addToNotifList(message) {
    const list = document.getElementById('pm-notif-list');
    // Remove empty placeholder
    const empty = list.querySelector('.pm-notif-empty');
    if (empty) empty.remove();

    const item = document.createElement('div');
    item.className = 'pm-notif-item';
    const now = new Date().toLocaleTimeString('ms-MY', { hour: '2-digit', minute: '2-digit' });
    item.innerHTML = `<span>${message}</span><span class="pm-notif-time">${now}</span>`;
    list.prepend(item);
}

function updateBadge() {
    const badge = document.getElementById('pm-notif-badge');
    const unread = document.querySelectorAll('#pm-notif-list .pm-notif-item:not(.read)').length;
    if (unread > 0) {
        badge.style.display = 'flex';
        badge.textContent = unread > 99 ? '99+' : unread;
    } else {
        badge.style.display = 'none';
    }
}

// Mark all as read when bell is opened
function pmToggleNotifMenu(event) {
    event.stopPropagation();
    const menu = document.getElementById('pm-notif-menu');
    const open = menu.classList.toggle('show');
    document.getElementById('pm-notif-btn')?.setAttribute('aria-expanded', open ? 'true' : 'false');

    if (menu.classList.contains('show') && pmPendingIds.length > 0) {
        // Mark items visually as read
        document.querySelectorAll('#pm-notif-list .pm-notif-item').forEach(el => el.classList.add('read'));
        updateBadge();

        // Persist to DB
        const ids = [...pmPendingIds];
        pmPendingIds = [];

        const formData = new URLSearchParams();
        ids.forEach(id => formData.append('ids[]', id));
        formData.append('csrf_token', PM_LAYOUT_CSRF);

        pmFetch('mark_notifications_read.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString()
        }).catch(() => {});
    }
}

// Clear all
function pmClearNotifs(event) {
    event.stopPropagation();
    document.getElementById('pm-notif-list').innerHTML = '<div class="pm-notif-empty">Tiada notifikasi baharu.</div>';
    pmPendingIds = [];
    updateBadge();
    pmFetch('mark_notifications_read.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'all=1&csrf_token=' + encodeURIComponent(PM_LAYOUT_CSRF)
    }).catch(() => {});
}

// ── Toast ─────────────────────────────────────────────────
function spawnNotification(message) {
    const container = document.getElementById('pm-global-toasts');
    const toast = document.createElement('div');
    toast.className = 'pm-toast-notification';
    toast.innerHTML = `
        <div class="pm-toast-header">
            <span style="display:flex; align-items:center; gap:8px;">
                <span style="font-size:1.2rem;">🔔</span> Markah Masuk!
            </span>
            <span class="pm-toast-close">✕</span>
        </div>
        <div style="line-height:1.4;">${message}</div>`;
    toast.addEventListener('click', () => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 400);
    });
    container.appendChild(toast);

    // Request an animation frame to ensure the DOM is updated before adding the class
    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            toast.classList.add('show');
        });
    });

    setTimeout(() => {
        if (toast.parentNode) {
            toast.classList.remove('show');
            setTimeout(() => { if (toast.parentNode) toast.remove(); }, 400);
        }
    }, 6000);
}

// ── Generic save/action result toast ────────────────────────
// Shared by every PIC page that shows a "?msg=...&status=..."
// result after a POST redirect (pic_groups.php, pic_criteria.php,
// pic_tests.php, pic_levels.php, ...). Several of those pages have
// an accordion open/scroll-position restore (sessionStorage-based)
// that puts the user back wherever they were editing — a static
// alert block at the top of the page would be scrolled out of
// view in that case, so this floats instead, regardless of
// scroll position.
function spawnPmToast(message, isError) {
    const container = document.getElementById('pm-global-toasts');
    if (!container) return;
    const toast = document.createElement('div');
    toast.className = 'pm-toast-notification';
    toast.innerHTML = `
        <div class="pm-toast-header">
            <span style="display:flex; align-items:center; gap:8px;">
                <span style="font-size:1.2rem;">${isError ? '⚠️' : '✅'}</span> ${isError ? 'Ralat' : 'Berjaya'}
            </span>
            <span class="pm-toast-close">✕</span>
        </div>
        <div style="line-height:1.4;">${message}</div>`;
    toast.addEventListener('click', () => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 400);
    });
    container.appendChild(toast);
    requestAnimationFrame(() => requestAnimationFrame(() => toast.classList.add('show')));
    setTimeout(() => {
        if (toast.parentNode) {
            toast.classList.remove('show');
            setTimeout(() => { if (toast.parentNode) toast.remove(); }, 400);
        }
    }, 5000);
}
