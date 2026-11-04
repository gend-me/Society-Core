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

	// A video layer is a run of frames: every 8th first, then the rest, so scrubbing works almost at once.
	function setupFrames( layer ) {
		var count = parseInt( layer.getAttribute( 'data-frames' ) ? layer.getAttribute( 'data-count' ) : 0, 10 );
		if ( ! count ) {
			return null;
		}
		var base = layer.getAttribute( 'data-frames' );
		var ext = layer.getAttribute( 'data-ext' ) || 'jpg';
		var canvas = document.createElement( 'canvas' );
		canvas.className = 'gss-canvas';
		canvas.setAttribute( 'aria-hidden', 'true' );
		canvas.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;';
		layer.appendChild( canvas );
		var f = { canvas: canvas, ctx: canvas.getContext( '2d' ), count: count, images: [], at: -1, started: false };
		f.start = function () {
			if ( f.started ) {
				return;
			}
			f.started = true;
			var order = [];
			[ 8, 4, 2, 1 ].forEach( function ( step ) {
				for ( var i = 0; i < count; i += step ) {
					if ( order.indexOf( i ) < 0 ) {
						order.push( i );
					}
				}
			} );
			var active = 0;
			( function next() {
				while ( active < 6 && order.length ) {
					( function ( i ) {
						active++;
						var im = new Image();
						im.onload = function () {
							f.images[ i ] = im;
							active--;
							f.at = -1;
							onScroll();
							next();
						};
						im.onerror = function () {
							active--;
							next();
						};
						im.src = base + 'f' + ( '00' + ( i + 1 ) ).slice( -3 ) + '.' + ext;
					}( order.shift() ) );
				}
			}() );
		};
		return f;
	}

	function drawFrames( f, local ) {
		f.start();
		if ( reduce ) {
			return;
		}
		var want = Math.round( local * ( f.count - 1 ) );
		var i = want;
		// The nearest frame that has loaded.
		for ( var d = 0; d < f.count && ! f.images[ i ]; d++ ) {
			i = f.images[ want - d ] ? want - d : want + d;
		}
		var im = f.images[ i ];
		if ( ! im ) {
			return;
		}
		var box = f.canvas.getBoundingClientRect();
		var w = Math.round( box.width ) || 1;
		var h = Math.round( box.height ) || 1;
		if ( f.canvas.width !== w || f.canvas.height !== h ) {
			f.canvas.width = w;
			f.canvas.height = h;
			f.at = -1;
		}
		if ( f.at === i ) {
			return;
		}
		f.at = i;
		var scale = Math.max( w / im.naturalWidth, h / im.naturalHeight );
		var dw = im.naturalWidth * scale;
		var dh = im.naturalHeight * scale;
		f.ctx.drawImage( im, ( w - dw ) / 2, ( h - dh ) / 2, dw, dh );
	}

	function update() {
		ticking = false;
		var vh = window.innerHeight;
		scenes.forEach( function ( s ) {
			var r = s.el.getBoundingClientRect();
			var whole = s.el.classList.contains( 'gss--page' );
			var behind = s.el.classList.contains( 'gss--section' );
			if ( ! whole && ( r.bottom < -vh || r.top > vh * 2 ) ) {
				return;
			}
			var total = Math.max( 1, s.el.offsetHeight - vh );
			var p = clamp( -r.top / total );
			if ( whole ) {
				p = clamp( window.scrollY / Math.max( 1, document.documentElement.scrollHeight - vh ) );
			} else if ( behind ) {
				p = clamp( ( vh - r.top ) / ( vh + r.height ) );
			}
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
				if ( layer.gssFrames ) {
					drawFrames( layer.gssFrames, local );
				}
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
			var layers = Array.prototype.slice.call( el.querySelectorAll( '.gss-layer' ) );
			layers.forEach( function ( layer ) {
				layer.gssFrames = setupFrames( layer );
			} );
			return { el: el, layers: layers };
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
