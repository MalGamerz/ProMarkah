// layout.js — static (no PHP interpolation) behavior shared by every page
// that includes layout.php: session-expiry-aware fetch, idle keep-alive,
// theme toggle, sidebar, dropdowns, and alert auto-dismiss.
//
// Split out of layout.php's inline <script> because none of this code reads
// a PHP-templated value — it's plain, cacheable JS. The one block that still
// lives inline in layout.php (PIC notification polling/toasts) interpolates
// the CSRF token directly and can't move here without first exposing that
// token via a data-* attribute; see the comment above that block.
//
// Loaded via a plain <script src="layout.js?v=..."> with no defer/async, at
// the same point in <head> the old inline block occupied — that matters for
// the theme pre-paint IIFE below (see its own comment).

// Shared wrapper around fetch() for same-origin AJAX calls to our own PHP
// endpoints. auth_check.php answers an idle-timed-out session with a 401 +
// {"error":"session_expired"} JSON body, but only when it sees an
// AJAX-flavoured request — so every call site needs to (a) actually send
// that signal and (b) handle the 401 instead of silently parsing/using
// whatever came back (previously: dropdowns rendering empty, drafts
// vanishing, polls going quiet — all with no indication to the user that
// they'd been logged out).
window.pmSessionExpiredShown = false;
function pmFetch(url, options) {
    options = options || {};
    options.headers = Object.assign({ 'X-Requested-With': 'XMLHttpRequest' }, options.headers || {});
    return fetch(url, options).then(function (response) {
        if (response.status !== 401) return response;
        return response.clone().json().catch(function () { return {}; }).then(function (data) {
            if (data && data.error === 'session_expired' && !window.pmSessionExpiredShown) {
                window.pmSessionExpiredShown = true;
                alert('Sesi anda telah tamat kerana tiada aktiviti. Sila log masuk semula.');
                window.location.href = 'login.php';
            }
            // Swallowed by callers' existing .catch(() => {}) where present;
            // stops a session_expired response from being treated as real data.
            throw new Error('session_expired');
        });
    });
}

// Keep-alive: auth_check.php's idle timeout only resets on an actual request
// hitting the server, so a user who's genuinely reading/typing on one page
// with no navigation and no AJAX would still get timed out. Track real input
// (mouse/keyboard/touch/scroll) and, once a minute, ping the server ONLY if
// that activity happened since the last ping — a user who's truly away (tab
// open, not touching anything) still times out normally, since no pings fire
// for them. Gated on <html data-pm-role> (not <body> — this script runs in
// <head>, before <body> exists) instead of PHP-templated here (this file is
// static) — no ping fires for a guest session, same as before.
(function () {
    if (document.documentElement.dataset.pmRole === 'guest') return;

    let pmActiveSinceLastPing = false;
    const markActive = function () { pmActiveSinceLastPing = true; };
    ['mousemove', 'keydown', 'scroll', 'click', 'touchstart'].forEach(function (evt) {
        document.addEventListener(evt, markActive, { passive: true });
    });

    setInterval(function () {
        if (!pmActiveSinceLastPing) return;
        pmActiveSinceLastPing = false;
        pmFetch('keepalive.php').catch(function () {});
    }, 60000);
})();

// Theme pre-paint: runs synchronously in <head> (this script has no
// defer/async and is placed where the old inline block was) so the correct
// theme class is on <html> before first paint — avoids a flash of the wrong
// theme. Must stay this early; moving it to run after <body> renders would
// reintroduce the flash.
(function () {
    let savedTheme = localStorage.getItem('pm-theme');

    // Default theme for every role (first visit, nothing saved yet) is
    // light. Once a user toggles it, their choice is remembered here.
    if (!savedTheme) {
        savedTheme = 'light';
        localStorage.setItem('pm-theme', savedTheme);
    }

    if (savedTheme === 'light') {
        document.documentElement.classList.add('pm-light');
    } else {
        document.documentElement.classList.remove('pm-light');
    }
})();

function pmToggleTheme() {
    const html = document.documentElement;
    html.classList.toggle('pm-light');
    localStorage.setItem('pm-theme', html.classList.contains('pm-light') ? 'light' : 'dark');
}

// Sidebar and Menu Toggles
function pmToggleSidebar() {
    document.getElementById('pm-sidebar').classList.toggle('pm-sidebar-open');
    document.getElementById('pm-backdrop').classList.toggle('pm-backdrop-show');
}

