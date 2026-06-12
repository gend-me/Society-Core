/* GenD Society — Frontend Bar Script v1.1.0 */
(function () {
    'use strict';

    var sidebar = document.querySelector('.gs-front-sidebar');
    if (!sidebar) { return; }

    var toggle = document.getElementById('gs-float-menu');
    var hideTimer = null;
    var HIDE_DELAY = 350;

    function reveal() {
        if (hideTimer) { clearTimeout(hideTimer); hideTimer = null; }
        sidebar.classList.add('gs-revealed');
        sidebar.setAttribute('aria-hidden', 'false');
        if (toggle) { toggle.setAttribute('aria-expanded', 'true'); }
    }
    function hide() {
        sidebar.classList.remove('gs-revealed');
        sidebar.setAttribute('aria-hidden', 'true');
        if (toggle) { toggle.setAttribute('aria-expanded', 'false'); }
    }
    function scheduleHide() {
        if (hideTimer) { clearTimeout(hideTimer); }
        hideTimer = setTimeout(hide, HIDE_DELAY);
    }
    function cancelHide() {
        if (hideTimer) { clearTimeout(hideTimer); hideTimer = null; }
    }

    if (toggle) {
        // Click toggles (primary interaction, also covers touch devices)
        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            if (sidebar.classList.contains('gs-revealed')) { hide(); }
            else { reveal(); }
        });
        // Hover-intent reveal on pointer-capable devices
        toggle.addEventListener('mouseenter', reveal);
        toggle.addEventListener('mouseleave', scheduleHide);
    }
    sidebar.addEventListener('mouseenter', cancelHide);
    sidebar.addEventListener('mouseleave', scheduleHide);

    // Click outside the sidebar (and not on the toggle) closes it
    document.addEventListener('click', function (e) {
        if (!sidebar.classList.contains('gs-revealed')) { return; }
        if (sidebar.contains(e.target)) { return; }
        if (toggle && toggle.contains(e.target)) { return; }
        hide();
    });
    // Esc closes
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && sidebar.classList.contains('gs-revealed')) { hide(); }
    });

    // ---- Flyout submenus ----------------------------------------
    document.querySelectorAll('.gs-sidebar-item[data-has-sub]').forEach(function (item) {
        var flyout = item.querySelector('.gs-sidebar-flyout');
        if (!flyout) { return; }

        // Move to body to avoid being clipped by sidebar's overflow:hidden & backdrop-filter
        document.body.appendChild(flyout);
        var ft = null;

        function showFlyout() {
            if (ft) { clearTimeout(ft); }
            var rect = item.getBoundingClientRect();
            var sbRect = sidebar.getBoundingClientRect();
            flyout.style.top = rect.top + 'px';
            // Anchor to the right edge of the viewport so the flyout opens
            // to the LEFT of the right-pinned sidebar (toward page center).
            flyout.style.left = '';
            flyout.style.right = (window.innerWidth - sbRect.left + 8) + 'px';
            flyout.style.display = 'block';
        }
        function hideFlyout() {
            ft = setTimeout(function () { flyout.style.display = 'none'; }, 200);
        }

        item.addEventListener('mouseenter', showFlyout);
        item.addEventListener('mouseleave', hideFlyout);
        // While the cursor is over the flyout, the sidebar should stay
        // open even though the flyout lives outside the sidebar DOM (it
        // gets appended to <body> to escape overflow:hidden). Cancel the
        // sidebar-level hideTimer on enter and re-arm it on leave so the
        // sidebar doesn't slide out from under the submenu.
        flyout.addEventListener('mouseenter', function () {
            if (ft) clearTimeout(ft);
            cancelHide();
        });
        flyout.addEventListener('mouseleave', function () {
            hideFlyout();
            scheduleHide();
        });
    });

    

    // ---- Mark active sidebar link --------------------------------
    // Exact-path match (after stripping trailing slashes + query/hash) so
    // Overview's base URL "/members/<u>/" doesn't substring-match every
    // other profile sub-page like "/members/<u>/groups/".
    function gsNormalizePath(u) {
        try {
            var url = new URL(u, window.location.origin);
            var p = url.pathname.replace(/\/+$/, '');
            return p + (url.search || '');
        } catch (e) {
            return String(u || '').split('#')[0].replace(/\/+$/, '');
        }
    }
    var curPath = gsNormalizePath(window.location.href);
    document.querySelectorAll('.gs-sidebar-link').forEach(function (a) {
        var href = a.getAttribute('href') || '';
        if (!href || href === '#') return;
        if (gsNormalizePath(href) === curPath) {
            a.classList.add('gs-active');
        }
    });
})();


