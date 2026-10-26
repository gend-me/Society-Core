/* GenD Society - Profile Widget block (inc/profile-widgets.php).
   Pick one of the profile's Overview widgets; it previews live on your profile. */
( function ( wp ) {
	'use strict';
	if ( ! wp || ! wp.blocks ) {
		return;
	}
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var be = wp.blockEditor;
	var C = wp.components;
	var NAME = 'gend-society/profile-widget';

	function previewUrl( attrs ) {
		var cfg = window.gsStudioPreview;
		var base = cfg && cfg.profileUrl;
		if ( ! base ) {
			return '';
		}
		return base + ( base.indexOf( '?' ) === -1 ? '?' : '&' ) + 'gs_studio_view=block&block=' + encodeURIComponent( NAME ) + '&attrs=' + encodeURIComponent( JSON.stringify( attrs || {} ) );
	}

	function Frame( props ) {
		var ref = wp.element.useRef( null );
		var st = wp.element.useState( 200 );
		wp.element.useEffect( function () {
			var f = ref.current;
			if ( ! f ) {
				return undefined;
			}
			var timer;
			var measure = function () {
				try {
					var d = f.contentDocument;
					if ( d && d.body ) {
						st[ 1 ]( Math.max( 80, Math.min( 3000, d.body.scrollHeight ) ) );
					}
				} catch ( e ) {}
			};
			var onLoad = function () {
				var n = 0;
				measure();
				clearInterval( timer );
				timer = setInterval( function () {
					measure();
					if ( ++n > 20 ) {
						clearInterval( timer );
					}
				}, 500 );
			};
			f.addEventListener( 'load', onLoad );
			return function () {
				f.removeEventListener( 'load', onLoad );
				clearInterval( timer );
			};
		}, [ props.src ] );
		return el( 'iframe', { ref: ref, className: 'gs-pv-frame', src: props.src, title: props.title, style: { height: st[ 0 ] + 'px' }, loading: 'lazy' } );
	}

	function labelOf( value ) {
		var list = window.gsProfileWidgets || [];
		for ( var i = 0; i < list.length; i++ ) {
			if ( list[ i ].value === value ) {
				return list[ i ].label;
			}
		}
		return value;
	}

	wp.blocks.registerBlockType( NAME, {
		apiVersion: 3,
		title: __( 'Profile Widget', 'gend-society' ),
		icon: 'id-alt',
		category: 'widgets',
		description: __( 'One of the profile’s widgets (portfolio, skills, services, video, contact details …), filled in by each member.', 'gend-society' ),
		edit: function ( props ) {
			var a = props.attributes;
			var src = previewUrl( a );
			var title = __( 'Profile Widget', 'gend-society' ) + ': ' + labelOf( a.widget );
			return el( 'div', be.useBlockProps(),
				el( be.InspectorControls, null, el( C.PanelBody, { title: __( 'Widget', 'gend-society' ) },
					el( C.SelectControl, { label: __( 'Show', 'gend-society' ), value: a.widget, options: window.gsProfileWidgets || [], onChange: function ( v ) {
						props.setAttributes( { widget: v } );
					}, __nextHasNoMarginBottom: true } ),
					el( C.SelectControl, { label: __( 'Title', 'gend-society' ), value: a.title, options: [
						{ label: __( 'As in the widget settings', 'gend-society' ), value: 'default' },
						{ label: __( 'Show', 'gend-society' ), value: 'on' },
						{ label: __( 'Hide', 'gend-society' ), value: 'off' },
					], onChange: function ( v ) {
						props.setAttributes( { title: v } );
					}, __nextHasNoMarginBottom: true } ),
					el( 'p', { className: 'components-base-control__help' }, __( 'Preview shows your own profile. Members fill their widgets in from their profile settings; empty widgets are hidden.', 'gend-society' ) )
				) ),
				el( 'div', { className: 'gs-pv-label' }, title, el( 'span', null, src ? __( 'Live preview', 'gend-society' ) : __( 'Shown on member profiles', 'gend-society' ) ) ),
				src ? el( 'div', { className: 'gs-pv-wrap' }, el( Frame, { src: src, title: title } ) ) : el( 'div', { className: 'gdc-ps-placeholder' }, el( 'strong', null, title ) )
			);
		},
		save: function () {
			return null;
		},
	} );
}( window.wp ) );
