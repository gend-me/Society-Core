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
			var stopEdit = null;
			var onLoad = function () {
				measure();
				if ( stopEdit ) {
					stopEdit();
				}
				stopEdit = props.editRef ? editWords( frame, props.editRef ) : null;
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
				if ( stopEdit ) {
					stopEdit();
				}
			};
		}, [ props.src ] );
		var blockProps = wp.blockEditor.useBlockProps();
		if ( ! props.src ) {
			return el( 'div', blockProps, el( 'div', { className: 'gs-pv-label' }, props.label, el( 'span', null, props.empty || __( 'Nothing to preview yet.', 'gend-society' ) ) ) );
		}
		return el( 'div', blockProps,
			el( 'div', { className: 'gs-pv-label' }, props.label, el( 'span', null, props.editRef ? __( 'Live preview · click any text to edit it', 'gend-society' ) : __( 'Live preview', 'gend-society' ) ) ),
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
			var texts = attrs.gsTexts && Object.keys( attrs.gsTexts ).length ? '&gs_texts=' + encodeURIComponent( JSON.stringify( attrs.gsTexts ) ) : '';
			return { label: __( 'Group content', 'gend-society' ) + ': ' + ( attrs.slug || '' ) + ( cfg.groupName ? ' · ' + cfg.groupName : '' ), src: attrs.slug ? withQuery( cfg.groupUrl, 'gs_studio_view=group-endpoint&slug=' + encodeURIComponent( attrs.slug ) + texts ) : '', wording: true };
		},
	};

	// App sections can't become HTML (the app renders them): their words are renamed instead (inc/app-wording.php).
	var WORDING_BLOCKS = [ 'gdc-blocks/endpoint-panel' ];
	wp.hooks.addFilter( 'blocks.registerBlockType', 'gend-society/app-wording', function ( settings, name ) {
		if ( WORDING_BLOCKS.indexOf( name ) !== -1 ) {
			settings.attributes = Object.assign( {}, settings.attributes, { gsTexts: { type: 'object', default: {} } } );
		}
		return settings;
	} );
	var wordCache = {};
	// The words the app shows: tabs, buttons, headings, labels, field hints (not its data).
	function appWords( src ) {
		if ( ! wordCache[ src ] ) {
			wordCache[ src ] = window.fetch( src, { credentials: 'same-origin' } ).then( function ( r ) {
				return r.text();
			} ).then( function ( html ) {
				var doc = new window.DOMParser().parseFromString( html, 'text/html' );
				var root = doc.querySelector( '.gs-pv-pad' ) || doc.body;
				var seen = {};
				var out = [];
				var add = function ( t ) {
					t = ( t || '' ).replace( /\s+/g, ' ' ).trim();
					if ( t.length > 1 && t.length <= 400 && /[a-z]/i.test( t ) && ! seen[ t ] && out.length < 200 ) {
						seen[ t ] = 1;
						out.push( t );
					}
				};
				// Every text the section shows, top to bottom (headers first).
				var walker = doc.createTreeWalker( root, window.NodeFilter.SHOW_TEXT );
				var node;
				while ( ( node = walker.nextNode() ) ) {
					var p = node.parentElement;
					if ( p && ! p.closest( 'script,style,textarea,noscript,svg,template' ) ) {
						add( node.nodeValue );
					}
				}
				Array.prototype.forEach.call( root.querySelectorAll( '[placeholder]' ), function ( f ) {
					add( f.getAttribute( 'placeholder' ) );
				} );
				return out;
			} );
		}
		return wordCache[ src ];
	}
	function Wording( props ) {
		var st = wp.element.useState( null );
		var src = props.src;
		// Selecting the section shows its Wording panel (the sidebar may be on the page tab or closed).
		wp.element.useEffect( function () {
			var ep = wp.data.dispatch( 'core/edit-post' );
			if ( ep && ep.openGeneralSidebar ) {
				ep.openGeneralSidebar( 'edit-post/block' );
			}
		}, [] );
		wp.element.useEffect( function () {
			if ( src ) {
				appWords( src ).then( st[ 1 ], function () {
					st[ 1 ]( [] );
				} );
			}
		}, [ src ] );
		var C = wp.components;
		var cur = props.attributes.gsTexts || {};
		var body;
		if ( ! src ) {
			body = el( 'p', null, __( 'Choose the group content first.', 'gend-society' ) );
		} else if ( ! st[ 0 ] ) {
			body = el( C.Spinner );
		} else if ( ! st[ 0 ].length ) {
			body = el( 'p', null, __( 'No words to rename.', 'gend-society' ) );
		} else {
			body = st[ 0 ].map( function ( t ) {
				var long = t.length > 60;
				return el( long ? C.TextareaControl : C.TextControl, { key: t, label: long ? t.slice( 0, 60 ) + '…' : t, value: cur[ t ] ? plainText( cur[ t ] ) : '', placeholder: t, rows: long ? 3 : undefined, __nextHasNoMarginBottom: true, __next40pxDefaultSize: long ? undefined : true, onChange: function ( v ) {
					var next = Object.assign( {}, cur );
					if ( v.trim() ) {
						next[ t ] = escText( v );
					} else {
						delete next[ t ];
					}
					props.setAttributes( { gsTexts: next } );
				} } );
			} );
		}
		return el( wp.blockEditor.InspectorControls, null,
			el( C.PanelBody, { title: __( 'Wording', 'gend-society' ), initialOpen: true },
				el( 'p', null, __( 'This section is a live app. Click any text in the preview to type over it and change its font, size or colour, or rename it here: headings, descriptions, tabs, buttons and field hints, top to bottom. Leave a box empty to keep the original. The app keeps working.', 'gend-society' ) ),
				body
			)
		);
	}

	function plainText( html ) {
		return new window.DOMParser().parseFromString( '<body>' + html, 'text/html' ).body.textContent;
	}
	function escText( t ) {
		return String( t ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
	}
	var AW_INLINE = /^(A|B|STRONG|I|EM|U|S|SMALL|SPAN|BR|MARK|SUB|SUP|FONT)$/;
	var AW_SKIP = 'script,style,textarea,noscript,svg,template,select,option,input,[contenteditable="false"]';
	// Click a text in the app preview to type over it; the text toolbar styles it.
	// Each text the section shows is wrapped in <span data-gs-aw="shown text"> in
	// the preview only (renamed ones come wrapped from the server), and a change
	// is written to gsTexts as "shown text" => inline HTML.
	function editWords( frame, ctxRef ) {
		var doc = frame.contentDocument;
		if ( ! doc || ! doc.body ) {
			return null;
		}
		var win = doc.defaultView;
		var host = frame.ownerDocument.defaultView;
		var kit = window.gsHtmlVisualKit;
		var root = doc.querySelector( '.gs-pv-pad' ) || doc.body;
		var cleanup = [];
		var on = function ( type, fn, opts ) {
			doc.addEventListener( type, fn, opts );
			cleanup.push( function () {
				doc.removeEventListener( type, fn, opts );
			} );
		};
		var css = doc.createElement( 'style' );
		css.textContent = ( kit ? kit.css : '' ) + '[data-gs-aw]{cursor:text;border-radius:3px;outline:1px dashed transparent;outline-offset:2px}[data-gs-aw]:hover{outline-color:rgba(151,228,110,.7)}[data-gs-aw]:focus{outline:2px solid #97e46e;outline-offset:2px}';
		doc.head.appendChild( css );
		var walker = doc.createTreeWalker( root, win.NodeFilter.SHOW_TEXT );
		var found = [];
		var node;
		while ( ( node = walker.nextNode() ) ) {
			var key = node.nodeValue.replace( /\s+/g, ' ' ).trim();
			var p = node.parentElement;
			if ( key.length > 1 && /[a-z]/i.test( key ) && p && ! p.closest( AW_SKIP ) && ! p.closest( '[data-gs-aw]' ) ) {
				found.push( [ node, key ] );
			}
		}
		found.forEach( function ( f ) {
			var span = doc.createElement( 'span' );
			span.setAttribute( 'data-gs-aw', f[ 1 ] );
			f[ 0 ].parentNode.insertBefore( span, f[ 0 ] );
			span.appendChild( f[ 0 ] );
		} );
		Array.prototype.forEach.call( root.querySelectorAll( '[data-gs-aw]' ), function ( t ) {
			t.setAttribute( 'data-gs-k', 'text' );
			t.setAttribute( 'contenteditable', 'true' );
			t.setAttribute( 'spellcheck', 'true' );
			t.dataset.gsBefore = t.innerHTML;
		} );
		// Only the text's inline styling is kept (what the toolbar makes).
		var commit = function ( t ) {
			var after = t.innerHTML;
			if ( after === t.dataset.gsBefore ) {
				return;
			}
			var key = t.getAttribute( 'data-gs-aw' );
			var box = doc.createElement( 'div' );
			box.innerHTML = after;
			Array.prototype.slice.call( box.querySelectorAll( '*' ) ).reverse().forEach( function ( c ) {
				if ( ! AW_INLINE.test( c.tagName ) ) {
					c.replaceWith.apply( c, Array.prototype.slice.call( c.childNodes ) );
					return;
				}
				Array.prototype.slice.call( c.attributes ).forEach( function ( a ) {
					if ( ! /^(style|href|face|color)$/.test( a.name ) ) {
						c.removeAttribute( a.name );
					}
				} );
				// The browser writes colours as rgb(), which WordPress's CSS filter drops: hex instead.
				if ( c.getAttribute( 'style' ) ) {
					c.setAttribute( 'style', c.getAttribute( 'style' ).replace( /rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)(?:[,\s/]+([\d.]+%?))?\s*\)/gi, function ( m, r, g, b, a ) {
						var alpha = a === undefined ? 1 : ( /%$/.test( a ) ? parseFloat( a ) / 100 : parseFloat( a ) );
						return '#' + [ r, g, b ].concat( alpha < 1 ? [ Math.round( alpha * 255 ) ] : [] ).map( function ( n ) {
							return ( '0' + Math.min( 255, +n ).toString( 16 ) ).slice( -2 );
						} ).join( '' );
					} ) );
				}
			} );
			var plain = box.textContent.replace( /\s+/g, ' ' ).trim();
			if ( ! plain ) {
				// Emptied: back to the app's own text.
				t.textContent = key;
			}
			t.dataset.gsBefore = t.innerHTML;
			var same = ! plain || ( plain === key && ! box.querySelector( '*' ) );
			ctxRef.current.change( key, same ? '' : box.innerHTML.trim() );
		};
		// Clicks in the preview select the block and edit text; the app's own links and buttons wait.
		on( 'pointerdown', function ( e ) {
			var t = e.target.closest && e.target.closest( '[data-gs-aw]' );
			if ( ! ctxRef.current.onSelect() || ! t ) {
				return;
			}
			// Selecting the block focuses its first text: put the caret back where the click was.
			var x = e.clientX;
			var y = e.clientY;
			[ 120, 360 ].forEach( function ( ms ) {
				setTimeout( function () {
					if ( doc.activeElement === t ) {
						return;
					}
					t.focus( { preventScroll: true } );
					var r = doc.caretRangeFromPoint ? doc.caretRangeFromPoint( x, y ) : null;
					if ( r && t.contains( r.startContainer ) ) {
						var sel = doc.getSelection();
						sel.removeAllRanges();
						sel.addRange( r );
					}
				}, ms );
			} );
		}, true );
		on( 'click', function ( e ) {
			var a = e.target.closest && e.target.closest( 'a[href]' );
			if ( ( e.target.closest && e.target.closest( '[data-gs-aw]' ) ) || ( a && ! /^#/.test( a.getAttribute( 'href' ) ) ) ) {
				e.preventDefault();
				e.stopPropagation();
			}
		}, true );
		on( 'submit', function ( e ) {
			e.preventDefault();
		}, true );
		on( 'focusout', function ( e ) {
			if ( e.target.hasAttribute && e.target.hasAttribute( 'data-gs-aw' ) ) {
				commit( e.target );
			}
		} );
		on( 'keydown', function ( e ) {
			var t = e.target;
			if ( ! t.isContentEditable ) {
				return;
			}
			var key = ( e.key || '' ).toLowerCase();
			if ( e.key === 'Enter' && ! e.shiftKey ) {
				e.preventDefault();
				t.blur();
			} else if ( e.key === 'Escape' ) {
				t.innerHTML = t.dataset.gsBefore;
				t.blur();
			} else if ( e.key === ' ' && t.closest( 'button' ) ) {
				// A space typed in a button label is text, not a click.
				e.preventDefault();
				doc.execCommand( 'insertText', false, ' ' );
			} else if ( ( e.ctrlKey || e.metaKey ) && /^[zys]$/.test( key ) && ( key === 's' || t.innerHTML === t.dataset.gsBefore ) ) {
				// Undo / redo / save belong to the editor once the text has no typing of its own to undo.
				e.preventDefault();
				t.blur();
				var ed = wp.data.dispatch( 'core/editor' );
				if ( key === 's' ) {
					ed.savePost();
				} else if ( key === 'y' || e.shiftKey ) {
					ed.redo();
				} else {
					ed.undo();
				}
			}
		} );
		on( 'paste', function ( e ) {
			if ( e.target.isContentEditable && e.clipboardData ) {
				e.preventDefault();
				doc.execCommand( 'insertText', false, e.clipboardData.getData( 'text/plain' ) );
			}
		} );
		if ( kit ) {
			// The part of the frame on screen in the editor, in the frame's own view.
			var onScreen = function () {
				var r = frame.getBoundingClientRect();
				return { top: Math.max( 0, -r.top ), bottom: Math.min( r.height, host.innerHeight - r.top ) };
			};
			var tb = kit.textToolbar( doc, on, commit, onScreen, { whole: true } );
			host.addEventListener( 'scroll', tb.place, { passive: true } );
			cleanup.push( function () {
				host.removeEventListener( 'scroll', tb.place );
				tb.destroy();
			} );
		}
		return function () {
			cleanup.forEach( function ( fn ) {
				fn();
			} );
		};
	}
	// An app section: the live preview, edited in place, plus its Wording panel.
	function AppSection( props ) {
		var make = props.make;
		var attrs = props.attributes || {};
		var p = make( attrs );
		// Words come from the app as shipped (no gs_texts), so renamed ones can be found again.
		var bare = make( Object.assign( {}, attrs, { gsTexts: {} } ) ).src;
		// An edit typed in the preview is already on screen: the frame keeps its page (no reload).
		var own = wp.element.useRef( null );
		var shown = wp.element.useState( p.src );
		wp.element.useEffect( function () {
			if ( own.current !== p.src ) {
				own.current = null;
				shown[ 1 ]( p.src );
			}
		}, [ p.src ] );
		var latest = wp.element.useRef( attrs.gsTexts || {} );
		latest.current = attrs.gsTexts || {};
		var editRef = wp.element.useRef( null );
		editRef.current = {
			// True when this click selected the block.
			onSelect: function () {
				if ( wp.data.select( 'core/block-editor' ).isBlockSelected( props.clientId ) ) {
					return false;
				}
				wp.data.dispatch( 'core/block-editor' ).selectBlock( props.clientId );
				return true;
			},
			change: function ( key, value ) {
				var next = Object.assign( {}, latest.current );
				if ( value ) {
					next[ key ] = value;
				} else if ( next[ key ] !== undefined ) {
					delete next[ key ];
				} else {
					return;
				}
				latest.current = next;
				own.current = make( Object.assign( {}, props.attributes, { gsTexts: next } ) ).src;
				props.setAttributes( { gsTexts: next } );
			},
		};
		return el( wp.element.Fragment, null,
			el( LiveFrame, { src: shown[ 0 ], label: p.label, empty: p.empty, editRef: p.src ? editRef : null } ),
			props.isSelected ? el( Wording, { src: bare, attributes: attrs, setAttributes: props.setAttributes } ) : null );
	}

	wp.hooks.addFilter( 'editor.BlockEdit', 'gend-society/studio-previews', wp.compose.createHigherOrderComponent( function ( BlockEdit ) {
		return function ( props ) {
			var make = previews[ props.name ];
			if ( ! make ) {
				return el( BlockEdit, props );
			}
			var p = make( props.attributes || {} );
			if ( p.wording ) {
				return el( AppSection, Object.assign( {}, props, { make: make } ) );
			}
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
		// App sections would only become an empty live placeholder: they get the Wording panel instead.
		return LIVE_PREFIX.test( block.name ) && WORDING_BLOCKS.indexOf( block.name ) === -1 && ! ( block.innerBlocks && block.innerBlocks.length );
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

	wp.hooks.addFilter( 'editor.BlockEdit', 'gend-society/studio-live-html', wp.compose.createHigherOrderComponent( function ( BlockEdit ) {
		return function ( props ) {
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