// ── Siri Aktif custom dropdown (avoids native <select> popup) ───
function pmSiriDdToggle() {
    document.getElementById('pmSiriDd')?.classList.toggle('open');
    document.getElementById('pmSiriDdPanel')?.classList.toggle('open');
}
function pmSiriDdSelect(el) {
    const value = el.getAttribute('data-value') || '';
    // Always keep siri_id in the URL (even blank for "Semua Siri") so the
    // navigation target always differs from wherever we already are —
    // otherwise picking "Semua Siri" when the URL has no siri_id param
    // produces an identical URL, and the browser treats it as a no-op (no
    // reload, nothing happens).
    const url = new URL(window.location.href);
    url.searchParams.set('siri_id', value);
    window.location.href = url.pathname + url.search;
}
document.addEventListener('click', function (e) {
    if (!e.target.closest('#pmSiriDd')) {
        document.getElementById('pmSiriDd')?.classList.remove('open');
        document.getElementById('pmSiriDdPanel')?.classList.remove('open');
    }
});

// ── Preserve sidebar scroll position across nav clicks ──────────
// Every nav link is a normal <a href> (full page load), so the sidebar's
// scroll naturally resets to top on every navigation. Save the scroll
// position right before it unloads and restore it as soon as the next
// page's sidebar exists.
(function () {
    var sidebar = document.getElementById('pm-sidebar');
    if (!sidebar) return;

    var saved = sessionStorage.getItem('pm-sidebar-scroll');
    if (saved !== null) sidebar.scrollTop = parseInt(saved, 10) || 0;

    window.addEventListener('beforeunload', function () {
        sessionStorage.setItem('pm-sidebar-scroll', sidebar.scrollTop);
    });
    sidebar.querySelectorAll('a.pm-nav-link').forEach(function (link) {
        link.addEventListener('click', function () {
            sessionStorage.setItem('pm-sidebar-scroll', sidebar.scrollTop);
        });
    });
})();

function pmToggleUserMenu(e) {
    e.stopPropagation();
    const menu = document.getElementById('pm-user-menu');
    const open = menu.classList.toggle('show');
    document.getElementById('pm-user-btn')?.setAttribute('aria-expanded', open ? 'true' : 'false');
}

// Notification toggle/clear defined in the PIC notification block below
// (pmToggleNotifMenu, pmClearNotifs)

// Close dropdowns when clicking outside
document.addEventListener('click', function (e) {
    const userMenu = document.getElementById('pm-user-menu');
    const userBtn = document.getElementById('pm-user-btn');
    if (userMenu && userMenu.classList.contains('show') && !userMenu.contains(e.target) && !userBtn.contains(e.target)) {
        userMenu.classList.remove('show');
        userBtn?.setAttribute('aria-expanded', 'false');
    }

    const notifMenu = document.getElementById('pm-notif-menu');
    const notifBtn = document.getElementById('pm-notif-btn');
    if (notifMenu && notifMenu.classList.contains('show') && !notifMenu.contains(e.target) && !notifBtn.contains(e.target)) {
        notifMenu.classList.remove('show');
        notifBtn?.setAttribute('aria-expanded', 'false');
    }
});

// ── Auto-dismiss success/error banners (.pm-alert-success / .pm-alert-danger) ──
// Applies app-wide (every role, every page using layout.php) so a "saved!" /
// "failed!" message doesn't sit on screen forever. Wrapped in
// DOMContentLoaded since these banners are rendered by page-specific markup
// that appears in the HTML AFTER this script tag.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.pm-alert-success, .pm-alert-danger').forEach(function (el) {
        setTimeout(function () {
            el.style.transition = 'opacity .4s ease';
            el.style.opacity = '0';
            setTimeout(function () {
                el.style.overflow = 'hidden';
                el.style.transition = 'max-height .3s ease, margin .3s ease, padding .3s ease, border-width .3s ease';
                el.style.maxHeight = el.offsetHeight + 'px';
                void el.offsetHeight; // force reflow so the browser registers the start height before animating to 0
                el.style.maxHeight = '0px';
                el.style.marginTop = '0px';
                el.style.marginBottom = '0px';
                el.style.paddingTop = '0px';
                el.style.paddingBottom = '0px';
                el.style.borderWidth = '0px';
                setTimeout(function () { el.remove(); }, 320);
            }, 400);
        }, 4500); // visible ~4.5s, then ~0.7s fade+collapse
    });
});

