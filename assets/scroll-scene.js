/**
 * Scroll Scene (front end): moves and cross-fades each scene's image layers
 * with the visitor's scroll position. Transforms and opacity only, one
 * requestAnimationFrame per scroll; reduced-motion visitors get gentle
 * cross-fades without movement.
 */
( function () {
	'use strict';
	var scenes = [];
	var ticking = false;
	var reduce = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	function clamp( v ) {
		return v < 0 ? 0 : v > 1 ? 1 : v;
	}

	function effect( name, p ) {
		if ( reduce ) {
			return { t: 'none', f: '' };
		}
		switch ( name ) {
			case 'zoom-in': return { t: 'scale(' + ( 1 + 0.22 * p ) + ')', f: '' };
			case 'zoom-out': return { t: 'scale(' + ( 1.22 - 0.22 * p ) + ')', f: '' };
			case 'pan-left': return { t: 'scale(1.14) translateX(' + ( 3 - 6 * p ) + '%)', f: '' };
			case 'pan-right': return { t: 'scale(1.14) translateX(' + ( -3 + 6 * p ) + '%)', f: '' };
			case 'pan-up': return { t: 'scale(1.16) translateY(' + ( 4 - 8 * p ) + '%)', f: '' };
			case 'parallax': return { t: 'scale(1.18) translateY(' + ( ( 0.5 - p ) * 12 ) + '%)', f: '' };
			case 'blur-in': return { t: 'scale(' + ( 1.08 - 0.08 * p ) + ')', f: 'blur(' + ( ( 1 - Math.min( 1, p * 2 ) ) * 14 ).toFixed( 1 ) + 'px)' };
			default: return { t: 'none', f: '' };
		}
	}

	function update() {
		ticking = false;
		var vh = window.innerHeight;
		scenes.forEach( function ( s ) {
			var r = s.el.getBoundingClientRect();
			if ( r.bottom < -vh || r.top > vh * 2 ) {
				return;
			}
			var total = Math.max( 1, s.el.offsetHeight - vh );
			var p = clamp( -r.top / total );
			var n = s.layers.length;
			var seg = 1 / n;
			var fade = Math.min( 0.12, seg * 0.4 );
			s.layers.forEach( function ( layer, i ) {
				var start = i * seg;
				// Each layer fades in over the previous one, then keeps moving.
				var opacity = i === 0 ? 1 : clamp( ( p - start + fade ) / ( 2 * fade ) );
				var local = n === 1 ? p : clamp( ( p - start + fade ) / ( seg + 2 * fade ) );
				var fx = effect( layer.getAttribute( 'data-effect' ), local );
				layer.style.opacity = String( opacity );
				layer.style.transform = fx.t;
				layer.style.filter = fx.f;
			} );
		} );
	}

	function onScroll() {
		if ( ! ticking ) {
			ticking = true;
			window.requestAnimationFrame( update );
		}
	}

	function init() {
		scenes = Array.prototype.slice.call( document.querySelectorAll( '.gss' ) ).map( function ( el ) {
			return { el: el, layers: Array.prototype.slice.call( el.querySelectorAll( '.gss-layer' ) ) };
		} ).filter( function ( s ) {
			return s.layers.length;
		} );
		if ( ! scenes.length ) {
			return;
		}
		document.documentElement.classList.add( 'gss-ready' );
		window.addEventListener( 'scroll', onScroll, { passive: true } );
		window.addEventListener( 'resize', onScroll );
		update();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
