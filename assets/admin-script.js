/* GenD Society — Admin Script v1.0.0 */
(function () {
    'use strict';

    // Inject the glassmorphic header into the admin
    function injectHeader() {
        if (document.getElementById('gs-admin-header')) { return; }

        var siteUrl = window.location.origin;
        var userName = (window.gsAdminData && window.gsAdminData.userName) ? window.gsAdminData.userName : 'Admin';
        var logoutUrl = (window.gsAdminData && window.gsAdminData.logoutUrl) ? window.gsAdminData.logoutUrl : '#';
        var profileUrl = (window.gsAdminData && window.gsAdminData.profileUrl) ? window.gsAdminData.profileUrl : '#';

        var siteTitle = (window.gsAdminData && window.gsAdminData.siteTitle) ? window.gsAdminData.siteTitle : 'GenD Society';

        var header = document.createElement('div');
        header.id = 'gs-admin-header';
        header.innerHTML =
            '<div class="logo-container">' +
            '<a href="https://gend.me/my-account"><img src="https://gend.me/wp-content/uploads/2025/12/Futuristic_Logo_Animation_Generation-ezgif.com-crop-1.gif" alt="Logo"></a>' +
            '<span class="gs-site-title">' + escHtml(siteTitle) + '</span>' +
            '</div>' +
            '<nav class="nav-central">' +
            '<a href="https://gend.me/app-features" class="nav-pill">' +
            '<div class="pill-icon"><img src="https://gend.me/wp-content/uploads/2023/07/Launch_Web_Apps_Video_Generated-ezgif.com-optimize.gif" alt="Web Apps"></div>' +
            '<span>Web Apps</span>' +
            '</a>' +
            '<a href="https://gend.me/leo" class="nav-pill">' +
            '<div class="pill-icon"><img src="https://gend.me/wp-content/uploads/2025/12/Animated_Profile_Picture_At_Desk-ezgif.com-optimize.gif" alt="LEO"></div>' +
            '<span>Build with LEO</span>' +
            '</a>' +
            '<a href="https://gend.me/smart-wallets" class="nav-pill">' +
            '<div class="pill-icon"><img src="https://gend.me/wp-content/uploads/2023/07/Contract_Wallet_Icon_Animation_Created-ezgif.com-optimize.gif" alt="Wallet"></div>' +
            '<span>Contract Wallet</span>' +
            '</a>' +
            '</nav>' +
            '<div class="gs-header-right">' +
            '<a href="' + siteUrl + '" class="gs-btn-site" target="_blank" rel="noopener">View Site</a>' +
            '<span class="gs-header-user">Hi, <a href="' + profileUrl + '">' + escHtml(userName) + '</a> · <a href="' + logoutUrl + '">Log out</a></span>' +
            '</div>';
        document.body.insertBefore(header, document.body.firstChild);
    }

    function escHtml(s) {
        return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function gsAdminUrl(path) {
        var base = (window.gsAdminData && window.gsAdminData.adminUrl) ? window.gsAdminData.adminUrl : '/wp-admin/';
        return base.replace(/\/?$/, '/') + path;
    }

    // Inject JS data
    function localizeData() {
        if (!window.gsAdminData) { window.gsAdminData = {}; }
    }

    // Sidebar active state
    function markActive() {
        var cur = window.location.href;
        document.querySelectorAll('#adminmenu a').forEach(function (a) {
            if (a.href && cur.indexOf(a.getAttribute('href')) !== -1) {
                a.closest('li') && a.closest('li').classList.add('current');
            }
        });
    }

    // Submenu hover animation
    function enhanceSubmenus() {
        document.querySelectorAll('#adminmenu li.menu-top').forEach(function (li) {
            li.addEventListener('mouseenter', function () {
                var sub = li.querySelector('.wp-submenu');
                if (sub) { sub.style.animation = 'gsFadeIn .15s ease'; }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        localizeData();
        injectHeader();
        markActive();
        enhanceSubmenus();
    });
})();