// ── Shared pagination button renderer ──
// Every client-side-paginated list (pic_criteria, pic_directory, pic_judges,
// pic_levels, pic_master_list, pic_medal_settings, pic_schools, pic_sessions,
// pic_students, pic_tests, attendance_view_school, judge_view_marks,
// upload_students, ...) used to hand-roll this same button-building loop
// with a page-specific function name for the onclick handler. Centralized
// here so every page's pagination looks and updates identically; callers
// only supply the numbers and a callback.
function pmRenderPagination(btnsEl, page, totalPages, onGoToPage) {
    if (!btnsEl) return;
    const mk = (label, target, opts) => {
        opts = opts || {};
        const cls = 'vm-page-btn' + (opts.active ? ' vm-page-active' : '');
        const dis = opts.disabled ? 'disabled' : '';
        return '<button class="' + cls + '" ' + dis + ' data-pm-page="' + target + '">' + label + '</button>';
    };
    let html = mk('&laquo;', page - 1, { disabled: page === 1 });
    let sp = Math.max(1, page - 2);
    let ep = Math.min(totalPages, sp + 4);
    if (ep - sp < 4) sp = Math.max(1, ep - 4);
    if (sp > 1) {
        html += mk('1', 1);
        if (sp > 2) html += '<span class="vm-page-ellipsis">&hellip;</span>';
    }
    for (let i = sp; i <= ep; i++) html += mk(i, i, { active: i === page });
    if (ep < totalPages) {
        if (ep < totalPages - 1) html += '<span class="vm-page-ellipsis">&hellip;</span>';
        html += mk(totalPages, totalPages);
    }
    html += mk('&raquo;', page + 1, { disabled: page === totalPages });
    btnsEl.innerHTML = html;
    btnsEl.querySelectorAll('[data-pm-page]').forEach(function (b) {
        b.addEventListener('click', function () { onGoToPage(parseInt(b.dataset.pmPage, 10)); });
    });
}

// ── Keyboard activation for role="button"/role="option" elements ──
// A lot of this app's interactive controls (dropdown triggers, accordion
// headers, dropdown options) are <div onclick="..."> rather than real
// <button>s, so they were reachable by mouse only — Tab wouldn't land on
// them, and even if it somehow did, Enter/Space did nothing. Rather than
// rewrite every one of those onclick handlers, this listens once, globally,
// for Enter/Space on anything marked role="button" or role="option" and
// fires a native click() — which every existing onclick handler already
// responds to. Real <button>/<a>/<input> elements already get this from the
// browser for free and are skipped here to avoid double-firing.
document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') return;
    const target = e.target.closest('[role="button"], [role="option"]');
    if (!target) return;
    const tag = target.tagName;
    if (tag === 'BUTTON' || tag === 'A' || tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA') return;
    e.preventDefault(); // stop Space from scrolling the page
    target.click();
});

// ── Arrow-key navigation inside the .dd-panel/.pm-siri-dd-panel listboxes
// opened by the trigger above ── role="option" alone only makes each option
// individually Tab-reachable; the WAI-ARIA listbox pattern also expects
// Up/Down (and Home/End) to move among them without tabbing through one at
// a time. Works whether focus starts on the trigger, the search box, or
// another option — .dd-wrap/.pm-siri-dd wraps trigger+panel as siblings in
// every instance of this component, so that's the one thing this can
// reliably walk up to regardless of which page/ddName it's on.
document.addEventListener('keydown', function (e) {
    if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp' && e.key !== 'Home' && e.key !== 'End') return;
    const wrap = e.target.closest('.dd-wrap, .pm-siri-dd');
    if (!wrap) return;
    const listbox = wrap.querySelector('[role="listbox"]');
    if (!listbox || getComputedStyle(listbox).display === 'none') return;
    const opts = Array.from(listbox.querySelectorAll('[role="option"]')).filter(function (o) {
        return !o.classList.contains('hidden') && getComputedStyle(o).display !== 'none';
    });
    if (opts.length === 0) return;
    e.preventDefault();
    if (e.key === 'Home') { opts[0].focus(); return; }
    if (e.key === 'End') { opts[opts.length - 1].focus(); return; }
    let idx = opts.indexOf(e.target);
    if (e.key === 'ArrowDown') {
        idx = idx === -1 ? 0 : Math.min(idx + 1, opts.length - 1);
    } else {
        idx = idx === -1 ? opts.length - 1 : Math.max(idx - 1, 0);
    }
    opts[idx].focus();
});

