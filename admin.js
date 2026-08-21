    function toggleDrawer(openId, closeId) {
        const toOpen  = document.getElementById(openId);
        const toClose = document.getElementById(closeId);
        if (toClose) toClose.classList.remove('open');
        if (toOpen) {
            const wasOpen = toOpen.classList.contains('open');
            toOpen.classList.toggle('open', !wasOpen);
            if (!wasOpen) {
                const first = toOpen.querySelector('input:not([type="hidden"]), select');
                if (first) first.focus();
            }
        }
    }

    function closeDrawer(id) {
        const el = document.getElementById(id);
        if (el) el.classList.remove('open');
    }
