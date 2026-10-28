/* GenD Society - Custom HTML blocks, visual and editable in place (inc/html-visual-edit.php).
   Every block editor shows a Custom HTML block as the page looks, in a live frame that runs
   the block's own scripts (animations, tabs, scroll scenes). Click a text to type in it; hover
   an image (or a background image) for "Replace image". Each change is written back into the
   block's HTML. {{tokens}} and live parts are each member's or group's own and stay as they are. */
( function ( wp ) {
	'use strict';
	if ( ! wp || ! wp.hooks || ! wp.blockEditor || ! wp.element ) {
		return;
	}
	var cfg = window.gsHtmlVisual || {};
	var el = wp.element.createElement;
	var __ = wp.i18n.__;

	function notify( msg, type ) {
		var n = wp.data.dispatch( 'core/notices' );
		if ( n ) {
			n.createNotice( type || 'success', msg, { type: 'snackbar', isDismissible: true } );
		}
	}

	// ── 1. What can be edited ─────────────────────────────────────────────
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
		var bare = html.replace( /<script\b[\s\S]*?<\/script\s*>|<style\b[\s\S]*?<\/style\s*>|<!--[\s\S]*?-->/gi, function ( m ) {
			return m.slice( 0, m.indexOf( '>' ) + 1 );
		} );
		var written = ( bare.match( /<([a-zA-Z][\w-]*)/g ) || [] ).map( function ( t ) {
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
		Array.prototype.forEach.call( tpl.content.querySelectorAll( '*' ), function ( node ) {
			if ( node.closest( SKIP ) || ( node.parentElement && node.parentElement.closest( '[data-gs-k="text"]' ) ) ) {
				return;
			}
			var kind = '';
			var style = node.getAttribute( 'style' ) || '';
			if ( node.tagName === 'IMG' ) {
				kind = node.getAttribute( 'src' ) && node.getAttribute( 'src' ).indexOf( '{{' ) === -1 ? 'img' : '';
			} else if ( textEditable( node ) ) {
				kind = 'text';
			} else if ( BG_URL.test( style ) && style.indexOf( '{{' ) === -1 ) {
				kind = 'bg';
			}
			if ( kind ) {
				node.setAttribute( 'data-gs-e', String( n++ ) );
				node.setAttribute( 'data-gs-k', kind );
			}
		} );
		return n;
	}
	function unmark( tpl ) {
		Array.prototype.forEach.call( tpl.content.querySelectorAll( '[data-gs-e]' ), function ( node ) {
			node.removeAttribute( 'data-gs-e' );
			node.removeAttribute( 'data-gs-k' );
			node.removeAttribute( 'contenteditable' );
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
	// One media picker for the page, pointed at whichever image was chosen last.
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
	function applyImage( node, kind, a ) {
		if ( kind === 'img' ) {
			node.setAttribute( 'src', a.url );
			node.removeAttribute( 'srcset' );
			node.removeAttribute( 'sizes' );
			if ( a.width && a.height && node.hasAttribute( 'width' ) ) {
				node.setAttribute( 'width', String( a.width ) );
				node.setAttribute( 'height', String( a.height ) );
			}
			if ( ! node.getAttribute( 'alt' ) && a.alt ) {
				node.setAttribute( 'alt', a.alt );
			}
		} else {
			node.setAttribute( 'style', ( node.getAttribute( 'style' ) || '' ).replace( BG_URL, 'url("' + a.url.replace( /["\\]/g, '' ) + '")' ) );
		}
	}

	// ── 2. The live frame ─────────────────────────────────────────────────
	// The theme's styles as the editor canvas has them, without the editor's own UI styles.
	var EDITOR_CSS = /\/(components|block-editor|edit-post|edit-site|editor|base-styles|format-library|dashicons|common|forms|admin-bar)[/.]|\/block-library\/editor/;
	function themeStyles( doc ) {
		var out = '';
		Array.prototype.forEach.call( doc.querySelectorAll( 'link[rel="stylesheet"], style' ), function ( n ) {
			if ( n.tagName === 'LINK' ? EDITOR_CSS.test( n.getAttribute( 'href' ) || '' ) : /gs-hv-|block-editor-|\.components-/.test( n.textContent.slice( 0, 4000 ) ) ) {
				return;
			}
			out += n.outerHTML;
		} );
		return out;
	}
	var FRAME_CSS = 'html{scrollbar-width:none}html::-webkit-scrollbar{display:none}html,body{margin:0}body{min-height:0!important;padding:0!important}'
		+ '[data-gs-k=text]{cursor:text;border-radius:4px;outline:1px dashed transparent;outline-offset:3px;transition:outline-color .15s,box-shadow .15s}'
		+ '[data-gs-k=text]:hover{outline-color:rgba(151,228,110,.7)}'
		+ '[data-gs-k=text]:focus{outline:2px solid #97e46e;box-shadow:0 0 0 6px rgba(151,228,110,.14)}'
		+ '.gs-hv-on{outline:2px dashed #6ec1e4!important;outline-offset:2px}'
		+ '.gs-hv-chip{position:absolute;z-index:2147483646;display:none;align-items:center;gap:6px;padding:7px 12px;border:0;border-radius:999px;background:#0b0e14;color:#fff;font:600 12px/1 system-ui,sans-serif;box-shadow:0 0 0 1px #6ec1e4,0 6px 20px rgba(0,0,0,.45);cursor:pointer}'
		+ '.gs-hv-chip:hover{background:#6ec1e4;color:#0b0e14}';
	function frameDoc( head, bodyClass, html ) {
		return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
			+ ( cfg.siteUrl ? '<base href="' + String( cfg.siteUrl ).replace( /"/g, '' ) + '" target="_self">' : '' )
			+ head + '<style id="gs-hv-frame">' + FRAME_CSS + '</style></head><body class="' + bodyClass.replace( /"/g, '' ) + ' gs-hv-doc">'
			+ html + '</body></html>';
	}

	// The block's HTML: inner content since WordPress 7.1, the content attribute before.
	function blockHtml( block, attrs ) {
		if ( attrs && typeof attrs.content === 'string' ) {
			return attrs.content;
		}
		return block ? wp.blocks.getBlockContent( block ) : '';
	}
	function writeHtml( registry, clientId, attrs, setAttributes, next ) {
		if ( attrs && typeof attrs.content === 'string' ) {
			setAttributes( { content: next } );
			return;
		}
		// Same as the Custom HTML block's own code editor.
		var parsed = wp.blocks.parse( '<!-- wp:html -->\n' + next + '\n<!-- /wp:html -->' )[ 0 ];
		var be = wp.data.dispatch( 'core/block-editor' );
		var inner = ( parsed && parsed.innerBlocks ) || [];
		var now = wp.data.select( 'core/block-editor' ).getBlocks( clientId );
		var same = now.length === inner.length && now.every( function ( b, i ) {
			return wp.blocks.serialize( b ) === wp.blocks.serialize( inner[ i ] );
		} );
		registry.batch( function () {
			be.updateBlock( clientId, { innerContent: parsed && parsed.innerContent ? parsed.innerContent : [ next ] } );
			if ( ! same ) {
				be.replaceInnerBlocks( clientId, inner, false );
			}
		} );
	}

	function HtmlPreview( props ) {
		var content = props.content || '';
		var frameRef = wp.element.useRef( null );
		var own = wp.element.useRef( null );
		var latest = wp.element.useRef( content );
		latest.current = content;
		var state = wp.element.useState( null );
		var html = state[ 0 ];
		var setHtml = state[ 1 ];
		// The frame shows an in-place edit the HTML state doesn't have yet; reload it on the next outside change (undo).
		var stale = wp.element.useRef( false );
		var reload = wp.element.useState( 0 );
		// The block's HTML with edit marks, and whether it can be written back safely.
		var marked = wp.element.useMemo( function () {
			var tpl = parse( content );
			var safe = roundTrips( content, tpl );
			var count = safe ? annotate( tpl ) : 0;
			return { html: count ? tpl.innerHTML : content, safe: safe, count: count };
		}, [ content ] );

		// Fill {{tokens}} on the server; plain HTML shows as written. A change
		// typed in the frame is already on screen: no reload (it would restart the page).
		wp.element.useEffect( function () {
			if ( own.current !== null && own.current === content ) {
				stale.current = true;
				return undefined;
			}
			own.current = null;
			if ( stale.current ) {
				stale.current = false;
				reload[ 1 ]( function ( n ) {
					return n + 1;
				} );
			}
			var stop = false;
			if ( ! cfg.liveHtml || ! /\{\{|data-gs-live-/.test( marked.html ) ) {
				setHtml( marked.html );
				return undefined;
			}
			var t = setTimeout( function () {
				wp.apiFetch( { path: '/gend-society/v1/live-html/render', method: 'POST', data: { html: marked.html, postId: wp.data.select( 'core/editor' ) ? wp.data.select( 'core/editor' ).getCurrentPostId() : 0 } } ).then( function ( r ) {
					if ( ! stop ) {
						setHtml( r.html );
					}
				} ).catch( function () {
					if ( ! stop ) {
						setHtml( marked.html );
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
			var tpl = parse( latest.current );
			annotate( tpl );
			var src = tpl.content.querySelector( '[data-gs-e="' + id + '"]' );
			if ( ! src ) {
				return;
			}
			apply( src );
			var next = unmark( tpl );
			if ( next !== latest.current ) {
				own.current = next;
				props.onChange( next );
			}
		};
		var commitRef = wp.element.useRef( commit );
		commitRef.current = commit;
		var selectRef = wp.element.useRef( props.onSelect );
		selectRef.current = props.onSelect;

		// Load the frame and make its marked parts editable.
		wp.element.useEffect( function () {
			var frame = frameRef.current;
			if ( ! frame || html === null ) {
				return undefined;
			}
			var host = frame.ownerDocument;
			var view = host.defaultView;
			var cleanup = [];
			var onLoad = function () {
				var doc = frame.contentDocument;
				if ( ! doc || ! doc.body ) {
					return;
				}
				// One scrollbar: the stage is as tall as the page, the frame is one
				// screen that rides along it, and the canvas scroll drives the page's
				// own scroll (sticky scenes and scroll effects play as on the site).
				var stage = frame.parentNode;
				var win = doc.defaultView;
				var full = 0;
				var screenH = 0;
				var placed = 0;
				var sync = function () {
					var top = stage.getBoundingClientRect().top;
					var at = Math.min( Math.max( -top, 0 ), Math.max( full - screenH, 0 ) );
					frame.style.transform = at ? 'translateY(' + at + 'px)' : '';
					placed = at;
					if ( Math.abs( win.scrollY - at ) > 0.5 ) {
						win.scrollTo( { top: at, behavior: 'instant' } );
					}
				};
				var fit = function () {
					screenH = Math.max( 320, Math.round( view.innerHeight || 800 ) );
					frame.style.height = screenH + 'px';
					// The page's height without the frame's own screen in it.
					var h = Math.max( 60, doc.body.scrollHeight, doc.documentElement.scrollHeight > screenH ? doc.documentElement.scrollHeight : 0 );
					full = h;
					screenH = Math.min( screenH, h );
					frame.style.height = screenH + 'px';
					stage.style.height = full + 'px';
					sync();
				};
				fit();
				var lis = function ( target, type, fn, opts ) {
					target.addEventListener( type, fn, opts );
					cleanup.push( function () {
						target.removeEventListener( type, fn, opts );
					} );
				};
				lis( view, 'resize', fit );
				lis( view, 'scroll', sync, { passive: true } );
				// The wheel scrolls the editor, never the frame alone.
				lis( doc, 'wheel', function ( e ) {
					if ( e.ctrlKey ) {
						return;
					}
					e.preventDefault();
					var k = e.deltaMode === 1 ? 16 : ( e.deltaMode === 2 ? screenH : 1 );
					view.scrollBy( { left: e.deltaX * k, top: e.deltaY * k, behavior: 'instant' } );
				}, { passive: false } );
				// The page scrolled itself (keyboard, a # link, a focused text): the editor follows.
				lis( win, 'scroll', function () {
					// Our own placement echoing back: nothing to follow.
					if ( Math.abs( win.scrollY - placed ) <= 1 ) {
						return;
					}
					view.scrollBy( { top: win.scrollY - placed, behavior: 'instant' } );
					sync();
				}, { passive: true } );
				var tick = setInterval( fit, 400 );
				setTimeout( function () {
					clearInterval( tick );
				}, 6000 );
				cleanup.push( function () {
					clearInterval( tick );
				} );
				if ( view.ResizeObserver ) {
					var ro = new view.ResizeObserver( fit );
					ro.observe( doc.body );
					cleanup.push( function () {
						ro.disconnect();
					} );
				}
				var on = function ( type, fn, opts ) {
					doc.addEventListener( type, fn, opts );
					cleanup.push( function () {
						doc.removeEventListener( type, fn, opts );
					} );
				};
				// Clicking the page selects its block (the frame keeps clicks to itself).
				on( 'pointerdown', function () {
					if ( selectRef.current ) {
						selectRef.current();
					}
				}, true );
				// Links and forms never leave the editor.
				on( 'click', function ( e ) {
					var a = e.target.closest && e.target.closest( 'a[href]' );
					if ( a && ! /^#/.test( a.getAttribute( 'href' ) ) ) {
						e.preventDefault();
					}
				} );
				on( 'submit', function ( e ) {
					e.preventDefault();
				}, true );
				if ( ! marked.count ) {
					return;
				}
				Array.prototype.forEach.call( doc.querySelectorAll( '[data-gs-k="text"]' ), function ( t ) {
					t.setAttribute( 'contenteditable', 'true' );
					t.setAttribute( 'spellcheck', 'true' );
					t.dataset.gsBefore = t.innerHTML;
				} );
				// Images: a "Replace image" chip on hover, so clicks still work (tabs, sliders, links).
				var chip = doc.createElement( 'button' );
				chip.type = 'button';
				chip.className = 'gs-hv-chip';
				chip.textContent = __( 'Replace image', 'gend-society' );
				doc.body.appendChild( chip );
				var target = null;
				var hideT = 0;
				var show = function ( t ) {
					clearTimeout( hideT );
					if ( target && target !== t ) {
						target.classList.remove( 'gs-hv-on' );
					}
					target = t;
					t.classList.add( 'gs-hv-on' );
					var r = t.getBoundingClientRect();
					var w = doc.defaultView;
					chip.style.display = 'inline-flex';
					chip.style.top = Math.max( r.top + w.scrollY + 10, w.scrollY + 10 ) + 'px';
					chip.style.left = Math.max( r.right + w.scrollX - chip.offsetWidth - 10, r.left + w.scrollX + 10 ) + 'px';
				};
				var hide = function () {
					hideT = setTimeout( function () {
						chip.style.display = 'none';
						if ( target ) {
							target.classList.remove( 'gs-hv-on' );
							target = null;
						}
					}, 250 );
				};
				on( 'mouseover', function ( e ) {
					if ( e.target === chip ) {
						clearTimeout( hideT );
						return;
					}
					var t = e.target.closest && e.target.closest( '[data-gs-k="img"],[data-gs-k="bg"]' );
					if ( t && ! ( e.target.closest( '[data-gs-k="text"]' ) && t.getAttribute( 'data-gs-k' ) === 'bg' ) ) {
						show( t );
					} else if ( target ) {
						hide();
					}
				} );
				chip.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					e.stopPropagation();
					if ( ! target ) {
						return;
					}
					var t = target;
					var id = t.getAttribute( 'data-gs-e' );
					var kind = t.getAttribute( 'data-gs-k' );
					pickMedia( __( 'Replace image', 'gend-society' ), function ( a ) {
						applyImage( t, kind, a );
						commitRef.current( id, function ( src ) {
							applyImage( src, kind, a );
						} );
						notify( __( 'Image replaced. Save the page to keep it.', 'gend-society' ) );
					} );
				} );
				on( 'focusout', function ( e ) {
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
					commitRef.current( t.getAttribute( 'data-gs-e' ), function ( src ) {
						src.innerHTML = value;
					} );
				} );
				on( 'keydown', function ( e ) {
					if ( ! e.target.isContentEditable ) {
						return;
					}
					if ( e.key === 'Enter' && ! e.shiftKey ) {
						e.preventDefault();
						e.target.blur();
					} else if ( e.key === 'Escape' ) {
						e.target.innerHTML = e.target.dataset.gsBefore;
						e.target.blur();
					} else if ( e.key === ' ' && e.target.closest( 'button' ) ) {
						// A space typed in a button label is text, not a click.
						e.preventDefault();
						doc.execCommand( 'insertText', false, ' ' );
					}
				} );
				on( 'paste', function ( e ) {
					if ( ! e.target.isContentEditable || ! e.clipboardData ) {
						return;
					}
					e.preventDefault();
					doc.execCommand( 'insertText', false, e.clipboardData.getData( 'text/plain' ) );
				} );
				// Undo / redo / save belong to the editor, not to the text.
				on( 'keydown', function ( e ) {
					var key = ( e.key || '' ).toLowerCase();
					if ( ! ( e.ctrlKey || e.metaKey ) || ! /^[zys]$/.test( key ) ) {
						return;
					}
					// Typing in progress: the text's own undo first.
					if ( key !== 's' && e.target.isContentEditable && e.target.innerHTML !== e.target.dataset.gsBefore ) {
						return;
					}
					e.preventDefault();
					if ( e.target.blur ) {
						e.target.blur();
					}
					var ed = wp.data.dispatch( 'core/editor' );
					if ( ! ed ) {
						return;
					}
					if ( key === 's' ) {
						ed.savePost();
					} else if ( key === 'y' || e.shiftKey ) {
						ed.redo();
					} else {
						ed.undo();
					}
				} );
			};
			frame.addEventListener( 'load', onLoad );
			frame.srcdoc = frameDoc( themeStyles( host ), host.body ? host.body.className : '', html );
			return function () {
				frame.removeEventListener( 'load', onLoad );
				cleanup.forEach( function ( fn ) {
					fn();
				} );
			};
		}, [ html, reload[ 0 ] ] );

		var blockProps = wp.blockEditor.useBlockProps( { className: 'gs-lh-preview gs-hv' + ( marked.count ? ' is-editable' : '' ) } );
		return el( 'div', blockProps,
			props.isSelected ? el( 'div', { className: 'gs-lh-hint' }, marked.count
				? __( 'Click any text to edit it. Hover an image for “Replace image”. Use “Edit HTML” in the toolbar for everything else.', 'gend-society' )
				: ( marked.safe ? __( 'Nothing here can be edited in place. Use “Edit HTML” in the toolbar.', 'gend-society' ) : __( 'This section’s HTML can only be changed as code. Use “Edit HTML” in the toolbar.', 'gend-society' ) ) ) : null,
			html === null ? el( 'div', { className: 'gs-lh-loading' }, __( 'Rendering…', 'gend-society' ) ) : null,
			el( 'div', { className: 'gs-hv-stage', style: html === null ? { display: 'none' } : undefined },
				el( 'iframe', { ref: frameRef, className: 'gs-hv-frame', title: __( 'Section preview', 'gend-society' ) } ) ) );
	}

	// ── 3. The block: visual by default, the code on request ──────────────
	// The code, right in the block (WordPress 7.1's own editor opens in a dialog).
	function CodeBox( props ) {
		var s = wp.element.useState( props.html );
		var draft = s[ 0 ];
		var setDraft = s[ 1 ];
		wp.element.useEffect( function () {
			setDraft( props.html );
		}, [ props.html ] );
		var blockProps = wp.blockEditor.useBlockProps( { className: 'gs-hv-code' } );
		return el( 'div', blockProps, el( 'textarea', {
			value: draft,
			spellCheck: false,
			'aria-label': __( 'HTML', 'gend-society' ),
			rows: Math.min( 40, Math.max( 8, draft.split( '\n' ).length + 1 ) ),
			onChange: function ( e ) {
				setDraft( e.target.value );
			},
			onBlur: function () {
				if ( draft !== props.html ) {
					writeHtml( props.registry, props.clientId, props.attributes, props.setAttributes, draft );
				}
			},
		} ) );
	}
	function EditableHtml( props ) {
		var registry = wp.data.useRegistry();
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
				el( 'div', { className: 'gs-lh-hint' }, /\{\{|data-gs-live-/.test( props.html )
					? __( 'Live HTML: {{tokens}} fill in each group’s or member’s details; data-gs-live parts stay live.', 'gend-society' )
					: __( 'Editing the HTML. Switch back to Visual to edit in place.', 'gend-society' ) ),
				toggle,
				typeof props.attributes.content === 'string' ? el( props.BlockEdit, props ) : el( CodeBox, Object.assign( {}, props, { registry: registry } ) ) );
		}
		return el( wp.element.Fragment, null,
			props.isSelected ? toggle : null,
			el( HtmlPreview, {
				content: props.html,
				isSelected: props.isSelected,
				onSelect: function () {
					if ( ! wp.data.select( 'core/block-editor' ).isBlockSelected( props.clientId ) ) {
						wp.data.dispatch( 'core/block-editor' ).selectBlock( props.clientId );
					}
				},
				onChange: function ( next ) {
					writeHtml( registry, props.clientId, props.attributes, props.setAttributes, next );
				},
			} ) );
	}

	wp.hooks.addFilter( 'editor.BlockEdit', 'gend-society/html-visual-edit', wp.compose.createHigherOrderComponent( function ( BlockEdit ) {
		return function ( props ) {
			var html = wp.data.useSelect( function ( select ) {
				if ( props.name !== 'core/html' ) {
					return '';
				}
				return blockHtml( select( 'core/block-editor' ).getBlock( props.clientId ), props.attributes );
			}, [ props.name, props.clientId, props.attributes ] );
			if ( props.name !== 'core/html' || ! ( html || '' ).trim() ) {
				return el( BlockEdit, props );
			}
			return el( EditableHtml, Object.assign( {}, props, { BlockEdit: BlockEdit, html: html } ) );
		};
	}, 'withGsHtmlVisual' ) );
}( window.wp ) );
