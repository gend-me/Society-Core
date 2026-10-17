/**
 * Profile tab video backdrop (gend-society inc/profile-video-backdrop.php): the video's frames (a pre-extracted WebP
 * sequence) are drawn on a canvas that stays pinned to the viewport, clipped to the tab content, with its bottom-right
 * corner (where the vines start) anchored to the screen's bottom-right. Scrolling grows the scene at a steady rate per
 * pixel scrolled; on a long tab it shrinks back over the last stretch, on a short one it simply opens as far as the
 * page allows. Adjacent frames are cross-faded and the position is eased per frame, so scrubbing stays smooth.
 * A glass pass turns the content's solid panels translucent so they layer over the scene.
 */
(function () {
    'use strict';
    var CFG = {}, lastCfg = null;

    function boot() {
        CFG = window.GS_VIDEO_BACKDROP || {};
        lastCfg = window.GS_VIDEO_BACKDROP || null;
        var host = CFG.host ? document.querySelector(CFG.host) : null;
        var count = parseInt(CFG.count, 10) || 0;
        if (!host || !CFG.frames || !count || host.dataset.gsVbg) return;
        host.dataset.gsVbg = '1';
        host.classList.add('gs-vbg-host');

        var clip = document.createElement('div');
        clip.className = 'gs-vbg';
        clip.setAttribute('aria-hidden', 'true');
        var stage = document.createElement('div');
        stage.className = 'gs-vbg__stage';
        var canvas = document.createElement('canvas');
        canvas.className = 'gs-vbg__canvas';
        var shade = document.createElement('div');
        shade.className = 'gs-vbg__shade';
        stage.appendChild(canvas); stage.appendChild(shade);
        clip.appendChild(stage);
        host.insertBefore(clip, host.firstChild);
        // Some tabs swap the host's content in place (group page loaders) — put the scene back if it gets removed.
        if (window.MutationObserver) new MutationObserver(function () {
            if (!host.contains(clip)) host.insertBefore(clip, host.firstChild);
        }).observe(host, { childList: true });
        var ctx = canvas.getContext('2d');

        var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var startAt = CFG.start != null && !isNaN(parseFloat(CFG.start)) ? parseFloat(CFG.start) : 0.12;
        var floor = Math.round(count * startAt);   // default: the first ~1s is near-black, start where the seed is visible
        // which point of the scene stays pinned to the viewport: x/y as 0..1 (right bottom = 1,1; center top = .5,0)
        var AX = { left: 0, center: 0.5, right: 1 }, AY = { top: 0, center: 0.5, bottom: 1 };
        var anc = String(CFG.anchor || 'right bottom').split(/\s+/);
        var ax = AX[anc[0]] != null ? AX[anc[0]] : 1, ay = AY[anc[1] || anc[0]] != null ? AY[anc[1] || anc[0]] : 1;
        clip.style.setProperty('--gs-vbg-ax', (ax * 100) + '%');
        clip.style.setProperty('--gs-vbg-ay', (ay * 100) + '%');
        var dim = parseFloat(CFG.dim);
        if (dim > 0) clip.style.setProperty('--gs-vbg-dim', String(Math.min(0.9, dim)));   // semi-transparent black filter
        var last = count - 1;

        // ---- frames: load coarse-to-fine (every 16th, then 8th, 4th, 2nd, all) so scrubbing works almost at once
        var imgs = new Array(count), ready = new Array(count);
        function src(i) { var n = String(i + 1); while (n.length < 3) n = '0' + n; return CFG.frames + 'f' + n + '.webp'; }
        var order = [], seen = {};
        [16, 8, 4, 2, 1].forEach(function (step) {
            for (var i = floor; i < count; i += step) if (!seen[i]) { seen[i] = 1; order.push(i); }
        });
        for (var j = 0; j < floor; j++) order.push(j);
        var inflight = 0, qi = 0;
        function pump() {
            while (inflight < 6 && qi < order.length) load(order[qi++]);
        }
        function load(i) {
            inflight++;
            var im = new Image();
            im.decoding = 'async';
            im.onload = function () {
                var done = function () { ready[i] = true; inflight--; dirty = true; kick(); pump(); };
                if (im.decode) im.decode().then(done, done); else done();
            };
            im.onerror = function () { inflight--; pump(); };
            im.src = src(i);
            imgs[i] = im;
        }
        function nearest(i, dir) {   // closest loaded frame at or beyond i in direction dir
            for (var k = i; k >= 0 && k <= last; k += dir) if (ready[k]) return k;
            return -1;
        }

        // ---- drawing: cover-fit anchored bottom-right, cross-fade between the two frames around the position
        var cw = 0, ch = 0, dpr = 1;
        function size() {
            dpr = Math.min(window.devicePixelRatio || 1, 2);
            var w = Math.round(window.innerWidth * dpr), h = Math.round(window.innerHeight * dpr);
            if (w !== cw || h !== ch) { cw = canvas.width = w; ch = canvas.height = h; dirty = true; }
        }
        function blit(im, alpha) {
            var iw = im.naturalWidth, ih = im.naturalHeight;
            if (!iw || !ih) return;
            var s = Math.max(cw / iw, ch / ih), dw = iw * s, dh = ih * s;
            ctx.globalAlpha = alpha;
            ctx.drawImage(im, (cw - dw) * ax, (ch - dh) * ay, dw, dh);
        }
        var drawn = -1, dirty = true;
        function draw(pos) {
            var a = Math.floor(pos), f = pos - a;
            var lo = nearest(a, -1), hi = nearest(Math.min(last, a + 1), 1);
            if (lo < 0 && hi < 0) return;
            if (lo < 0) { lo = hi; f = 0; } else if (hi < 0 || hi === lo) { hi = lo; f = 0; } else { f = (pos - lo) / (hi - lo); }
            var key = lo + ':' + hi + ':' + Math.round(f * 64);
            if (!dirty && key === drawn) return;
            drawn = key; dirty = false;
            ctx.globalAlpha = 1;
            ctx.fillStyle = '#05070c';
            ctx.fillRect(0, 0, cw, ch);
            blit(imgs[lo], 1);
            if (f > 0.004 && hi !== lo) blit(imgs[hi], f);
            ctx.globalAlpha = 1;
        }

        // ---- scroll → frame position: a steady ping-pong loop (grow, shrink, grow, …) over the scroll distance; a short
        // tab just opens as far as it goes
        function docTop(el) { var r = el.getBoundingClientRect(); return r.top + window.pageYOffset; }
        function targetPos() {
            var vh = window.innerHeight;
            var start = Math.max(0, docTop(host) - vh * 0.85);   // begins as the tab's top edge rises into view
            var openPx = Math.max(700, vh * 1.5);                 // pixels of scroll for one open (or one close)
            var ph = (Math.max(0, window.pageYOffset - start) / openPx) % 2;
            var g = ph <= 1 ? ph : 2 - ph;
            g = g * g * (3 - 2 * g);                               // soft turnaround at both ends
            return floor + g * (last - floor);
        }

        var cur = -1, raf = 0, prevT = 0;
        function frame(t) {
            raf = 0;
            var tgt = reduce ? floor + (last - floor) * 0.6 : targetPos();
            if (cur < 0) cur = tgt;
            var dt = prevT ? Math.min(64, t - prevT) : 16;
            prevT = t;
            var k = 1 - Math.exp(-dt / 140);                       // ~140ms time constant: smooth, frame-rate independent
            cur += (tgt - cur) * k;
            if (Math.abs(tgt - cur) < 0.01) cur = tgt;
            draw(cur);
            if (cur !== tgt || dirty) kick(); else prevT = 0;
        }
        function kick() { if (!raf) raf = requestAnimationFrame(frame); }

        size();
        // an ancestor transform makes position:fixed relative to it - fall back to a sticky stage
        var sr = stage.getBoundingClientRect();
        if (Math.abs(sr.top) > 2 || Math.abs(sr.height - window.innerHeight) > 2) clip.classList.add('gs-vbg--sticky');
        pump();
        window.addEventListener('scroll', kick, { passive: true });
        window.addEventListener('resize', function () { size(); kick(); });

        // Glass pass: large solid / gradient panels inside the tab become translucent glass over the scene.
        function alphaOf(c) {
            var m = /rgba?\(([^)]+)\)/.exec(c || '');
            if (!m) return 0;
            var p = m[1].split(',');
            return p.length > 3 ? parseFloat(p[3]) : 1;
        }
        function rgbOf(c) { var m = /rgba?\((\d+),\s*(\d+),\s*(\d+)/.exec(c || ''); return m ? [m[1], m[2], m[3]] : [10, 14, 24]; }
        var KEEP = '.aas-grow-cta, .aas-grow-cta-link, button, a, [role="button"], .button, .gs-vbg-keep';
        // Tab roots that paint a solid backdrop of their own go transparent: the scene is the only background.
        function clearRoots() {
            if (!CFG.clear) return;
            var roots;
            try { roots = host.querySelectorAll(CFG.clear); } catch (e) { return; }
            for (var i = 0; i < roots.length; i++) {
                var r = roots[i];
                if (r.dataset.gsCleared) continue;
                r.dataset.gsCleared = '1';
                r.dataset.gsGlass = '1';   // never tinted by the glass pass either
                r.style.setProperty('background', 'transparent', 'important');
                r.style.setProperty('background-image', 'none', 'important');
            }
        }
        function glassify() {
            clearRoots();
            var nodes = host.querySelectorAll('*');
            for (var i = 0; i < nodes.length; i++) {
                var el = nodes[i];
                if (el.dataset.gsGlass || clip.contains(el)) continue;
                // keep branded colour: the Invite by email / Share on social cards, buttons and links
                if (el.closest(KEEP)) continue;
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
        document.addEventListener('click', function () { setTimeout(glassify, 120); setTimeout(glassify, 900); setTimeout(kick, 400); });
        kick();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
    // content-only profile navigation (member-profile-header.php PJAX): boot again when the new page set a fresh config
    document.addEventListener('gdc:pjax-load', function () { if (window.GS_VIDEO_BACKDROP && window.GS_VIDEO_BACKDROP !== lastCfg) boot(); });
}());
