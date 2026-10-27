/**
 * Scroll Scene block — editor. Images are media-library attachments (add,
 * replace, reorder, remove); each has its own scroll effect. A preview
 * slider shows the animation inside the editor. Content on top is ordinary
 * inner blocks. No build step: plain wp.element.
 */
( function ( wp ) {
	'use strict';
	if ( ! wp || ! wp.blocks ) {
		return;
	}
	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var __ = wp.i18n.__;
	var be = wp.blockEditor;
	var c = wp.components;

	var EFFECTS = [
		{ value: 'zoom-in', label: __( 'Slow zoom in', 'gend-society' ) },
		{ value: 'zoom-out', label: __( 'Slow zoom out', 'gend-society' ) },
		{ value: 'pan-left', label: __( 'Pan left', 'gend-society' ) },
		{ value: 'pan-right', label: __( 'Pan right', 'gend-society' ) },
		{ value: 'pan-up', label: __( 'Drift up', 'gend-society' ) },
		{ value: 'parallax', label: __( 'Parallax', 'gend-society' ) },
		{ value: 'blur-in', label: __( 'Blur into focus', 'gend-society' ) },
		{ value: 'none', label: __( 'No movement (fade only)', 'gend-society' ) }
	];

	function clamp( v ) {
		return v < 0 ? 0 : v > 1 ? 1 : v;
	}

	// Same math as assets/scroll-scene.js, for the preview slider.
	function look( name, p ) {
		switch ( name ) {
			case 'zoom-in': return { transform: 'scale(' + ( 1 + 0.22 * p ) + ')' };
			case 'zoom-out': return { transform: 'scale(' + ( 1.22 - 0.22 * p ) + ')' };
			case 'pan-left': return { transform: 'scale(1.14) translateX(' + ( 3 - 6 * p ) + '%)' };
			case 'pan-right': return { transform: 'scale(1.14) translateX(' + ( -3 + 6 * p ) + '%)' };
			case 'pan-up': return { transform: 'scale(1.16) translateY(' + ( 4 - 8 * p ) + '%)' };
			case 'parallax': return { transform: 'scale(1.18) translateY(' + ( ( 0.5 - p ) * 12 ) + '%)' };
			case 'blur-in': return { transform: 'scale(' + ( 1.08 - 0.08 * p ) + ')', filter: 'blur(' + ( ( 1 - Math.min( 1, p * 2 ) ) * 14 ).toFixed( 1 ) + 'px)' };
			default: return {};
		}
	}

	function tintColor( v ) {
		if ( ! v ) {
			return '';
		}
		return /^#/.test( v ) ? v : 'var(--wp--preset--color--' + v + ')';
	}

	function fromMedia( m, effect ) {
		return { id: m.id, url: ( m.sizes && m.sizes.large && m.sizes.large.url ) || m.url, alt: m.alt || '', effect: effect || 'zoom-in' };
	}

	wp.blocks.registerBlockType( 'gend-society/scroll-scene', {
		apiVersion: 3,
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			var layers = a.layers || [];
			var preview = useState( 0 );
			var p = preview[ 0 ];
			var palette = ( be.useSettings ? be.useSettings( 'color.palette' )[ 0 ] : be.useSetting( 'color.palette' ) ) || [];
			var blockProps = be.useBlockProps( { className: 'gss-editor' } );
			var inner = be.useInnerBlocksProps( { className: 'gss-content gss-content--' + ( a.contentAlign || 'center' ) }, {
				template: [ [ 'core/heading', { textAlign: 'center', placeholder: __( 'Headline over the scene', 'gend-society' ) } ], [ 'core/paragraph', { align: 'center', placeholder: __( 'Supporting line…', 'gend-society' ) } ] ]
			} );

			function update( i, patch ) {
				set( { layers: layers.map( function ( l, j ) { return j === i ? Object.assign( {}, l, patch ) : l; } ) } );
			}
			function move( i, d ) {
				var next = layers.slice();
				var j = i + d;
				if ( j < 0 || j >= next.length ) {
					return;
				}
				var t = next[ i ];
				next[ i ] = next[ j ];
				next[ j ] = t;
				set( { layers: next } );
			}

			var n = layers.length || 1;
			var seg = 1 / n;
			var fade = Math.min( 0.12, seg * 0.4 );
			var shown = layers.map( function ( l, i ) {
				var start = i * seg;
				var opacity = i === 0 ? 1 : clamp( ( p - start + fade ) / ( 2 * fade ) );
				var local = n === 1 ? p : clamp( ( p - start + fade ) / ( seg + 2 * fade ) );
				return el( 'div', { key: l.id || i, className: 'gss-layer', style: Object.assign( { opacity: opacity }, look( l.effect, local ) ) }, el( 'img', { src: l.url, alt: l.alt || '' } ) );
			} );

			var controls = el( be.InspectorControls, null,
				el( c.PanelBody, { title: __( 'Images', 'gend-society' ), initialOpen: true },
					layers.map( function ( l, i ) {
						return el( 'div', { key: l.id || i, style: { display: 'grid', gridTemplateColumns: '56px 1fr', gap: '8px', marginBottom: '12px', alignItems: 'start' } },
							el( 'img', { src: l.url, alt: '', style: { width: '56px', height: '56px', objectFit: 'cover', borderRadius: '4px' } } ),
							el( 'div', null,
								el( c.SelectControl, { label: __( 'Effect', 'gend-society' ), value: l.effect || 'zoom-in', options: EFFECTS, onChange: function ( v ) { update( i, { effect: v } ); }, __nextHasNoMarginBottom: true } ),
								el( 'div', { style: { display: 'flex', gap: '4px', flexWrap: 'wrap', marginTop: '6px' } },
									el( be.MediaUploadCheck, null, el( be.MediaUpload, {
										onSelect: function ( m ) { update( i, fromMedia( m, l.effect ) ); },
										allowedTypes: [ 'image' ],
										value: l.id,
										render: function ( o ) { return el( c.Button, { variant: 'secondary', size: 'small', onClick: o.open }, __( 'Replace', 'gend-society' ) ); }
									} ) ),
									el( c.Button, { size: 'small', onClick: function () { move( i, -1 ); }, disabled: i === 0, label: __( 'Earlier', 'gend-society' ), icon: 'arrow-up-alt2' } ),
									el( c.Button, { size: 'small', onClick: function () { move( i, 1 ); }, disabled: i === layers.length - 1, label: __( 'Later', 'gend-society' ), icon: 'arrow-down-alt2' } ),
									el( c.Button, { size: 'small', isDestructive: true, onClick: function () { set( { layers: layers.filter( function ( x, j ) { return j !== i; } ) } ); }, label: __( 'Remove', 'gend-society' ), icon: 'trash' } )
								)
							)
						);
					} ),
					el( be.MediaUploadCheck, null, el( be.MediaUpload, {
						onSelect: function ( list ) { set( { layers: layers.concat( ( Array.isArray( list ) ? list : [ list ] ).map( function ( m ) { return fromMedia( m ); } ) ) } ); },
						allowedTypes: [ 'image' ],
						multiple: true,
						render: function ( o ) { return el( c.Button, { variant: 'primary', onClick: o.open }, layers.length ? __( 'Add images', 'gend-society' ) : __( 'Choose images', 'gend-society' ) ); }
					} ) ),
					el( 'p', { style: { marginTop: '10px', color: '#757575' } }, __( 'One image animates through the whole scroll; several images fade into each other in this order.', 'gend-society' ) )
				),
				el( c.PanelBody, { title: __( 'Scroll', 'gend-society' ), initialOpen: true },
					el( c.RangeControl, { label: __( 'Scroll length (% of screen height)', 'gend-society' ), value: a.height, min: 150, max: 800, step: 10, onChange: function ( v ) { set( { height: v } ); } } ),
					el( c.RangeControl, { label: __( 'Preview scroll position', 'gend-society' ), value: Math.round( p * 100 ), min: 0, max: 100, onChange: function ( v ) { preview[ 1 ]( v / 100 ); } } ),
					el( c.SelectControl, { label: __( 'Content position', 'gend-society' ), value: a.contentAlign || 'center', options: [ { value: 'top', label: __( 'Top', 'gend-society' ) }, { value: 'center', label: __( 'Middle', 'gend-society' ) }, { value: 'bottom', label: __( 'Bottom', 'gend-society' ) } ], onChange: function ( v ) { set( { contentAlign: v } ); } } )
				),
				el( c.PanelBody, { title: __( 'Overlay', 'gend-society' ), initialOpen: false },
					el( c.ColorPalette, {
						colors: palette,
						value: a.overlay && ! /^#/.test( a.overlay ) ? ( ( palette.filter( function ( x ) { return x.slug === a.overlay; } )[ 0 ] || {} ).color ) : a.overlay,
						onChange: function ( v ) {
							var hit = palette.filter( function ( x ) { return x.color === v; } )[ 0 ];
							set( { overlay: hit ? hit.slug : ( v || '' ) } );
						}
					} ),
					el( c.RangeControl, { label: __( 'Overlay strength', 'gend-society' ), value: a.overlayOpacity, min: 0, max: 90, onChange: function ( v ) { set( { overlayOpacity: v } ); } } )
				)
			);

			if ( ! layers.length ) {
				return el( 'div', blockProps, controls,
					el( c.Placeholder, { icon: 'images-alt2', label: __( 'Scroll Scene', 'gend-society' ), instructions: __( 'Pick one image to animate with the scroll, or several to fade between.', 'gend-society' ) },
						el( be.MediaUploadCheck, null, el( be.MediaUpload, {
							onSelect: function ( list ) { set( { layers: ( Array.isArray( list ) ? list : [ list ] ).map( function ( m ) { return fromMedia( m ); } ) } ); },
							allowedTypes: [ 'image' ],
							multiple: true,
							render: function ( o ) { return el( c.Button, { variant: 'primary', onClick: o.open }, __( 'Choose images', 'gend-society' ) ); }
						} ) )
					)
				);
			}
			return el( 'div', blockProps, controls,
				shown,
				a.overlay && a.overlayOpacity ? el( 'div', { className: 'gss-tint', style: { background: tintColor( a.overlay ), opacity: a.overlayOpacity / 100 } } ) : null,
				el( 'div', inner )
			);
		},
		save: function () {
			return el( be.InnerBlocks.Content );
		}
	} );
}( window.wp ) );
