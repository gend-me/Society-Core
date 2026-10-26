/* GenD Society - Design Studio brand style guide: keeps the guide's labels true to
   what the site's styles render, including the studio's unsaved live preview CSS. */
( function () {
	'use strict';

	function hex( rgb ) {
		var m = String( rgb ).match( /rgba?\(([^)]+)\)/ );
		var p;
		if ( m ) {
			p = m[ 1 ].split( /[\s,/]+/ ).filter( Boolean ).map( parseFloat );
		} else {
			// color-mix() and friends compute to color(srgb r g b / a) with 0-1 channels.
			m = String( rgb ).match( /color\(srgb\s+([^)]+)\)/ );
			if ( ! m ) {
				return { hex: '', rgb: '' };
			}
			p = m[ 1 ].split( /[\s/]+/ ).filter( Boolean ).map( function ( v, i ) {
				return i < 3 ? parseFloat( v ) * 255 : parseFloat( v );
			} );
		}
		var h = '#' + p.slice( 0, 3 ).map( function ( v ) {
			return ( '0' + Math.round( v ).toString( 16 ) ).slice( -2 );
		} ).join( '' );
		return { hex: h.toUpperCase(), rgb: p.slice( 0, 3 ).map( Math.round ).join( ' ' ) };
	}
	function firstFamily( ff ) {
		return String( ff || '' ).split( ',' )[ 0 ].replace( /["']/g, '' ).trim();
	}
	function px( v ) {
		var n = parseFloat( v );
		return isNaN( n ) ? v : Math.round( n * 10 ) / 10 + 'px';
	}

	function update() {
		// Specimen surfaces take the site's body styles (the guide frame has its own).
		var body = window.getComputedStyle( document.body );
		var canvasStyle = {
			background: body.backgroundColor,
			color: body.color,
			fontFamily: body.fontFamily,
			fontSize: body.fontSize,
			lineHeight: body.lineHeight,
			fontWeight: body.fontWeight,
			letterSpacing: body.letterSpacing,
		};
		Array.prototype.forEach.call( document.querySelectorAll( '.gsg-canvas' ), function ( el ) {
			Object.keys( canvasStyle ).forEach( function ( k ) {
				el.style[ k ] = canvasStyle[ k ];
			} );
		} );

		Array.prototype.forEach.call( document.querySelectorAll( '.gsg-swatch' ), function ( sw ) {
			var c = hex( window.getComputedStyle( sw.querySelector( '.gsg-chip' ) ).backgroundColor );
			var h = sw.querySelector( '[data-gsg="hex"]' );
			var r = sw.querySelector( '[data-gsg="rgb"]' );
			if ( h ) {
				h.textContent = c.hex || '—';
			}
			if ( r ) {
				r.textContent = c.rgb || '—';
			}
		} );

		Array.prototype.forEach.call( document.querySelectorAll( '[data-gsg-font]' ), function ( el ) {
			var name = document.querySelector( '[data-gsg-name="' + el.getAttribute( 'data-gsg-font' ) + '"]' );
			if ( name ) {
				name.textContent = firstFamily( window.getComputedStyle( el ).fontFamily ) || '—';
			}
		} );

		Array.prototype.forEach.call( document.querySelectorAll( '[data-gsg-el]' ), function ( el ) {
			var tag = el.getAttribute( 'data-gsg-el' );
			var spec = document.querySelector( '[data-gsg-spec="' + tag + '"]' );
			if ( ! spec ) {
				return;
			}
			var cs = window.getComputedStyle( el );
			var lh = cs.lineHeight === 'normal' ? 'normal' : ( Math.round( ( parseFloat( cs.lineHeight ) / parseFloat( cs.fontSize ) ) * 100 ) / 100 );
			spec.textContent = firstFamily( cs.fontFamily ) + ' · ' + cs.fontWeight + ( cs.fontStyle === 'italic' ? ' italic' : '' ) + '\n' + px( cs.fontSize ) + ' / ' + lh + ( cs.letterSpacing !== 'normal' ? ' · ' + cs.letterSpacing : '' );
			spec.style.whiteSpace = 'pre-line';
		} );
	}

	var queued = false;
	function schedule() {
		if ( queued ) {
			return;
		}
		queued = true;
		window.requestAnimationFrame( function () {
			queued = false;
			update();
		} );
	}

	update();
	window.addEventListener( 'load', schedule );
	window.addEventListener( 'resize', schedule );
	if ( document.fonts && document.fonts.ready ) {
		document.fonts.ready.then( schedule );
	}
	// The studio injects draft styles into <head>: refresh the labels when it does.
	new window.MutationObserver( schedule ).observe( document.head, { childList: true, subtree: true, characterData: true } );
}() );
