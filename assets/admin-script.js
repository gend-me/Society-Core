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
                { label: 'Projects', url: base + 'groups/',       icon: 'dashicons-screenoptions' },
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

        // ── Centre: connected group menu — direct links onto the linked
        // gend.me group's own tabs (Business Plan / Contracts / Marketing /
        // Payments / Organization), not wp-admin pages, so they open in a
        // new tab rather than navigating away from the dashboard.
        var groupLinks = '';
        for (var g = 0; g < groupMenu.length; g++) {
            var gi = groupMenu[g] || {};
            var gIcon = gi.icon
                ? '<span class="dashicons ' + escapeHtml(gi.icon) + ' gs-group-nav-icon" aria-hidden="true"></span>'
                : '';
            groupLinks +=
                '<a href="' + escapeHtml(gi.url) + '" class="gs-group-nav-item" target="_blank" rel="noopener">' +
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
                '<a href="' + escapeHtml(data.viewSiteUrl || '/') + '" class="btn-visit-site" target="_blank" rel="noopener" aria-label="View Site">' +
                    '<span class="dashicons dashicons-external gs-vs-icon" aria-hidden="true"></span>' +
                    '<span class="gs-vs-label">View Site</span>' +
                '</a>' +
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
    // @build-strip-begin wporg
        // Only render the Login-to-GenD button when the OAuth client is
        // configured — otherwise the button can't actually do anything and
        // showing a dead/disabled control just confuses people.
        var canLogin = !!(data.gendOauthClientId && data.gendOauthRestUrl);
        var loginBtn = canLogin
            ? '<button type="button" class="gs-login-gend-btn" id="gs-header-login-gend">Login to GenD</button>'
            : '';
    // @build-strip-end wporg
        // Pill backgrounds come from the consent-gated gend.me table
        // (inc/remote-assets.php via gsAdminData.navPillImages). An empty
        // URL renders a neutral placeholder instead of requesting gend.me.
        var pills = data.navPillImages || {};
        function pillBg(url) {
            if (url) {
                return '<img src="' + String(url).replace(/"/g, '&quot;') + '" class="pill-bg" alt="">';
            }
            return '<span class="pill-bg" aria-hidden="true" style="display:block;' +
                String(data.remotePlaceholderCss || '').replace(/"/g, '') + '"></span>';
        }

        return '' +
    // @build-strip-begin wporg
            loginBtn +
    // @build-strip-end wporg
            '<nav class="nav-central">' +
                '<a href="/app-features" class="nav-pill">' +
                    pillBg(pills.digitalBusiness) +
                    '<span class="pill-content">Digital Business</span>' +
                '</a>' +
                '<a href="/leo" class="nav-pill">' +
                    pillBg(pills.buildWithLeo) +
                    '<span class="pill-content">Build with LEO</span>' +
                '</a>' +
                '<a href="/smart-wallets" class="nav-pill">' +
                    pillBg(pills.contractWallet) +
                    '<span class="pill-content">Contract Wallet</span>' +
                '</a>' +
            '</nav>' +
            '<div class="visit-site-slot">' +
                '<a href="/" class="btn-visit-site" target="_blank" rel="noopener">Visit Site</a>' +
            '</div>';
    }
    // @build-strip-begin wporg

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
    // @build-strip-end wporg

    function injectHeader() {
        if (document.getElementById('main-3d-header')) { return; }

        // Never inject the branded 3D header into a framed wp-admin (e.g. the
        // owned landing/thank-you page editor embedded inside the Postings
        // modal's iframe) — it's meant for a standalone top-level admin
        // session, and duplicating it inside a small nested iframe just
        // clutters the embedded preview.
        if (window.self !== window.top) { return; }

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

    // The collapsed/hover-expand rail (admin-style.css) hides menu labels
    // via .gs-menu-label — our own add_menu_page() calls already wrap their
    // text in that span, but third-party plugins (myCred, network menus,
    // etc.) just pass plain text. Wrap those too so every item collapses to
    // an icon and expands to icon+label consistently, not just ours.
    function normalizeMenuLabels() {
        document.querySelectorAll('#adminmenu .wp-menu-name').forEach(function (name) {
            if (name.querySelector('.gs-menu-label')) { return; }
            var text = name.textContent.trim();
            if (!text) { return; }
            name.textContent = '';
            var label = document.createElement('span');
            label.className = 'gs-menu-label';
            label.textContent = text;
            name.appendChild(label);
        });
    }

    // Each item's own submenu now opens purely on hovering/focusing that
    // specific item (admin-style.css: li.menu-top:hover > .wp-submenu). If
    // that item — or the submenu it just revealed — sits near the top/
    // bottom edge of the scrollable rail, bring it fully into view instead
    // of leaving it clipped or the rail scrolled somewhere unrelated.
    function scrollHoveredItemIntoView() {
        document.querySelectorAll('#adminmenu li.menu-top').forEach(function (li) {
            li.addEventListener('mouseenter', function () {
                li.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            });
        });
    }

    // ── Touch / tablet / phone navigation ──────────────────────────
    // The rail above opens on hover, which touch screens don't have, and WP
    // core hides #adminmenuwrap at <=782px expecting the admin-bar toggle
    // (hidden on gend.me). In this mode (same query as the CSS section
    // "TOUCH / TABLET / PHONE NAV") the header gets a menu button, the rail
    // becomes an off-canvas drawer with full labels, and a parent row with a
    // submenu expands it in place instead of navigating.
    var NAV_MQ = '(max-width: 1024px), (hover: none) and (pointer: coarse)';

    function setupTouchNav() {
        var menuMain = document.getElementById('adminmenumain');
        var menu = document.getElementById('adminmenu');
        var header = document.getElementById('main-3d-header');
        if (!menuMain || !menu || !header || !window.matchMedia) { return; }
        var mq = window.matchMedia(NAV_MQ);
        var root = document.documentElement;

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'gs-nav-toggle';
        btn.setAttribute('aria-controls', 'adminmenumain');
        btn.setAttribute('aria-expanded', 'false');
        btn.setAttribute('aria-label', 'Open menu');
        btn.innerHTML = '<span class="gs-nav-toggle-bars" aria-hidden="true"><span></span><span></span><span></span></span>';
        header.insertBefore(btn, header.firstChild);

        var scrim = document.createElement('div');
        scrim.className = 'gs-nav-scrim';
        scrim.setAttribute('aria-hidden', 'true');
        document.body.appendChild(scrim);

        // Nav mode = the media query OR html.gs-nav-compact: wider screens whose header links do not fit
        // (narrow desktop windows, browser zoom, more/longer group links) switch to the menu button + drawer
        // instead of clipping the last links (2026-11-15).
        function navMode() { return mq.matches || root.classList.contains('gs-nav-compact'); }
        function isOpen() { return root.classList.contains('gs-nav-open'); }
        function setOpen(on) {
            root.classList.toggle('gs-nav-open', !!on);
            btn.setAttribute('aria-expanded', on ? 'true' : 'false');
            btn.setAttribute('aria-label', on ? 'Close menu' : 'Open menu');
            if (on) {
                var cur = menu.querySelector('li.gs-sub-open, li.current, li.wp-has-current-submenu');
                if (cur && cur.scrollIntoView) { cur.scrollIntoView({ block: 'nearest' }); }
            }
        }
        btn.addEventListener('click', function (e) { e.stopPropagation(); setOpen(!isOpen()); });
        scrim.addEventListener('click', function () { setOpen(false); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isOpen()) { setOpen(false); btn.focus(); }
        });
        var onChange = function () { checkFit(); if (!navMode()) { setOpen(false); } };
        if (mq.addEventListener) { mq.addEventListener('change', onChange); } else if (mq.addListener) { mq.addListener(onChange); }
        // Back/forward cache restores the page with the drawer still open.
        window.addEventListener('pageshow', function (e) { if (e.persisted) { setOpen(false); } });

        // Accordion: the current section starts open; tapping a parent row
        // toggles its own submenu (the submenu's first row is the parent page).
        menu.querySelectorAll('li.wp-has-submenu').forEach(function (li) {
            var a = li.querySelector(':scope > a');
            if (!a || !li.querySelector(':scope > .wp-submenu')) { return; }
            var open = li.classList.contains('wp-has-current-submenu') || li.classList.contains('wp-menu-open');
            li.classList.toggle('gs-sub-open', open);
            a.setAttribute('aria-expanded', open ? 'true' : 'false');
            a.addEventListener('click', function (e) {
                if (!navMode()) { return; }
                e.preventDefault();
                var willOpen = !li.classList.contains('gs-sub-open');
                li.classList.toggle('gs-sub-open', willOpen);
                a.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            });
        });

        // In nav mode the header's connected-group links move to the top of the drawer
        // (CSS shows this block only in nav mode).
        var groupLinks = header.querySelectorAll('.nav-central--group .gs-group-nav-item');
        if (groupLinks.length) {
            var data = window.gsAdminData || {};
            var li = document.createElement('li');
            li.className = 'gs-drawer-group';
            var html = '<span class="gs-drawer-group-title">' + escapeHtml(data.gendGroupName || 'gend.me group') + '</span><div class="gs-drawer-group-links">';
            groupLinks.forEach(function (g) {
                var icon = g.querySelector('.dashicons');
                html += '<a href="' + escapeHtml(g.getAttribute('href')) + '" target="_blank" rel="noopener">' +
                    (icon ? '<span class="dashicons ' + escapeHtml(icon.className.replace('gs-group-nav-icon', '')) + '" aria-hidden="true"></span>' : '') +
                    '<span>' + escapeHtml(g.textContent.trim()) + '</span></a>';
            });
            li.innerHTML = html + '</div>';
            menu.insertBefore(li, menu.firstChild);
        }

        // Fit check (desktop sizes): if the group links overflow their slot, run into the profile button or push
        // the header wider than the window, switch to compact nav mode. Remember the width that was needed and
        // switch back only once the window is that wide again (the links are hidden while compact).
        var nav = header.querySelector('.nav-central--group');
        var needW = 0;
        function overflowBy() {
            if (!nav) { return 0; }
            var nr = nav.getBoundingClientRect();
            var over = Math.max(0, nav.scrollWidth - nav.clientWidth, header.scrollWidth - header.clientWidth);
            var items = nav.querySelectorAll('.gs-group-nav-item');
            if (items.length) { over = Math.max(over, items[items.length - 1].getBoundingClientRect().right - nr.right); }
            var next = nav.nextElementSibling;
            while (next && !next.getBoundingClientRect().width) { next = next.nextElementSibling; }
            if (next) { over = Math.max(over, nr.right + 12 - next.getBoundingClientRect().left); }
            items.forEach(function (it) { if (it.scrollWidth > it.clientWidth + 2) { over = Math.max(over, it.scrollWidth - it.clientWidth); } });
            return Math.ceil(over);
        }
        function checkFit() {
            if (!nav || mq.matches) { root.classList.remove('gs-nav-compact'); return; }
            if (root.classList.contains('gs-nav-compact')) {
                if (needW && window.innerWidth >= needW) {
                    root.classList.remove('gs-nav-compact');
                    setOpen(false);
                    var o = overflowBy();
                    if (o > 0) { needW = window.innerWidth + o + 24; root.classList.add('gs-nav-compact'); }
                }
                return;
            }
            var over = overflowBy();
            if (over > 0) { needW = window.innerWidth + over + 24; root.classList.add('gs-nav-compact'); }
        }
        var fitT = 0;
        window.addEventListener('resize', function () { clearTimeout(fitT); fitT = setTimeout(checkFit, 80); });
        if (document.fonts && document.fonts.ready) { document.fonts.ready.then(checkFit); }
        window.addEventListener('load', checkFit);
        checkFit();
    }

    document.addEventListener('DOMContentLoaded', function () {
        injectHeader();
        bindProfileDropdown();
        attach3DHover();
        markActive();
        enhanceSubmenus();
        normalizeMenuLabels();
        scrollHoveredItemIntoView();
        setupTouchNav();
    });
})();
