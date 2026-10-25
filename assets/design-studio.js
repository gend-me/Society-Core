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
			document.body.appendChild( overlay );
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
			el.textContent = S.previewCss;
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

	function previewPane( opts ) {
		opts = opts || {};
		var url = S.previewMode === 'home' ? cfg.homeUrl : cfg.specimenUrl;
		previewFrame = h( 'iframe', { class: 'gs-st-preview-frame', title: __( 'Live preview', 'gend-society' ), src: url, loading: 'lazy' } );
		previewFrame.addEventListener( 'load', injectPreview );
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
			h( 'div', { class: 'gs-st-segs' }, seg( 'specimen', __( 'Style guide', 'gend-society' ) ), seg( 'home', __( 'Home page', 'gend-society' ) ) ),
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
	var nav, main, searchInput, current = { section: '', item: '' };
	var collapsed = {};
	try {
		collapsed = JSON.parse( window.localStorage.getItem( 'gsStudioCollapsed' ) || '{}' ) || {};
	} catch ( e ) {
		collapsed = {};
	}

	function shell() {
		root.textContent = '';
		root.removeAttribute( 'aria-busy' );
		searchInput = h( 'input', { type: 'search', class: 'gs-st-search', placeholder: __( 'Search designs…', 'gend-society' ), 'aria-label': __( 'Search designs', 'gend-society' ), oninput: function () {
			renderNav();
		}, onkeydown: function ( e ) {
			if ( e.key === 'Enter' ) {
				var first = nav.querySelector( '.gs-st-nav-item' );
				if ( first ) {
					first.click();
				}
			}
			if ( e.key === 'Escape' ) {
				searchInput.value = '';
				renderNav();
			}
		} } );
		var top = h(
			'header',
			{ class: 'gs-st-top' },
			h( 'div', { class: 'gs-st-brand' }, h( 'span', { class: 'gs-st-brand-mark' }, icon( 'art' ) ), h( 'span', { class: 'gs-st-brand-text' }, h( 'strong', null, __( 'Design Studio', 'gend-society' ) ), h( 'small', null, cfg.themeName ) ) ),
			h( 'div', { class: 'gs-st-search-wrap' }, icon( 'search' ), searchInput, h( 'kbd', null, navigator.platform.indexOf( 'Mac' ) === 0 ? '⌘K' : 'Ctrl K' ) ),
			saveSlot,
		);
		nav = h( 'nav', { class: 'gs-st-nav', 'aria-label': __( 'Design areas', 'gend-society' ) } );
		main = h( 'main', { class: 'gs-st-main', tabindex: '-1' } );
		root.appendChild( h( 'div', { class: 'gs-st-app' }, top, h( 'div', { class: 'gs-st-body' }, nav, main ) ) );
		document.addEventListener( 'keydown', function ( e ) {
			if ( ( e.ctrlKey || e.metaKey ) && e.key.toLowerCase() === 'k' ) {
				e.preventDefault();
				searchInput.focus();
				searchInput.select();
			}
		} );
	}

	function renderNav() {
		nav.textContent = '';
		var q = ( searchInput.value || '' ).trim().toLowerCase();
		nav.appendChild( h( 'a', { class: 'gs-st-nav-home' + ( ! current.section ? ' is-active' : '' ), href: '#/', title: __( 'Overview', 'gend-society' ) }, h( 'span', { class: 'gs-st-nav-ico' }, icon( 'dashboard' ) ), h( 'span', { class: 'gs-st-nav-home-label' }, __( 'Overview', 'gend-society' ) ) ) );
		activeSections.forEach( function ( section ) {
			var items = allItems( section );
			var counted = items.filter( function ( it ) {
				return it.kind === 'template' || it.kind === 'part';
			} );
			var custom = counted.filter( function ( it ) {
				return statusOf( templateFor( it ) ).key === 'custom';
			} ).length;
			var open = q ? true : ! collapsed[ section.id ];
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
			var any = false;
			section.groups.forEach( function ( g ) {
				var gi = g.items.filter( function ( it ) {
					return ! q || it.label.toLowerCase().indexOf( q ) !== -1 || section.label.toLowerCase().indexOf( q ) !== -1 || ( g.label || '' ).toLowerCase().indexOf( q ) !== -1;
				} );
				if ( ! gi.length ) {
					return;
				}
				any = true;
				if ( g.label ) {
					list.appendChild( h( 'div', { class: 'gs-st-nav-group' }, g.label ) );
				}
				gi.forEach( function ( it ) {
					var st = ( it.kind === 'template' || it.kind === 'part' ) && S.templatesLoaded ? statusOf( templateFor( it ) ) : null;
					list.appendChild( h(
						'a',
						{ class: 'gs-st-nav-item' + ( current.section === section.id && current.item === it.id ? ' is-active' : '' ), href: '#/' + section.id + '/' + it.id },
						h( 'span', null, it.label ),
						st && st.key !== 'theme' && st.key !== 'plugin' ? h( 'span', { class: 'gs-st-dot is-' + st.key, title: st.label } ) : null
					) );
				} );
			} );
			if ( q && ! any ) {
				return;
			}
			nav.appendChild( h( 'div', { class: 'gs-st-nav-block' }, head, open ? list : null ) );
		} );
		if ( lockedSections.length && ! q ) {
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
		var needsStyles = p === 'palettes' || p === 'fonts' || p.indexOf( 'type:' ) === 0;
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
		if ( p === 'fonts' ) {
			return panelFonts( hit );
		}
		return panelType( hit, p.slice( 5 ) );
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

	function familySelect( value, onChange, label ) {
		var sel = h( 'select', { class: 'gs-st-input', 'aria-label': label, onchange: function () {
			onChange( sel.value ? 'var:preset|font-family|' + sel.value : '' );
		} }, h( 'option', { value: '' }, __( 'Theme default', 'gend-society' ) ) );
		var slug = presetSlug( value, 'font-family' );
		fontFamilies().forEach( function ( f ) {
			sel.appendChild( h( 'option', { value: f.slug, selected: f.slug === slug }, f.name ) );
		} );
		return sel;
	}
	function panelFonts( hit ) {
		var row = function ( label, path, sample ) {
			var cur = get( S.gs, path );
			var shown = h( 'div', { class: 'gs-st-font-sample', style: { 'font-family': ( fontFamilies().filter( function ( f ) {
				return f.slug === presetSlug( eff( path ), 'font-family' );
			} )[ 0 ] || {} ).fontFamily || 'inherit' } }, sample );
			return h( 'div', { class: 'gs-st-card is-flat gs-st-font-row' }, h( 'div', { class: 'gs-st-font-head' }, h( 'strong', null, label ), h( 'small', null, cur ? familyName( cur ) : sprintf( __( 'Theme default (%s)', 'gend-society' ), familyName( get( S.base, path ) ) || __( 'system', 'gend-society' ) ) ) ), shown, familySelect( cur, function ( v ) {
				set( S.gs, path, v );
				changed();
				render();
			}, label ) );
		};
		var lib = fontFamilies();
		var controls = h(
			'div',
			{ class: 'gs-st-stack' },
			row( __( 'Headings', 'gend-society' ), 'styles.elements.heading.typography.fontFamily', __( 'Big ideas, bold headlines', 'gend-society' ) ),
			row( __( 'Body text', 'gend-society' ), 'styles.typography.fontFamily', __( 'Comfortable reading for every paragraph on your site.', 'gend-society' ) ),
			row( __( 'Buttons', 'gend-society' ), 'styles.elements.button.typography.fontFamily', __( 'Get started', 'gend-society' ) ),
			h( 'div', { class: 'gs-st-card is-flat' }, h( 'h3', null, sprintf( __( 'Your font library (%d)', 'gend-society' ), lib.length ) ), h( 'div', { class: 'gs-st-font-chips' }, lib.map( function ( f ) {
				return h( 'span', { class: 'gs-st-chip', style: { 'font-family': f.fontFamily } }, f.name );
			} ) ), h( 'a', { class: 'gs-st-btn is-ghost', href: '#/branding/fonts', onclick: function ( e ) {
				e.preventDefault();
				openEmbed( __( 'Font library', 'gend-society' ), editorUrl( '/styles', { section: '/typography' } ) );
			} }, icon( 'plus-alt2' ), __( 'Add or upload fonts', 'gend-society' ) ) )
		);
		return panelLayout( hit, __( 'Choose the typefaces for headings, body text and buttons. Each heading level and the link style can be tuned under Typography.', 'gend-society' ), controls );
	}

	var WEIGHTS = [ '', '100', '200', '300', '400', '500', '600', '700', '800', '900' ];
	function panelType( hit, el ) {
		var base;
		var colorPath;
		if ( el === 'p' ) {
			base = 'styles';
			colorPath = 'styles.color.text';
		} else if ( el === 'a' ) {
			base = 'styles.elements.link';
			colorPath = 'styles.elements.link.color.text';
		} else {
			base = 'styles.elements.' + el;
			colorPath = base + '.color.text';
		}
		var tp = base + '.typography.';
		var inherit = function ( prop ) {
			if ( el.charAt( 0 ) === 'h' ) {
				var v = eff( base + '.typography.' + prop );
				return v !== undefined ? v : eff( 'styles.elements.heading.typography.' + prop );
			}
			return eff( tp + prop );
		};
		var field = function ( label, prop, input ) {
			return h( 'label', { class: 'gs-st-field' }, h( 'span', null, label ), input );
		};
		var text = function ( prop, ph ) {
			var cur = get( S.gs, tp + prop );
			var inp = h( 'input', { type: 'text', class: 'gs-st-input', value: cur || '', placeholder: ph || ( inherit( prop ) !== undefined ? String( inherit( prop ) ) : '' ), oninput: debounce( function () {
				set( S.gs, tp + prop, inp.value.trim() );
				changed();
			}, 250 ) } );
			return inp;
		};
		var select = function ( prop, opts ) {
			var cur = get( S.gs, tp + prop ) || '';
			var sel = h( 'select', { class: 'gs-st-input', onchange: function () {
				set( S.gs, tp + prop, sel.value );
				changed();
			} } );
			opts.forEach( function ( o ) {
				sel.appendChild( h( 'option', { value: o[ 0 ], selected: o[ 0 ] === cur }, o[ 1 ] ) );
			} );
			return sel;
		};
		var sizeChips = h( 'div', { class: 'gs-st-chips' }, fontSizes().map( function ( fs ) {
			var v = 'var:preset|font-size|' + fs.slug;
			return h( 'button', { type: 'button', class: 'gs-st-chip' + ( get( S.gs, tp + 'fontSize' ) === v ? ' is-on' : '' ), title: typeof fs.size === 'string' ? fs.size : '', onclick: function () {
				set( S.gs, tp + 'fontSize', get( S.gs, tp + 'fontSize' ) === v ? '' : v );
				changed();
				render();
			} }, fs.name || fs.slug );
		} ) );
		var colorChips = function ( path ) {
			var cur = get( S.gs, path );
			return h( 'div', { class: 'gs-st-chips is-colors' }, h( 'button', { type: 'button', class: 'gs-st-chip' + ( ! cur ? ' is-on' : '' ), onclick: function () {
				set( S.gs, path, '' );
				changed();
				render();
			} }, __( 'Default', 'gend-society' ) ), palette().map( function ( c ) {
				var v = 'var:preset|color|' + c.slug;
				return h( 'button', { type: 'button', class: 'gs-st-color-chip' + ( cur === v ? ' is-on' : '' ), title: c.name, 'aria-label': c.name, style: { background: c.color }, onclick: function () {
					set( S.gs, path, v );
					changed();
					render();
				} } );
			} ) );
		};
		var famCur = get( S.gs, tp + 'fontFamily' );
		var controls = h( 'div', { class: 'gs-st-stack' } );
		if ( el !== 'a' ) {
			controls.appendChild( h( 'div', { class: 'gs-st-card is-flat' },
				field( __( 'Font', 'gend-society' ), 'fontFamily', familySelect( famCur, function ( v ) {
					set( S.gs, tp + 'fontFamily', v );
					changed();
				}, __( 'Font', 'gend-society' ) ) ),
				h( 'div', { class: 'gs-st-field' }, h( 'span', null, __( 'Size', 'gend-society' ) ), sizeChips, text( 'fontSize', __( 'or a value like 2.5rem or clamp(2rem, 5vw, 3.5rem)', 'gend-society' ) ) ),
				h( 'div', { class: 'gs-st-grid2' },
					field( __( 'Weight', 'gend-society' ), 'fontWeight', select( 'fontWeight', WEIGHTS.map( function ( w ) {
						return [ w, w || __( 'Default', 'gend-society' ) ];
					} ) ) ),
					field( __( 'Line height', 'gend-society' ), 'lineHeight', text( 'lineHeight' ) ),
					field( __( 'Letter spacing', 'gend-society' ), 'letterSpacing', text( 'letterSpacing' ) ),
					field( __( 'Case', 'gend-society' ), 'textTransform', select( 'textTransform', [ [ '', __( 'Default', 'gend-society' ) ], [ 'none', __( 'As typed', 'gend-society' ) ], [ 'uppercase', __( 'UPPERCASE', 'gend-society' ) ], [ 'lowercase', __( 'lowercase', 'gend-society' ) ], [ 'capitalize', __( 'Capitalized', 'gend-society' ) ] ] ) )
				)
			) );
		}
		controls.appendChild( h( 'div', { class: 'gs-st-card is-flat' }, h( 'div', { class: 'gs-st-field' }, h( 'span', null, el === 'a' ? __( 'Link colour', 'gend-society' ) : __( 'Colour', 'gend-society' ) ), colorChips( colorPath ) ) ) );
		if ( el === 'a' ) {
			controls.appendChild( h( 'div', { class: 'gs-st-card is-flat' },
				h( 'div', { class: 'gs-st-field' }, h( 'span', null, __( 'Hover colour', 'gend-society' ) ), colorChips( [ 'styles', 'elements', 'link', ':hover', 'color', 'text' ] ) ),
				h( 'div', { class: 'gs-st-grid2' },
					field( __( 'Underline', 'gend-society' ), 'textDecoration', select( 'textDecoration', [ [ '', __( 'Default', 'gend-society' ) ], [ 'underline', __( 'Underlined', 'gend-society' ) ], [ 'none', __( 'No underline', 'gend-society' ) ] ] ) ),
					h( 'label', { class: 'gs-st-field' }, h( 'span', null, __( 'Underline on hover', 'gend-society' ) ), ( function () {
						var path = [ 'styles', 'elements', 'link', ':hover', 'typography', 'textDecoration' ];
						var cur = get( S.gs, path ) || '';
						var sel = h( 'select', { class: 'gs-st-input', onchange: function () {
							set( S.gs, path, sel.value );
							changed();
						} } );
						[ [ '', __( 'Default', 'gend-society' ) ], [ 'underline', __( 'Underlined', 'gend-society' ) ], [ 'none', __( 'No underline', 'gend-society' ) ] ].forEach( function ( o ) {
							sel.appendChild( h( 'option', { value: o[ 0 ], selected: o[ 0 ] === cur }, o[ 1 ] ) );
						} );
						return sel;
					}() ) )
				)
			) );
		}
		var anyCustom = !! get( S.gs, base === 'styles' ? 'styles.typography' : base );
		if ( anyCustom ) {
			controls.appendChild( h( 'button', { type: 'button', class: 'gs-st-btn is-ghost', onclick: function () {
				if ( base === 'styles' ) {
					set( S.gs, 'styles.typography', '' );
					set( S.gs, 'styles.color.text', '' );
				} else {
					set( S.gs, base, '' );
				}
				changed();
				render();
			} }, icon( 'image-rotate' ), __( 'Reset to theme default', 'gend-society' ) ) );
		}
		var intro = el === 'p' ? __( 'The type most of your content is set in.', 'gend-society' ) : el === 'a' ? __( 'How links look inside your text.', 'gend-society' ) : sprintf( __( 'How every %s heading looks across your site.', 'gend-society' ), hit.item.label );
		return panelLayout( hit, intro, controls );
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
	function frameView( hit, url, headExtra, opts ) {
		opts = opts || {};
		var frame = h( 'iframe', { class: 'gs-st-editor-frame', src: url, title: hit.item.label, allow: 'clipboard-read; clipboard-write' } );
		var loader = h( 'div', { class: 'gs-st-frame-loader' }, h( 'span', { class: 'gs-st-spinner' } ), opts.loading || __( 'Opening the editor…', 'gend-society' ) );
		frame.addEventListener( 'load', function () {
			loader.classList.add( 'is-done' );
		} );
		return h( 'div', { class: 'gs-st-editor' }, h( 'div', { class: 'gs-st-editor-head' }, crumbs( hit ), h( 'div', { class: 'gs-st-editor-actions' }, headExtra || null ) ), h( 'div', { class: 'gs-st-editor-body' }, loader, frame ) );
	}
	function openEmbed( label, url ) {
		S.stylesStale = true;
		main.textContent = '';
		previewFrame = null;
		main.appendChild( frameView( { section: { label: __( 'Branding', 'gend-society' ) }, item: { label: label } }, url ) );
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
		var st = statusOf( t );
		var extra = [ h( 'span', { class: 'gs-st-badge is-' + st.key }, st.label ) ];
		if ( it.preview ) {
			extra.push( h( 'a', { class: 'gs-st-btn is-ghost is-sm', href: it.preview, target: '_blank', rel: 'noopener' }, icon( 'visibility' ), __( 'Preview', 'gend-society' ) ) );
		}
		return frameView( hit, canvasUrl( t ), extra );
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
				main.appendChild( frameView( { section: hit.section, item: { label: titleOf( t ) } }, canvasUrl( t ), [ h( 'a', { class: 'gs-st-btn is-ghost is-sm', href: '#/' + hit.section.id + '/' + hit.item.id }, icon( 'arrow-left-alt2' ), __( 'All page templates', 'gend-society' ) ) ] ) );
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
			document.body.appendChild( overlay );
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
					main.appendChild( frameView( { section: hit.section, item: { label: name } }, canvasUrl( t ), [ h( 'a', { class: 'gs-st-btn is-ghost is-sm', href: '#/' + hit.section.id + '/' + hit.item.id }, icon( 'arrow-left-alt2' ), __( 'All page templates', 'gend-society' ) ) ] ) );
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
		return frameView( { section: { label: type === 'wp_template_part' ? __( 'Template parts', 'gend-society' ) : __( 'Templates', 'gend-society' ) }, item: { label: titleOf( t ) } }, canvasUrl( t ) );
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
				main.appendChild( frameView( { section: hit.section, item: { label: titleOf( m ) || __( 'Menu', 'gend-society' ) } }, editorUrl( '/wp_navigation/' + m.id, { canvas: 'edit' } ), [ h( 'a', { class: 'gs-st-btn is-ghost is-sm', href: '#/' + hit.section.id + '/' + hit.item.id }, icon( 'arrow-left-alt2' ), __( 'All menus', 'gend-society' ) ) ] ) );
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
		return frameView( hit, url, [ h( 'span', { class: 'gs-st-badge is-plugin', title: __( 'These pages are drawn by the app. A block template version is on the way.', 'gend-society' ) }, __( 'App settings', 'gend-society' ) ) ], { loading: __( 'Loading settings…', 'gend-society' ) } );
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
