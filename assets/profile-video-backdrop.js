/**
 * Profile tab video backdrop (gend-society inc/profile-video-backdrop.php): a sticky video behind the tab content,
 * scrubbed by scroll (forward then back across the tab — the vines grow, then shrink), and a glass pass that turns the
 * content's solid panels translucent so they layer over the video.
 */
(function () {
    'use strict';
    var CFG = window.GS_VIDEO_BACKDROP || {};

    function boot() {
        var host = CFG.host ? document.querySelector(CFG.host) : null;
        if (!host || !CFG.video || host.dataset.gsVbg) return;
        host.dataset.gsVbg = '1';
        host.classList.add('gs-vbg-host');

        var stage = document.createElement('div');
        stage.className = 'gs-vbg';
        stage.setAttribute('aria-hidden', 'true');
        var v = document.createElement('video');
        v.className = 'gs-vbg__video';
        v.muted = true; v.defaultMuted = true; v.playsInline = true;
        v.setAttribute('muted', ''); v.setAttribute('playsinline', ''); v.setAttribute('webkit-playsinline', '');
        v.setAttribute('disablepictureinpicture', ''); v.preload = 'auto';
        v.src = CFG.video;
        var shade = document.createElement('div');
        shade.className = 'gs-vbg__shade';
        stage.appendChild(v); stage.appendChild(shade);
        host.insertBefore(stage, host.firstChild);

        var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var dur = 0, target = 0, cur = 0, raf = 0;

        function progress() {
            var r = host.getBoundingClientRect();
            var span = Math.max(1, r.height - window.innerHeight);
            return Math.min(1, Math.max(0, -r.top / span));
        }
        var seekAt = 0;
        function tick() {   // rAF, with a timer fallback for when frames are throttled
            if (raf) return;
            raf = 1;
            var done = false;
            function run() { if (done) return; done = true; raf = 0; step(); }
            requestAnimationFrame(run);
            setTimeout(run, 50);
        }
        function aim() {
            if (!dur) return;
            var p = progress();
            var floor = dur * 0.12;   // the first ~1s is near-black; start where the seed is already visible
            target = floor + (p <= 0.5 ? p * 2 : (1 - p) * 2) * (dur - 0.05 - floor);   // grow, then shrink
            tick();
        }
        function step() {
            cur += (target - cur) * 0.16;
            if (Math.abs(target - cur) < 0.004) cur = target;
            var now = Date.now();
            // a stalled seek (rare decoder hiccup) is retried after 400ms
            if ((!v.seeking || now - seekAt > 400) && Math.abs(v.currentTime - cur) > 1 / 60) {
                seekAt = now;
                try { v.currentTime = cur; } catch (e) {}
            }
            // keep going until the video itself has landed on the target, not just the eased value
            if (cur !== target || v.seeking || Math.abs(v.currentTime - target) > 1 / 60) tick();
        }
        v.addEventListener('seeked', function () { if (Math.abs(v.currentTime - target) > 1 / 60) tick(); });
        v.addEventListener('loadedmetadata', function () {
            dur = v.duration || 0;
            if (reduce) { try { v.currentTime = dur * 0.5; } catch (e) {} return; }
            var pr = v.play();   // a muted play/pause unlocks seeking on iOS
            if (pr && pr.then) pr.then(function () { v.pause(); aim(); }).catch(function () { aim(); }); else { v.pause(); aim(); }
        });
        if (!reduce) {
            window.addEventListener('scroll', aim, { passive: true });
            window.addEventListener('resize', aim);
        }

        // Glass pass: large solid / gradient panels inside the tab become translucent glass over the video.
        function alphaOf(c) {
            var m = /rgba?\(([^)]+)\)/.exec(c || '');
            if (!m) return 0;
            var p = m[1].split(',');
            return p.length > 3 ? parseFloat(p[3]) : 1;
        }
        function rgbOf(c) { var m = /rgba?\((\d+),\s*(\d+),\s*(\d+)/.exec(c || ''); return m ? [m[1], m[2], m[3]] : [10, 14, 24]; }
        function glassify() {
            var nodes = host.querySelectorAll('*');
            for (var i = 0; i < nodes.length; i++) {
                var el = nodes[i];
                if (el.dataset.gsGlass || el === stage || stage.contains(el)) continue;
                var tag = el.tagName;
                if (tag === 'IMG' || tag === 'VIDEO' || tag === 'SVG' || tag === 'svg' || tag === 'CANVAS' || tag === 'IFRAME' || tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA') continue;
                var w = el.offsetWidth, h = el.offsetHeight;
                if (w < 160 || h < 44) continue;
                var cs = getComputedStyle(el);
                var a = alphaOf(cs.backgroundColor);
                var grad = cs.backgroundImage && cs.backgroundImage.indexOf('gradient') !== -1 && cs.backgroundImage.indexOf('url(') === -1;
                if (a < 0.55 && !grad) continue;
                el.dataset.gsGlass = '1';
                var rgb = rgbOf(a >= 0.55 ? cs.backgroundColor : 'rgb(10,14,24)');
                el.style.setProperty('background-image', 'none', 'important');
                el.style.setProperty('background-color', 'rgba(' + rgb.join(',') + ',0.46)', 'important');
                el.classList.add('gs-vglass');
            }
        }
        glassify();
        setTimeout(glassify, 900);
        setTimeout(glassify, 2600);
        document.addEventListener('click', function () { setTimeout(glassify, 120); setTimeout(glassify, 900); });
        setTimeout(aim, 300);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
}());
