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
	// It opens on the image being replaced: shown in its bar, and selected when it's in the library.
	var mediaFrame = null;
	var mediaPick = null;
	var mediaAsk = 0;
	// "photo-1024x768.png", "photo-scaled.webp" and "photo.png" are the same image.
	function imageKey( url ) {
		var name = String( url || '' ).split( /[?#]/ )[ 0 ].split( '/' ).pop();
		try {
			name = decodeURIComponent( name );
		} catch ( e ) {}
		return name.replace( /\.[a-z0-9]+$/i, '' ).replace( /-(\d+x\d+|scaled|rotated|e\d{10,})$/i, '' ).toLowerCase();
	}
	function findAttachment( current ) {
		var m = / wp-image-(\d+) /.exec( ' ' + ( current.className || '' ) + ' ' );
		if ( m ) {
			return Promise.resolve( parseInt( m[ 1 ], 10 ) );
		}
		var key = imageKey( current.url );
		if ( ! key || /^data:/.test( current.url ) ) {
			return Promise.resolve( 0 );
		}
		return wp.apiFetch( { path: '/wp/v2/media?media_type=image&per_page=20&_fields=id,source_url,media_details&search=' + encodeURIComponent( key.replace( /[-_]+/g, ' ' ) ) } ).then( function ( list ) {
			var hit = ( list || [] ).filter( function ( a ) {
				var urls = [ a.source_url ];
				var sizes = ( a.media_details && a.media_details.sizes ) || {};
				Object.keys( sizes ).forEach( function ( k ) {
					urls.push( sizes[ k ].source_url );
				} );
				return urls.some( function ( u ) {
					return imageKey( u ) === key;
				} );
			} )[ 0 ];
			return hit ? hit.id : 0;
		} ).catch( function () {
			return 0;
		} );
	}
	function showCurrent( current ) {
		var root = mediaFrame.$el[ 0 ];
		var bar = root.querySelector( '.gs-hv-current' );
		if ( ! bar ) {
			bar = document.createElement( 'div' );
			bar.className = 'gs-hv-current';
			root.appendChild( bar );
		}
		bar.textContent = '';
		if ( ! current || ! current.url ) {
			bar.hidden = true;
			return;
		}
		bar.hidden = false;
		var img = document.createElement( 'img' );
		img.src = current.url;
		img.alt = '';
		var txt = document.createElement( 'span' );
		var lab = document.createElement( 'small' );
		lab.textContent = __( 'Replacing', 'gend-society' );
		var name = document.createElement( 'b' );
		name.textContent = String( current.url ).split( /[?#]/ )[ 0 ].split( '/' ).pop();
		txt.appendChild( lab );
		txt.appendChild( name );
		bar.appendChild( img );
		bar.appendChild( txt );
	}
	function pickMedia( title, onPick, current ) {
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
		mediaFrame.modal.$el.addClass( 'gs-hv-media' );
		showCurrent( current );
		var selection = mediaFrame.state().get( 'selection' );
		selection.reset( [] );
		var ask = ++mediaAsk;
		if ( current && current.url ) {
			findAttachment( current ).then( function ( id ) {
				if ( ! id || ask !== mediaAsk ) {
					return;
				}
				var att = wp.media.attachment( id );
				att.fetch().then( function () {
					if ( ask === mediaAsk ) {
						selection.reset( [ att ] );
					}
				} );
			} );
		}
	}
	// The image an element shows now: its source, or its background's URL.
	function currentImage( node, kind ) {
		if ( kind === 'img' ) {
			return { url: node.currentSrc || node.getAttribute( 'src' ), className: node.getAttribute( 'class' ) || '' };
		}
		var m = BG_URL.exec( node.getAttribute( 'style' ) || '' );
		return { url: m ? m[ 2 ] : '', className: '' };
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
		+ '.gs-hv-chip:hover{background:#6ec1e4;color:#0b0e14}'
		// The text toolbar: reset first (the page's own button and select styles apply in here).
		+ '.gs-tb,.gs-tb *:not(svg):not(svg *){all:unset;box-sizing:border-box}'
		+ '.gs-tb{position:absolute;z-index:2147483647;display:none;flex-direction:column;max-width:calc(100vw - 16px);padding:6px;border-radius:12px;background:#0b0e14;color:#e6edf7;font:500 13px/1 system-ui,-apple-system,sans-serif;box-shadow:0 0 0 1px rgba(110,193,228,.35),0 12px 32px rgba(0,0,0,.5);cursor:default}'
		+ '.gs-tb.is-open{display:flex}.gs-tb [hidden]{display:none!important}'
		+ '.gs-tb .gs-tb-row{display:flex;flex-wrap:wrap;align-items:center;gap:2px}'
		+ '.gs-tb .gs-tb-sel{display:inline-block;height:30px;max-width:140px;padding:0 24px 0 10px;border-radius:8px;background:#151b26 url("data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2710%27 height=%276%27%3E%3Cpath d=%27M1 1l4 4 4-4%27 stroke=%27%2394a3b8%27 fill=%27none%27 stroke-width=%271.5%27/%3E%3C/svg%3E") no-repeat right 9px center;color:#e6edf7;font:500 13px/30px system-ui,sans-serif;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;cursor:pointer}'
		+ '.gs-tb .gs-tb-sel:hover{background-color:#1c2433}.gs-tb .gs-tb-sel option{background:#0b0e14;color:#e6edf7}'
		+ '.gs-tb .gs-tb-sep{display:inline-block;width:1px;height:20px;margin:0 4px;background:rgba(255,255,255,.12)}'
		+ '.gs-tb .gs-tb-btn{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:8px;color:#cbd5e1;font:700 15px/1 Georgia,serif;cursor:pointer}'
		+ '.gs-tb .gs-tb-btn:hover{background:#1c2433;color:#fff}.gs-tb .gs-tb-btn.is-on{background:rgba(151,228,110,.16);color:#97e46e}'
		+ '.gs-tb .gs-tb-btn[disabled]{opacity:.35;cursor:default}.gs-tb .gs-tb-btn[disabled]:hover{background:none}'
		+ '.gs-tb .gs-tb-btn b{font-weight:800}.gs-tb .gs-tb-btn i{font-style:italic}.gs-tb .gs-tb-btn u{text-decoration:underline}.gs-tb .gs-tb-btn s{text-decoration:line-through}'
		+ '.gs-tb .gs-tb-a{display:inline-flex;flex-direction:column;align-items:center;font:700 15px/1 Georgia,serif}'
		+ '.gs-tb .gs-tb-line{display:block;width:14px;height:3px;margin-top:2px;border-radius:2px;background:linear-gradient(90deg,#f87171,#fbbf24,#97e46e,#6ec1e4,#a855f7)}'
		+ '.gs-tb .gs-tb-hl{padding:2px 4px;border-radius:4px;background:#fbbf24;color:#0b0e14}'
		+ '.gs-tb .gs-tb-panel{display:block;margin-top:6px;padding:10px;border-top:1px solid rgba(255,255,255,.08);min-width:240px}'
		+ '.gs-tb .gs-tb-panel-title{display:block;margin:0 0 8px;color:#94a3b8;font:600 10px/1 system-ui,sans-serif;letter-spacing:.08em;text-transform:uppercase}'
		+ '.gs-tb .gs-tb-swatches{display:grid;grid-template-columns:repeat(8,24px);gap:6px}'
		+ '.gs-tb .gs-tb-swatch{display:block;width:24px;height:24px;border-radius:50%;box-shadow:inset 0 0 0 1px rgba(255,255,255,.25);cursor:pointer}'
		+ '.gs-tb .gs-tb-swatch:hover{transform:scale(1.12);box-shadow:inset 0 0 0 1px rgba(255,255,255,.25),0 0 0 2px #6ec1e4}'
		+ '.gs-tb .gs-tb-swatch.is-none{background:linear-gradient(135deg,transparent 45%,#f87171 45%,#f87171 55%,transparent 55%),#151b26!important}'
		+ '.gs-tb .gs-tb-custom{display:flex;align-items:center;gap:8px;margin-top:10px;color:#cbd5e1;font:500 12px system-ui,sans-serif;cursor:pointer}'
		+ '.gs-tb .gs-tb-custom input{display:block;width:28px;height:28px;border-radius:6px;overflow:hidden;cursor:pointer}'
		+ '.gs-tb .gs-tb-link{display:flex;gap:6px}'
		+ '.gs-tb .gs-tb-link input{display:block;flex:1;min-width:200px;height:32px;padding:0 10px;border-radius:8px;background:#151b26;color:#e6edf7;font:500 13px/32px system-ui,sans-serif;box-shadow:inset 0 0 0 1px rgba(255,255,255,.14)}'
		+ '.gs-tb .gs-tb-link input:focus{box-shadow:inset 0 0 0 1px #6ec1e4}'
		+ '.gs-tb .gs-tb-go{display:inline-flex;align-items:center;height:32px;padding:0 14px;border-radius:999px;background:#97e46e;color:#0b0e14;font:700 12px/1 system-ui,sans-serif;cursor:pointer}';
	function frameDoc( head, bodyClass, html ) {
		return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
			+ ( cfg.siteUrl ? '<base href="' + String( cfg.siteUrl ).replace( /"/g, '' ) + '" target="_self">' : '' )
			+ head + '<style id="gs-hv-frame">' + FRAME_CSS + '</style></head><body class="' + bodyClass.replace( /"/g, '' ) + ' gs-hv-doc">'
			+ html + '</body></html>';
	}

	// ── 2b. The text toolbar: fonts, sizes, styles, colours, links, alignment ──
	// The site's own presets (theme.json), so text matches the rest of the site.
	function presets() {
		var s = ( wp.data.select( 'core/block-editor' ) && wp.data.select( 'core/block-editor' ).getSettings() ) || {};
		var feat = ( s.__experimentalFeatures && s.__experimentalFeatures.typography ) || {};
		var fams = [];
		[ feat.fontFamilies, s.fontFamilies ].forEach( function ( src ) {
			if ( ! src ) {
				return;
			}
			( Array.isArray( src ) ? [ src ] : [ src.custom, src.theme, src.default ] ).forEach( function ( list ) {
				( list || [] ).forEach( function ( f ) {
					if ( f && f.fontFamily && ! fams.some( function ( x ) {
						return x.fontFamily === f.fontFamily;
					} ) ) {
						fams.push( { name: f.name || f.slug, fontFamily: f.fontFamily } );
					}
				} );
			} );
		} );
		var sizes = ( s.fontSizes || [] ).filter( function ( f ) {
			return f && f.size;
		} ).map( function ( f ) {
			return { name: f.name || f.slug, size: /^\d+(\.\d+)?$/.test( String( f.size ) ) ? f.size + 'px' : String( f.size ) };
		} );
		if ( ! sizes.length ) {
			sizes = [ 12, 14, 16, 18, 20, 24, 30, 36, 48, 60, 72 ].map( function ( n ) {
				return { name: n + 'px', size: n + 'px' };
			} );
		}
		var colors = ( s.colors || [] ).filter( function ( c ) {
			return c && /^#|^rgb/i.test( c.color || '' );
		} );
		return { fams: fams, sizes: sizes, colors: colors };
	}
	var SVG = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">';
	var LINK_PATH = '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>';
	var TB_ICONS = {
		bold: '<b>B</b>',
		italic: '<i>I</i>',
		underline: '<u>U</u>',
		strikeThrough: '<s>S</s>',
		color: '<span class="gs-tb-a">A<i class="gs-tb-line"></i></span>',
		hilite: '<span class="gs-tb-a gs-tb-hl">A</span>',
		link: SVG + LINK_PATH + '</svg>',
		unlink: SVG + LINK_PATH + '<path d="M3 3l18 18"/></svg>',
		left: SVG + '<path d="M4 6h16M4 12h10M4 18h14"/></svg>',
		center: SVG + '<path d="M4 6h16M7 12h10M5 18h14"/></svg>',
		right: SVG + '<path d="M4 6h16M10 12h10M6 18h14"/></svg>',
		clear: SVG + '<path d="M6 5h12M12 5l-4 14M15 15l5 5M20 15l-5 5"/></svg>'
	};
	// The toolbar over the text being edited, built inside the frame. commitText(t, fn)
	// writes a text's HTML back, and fn( element ) changes the element itself;
	// shown() is the part of the frame on screen, { top, bottom } in the frame's own view.
	function textToolbar( doc, on, commitText, shown ) {
		var P = presets();
		var win = doc.defaultView;
		var active = null;
		var range = null;
		var h = function ( tag, cls, html ) {
			var n = doc.createElement( tag );
			if ( cls ) {
				n.className = cls;
			}
			if ( html ) {
				n.innerHTML = html;
			}
			return n;
		};
		var btn = function ( key, title ) {
			var b = h( 'button', 'gs-tb-btn', TB_ICONS[ key ] );
			b.type = 'button';
			b.title = title;
			b.setAttribute( 'aria-label', title );
			b.dataset.cmd = key;
			return b;
		};
		var select = function ( label, opts ) {
			var s = h( 'select', 'gs-tb-sel' );
			s.title = label;
			s.appendChild( new win.Option( label, '' ) );
			opts.forEach( function ( o ) {
				s.appendChild( new win.Option( o[ 0 ], o[ 1 ] ) );
			} );
			return s;
		};
		var sep = function () {
			return h( 'span', 'gs-tb-sep' );
		};
		var bar = h( 'div', 'gs-tb' );
		bar.setAttribute( 'contenteditable', 'false' );
		var row = h( 'div', 'gs-tb-row' );
		var fam = select( __( 'Font', 'gend-society' ), P.fams.map( function ( f ) {
			return [ f.name, f.fontFamily ];
		} ) );
		var size = select( __( 'Size', 'gend-society' ), P.sizes.map( function ( f ) {
			return [ f.name, f.size ];
		} ) );
		[ fam, size, sep(),
			btn( 'bold', __( 'Bold', 'gend-society' ) ), btn( 'italic', __( 'Italic', 'gend-society' ) ), btn( 'underline', __( 'Underline', 'gend-society' ) ), btn( 'strikeThrough', __( 'Strikethrough', 'gend-society' ) ), sep(),
			btn( 'color', __( 'Text colour', 'gend-society' ) ), btn( 'hilite', __( 'Highlight', 'gend-society' ) ), sep(),
			btn( 'link', __( 'Link', 'gend-society' ) ), btn( 'unlink', __( 'Remove link', 'gend-society' ) ), sep(),
			btn( 'left', __( 'Align left', 'gend-society' ) ), btn( 'center', __( 'Align centre', 'gend-society' ) ), btn( 'right', __( 'Align right', 'gend-society' ) ), sep(),
			btn( 'clear', __( 'Clear formatting', 'gend-society' ) ) ].forEach( function ( n ) {
			row.appendChild( n );
		} );
		bar.appendChild( row );
		// Colours and the link address open in a panel under the row.
		var panel = h( 'div', 'gs-tb-panel' );
		panel.hidden = true;
		bar.appendChild( panel );
		doc.body.appendChild( bar );

		var place = function () {
			if ( ! active ) {
				return;
			}
			var r = active.getBoundingClientRect();
			var bh = bar.offsetHeight;
			var bw = bar.offsetWidth;
			var vis = shown ? shown() : { top: 0, bottom: win.innerHeight };
			var top = r.top - bh - 10;
			if ( top < vis.top + 8 ) {
				top = r.bottom + 10;
			}
			top = Math.max( vis.top + 8, Math.min( top, vis.bottom - bh - 8 ) );
			var left = Math.max( 8, Math.min( r.left, win.innerWidth - bw - 8 ) );
			bar.style.top = ( top + win.scrollY ) + 'px';
			bar.style.left = ( left + win.scrollX ) + 'px';
		};
		// The link the selection is in, inside the text (or the text itself when it is a link).
		var inLink = function () {
			if ( ! active ) {
				return null;
			}
			if ( active.tagName === 'A' ) {
				return active;
			}
			var sel = doc.getSelection();
			var n = sel && sel.rangeCount ? sel.getRangeAt( 0 ).commonAncestorContainer : null;
			n = n && n.nodeType === 3 ? n.parentNode : n;
			var a = n && n.closest ? n.closest( 'a' ) : null;
			return a && active.contains( a ) ? a : null;
		};
		var refresh = function () {
			var align = active ? win.getComputedStyle( active ).textAlign : '';
			align = align === 'start' ? 'left' : ( align === 'end' ? 'right' : align );
			Array.prototype.forEach.call( row.querySelectorAll( '[data-cmd]' ), function ( b ) {
				var c = b.dataset.cmd;
				var state = false;
				if ( /^(bold|italic|underline|strikeThrough)$/.test( c ) ) {
					try {
						state = doc.queryCommandState( c );
					} catch ( e ) {}
				} else if ( /^(left|center|right)$/.test( c ) ) {
					state = align === c;
				} else if ( c === 'unlink' ) {
					b.disabled = ! inLink() || inLink() === active;
				}
				b.classList.toggle( 'is-on', !! state );
			} );
		};
		var show = function ( t ) {
			active = t;
			range = null;
			panel.hidden = true;
			bar.classList.add( 'is-open' );
			place();
			refresh();
		};
		var hide = function () {
			active = null;
			range = null;
			panel.hidden = true;
			bar.classList.remove( 'is-open' );
		};
		// Back to the text with its selection; with nothing selected, all of the text.
		var restore = function ( whole ) {
			active.focus( { preventScroll: true } );
			var sel = doc.getSelection();
			sel.removeAllRanges();
			if ( range && ( ! range.collapsed || whole === false ) && active.contains( range.commonAncestorContainer ) ) {
				sel.addRange( range );
			} else {
				var all = doc.createRange();
				all.selectNodeContents( active );
				sel.addRange( all );
			}
			doc.execCommand( 'styleWithCSS', false, true );
		};
		var done = function () {
			var sel = doc.getSelection();
			if ( sel && sel.rangeCount && active.contains( sel.getRangeAt( 0 ).commonAncestorContainer ) ) {
				range = sel.getRangeAt( 0 ).cloneRange();
			}
			commitText( active );
			refresh();
			place();
		};
		// Any CSS property on the selection: mark it with a font name, then swap the mark for the real style.
		var styleSel = function ( prop, value ) {
			restore();
			doc.execCommand( 'fontName', false, 'gshvmark' );
			Array.prototype.forEach.call( active.querySelectorAll( '[style*="gshvmark"]' ), function ( s ) {
				s.style.removeProperty( 'font-family' );
				if ( value ) {
					s.style.setProperty( prop, value );
				}
				// Spans inside with the same property would hide the new one.
				Array.prototype.forEach.call( s.querySelectorAll( '[style]' ), function ( inner ) {
					inner.style.removeProperty( prop );
					if ( ! inner.getAttribute( 'style' ) ) {
						inner.removeAttribute( 'style' );
					}
				} );
				if ( ! s.getAttribute( 'style' ) && s.tagName === 'SPAN' ) {
					s.replaceWith.apply( s, Array.prototype.slice.call( s.childNodes ) );
				}
			} );
			done();
		};
		var title = function ( text ) {
			var t = h( 'div', 'gs-tb-panel-title' );
			t.textContent = text;
			return t;
		};
		var colourPanel = function ( prop, label ) {
			panel.textContent = '';
			panel.appendChild( title( label ) );
			var grid = h( 'div', 'gs-tb-swatches' );
			P.colors.forEach( function ( c ) {
				var s = h( 'button', 'gs-tb-swatch' );
				s.type = 'button';
				s.title = c.name || c.color;
				s.style.background = c.color;
				s.dataset.value = c.color;
				grid.appendChild( s );
			} );
			var none = h( 'button', 'gs-tb-swatch is-none' );
			none.type = 'button';
			none.title = __( 'None', 'gend-society' );
			none.dataset.value = '';
			grid.appendChild( none );
			panel.appendChild( grid );
			var custom = h( 'label', 'gs-tb-custom' );
			var input = h( 'input' );
			input.type = 'color';
			input.value = '#97e46e';
			custom.appendChild( input );
			custom.appendChild( doc.createTextNode( __( 'Custom colour', 'gend-society' ) ) );
			panel.appendChild( custom );
			grid.addEventListener( 'click', function ( e ) {
				var s = e.target.closest( '.gs-tb-swatch' );
				if ( s ) {
					panel.hidden = true;
					styleSel( prop, s.dataset.value );
				}
			} );
			input.addEventListener( 'change', function () {
				panel.hidden = true;
				styleSel( prop, input.value );
			} );
			panel.hidden = false;
			place();
		};
		var linkPanel = function () {
			var a = inLink();
			panel.textContent = '';
			panel.appendChild( title( a ? __( 'Edit link', 'gend-society' ) : __( 'Add a link', 'gend-society' ) ) );
			var form = h( 'form', 'gs-tb-link' );
			var input = h( 'input' );
			input.type = 'text';
			input.placeholder = 'https://…  or  /page';
			input.value = a ? a.getAttribute( 'href' ) || '' : '';
			var ok = h( 'button', 'gs-tb-go' );
			ok.type = 'submit';
			ok.textContent = __( 'Apply', 'gend-society' );
			form.appendChild( input );
			form.appendChild( ok );
			panel.appendChild( form );
			input.addEventListener( 'input', function () {
				input.setCustomValidity( '' );
			} );
			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				var url = input.value.trim();
				if ( /^(javascript|data|vbscript):/i.test( url.replace( /\s+/g, '' ) ) ) {
					input.setCustomValidity( __( 'That kind of link is not allowed.', 'gend-society' ) );
					input.reportValidity();
					return;
				}
				panel.hidden = true;
				if ( a && a === active ) {
					// The text is the link itself: its address is on the element.
					if ( url ) {
						active.setAttribute( 'href', url );
						commitText( active, function ( src ) {
							src.setAttribute( 'href', url );
						} );
					}
					active.focus( { preventScroll: true } );
					return;
				}
				restore( !! a );
				if ( a ) {
					if ( url ) {
						a.setAttribute( 'href', url );
					} else {
						a.replaceWith.apply( a, Array.prototype.slice.call( a.childNodes ) );
					}
				} else if ( url ) {
					doc.execCommand( 'createLink', false, url );
				}
				done();
			} );
			panel.hidden = false;
			place();
			input.focus();
		};

		// The toolbar keeps the text's selection, except in its own fields.
		bar.addEventListener( 'mousedown', function ( e ) {
			if ( ! e.target.closest( 'select, input' ) ) {
				e.preventDefault();
			}
		} );
		bar.addEventListener( 'click', function ( e ) {
			var b = e.target.closest( '[data-cmd]' );
			if ( ! b || ! active || b.disabled ) {
				return;
			}
			e.preventDefault();
			var c = b.dataset.cmd;
			if ( c === 'color' || c === 'hilite' || c === 'link' ) {
				if ( ! panel.hidden && panel.dataset.kind === c ) {
					panel.hidden = true;
					return;
				}
				panel.dataset.kind = c;
				if ( c === 'link' ) {
					linkPanel();
				} else {
					colourPanel( c === 'color' ? 'color' : 'background-color', c === 'color' ? __( 'Text colour', 'gend-society' ) : __( 'Highlight', 'gend-society' ) );
				}
			} else if ( c === 'unlink' ) {
				var a = inLink();
				if ( a && a !== active ) {
					restore( false );
					a.replaceWith.apply( a, Array.prototype.slice.call( a.childNodes ) );
					done();
				}
			} else if ( /^(left|center|right)$/.test( c ) ) {
				active.style.textAlign = c;
				commitText( active, function ( src ) {
					src.style.textAlign = c;
				} );
				refresh();
			} else if ( c === 'clear' ) {
				restore();
				doc.execCommand( 'removeFormat' );
				var sel = doc.getSelection();
				Array.prototype.slice.call( active.querySelectorAll( 'span[style]' ) ).forEach( function ( s ) {
					if ( sel.containsNode( s, true ) ) {
						s.replaceWith.apply( s, Array.prototype.slice.call( s.childNodes ) );
					}
				} );
				done();
			} else {
				restore();
				doc.execCommand( c );
				done();
			}
		} );
		fam.addEventListener( 'change', function () {
			if ( active && fam.value ) {
				styleSel( 'font-family', fam.value );
			}
			fam.value = '';
		} );
		size.addEventListener( 'change', function () {
			if ( active && size.value ) {
				styleSel( 'font-size', size.value );
			}
			size.value = '';
		} );

		on( 'focusin', function ( e ) {
			var t = e.target;
			if ( t.getAttribute && t.getAttribute( 'data-gs-k' ) === 'text' && t !== active ) {
				show( t );
			}
		} );
		on( 'selectionchange', function () {
			var sel = doc.getSelection();
			if ( active && sel && sel.rangeCount && active.contains( sel.getRangeAt( 0 ).commonAncestorContainer ) ) {
				range = sel.getRangeAt( 0 ).cloneRange();
				refresh();
			}
		} );
		on( 'pointerdown', function ( e ) {
			if ( active && ! bar.contains( e.target ) && ! active.contains( e.target ) ) {
				hide();
			}
		}, true );
		on( 'keydown', function ( e ) {
			if ( ! active || e.target !== active ) {
				return;
			}
			if ( ( e.key === 'Enter' && ! e.shiftKey ) || e.key === 'Escape' ) {
				hide();
			} else if ( ( e.ctrlKey || e.metaKey ) && /^[biu]$/i.test( e.key ) ) {
				setTimeout( done, 0 );
			}
		} );
		win.addEventListener( 'scroll', place, { passive: true } );
		return {
			place: place,
			destroy: function () {
				win.removeEventListener( 'scroll', place );
				bar.remove();
			}
		};
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
					}, currentImage( t, kind ) );
				} );
				// A text's HTML, and any change to the element itself, in one write.
				var commitText = function ( t, extra ) {
					var after = t.innerHTML;
					if ( after === t.dataset.gsBefore && ! extra ) {
						return;
					}
					var value = null;
					if ( after !== t.dataset.gsBefore ) {
						var clean = doc.createElement( 'div' );
						clean.innerHTML = after;
						value = cleanInline( clean );
						t.dataset.gsBefore = after;
					}
					commitRef.current( t.getAttribute( 'data-gs-e' ), function ( src ) {
						if ( value !== null ) {
							src.innerHTML = value;
						}
						if ( extra ) {
							extra( src );
						}
					} );
				};
				on( 'focusout', function ( e ) {
					var t = e.target;
					if ( t.getAttribute && t.getAttribute( 'data-gs-k' ) === 'text' ) {
						commitText( t );
					}
				} );
				// Fonts, sizes, styles, colours, links and alignment for the text being edited.
				var tb = textToolbar( doc, on, commitText, function () {
					var r = frame.getBoundingClientRect();
					return { top: Math.max( 0, -r.top ), bottom: Math.min( r.height, view.innerHeight - r.top ) };
				} );
				lis( view, 'scroll', tb.place, { passive: true } );
				cleanup.push( tb.destroy );
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
