/* GenD Society - Sales blocks (inc/sales-blocks.php).
   Each Sales Team component as a block: live preview, and its own colours,
   sections and wording on top of the site's (Design Studio › Sales). */
( function ( wp ) {
	'use strict';
	var cfg = window.gsSalesBlocks;
	if ( ! wp || ! wp.blocks || ! cfg ) {
		return;
	}
	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var __ = wp.i18n.__;
	var be = wp.blockEditor;
	var C = wp.components;

	var texts = {}; // tag => promise of the component's texts

	function loadTexts( tag ) {
		if ( ! texts[ tag ] ) {
			texts[ tag ] = wp.apiFetch( { path: '/gend-society/v1/studio/shortcode-custom?tag=' + encodeURIComponent( tag ) } ).then( function ( r ) {
				return r.texts || [];
			}, function () {
				return [];
			} );
		}
		return texts[ tag ];
	}

	function previewSrc( tag, custom ) {
		var base = cfg.previewUrl + ( cfg.previewUrl.indexOf( '?' ) === -1 ? '?' : '&' ) + 'gs_studio_shortcode=' + encodeURIComponent( tag );
		return base + '&gs_scx_block=' + encodeURIComponent( JSON.stringify( custom || {} ) );
	}

	// The component, live; it posts its height (inc/design-studio.php preview page).
	function Preview( props ) {
		var ref = useRef( null );
		var h = useState( 360 );
		var src = useState( previewSrc( props.tag, props.custom ) );
		var key = JSON.stringify( props.custom || {} );
		useEffect( function () {
			var t = setTimeout( function () {
				src[ 1 ]( previewSrc( props.tag, props.custom ) );
			}, 500 );
			return function () {
				clearTimeout( t );
			};
		}, [ key ] );
		useEffect( function () {
			var f = ref.current;
			var win = f && f.ownerDocument.defaultView;
			if ( ! win ) {
				return undefined;
			}
			var onMsg = function ( e ) {
				if ( e.source === f.contentWindow && e.data && e.data.gsPreviewHeight ) {
					h[ 1 ]( Math.max( 120, Math.min( 6000, e.data.gsPreviewHeight ) ) );
				}
			};
			win.addEventListener( 'message', onMsg );
			return function () {
				win.removeEventListener( 'message', onMsg );
			};
		}, [] );
		return el( 'iframe', { ref: ref, src: src[ 0 ], title: props.title, tabIndex: -1, style: { display: 'block', width: '100%', height: h[ 0 ] + 'px', border: 0, borderRadius: '12px', pointerEvents: 'none', background: '#0b0e14' } } );
	}

	function Wording( props ) {
		var list = useState( null );
		useEffect( function () {
			loadTexts( props.tag ).then( list[ 1 ] );
		}, [ props.tag ] );
		if ( ! list[ 0 ] ) {
			return el( C.Spinner );
		}
		if ( ! list[ 0 ].length ) {
			return el( 'p', null, __( 'No headings or buttons to rename.', 'gend-society' ) );
		}
		var cur = props.custom.texts || {};
		return list[ 0 ].map( function ( t ) {
			return el( C.TextControl, { key: t, label: t, value: cur[ t ] || '', placeholder: t, __nextHasNoMarginBottom: true, __next40pxDefaultSize: true, onChange: function ( v ) {
				var next = Object.assign( {}, cur );
				if ( v.trim() ) {
					next[ t ] = v;
				} else {
					delete next[ t ];
				}
				props.set( { texts: next } );
			} } );
		} );
	}

	function edit( name, title ) {
		var b = cfg.blocks[ name ];
		return function ( props ) {
			var custom = props.attributes.custom || {};
			var set = function ( patch ) {
				props.setAttributes( { custom: Object.assign( {}, custom, patch ) } );
			};
			var palette = be.useSettings ? be.useSettings( 'color.palette' )[ 0 ] : [];
			var colors = custom.colors || {};
			var hide = custom.ownHide ? custom.hide || [] : null;
			var blockProps = be.useBlockProps( { className: 'gs-sales-block' } );
			var inspector = el( be.InspectorControls, null,
				el( C.PanelBody, { title: __( 'This block', 'gend-society' ) },
					el( 'p', null, __( 'Changes here apply to this block only, on top of the site-wide look.', 'gend-society' ) ),
					el( C.ExternalLink, { href: cfg.studioUrl }, __( 'Site-wide look: Design Studio › Sales', 'gend-society' ) )
				),
				b.colors.length ? el( C.PanelBody, { title: __( 'Colours', 'gend-society' ), initialOpen: false }, b.colors.map( function ( c ) {
					return el( C.BaseControl, { key: c.key, label: c.label, __nextHasNoMarginBottom: true },
						el( C.ColorPalette, { colors: palette || [], value: colors[ c.key ] || undefined, disableCustomColors: false, enableAlpha: false, clearable: true, onChange: function ( v ) {
							var next = Object.assign( {}, colors );
							if ( v && /^#[0-9a-f]{6}$/i.test( v ) ) {
								next[ c.key ] = v;
							} else {
								delete next[ c.key ];
							}
							set( { colors: next } );
						} } )
					);
				} ) ) : null,
				b.sections.length ? el( C.PanelBody, { title: __( 'Sections shown', 'gend-society' ), initialOpen: false },
					el( 'p', null, hide ? __( 'This block shows only the ticked sections.', 'gend-society' ) : __( 'Using the site-wide choice. Change any to set this block’s own.', 'gend-society' ) ),
					b.sections.map( function ( s ) {
						return el( C.ToggleControl, { key: s.key, label: s.label, checked: hide ? hide.indexOf( s.key ) === -1 : true, __nextHasNoMarginBottom: true, onChange: function ( on ) {
							var next = ( hide || [] ).filter( function ( k ) {
								return k !== s.key;
							} );
							if ( ! on ) {
								next.push( s.key );
							}
							set( { hide: next, ownHide: true } );
						} } );
					} ),
					hide ? el( C.Button, { variant: 'link', onClick: function () {
						set( { hide: [], ownHide: false } );
					} }, __( 'Use the site-wide choice', 'gend-society' ) ) : null
				) : null,
				el( C.PanelBody, { title: __( 'Wording', 'gend-society' ), initialOpen: false }, el( Wording, { tag: b.tag, custom: custom, set: set } ) ),
				el( C.PanelBody, { title: __( 'Extra CSS', 'gend-society' ), initialOpen: false },
					el( C.TextareaControl, { label: __( 'Applies to this block only', 'gend-society' ), value: custom.css || '', rows: 6, __nextHasNoMarginBottom: true, onChange: function ( v ) {
						set( { css: v } );
					} } )
				)
			);
			return el( 'div', blockProps, inspector, el( Preview, { tag: b.tag, custom: custom, title: title } ) );
		};
	}

	Object.keys( cfg.blocks ).forEach( function ( name ) {
		var type = wp.blocks.getBlockType( name );
		if ( type && type.edit ) {
			return;
		}
		var title = cfg.blocks[ name ].title;
		// Attributes and supports come from the server registration.
		wp.blocks.registerBlockType( name, {
			apiVersion: 3,
			title: title,
			icon: 'chart-line',
			category: 'gend-sales',
			edit: edit( name, title ),
			save: function () {
				return null;
			},
		} );
	} );
}( window.wp ) );
