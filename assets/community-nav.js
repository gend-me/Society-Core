/**
 * A web app's profile: its tabs open in place. Only the tab's content (main.youzify-page-main-content) is swapped; the
 * header, the page and the chat stay. Anything that goes wrong falls back to an ordinary page load.
 * Loaded on branded web apps only (inc/hub-boundary.php).
 */
( function () {
	'use strict';
	var AREA = 'main.youzify-page-main-content';
	var TABS = '.gs-community-profile-header a.gdc-ps-nav-item, ' + AREA + ' #subnav a';
	var area = document.querySelector( AREA );
	if ( ! area || ! window.fetch || ! window.DOMParser || ! window.history || ! history.pushState ) {
		return;
	}
	var root = location.origin + location.pathname.split( '/' ).slice( 0, location.pathname.split( '/' ).indexOf( 'members' ) + 2 ).join( '/' ) + '/';
	var busy = null;

	function sameProfile( href ) {
		return href && href.indexOf( root ) === 0 && href.indexOf( '#' ) === -1 && ! /[?&](action|_wpnonce)=/.test( href ) && ! /\/(logout|delete-account)\//.test( href );
	}

	// Loads the scripts and styles the new tab needs that this page doesn't have yet, in order.
	function addAssets( doc ) {
		var waits = [];
		doc.querySelectorAll( 'link[rel="stylesheet"][href]' ).forEach( function ( l ) {
			if ( ! document.querySelector( 'link[rel="stylesheet"][href="' + l.getAttribute( 'href' ) + '"]' ) ) {
				var n = document.createElement( 'link' );
				n.rel = 'stylesheet';
				n.href = l.getAttribute( 'href' );
				document.head.appendChild( n );
			}
		} );
		var chain = Promise.resolve();
		doc.querySelectorAll( 'script' ).forEach( function ( s ) {
			if ( s.closest && s.closest( AREA ) ) {
				return; // Run with the content, below.
			}
			var src = s.getAttribute( 'src' );
			var id = s.id;
			if ( src ? document.querySelector( 'script[src="' + src + '"]' ) : ( ! id || document.getElementById( id ) ) ) {
				return;
			}
			chain = chain.then( function () {
				return new Promise( function ( done ) {
					var n = document.createElement( 'script' );
					if ( id ) {
						n.id = id;
					}
					if ( src ) {
						n.src = src;
						n.onload = n.onerror = done;
					} else {
						n.textContent = s.textContent;
					}
					document.body.appendChild( n );
					if ( ! src ) {
						done();
					}
				} );
			} );
		} );
		waits.push( chain );
		return Promise.all( waits );
	}

	// Scripts inside the swapped content run as they would on a page load.
	function runScripts( el ) {
		el.querySelectorAll( 'script' ).forEach( function ( old ) {
			var n = document.createElement( 'script' );
			Array.prototype.forEach.call( old.attributes, function ( a ) {
				n.setAttribute( a.name, a.value );
			} );
			n.textContent = old.textContent;
			old.parentNode.replaceChild( n, old );
		} );
	}

	function markTab( href ) {
		document.querySelectorAll( '.gs-community-profile-header a.gdc-ps-nav-item' ).forEach( function ( a ) {
			var on = href.indexOf( a.href ) === 0 && ( a.href !== root || href === root );
			a.classList.toggle( 'is-active', on );
			if ( on ) {
				a.setAttribute( 'aria-current', 'page' );
			} else {
				a.removeAttribute( 'aria-current' );
			}
		} );
	}

	// A tab starts loading when it's pointed at, so most of the wait is over by the click. Kept for half a minute.
	var cache = {};
	function load( href ) {
		var hit = cache[ href ];
		if ( hit && Date.now() - hit.at < 30000 ) {
			return hit.page;
		}
		var page = fetch( href, { credentials: 'same-origin', headers: { 'X-Requested-With': 'gs-tab' } } ).then( function ( r ) {
			if ( ! r.ok || r.redirected && ! sameProfile( r.url ) ) {
				throw new Error( 'page' );
			}
			return r.text();
		} );
		cache[ href ] = { at: Date.now(), page: page };
		page.catch( function () {
			delete cache[ href ];
		} );
		return page;
	}

	function open( href, push ) {
		var ticket = busy = {};
		area.classList.add( 'gs-tab-loading' );
		return load( href )
			.then( function ( html ) {
				if ( ticket !== busy ) {
					return; // Another tab was chosen meanwhile.
				}
				var doc = new DOMParser().parseFromString( html, 'text/html' );
				var next = doc.querySelector( AREA );
				if ( ! next ) {
					throw new Error( 'page' );
				}
				return addAssets( doc ).then( function () {
					area.innerHTML = next.innerHTML;
					runScripts( area );
					if ( push ) {
						history.pushState( { gsTab: href }, '', href );
					}
					markTab( href );
					area.classList.remove( 'gs-tab-loading' );
					var top = area.getBoundingClientRect().top + window.scrollY - 120;
					if ( window.scrollY > top ) {
						window.scrollTo( { top: top, behavior: 'smooth' } );
					}
					document.dispatchEvent( new CustomEvent( 'gs:tab-loaded', { detail: { url: href } } ) );
					if ( window.jQuery ) {
						window.jQuery( document ).trigger( 'gs:tab-loaded' );
					}
				} );
			} )
			.catch( function () {
				if ( ticket === busy ) {
					location.href = href;
				}
			} );
	}

	document.addEventListener( 'click', function ( e ) {
		if ( e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey ) {
			return;
		}
		var a = e.target.closest && e.target.closest( TABS );
		if ( ! a || a.target === '_blank' || ! sameProfile( a.href ) ) {
			return;
		}
		e.preventDefault();
		open( a.href, true );
	} );

	[ 'mouseover', 'touchstart', 'focusin' ].forEach( function ( type ) {
		document.addEventListener( type, function ( e ) {
			var a = e.target.closest && e.target.closest( TABS );
			if ( a && sameProfile( a.href ) && a.href !== location.href ) {
				load( a.href ).catch( function () {} );
			}
		}, { passive: true } );
	} );

	window.addEventListener( 'popstate', function () {
		if ( sameProfile( location.href ) ) {
			open( location.href, false );
		}
	} );
	history.replaceState( { gsTab: location.href }, '', location.href );
}() );
