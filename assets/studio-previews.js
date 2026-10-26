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
		var html = doc.createElement( 'button' );
		html.type = 'button';
		html.className = 'gs-pv-add is-html';
		html.textContent = '</> ' + __( 'Edit page as HTML', 'gend-society' );
		html.addEventListener( 'click', function () {
			convertPage( 'text' );
		} );
		btn.parentNode.insertBefore( html, btn.nextSibling );
		var site = layoutPageUrl();
		if ( site ) {
			var view = doc.createElement( 'button' );
			view.type = 'button';
			view.className = 'gs-pv-add is-site';
			view.textContent = '↗ ' + __( 'Preview this layout on the site (saved version, only you see it)', 'gend-society' );
			view.addEventListener( 'click', function () {
				var id = wp.data.select( 'core/editor' ).getCurrentPostId();
				window.open( withQuery( site, 'gs_layout_preview=' + id ), '_blank', 'noopener' );
			} );
			html.parentNode.insertBefore( view, html.nextSibling );
		}
	}

	// The real page a Directory Layout applies to (for "Preview on the site").
	function layoutPageUrl() {
		if ( cfg.postType !== 'gdc_bp_layout' ) {
			return '';
		}
		var slug = cfg.layoutSlug;
		if ( cfg.directories && cfg.directories[ slug ] ) {
			return cfg.directories[ slug ];
		}
		if ( slug === 'profile-sidebar' ) {
			return cfg.profileUrl;
		}
		if ( slug === 'group-sidebar' ) {
			return cfg.groupUrl;
		}
		if ( slug === 'group-feed' && cfg.groupUrl ) {
			return cfg.groupUrl + 'home/';
		}
		return '';
	}

	// ── 4. Edit as HTML ───────────────────────────────────────────────────
	// Server-rendered blocks store no HTML (the code editor only shows their
	// block comments). Converting one gives a Custom HTML block with its real
	// markup and {{tokens}} for each group's / member's own values; parts
	// that run code stay live (data-gs-live-part / data-gs-live-block).
	var LIVE_PREFIX = /^(gdc-blocks|gend-society)\//;
	function convertible( block ) {
		return LIVE_PREFIX.test( block.name ) && ! ( block.innerBlocks && block.innerBlocks.length );
	}
	function collect( blocks, out ) {
		( blocks || [] ).forEach( function ( b ) {
			if ( convertible( b ) ) {
				out.push( b );
			} else {
				collect( b.innerBlocks, out );
			}
		} );
		return out;
	}
	function editorMode() {
		var ed = wp.data.select( 'core/editor' );
		if ( ed && ed.getEditorMode ) {
			return ed.getEditorMode();
		}
		var ep = wp.data.select( 'core/edit-post' );
		return ep && ep.getEditorMode ? ep.getEditorMode() : 'visual';
	}
	function switchMode( mode ) {
		var ed = wp.data.dispatch( 'core/editor' );
		if ( ed && ed.switchEditorMode ) {
			ed.switchEditorMode( mode );
		} else if ( wp.data.dispatch( 'core/edit-post' ) ) {
			wp.data.dispatch( 'core/edit-post' ).switchEditorMode( mode );
		}
	}
	function notify( msg, type ) {
		var n = wp.data.dispatch( 'core/notices' );
		if ( n ) {
			n.createNotice( type || 'success', msg, { type: 'snackbar', isDismissible: true } );
		}
	}
	function convertBlocks( blocks, thenMode ) {
		var back = editorMode();
		if ( ! blocks.length ) {
			if ( thenMode ) {
				switchMode( thenMode );
			}
			return Promise.resolve( 0 );
		}
		if ( back !== 'visual' ) {
			switchMode( 'visual' );
		}
		return wp.apiFetch( { path: '/gend-society/v1/live-html/convert', method: 'POST', data: { blocks: blocks.map( function ( b ) {
			return { name: b.name, attributes: b.attributes };
		} ) } } ).then( function ( res ) {
			var be = wp.data.dispatch( 'core/block-editor' );
			var live = 0;
			blocks.forEach( function ( b, i ) {
				var r = res[ i ];
				if ( r && r.html ) {
					live += r.live ? 1 : 0;
					be.replaceBlock( b.clientId, wp.blocks.createBlock( 'core/html', { content: r.html } ) );
				}
			} );
			switchMode( thenMode || back );
			notify( live
				? __( 'Converted to HTML. App and feed sections stay live (data-gs-live-block): their content comes from the app itself.', 'gend-society' )
				: __( 'Converted to HTML. {{tokens}} fill in each group’s or member’s own details.', 'gend-society' ) );
			return blocks.length;
		} ).catch( function ( e ) {
			switchMode( back );
			notify( ( e && e.message ) || __( 'Could not convert.', 'gend-society' ), 'error' );
		} );
	}
	function convertPage( thenMode ) {
		return convertBlocks( collect( wp.data.select( 'core/block-editor' ).getBlocks(), [] ), thenMode );
	}

	function HtmlPreview( props ) {
		var state = wp.element.useState( null );
		var html = state[ 0 ];
		var setHtml = state[ 1 ];
		wp.element.useEffect( function () {
			var stop = false;
			var t = setTimeout( function () {
				wp.apiFetch( { path: '/gend-society/v1/live-html/render', method: 'POST', data: { html: props.content, postId: wp.data.select( 'core/editor' ).getCurrentPostId() } } ).then( function ( r ) {
					if ( ! stop ) {
						setHtml( r.html );
					}
				} ).catch( function () {
					if ( ! stop ) {
						setHtml( '' );
					}
				} );
			}, 250 );
			return function () {
				stop = true;
				clearTimeout( t );
			};
		}, [ props.content ] );
		var blockProps = wp.blockEditor.useBlockProps( { className: 'gs-lh-preview' } );
		return el( 'div', blockProps, html === null
			? el( 'div', { className: 'gs-lh-loading' }, __( 'Rendering…', 'gend-society' ) )
			: el( 'div', { dangerouslySetInnerHTML: { __html: html } } ) );
	}

	wp.hooks.addFilter( 'editor.BlockEdit', 'gend-society/studio-live-html', wp.compose.createHigherOrderComponent( function ( BlockEdit ) {
		return function ( props ) {
			if ( props.name === 'core/html' && /\{\{|data-gs-live-/.test( props.attributes.content || '' ) ) {
				return props.isSelected
					? el( wp.element.Fragment, null,
						el( 'div', { className: 'gs-lh-hint' }, __( 'Live HTML: {{tokens}} fill in each group’s or member’s details; data-gs-live parts stay live. Click outside the block to preview it.', 'gend-society' ) ),
						el( BlockEdit, props ) )
					: el( HtmlPreview, { content: props.attributes.content } );
			}
			if ( ! props.isSelected || ! LIVE_PREFIX.test( props.name ) ) {
				return el( BlockEdit, props );
			}
			var block = wp.data.select( 'core/block-editor' ).getBlock( props.clientId );
			if ( ! block || ! convertible( block ) ) {
				return el( BlockEdit, props );
			}
			return el( wp.element.Fragment, null,
				el( BlockEdit, props ),
				el( wp.blockEditor.BlockControls, { group: 'other' },
					el( wp.components.ToolbarButton, { icon: 'html', label: __( 'Edit as HTML', 'gend-society' ), onClick: function () {
						convertBlocks( [ wp.data.select( 'core/block-editor' ).getBlock( props.clientId ) ] );
					} }, __( 'Edit as HTML', 'gend-society' ) ) )
			);
		};
	}, 'withGsLiveHtml' ) );

	// The code editor: say why server-rendered sections show no HTML, and convert them.
	var TOKENS_HELP = '{{group.name}} {{group.excerpt}} {{group.description}} {{group.url}} {{group.admin_url}} {{group.avatar}} {{group.initial}} {{group.status_label}} {{group.status_color}} {{group.members}} {{group.last_active}} {{app.name}} {{app.url}} {{app.connect_url}} {{pm.name}} {{pm.url}} {{pm.avatar}} {{proposal.title}} {{proposal.url}} {{member.name}} {{member.username}} {{member.url}} {{member.avatar}} {{member.cover}} {{member.type}} {{member.bio_html}} {{site.name}} {{site.url}} {{user.name}}';
	var FLAGS_HELP = '{{#if pm.id}} … {{else}} … {{/if}}   {{#unless app.connected}} … {{/unless}}   — can.manage, app.connected, pm.can_chat, invest.active, group.is_current, member.is_me, user.logged_in';
	function ensureCodeBanner() {
		var box = document.querySelector( '.editor-text-editor' );
		var banner = document.querySelector( '.gs-lh-banner' );
		if ( ! box ) {
			if ( banner ) {
				banner.remove();
			}
			return;
		}
		var count = collect( wp.data.select( 'core/block-editor' ).getBlocks(), [] ).length;
		if ( banner ) {
			banner.querySelector( '.gs-lh-count' ).textContent = count ? String( count ) : '';
			banner.classList.toggle( 'is-done', ! count );
			return;
		}
		banner = document.createElement( 'div' );
		banner.className = 'gs-lh-banner' + ( count ? '' : ' is-done' );
		var p = document.createElement( 'p' );
		var strong = document.createElement( 'strong' );
		strong.className = 'gs-lh-count';
		strong.textContent = count ? String( count ) : '';
		p.appendChild( strong );
		p.appendChild( document.createTextNode( ' ' + __( 'section(s) here are built on the server, so they store no HTML, only a block comment. Convert them to see and edit their real HTML.', 'gend-society' ) ) );
		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'gs-lh-btn';
		btn.textContent = __( 'Convert to editable HTML', 'gend-society' );
		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			convertPage( 'text' ).then( function () {
				btn.disabled = false;
			} );
		} );
		var help = document.createElement( 'details' );
		var sum = document.createElement( 'summary' );
		sum.textContent = __( 'Tokens you can use in HTML', 'gend-society' );
		help.appendChild( sum );
		help.appendChild( document.createElement( 'code' ) ).textContent = TOKENS_HELP;
		help.appendChild( document.createElement( 'code' ) ).textContent = FLAGS_HELP;
		banner.appendChild( p );
		banner.appendChild( btn );
		banner.appendChild( help );
		box.parentNode.insertBefore( banner, box );
	}

	wp.domReady( function () {
		var addQueued = false;
		new window.MutationObserver( function () {
			if ( ! addQueued ) {
				addQueued = true;
				window.requestAnimationFrame( function () {
					addQueued = false;
					ensureAddButton();
					ensureCodeBanner();
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
