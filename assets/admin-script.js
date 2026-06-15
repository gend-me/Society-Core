/* GenD Society — Admin Script v2.0.0 */
(function () {
    'use strict';

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function buildOauthProfileHeader(data) {
        data = data || {};
        var hub  = String(data.gendHubUrl || 'https://gend.me').replace(/\/+$/, '');
        var base = data.gendProfileUrl || (hub + '/members/me/');
        if (base.charAt(base.length - 1) !== '/') base += '/';

        // Profile nav (App Projects / Connections / Wallet / Messages) — these
        // used to live across the centre of the bar; they now collapse behind
        // the avatar on the far right and stagger in as a dropdown. Keep the
        // self-contained fallback so the menu renders even if the server
        // localize didn't run / was clobbered.
        var profileMenu = (data && Array.isArray(data.gendProfileMenu) && data.gendProfileMenu.length)
            ? data.gendProfileMenu
            : [
                { label: 'App Projects', url: base + 'groups/',       icon: 'dashicons-screenoptions' },
                { label: 'Connections',  url: base + 'friends/',      icon: 'dashicons-networking' },
                { label: 'Wallet',       url: base + 'member-wallet/', icon: 'dashicons-money-alt' },
                { label: 'Messages',     url: base + 'messages/',     icon: 'dashicons-email' }
              ];

        // Connected web-app group menu — fills the centre of the bar. Each
        // item opens a wp-admin page that embeds that group section inline.
        // Server-provided + capability-filtered (gs_group_embed_menu_items).
        var groupMenu = (data && Array.isArray(data.gendGroupMenu)) ? data.gendGroupMenu : [];
        var groupName = (data && data.gendGroupName) ? String(data.gendGroupName) : '';

        var profileUrl = base;
        var avatarUrl  = (data && data.gendAvatarUrl)  ? data.gendAvatarUrl  : '';
        var userName   = (data && data.userName)       ? data.userName       : 'Profile';
        var logoutUrl  = (data && data.logoutUrl)      ? data.logoutUrl      : '';
        var here       = window.location.href;

        // ── Centre: connected group menu ──────────────────────────────
        var groupLinks = '';
        for (var g = 0; g < groupMenu.length; g++) {
            var gi = groupMenu[g] || {};
            var gIcon = gi.icon
                ? '<span class="dashicons ' + escapeHtml(gi.icon) + ' gs-group-nav-icon" aria-hidden="true"></span>'
                : '';
            // Mark the active item when the current URL targets this tab.
            var isActive = gi.slug && here.indexOf('page=gs-group-embed') !== -1 && here.indexOf('tab=' + gi.slug) !== -1;
            groupLinks +=
                '<a href="' + escapeHtml(gi.url) + '" class="gs-group-nav-item' + (isActive ? ' is-active' : '') + '">' +
                    gIcon +
                    '<span class="pill-content">' + escapeHtml(gi.label) + '</span>' +
                '</a>';
        }
        var groupNav = groupLinks
            ? '<nav class="nav-central nav-central--group" aria-label="Connected web app">' + groupLinks + '</nav>'
            : '<nav class="nav-central nav-central--group" aria-hidden="true"></nav>';

        // ── Right: avatar → animated dropdown of profile items ─────────
        var dropItems = '';
        for (var i = 0; i < profileMenu.length; i++) {
            var item = profileMenu[i] || {};
            var icon = item.icon
                ? '<span class="dashicons ' + escapeHtml(item.icon) + ' gs-pd-icon" aria-hidden="true"></span>'
                : '';
            dropItems +=
                '<a href="' + escapeHtml(item.url) + '" class="gs-pd-item" role="menuitem" target="_blank" rel="noopener">' +
                    icon +
                    '<span>' + escapeHtml(item.label) + '</span>' +
                    '<span class="gs-pd-arrow dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>' +
                '</a>';
        }
        var logoutItem = logoutUrl
            ? '<a href="' + escapeHtml(logoutUrl) + '" class="gs-pd-item gs-pd-logout" role="menuitem">' +
                '<span class="dashicons dashicons-exit gs-pd-icon" aria-hidden="true"></span>' +
                '<span>Log Out</span>' +
              '</a>'
            : '';

        var cluster =
            '<div class="gs-profile-cluster">' +
                '<button type="button" class="gs-profile-avatar-btn" id="gs-profile-toggle" aria-haspopup="true" aria-expanded="false" aria-controls="gs-profile-dropdown" aria-label="Open profile menu">' +
                    '<img src="' + escapeHtml(avatarUrl) + '" alt="' + escapeHtml(userName) + '" class="gs-profile-avatar-img">' +
                    '<span class="gs-profile-avatar-ring" aria-hidden="true"></span>' +
                '</button>' +
                '<div class="gs-profile-dropdown" id="gs-profile-dropdown" role="menu" aria-label="Profile menu">' +
                    '<div class="gs-pd-head">' +
                        '<img src="' + escapeHtml(avatarUrl) + '" alt="" class="gs-pd-head-avatar">' +
                        '<div class="gs-pd-head-meta">' +
                            '<span class="gs-pd-head-name">' + escapeHtml(userName) + '</span>' +
                            '<a href="' + escapeHtml(profileUrl) + '" class="gs-pd-head-link" target="_blank" rel="noopener">View gend.me profile</a>' +
                        '</div>' +
                    '</div>' +
                    '<div class="gs-pd-items">' + dropItems + logoutItem + '</div>' +
                '</div>' +
            '</div>';

        return '' +
            groupNav +
            cluster +
            '<div class="visit-site-slot">' +
                '<a href="/" class="btn-visit-site" target="_blank" rel="noopener">View Site</a>' +
            '</div>';
    }

    // Wire the avatar → dropdown. The staggered "menu builds itself"
    // entrance is pure CSS (per-item animation-delay) that replays every
    // time `.is-open` is added because the items go display:none → block.
    function bindProfileDropdown() {
        var btn = document.getElementById('gs-profile-toggle');
        var dd  = document.getElementById('gs-profile-dropdown');
        if (!btn || !dd) return;

        function open()  { dd.classList.add('is-open');    btn.setAttribute('aria-expanded', 'true');  }
        function close() { dd.classList.remove('is-open');  btn.setAttribute('aria-expanded', 'false'); }
        function toggle() { dd.classList.contains('is-open') ? close() : open(); }

        btn.addEventListener('click', function (e) { e.stopPropagation(); toggle(); });
        dd.addEventListener('click', function (e) { e.stopPropagation(); });
        document.addEventListener('click', function () { close(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
    }

    function buildDefaultHeader() {
        var data = window.gsAdminData || {};
        // Only render the Login-to-GenD button when the OAuth client is
        // configured — otherwise the button can't actually do anything and
        // showing a dead/disabled control just confuses people.
        var canLogin = !!(data.gendOauthClientId && data.gendOauthRestUrl);
        var loginBtn = canLogin
            ? '<button type="button" class="gs-login-gend-btn" id="gs-header-login-gend">Login to GenD</button>'
            : '';

        return '' +
            loginBtn +
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
            '<div class="visit-site-slot">' +
                '<a href="/" class="btn-visit-site" target="_blank" rel="noopener">Visit Site</a>' +
            '</div>';
    }

    // ── OAuth popup launcher (mirrors wp-login.php's PKCE flow in
    // oauth-login.php so an already-logged-in admin can link their
    // gend.me account from the header without leaving wp-admin).
    function base64url(bytes) {
        var s = '';
        for (var i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
        return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    async function pkce() {
        var arr = new Uint8Array(32);
        crypto.getRandomValues(arr);
        var verifier = base64url(arr);
        var hash = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(verifier));
        return { verifier: verifier, challenge: base64url(new Uint8Array(hash)) };
    }

    function bindLoginButton() {
        var btn = document.getElementById('gs-header-login-gend');
        if (!btn) return;

        var data       = window.gsAdminData || {};
        var hubUrl     = data.gendHubUrl || 'https://gend.me';
        var clientId   = data.gendOauthClientId || '';
        var restUrl    = data.gendOauthRestUrl  || '';

        // Defensive: should never happen because buildDefaultHeader skips
        // rendering the button when the client isn't configured. If it did
        // somehow render, just remove it rather than leaving a dead control.
        if (!clientId || !restUrl) {
            btn.remove();
            return;
        }

        btn.addEventListener('click', async function () {
            var origText = btn.textContent;
            function setBusy() {
                btn.disabled = true;
                btn.classList.add('is-busy');
                btn.textContent = 'Connecting…';
            }
            function setIdle() {
                btn.disabled = false;
                btn.classList.remove('is-busy');
                btn.textContent = origText;
            }

            setBusy();
            try {
                var p = await pkce();
                var state = base64url(crypto.getRandomValues(new Uint8Array(16)));

                var authUrl = hubUrl + '/oauth/authorize?' + new URLSearchParams({
                    response_type: 'code',
                    client_id: clientId,
                    redirect_uri: hubUrl + '/oauth-bridge/',
                    scope: 'profile', // standard scope; 'basic' retired (clients whitelist 'profile')
                    state: state,
                    code_challenge: p.challenge,
                    code_challenge_method: 'S256'
                }).toString();

                var w = 480, h = 720;
                var x = (window.screen.width  - w) / 2;
                var y = (window.screen.height - h) / 2;
                var popup = window.open(authUrl, 'gs_oauth', 'width=' + w + ',height=' + h + ',left=' + x + ',top=' + y);
                if (!popup) {
                    setIdle();
                    alert('Popup blocked. Allow popups from this site and try again.');
                    return;
                }

                var watchdog;
                var gotMessage = false;
                function onMessage(ev) {
                    if (ev.origin !== hubUrl) return;
                    var d = ev.data;
                    if (!d || d.type !== 'gend_oauth') return;
                    if (d.state && d.state !== state) return;
                    gotMessage = true;
                    if (watchdog) clearInterval(watchdog);
                    window.removeEventListener('message', onMessage);
                    try { popup.close(); } catch (_) {}

                    if (d.error) {
                        setIdle();
                        alert(d.error_description || d.error);
                        return;
                    }

                    fetch(restUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ code: d.code, code_verifier: p.verifier })
                    }).then(function (r) {
                        return r.json().then(function (j) { return { status: r.status, body: j }; });
                    }).then(function (res) {
                        if (res.status >= 200 && res.status < 300 && res.body && res.body.success) {
                            window.location.reload();
                        } else {
                            setIdle();
                            alert((res.body && res.body.message) || ('Login failed (HTTP ' + res.status + ')'));
                        }
                    }).catch(function (e) {
                        setIdle();
                        alert(e && e.message ? e.message : 'Network error during login.');
                    });
                }
                window.addEventListener('message', onMessage);

                watchdog = setInterval(function () {
                    if (popup.closed) {
                        clearInterval(watchdog);
                        if (btn.disabled && !gotMessage) {
                            window.removeEventListener('message', onMessage);
                            setIdle();
                        }
                    }
                }, 600);
            } catch (e) {
                setIdle();
                alert(e && e.message ? e.message : 'Could not start login.');
            }
        });
    }

    function injectHeader() {
        if (document.getElementById('main-3d-header')) { return; }

        // wp-admin body never carries `logged-in`; add it so the new-header
        // CSS reveals the admin-facing action buttons.
        document.body.classList.add('logged-in');

        var data = window.gsAdminData || {};

        // Always render the gend.me profile menu. Every wp-admin viewer is
        // signed in via gend.me OAuth, so there's no "logged out" header
        // variant any more — the old image-button (buildDefaultHeader) +
        // gendOauth gating is removed. buildOauthProfileHeader carries its
        // own fallback menu, so it renders even if gsAdminData is empty.
        var header = document.createElement('header');
        header.className = 'header-anchor-wrap header-anchor-wrap--oauth';
        header.id = 'main-3d-header';
        header.innerHTML = buildOauthProfileHeader(data);
        document.body.insertBefore(header, document.body.firstChild);
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
        bindProfileDropdown();
        attach3DHover();
        markActive();
        enhanceSubmenus();
    });
})();
