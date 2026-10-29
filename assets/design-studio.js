/* GenD Society - Design Studio (inc/design-studio.php). */
( function () {
	'use strict';

	var cfg = window.gsStudio;
	var root = document.getElementById( 'gs-studio-root' );
	if ( ! cfg || ! root || ! window.wp || ! wp.apiFetch ) {
		return;
	}
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var api = wp.apiFetch;

	/* ── State ─────────────────────────────────────────────────────────── */
	var S = {
		templates: {}, // slug => template (wp_template)
		parts: {}, // slug => template part
		templatesLoaded: false,
		settings: null, // core site settings (title, description, site_logo, site_icon)
		gs: null, // user global styles draft { settings, styles }
		gsSaved: '', // JSON of the last saved user config
		base: null, // theme config { settings, styles }
		variations: [],
		media: {}, // attachment id => media object
		menus: null,
		tax: {}, // set => category template groups
		pt: null, // product templates: types, cats, attrs, map
		previewCss: '',
		previewMode: 'specimen',
		device: 'desktop',
	};

	/* ── Small helpers ─────────────────────────────────────────────────── */
	function h( tag, attrs ) {
		var el = document.createElement( tag );
		attrs = attrs || {};
		Object.keys( attrs ).forEach( function ( k ) {
			var v = attrs[ k ];
			if ( v === null || v === undefined || v === false ) {
				return;
			}
			if ( k === 'class' ) {
				el.className = v;
			} else if ( k === 'style' && typeof v === 'object' ) {
				Object.keys( v ).forEach( function ( p ) {
					el.style.setProperty( p, v[ p ] );
				} );
			} else if ( k.indexOf( 'on' ) === 0 && typeof v === 'function' ) {
				el.addEventListener( k.slice( 2 ), v );
			} else if ( k === 'text' ) {
				el.textContent = v;
			} else if ( v === true ) {
				el.setAttribute( k, '' );
			} else {
				el.setAttribute( k, v );
			}
		} );
		for ( var i = 2; i < arguments.length; i++ ) {
			append( el, arguments[ i ] );
		}
		return el;
	}
	function append( el, c ) {
		if ( c === null || c === undefined || c === false ) {
			return;
		}
		if ( Array.isArray( c ) ) {
			c.forEach( function ( x ) {
				append( el, x );
			} );
			return;
		}
		el.appendChild( typeof c === 'string' || typeof c === 'number' ? document.createTextNode( String( c ) ) : c );
	}
	function icon( name ) {
		return h( 'span', { class: 'dashicons dashicons-' + name, 'aria-hidden': 'true' } );
	}
	function clone( o ) {
		return o === undefined ? undefined : JSON.parse( JSON.stringify( o ) );
	}
	function get( obj, path ) {
		var parts = Array.isArray( path ) ? path : path.split( '.' );
		var cur = obj;
		for ( var i = 0; i < parts.length; i++ ) {
			if ( cur === null || typeof cur !== 'object' || ! ( parts[ i ] in cur ) ) {
				return undefined;
			}
			cur = cur[ parts[ i ] ];
		}
		return cur;
	}
	function set( obj, path, value ) {
		var parts = Array.isArray( path ) ? path : path.split( '.' );
		var cur = obj;
		var trail = [];
		for ( var i = 0; i < parts.length - 1; i++ ) {
			if ( ! cur[ parts[ i ] ] || typeof cur[ parts[ i ] ] !== 'object' ) {
				cur[ parts[ i ] ] = {};
			}
			trail.push( [ cur, parts[ i ] ] );
			cur = cur[ parts[ i ] ];
		}
		var last = parts[ parts.length - 1 ];
		if ( value === '' || value === undefined || value === null ) {
			delete cur[ last ];
			// Prune empty parents so the saved config stays clean.
			for ( var j = trail.length - 1; j >= 0; j-- ) {
				var parent = trail[ j ][ 0 ];
				var key = trail[ j ][ 1 ];
				if ( parent[ key ] && typeof parent[ key ] === 'object' && ! Array.isArray( parent[ key ] ) && ! Object.keys( parent[ key ] ).length ) {
					delete parent[ key ];
				} else {
					break;
				}
			}
		} else {
			cur[ last ] = value;
		}
	}
	function debounce( fn, ms ) {
		var t;
		return function () {
			var args = arguments;
			clearTimeout( t );
			t = setTimeout( function () {
				fn.apply( null, args );
			}, ms );
		};
	}
	function toast( msg, kind ) {
		var t = h( 'div', { class: 'gs-st-toast' + ( kind ? ' is-' + kind : '' ), role: 'status' }, msg );
		document.body.appendChild( t );
		requestAnimationFrame( function () {
			t.classList.add( 'is-in' );
		} );
		setTimeout( function () {
			t.classList.remove( 'is-in' );
			setTimeout( function () {
				t.remove();
			}, 300 );
		}, 3200 );
	}
	function confirmBox( title, body, buttons ) {
		return new Promise( function ( resolve ) {
			var overlay = h( 'div', { class: 'gs-st-modal-wrap', role: 'dialog', 'aria-modal': 'true', 'aria-label': title } );
			var close = function ( v ) {
				overlay.remove();
				resolve( v );
			};
			var row = h( 'div', { class: 'gs-st-modal-actions' } );
			buttons.forEach( function ( b ) {
				row.appendChild( h( 'button', { type: 'button', class: 'gs-st-btn' + ( b.primary ? ' is-primary' : '' ), onclick: function () {
					close( b.value );
				} }, b.label ) );
			} );
			overlay.appendChild( h( 'div', { class: 'gs-st-modal' }, h( 'h2', null, title ), h( 'p', null, body ), row ) );
			overlay.addEventListener( 'click', function ( e ) {
				if ( e.target === overlay ) {
					close( null );
				}
			} );
			root.appendChild( overlay );
			var first = row.querySelector( '.is-primary' ) || row.querySelector( 'button' );
			if ( first ) {
				first.focus();
			}
		} );
	}
	function errMsg( e ) {
		return ( e && e.message ) || __( 'Something went wrong. Please try again.', 'gend-society' );
	}
	// Template ids are "theme//slug". Keep the slashes literal: servers often reject %2F in paths.
	function idPath( id ) {
		return String( id ).split( '/' ).map( encodeURIComponent ).join( '/' );
	}
	function titleOf( t ) {
		if ( ! t ) {
			return '';
		}
		return ( t.title && ( t.title.rendered || t.title.raw ) ) || t.slug;
	}

	/* ── Registry lookups ──────────────────────────────────────────────── */
	var activeSections = cfg.sections.filter( function ( s ) {
		return s.active;
	} );
	var lockedSections = cfg.sections.filter( function ( s ) {
		return ! s.active;
	} );
	function allItems( section ) {
		var out = [];
		section.groups.forEach( function ( g ) {
			g.items.forEach( function ( it ) {
				out.push( it );
			} );
		} );
		return out;
	}
	function findItem( sectionId, itemId ) {
		var section = activeSections.filter( function ( s ) {
			return s.id === sectionId;
		} )[ 0 ];
		if ( ! section ) {
			return null;
		}
		var item = allItems( section ).filter( function ( it ) {
			return it.id === itemId;
		} )[ 0 ];
		return item ? { section: section, item: item } : null;
	}
	function templateFor( item ) {
		if ( item.kind === 'template' ) {
			return S.templates[ item.slug ] || null;
		}
		if ( item.kind === 'part' ) {
			return S.parts[ item.slug ] || null;
		}
		return null;
	}
	function statusOf( t ) {
		if ( ! t ) {
			return { key: 'missing', label: __( 'Not created', 'gend-society' ) };
		}
		if ( t.source === 'custom' ) {
			return { key: 'custom', label: __( 'Customized', 'gend-society' ) };
		}
		if ( t.origin === 'plugin' || t.plugin ) {
			return { key: 'plugin', label: __( 'Plugin default', 'gend-society' ) };
		}
		return { key: 'theme', label: __( 'Theme default', 'gend-society' ) };
	}

	/* ── Data loading ──────────────────────────────────────────────────── */
	var FIELDS = 'id,slug,title,description,source,origin,has_theme_file,is_custom,modified,plugin,theme,type,area';
	function loadTemplates() {
		return Promise.all( [
			api( { path: '/wp/v2/templates?context=edit&per_page=100&_fields=' + FIELDS } ),
			api( { path: '/wp/v2/template-parts?context=edit&per_page=100&_fields=' + FIELDS } ),
		] ).then( function ( r ) {
			S.templates = {};
			S.parts = {};
			r[ 0 ].forEach( function ( t ) {
				S.templates[ t.slug ] = t;
			} );
			r[ 1 ].forEach( function ( t ) {
				S.parts[ t.slug ] = t;
			} );
			S.templatesLoaded = true;
		} );
	}
	function loadSettings() {
		return api( { path: '/wp/v2/settings' } ).then( function ( s ) {
			S.settings = s;
			var ids = [ s.site_logo, s.site_icon ].filter( Boolean );
			return Promise.all( ids.map( loadMedia ) );
		} );
	}
	function loadMedia( id ) {
		if ( ! id || S.media[ id ] ) {
			return Promise.resolve( S.media[ id ] );
		}
		return api( { path: '/wp/v2/media/' + id + '?_fields=id,source_url,media_details,alt_text' } ).then(
			function ( m ) {
				S.media[ id ] = m;
				return m;
			},
			function () {
				return null;
			}
		);
	}
	function loadStyles( force ) {
		if ( ! cfg.globalStylesId ) {
			return Promise.resolve();
		}
		if ( S.gs && ! force ) {
			return Promise.resolve();
		}
		var sheet = encodeURIComponent( cfg.stylesheet );
		return Promise.all( [
			api( { path: '/wp/v2/global-styles/' + cfg.globalStylesId + '?context=edit' } ),
			S.base ? Promise.resolve( S.base ) : api( { path: '/wp/v2/global-styles/themes/' + sheet + '?context=edit' } ),
			S.variations.length ? Promise.resolve( S.variations ) : api( { path: '/wp/v2/global-styles/themes/' + sheet + '/variations?context=edit' } ).catch( function () {
				return [];
			} ),
		] ).then( function ( r ) {
			if ( force && isDirty() ) {
				return; // Never replace a draft the user started while this was loading.
			}
			S.gs = { settings: r[ 0 ].settings || {}, styles: r[ 0 ].styles || {} };
			S.gsSaved = JSON.stringify( S.gs );
			S.base = { settings: r[ 1 ].settings || {}, styles: r[ 1 ].styles || {} };
			S.variations = Array.isArray( r[ 2 ] ) ? r[ 2 ] : [];
			S.previewCss = '';
		} );
	}
	function isDirty() {
		return !! S.gs && JSON.stringify( S.gs ) !== S.gsSaved;
	}

	/* ── Global styles accessors ───────────────────────────────────────── */
	function originList( v ) {
		if ( Array.isArray( v ) ) {
			return v;
		}
		if ( v && typeof v === 'object' ) {
			return v.custom || v.theme || v.default || [];
		}
		return [];
	}
	function basePalette() {
		return originList( get( S.base, 'settings.color.palette' ) );
	}
	function palette() {
		var user = get( S.gs, 'settings.color.palette.theme' );
		return Array.isArray( user ) ? user : basePalette();
	}
	function fontFamilies() {
		var out = [];
		var seen = {};
		var add = function ( list ) {
			originList( list ).forEach( function ( f ) {
				if ( f && f.slug && ! seen[ f.slug ] ) {
					seen[ f.slug ] = 1;
					out.push( f );
				}
			} );
		};
		add( get( S.base, 'settings.typography.fontFamilies' ) );
		var user = get( S.gs, 'settings.typography.fontFamilies' ) || {};
		add( user.theme );
		add( user.custom );
		return out;
	}
	function fontSizes() {
		return originList( get( S.base, 'settings.typography.fontSizes' ) );
	}
	function eff( path ) {
		var u = get( S.gs, path );
		return u !== undefined ? u : get( S.base, path );
	}
	function presetSlug( v, kind ) {
		if ( typeof v !== 'string' ) {
			return '';
		}
		var m = v.match( new RegExp( '^var:preset\\|' + kind + '\\|(.+)$' ) ) || v.match( new RegExp( '^var\\(--wp--preset--' + kind + '--([a-z0-9-]+)\\)$' ) );
		return m ? m[ 1 ] : '';
	}
	function resolveColor( v ) {
		var slug = presetSlug( v, 'color' );
		if ( slug ) {
			var c = palette().filter( function ( p ) {
				return p.slug === slug;
			} )[ 0 ];
			return c ? c.color : '';
		}
		return v || '';
	}
	function familyName( v ) {
		var slug = presetSlug( v, 'font-family' );
		var f = fontFamilies().filter( function ( x ) {
			return x.slug === slug;
		} )[ 0 ];
		return f ? f.name : v || '';
	}
	function changed() {
		renderSaveBar();
		schedulePreview();
	}

	/* ── Live preview ──────────────────────────────────────────────────── */
	var previewFrame = null;
	function injectPreview() {
		if ( ! previewFrame || ! S.previewCss ) {
			return;
		}
		var css = fontFaceCss() + S.previewCss;
		try {
			var doc = previewFrame.contentDocument;
			if ( ! doc || ! doc.head ) {
				return;
			}
			var el = doc.getElementById( 'gs-studio-live' );
			if ( ! el ) {
				el = doc.createElement( 'style' );
				el.id = 'gs-studio-live';
				doc.head.appendChild( el );
			}
			el.textContent = css;
		} catch ( e ) {
			// Cross-origin preview (unusual front-end domain): live CSS is skipped, saving still updates it.
		}
	}
	var schedulePreview = debounce( function () {
		if ( ! S.gs || ! isDirty() ) {
			S.previewCss = '';
			var el = previewFrame && previewFrame.contentDocument && previewFrame.contentDocument.getElementById( 'gs-studio-live' );
			if ( el ) {
				el.textContent = '';
			}
			return;
		}
		api( { path: cfg.restPreview, method: 'POST', data: S.gs } ).then( function ( r ) {
			S.previewCss = r.css || '';
			injectPreview();
		}, function () {} );
	}, 300 );

	// The style guide section that matches what is being edited.
	function guideAnchor() {
		var byItem = { logo: 'gsg-logo', favicon: 'gsg-icons', 'site-name': 'gsg-cover', palettes: 'gsg-colour', voice: 'gsg-voice', 'app-styling': 'gsg-style', cast: 'gsg-cast' };
		if ( current.item === 'fonts' ) {
			var byEl = { heading: 'gsg-faces', p: 'gsg-type-p', a: 'gsg-comp-links', button: 'gsg-comp-buttons' };
			return byEl[ S.typeEl ] || 'gsg-type-' + ( S.typeEl || 'h1' );
		}
		return byItem[ current.item ] || '';
	}
	function guideScroll( smooth ) {
		if ( ! previewFrame || S.previewMode === 'home' ) {
			return;
		}
		try {
			var doc = previewFrame.contentDocument;
			var id = guideAnchor();
			// Fall back to the chapter when a section is missing (no app icon yet, for example).
			var el = id && ( doc.getElementById( id ) || ( id === 'gsg-icons' && doc.getElementById( 'gsg-logo' ) ) );
			if ( el ) {
				var center = id.indexOf( 'gsg-type-' ) === 0 || id.indexOf( 'gsg-comp-' ) === 0;
				el.scrollIntoView( { behavior: smooth ? 'smooth' : 'instant', block: center ? 'center' : 'start' } );
			}
		} catch ( e ) {
			// Cross-origin preview: leave the scroll position alone.
		}
	}

	function previewPane( opts ) {
		opts = opts || {};
		var url = opts.url || ( S.previewMode === 'home' ? cfg.homeUrl : cfg.specimenUrl );
		previewFrame = h( 'iframe', { class: 'gs-st-preview-frame', title: __( 'Live preview', 'gend-society' ), src: url, loading: 'lazy' } );
		previewFrame.addEventListener( 'load', function () {
			injectPreview();
			guideScroll( false );
			// Fonts and the guide's own adjustments can still move things: anchor again once settled.
			try {
				previewFrame.contentDocument.fonts.ready.then( function () {
					guideScroll( false );
				} );
			} catch ( e ) {}
			setTimeout( function () {
				guideScroll( false );
			}, 700 );
		} );
		var seg = function ( mode, label ) {
			return h( 'button', { type: 'button', class: 'gs-st-seg' + ( S.previewMode === mode ? ' is-on' : '' ), 'aria-pressed': S.previewMode === mode ? 'true' : 'false', onclick: function () {
				S.previewMode = mode;
				render();
			} }, label );
		};
		var dev = function ( d, ic, label ) {
			return h( 'button', { type: 'button', class: 'gs-st-icon-btn' + ( S.device === d ? ' is-on' : '' ), title: label, 'aria-label': label, onclick: function () {
				S.device = d;
				frameWrap.className = 'gs-st-preview-device is-' + d;
				Array.prototype.forEach.call( bar.querySelectorAll( '.gs-st-dev .gs-st-icon-btn' ), function ( b ) {
					b.classList.toggle( 'is-on', b === this );
				}, this );
			} }, icon( ic ) );
		};
		var frameWrap = h( 'div', { class: 'gs-st-preview-device is-' + S.device }, previewFrame );
		var bar = h(
			'div',
			{ class: 'gs-st-preview-bar' },
			opts.url ? h( 'strong', { class: 'gs-st-preview-title' }, opts.title || '' ) : h( 'div', { class: 'gs-st-segs' }, seg( 'specimen', __( 'Style guide', 'gend-society' ) ), seg( 'home', __( 'Home page', 'gend-society' ) ) ),
			h( 'span', { class: 'gs-st-live-dot' }, isDirty() ? __( 'Unsaved preview', 'gend-society' ) : __( 'Live', 'gend-society' ) ),
			h( 'div', { class: 'gs-st-dev' }, dev( 'desktop', 'desktop', __( 'Desktop', 'gend-society' ) ), dev( 'tablet', 'tablet', __( 'Tablet', 'gend-society' ) ), dev( 'mobile', 'smartphone', __( 'Mobile', 'gend-society' ) ) )
		);
		if ( opts.note ) {
			bar.appendChild( h( 'span', { class: 'gs-st-preview-note' }, opts.note ) );
		}
		return h( 'section', { class: 'gs-st-preview', 'aria-label': __( 'Preview', 'gend-society' ) }, bar, frameWrap );
	}
	function reloadPreview() {
		if ( previewFrame ) {
			previewFrame.src = previewFrame.src;
		}
	}

	/* ── Save bar ──────────────────────────────────────────────────────── */
	var saveSlot = h( 'div', { class: 'gs-st-save-slot' } );
	function renderSaveBar() {
		saveSlot.textContent = '';
		if ( ! isDirty() ) {
			return;
		}
		saveSlot.appendChild( h( 'span', { class: 'gs-st-unsaved' }, icon( 'marker' ), __( 'Unsaved style changes', 'gend-society' ) ) );
		saveSlot.appendChild( h( 'button', { type: 'button', class: 'gs-st-btn is-ghost', onclick: discardStyles }, __( 'Discard', 'gend-society' ) ) );
		saveSlot.appendChild( h( 'button', { type: 'button', class: 'gs-st-btn is-primary', onclick: saveStyles }, __( 'Save styles', 'gend-society' ) ) );
	}
	function saveStyles() {
		saveSlot.classList.add( 'is-busy' );
		return api( { path: '/wp/v2/global-styles/' + cfg.globalStylesId, method: 'POST', data: { settings: S.gs.settings, styles: S.gs.styles } } ).then(
			function () {
				S.gsSaved = JSON.stringify( S.gs );
				S.previewCss = '';
				saveSlot.classList.remove( 'is-busy' );
				renderSaveBar();
				reloadPreview();
				toast( __( 'Styles saved. Your site is updated.', 'gend-society' ), 'ok' );
				return true;
			},
			function ( e ) {
				saveSlot.classList.remove( 'is-busy' );
				toast( errMsg( e ), 'error' );
				return false;
			}
		);
	}
	function discardStyles() {
		S.gs = JSON.parse( S.gsSaved );
		S.previewCss = '';
		renderSaveBar();
		render();
	}
	window.addEventListener( 'beforeunload', function ( e ) {
		if ( isDirty() ) {
			e.preventDefault();
			e.returnValue = '';
		}
	} );

	/* ── Shell ─────────────────────────────────────────────────────────── */
	var nav, main, current = { section: '', item: '' };
	var collapsed = {};
	try {
		collapsed = JSON.parse( window.localStorage.getItem( 'gsStudioCollapsed' ) || '{}' ) || {};
	} catch ( e ) {
		collapsed = {};
	}

	function shell() {
		root.textContent = '';
		root.removeAttribute( 'aria-busy' );
		nav = h( 'nav', { class: 'gs-st-nav', 'aria-label': __( 'Design areas', 'gend-society' ) } );
		main = h( 'main', { class: 'gs-st-main', tabindex: '-1' } );
		// The rail sits on the right; the save bar floats over the bottom of the canvas.
		root.appendChild( h( 'div', { class: 'gs-st-app' }, h( 'div', { class: 'gs-st-body' }, main, nav ), saveSlot ) );
		// Clicking the item that is already open reopens it (e.g. back to a list from an editor opened inside it).
		nav.addEventListener( 'click', function ( e ) {
			var a = e.target.closest( 'a[href^="#"]' );
			if ( a && a.getAttribute( 'href' ) === location.hash ) {
				e.preventDefault();
				onRoute();
			}
		} );
	}

	function renderNav() {
		nav.textContent = '';
		nav.appendChild( h( 'a', { class: 'gs-st-nav-home' + ( ! current.section ? ' is-active' : '' ), href: '#/', title: __( 'Overview', 'gend-society' ) }, h( 'span', { class: 'gs-st-nav-ico' }, icon( 'dashboard' ) ), h( 'span', { class: 'gs-st-nav-home-label' }, __( 'Overview', 'gend-society' ) ) ) );
		activeSections.forEach( function ( section ) {
			var items = allItems( section );
			var counted = items.filter( function ( it ) {
				return it.kind === 'template' || it.kind === 'part';
			} );
			var custom = counted.filter( function ( it ) {
				return statusOf( templateFor( it ) ).key === 'custom';
			} ).length;
			var open = ! collapsed[ section.id ];
			var head = h(
				'button',
				{ type: 'button', title: section.label, class: 'gs-st-nav-section' + ( current.section === section.id ? ' is-current' : '' ), 'aria-expanded': open ? 'true' : 'false', onclick: function () {
					collapsed[ section.id ] = open;
					try {
						window.localStorage.setItem( 'gsStudioCollapsed', JSON.stringify( collapsed ) );
					} catch ( e ) {}
					renderNav();
				} },
				h( 'span', { class: 'gs-st-nav-ico' }, icon( section.icon ) ),
				h( 'span', { class: 'gs-st-nav-label' }, section.label ),
				counted.length && S.templatesLoaded ? h( 'span', { class: 'gs-st-nav-count', title: __( 'Customized templates', 'gend-society' ) }, custom + '/' + counted.length ) : null,
				icon( open ? 'arrow-up-alt2' : 'arrow-down-alt2' )
			);
			var list = h( 'div', { class: 'gs-st-nav-items' } );
			section.groups.forEach( function ( g ) {
				if ( g.label ) {
					list.appendChild( h( 'div', { class: 'gs-st-nav-group' }, g.label ) );
				}
				g.items.forEach( function ( it ) {
					var st = ( it.kind === 'template' || it.kind === 'part' ) && S.templatesLoaded ? statusOf( templateFor( it ) ) : null;
					list.appendChild( h(
						'a',
						{ class: 'gs-st-nav-item' + ( current.section === section.id && current.item === it.id ? ' is-active' : '' ), href: '#/' + section.id + '/' + it.id },
						h( 'span', null, it.label ),
						st && st.key !== 'theme' && st.key !== 'plugin' ? h( 'span', { class: 'gs-st-dot is-' + st.key, title: st.label } ) : null
					) );
				} );
			} );
			nav.appendChild( h( 'div', { class: 'gs-st-nav-block' }, head, open ? list : null ) );
		} );
		if ( lockedSections.length ) {
			var locked = h( 'div', { class: 'gs-st-nav-locked' }, h( 'div', { class: 'gs-st-nav-group' }, __( 'Unlock with apps', 'gend-society' ) ) );
			lockedSections.forEach( function ( s ) {
				locked.appendChild( h( 'a', { class: 'gs-st-nav-lock', href: '#/', title: sprintf( __( 'Turns on with %s', 'gend-society' ), s.requires || '' ) }, icon( s.icon ), h( 'span', null, s.label ), icon( 'lock' ) ) );
			} );
			nav.appendChild( locked );
		}
	}

	/* ── Router ────────────────────────────────────────────────────────── */
	function parseHash() {
		var p = ( location.hash || '' ).replace( /^#\/?/, '' ).split( '/' );
		return { section: p[ 0 ] || '', item: p[ 1 ] || '' };
	}
	var lastView = '';
	function onRoute() {
		var raw = ( location.hash || '' ).replace( /^#\/?/, '' ).split( '/' );
		if ( raw[ 0 ] === 'edit' && raw[ 1 ] && raw[ 2 ] ) {
			current = { section: 'edit', item: '' };
			lastView = 'editor';
			S.stylesStale = true;
			renderNav();
			main.textContent = '';
			main.appendChild( viewEditRoute( raw[ 1 ], decodeURIComponent( raw.slice( 2 ).join( '/' ) ) ) );
			return;
		}
		var next = parseHash();
		var hit = next.section ? findItem( next.section, next.item ) : null;
		if ( next.section && ! next.item ) {
			var sec = activeSections.filter( function ( s ) {
				return s.id === next.section;
			} )[ 0 ];
			if ( sec ) {
				location.replace( '#/' + sec.id + '/' + allItems( sec )[ 0 ].id );
				return;
			}
		}
		var goingToEditor = hit && hit.item.kind !== 'panel';
		if ( goingToEditor && isDirty() && lastView === 'panel' ) {
			confirmBox( __( 'Save your style changes?', 'gend-society' ), __( 'You have unsaved colour or type changes. Save them before you open the editor, so the two do not overwrite each other.', 'gend-society' ), [
				{ label: __( 'Keep editing styles', 'gend-society' ), value: 'cancel' },
				{ label: __( 'Discard', 'gend-society' ), value: 'discard' },
				{ label: __( 'Save and continue', 'gend-society' ), value: 'save', primary: true },
			] ).then( function ( v ) {
				if ( v === 'save' ) {
					saveStyles().then( function ( ok ) {
						if ( ok ) {
							go( next, hit );
						}
					} );
				} else if ( v === 'discard' ) {
					S.gs = JSON.parse( S.gsSaved );
					renderSaveBar();
					go( next, hit );
				} else {
					history.replaceState( null, '', '#/' + current.section + '/' + current.item );
				}
			} );
			return;
		}
		go( next, hit );
	}
	function go( next, hit ) {
		current = hit ? next : { section: '', item: '' };
		renderNav();
		render();
		if ( main ) {
			main.scrollTop = 0;
		}
	}

	function render() {
		main.textContent = '';
		previewFrame = null;
		var hit = current.section ? findItem( current.section, current.item ) : null;
		if ( ! hit ) {
			lastView = 'overview';
			main.appendChild( viewOverview() );
			return;
		}
		var it = hit.item;
		lastView = it.kind === 'panel' ? 'panel' : 'editor';
		if ( lastView === 'editor' ) {
			S.stylesStale = true;
		}
		var view;
		if ( it.kind === 'panel' ) {
			view = viewPanel( hit );
		} else if ( it.kind === 'template' || it.kind === 'part' ) {
			view = viewTemplate( hit );
		} else if ( it.kind === 'templates' ) {
			view = viewTemplateList( hit );
		} else if ( it.kind === 'producttemplates' ) {
			view = viewProductTemplates( hit );
		} else if ( it.kind === 'taxtemplates' ) {
			view = viewTaxTemplates( hit );
		} else if ( it.kind === 'menus' ) {
			view = viewMenus( hit );
		} else {
			view = viewAdmin( hit );
		}
		main.appendChild( view );
	}

	function crumbs( hit, extra ) {
		return h( 'div', { class: 'gs-st-crumbs' }, h( 'a', { href: '#/' }, __( 'Studio', 'gend-society' ) ), h( 'span', { 'aria-hidden': 'true' }, '›' ), h( 'span', null, hit.section.label ), h( 'span', { 'aria-hidden': 'true' }, '›' ), h( 'strong', null, hit.item.label ), extra || null );
	}

	/* ── Overview ──────────────────────────────────────────────────────── */
	function checklist() {
		var hasPart = function ( slug ) {
			return S.parts[ slug ] && S.parts[ slug ].source === 'custom';
		};
		var palCustom = !! get( S.gs, 'settings.color.palette.theme' ) || !! get( S.gs, 'styles.color' );
		var fontsCustom = !! get( S.gs, 'styles.typography.fontFamily' ) || !! get( S.gs, 'styles.elements.heading.typography.fontFamily' );
		return [
			{ done: !! ( S.settings && S.settings.site_logo ), label: __( 'Add your logo', 'gend-society' ), href: '#/branding/logo' },
			{ done: !! ( S.settings && S.settings.site_icon ), label: __( 'Set a favicon', 'gend-society' ), href: '#/branding/favicon' },
			{ done: palCustom, label: __( 'Choose your colours', 'gend-society' ), href: '#/branding/palettes' },
			{ done: fontsCustom, label: __( 'Pick your fonts', 'gend-society' ), href: '#/branding/fonts' },
			{ done: hasPart( 'header' ), label: __( 'Design your header', 'gend-society' ), href: '#/app/header' },
			{ done: hasPart( 'footer' ), label: __( 'Design your footer', 'gend-society' ), href: '#/app/footer' },
		];
	}
	function ring( pct ) {
		var r = 34;
		var c = 2 * Math.PI * r;
		var svg = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
		svg.setAttribute( 'viewBox', '0 0 80 80' );
		svg.setAttribute( 'class', 'gs-st-ring' );
		svg.setAttribute( 'aria-hidden', 'true' );
		svg.innerHTML = '<circle cx="40" cy="40" r="' + r + '" class="gs-st-ring-bg"/><circle cx="40" cy="40" r="' + r + '" class="gs-st-ring-fg" stroke-dasharray="' + c + '" stroke-dashoffset="' + ( c * ( 1 - pct / 100 ) ) + '"/>';
		return h( 'div', { class: 'gs-st-ring-wrap' }, svg, h( 'span', { class: 'gs-st-ring-num' }, Math.round( pct ) + '%' ) );
	}
	function viewOverview() {
		var list = checklist();
		var done = list.filter( function ( x ) {
			return x.done;
		} ).length;
		var pct = ( done / list.length ) * 100;
		var logo = S.settings && S.settings.site_logo && S.media[ S.settings.site_logo ];

		var heroPreview = h( 'div', { class: 'gs-st-hero-preview' }, h( 'iframe', { src: cfg.homeUrl, title: __( 'Your home page', 'gend-society' ), tabindex: '-1', loading: 'lazy' } ), h( 'a', { class: 'gs-st-hero-open', href: cfg.homeUrl, target: '_blank', rel: 'noopener' }, icon( 'external' ), __( 'Open site', 'gend-society' ) ) );
		var hero = h(
			'section',
			{ class: 'gs-st-hero' },
			h(
				'div',
				{ class: 'gs-st-hero-copy' },
				h( 'div', { class: 'gs-st-hero-site' }, logo ? h( 'img', { src: logo.source_url, alt: '' } ) : h( 'span', { class: 'gs-st-hero-mono' }, ( cfg.siteTitle || 'G' ).charAt( 0 ) ), h( 'div', null, h( 'small', null, __( 'Designing', 'gend-society' ) ), h( 'strong', null, ( S.settings && S.settings.title ) || cfg.siteTitle ) ) ),
				h( 'h1', null, __( 'Make it unmistakably yours.', 'gend-society' ) ),
				h( 'p', null, __( 'Set your brand once and it flows through every page, store screen, profile and dashboard. Each app you turn on adds its own design areas here.', 'gend-society' ) ),
				h( 'div', { class: 'gs-st-hero-ctas' }, h( 'a', { class: 'gs-st-btn is-primary', href: '#/branding/palettes' }, icon( 'admin-appearance' ), __( 'Choose colours', 'gend-society' ) ), h( 'a', { class: 'gs-st-btn', href: '#/app/header' }, icon( 'editor-kitchensink' ), __( 'Edit header', 'gend-society' ) ) ),
				palette().length ? h( 'div', { class: 'gs-st-hero-swatches', title: __( 'Your colours', 'gend-society' ) }, palette().slice( 0, 8 ).map( function ( p ) {
					return h( 'span', { style: { background: p.color }, title: p.name } );
				} ) ) : null
			),
			heroPreview
		);

		var checks = h( 'section', { class: 'gs-st-card gs-st-checklist' }, h( 'div', { class: 'gs-st-card-head' }, ring( pct ), h( 'div', null, h( 'h2', null, __( 'Brand checklist', 'gend-society' ) ), h( 'p', null, done === list.length ? __( 'Everything is set. Nice work!', 'gend-society' ) : sprintf( __( '%1$d of %2$d done', 'gend-society' ), done, list.length ) ) ) ), h( 'ul', null, list.map( function ( x ) {
			return h( 'li', { class: x.done ? 'is-done' : '' }, h( 'a', { href: x.href }, icon( x.done ? 'yes-alt' : 'marker' ), h( 'span', null, x.label ), icon( 'arrow-right-alt2' ) ) );
		} ) ) );

		var recent = Object.keys( S.templates ).map( function ( k ) {
			return S.templates[ k ];
		} ).concat( Object.keys( S.parts ).map( function ( k ) {
			return S.parts[ k ];
		} ) ).filter( function ( t ) {
			return t.source === 'custom' && t.modified;
		} ).sort( function ( a, b ) {
			return a.modified < b.modified ? 1 : -1;
		} ).slice( 0, 5 );
		var recentCard = h( 'section', { class: 'gs-st-card gs-st-recent' }, h( 'h2', null, __( 'Recently edited', 'gend-society' ) ), recent.length ? h( 'ul', null, recent.map( function ( t ) {
			var link = linkForTemplate( t );
			return h( 'li', null, h( link ? 'a' : 'span', link ? { href: link } : null, icon( t.type === 'wp_template_part' ? 'block-default' : 'layout' ), h( 'span', null, titleOf( t ) ), h( 'time', null, new Date( t.modified ).toLocaleDateString() ) ) );
		} ) ) : h( 'p', { class: 'gs-st-muted' }, __( 'Nothing yet. Pick an area on the left to start.', 'gend-society' ) ) );

		var areas = h( 'section', { class: 'gs-st-areas' }, h( 'h2', { class: 'gs-st-h2' }, __( 'Design areas', 'gend-society' ) ), h( 'div', { class: 'gs-st-area-grid' }, activeSections.map( function ( s, i ) {
			var items = allItems( s );
			return h( 'a', { class: 'gs-st-area', href: '#/' + s.id, style: { '--i': i } }, h( 'span', { class: 'gs-st-area-ico' }, icon( s.icon ) ), h( 'strong', null, s.label ), h( 'p', null, s.blurb || '' ), h( 'span', { class: 'gs-st-area-meta' }, sprintf( __( '%d designs', 'gend-society' ), items.length ), icon( 'arrow-right-alt' ) ) );
		} ).concat( lockedSections.map( function ( s ) {
			return h( 'a', { class: 'gs-st-area is-locked', href: cfg.pluginsUrl }, h( 'span', { class: 'gs-st-area-ico' }, icon( s.icon ) ), h( 'strong', null, s.label ), h( 'p', null, s.blurb || '' ), h( 'span', { class: 'gs-st-area-meta' }, icon( 'lock' ), sprintf( __( 'Turns on with %s', 'gend-society' ), s.requires || '' ) ) );
		} ) ) ) );

		return h( 'div', { class: 'gs-st-overview' }, hero, h( 'div', { class: 'gs-st-overview-row' }, checks, recentCard ), areas );
	}
	function linkForTemplate( t ) {
		var out = '';
		activeSections.some( function ( s ) {
			return allItems( s ).some( function ( it ) {
				if ( ( it.kind === 'template' && t.type === 'wp_template' && it.slug === t.slug ) || ( it.kind === 'part' && t.type === 'wp_template_part' && it.slug === t.slug ) ) {
					out = '#/' + s.id + '/' + it.id;
					return true;
				}
				return false;
			} );
		} );
		return out;
	}

	/* ── Branding / typography panels ──────────────────────────────────── */
	function panelLayout( hit, intro, controls, opts ) {
		return h( 'div', { class: 'gs-st-split' }, h( 'div', { class: 'gs-st-controls' }, crumbs( hit ), h( 'h1', { class: 'gs-st-title' }, hit.item.label ), intro ? h( 'p', { class: 'gs-st-intro' }, intro ) : null, controls ), previewPane( opts ) );
	}
	function loadingView() {
		return h( 'div', { class: 'gs-st-loading' }, h( 'span', { class: 'gs-st-spinner' } ), __( 'Loading…', 'gend-society' ) );
	}
	function viewPanel( hit ) {
		var p = hit.item.panel;
		// Panels from their own scripts (e.g. assets/social-pages.js).
		if ( window.gsStudioPanels && window.gsStudioPanels[ p ] ) {
			return window.gsStudioPanels[ p ]( hit, { h: h, icon: icon, api: api, toast: toast, confirmBox: confirmBox, promptBox: promptBox, crumbs: crumbs, errMsg: errMsg, loadingView: loadingView, debounce: debounce, panelLayout: panelLayout, reloadPreview: reloadPreview, pickImage: pickImage } );
		}
		var needsStyles = p === 'palettes' || p === 'fonts';
		if ( needsStyles && ! cfg.globalStylesId ) {
			return emptyState( 'warning', __( 'Styles are not available', 'gend-society' ), __( 'Your active theme does not support global styles, so colours and type are set by the theme.', 'gend-society' ) );
		}
		if ( ( needsStyles && ! S.gs ) || ( ! needsStyles && ! S.settings ) ) {
			var wait = loadingView();
			( needsStyles ? loadStyles() : loadSettings() ).then( render, function ( e ) {
				wait.textContent = errMsg( e );
			} );
			return wait;
		}
		if ( needsStyles && S.stylesStale && ! isDirty() ) {
			// Pick up style changes saved in the block editor since the studio loaded them.
			S.stylesStale = false;
			var waitFresh = loadingView();
			loadStyles( true ).then( render, render );
			return waitFresh;
		}
		if ( p === 'logo' ) {
			return panelLogo( hit );
		}
		if ( p === 'favicon' ) {
			return panelFavicon( hit );
		}
		if ( p === 'site-name' ) {
			return panelSiteName( hit );
		}
		if ( p === 'palettes' ) {
			return panelPalettes( hit );
		}
		if ( p === 'youzify' ) {
			return panelYouzify( hit );
		}
		if ( p === 'shortcode' ) {
			return panelShortcode( hit );
		}
		if ( p === 'sidebars' ) {
			return panelSidebars( hit );
		}
		return panelTypography( hit );
	}

	function pickImage( title, onPick ) {
		if ( ! window.wp || ! wp.media ) {
			toast( __( 'The media library could not load.', 'gend-society' ), 'error' );
			return;
		}
		var frame = wp.media( { title: title, library: { type: 'image' }, multiple: false, button: { text: __( 'Use this image', 'gend-society' ) } } );
		frame.on( 'select', function () {
			var a = frame.state().get( 'selection' ).first().toJSON();
			S.media[ a.id ] = { id: a.id, source_url: a.url, media_details: { width: a.width, height: a.height }, alt_text: a.alt };
			onPick( a );
		} );
		frame.open();
	}
	function saveSetting( data, msg ) {
		return api( { path: '/wp/v2/settings', method: 'POST', data: data } ).then( function ( s ) {
			S.settings = s;
			toast( msg, 'ok' );
			render();
			renderNav();
		}, function ( e ) {
			toast( errMsg( e ), 'error' );
		} );
	}
	function panelLogo( hit ) {
		var id = S.settings.site_logo;
		var m = id && S.media[ id ];
		var stage = function ( cls ) {
			return h( 'div', { class: 'gs-st-logo-stage ' + cls }, m ? h( 'img', { src: m.source_url, alt: m.alt_text || '' } ) : h( 'span', { class: 'gs-st-logo-empty' }, icon( 'format-image' ), __( 'No logo yet', 'gend-society' ) ) );
		};
		var controls = h(
			'div',
			{ class: 'gs-st-stack' },
			h( 'div', { class: 'gs-st-logo-stages' }, stage( 'is-light' ), stage( 'is-dark' ) ),
			h( 'p', { class: 'gs-st-hint' }, __( 'Check that your logo reads well on light and dark backgrounds. A transparent PNG or SVG at least 400px wide works best.', 'gend-society' ) ),
			h( 'div', { class: 'gs-st-row' },
				h( 'button', { type: 'button', class: 'gs-st-btn is-primary', onclick: function () {
					pickImage( __( 'Choose your logo', 'gend-society' ), function ( a ) {
						saveSetting( { site_logo: a.id }, __( 'Logo updated.', 'gend-society' ) ).then( reloadPreview );
					} );
				} }, icon( 'upload' ), m ? __( 'Replace logo', 'gend-society' ) : __( 'Upload logo', 'gend-society' ) ),
				m ? h( 'button', { type: 'button', class: 'gs-st-btn is-ghost', onclick: function () {
					saveSetting( { site_logo: 0 }, __( 'Logo removed.', 'gend-society' ) ).then( reloadPreview );
				} }, __( 'Remove', 'gend-society' ) ) : null
			)
		);
		return panelLayout( hit, __( 'Your logo appears in the header, emails and anywhere the Site Logo block is used.', 'gend-society' ), controls, { note: __( 'Shown in your header', 'gend-society' ) } );
	}
	function panelFavicon( hit ) {
		var id = S.settings.site_icon;
		var m = id && S.media[ id ];
		var src = m ? m.source_url : '';
		var w = m && m.media_details ? m.media_details.width : 0;
		var hgt = m && m.media_details ? m.media_details.height : 0;
		var warn = m && ( w !== hgt || w < 512 ) ? __( 'For a sharp icon everywhere, use a square image of at least 512 × 512 pixels.', 'gend-society' ) : '';
		var tab = h( 'div', { class: 'gs-st-browser' }, h( 'div', { class: 'gs-st-browser-tab' }, src ? h( 'img', { src: src, alt: '' } ) : h( 'span', { class: 'gs-st-fav-empty' } ), h( 'span', null, ( S.settings.title || cfg.siteTitle ) ), h( 'span', { class: 'gs-st-x', 'aria-hidden': 'true' }, '×' ) ), h( 'div', { class: 'gs-st-browser-bar' }, cfg.homeUrl ) );
		var app = h( 'div', { class: 'gs-st-app-icons' }, [ 'is-round', 'is-squircle', 'is-square' ].map( function ( c ) {
			return h( 'div', { class: 'gs-st-app-icon ' + c }, src ? h( 'img', { src: src, alt: '' } ) : null );
		} ) );
		var controls = h(
			'div',
			{ class: 'gs-st-stack' },
			h( 'div', { class: 'gs-st-card is-flat' }, h( 'h3', null, __( 'Browser tab', 'gend-society' ) ), tab, h( 'h3', null, __( 'Home screen', 'gend-society' ) ), app ),
			warn ? h( 'p', { class: 'gs-st-hint is-warn' }, icon( 'warning' ), warn ) : null,
			h( 'div', { class: 'gs-st-row' },
				h( 'button', { type: 'button', class: 'gs-st-btn is-primary', onclick: function () {
					pickImage( __( 'Choose your favicon', 'gend-society' ), function ( a ) {
						saveSetting( { site_icon: a.id }, __( 'Favicon updated.', 'gend-society' ) );
					} );
				} }, icon( 'upload' ), m ? __( 'Replace favicon', 'gend-society' ) : __( 'Upload favicon', 'gend-society' ) ),
				m ? h( 'button', { type: 'button', class: 'gs-st-btn is-ghost', onclick: function () {
					saveSetting( { site_icon: 0 }, __( 'Favicon removed.', 'gend-society' ) );
				} }, __( 'Remove', 'gend-society' ) ) : null
			)
		);
		return panelLayout( hit, __( 'The small icon in browser tabs, bookmarks and on phone home screens.', 'gend-society' ), controls );
	}
	function panelSiteName( hit ) {
		var title = h( 'input', { type: 'text', class: 'gs-st-input', value: S.settings.title || '', maxlength: '120' } );
		var tagline = h( 'input', { type: 'text', class: 'gs-st-input', value: S.settings.description || '', maxlength: '200' } );
		var mockT = h( 'strong', null, S.settings.title || '' );
		var mockD = h( 'span', null, S.settings.description || '' );
		var sync = function () {
			mockT.textContent = title.value;
			mockD.textContent = tagline.value;
		};
		title.addEventListener( 'input', sync );
		tagline.addEventListener( 'input', sync );
		var controls = h(
			'form',
			{ class: 'gs-st-stack', onsubmit: function ( e ) {
				e.preventDefault();
				saveSetting( { title: title.value, description: tagline.value }, __( 'Site name saved.', 'gend-society' ) ).then( reloadPreview );
			} },
			h( 'label', { class: 'gs-st-field' }, h( 'span', null, __( 'Site name', 'gend-society' ) ), title ),
			h( 'label', { class: 'gs-st-field' }, h( 'span', null, __( 'Tagline', 'gend-society' ) ), tagline ),
			h( 'div', { class: 'gs-st-card is-flat gs-st-search-mock' }, h( 'small', null, __( 'How search results show it', 'gend-society' ) ), h( 'div', { class: 'gs-st-serp' }, mockT, h( 'cite', null, cfg.homeUrl ), mockD ) ),
			h( 'div', { class: 'gs-st-row' }, h( 'button', { type: 'submit', class: 'gs-st-btn is-primary' }, __( 'Save', 'gend-society' ) ) )
		);
		return panelLayout( hit, __( 'Your site name appears in the header, browser tabs and search results.', 'gend-society' ), controls );
	}

	function colorOnlyStyles( styles ) {
		// Keep only colour properties from a style variation, so applying a scheme leaves type alone.
		if ( ! styles || typeof styles !== 'object' ) {
			return undefined;
		}
		var out = {};
		Object.keys( styles ).forEach( function ( k ) {
			if ( k === 'color' ) {
				out.color = clone( styles.color );
			} else if ( styles[ k ] && typeof styles[ k ] === 'object' && ! Array.isArray( styles[ k ] ) && k !== 'typography' && k !== 'spacing' && k !== 'dimensions' ) {
				var sub = colorOnlyStyles( styles[ k ] );
				if ( sub && Object.keys( sub ).length ) {
					out[ k ] = sub;
				}
			}
		} );
		return out;
	}
	function stripColors( styles ) {
		if ( ! styles || typeof styles !== 'object' ) {
			return;
		}
		delete styles.color;
		Object.keys( styles ).forEach( function ( k ) {
			if ( styles[ k ] && typeof styles[ k ] === 'object' && ! Array.isArray( styles[ k ] ) ) {
				stripColors( styles[ k ] );
				if ( ! Object.keys( styles[ k ] ).length ) {
					delete styles[ k ];
				}
			}
		} );
	}
	function mergeDeep( target, src ) {
		Object.keys( src || {} ).forEach( function ( k ) {
			if ( src[ k ] && typeof src[ k ] === 'object' && ! Array.isArray( src[ k ] ) ) {
				if ( ! target[ k ] || typeof target[ k ] !== 'object' ) {
					target[ k ] = {};
				}
				mergeDeep( target[ k ], src[ k ] );
			} else {
				target[ k ] = clone( src[ k ] );
			}
		} );
		return target;
	}
	function applyScheme( v ) {
		var pal = originList( get( v, 'settings.color.palette' ) );
		stripColors( S.gs.styles );
		if ( pal.length ) {
			set( S.gs, 'settings.color.palette.theme', clone( pal ) );
		} else {
			set( S.gs, 'settings.color.palette.theme', undefined );
		}
		mergeDeep( S.gs.styles, colorOnlyStyles( v.styles ) || {} );
		changed();
		render();
	}
	function schemeKey( pal ) {
		return pal.map( function ( p ) {
			return String( p.color ).toLowerCase();
		} ).join( '|' );
	}
	function panelPalettes( hit ) {
		var pal = palette();
		var currentKey = schemeKey( pal );
		var schemes = [ { title: __( 'Theme default', 'gend-society' ), settings: { color: { palette: basePalette() } }, styles: {}, isBase: true } ].concat(
			S.variations.filter( function ( v ) {
				return originList( get( v, 'settings.color.palette' ) ).length;
			} )
		);
		// Full style variations and colour-only ones often share a palette: show each palette once.
		var seenKeys = {};
		schemes = schemes.filter( function ( v ) {
			var k = schemeKey( originList( get( v, 'settings.color.palette' ) ) );
			if ( seenKeys[ k ] ) {
				return false;
			}
			seenKeys[ k ] = 1;
			return true;
		} );
		var grid = h( 'div', { class: 'gs-st-schemes' }, schemes.map( function ( v ) {
			var vp = originList( get( v, 'settings.color.palette' ) );
			var on = schemeKey( vp ) === currentKey;
			var bg = ( vp[ 0 ] && vp[ 0 ].color ) || '#fff';
			var fg = ( vp[ 1 ] && vp[ 1 ].color ) || '#111';
			return h(
				'button',
				{ type: 'button', class: 'gs-st-scheme' + ( on ? ' is-on' : '' ), 'aria-pressed': on ? 'true' : 'false', onclick: function () {
					if ( v.isBase ) {
						stripColors( S.gs.styles );
						set( S.gs, 'settings.color.palette.theme', undefined );
						changed();
						render();
					} else {
						applyScheme( v );
					}
				} },
				h( 'span', { class: 'gs-st-scheme-face', style: { background: bg, color: fg } }, h( 'b', null, 'Aa' ), h( 'span', { class: 'gs-st-scheme-chips' }, vp.slice( 2, 6 ).map( function ( c ) {
					return h( 'i', { style: { background: c.color } } );
				} ) ) ),
				h( 'span', { class: 'gs-st-scheme-name' }, v.title || __( 'Scheme', 'gend-society' ) ),
				on ? icon( 'yes' ) : null
			);
		} ) );
		var editor = h( 'div', { class: 'gs-st-palette' }, pal.map( function ( c, i ) {
			var isHex = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test( c.color );
			var update = function ( value ) {
				var next = clone( palette() );
				next[ i ] = Object.assign( {}, next[ i ], { color: value } );
				set( S.gs, 'settings.color.palette.theme', next );
				sw.style.background = value;
				changed();
			};
			var sw = h( 'span', { class: 'gs-st-swatch', style: { background: c.color } } );
			var text = h( 'input', { type: 'text', class: 'gs-st-input is-mono', value: c.color, 'aria-label': sprintf( __( '%s colour value', 'gend-society' ), c.name ), onchange: function () {
				update( text.value.trim() );
			} } );
			var picker = isHex ? h( 'input', { type: 'color', class: 'gs-st-color', value: c.color.length === 4 ? '#' + c.color.slice( 1 ).split( '' ).map( function ( x ) {
				return x + x;
			} ).join( '' ) : c.color, 'aria-label': sprintf( __( 'Pick %s', 'gend-society' ), c.name ), oninput: function () {
				text.value = picker.value;
				update( picker.value );
			} } ) : null;
			return h( 'div', { class: 'gs-st-palette-row' }, h( 'label', { class: 'gs-st-swatch-wrap' }, sw, picker ), h( 'div', { class: 'gs-st-palette-meta' }, h( 'strong', null, c.name ), h( 'small', null, c.slug ) ), text );
		} ) );
		var controls = h( 'div', { class: 'gs-st-stack' }, h( 'h3', { class: 'gs-st-h3' }, __( 'Schemes', 'gend-society' ) ), grid, h( 'h3', { class: 'gs-st-h3' }, __( 'Fine-tune your palette', 'gend-society' ) ), editor );
		return panelLayout( hit, __( 'Pick a scheme to restyle the whole site at once, then fine-tune any colour. Nothing goes live until you save.', 'gend-society' ), controls );
	}

	/* ── Shortcodes (Sales): live preview + details ─────────────────────── */
	function panelShortcode( hit ) {
		var tag = hit.item.tag;
		var ctl = h( 'div', { class: 'gs-st-controls' }, crumbs( hit ), h( 'h1', { class: 'gs-st-title' }, hit.item.label ), loadingView() );
		var split = h( 'div', { class: 'gs-st-split' }, ctl, previewPane( { url: cfg.homeUrl + ( cfg.homeUrl.indexOf( '?' ) === -1 ? '?' : '&' ) + 'gs_studio_shortcode=' + encodeURIComponent( tag ) + '&gs_scx_off=1', title: __( 'Live preview (as you)', 'gend-society' ) } ) );
		var frame = previewFrame;
		api( { path: '/gend-society/v1/studio/shortcode?tag=' + encodeURIComponent( tag ) } ).then( function ( d ) {
			ctl.textContent = '';
			var code = '[' + d.tag + ']';
			var copyBtn = h( 'button', { type: 'button', class: 'gs-st-btn is-sm', onclick: function () {
				var done = function () {
					toast( sprintf( __( '%s copied.', 'gend-society' ), code ), 'ok' );
				};
				if ( navigator.clipboard ) {
					navigator.clipboard.writeText( code ).then( done, done );
				} else {
					done();
				}
			} }, icon( 'admin-page' ), __( 'Copy', 'gend-society' ) );
			var section = function ( title, body ) {
				return h( 'div', { class: 'gs-st-card is-flat gs-st-sc-block' }, h( 'h3', null, title ), body );
			};
			var attrs = d.attributes.length
				? h( 'table', { class: 'gs-st-sc-table' }, h( 'thead', null, h( 'tr', null, h( 'th', null, __( 'Attribute', 'gend-society' ) ), h( 'th', null, __( 'Default', 'gend-society' ) ) ) ), h( 'tbody', null, d.attributes.map( function ( a ) {
					return h( 'tr', null, h( 'td', null, h( 'code', null, a.name ) ), h( 'td', null, h( 'code', null, a.default ) ) );
				} ) ) )
				: h( 'p', { class: 'gs-st-muted' }, __( 'No attributes: place it exactly as shown.', 'gend-society' ) );
			var pages = d.pages.length
				? h( 'ul', { class: 'gs-st-sc-pages' }, d.pages.map( function ( pg ) {
					return h( 'li', null,
						h( 'span', null, h( 'strong', null, pg.title || __( '(no title)', 'gend-society' ) ), h( 'small', null, pg.type ) ),
						h( 'a', { class: 'gs-st-btn is-ghost is-sm', href: pg.url, target: '_blank', rel: 'noopener' }, icon( 'external' ), __( 'View', 'gend-society' ) ),
						h( 'button', { type: 'button', class: 'gs-st-btn is-ghost is-sm', onclick: function () {
							main.textContent = '';
							previewFrame = null;
							main.appendChild( frameView( pg.title, pg.editUrl ) );
						} }, icon( 'edit' ), __( 'Edit', 'gend-society' ) )
					);
				} ) )
				: h( 'p', { class: 'gs-st-muted' }, __( 'Not used on any page yet. Paste the shortcode into a page or a Shortcode block.', 'gend-society' ) );
			var layoutBtn = null;
			var tpl = d.template && S.templates[ d.template ];
			if ( tpl ) {
				layoutBtn = h( 'button', { type: 'button', class: 'gs-st-btn is-primary', onclick: function () {
					main.textContent = '';
					previewFrame = null;
					main.appendChild( frameView( titleOf( tpl ), canvasUrl( tpl ) ) );
				} }, icon( 'layout' ), __( 'Design the page layout', 'gend-society' ) );
			}
			ctl.appendChild( h( 'div', { class: 'gs-st-stack' },
				h( 'div', null, crumbs( hit ), h( 'h1', { class: 'gs-st-title' }, d.label ), h( 'p', { class: 'gs-st-intro' }, d.description ) ),
				h( 'div', { class: 'gs-st-sc-code' }, h( 'code', null, code ), copyBtn ),
				scxCard( tag, frame ),
				d.shows.length ? section( __( 'What it shows', 'gend-society' ), h( 'ul', { class: 'gs-st-sc-list' }, d.shows.map( function ( x ) {
					return h( 'li', null, x );
				} ) ) ) : null,
				d.audience ? section( __( 'Who sees it', 'gend-society' ), h( 'p', null, d.audience ) ) : null,
				section( __( 'Attributes', 'gend-society' ), attrs ),
				section( sprintf( __( 'Used on (%d)', 'gend-society' ), d.pages.length ), pages ),
				layoutBtn ? h( 'div', { class: 'gs-st-row' }, layoutBtn, h( 'span', { class: 'gs-st-muted' }, __( 'The block layout every page with this shortcode uses.', 'gend-society' ) ) ) : null,
				d.source ? h( 'p', { class: 'gs-st-muted gs-st-sc-source' }, __( 'Source:', 'gend-society' ) + ' ' + d.source ) : null
			) );
		}, function ( e ) {
			ctl.textContent = errMsg( e );
		} );
		return split;
	}

	/* ── Customise a shortcode component (inc/shortcode-customizer.php) ──── */
	// Applies unsaved settings to the preview (rendered with customisation off).
	function scxApply( frame, data, draft ) {
		var doc;
		try {
			doc = frame.contentDocument;
		} catch ( e ) {
			return;
		}
		if ( ! doc || ! doc.head ) {
			return;
		}
		var css = '.gs-sc-preview{';
		data.colors.forEach( function ( c ) {
			if ( draft.colors[ c.key ] ) {
				c.vars.forEach( function ( v ) {
					css += v + ':' + draft.colors[ c.key ] + ';';
				} );
			}
		} );
		css += '}';
		var sels = [];
		data.sections.forEach( function ( sec ) {
			if ( draft.hide.indexOf( sec.key ) !== -1 ) {
				sec.selectors.forEach( function ( s ) {
					sels.push( '.gs-sc-preview ' + s );
				} );
			}
		} );
		if ( sels.length ) {
			css += sels.join( ',' ) + '{display:none!important}';
		}
		css += draft.css || '';
		var st = doc.getElementById( 'gs-scx-draft' );
		if ( ! st ) {
			st = doc.createElement( 'style' );
			st.id = 'gs-scx-draft';
			doc.head.appendChild( st );
		}
		st.textContent = css;
		Array.prototype.forEach.call( doc.querySelectorAll( '.gs-sc-preview :is(h1,h2,h3,h4,h5,h6,button,span.aas-tab-label)' ), function ( el ) {
			if ( el.children.length && ! el.hasAttribute( 'data-gs-scx-orig' ) ) {
				return;
			}
			if ( ! el.hasAttribute( 'data-gs-scx-orig' ) ) {
				el.setAttribute( 'data-gs-scx-orig', el.textContent.replace( /\s+/g, ' ' ).trim() );
			}
			var orig = el.getAttribute( 'data-gs-scx-orig' );
			el.textContent = draft.texts[ orig ] || orig;
		} );
		// A hidden tab may be the open one: open the first one left.
		Array.prototype.forEach.call( doc.querySelectorAll( '.gs-sc-preview [role=tablist]' ), function ( l ) {
			var active = l.querySelector( 'button.is-active' );
			if ( active && active.offsetParent === null ) {
				var next = Array.prototype.filter.call( l.querySelectorAll( 'button' ), function ( b ) {
					return b.offsetParent !== null;
				} )[ 0 ];
				if ( next ) {
					next.click();
				}
			}
		} );
	}
	function scxCard( tag, frame ) {
		var card = h( 'div', { class: 'gs-st-card is-flat gs-st-scx' }, h( 'h3', null, __( 'Customise', 'gend-society' ) ), loadingView() );
		api( { path: '/gend-society/v1/studio/shortcode-custom?tag=' + encodeURIComponent( tag ) } ).then( function ( data ) {
			var saved = JSON.stringify( data.settings );
			var draft = clone( data.settings );
			draft.colors = Array.isArray( draft.colors ) ? {} : draft.colors;
			draft.texts = Array.isArray( draft.texts ) ? {} : draft.texts;
			saved = JSON.stringify( draft );
			var saveBtn;
			var update = function () {
				scxApply( frame, data, draft );
				saveBtn.disabled = JSON.stringify( draft ) === saved;
			};
			var swatches = [];
			// Unset colours start at the component's own value (read from the preview).
			var syncDefaults = function () {
				var doc;
				try {
					doc = frame.contentDocument;
				} catch ( e ) {
					return;
				}
				var box = doc && doc.querySelector( '.gs-sc-preview' );
				if ( ! box ) {
					return;
				}
				swatches.forEach( function ( sw ) {
					if ( draft.colors[ sw.key ] ) {
						return;
					}
					var cs = getComputedStyle( box );
					var v = '';
					sw.vars.some( function ( name ) {
						v = cs.getPropertyValue( name ).trim();
						return !! v;
					} );
					var rgb = v.match( /^rgba?\(\s*(\d+)[\s,]+(\d+)[\s,]+(\d+)/i );
					if ( rgb ) {
						v = '#' + [ rgb[ 1 ], rgb[ 2 ], rgb[ 3 ] ].map( function ( n ) {
							return ( '0' + Number( n ).toString( 16 ) ).slice( -2 );
						} ).join( '' );
					} else if ( /^#[0-9a-f]{3}$/i.test( v ) ) {
						v = '#' + v.slice( 1 ).split( '' ).map( function ( x ) {
							return x + x;
						} ).join( '' );
					}
					if ( /^#[0-9a-f]{6}$/i.test( v ) ) {
						sw.input.value = v.toLowerCase();
					}
				} );
			};
			frame.addEventListener( 'load', function () {
				scxApply( frame, data, draft );
				syncDefaults();
			} );
			var colors = data.colors.length ? h( 'div', { class: 'gs-st-scx-colors' }, data.colors.map( function ( c ) {
				var input = h( 'input', { type: 'color', value: draft.colors[ c.key ] || '#97e46e', 'aria-label': c.label } );
				var reset = h( 'button', { type: 'button', class: 'gs-st-icon-btn', title: __( 'Use the default', 'gend-society' ), 'aria-label': __( 'Use the default', 'gend-society' ), hidden: ! draft.colors[ c.key ] }, icon( 'no-alt' ) );
				var row = h( 'label', { class: 'gs-st-scx-color' + ( draft.colors[ c.key ] ? ' is-set' : '' ) }, input, h( 'span', null, c.label, h( 'small', null, draft.colors[ c.key ] || __( 'Default', 'gend-society' ) ) ), reset );
				swatches.push( { key: c.key, vars: c.vars, input: input } );
				input.addEventListener( 'input', function () {
					draft.colors[ c.key ] = input.value;
					row.classList.add( 'is-set' );
					row.querySelector( 'small' ).textContent = input.value;
					reset.hidden = false;
					update();
				} );
				reset.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					delete draft.colors[ c.key ];
					row.classList.remove( 'is-set' );
					row.querySelector( 'small' ).textContent = __( 'Default', 'gend-society' );
					reset.hidden = true;
					update();
					syncDefaults();
				} );
				return row;
			} ) ) : null;
			var sections = data.sections.length ? h( 'div', { class: 'gs-st-pt-opts' }, data.sections.map( function ( sec ) {
				var cb = h( 'input', { type: 'checkbox', checked: draft.hide.indexOf( sec.key ) === -1 } );
				cb.addEventListener( 'change', function () {
					draft.hide = draft.hide.filter( function ( k ) {
						return k !== sec.key;
					} );
					if ( ! cb.checked ) {
						draft.hide.push( sec.key );
					}
					update();
				} );
				return h( 'label', { class: 'gs-st-pt-opt' }, cb, h( 'span', null, sec.label ) );
			} ) ) : null;
			var texts = data.texts.length ? h( 'div', { class: 'gs-st-scx-texts' }, data.texts.map( function ( t ) {
				var input = h( 'input', { type: 'text', class: 'gs-st-input', value: draft.texts[ t ] || '', placeholder: t, 'aria-label': t } );
				input.addEventListener( 'input', function () {
					if ( input.value.trim() ) {
						draft.texts[ t ] = input.value.trim();
					} else {
						delete draft.texts[ t ];
					}
					update();
				} );
				return h( 'label', { class: 'gs-st-field' }, h( 'span', null, t ), input );
			} ) ) : h( 'p', { class: 'gs-st-muted' }, __( 'No headings or buttons to rename.', 'gend-society' ) );
			var css = h( 'textarea', { class: 'gs-st-input is-mono', rows: 5, placeholder: '.aas-card { border-radius: 20px; }', spellcheck: 'false' } );
			css.value = draft.css || '';
			css.addEventListener( 'input', debounce( function () {
				draft.css = css.value;
				update();
			}, 250 ) );
			saveBtn = h( 'button', { type: 'button', class: 'gs-st-btn is-primary', disabled: true, onclick: function () {
				saveBtn.disabled = true;
				api( { path: '/gend-society/v1/studio/shortcode-custom', method: 'POST', data: { tag: tag, settings: draft } } ).then( function ( r ) {
					saved = JSON.stringify( r.settings );
					draft = clone( r.settings );
					draft.colors = Array.isArray( draft.colors ) ? {} : draft.colors;
					draft.texts = Array.isArray( draft.texts ) ? {} : draft.texts;
					saved = JSON.stringify( draft );
					toast( __( 'Saved. Every page with this component now shows it this way.', 'gend-society' ), 'ok' );
				}, function ( e ) {
					saveBtn.disabled = false;
					toast( errMsg( e ), 'error' );
				} );
			} }, icon( 'saved' ), __( 'Save', 'gend-society' ) );
			var resetBtn = h( 'button', { type: 'button', class: 'gs-st-btn is-ghost', onclick: function () {
				if ( ! window.confirm( __( 'Reset this component to how its plugin shows it?', 'gend-society' ) ) ) {
					return;
				}
				api( { path: '/gend-society/v1/studio/shortcode-custom', method: 'POST', data: { tag: tag, settings: {} } } ).then( function () {
					toast( __( 'Reset to the default.', 'gend-society' ), 'ok' );
					render();
				}, function ( e ) {
					toast( errMsg( e ), 'error' );
				} );
			} }, icon( 'image-rotate' ), __( 'Reset all', 'gend-society' ) );
			card.textContent = '';
			append( card, [
				h( 'h3', null, __( 'Customise', 'gend-society' ) ),
				h( 'p', { class: 'gs-st-muted' }, __( 'Make it yours: colours, which parts show, and the wording. The preview updates as you go; Save applies it everywhere this component is used.', 'gend-society' ) ),
				colors ? h( 'h4', { class: 'gs-st-scx-h' }, __( 'Colours', 'gend-society' ) ) : null,
				colors,
				sections ? h( 'h4', { class: 'gs-st-scx-h' }, __( 'Sections shown', 'gend-society' ) ) : null,
				sections,
				h( 'h4', { class: 'gs-st-scx-h' }, __( 'Wording', 'gend-society' ) ),
				texts,
				h( 'h4', { class: 'gs-st-scx-h' }, __( 'Extra CSS', 'gend-society' ) ),
				css,
				h( 'div', { class: 'gs-st-row' }, saveBtn, resetBtn ),
			] );
			scxApply( frame, data, draft );
			syncDefaults();
		}, function ( e ) {
			card.textContent = '';
			if ( e && e.code !== 'gs_scx_tag' ) {
				card.appendChild( h( 'p', { class: 'gs-st-muted' }, errMsg( e ) ) );
			} else {
				card.remove();
			}
		} );
		return card;
	}

	/* ── Sidebars: optional sidebar per area of the site ────────────────── */
	function panelSidebars( hit ) {
		var ctl = h( 'div', { class: 'gs-st-controls' }, crumbs( hit ), h( 'h1', { class: 'gs-st-title' }, hit.item.label ),
			h( 'p', { class: 'gs-st-intro' }, __( 'Every page is full width until you give its area a sidebar. Pick a side, then design the sidebar with blocks, just like the header and footer.', 'gend-society' ) ) );
		var list = h( 'div', { class: 'gs-st-sb-list' }, loadingView() );
		ctl.appendChild( list );
		var current = null;
		var pane = previewPane( { url: cfg.homeUrl, title: __( 'Preview', 'gend-society' ) } );
		var titleEl = pane.querySelector( '.gs-st-preview-title' );
		var show = function ( a ) {
			current = a.slug;
			if ( titleEl ) {
				titleEl.textContent = a.label;
			}
			if ( previewFrame && a.url ) {
				previewFrame.src = a.url + ( a.url.indexOf( '?' ) === -1 ? '?' : '&' ) + 'gs_sb=' + a.position + '&gs_sb_t=' + Date.now();
			}
		};
		var save = function ( a, data ) {
			return api( { path: '/gend-society/v1/sidebars', method: 'POST', data: Object.assign( { area: a.slug }, data ) } ).then( function ( r ) {
				draw( r, a.slug );
				toast( __( 'Saved.', 'gend-society' ), 'ok' );
			}, function ( e ) {
				toast( errMsg( e ), 'error' );
			} );
		};
		var edit = function ( a, btn ) {
			btn.disabled = true;
			api( { path: '/gend-society/v1/sidebars/part', method: 'POST', data: { area: a.slug } } ).then( function () {
				return loadTemplates();
			} ).then( function () {
				var t = S.parts[ 'gs-sidebar-' + a.slug ];
				if ( ! t ) {
					throw new Error( __( 'The sidebar could not be created.', 'gend-society' ) );
				}
				openEmbed( sprintf( __( 'Sidebar: %s', 'gend-society' ), a.label ), canvasUrl( t ) );
			} ).catch( function ( e ) {
				btn.disabled = false;
				toast( errMsg( e ), 'error' );
			} );
		};
		var draw = function ( areas, focus ) {
			list.textContent = '';
			areas.forEach( function ( a ) {
				var seg = function ( pos, label ) {
					return h( 'button', { type: 'button', class: 'gs-st-seg' + ( a.position === pos ? ' is-on' : '' ), 'aria-pressed': a.position === pos ? 'true' : 'false', onclick: function () {
						save( a, { position: pos } );
					} }, label );
				};
				var on = a.position !== 'none';
				var width = h( 'input', { type: 'range', min: '200', max: '480', step: '10', value: String( a.width ), 'aria-label': __( 'Sidebar width', 'gend-society' ), onchange: function ( ev ) {
					save( a, { width: parseInt( ev.target.value, 10 ) } );
				}, oninput: function ( ev ) {
					widthVal.textContent = ev.target.value + 'px';
				} } );
				var widthVal = h( 'span', { class: 'gs-st-muted' }, a.width + 'px' );
				var editBtn = h( 'button', { type: 'button', class: 'gs-st-btn is-sm' + ( on ? ' is-primary' : ' is-ghost' ), onclick: function () {
					edit( a, editBtn );
				} }, icon( 'edit' ), a.hasPart ? __( 'Edit sidebar', 'gend-society' ) : __( 'Design sidebar', 'gend-society' ) );
				var card = h( 'div', { class: 'gs-st-card gs-st-sb' + ( current === a.slug ? ' is-current' : '' ) },
					h( 'div', { class: 'gs-st-yz-head' },
						h( 'strong', null, a.label ),
						h( 'span', { class: 'gs-st-badge ' + ( on ? 'is-custom' : 'is-missing' ) }, on ? ( a.position === 'left' ? __( 'Sidebar on the left', 'gend-society' ) : __( 'Sidebar on the right', 'gend-society' ) ) : __( 'Full width', 'gend-society' ) )
					),
					h( 'div', { class: 'gs-st-row' }, h( 'div', { class: 'gs-st-segs' }, seg( 'none', __( 'None', 'gend-society' ) ), seg( 'left', __( 'Left', 'gend-society' ) ), seg( 'right', __( 'Right', 'gend-society' ) ) ) ),
					on ? h( 'div', { class: 'gs-st-row gs-st-sb-opts' },
						h( 'label', { class: 'gs-st-sb-width' }, __( 'Width', 'gend-society' ), width, widthVal ),
						h( 'label', { class: 'gs-st-sb-sticky' }, h( 'input', { type: 'checkbox', checked: a.sticky ? 'checked' : null, onchange: function ( ev ) {
							save( a, { sticky: ev.target.checked } );
						} } ), __( 'Stays in view when scrolling', 'gend-society' ) )
					) : null,
					h( 'div', { class: 'gs-st-row' }, editBtn,
						a.url ? h( 'button', { type: 'button', class: 'gs-st-btn is-ghost is-sm', onclick: function () {
							show( a );
							draw( areas, a.slug );
						} }, icon( 'visibility' ), __( 'Preview', 'gend-society' ) ) : null )
				);
				list.appendChild( card );
				if ( focus === a.slug ) {
					show( a );
				}
			} );
			if ( ! current && areas[ 0 ] ) {
				current = areas[ 0 ].slug;
				show( areas[ 0 ] );
				draw( areas );
			}
		};
		api( { path: '/gend-society/v1/sidebars' } ).then( function ( r ) {
			draw( r );
		}, function ( e ) {
			list.textContent = errMsg( e );
		} );
		return h( 'div', { class: 'gs-st-split' }, ctl, pane );
	}

	/* ── Youzify styling: per-area switch for Youzify's front-end CSS ───── */
	function panelYouzify( hit ) {
		var wrap = h( 'div', { class: 'gs-st-pad' }, crumbs( hit ), h( 'h1', { class: 'gs-st-title' }, hit.item.label ),
			h( 'p', { class: 'gs-st-intro' }, __( 'Youzify’s stylesheets fight the theme. Switch them off area by area as block layouts replace each page; its features (posting, reactions, media) keep working because its scripts still load. Auto turns them off wherever a published block layout fully replaces the page.', 'gend-society' ) ) );
		var list = h( 'div', { class: 'gs-st-yz-list' }, loadingView() );
		wrap.appendChild( list );
		var draw = function ( areas ) {
			list.textContent = '';
			areas.forEach( function ( a ) {
				var seg = function ( mode, label ) {
					return h( 'button', { type: 'button', class: 'gs-st-seg' + ( a.mode === mode ? ' is-on' : '' ), 'aria-pressed': a.mode === mode ? 'true' : 'false', onclick: function () {
						api( { path: '/gend-society/v1/youzify-styles', method: 'POST', data: { area: a.slug, mode: mode } } ).then( function ( r ) {
							draw( r );
							toast( __( 'Saved. Reload the page to see it.', 'gend-society' ), 'ok' );
						}, function ( e ) {
							toast( errMsg( e ), 'error' );
						} );
					} }, label );
				};
				var state = a.loads ? __( 'Youzify styles load here', 'gend-society' ) : __( 'Youzify styles are off here', 'gend-society' );
				list.appendChild( h( 'div', { class: 'gs-st-card gs-st-yz' },
					h( 'div', { class: 'gs-st-yz-head' },
						h( 'strong', null, a.label ),
						h( 'span', { class: 'gs-st-badge ' + ( a.loads ? 'is-missing' : 'is-custom' ) }, state )
					),
					h( 'p', { class: 'gs-st-hint' }, a.replaced ? __( 'A published block layout replaces this page, so Auto keeps Youzify off.', 'gend-society' ) : __( 'This page still shows Youzify’s own markup. Switching its styles off will make that part look plain until a block layout replaces it.', 'gend-society' ) ),
					h( 'div', { class: 'gs-st-row' },
						h( 'div', { class: 'gs-st-segs' }, seg( 'auto', __( 'Auto', 'gend-society' ) ), seg( 'off', __( 'Off', 'gend-society' ) ), seg( 'on', __( 'On', 'gend-society' ) ) ),
						a.url ? h( 'a', { class: 'gs-st-btn is-ghost is-sm', href: a.url, target: '_blank', rel: 'noopener' }, icon( 'external' ), __( 'Check the page', 'gend-society' ) ) : null
					)
				) );
			} );
		};
		api( { path: '/gend-society/v1/youzify-styles' } ).then( draw, function ( e ) {
			list.textContent = errMsg( e );
		} );
		return wrap;
	}

	/* ── Typography: one panel for every text style, with Google Fonts ─── */
	var TYPE_ELEMENTS = [
		{ id: 'heading', label: __( 'All headings', 'gend-society' ), base: 'styles.elements.heading', sample: __( 'Big ideas, bold headlines', 'gend-society' ) },
		{ id: 'h1', label: 'H1', base: 'styles.elements.h1', parent: 'heading', sample: __( 'Heading 1', 'gend-society' ) },
		{ id: 'h2', label: 'H2', base: 'styles.elements.h2', parent: 'heading', sample: __( 'Heading 2', 'gend-society' ) },
		{ id: 'h3', label: 'H3', base: 'styles.elements.h3', parent: 'heading', sample: __( 'Heading 3', 'gend-society' ) },
		{ id: 'h4', label: 'H4', base: 'styles.elements.h4', parent: 'heading', sample: __( 'Heading 4', 'gend-society' ) },
		{ id: 'h5', label: 'H5', base: 'styles.elements.h5', parent: 'heading', sample: __( 'Heading 5', 'gend-society' ) },
		{ id: 'h6', label: 'H6', base: 'styles.elements.h6', parent: 'heading', sample: __( 'Heading 6', 'gend-society' ) },
		{ id: 'p', label: __( 'Paragraph', 'gend-society' ), base: 'styles', sample: __( 'Comfortable reading for every paragraph on your site.', 'gend-society' ) },
		{ id: 'a', label: __( 'Link', 'gend-society' ), base: 'styles.elements.link', sample: __( 'A link that stands out', 'gend-society' ) },
		{ id: 'button', label: __( 'Buttons', 'gend-society' ), base: 'styles.elements.button', sample: __( 'Get started', 'gend-society' ) },
	];
	var WEIGHT_NAMES = { 100: __( 'Thin', 'gend-society' ), 200: __( 'Extra light', 'gend-society' ), 300: __( 'Light', 'gend-society' ), 400: __( 'Regular', 'gend-society' ), 500: __( 'Medium', 'gend-society' ), 600: __( 'Semi bold', 'gend-society' ), 700: __( 'Bold', 'gend-society' ), 800: __( 'Extra bold', 'gend-society' ), 900: __( 'Black', 'gend-society' ) };

	function familyBySlug( slug ) {
		return fontFamilies().filter( function ( f ) {
			return f.slug === slug;
		} )[ 0 ] || null;
	}
	// Turn a theme.json src ("file:./assets/fonts/x.woff2" or a URL) into a URL.
	function fontSrcUrl( src ) {
		src = Array.isArray( src ) ? src[ 0 ] : src;
		if ( typeof src !== 'string' ) {
			return '';
		}
		return src.indexOf( 'file:./' ) === 0 ? cfg.themeUrl + '/' + src.slice( 7 ) : src;
	}
	// @font-face rules for every family in the draft, so new fonts show before they are saved.
	function fontFaceCss() {
		var css = '';
		fontFamilies().forEach( function ( f ) {
			( f.fontFace || [] ).forEach( function ( face ) {
				var url = fontSrcUrl( face.src );
				if ( ! url ) {
					return;
				}
				css += '@font-face{font-family:"' + String( face.fontFamily || f.name ).replace( /"/g, '' ) + '";font-style:' + ( face.fontStyle || 'normal' ) + ';font-weight:' + ( face.fontWeight || '400' ) + ';font-display:swap;src:url("' + url.replace( /"/g, '%22' ) + '");}';
			} );
		} );
		return css;
	}
	function syncStudioFonts() {
		var el = document.getElementById( 'gs-studio-fonts' );
		if ( ! el ) {
			el = h( 'style', { id: 'gs-studio-fonts' } );
			document.head.appendChild( el );
		}
		el.textContent = fontFaceCss();
	}

	// Google Fonts, from WordPress's own font collection (files are installed on this site, not hot-linked).
	function loadGoogleFonts() {
		if ( S.google ) {
			return Promise.resolve( S.google );
		}
		if ( ! S.googleLoading ) {
			S.googleLoading = api( { path: '/wp/v2/font-collections/google-fonts' } ).then( function ( r ) {
				S.google = r;
				return r;
			}, function ( e ) {
				S.googleLoading = null;
				throw e;
			} );
		}
		return S.googleLoading;
	}
	function installGoogleFont( fam, onProgress ) {
		var s = fam.font_family_settings;
		var faces = s.fontFace || [];
		var familyId;
		return api( { path: '/wp/v2/font-families?slug=' + encodeURIComponent( s.slug ) + '&_embed=true' } ).then( function ( found ) {
			if ( found && found.length ) {
				familyId = found[ 0 ].id;
				var installed = ( found[ 0 ]._embedded && found[ 0 ]._embedded.font_faces ) || [];
				return installed.length ? installed.map( function ( f ) {
					return f.font_face_settings;
				} ) : null;
			}
			var fd = new window.FormData();
			fd.append( 'font_family_settings', JSON.stringify( { name: s.name, slug: s.slug, fontFamily: s.fontFamily, preview: s.preview } ) );
			return api( { path: '/wp/v2/font-families', method: 'POST', body: fd } ).then( function ( r ) {
				familyId = r.id;
				return null;
			} );
		} ).then( function ( existing ) {
			if ( existing ) {
				return existing;
			}
			var out = [];
			// One style at a time: download the file from Google, upload it to this site.
			return faces.reduce( function ( chain, face, i ) {
				return chain.then( function () {
					return window.fetch( face.src ).then( function ( res ) {
						if ( ! res.ok ) {
							throw new Error( __( 'Could not download the font file.', 'gend-society' ) );
						}
						return res.blob();
					} ).then( function ( blob ) {
						var key = 'file-' + i;
						var fd = new window.FormData();
						fd.append( 'font_face_settings', JSON.stringify( { fontFamily: face.fontFamily, fontStyle: face.fontStyle, fontWeight: face.fontWeight, src: key } ) );
						fd.append( key, new window.File( [ blob ], s.slug + '-' + face.fontWeight + '-' + face.fontStyle + '.woff2', { type: 'font/woff2' } ) );
						return api( { path: '/wp/v2/font-families/' + familyId + '/font-faces', method: 'POST', body: fd } );
					} ).then( function ( r ) {
						out.push( r.font_face_settings );
						onProgress( out.length, faces.length );
					} );
				} );
			}, Promise.resolve() ).then( function () {
				return out;
			} );
		} ).then( function ( installedFaces ) {
			// Activate it in the draft's custom font families (saved with the rest of the styles).
			var family = { name: s.name, slug: s.slug, fontFamily: s.fontFamily, fontFace: installedFaces.map( function ( f ) {
				return { fontFamily: f.fontFamily, fontStyle: f.fontStyle, fontWeight: f.fontWeight, src: f.src };
			} ) };
			var custom = ( get( S.gs, 'settings.typography.fontFamilies.custom' ) || [] ).filter( function ( f ) {
				return f.slug !== s.slug;
			} );
			custom.push( family );
			set( S.gs, 'settings.typography.fontFamilies.custom', custom );
			syncStudioFonts();
			return family;
		} );
	}

	function fontPickerModal( current, onPick ) {
		var overlay = h( 'div', { class: 'gs-st-modal-wrap', role: 'dialog', 'aria-modal': 'true', 'aria-label': __( 'Choose a font', 'gend-society' ) } );
		var onKey = function ( e ) {
			if ( e.key === 'Escape' ) {
				close();
			}
		};
		var close = function () {
			overlay.remove();
			document.removeEventListener( 'keydown', onKey );
		};
		document.addEventListener( 'keydown', onKey );
		var cat = '';
		var limit = 60;
		var busy = false;
		var search = h( 'input', { type: 'search', class: 'gs-st-input', placeholder: __( 'Search 1,900+ fonts…', 'gend-society' ), 'aria-label': __( 'Search fonts', 'gend-society' ) } );
		var cats = h( 'div', { class: 'gs-st-chips' } );
		var mine = h( 'div', { class: 'gs-st-font-list' } );
		var google = h( 'div', { class: 'gs-st-font-list is-google' }, h( 'div', { class: 'gs-st-loading' }, h( 'span', { class: 'gs-st-spinner' } ), __( 'Loading Google Fonts…', 'gend-society' ) ) );
		var status = h( 'div', { class: 'gs-st-font-status', role: 'status' } );
		var pick = function ( value ) {
			onPick( value );
			close();
		};
		var drawMine = function () {
			var q = search.value.trim().toLowerCase();
			mine.textContent = '';
			mine.appendChild( h( 'button', { type: 'button', class: 'gs-st-font-opt' + ( ! current ? ' is-on' : '' ), onclick: function () {
				pick( '' );
			} }, h( 'span', { class: 'gs-st-font-name' }, __( 'Theme default', 'gend-society' ) ) ) );
			fontFamilies().filter( function ( f ) {
				return ! q || f.name.toLowerCase().indexOf( q ) !== -1;
			} ).forEach( function ( f ) {
				mine.appendChild( h( 'button', { type: 'button', class: 'gs-st-font-opt' + ( presetSlug( current, 'font-family' ) === f.slug ? ' is-on' : '' ), onclick: function () {
					pick( 'var:preset|font-family|' + f.slug );
				} }, h( 'span', { class: 'gs-st-font-name', style: { 'font-family': f.fontFamily } }, f.name ), h( 'small', null, __( 'Installed', 'gend-society' ) ) ) );
			} );
		};
		var drawGoogle = function () {
			if ( ! S.google ) {
				return;
			}
			var q = search.value.trim().toLowerCase();
			var have = {};
			fontFamilies().forEach( function ( f ) {
				have[ f.slug ] = 1;
			} );
			var list = S.google.font_families.filter( function ( f ) {
				var st = f.font_family_settings;
				return ! have[ st.slug ] && ( ! q || st.name.toLowerCase().indexOf( q ) !== -1 ) && ( ! cat || ( f.categories || [] ).indexOf( cat ) !== -1 );
			} );
			google.textContent = '';
			list.slice( 0, limit ).forEach( function ( f ) {
				var st = f.font_family_settings;
				google.appendChild( h( 'button', { type: 'button', class: 'gs-st-font-opt', title: st.name, onclick: function () {
					if ( busy ) {
						return;
					}
					busy = true;
					overlay.classList.add( 'is-busy' );
					status.textContent = sprintf( __( 'Installing %s…', 'gend-society' ), st.name );
					installGoogleFont( f, function ( n, total ) {
						status.textContent = sprintf( __( 'Installing %1$s… %2$d of %3$d styles', 'gend-society' ), st.name, n, total );
					} ).then( function ( family ) {
						toast( sprintf( __( '%s installed. Save styles to publish it.', 'gend-society' ), family.name ), 'ok' );
						pick( 'var:preset|font-family|' + family.slug );
					}, function ( e ) {
						busy = false;
						overlay.classList.remove( 'is-busy' );
						status.textContent = errMsg( e );
					} );
				} }, st.preview ? h( 'img', { class: 'gs-st-font-preview', src: st.preview, alt: st.name, loading: 'lazy' } ) : h( 'span', { class: 'gs-st-font-name' }, st.name ), h( 'small', null, sprintf( __( '%d styles', 'gend-society' ), ( st.fontFace || [] ).length ) ) ) );
			} );
			if ( list.length > limit ) {
				google.appendChild( h( 'button', { type: 'button', class: 'gs-st-btn is-ghost', onclick: function () {
					limit += 60;
					drawGoogle();
				} }, sprintf( __( 'Show more (%d)', 'gend-society' ), list.length - limit ) ) );
			}
			if ( ! list.length ) {
				google.appendChild( h( 'p', { class: 'gs-st-muted' }, __( 'No Google Fonts match.', 'gend-society' ) ) );
			}
		};
		search.addEventListener( 'input', debounce( function () {
			limit = 60;
			drawMine();
			drawGoogle();
		}, 150 ) );
		var dialog = h(
			'div',
			{ class: 'gs-st-modal is-wide' },
			h( 'div', { class: 'gs-st-font-head' }, h( 'h2', null, __( 'Choose a font', 'gend-society' ) ), h( 'button', { type: 'button', class: 'gs-st-icon-btn', 'aria-label': __( 'Close', 'gend-society' ), onclick: function () {
				close();
			} }, icon( 'no-alt' ) ) ),
			search,
			status,
			h( 'div', { class: 'gs-st-font-cols' },
				h( 'section', null, h( 'h3', { class: 'gs-st-h3' }, __( 'Your fonts', 'gend-society' ) ), mine ),
				h( 'section', null, h( 'h3', { class: 'gs-st-h3' }, __( 'Google Fonts', 'gend-society' ) ), h( 'p', { class: 'gs-st-hint' }, __( 'Pick one and it is downloaded and installed on your site automatically.', 'gend-society' ) ), cats, google )
			)
		);
		overlay.appendChild( dialog );
		overlay.addEventListener( 'click', function ( e ) {
			if ( e.target === overlay ) {
				close();
			}
		} );
		root.appendChild( overlay );
		drawMine();
		search.focus();
		loadGoogleFonts().then( function ( g ) {
			var setCat = function ( slug, btn ) {
				cat = slug;
				Array.prototype.forEach.call( cats.children, function ( c ) {
					c.classList.toggle( 'is-on', c === btn );
				} );
				limit = 60;
				drawGoogle();
			};
			var all = h( 'button', { type: 'button', class: 'gs-st-chip is-on', onclick: function () {
				setCat( '', all );
			} }, __( 'All', 'gend-society' ) );
			cats.appendChild( all );
			( g.categories || [] ).forEach( function ( c ) {
				var b = h( 'button', { type: 'button', class: 'gs-st-chip', onclick: function () {
					setCat( c.slug, b );
				} }, c.name );
				cats.appendChild( b );
			} );
			drawGoogle();
		}, function ( e ) {
			google.textContent = errMsg( e );
		} );
	}

	function panelTypography( hit ) {
		if ( ! S.typeEl ) {
			S.typeEl = 'heading';
		}
		syncStudioFonts();
		var ctl = h( 'div', { class: 'gs-st-controls' } );
		var redraw = function () {
			var top = ctl.scrollTop;
			ctl.textContent = '';
			ctl.appendChild( typographyControls( hit, redraw ) );
			ctl.scrollTop = top;
		};
		redraw();
		return h( 'div', { class: 'gs-st-split' }, ctl, previewPane() );
	}

	function parseSize( v ) {
		var m = typeof v === 'string' && v.trim().match( /^(-?[\d.]+)\s*(px|rem|em|vw|%)$/ );
		return m ? { n: parseFloat( m[ 1 ] ), u: m[ 2 ] } : null;
	}
	// A size as text: presets resolve to their size (the largest step of a fluid size).
	function sizeText( v ) {
		var slug = presetSlug( v, 'font-size' );
		if ( slug ) {
			var fs = fontSizes().filter( function ( x ) {
				return x.slug === slug;
			} )[ 0 ];
			if ( ! fs ) {
				return '';
			}
			var sz = fs.fluid && typeof fs.fluid === 'object' && fs.fluid.max ? fs.fluid.max : fs.size;
			return typeof sz === 'string' ? sz : '';
		}
		return typeof v === 'string' ? v : '';
	}
	function toPx( s ) {
		return s ? ( s.u === 'px' ? s.n : s.n * 16 ) : 16;
	}

	function typographyControls( hit, redraw ) {
		var el = TYPE_ELEMENTS.filter( function ( e ) {
			return e.id === S.typeEl;
		} )[ 0 ];
		var tp = el.base + '.typography.';
		var cur = function ( prop ) {
			return get( S.gs, tp + prop );
		};
		var inherit = function ( prop ) {
			var v = get( S.base, tp + prop );
			if ( v === undefined && el.parent ) {
				v = eff( 'styles.elements.heading.typography.' + prop );
			}
			if ( v === undefined && el.id !== 'p' ) {
				v = eff( 'styles.typography.' + prop );
			}
			return v;
		};
		var shown = function ( prop ) {
			var v = cur( prop );
			return v !== undefined ? v : inherit( prop );
		};
		var put = function ( prop, value, rerender ) {
			set( S.gs, tp + prop, value );
			changed();
			if ( rerender ) {
				redraw();
			}
		};
		var customisedEl = function ( e ) {
			return !! get( S.gs, e.id === 'p' ? 'styles.typography' : e.base );
		};

		// Which text style is being edited.
		var tabs = h( 'div', { class: 'gs-st-type-tabs', role: 'tablist', 'aria-label': __( 'Text styles', 'gend-society' ) }, TYPE_ELEMENTS.map( function ( e ) {
			return h( 'button', { type: 'button', role: 'tab', 'aria-selected': e.id === el.id ? 'true' : 'false', class: 'gs-st-type-tab' + ( e.id === el.id ? ' is-on' : '' ), onclick: function () {
				S.typeEl = e.id;
				redraw();
				guideScroll( true );
			} }, e.label, customisedEl( e ) ? h( 'i', { class: 'gs-st-dot is-custom' } ) : null );
		} ) );

		// A sample in the chosen settings (the preview frame shows the real site).
		var fam = familyBySlug( presetSlug( shown( 'fontFamily' ), 'font-family' ) );
		var sample = h( 'div', { class: 'gs-st-type-sample' }, el.sample );
		var styleSample = function () {
			var size = parseSize( sizeText( shown( 'fontSize' ) ) );
			sample.style.fontFamily = fam ? fam.fontFamily : '';
			sample.style.fontSize = size ? Math.min( toPx( size ), 56 ) + 'px' : '';
			sample.style.fontWeight = shown( 'fontWeight' ) || '';
			sample.style.fontStyle = shown( 'fontStyle' ) || '';
			sample.style.textTransform = shown( 'textTransform' ) || '';
			sample.style.textDecoration = shown( 'textDecoration' ) || '';
			sample.style.letterSpacing = shown( 'letterSpacing' ) || '';
			sample.style.lineHeight = shown( 'lineHeight' ) || '';
		};
		styleSample();

		// Font family.
		var famBtn = h( 'button', { type: 'button', class: 'gs-st-font-btn', onclick: function () {
			fontPickerModal( cur( 'fontFamily' ), function ( v ) {
				put( 'fontFamily', v, true );
			} );
		} }, h( 'span', { style: { 'font-family': fam ? fam.fontFamily : 'inherit' } }, cur( 'fontFamily' ) ? familyName( cur( 'fontFamily' ) ) : sprintf( __( 'Default (%s)', 'gend-society' ), fam ? fam.name : __( 'theme', 'gend-society' ) ) ), h( 'span', { class: 'gs-st-font-btn-hint' }, __( 'Change', 'gend-society' ), icon( 'arrow-down-alt2' ) ) );

		// Size: a number and unit, with a slider.
		var sz = parseSize( sizeText( cur( 'fontSize' ) ) );
		var szIn = parseSize( sizeText( inherit( 'fontSize' ) ) );
		var unit = h( 'select', { class: 'gs-st-input is-unit', 'aria-label': __( 'Unit', 'gend-society' ) }, [ 'px', 'rem', 'em', 'vw' ].map( function ( u ) {
			return h( 'option', { value: u, selected: ( sz ? sz.u : 'px' ) === u }, u );
		} ) );
		var num = h( 'input', { type: 'number', class: 'gs-st-input', step: 'any', min: '0', value: sz ? String( sz.n ) : '', placeholder: szIn ? sprintf( __( 'Default (%spx)', 'gend-society' ), String( Math.round( toPx( szIn ) * 10 ) / 10 ) ) : __( 'Default', 'gend-society' ), 'aria-label': __( 'Font size', 'gend-society' ) } );
		var range = h( 'input', { type: 'range', class: 'gs-st-range', min: '8', max: '120', step: '1', value: String( toPx( sz || szIn ) ), 'aria-label': __( 'Font size slider', 'gend-society' ) } );
		var applySize = function () {
			put( 'fontSize', num.value === '' ? '' : num.value + unit.value );
			styleSample();
		};
		num.addEventListener( 'input', debounce( function () {
			var n = parseFloat( num.value );
			if ( ! isNaN( n ) ) {
				range.value = String( unit.value === 'px' ? n : n * 16 );
			}
			applySize();
		}, 250 ) );
		unit.addEventListener( 'change', applySize );
		range.addEventListener( 'input', function () {
			var px = parseFloat( range.value );
			num.value = unit.value === 'px' ? String( px ) : String( Math.round( ( px / 16 ) * 100 ) / 100 );
			sample.style.fontSize = Math.min( px, 56 ) + 'px';
		} );
		range.addEventListener( 'change', applySize );

		// Weight: the weights the chosen font actually has, when it lists them.
		var weights = [ '100', '200', '300', '400', '500', '600', '700', '800', '900' ];
		if ( fam && fam.fontFace && fam.fontFace.length ) {
			var avail = {};
			fam.fontFace.forEach( function ( f ) {
				var parts = String( f.fontWeight || '400' ).split( ' ' );
				weights.forEach( function ( w ) {
					if ( parts.length === 2 ? +w >= +parts[ 0 ] && +w <= +parts[ 1 ] : w === parts[ 0 ] ) {
						avail[ w ] = 1;
					}
				} );
			} );
			var only = weights.filter( function ( w ) {
				return avail[ w ];
			} );
			if ( only.length ) {
				weights = only;
			}
		}
		var weightSel = h( 'select', { class: 'gs-st-input', 'aria-label': __( 'Weight', 'gend-society' ), onchange: function () {
			put( 'fontWeight', weightSel.value );
			styleSample();
		} }, h( 'option', { value: '' }, sprintf( __( 'Default (%s)', 'gend-society' ), inherit( 'fontWeight' ) || '400' ) ), weights.map( function ( w ) {
			return h( 'option', { value: w, selected: String( cur( 'fontWeight' ) ) === w }, w + ' · ' + WEIGHT_NAMES[ w ] );
		} ) );

		// Styling tools.
		var toggle = function ( ic, prop, onValue, offValue, title ) {
			var on = shown( prop ) === onValue;
			return h( 'button', { type: 'button', class: 'gs-st-tool' + ( on ? ' is-on' : '' ), 'aria-pressed': on ? 'true' : 'false', title: title, 'aria-label': title, onclick: function () {
				put( prop, on ? offValue : onValue, true );
			} }, icon( ic ) );
		};
		var caseBtn = function ( value, label, title ) {
			var on = ( shown( 'textTransform' ) || 'none' ) === value;
			return h( 'button', { type: 'button', class: 'gs-st-tool' + ( on ? ' is-on' : '' ), 'aria-pressed': on ? 'true' : 'false', title: title, 'aria-label': title, onclick: function () {
				put( 'textTransform', value, true );
			} }, h( 'span', { class: 'gs-st-tool-txt' }, label ) );
		};
		var tools = h( 'div', { class: 'gs-st-tools' },
			h( 'div', { class: 'gs-st-toolgroup' },
				toggle( 'editor-italic', 'fontStyle', 'italic', 'normal', __( 'Italic', 'gend-society' ) ),
				toggle( 'editor-underline', 'textDecoration', 'underline', 'none', __( 'Underline', 'gend-society' ) ),
				toggle( 'editor-strikethrough', 'textDecoration', 'line-through', 'none', __( 'Strikethrough', 'gend-society' ) )
			),
			h( 'div', { class: 'gs-st-toolgroup' },
				caseBtn( 'none', 'Aa', __( 'As typed', 'gend-society' ) ),
				caseBtn( 'uppercase', 'AB', __( 'Uppercase', 'gend-society' ) ),
				caseBtn( 'capitalize', 'Ab', __( 'Capitalize', 'gend-society' ) ),
				caseBtn( 'lowercase', 'ab', __( 'Lowercase', 'gend-society' ) )
			)
		);

		// Line height and letter spacing sliders.
		var slider = function ( label, prop, min, max, step, unitTxt ) {
			var raw = cur( prop );
			var base = inherit( prop );
			var val = parseFloat( raw !== undefined ? raw : base );
			if ( isNaN( val ) ) {
				val = min < 0 ? 0 : 1.4;
			}
			var out = h( 'span', { class: 'gs-st-slider-val' }, raw !== undefined ? String( raw ) : sprintf( __( 'Default (%s)', 'gend-society' ), base !== undefined ? String( base ) : '-' ) );
			var r = h( 'input', { type: 'range', class: 'gs-st-range', min: String( min ), max: String( max ), step: String( step ), value: String( val ), 'aria-label': label } );
			r.addEventListener( 'input', function () {
				out.textContent = r.value + unitTxt;
				sample.style[ prop ] = r.value + unitTxt;
			} );
			r.addEventListener( 'change', function () {
				put( prop, r.value + unitTxt );
			} );
			return h( 'div', { class: 'gs-st-field' }, h( 'span', { class: 'gs-st-slider-head' }, h( 'span', null, label ), out, raw !== undefined ? h( 'button', { type: 'button', class: 'gs-st-link-btn', onclick: function () {
				put( prop, '', true );
			} }, __( 'Reset', 'gend-society' ) ) : null ), r );
		};

		// Colours.
		var colorRow = function ( label, path ) {
			var c = get( S.gs, path );
			return h( 'div', { class: 'gs-st-field' }, h( 'span', null, label ), h( 'div', { class: 'gs-st-chips is-colors' }, h( 'button', { type: 'button', class: 'gs-st-chip' + ( ! c ? ' is-on' : '' ), onclick: function () {
				set( S.gs, path, '' );
				changed();
				redraw();
			} }, __( 'Default', 'gend-society' ) ), palette().map( function ( p ) {
				var v = 'var:preset|color|' + p.slug;
				return h( 'button', { type: 'button', class: 'gs-st-color-chip' + ( c === v ? ' is-on' : '' ), title: p.name, 'aria-label': p.name, style: { background: p.color }, onclick: function () {
					set( S.gs, path, v );
					changed();
					redraw();
				} } );
			} ) ) );
		};
		var colorBase = el.id === 'p' ? 'styles.color.' : el.base + '.color.';
		var colors = h( 'div', { class: 'gs-st-card is-flat gs-st-stack' }, colorRow( el.id === 'button' ? __( 'Text colour', 'gend-society' ) : __( 'Colour', 'gend-society' ), colorBase + 'text' ) );
		if ( el.id === 'button' ) {
			colors.appendChild( colorRow( __( 'Background', 'gend-society' ), colorBase + 'background' ) );
		}
		if ( el.id === 'a' || el.id === 'button' ) {
			colors.appendChild( colorRow( __( 'Hover colour', 'gend-society' ), el.base.split( '.' ).concat( [ ':hover', 'color', 'text' ] ) ) );
		}

		return h(
			'div',
			{ class: 'gs-st-stack' },
			h( 'div', null, crumbs( hit ), h( 'h1', { class: 'gs-st-title' }, hit.item.label ), h( 'p', { class: 'gs-st-intro' }, __( 'Pick a text style, then its font, size and styling. Google Fonts install on your site the moment you choose one.', 'gend-society' ) ) ),
			tabs,
			sample,
			h( 'div', { class: 'gs-st-card is-flat gs-st-stack' },
				h( 'div', { class: 'gs-st-field' }, h( 'span', null, __( 'Font', 'gend-society' ) ), famBtn ),
				h( 'div', { class: 'gs-st-field' }, h( 'span', null, __( 'Size', 'gend-society' ) ), h( 'div', { class: 'gs-st-size' }, num, unit ), range ),
				h( 'div', { class: 'gs-st-field' }, h( 'span', null, __( 'Weight', 'gend-society' ) ), weightSel ),
				h( 'div', { class: 'gs-st-field' }, h( 'span', null, __( 'Style', 'gend-society' ) ), tools ),
				slider( __( 'Line height', 'gend-society' ), 'lineHeight', 0.8, 2.6, 0.05, '' ),
				slider( __( 'Letter spacing', 'gend-society' ), 'letterSpacing', -3, 12, 0.1, 'px' )
			),
			colors,
			customisedEl( el ) ? h( 'button', { type: 'button', class: 'gs-st-btn is-ghost', onclick: function () {
				if ( el.id === 'p' ) {
					set( S.gs, 'styles.typography', '' );
					set( S.gs, 'styles.color.text', '' );
				} else {
					set( S.gs, el.base, '' );
				}
				changed();
				redraw();
			} }, icon( 'image-rotate' ), sprintf( __( 'Reset %s to the theme default', 'gend-society' ), el.label ) ) : null
		);
	}

	/* ── Templates in the block editor ─────────────────────────────────── */
	function editorUrl( path, extra ) {
		var q = 'p=' + encodeURIComponent( path );
		Object.keys( extra || {} ).forEach( function ( k ) {
			q += '&' + k + '=' + encodeURIComponent( extra[ k ] );
		} );
		return cfg.siteEditorUrl + '?' + q + '&gs_studio_embed=1';
	}
	function canvasUrl( t ) {
		return editorUrl( '/' + t.type + '/' + t.id, { canvas: 'edit' } );
	}
	// An embedded editor or settings screen, filling the content area (no studio bar above it).
	function frameView( label, url, opts ) {
		opts = opts || {};
		var frame = h( 'iframe', { class: 'gs-st-editor-frame', src: url, title: label, allow: 'clipboard-read; clipboard-write' } );
		var loader = h( 'div', { class: 'gs-st-frame-loader' }, h( 'span', { class: 'gs-st-spinner' } ), opts.loading || __( 'Opening the editor…', 'gend-society' ) );
		frame.addEventListener( 'load', function () {
			loader.classList.add( 'is-done' );
		} );
		return h( 'div', { class: 'gs-st-editor' }, h( 'div', { class: 'gs-st-editor-body' }, loader, frame ) );
	}
	function openEmbed( label, url ) {
		S.stylesStale = true;
		main.textContent = '';
		previewFrame = null;
		main.appendChild( frameView( label, url ) );
	}
	function emptyState( ic, title, body, actions ) {
		return h( 'div', { class: 'gs-st-empty' }, h( 'span', { class: 'gs-st-empty-ico' }, icon( ic ) ), h( 'h2', null, title ), body ? h( 'p', null, body ) : null, actions ? h( 'div', { class: 'gs-st-row is-center' }, actions ) : null );
	}
	function ensureTemplates() {
		return S.templatesLoaded ? Promise.resolve() : loadTemplates();
	}
	function viewTemplate( hit ) {
		var it = hit.item;
		if ( ! S.templatesLoaded ) {
			var wait = loadingView();
			ensureTemplates().then( function () {
				renderNav();
				render();
			}, function ( e ) {
				wait.textContent = errMsg( e );
			} );
			return wait;
		}
		var t = templateFor( it );
		if ( ! t ) {
			return viewMissing( hit );
		}
		return frameView( it.label, canvasUrl( t ) );
	}
	function viewMissing( hit ) {
		var it = hit.item;
		var fb = it.fallback ? S.templates[ it.fallback ] : null;
		var body = it.kind === 'part' ? __( 'This template part does not exist yet. Create it and it will be ready to design.', 'gend-society' ) : fb ? sprintf( __( 'Right now these pages use the "%s" template. Create a dedicated one to design them on their own.', 'gend-society' ), titleOf( fb ) ) : __( 'This template is provided when its app is active. Create a copy to design it.', 'gend-society' );
		var btn = h( 'button', { type: 'button', class: 'gs-st-btn is-primary', onclick: function () {
			btn.disabled = true;
			createTemplate( it, fb ).then( function () {
				return loadTemplates();
			} ).then( function () {
				renderNav();
				render();
				toast( sprintf( __( '%s created.', 'gend-society' ), it.label ), 'ok' );
			}, function ( e ) {
				btn.disabled = false;
				toast( errMsg( e ), 'error' );
			} );
		} }, icon( 'plus-alt2' ), sprintf( __( 'Create %s', 'gend-society' ), it.label ) );
		return h( 'div', { class: 'gs-st-pad' }, crumbs( hit ), emptyState( 'layout', sprintf( __( 'Design your %s', 'gend-society' ), it.label ), body, [ btn ] ) );
	}
	function createTemplate( it, fb ) {
		var seeds = {
			'add-to-cart': '<!-- wp:woocommerce/add-to-cart-form /-->',
		};
		var getContent = fb ? api( { path: '/wp/v2/templates/' + idPath( fb.id ) + '?context=edit&_fields=content' } ).then( function ( r ) {
			return ( r.content && r.content.raw ) || '';
		} ) : Promise.resolve( seeds[ it.slug ] || '<!-- wp:template-part {"slug":"header"} /--><!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} --><main class="wp-block-group"><!-- wp:post-content /--></main><!-- /wp:group --><!-- wp:template-part {"slug":"footer"} /-->' );
		return getContent.then( function ( content ) {
			var data = { slug: it.slug, title: it.label, content: content, status: 'publish' };
			if ( it.kind === 'part' ) {
				data.area = 'uncategorized';
				return api( { path: '/wp/v2/template-parts', method: 'POST', data: data } );
			}
			return api( { path: '/wp/v2/templates', method: 'POST', data: data } );
		} );
	}
	function viewTemplateList( hit ) {
		if ( ! S.templatesLoaded ) {
			var wait = loadingView();
			ensureTemplates().then( render );
			return wait;
		}
		var list = Object.keys( S.templates ).map( function ( k ) {
			return S.templates[ k ];
		} ).filter( function ( t ) {
			// Page templates: page, page-*, and the custom ones people add (wp-custom-template-*).
			return t.slug === hit.item.match || t.slug.indexOf( hit.item.match + '-' ) === 0 || t.slug.indexOf( 'wp-custom-template-' ) === 0;
		} ).filter( function ( t ) {
			// Plugin page templates are listed in their own section.
			return [ 'page-cart', 'page-checkout', 'page-affiliate-dashboard', 'page-team-dashboard', 'page-wallet' ].indexOf( t.slug ) === -1;
		} );
		var cards = h( 'div', { class: 'gs-st-tpl-grid' }, list.map( function ( t, i ) {
			var st = statusOf( t );
			return h( 'button', { type: 'button', class: 'gs-st-tpl', style: { '--i': i }, onclick: function () {
				main.textContent = '';
				main.appendChild( frameView( titleOf( t ), canvasUrl( t ) ) );
			} }, h( 'span', { class: 'gs-st-tpl-thumb' }, h( 'i' ), h( 'i' ), h( 'i' ) ), h( 'strong', null, titleOf( t ) ), h( 'small', null, t.description || t.slug ), h( 'span', { class: 'gs-st-badge is-' + st.key }, st.label ) );
		} ) );
		return h( 'div', { class: 'gs-st-pad' }, crumbs( hit ), h( 'h1', { class: 'gs-st-title' }, hit.item.label ), h( 'p', { class: 'gs-st-intro' }, __( 'Layouts you can pick for any page. Open one to design it.', 'gend-society' ) ), cards, h( 'div', { class: 'gs-st-row' }, h( 'button', { type: 'button', class: 'gs-st-btn is-primary', onclick: function () {
			newPageTemplate( hit );
		} }, icon( 'plus-alt2' ), __( 'New page template', 'gend-society' ) ) ) );
	}

	function promptBox( title, body, label, placeholder ) {
		return new Promise( function ( resolve ) {
			var input = h( 'input', { type: 'text', class: 'gs-st-input', placeholder: placeholder || '', 'aria-label': label } );
			var overlay = h( 'div', { class: 'gs-st-modal-wrap', role: 'dialog', 'aria-modal': 'true', 'aria-label': title } );
			var close = function ( v ) {
				overlay.remove();
				resolve( v );
			};
			var form = h( 'form', { class: 'gs-st-modal', onsubmit: function ( e ) {
				e.preventDefault();
				if ( input.value.trim() ) {
					close( input.value.trim() );
				}
			} }, h( 'h2', null, title ), h( 'p', null, body ), h( 'label', { class: 'gs-st-field' }, h( 'span', null, label ), input ), h( 'div', { class: 'gs-st-modal-actions' }, h( 'button', { type: 'button', class: 'gs-st-btn', onclick: function () {
				close( null );
			} }, __( 'Cancel', 'gend-society' ) ), h( 'button', { type: 'submit', class: 'gs-st-btn is-primary' }, __( 'Create', 'gend-society' ) ) ) );
			overlay.appendChild( form );
			overlay.addEventListener( 'click', function ( e ) {
				if ( e.target === overlay ) {
					close( null );
				}
			} );
			root.appendChild( overlay );
			input.focus();
		} );
	}
	function newPageTemplate( hit ) {
		promptBox( __( 'New page template', 'gend-society' ), __( 'Give it a name. It starts as a copy of your Pages template and can be picked for any page.', 'gend-society' ), __( 'Template name', 'gend-society' ), __( 'e.g. Landing page', 'gend-society' ) ).then( function ( name ) {
			if ( ! name ) {
				return;
			}
			var slug = 'wp-custom-template-' + name.toLowerCase().replace( /[^a-z0-9]+/g, '-' ).replace( /^-|-$/g, '' );
			var base = S.templates.page;
			( base ? api( { path: '/wp/v2/templates/' + idPath( base.id ) + '?context=edit&_fields=content' } ) : Promise.resolve( {} ) ).then( function ( r ) {
				return api( { path: '/wp/v2/templates', method: 'POST', data: { slug: slug, title: name, content: ( r.content && r.content.raw ) || '', status: 'publish' } } );
			} ).then( function ( t ) {
				return loadTemplates().then( function () {
					toast( sprintf( __( '%s created.', 'gend-society' ), name ), 'ok' );
					main.textContent = '';
					main.appendChild( frameView( name, canvasUrl( t ) ) );
				} );
			} ).catch( function ( e ) {
				toast( errMsg( e ), 'error' );
			} );
		} );
	}
	function viewEditRoute( type, id ) {
		var list = type === 'wp_template_part' ? S.parts : S.templates;
		var t = null;
		Object.keys( list ).some( function ( k ) {
			if ( list[ k ].id === id ) {
				t = list[ k ];
				return true;
			}
			return false;
		} );
		if ( ! t ) {
			return emptyState( 'warning', __( 'Template not found', 'gend-society' ), __( 'It may have been deleted. Pick a design area on the left.', 'gend-society' ) );
		}
		var link = linkForTemplate( t );
		if ( link ) {
			location.replace( link );
			return loadingView();
		}
		return frameView( titleOf( t ), canvasUrl( t ) );
	}

	/* ── Rows whose editor (or settings) slide down under them; one open at a time ── */
	function drawerSet() {
		var openWrap = null;
		function shut( w ) {
			w.classList.remove( 'is-open' );
			w.removeAttribute( 'data-open' );
			setTimeout( function () {
				if ( ! w.classList.contains( 'is-open' ) ) {
					w.querySelector( '.gs-st-tt-drawer-in' ).textContent = '';
				}
			}, 450 );
		}
		// Opens `id` in this row's drawer (head line + body), or closes it when it is already open.
		function toggle( wrap, id, head, body ) {
			if ( openWrap && openWrap !== wrap ) {
				shut( openWrap );
			}
			if ( wrap.getAttribute( 'data-open' ) === id ) {
				shut( wrap );
				openWrap = null;
				return;
			}
			var inner = wrap.querySelector( '.gs-st-tt-drawer-in' );
			inner.textContent = '';
			inner.appendChild( h( 'div', { class: 'gs-st-tt-drawer-head' }, head, h( 'button', { type: 'button', class: 'gs-st-btn is-sm is-ghost', onclick: function () {
				shut( wrap );
				openWrap = null;
			} }, icon( 'arrow-up-alt2' ), __( 'Close', 'gend-society' ) ) ) );
			inner.appendChild( body );
			wrap.classList.add( 'is-open' );
			wrap.setAttribute( 'data-open', id );
			openWrap = wrap;
			setTimeout( function () {
				wrap.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			}, 60 );
		}
		function toggleEditor( wrap, t ) {
			S.stylesStale = true;
			toggle( wrap, 'edit', [ icon( 'edit' ), h( 'span', null, sprintf( __( 'Editing "%s"', 'gend-society' ), titleOf( t ) ) ) ], frameView( titleOf( t ), canvasUrl( t ) ) );
		}
		return {
			toggle: toggle,
			toggleEditor: toggleEditor,
			wrap: function ( key, row ) {
				return h( 'div', { class: 'gs-st-tt-item', 'data-tt': key }, row, h( 'div', { class: 'gs-st-tt-drawer' }, h( 'div', { class: 'gs-st-tt-drawer-in' } ) ) );
			},
			editBtn: function ( getWrap, t ) {
				return h( 'button', { type: 'button', class: 'gs-st-btn is-sm gs-st-tt-edit', onclick: function () {
					toggleEditor( getWrap(), t );
				} }, icon( 'edit' ), __( 'Edit', 'gend-society' ) );
			},
			// A row is being replaced: it is no longer the open one.
			forget: function ( wrap ) {
				if ( openWrap === wrap ) {
					openWrap = null;
				}
			},
			// Re-draws the page (after a template was created) with that row's editor open.
			reopen: function ( key ) {
				render();
				var w = main.querySelector( '[data-tt="' + key + '"]' );
				var b = w && w.querySelector( '.gs-st-tt-edit' );
				if ( b ) {
					b.click();
				}
			},
		};
	}

	/* ── Category templates (Blog Archives, Product Categories & Attributes) ── */
	function loadTax( set ) {
		return api( { path: '/gend-society/v1/studio/tax-templates?set=' + set } ).then( function ( r ) {
			S.tax[ set ] = r;
		} );
	}
	// Templates a category can use: the group's own, its fallback, and every per-item one.
	function ttChoices( g ) {
		var pre = g.key === 'attribute' ? 'taxonomy-pa_' : g.prefix;
		return Object.keys( S.templates ).filter( function ( k ) {
			return k === g.base || k === g.fallback || k.indexOf( pre ) === 0;
		} ).map( function ( k ) {
			return S.templates[ k ];
		} );
	}
	// The template the archive renders with: the choice, else its own, else the group's, else the fallback.
	function ttAuto( g, it ) {
		var base = S.templates[ g.base ];
		// The store uses its own Products by Attribute template only once it is customised.
		if ( base && g.key === 'attribute' && base.source !== 'custom' ) {
			base = null;
		}
		return S.templates[ g.prefix + it.slug ] || base || S.templates[ g.fallback ] || null;
	}
	function ttEffective( g, it ) {
		return ( it.template && S.templates[ it.template ] ) || ttAuto( g, it );
	}
	function viewTaxTemplates( hit ) {
		var set = hit.item.set;
		if ( ! S.templatesLoaded || ! S.tax[ set ] ) {
			var wait = loadingView();
			Promise.all( [ ensureTemplates(), S.tax[ set ] ? null : loadTax( set ) ] ).then( render, function ( e ) {
				wait.textContent = errMsg( e );
			} );
			return wait;
		}
		var page = h( 'div', { class: 'gs-st-pad gs-st-tt' }, crumbs( hit ), h( 'h1', { class: 'gs-st-title' }, hit.item.label ), h( 'p', { class: 'gs-st-intro' }, set === 'store' ? __( 'The layout used for every product category and attribute page, and the ones that get their own.', 'gend-society' ) : __( 'The layout used for every blog category page, and the categories that get their own.', 'gend-society' ) ) );
		var d = drawerSet();
		var editBtn = d.editBtn;
		var reopen = d.reopen;
		var drawerWrap = d.wrap;
		function baseRow( g ) {
			var t = S.templates[ g.base ];
			var fb = S.templates[ g.fallback ];
			var wrap;
			var btn;
			if ( t ) {
				btn = editBtn( function () {
					return wrap;
				}, t );
			} else {
				btn = h( 'button', { type: 'button', class: 'gs-st-btn is-sm is-primary', onclick: function () {
					btn.disabled = true;
					createTemplate( { kind: 'template', slug: g.base, label: g.allLabel }, fb ).then( loadTemplates ).then( function () {
						toast( sprintf( __( '%s created.', 'gend-society' ), g.allLabel ), 'ok' );
						reopen( g.key + ':all' );
					}, function ( e ) {
						btn.disabled = false;
						toast( errMsg( e ), 'error' );
					} );
				} }, icon( 'plus-alt2' ), __( 'Create', 'gend-society' ) );
			}
			var st = statusOf( t );
			var note = t ? titleOf( t ) : fb ? sprintf( __( 'Uses "%s" until you create it', 'gend-society' ), titleOf( fb ) ) : '';
			wrap = drawerWrap( g.key + ':all', h( 'div', { class: 'gs-st-tt-row is-all' }, h( 'span', { class: 'gs-st-tpl-thumb' }, h( 'i' ), h( 'i' ), h( 'i' ) ), h( 'div', { class: 'gs-st-tt-name' }, h( 'strong', null, g.allLabel ), h( 'small', null, note ) ), h( 'span', { class: 'gs-st-badge is-' + st.key }, st.label ), btn ) );
			return wrap;
		}
		function itemRow( g, it ) {
			var own = g.prefix + it.slug;
			var eff = ttEffective( g, it );
			var auto = ttAuto( g, it );
			var key = g.key + ':' + it.id;
			var wrap;
			var sel = h( 'select', { class: 'gs-st-input', 'aria-label': sprintf( __( 'Template for %s', 'gend-society' ), it.name ) }, h( 'option', { value: '' }, sprintf( __( 'Automatic (%s)', 'gend-society' ), auto ? titleOf( auto ) : __( 'theme default', 'gend-society' ) ) ), ttChoices( g ).map( function ( t ) {
				return h( 'option', { value: t.slug, selected: t.slug === it.template }, titleOf( t ) );
			} ) );
			var assign = function ( slug ) {
				return api( { path: '/gend-society/v1/studio/tax-templates', method: 'POST', data: { group: g.key, id: it.id, template: slug } } ).then( function () {
					it.template = slug;
				} );
			};
			sel.addEventListener( 'change', function () {
				sel.disabled = true;
				assign( sel.value ).then( function () {
					d.forget( wrap );
					wrap.replaceWith( itemRow( g, it ) );
					toast( sprintf( __( '%s updated.', 'gend-society' ), it.name ), 'ok' );
				}, function ( e ) {
					sel.disabled = false;
					sel.value = it.template;
					toast( errMsg( e ), 'error' );
				} );
			} );
			var edit = eff ? editBtn( function () {
				return wrap;
			}, eff ) : null;
			var create = null;
			if ( ! S.templates[ own ] ) {
				create = h( 'button', { type: 'button', class: 'gs-st-btn is-sm is-ghost', title: sprintf( __( 'A new template just for %s, starting as a copy of the one it uses now', 'gend-society' ), it.name ), onclick: function () {
					create.disabled = true;
					var label = sprintf( '%1$s: %2$s', g.key === 'attribute' ? __( 'Attribute', 'gend-society' ) : __( 'Category', 'gend-society' ), it.name );
					createTemplate( { kind: 'template', slug: own, label: label }, eff ).then( function () {
						return assign( own );
					} ).then( loadTemplates ).then( function () {
						toast( sprintf( __( '%s created.', 'gend-society' ), label ), 'ok' );
						reopen( key );
					}, function ( e ) {
						create.disabled = false;
						toast( errMsg( e ), 'error' );
					} );
				} }, icon( 'plus-alt2' ), __( 'New template', 'gend-society' ) );
			}
			var count = g.key === 'attribute' ? sprintf( __( '%d values', 'gend-society' ), it.count ) : g.key === 'product_cat' ? sprintf( __( '%d products', 'gend-society' ), it.count ) : sprintf( __( '%d posts', 'gend-society' ), it.count );
			wrap = drawerWrap( key, h( 'div', { class: 'gs-st-tt-row' }, h( 'div', { class: 'gs-st-tt-name' }, h( 'strong', null, it.name ), h( 'small', null, count ) ), sel, edit, create ) );
			return wrap;
		}
		if ( ! S.tax[ set ].length ) {
			page.appendChild( emptyState( 'category', __( 'Nothing to design yet', 'gend-society' ), __( 'Categories appear here once the store is set up.', 'gend-society' ) ) );
		}
		S.tax[ set ].forEach( function ( g ) {
			var sub = g.key === 'attribute' ? __( 'Each attribute and its template', 'gend-society' ) : __( 'Each category and its template', 'gend-society' );
			page.appendChild( h( 'section', { class: 'gs-st-tt-group' }, h( 'h2', { class: 'gs-st-h2' }, g.label ), baseRow( g ), h( 'h3', { class: 'gs-st-tt-sub' }, sub ), g.items.length ? h( 'div', { class: 'gs-st-tt-list' }, g.items.map( function ( it ) {
				return itemRow( g, it );
			} ) ) : h( 'p', { class: 'gs-st-muted' }, __( 'None yet.', 'gend-society' ) ) ) );
		} );
		return page;
	}

	/* ── Product Templates: product page templates by type, category, attribute ── */
	function isProductTemplate( slug ) {
		return slug === 'single-product' || slug.indexOf( 'single-product-' ) === 0 || slug.indexOf( 'product-template-' ) === 0;
	}
	function viewProductTemplates( hit ) {
		if ( ! S.templatesLoaded || ! S.pt ) {
			var wait = loadingView();
			Promise.all( [ ensureTemplates(), S.pt ? null : api( { path: '/gend-society/v1/studio/product-templates' } ).then( function ( r ) {
				S.pt = r;
			} ) ] ).then( render, function ( e ) {
				wait.textContent = errMsg( e );
			} );
			return wait;
		}
		var d = drawerSet();
		var pt = S.pt;
		var by = {
			types: { label: __( 'Product types', 'gend-society' ), list: pt.types },
			cats: { label: __( 'Product categories', 'gend-society' ), list: pt.cats },
			attrs: { label: __( 'Product attributes', 'gend-society' ), list: pt.attrs },
		};
		var nameOf = function ( kind, id ) {
			var x = by[ kind ].list.filter( function ( o ) {
				return String( o.id ) === String( id );
			} )[ 0 ];
			return x ? x.name : String( id );
		};
		// Which template has this type / category / attribute now.
		var ownerOf = function ( kind, id ) {
			var out = '';
			Object.keys( pt.map ).some( function ( slug ) {
				if ( ( pt.map[ slug ][ kind ] || [] ).map( String ).indexOf( String( id ) ) !== -1 ) {
					out = slug;
					return true;
				}
				return false;
			} );
			return out;
		};
		var chips = function ( slug ) {
			var a = pt.map[ slug ] || {};
			var out = [];
			[ 'types', 'cats', 'attrs' ].forEach( function ( kind ) {
				( a[ kind ] || [] ).forEach( function ( id ) {
					out.push( h( 'span', { class: 'gs-st-pt-chip is-' + kind }, nameOf( kind, id ) ) );
				} );
			} );
			return out;
		};
		function assignForm( t, wrap ) {
			var a = pt.map[ t.slug ] || {};
			var boxes = [];
			var groups = [ 'types', 'cats', 'attrs' ].filter( function ( kind ) {
				return by[ kind ].list.length;
			} ).map( function ( kind ) {
				return h( 'fieldset', { class: 'gs-st-pt-set' }, h( 'legend', null, by[ kind ].label ), h( 'div', { class: 'gs-st-pt-opts' }, by[ kind ].list.map( function ( o ) {
					var owner = ownerOf( kind, o.id );
					var cb = h( 'input', { type: 'checkbox', checked: ( a[ kind ] || [] ).map( String ).indexOf( String( o.id ) ) !== -1 } );
					boxes.push( { kind: kind, id: o.id, cb: cb } );
					var other = owner && owner !== t.slug && S.templates[ owner ] ? titleOf( S.templates[ owner ] ) : '';
					return h( 'label', { class: 'gs-st-pt-opt', title: other ? sprintf( __( 'Now uses "%s"; ticking it moves it here.', 'gend-society' ), other ) : null }, cb, h( 'span', null, o.name ), other ? h( 'small', null, other ) : null );
				} ) ) );
			} );
			var save = h( 'button', { type: 'button', class: 'gs-st-btn is-primary is-sm', onclick: function () {
				var data = { template: t.slug, types: [], cats: [], attrs: [] };
				boxes.forEach( function ( b ) {
					if ( b.cb.checked ) {
						data[ b.kind ].push( b.id );
					}
				} );
				save.disabled = true;
				api( { path: '/gend-society/v1/studio/product-templates', method: 'POST', data: data } ).then( function ( r ) {
					pt.map = r.map;
					toast( sprintf( __( '%s assignments saved.', 'gend-society' ), titleOf( t ) ), 'ok' );
					render();
				}, function ( e ) {
					save.disabled = false;
					toast( errMsg( e ), 'error' );
				} );
			} }, icon( 'saved' ), __( 'Save', 'gend-society' ) );
			return h( 'div', { class: 'gs-st-pt-form' }, h( 'p', { class: 'gs-st-muted' }, __( 'Product pages use this template when the product is in a ticked category, has a ticked attribute, or is a ticked type (in that order). Each can belong to one template.', 'gend-society' ) ), groups, h( 'div', { class: 'gs-st-row' }, save ) );
		}
		function row( t, i ) {
			var key = 'pt:' + t.slug;
			var wrap;
			var isBase = t.slug === 'single-product';
			var assigned = chips( t.slug );
			var assign = isBase ? null : h( 'button', { type: 'button', class: 'gs-st-btn is-sm is-ghost', onclick: function () {
				d.toggle( wrap, 'assign', [ icon( 'tag' ), h( 'span', null, sprintf( __( 'Where "%s" is used', 'gend-society' ), titleOf( t ) ) ) ], assignForm( t, wrap ) );
			} }, icon( 'tag' ), __( 'Assign', 'gend-society' ) );
			var st = statusOf( t );
			var note = isBase ? h( 'small', null, __( 'Every product not covered by another template', 'gend-society' ) ) : assigned.length ? h( 'div', { class: 'gs-st-pt-chips' }, assigned ) : h( 'small', null, __( 'Not assigned', 'gend-society' ) );
			wrap = d.wrap( key, h( 'div', { class: 'gs-st-tt-row' + ( isBase ? ' is-all' : '' ), style: { '--i': i } }, isBase ? h( 'span', { class: 'gs-st-tpl-thumb' }, h( 'i' ), h( 'i' ), h( 'i' ) ) : null, h( 'div', { class: 'gs-st-tt-name' }, h( 'strong', null, titleOf( t ) ), note ), h( 'span', { class: 'gs-st-badge is-' + st.key }, st.label ), assign, d.editBtn( function () {
				return wrap;
			}, t ) ) );
			return wrap;
		}
		var list = Object.keys( S.templates ).filter( isProductTemplate ).sort( function ( a, b ) {
			return a === 'single-product' ? -1 : b === 'single-product' ? 1 : titleOf( S.templates[ a ] ).localeCompare( titleOf( S.templates[ b ] ) );
		} ).map( function ( k ) {
			return S.templates[ k ];
		} );
		var add = h( 'button', { type: 'button', class: 'gs-st-btn is-primary', onclick: function () {
			promptBox( __( 'New product template', 'gend-society' ), __( 'Give it a name. It starts as a copy of your Single Product template; then assign it to product types, categories or attributes.', 'gend-society' ), __( 'Template name', 'gend-society' ), __( 'e.g. Memberships', 'gend-society' ) ).then( function ( name ) {
				if ( ! name ) {
					return;
				}
				var slug = 'product-template-' + name.toLowerCase().replace( /[^a-z0-9]+/g, '-' ).replace( /^-|-$/g, '' );
				if ( S.templates[ slug ] ) {
					toast( __( 'A template with that name already exists.', 'gend-society' ), 'error' );
					return;
				}
				createTemplate( { kind: 'template', slug: slug, label: name }, S.templates[ 'single-product' ] ).then( loadTemplates ).then( function () {
					toast( sprintf( __( '%s created.', 'gend-society' ), name ), 'ok' );
					d.reopen( 'pt:' + slug );
				}, function ( e ) {
					toast( errMsg( e ), 'error' );
				} );
			} );
		} }, icon( 'plus-alt2' ), __( 'New product template', 'gend-society' ) );
		return h( 'div', { class: 'gs-st-pad gs-st-tt' }, crumbs( hit ), h( 'h1', { class: 'gs-st-title' }, hit.item.label ), h( 'p', { class: 'gs-st-intro' }, __( 'The layouts your product pages use, and which product types, categories and attributes each one is for. Edit opens the template builder.', 'gend-society' ) ), h( 'div', { class: 'gs-st-tt-list' }, list.map( row ) ), h( 'div', { class: 'gs-st-row', style: { 'margin-top': '18px' } }, add ) );
	}

	function viewMenus( hit ) {
		if ( ! S.menus ) {
			var wait = loadingView();
			api( { path: '/wp/v2/navigation?per_page=50&status=publish,draft&_fields=id,title,modified,status' } ).then( function ( r ) {
				S.menus = r;
				render();
			}, function ( e ) {
				wait.textContent = errMsg( e );
			} );
			return wait;
		}
		var cards = h( 'div', { class: 'gs-st-tpl-grid' }, S.menus.map( function ( m, i ) {
			return h( 'button', { type: 'button', class: 'gs-st-tpl', style: { '--i': i }, onclick: function () {
				main.textContent = '';
				main.appendChild( frameView( titleOf( m ) || __( 'Menu', 'gend-society' ), editorUrl( '/wp_navigation/' + m.id, { canvas: 'edit' } ) ) );
			} }, h( 'span', { class: 'gs-st-tpl-thumb is-menu' }, h( 'i' ), h( 'i' ), h( 'i' ) ), h( 'strong', null, titleOf( m ) || __( '(no title)', 'gend-society' ) ), h( 'small', null, new Date( m.modified ).toLocaleDateString() ) );
		} ) );
		return h( 'div', { class: 'gs-st-pad' }, crumbs( hit ), h( 'h1', { class: 'gs-st-title' }, hit.item.label ), h( 'p', { class: 'gs-st-intro' }, __( 'The navigation menus used in your header, footer and pages.', 'gend-society' ) ), S.menus.length ? cards : emptyState( 'menu', __( 'No menus yet', 'gend-society' ), __( 'Menus are created when you add a Navigation block to your header.', 'gend-society' ), [ h( 'a', { class: 'gs-st-btn is-primary', href: '#/app/header' }, __( 'Edit header', 'gend-society' ) ) ] ) );
	}

	function viewAdmin( hit ) {
		var url = hit.item.url + ( hit.item.url.indexOf( '?' ) === -1 ? '?' : '&' ) + 'gs_studio_embed=1';
		var hash = '';
		var hashAt = hit.item.url.indexOf( '#' );
		if ( hashAt !== -1 ) {
			hash = hit.item.url.slice( hashAt );
			url = hit.item.url.slice( 0, hashAt ) + ( hit.item.url.slice( 0, hashAt ).indexOf( '?' ) === -1 ? '?' : '&' ) + 'gs_studio_embed=1' + hash;
		}
		return frameView( hit.item.label, url, { loading: __( 'Loading settings…', 'gend-society' ) } );
	}

	/* ── Boot ──────────────────────────────────────────────────────────── */
	shell();
	if ( ! cfg.isBlockTheme ) {
		main.appendChild( emptyState( 'admin-appearance', __( 'The Design Studio works with block themes', 'gend-society' ), __( 'Your active theme is a classic theme. Switch to a block theme, such as GenD Society, to design your site here.', 'gend-society' ), [ h( 'a', { class: 'gs-st-btn is-primary', href: cfg.themesUrl }, __( 'Choose a theme', 'gend-society' ) ) ] ) );
		renderNav();
		return;
	}
	renderNav();
	main.appendChild( loadingView() );
	Promise.all( [ loadTemplates().catch( function () {} ), loadSettings().catch( function () {} ), loadStyles().catch( function () {} ) ] ).then( function () {
		renderNav();
		onRoute();
	} );
	window.addEventListener( 'hashchange', onRoute );
}() );
