/* GenD Society — Frontend Bar Script v1.0.0 */
(function () {
    'use strict';

    var sidebar = document.querySelector('.gs-front-sidebar');
    if (!sidebar) { return; }

    var body = document.body;
    var collapseTimer = null;
    var EXPAND_DELAY = 0;
    var COLLAPSE_DELAY = 700;

    body.classList.add('gs-bar-active');

    function expand() {
        if (collapseTimer) { clearTimeout(collapseTimer); collapseTimer = null; }
        sidebar.classList.add('gs-expanded');
        body.classList.add('gs-bar-expanded');
    }
    function collapse() {
        collapseTimer = setTimeout(function () {
            sidebar.classList.remove('gs-expanded');
            body.classList.remove('gs-bar-expanded');
        }, COLLAPSE_DELAY);
    }

    sidebar.addEventListener('mouseenter', expand);
    sidebar.addEventListener('mouseleave', collapse);

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
            flyout.style.top = rect.top + 'px';
            flyout.style.left = sidebar.getBoundingClientRect().right + 'px';
            flyout.style.display = 'block';
        }
        function hideFlyout() {
            ft = setTimeout(function () { flyout.style.display = 'none'; }, 200);
        }

        item.addEventListener('mouseenter', showFlyout);
        item.addEventListener('mouseleave', hideFlyout);
        flyout.addEventListener('mouseenter', function () { if (ft) clearTimeout(ft); });
        flyout.addEventListener('mouseleave', hideFlyout);
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


