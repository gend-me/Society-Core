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

	// ── 5. Edit in place ──────────────────────────────────────────────────
	// HTML sections show as the page looks; click a text to type in it, click
	// an image (or a background image) to replace it. Each change is written
	// back into the block's HTML. {{tokens}} and live parts are each member's
	// or group's own content, so they are left as they are.
	var INLINE = /^(A|B|STRONG|I|EM|U|S|SMALL|SPAN|BR|MARK|SUB|SUP|CODE|ABBR|TIME|DEL|INS|WBR)$/;
	var SKIP = 'script,style,svg,noscript,template,iframe,video,audio,canvas,select,textarea,input,[data-gs-live-part],[data-gs-live-block]';
	function parse( html ) {
		var tpl = document.createElement( 'template' );
		tpl.innerHTML = html;
		return tpl;
	}
	// The browser must read the HTML back exactly as written (same elements in
	// the same order), or writing it back could move things around.
	function roundTrips( html, tpl ) {
		var written = ( html.match( /<([a-zA-Z][\w-]*)/g ) || [] ).map( function ( t ) {
			return t.slice( 1 ).toLowerCase();
		} );
		var read = Array.prototype.map.call( tpl.content.querySelectorAll( '*' ), function ( n ) {
			return n.tagName.toLowerCase();
		} );
		return written.join( ',' ) === read.join( ',' ) && ! ( /<table/i.test( html ) && /\{\{[#/]/.test( html ) );
	}
	function hasOwnText( n ) {
		return Array.prototype.some.call( n.childNodes, function ( c ) {
			return c.nodeType === 3 && c.nodeValue.trim();
		} );
	}
	function textEditable( n ) {
		if ( INLINE.test( n.tagName ) && n.tagName !== 'A' && n.tagName !== 'SPAN' ) {
			return false;
		}
		if ( ! hasOwnText( n ) || n.innerHTML.indexOf( '{{' ) !== -1 ) {
			return false;
		}
		return Array.prototype.every.call( n.querySelectorAll( '*' ), function ( c ) {
			return INLINE.test( c.tagName );
		} );
	}
	var BG_URL = /url\(\s*(['"]?)([^'")]+)\1\s*\)/;
	// Marks what can be edited: data-gs-e="<n>" and data-gs-k = text | img | bg.
	function annotate( tpl ) {
		var n = 0;
		Array.prototype.forEach.call( tpl.content.querySelectorAll( '*' ), function ( el ) {
			if ( el.closest( SKIP ) || ( el.parentElement && el.parentElement.closest( '[data-gs-k="text"]' ) ) ) {
				return;
			}
			var kind = '';
			if ( el.tagName === 'IMG' ) {
				kind = ( el.getAttribute( 'src' ) || '' ).indexOf( '{{' ) === -1 && el.getAttribute( 'src' ) ? 'img' : '';
			} else if ( textEditable( el ) ) {
				kind = 'text';
			} else if ( BG_URL.test( el.getAttribute( 'style' ) || '' ) && ( el.getAttribute( 'style' ) || '' ).indexOf( '{{' ) === -1 ) {
				kind = 'bg';
			}
			if ( kind ) {
				el.setAttribute( 'data-gs-e', String( n++ ) );
				el.setAttribute( 'data-gs-k', kind );
			}
		} );
		return n;
	}
	function unmark( tpl ) {
		Array.prototype.forEach.call( tpl.content.querySelectorAll( '[data-gs-e]' ), function ( el ) {
			el.removeAttribute( 'data-gs-e' );
			el.removeAttribute( 'data-gs-k' );
			el.removeAttribute( 'contenteditable' );
		} );
		return tpl.innerHTML;
	}
	// Edited text keeps only the inline tags it already had.
	function cleanInline( node ) {
		Array.prototype.slice.call( node.querySelectorAll( '*' ) ).forEach( function ( c ) {
			if ( ! INLINE.test( c.tagName ) ) {
				c.replaceWith.apply( c, Array.prototype.slice.call( c.childNodes ) );
			}
		} );
		Array.prototype.forEach.call( node.querySelectorAll( '[data-gs-e]' ), function ( c ) {
			c.removeAttribute( 'data-gs-e' );
			c.removeAttribute( 'data-gs-k' );
		} );
		return node.innerHTML;
	}
	// One media picker for the page, pointed at whichever image was clicked last.
	var mediaFrame = null;
	var mediaPick = null;
	function pickMedia( title, onPick ) {
		if ( ! wp.media ) {
			notify( __( 'The media library could not load.', 'gend-society' ), 'error' );
			return;
		}
		mediaPick = onPick;
		if ( ! mediaFrame ) {
			mediaFrame = wp.media( { title: title, library: { type: 'image' }, multiple: false, button: { text: __( 'Use this image', 'gend-society' ) } } );
			mediaFrame.on( 'select', function () {
				var a = mediaFrame.state().get( 'selection' ).first();
				if ( a && mediaPick ) {
					mediaPick( a.toJSON() );
				}
			} );
		}
		mediaFrame.open();
	}

	function HtmlPreview( props ) {
		var content = props.content || '';
		var state = wp.element.useState( null );
		var html = state[ 0 ];
		var setHtml = state[ 1 ];
		var box = wp.element.useRef( null );
		// The block's HTML with edit marks, and whether it can be written back safely.
		var marked = wp.element.useMemo( function () {
			var tpl = parse( content );
			var safe = roundTrips( content, tpl );
			var count = safe ? annotate( tpl ) : 0;
			return { html: count ? tpl.innerHTML : content, safe: safe, count: count };
		}, [ content ] );
		wp.element.useEffect( function () {
			var stop = false;
			var live = /\{\{|data-gs-live-/.test( marked.html );
			if ( ! live ) {
				setHtml( marked.html );
				return undefined;
			}
			var t = setTimeout( function () {
				wp.apiFetch( { path: '/gend-society/v1/live-html/render', method: 'POST', data: { html: marked.html, postId: wp.data.select( 'core/editor' ).getCurrentPostId() } } ).then( function ( r ) {
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
		}, [ marked.html ] );

		// Write one change back into the block's HTML.
		var commit = function ( id, apply ) {
			var tpl = parse( content );
			annotate( tpl );
			var src = tpl.content.querySelector( '[data-gs-e="' + id + '"]' );
			if ( ! src ) {
				return;
			}
			apply( src );
			var next = unmark( tpl );
			if ( next !== content ) {
				props.onChange( next );
			}
		};

		// Make the marked parts of the rendered page editable.
		wp.element.useEffect( function () {
			var root = box.current;
			if ( ! root || html === null || ! marked.count ) {
				return undefined;
			}
			var doc = root.ownerDocument;
			Array.prototype.forEach.call( root.querySelectorAll( '[data-gs-k="text"]' ), function ( el ) {
				el.setAttribute( 'contenteditable', 'true' );
				el.setAttribute( 'spellcheck', 'true' );
				el.dataset.gsBefore = el.innerHTML;
			} );
			var onClick = function ( e ) {
				var t = e.target.closest && e.target.closest( '[data-gs-k]' );
				if ( ! t || ! root.contains( t ) ) {
					return;
				}
				var kind = t.getAttribute( 'data-gs-k' );
				if ( kind === 'text' ) {
					// Links and buttons inside the page don't navigate while editing.
					e.preventDefault();
					return;
				}
				if ( kind === 'bg' && t !== e.target && e.target.closest( '[data-gs-k="text"]' ) ) {
					return;
				}
				e.preventDefault();
				var id = t.getAttribute( 'data-gs-e' );
				pickMedia( __( 'Replace image', 'gend-society' ), function ( a ) {
					commit( id, function ( src ) {
						if ( kind === 'img' ) {
							src.setAttribute( 'src', a.url );
							src.removeAttribute( 'srcset' );
							src.removeAttribute( 'sizes' );
							if ( a.width && a.height && src.hasAttribute( 'width' ) ) {
								src.setAttribute( 'width', String( a.width ) );
								src.setAttribute( 'height', String( a.height ) );
							}
							if ( ! src.getAttribute( 'alt' ) && a.alt ) {
								src.setAttribute( 'alt', a.alt );
							}
						} else {
							src.setAttribute( 'style', ( src.getAttribute( 'style' ) || '' ).replace( BG_URL, 'url("' + a.url.replace( /["\\]/g, '' ) + '")' ) );
						}
					} );
					notify( __( 'Image replaced. Save the page to keep it.', 'gend-society' ) );
				} );
			};
			var onBlur = function ( e ) {
				var t = e.target;
				if ( ! t.getAttribute || t.getAttribute( 'data-gs-k' ) !== 'text' ) {
					return;
				}
				var after = t.innerHTML;
				if ( after === t.dataset.gsBefore ) {
					return;
				}
				var clean = doc.createElement( 'div' );
				clean.innerHTML = after;
				var value = cleanInline( clean );
				t.dataset.gsBefore = after;
				commit( t.getAttribute( 'data-gs-e' ), function ( src ) {
					src.innerHTML = value;
				} );
			};
			var onKey = function ( e ) {
				if ( ! e.target.isContentEditable ) {
					return;
				}
				// Keep typing inside the text: the editor's own shortcuts (delete block, arrows between blocks) stay out.
				e.stopPropagation();
				if ( e.key === 'Enter' && ! e.shiftKey ) {
					e.preventDefault();
					e.target.blur();
				} else if ( e.key === 'Escape' ) {
					e.target.innerHTML = e.target.dataset.gsBefore;
					e.target.blur();
				}
			};
			var onPaste = function ( e ) {
				if ( ! e.target.isContentEditable || ! e.clipboardData ) {
					return;
				}
				e.preventDefault();
				e.stopPropagation();
				doc.execCommand( 'insertText', false, e.clipboardData.getData( 'text/plain' ) );
			};
			root.addEventListener( 'click', onClick );
			root.addEventListener( 'focusout', onBlur );
			root.addEventListener( 'keydown', onKey );
			root.addEventListener( 'paste', onPaste );
			return function () {
				root.removeEventListener( 'click', onClick );
				root.removeEventListener( 'focusout', onBlur );
				root.removeEventListener( 'keydown', onKey );
				root.removeEventListener( 'paste', onPaste );
			};
		}, [ html, marked ] );

		var blockProps = wp.blockEditor.useBlockProps( { className: 'gs-lh-preview' + ( marked.count ? ' is-editable' : '' ) } );
		return el( 'div', blockProps,
			props.isSelected ? el( 'div', { className: 'gs-lh-hint' }, marked.count
				? __( 'Click any text to edit it, or an image to replace it. Use “Edit HTML” in the toolbar for everything else.', 'gend-society' )
				: ( marked.safe ? __( 'Nothing here can be edited in place. Use “Edit HTML” in the toolbar.', 'gend-society' ) : __( 'This section’s HTML can only be changed as code. Use “Edit HTML” in the toolbar.', 'gend-society' ) ) ) : null,
			html === null
				? el( 'div', { className: 'gs-lh-loading' }, __( 'Rendering…', 'gend-society' ) )
				: el( 'div', { ref: box, className: 'gs-lh-body', dangerouslySetInnerHTML: { __html: html } } ) );
	}

	// Custom HTML sections: the page as it looks, editable in place; the code on request.
	function EditableHtml( props ) {
		var mode = wp.element.useState( false );
		var code = mode[ 0 ];
		var setCode = mode[ 1 ];
		var BC = wp.blockEditor.BlockControls;
		var TB = wp.components.ToolbarButton;
		var toggle = el( BC, { group: 'other' }, el( TB, { icon: code ? 'visibility' : 'html', onClick: function () {
			setCode( ! code );
		} }, code ? __( 'Visual', 'gend-society' ) : __( 'Edit HTML', 'gend-society' ) ) );
		if ( code && props.isSelected ) {
			return el( wp.element.Fragment, null,
				el( 'div', { className: 'gs-lh-hint' }, /\{\{|data-gs-live-/.test( props.attributes.content || '' )
					? __( 'Live HTML: {{tokens}} fill in each group’s or member’s details; data-gs-live parts stay live.', 'gend-society' )
					: __( 'Editing the HTML. Switch back to Visual to edit in place.', 'gend-society' ) ),
				toggle,
				el( props.BlockEdit, props ) );
		}
		return el( wp.element.Fragment, null,
			props.isSelected ? toggle : null,
			el( HtmlPreview, { content: props.attributes.content, isSelected: props.isSelected, onChange: function ( next ) {
				props.setAttributes( { content: next } );
			} } ) );
	}

	wp.hooks.addFilter( 'editor.BlockEdit', 'gend-society/studio-live-html', wp.compose.createHigherOrderComponent( function ( BlockEdit ) {
		return function ( props ) {
			if ( props.name === 'core/html' && ( props.attributes.content || '' ).trim() ) {
				return el( EditableHtml, Object.assign( {}, props, { BlockEdit: BlockEdit } ) );
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
		// "Edit as HTML" switches to the code editor, and WordPress saves that
		// choice for every editor. Studio editors always open visually.
		if ( editorMode() === 'text' ) {
			switchMode( 'visual' );
		}
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
