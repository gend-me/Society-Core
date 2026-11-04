/* Built pages: glass cards lean toward the pointer and a light follows it. The page is complete without this. */
(function () {
  // Whoever can edit this page can fill its image spots from the chat. Visitors never get these buttons.
  var edit = window.GEND_UC_SPOTS;
  function controls(detail, noteText) {
    var send = function (extra) { window.dispatchEvent(new CustomEvent('gend-uc-image', { detail: Object.assign({}, detail, extra) })); };
    var tools = document.createElement('div');
    tools.className = 'gs-slot-tools';
    if (noteText) {
      var note = document.createElement('small');
      note.textContent = noteText;
      tools.appendChild(note);
    }
    var row = document.createElement('div');
    [['Create image', 'image'], ['Create video', 'video']].forEach(function (pair) {
      var create = document.createElement('button');
      create.type = 'button';
      create.textContent = pair[0];
      create.addEventListener('click', function () { send({ kind: pair[1] }); });
      row.appendChild(create);
    });
    var upload = document.createElement('label');
    upload.textContent = 'Upload';
    var input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/png,image/jpeg,image/webp,video/mp4,video/webm';
    input.hidden = true;
    input.addEventListener('change', function () { if (input.files[0]) send({ file: input.files[0] }); });
    upload.appendChild(input);
    row.appendChild(upload);
    tools.appendChild(row);
    return tools;
  }
  (edit ? document.querySelectorAll('.gs-image-slot[data-slot]') : []).forEach(function (spot) {
    var idea = spot.dataset.idea || '';
    spot.removeAttribute('aria-hidden');
    spot.appendChild(controls({ group: edit.group, page: edit.page, slot: Number(spot.dataset.slot), idea: idea }, idea));
  });
  // Dark sections can carry an image or film behind them.
  (edit ? document.querySelectorAll('.gs-page section.gs-sec-hero,.gs-page section.gs-sec-band') : []).forEach(function (section) {
    var heading = section.querySelector('h1,h2');
    if (!heading) return;
    var detail = { group: edit.group, page: edit.page, section: heading.textContent, idea: heading.textContent };
    var tools = controls(detail, '');
    tools.classList.add('gs-slot-tools--section');
    var scene = document.createElement('button');
    scene.type = 'button';
    scene.textContent = 'Scroll scene';
    scene.addEventListener('click', function () { window.dispatchEvent(new CustomEvent('gend-uc-image', { detail: Object.assign({}, detail, { kind: 'scene' }) })); });
    tools.firstChild.appendChild(scene);
    section.appendChild(tools);
  });
  // A sub-site admin's content can't keep playsinline, and phones need it to play a page's film.
  document.querySelectorAll('.gs-page video[autoplay]').forEach(function (film) {
    film.playsInline = true;
    film.muted = true;
    if (film.play) film.play().catch(function () {});
  });
  if (!window.matchMedia('(hover:hover) and (pointer:fine) and (prefers-reduced-motion:no-preference)').matches) return;
  var limit = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--wp--custom--gs--tilt'));
  if (isNaN(limit)) limit = 7;
  document.addEventListener('pointermove', function (e) {
    var card = e.target.closest ? e.target.closest('.gs-page-cards li') : null;
    if (!card) return;
    var box = card.getBoundingClientRect();
    var x = (e.clientX - box.left) / box.width;
    var y = (e.clientY - box.top) / box.height;
    card.style.setProperty('--gs-mx', Math.round(x * 100) + '%');
    card.style.setProperty('--gs-my', Math.round(y * 100) + '%');
    card.style.setProperty('--gs-ry', ((x - 0.5) * limit * 2).toFixed(2) + 'deg');
    card.style.setProperty('--gs-rx', ((0.5 - y) * limit * 2).toFixed(2) + 'deg');
  }, { passive: true });
  document.addEventListener('pointerout', function (e) {
    var card = e.target.closest ? e.target.closest('.gs-page-cards li') : null;
    if (!card || (e.relatedTarget && card.contains(e.relatedTarget))) return;
    card.style.removeProperty('--gs-ry');
    card.style.removeProperty('--gs-rx');
  }, { passive: true });
})();
