/* GenD Society — Admin Script v2.0.0 */
(function () {
    'use strict';

    function injectHeader() {
        if (document.getElementById('main-3d-header')) { return; }

        // wp-admin body never carries `logged-in`; add it so the new-header
        // CSS reveals the admin-facing action buttons (Sales / My Apps / Dashboard).
        document.body.classList.add('logged-in');

        var header = document.createElement('header');
        header.className = 'header-anchor-wrap';
        header.id = 'main-3d-header';
        header.innerHTML =
            '<div class="visit-site-slot">' +
                '<a href="/" class="btn-visit-site" target="_blank" rel="noopener">Visit Site</a>' +
            '</div>' +
            '<nav class="nav-central">' +
                '<a href="/app-features" class="nav-pill">' +
                    '<img src="https://gend.me/wp-content/uploads/2025/12/Web-App-Building-Waiting.gif" class="pill-bg" alt="">' +
                    '<span class="pill-content">Digital Business</span>' +
                '</a>' +
                '<a href="/leo" class="nav-pill">' +
                    '<img src="https://gend.me/wp-content/uploads/2026/03/Untitleddesign1-ezgif.com-video-to-gif-converter.gif" class="pill-bg" alt="">' +
                    '<span class="pill-content">Build with LEO</span>' +
                '</a>' +
                '<a href="/smart-wallets" class="nav-pill">' +
                    '<img src="https://gend.me/wp-content/uploads/2025/11/20251113_1637_New-Video_simple_compose_01k9zjcc05e6tbycty113spf54.gif" class="pill-bg" alt="">' +
                    '<span class="pill-content">Contract Wallet</span>' +
                '</a>' +
            '</nav>' +
            '<div class="nav-actions-right">' +
                '<a href="/launch" class="btn-header-action btn-pilot">Start Pilot</a>' +
                '<a href="https://gend.me/consultant-dashboard/" class="btn-header-action btn-projects admin-only">Projects</a>' +
                '<a href="https://gend.me/task-dashboard/" class="btn-header-action btn-tasks admin-only">Tasks</a>' +
                '<a href="https://gend.me/my-account/earnings/" class="btn-header-action btn-sales">' +
                    '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" style="margin-right:3px;"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>' +
                    'Sales' +
                '</a>' +
                '<a href="https://gend.me/my-account/memberships/" class="btn-header-action btn-my-apps">My Apps</a>' +
                '<a href="/members/me" class="dash-link">Dashboard</a>' +
            '</div>';
        document.body.insertBefore(header, document.body.firstChild);

        // wp-admin access implies admin privileges; reveal admin-only buttons.
        var isAdmin = document.body.classList.contains('wp-admin') ||
                      document.body.classList.contains('administrator') ||
                      document.body.classList.contains('super-admin') ||
                      document.getElementById('wpadminbar') !== null;
        if (isAdmin) {
            header.querySelectorAll('.admin-only').forEach(function (el) {
                el.classList.add('show-admin-tools');
            });
        }
    }

    function attach3DHover() {
        var header = document.getElementById('main-3d-header');
        if (!header) return;
        document.addEventListener('mousemove', function (e) {
            var x = (window.innerWidth / 2 - e.pageX) / 130;
            var y = (window.innerHeight / 2 - e.pageY) / 130;
            header.style.transform = 'rotateY(' + (-x) + 'deg) rotateX(' + (y / 2) + 'deg)';
        });
    }

    function markActive() {
        var cur = window.location.href;
        document.querySelectorAll('#adminmenu a').forEach(function (a) {
            if (a.href && cur.indexOf(a.getAttribute('href')) !== -1) {
                a.closest('li') && a.closest('li').classList.add('current');
            }
        });
    }

    function enhanceSubmenus() {
        document.querySelectorAll('#adminmenu li.menu-top').forEach(function (li) {
            li.addEventListener('mouseenter', function () {
                var sub = li.querySelector('.wp-submenu');
                if (sub) { sub.style.animation = 'gsFadeIn .15s ease'; }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        injectHeader();
        attach3DHover();
        markActive();
        enhanceSubmenus();
    });
})();
