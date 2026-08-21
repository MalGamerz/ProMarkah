        function setupPasswordToggle(toggleBtnId, inputId, iconId) {
            const toggleBtn = document.getElementById(toggleBtnId);
            if (!toggleBtn) return;
            toggleBtn.addEventListener('click', function () {
                const input = document.getElementById(inputId);
                const icon = document.getElementById(iconId);
                if (input.type === 'password') {
                    input.type = 'text';
                    icon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
                } else {
                    input.type = 'password';
                    icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
                }
            });
        }
        setupPasswordToggle('togglePassword', 'password', 'eyeIcon');
        setupPasswordToggle('togglePin', 'pin', 'eyeIconPin');

        // ── GUARANTEED fit-to-screen (no scrolling, any device height) ──
        // .pm-login-form-panel has no height of its own — it stretches to
        // fill .pm-login-outer, which is hard-clipped to the real visible
        // viewport (see CSS). Breakpoint media queries can only ever guess
        // at specific device sizes and still leave gaps — this instead
        // directly MEASURES the card's actual rendered height against that
        // available height and shrinks it with transform: scale() until it
        // fits, so it's correct for literally any viewport height,
        // including ones no breakpoint anticipated.
        function fitLoginCard() {
            const panel = document.querySelector('.pm-login-form-panel');
            const inner = document.querySelector('.pm-login-form-inner');
            if (!panel || !inner) return;

            // Reset to natural size first so scrollHeight reflects the
            // card's real, unscaled content height rather than whatever
            // scale was last applied.
            inner.style.transform = 'scale(1)';

            const availableHeight = panel.clientHeight;
            const neededHeight = inner.scrollHeight;
            // Small safety margin (0.97) so the card never touches the
            // panel's exact edge — reads as cramped otherwise. No lower
            // floor on the scale itself: this must fit no matter how short
            // the viewport is, by design.
            const scale = Math.min(1, (availableHeight / neededHeight) * 0.97);

            inner.style.transform = 'scale(' + scale + ')';
        }

        window.addEventListener('DOMContentLoaded', fitLoginCard);
        window.addEventListener('load', fitLoginCard); // re-check after images/fonts settle
        window.addEventListener('resize', fitLoginCard);
        // Mobile browsers fire 'resize' late (or not at all) when the
        // address bar shows/hides on scroll — orientationchange plus a
        // short delayed re-check covers that gap.
        window.addEventListener('orientationchange', function () {
            setTimeout(fitLoginCard, 300);
        });
