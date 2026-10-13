/* gend-society: checkout launcher error page (inc/plan-access.php). Closes the checkout popup. */
(function () {
	'use strict';
	var button = document.querySelector('.gs-plan-launcher__close');
	if (!button) {
		return;
	}
	button.addEventListener('click', function () {
		window.close();
	});
})();
