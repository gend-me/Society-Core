/* GenD Society - live previews for the Social block templates (inc/studio-previews.php).
   1. Blocks that stand for already-built content show the real page (a live frame).
   2. The editor canvas gets the live page's stylesheets, so blocks look as on the site. */
( function ( wp ) {
	'use strict';
	var cfg = window.gsStudioPreview;
	if ( ! wp || ! cfg ) {
		return;
	}
	var el = wp.element.createElement;
	var __ = wp.i18n.__;

	function withQuery( url, q ) {
		return url ? url + ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + q : '';
	}

	// ── 1. Live frames ────────────────────────────────────────────────────
	function LiveFrame( props ) {
		var ref = wp.element.useRef( null );
		var state = wp.element.useState( 240 );
		var height = state[ 0 ];
		var setHeight = state[ 1 ];
		wp.element.useEffect( function () {
			var frame = ref.current;
			if ( ! frame ) {
				return undefined;
			}
			var timer = null;
			var measure = function () {
				try {
					var doc = frame.contentDocument;
					if ( doc && doc.documentElement ) {
						setHeight( Math.max( 120, Math.min( 4000, doc.documentElement.scrollHeight ) ) );
					}
				} catch ( e ) {}
			};
			var onLoad = function () {
				measure();
				var n = 0;
				clearInterval( timer );
				timer = setInterval( function () {
					measure();
					if ( ++n > 20 ) {
						clearInterval( timer );
					}
				}, 500 );
			};
			frame.addEventListener( 'load', onLoad );
			return function () {
				frame.removeEventListener( 'load', onLoad );
				clearInterval( timer );
			};
		}, [ props.src ] );
		var blockProps = wp.blockEditor.useBlockProps();
		if ( ! props.src ) {
			return el( 'div', blockProps, el( 'div', { className: 'gs-pv-label' }, props.label, el( 'span', null, props.empty || __( 'Nothing to preview yet.', 'gend-society' ) ) ) );
		}
		return el( 'div', blockProps,
			el( 'div', { className: 'gs-pv-label' }, props.label, el( 'span', null, __( 'Live preview', 'gend-society' ) ) ),
			el( 'div', { className: 'gs-pv-wrap' },
				el( 'iframe', { ref: ref, className: 'gs-pv-frame', src: props.src, title: props.label, style: { height: height + 'px' }, loading: 'lazy' } )
			)
		);
	}

	var tab = cfg.tabSlug;
	var previews = {
		'gend-society/profile-header-classic': function () {
			return { label: __( 'GenD Profile Header', 'gend-society' ), src: withQuery( cfg.profileUrl, 'gs_studio_view=profile-header' ) };
		},
		'gend-society/profile-tab-content': function () {
			return { label: tab ? __( 'Profile tab content', 'gend-society' ) + ': ' + tab : __( 'Profile tab content', 'gend-society' ), src: tab && cfg.profileUrl ? withQuery( cfg.profileUrl + tab + '/', 'gdc_tab_only=1' ) : '', empty: __( 'Shows the tab’s content on profiles.', 'gend-society' ) };
		},
		'gend-society/original-content': function () {
			var url = cfg.directories && cfg.directories[ cfg.layoutSlug ];
			return { label: __( 'Original page content', 'gend-society' ), src: withQuery( url, 'gs_studio_view=original' ) };
		},
		'gend-society/original-sidebar': function () {
			return { label: __( 'Current group sidebar', 'gend-society' ) + ( cfg.groupName ? ': ' + cfg.groupName : '' ), src: withQuery( cfg.groupUrl, 'gs_studio_view=group-sidebar' ) };
		},
		'gdc-blocks/endpoint-panel': function ( attrs ) {
			return { label: __( 'Group content', 'gend-society' ) + ': ' + ( attrs.slug || '' ) + ( cfg.groupName ? ' · ' + cfg.groupName : '' ), src: attrs.slug ? withQuery( cfg.groupUrl, 'gs_studio_view=group-endpoint&slug=' + encodeURIComponent( attrs.slug ) ) : '' };
		},
	};

	wp.hooks.addFilter( 'editor.BlockEdit', 'gend-society/studio-previews', wp.compose.createHigherOrderComponent( function ( BlockEdit ) {
		return function ( props ) {
			var make = previews[ props.name ];
			if ( ! make ) {
				return el( BlockEdit, props );
			}
			var p = make( props.attributes || {} );
			return el( LiveFrame, { src: p.src, label: p.label, empty: p.empty } );
		};
	}, 'withGsLivePreview' ) );

	// ── 2. Live stylesheets in the canvas ─────────────────────────────────
	function styleSource() {
		return isGroupType() ? withQuery( cfg.groupUrl, 'gs_studio_view=group-header' ) : '';
	}
	var styles = null;
	var GLOBAL_RULE = /(^|[}\s,])(html|body|:root|\*)\s*[,{.:#\[]/;
	function isGroupType() {
		var type = cfg.postType;
		return type === 'gdc_group_header' || type === 'gdc_group_page';
	}
	// Only the group header and group page templates need the live header's
	// styles (their blocks are the header cards). Copy only its own scoped
	// rules, never page-wide ones (background, layout), in a framed or an
	// unframed canvas alike, and add the live wrapper around the blocks.
	function inject() {
		if ( ! styles || ! isGroupType() ) {
			return;
		}
		var canvas = document.querySelector( 'iframe[name="editor-canvas"]' );
		var doc = canvas && canvas.contentDocument && canvas.contentDocument.head ? canvas.contentDocument : document;
		var root = doc.querySelector( '.is-root-container' );
		if ( root && ! root.classList.contains( 'bp-bridge-header-container' ) ) {
			root.classList.add( 'bp-bridge-header-container', 'is-ready', 'gs-pv-group-root' );
		}
		if ( doc.head.querySelector( '[data-gs-live]' ) ) {
			return;
		}
		styles.forEach( function ( node ) {
			var keep = node.tagName === 'LINK' ? /psoo-bp|projects\/assets/.test( node.getAttribute( 'href' ) || '' ) : ! GLOBAL_RULE.test( node.textContent );
			if ( keep ) {
				var copy = doc === document ? node.cloneNode( true ) : doc.importNode( node, true );
				copy.setAttribute( 'data-gs-live', '1' );
				doc.head.appendChild( copy );
			}
		} );
	}
	// ── 3. "Add a section" under the template, whatever its blocks are ────
	function ensureAddButton() {
		var canvas = document.querySelector( 'iframe[name="editor-canvas"]' );
		var doc = canvas && canvas.contentDocument && canvas.contentDocument.body ? canvas.contentDocument : document;
		var root = doc.querySelector( '.is-root-container' );
		if ( ! root || ! root.parentNode || root.parentNode.querySelector( ':scope > .gs-pv-add' ) ) {
			return;
		}
		var btn = doc.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'gs-pv-add';
		btn.textContent = '+ ' + __( 'Add a section', 'gend-society' );
		btn.addEventListener( 'click', function () {
			var count = wp.data.select( 'core/block-editor' ).getBlockCount();
			wp.data.dispatch( 'core/block-editor' ).clearSelectedBlock();
			wp.data.dispatch( 'core/editor' ).setIsInserterOpened( { rootClientId: undefined, insertionIndex: count } );
		} );
		root.parentNode.insertBefore( btn, root.nextSibling );
	}

	wp.domReady( function () {
		var addQueued = false;
		new window.MutationObserver( function () {
			if ( ! addQueued ) {
				addQueued = true;
				window.requestAnimationFrame( function () {
					addQueued = false;
					ensureAddButton();
				} );
			}
		} ).observe( document.body, { childList: true, subtree: true } );
		ensureAddButton();
	} );

	wp.domReady( function () {
		var src = styleSource();
		if ( ! src ) {
			return;
		}
		window.fetch( src, { credentials: 'same-origin' } ).then( function ( r ) {
			return r.text();
		} ).then( function ( html ) {
			var parsed = new window.DOMParser().parseFromString( html, 'text/html' );
			styles = Array.prototype.slice.call( parsed.querySelectorAll( 'link[rel="stylesheet"], style' ) ).filter( function ( n ) {
				// Keep the page's own styles; skip the preview page's background reset.
				return ! ( n.tagName === 'STYLE' && n.textContent.indexOf( '.gs-pv-pad' ) !== -1 );
			} );
			inject();
			// The canvas frame can be (re)created later: keep it styled (checks are cheap; throttled to a frame).
			var queued = false;
			new window.MutationObserver( function () {
				if ( ! queued ) {
					queued = true;
					window.requestAnimationFrame( function () {
						queued = false;
						inject();
					} );
				}
			} ).observe( document.body, { childList: true, subtree: true } );
		} ).catch( function () {} );
	} );
}( window.wp ) );
