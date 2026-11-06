/*
 * Live dashboard widgets ([gs_live]): each one animates once as it comes into view. Numbers count up to the value the
 * page already shows, so with no script, or with reduced motion, nothing changes.
 */
(function () {
  'use strict';
  if (!('IntersectionObserver' in window) || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  function countUp(el) {
    var to = parseFloat(el.getAttribute('data-count')) || 0;
    var dec = parseInt(el.getAttribute('data-decimals'), 10) || 0;
    var prefix = el.getAttribute('data-prefix') || '';
    var fmt = new Intl.NumberFormat(document.documentElement.lang || undefined, { minimumFractionDigits: dec, maximumFractionDigits: dec });
    var start = null;
    var ms = 1100;
    function step(now) {
      if (start === null) start = now;
      var t = Math.min(1, (now - start) / ms);
      var eased = 1 - Math.pow(1 - t, 3);
      el.textContent = prefix + fmt.format(to * eased);
      if (t < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }

  var seen = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) {
      if (!e.isIntersecting) return;
      seen.unobserve(e.target);
      e.target.classList.add('is-in');
      e.target.querySelectorAll('.gs-live__num[data-count]').forEach(countUp);
    });
  }, { threshold: 0.2 });

  function arm(root) {
    (root || document).querySelectorAll('.gs-live:not(.is-armed)').forEach(function (el) {
      el.classList.add('is-armed');
      seen.observe(el);
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { arm(); });
  else arm();
  // Dashboards swapped in without a page load (tabs) arm themselves too.
  // One look per frame however busy the page is.
  var queued = false;
  new MutationObserver(function () {
    if (queued) return;
    queued = true;
    requestAnimationFrame(function () { queued = false; arm(); });
  }).observe(document.documentElement, { childList: true, subtree: true });
}());