// ── Keep aria-expanded/aria-selected in sync, without touching any
// existing toggle function ──
// The app's ~15 duplicated accordion/dropdown toggle functions each drive
// open/closed state through one of two conventions: an .open or .active
// class on the trigger itself (dd-trigger, judge.php's summary accordion),
// or the panel's style.display toggling directly while the trigger is its
// previous sibling (toggleBlock, dirToggle). Rather than editing every one
// of those functions to also set aria-expanded — real duplication, real
// chance of missing one or getting a variable name wrong — this observes
// the DOM mutations they already make and reflects them onto
// role="button"/role="option" ancestors generically.
(function () {
    function syncButtonState(el) {
        // Two different conventions drive open/closed state across the
        // ~15 duplicated toggle functions: an .open/.active class on the
        // trigger itself (dd-trigger, judge.php's summary accordion), or
        // the next sibling's style.display (toggleBlock, dirToggle) — the
        // mutation-observer branch below already knows both; this initial
        // sweep needs to check both too, or a style.display-driven header
        // that starts collapsed never gets aria-expanded at all until the
        // first toggle.
        const openByClass = el.classList.contains('open') || el.classList.contains('active');
        const sibling = el.nextElementSibling;
        const openByStyle = sibling && sibling.style.display === 'block';
        el.setAttribute('aria-expanded', (openByClass || openByStyle) ? 'true' : 'false');
    }
    function syncOptionState(el) {
        el.setAttribute('aria-selected', el.classList.contains('selected') ? 'true' : 'false');
    }

    function sweep(root) {
        root.querySelectorAll('[role="button"]').forEach(syncButtonState);
        root.querySelectorAll('[role="option"]').forEach(syncOptionState);
        if (root.getAttribute && root.getAttribute('role') === 'button') syncButtonState(root);
        if (root.getAttribute && root.getAttribute('role') === 'option') syncOptionState(root);
    }

    const observer = new MutationObserver(function (mutations) {
        mutations.forEach(function (m) {
            if (m.type === 'childList') {
                // Some pages (e.g. pic_students.php's _doFilter) replace a
                // whole list via innerHTML — even on first load, to apply
                // default sort/pagination — which wholesale-discards
                // server-rendered nodes the initial sweep already synced
                // and inserts fresh ones that were never synced at all.
                // Attribute-mutation watching alone can't catch that; this
                // re-sweeps whatever subtree just got added.
                m.addedNodes.forEach(function (node) {
                    if (node.nodeType === 1) sweep(node);
                });
                return;
            }
            const el = m.target;
            if (m.attributeName === 'class') {
                if (el.getAttribute('role') === 'button') syncButtonState(el);
                if (el.getAttribute('role') === 'option') syncOptionState(el);
            }
            if (m.attributeName === 'style') {
                const header = el.previousElementSibling;
                if (header && header.getAttribute('role') === 'button') {
                    header.setAttribute('aria-expanded', el.style.display === 'block' ? 'true' : 'false');
                }
            }
        });
    });
    // document.body doesn't exist yet — this file loads synchronously in
    // <head>, before <body> is parsed (see the file-level comment at the
    // top) — so starting the observer has to wait for DOMContentLoaded.
    document.addEventListener('DOMContentLoaded', function () {
        observer.observe(document.body, { attributes: true, attributeFilter: ['class', 'style'], childList: true, subtree: true });
        // The observer only reacts to FUTURE mutations — an option already
        // rendered with class="dd-opt selected" by PHP (the current filter
        // value) would otherwise never get aria-selected until the user
        // changes something. One-time initial sweep covers that.
        sweep(document.body);
    });
})();

// ── Escape closes whatever's open ──
// Backdrops/overlays (.pm-modal-overlay, the mobile sidebar, the QR modal)
// only close on a mouse click today — there was no keyboard equivalent at
// all. Rather than make each full-screen backdrop div itself a focusable
// "button" (which would be a stray, contentless tab stop — the wrong fix),
// this gives keyboard users the standard Escape-to-dismiss path instead.
document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;

    // Figure out which trigger should regain focus once we close things —
    // otherwise focus silently resets to <body>, which is disorienting for
    // a keyboard user (their next Tab jumps to the top of the page instead
    // of continuing from where the closed control was).
    let returnFocusTo = null;
    const openWrap = e.target.closest('.dd-wrap, .pm-siri-dd');
    if (openWrap) {
        returnFocusTo = openWrap.querySelector('.dd-trigger, .pm-siri-dd-trigger');
    } else if (document.getElementById('pm-user-menu')?.classList.contains('show')) {
        returnFocusTo = document.getElementById('pm-user-btn');
    } else if (document.getElementById('pm-notif-menu')?.classList.contains('show')) {
        returnFocusTo = document.getElementById('pm-notif-btn');
    } else if (document.getElementById('pm-sidebar')?.classList.contains('pm-sidebar-open')) {
        returnFocusTo = document.querySelector('.pm-hamburger');
    }

    document.querySelectorAll('.dd-panel.open, .dd-trigger.open').forEach(function (el) { el.classList.remove('open'); });
    document.getElementById('pmSiriDd')?.classList.remove('open');
    document.getElementById('pmSiriDdPanel')?.classList.remove('open');
    document.querySelectorAll('.pm-modal-overlay.show').forEach(function (el) { el.classList.remove('show'); });
    document.getElementById('qrModal')?.classList.remove('visible');
    document.getElementById('pm-sidebar')?.classList.remove('pm-sidebar-open');
    document.getElementById('pm-backdrop')?.classList.remove('pm-backdrop-show');
    document.getElementById('pm-user-menu')?.classList.remove('show');
    document.getElementById('pm-user-btn')?.setAttribute('aria-expanded', 'false');
    document.getElementById('pm-notif-menu')?.classList.remove('show');
    document.getElementById('pm-notif-btn')?.setAttribute('aria-expanded', 'false');

    if (returnFocusTo) returnFocusTo.focus();
});
