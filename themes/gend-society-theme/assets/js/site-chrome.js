/**
 * GenD Society theme — 3D tilt of the marketing header (#main-3d-header).
 * Moved out of the header template part so the part stays plain markup.
 * Only runs while the header is visible (it is hidden for logged-in members).
 */
(function () {
	if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
		return;
	}
	document.addEventListener('mousemove', function (e) {
		var header = document.getElementById('main-3d-header');
		if (!header || getComputedStyle(header).display === 'none') {
			return;
		}
		var x = (window.innerWidth / 2 - e.pageX) / 130;
		var y = (window.innerHeight / 2 - e.pageY) / 130;
		header.style.transform = 'rotateY(' + (-x) + 'deg) rotateX(' + (y / 2) + 'deg)';
	});
})();
