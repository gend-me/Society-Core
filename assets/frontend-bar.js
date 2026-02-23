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
    var cur = window.location.href;
    document.querySelectorAll('.gs-sidebar-link').forEach(function (a) {
        var href = a.getAttribute('href') || '';
        if (href && href !== '#' && cur.indexOf(href) !== -1) {
            a.classList.add('gs-active');
        }
    });
})();


