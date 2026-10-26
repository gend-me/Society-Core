/* GenD Society - Activity wall blocks (inc/activity-blocks.php).
   Each block previews the real activity page part, live, in the editor. */
( function ( wp ) {
	'use strict';
	if ( ! wp || ! wp.blocks ) {
		return;
	}
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var be = wp.blockEditor;
	var C = wp.components;

	function previewUrl( name, attrs ) {
		var cfg = window.gsStudioPreview;
		var base = cfg && cfg.directories && cfg.directories.activity;
		if ( ! base ) {
			return '';
		}
		return base + ( base.indexOf( '?' ) === -1 ? '?' : '&' ) + 'gs_studio_view=block&block=' + encodeURIComponent( name ) + '&attrs=' + encodeURIComponent( JSON.stringify( attrs || {} ) );
	}

	function Frame( props ) {
		var ref = wp.element.useRef( null );
		var st = wp.element.useState( 260 );
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

	function edit( name, title, controls ) {
		return function ( props ) {
			var blockProps = be.useBlockProps();
			var src = previewUrl( name, props.attributes );
			return el( 'div', blockProps,
				controls ? el( be.InspectorControls, null, el( C.PanelBody, { title: __( 'Settings', 'gend-society' ) }, controls( props ) ) ) : null,
				el( 'div', { className: 'gs-pv-label' }, title, el( 'span', null, src ? __( 'Live preview', 'gend-society' ) : __( 'Shown on the activity feed page', 'gend-society' ) ) ),
				src ? el( 'div', { className: 'gs-pv-wrap' }, el( Frame, { src: src, title: title } ) ) : el( 'div', { className: 'gdc-ps-placeholder' }, el( 'strong', null, title ) )
			);
		};
	}

	function toggle( props, key, label ) {
		return el( C.ToggleControl, { label: label, checked: !! props.attributes[ key ], onChange: function ( v ) {
			var o = {};
			o[ key ] = v;
			props.setAttributes( o );
		}, __nextHasNoMarginBottom: true } );
	}

	var blocks = {
		'gend-society/activity-tabs': {
			title: __( 'Activity Filter Bar', 'gend-society' ),
			icon: 'menu-alt',
			description: __( 'The feed tabs (All Members, My Friends, My Groups, Favorites, Mentions), the filter and search.', 'gend-society' ),
			controls: function ( props ) {
				return [ toggle( props, 'showFilter', __( 'Show the filter', 'gend-society' ) ), toggle( props, 'showSearch', __( 'Show search', 'gend-society' ) ) ];
			},
		},
		'gend-society/activity-post-form': {
			title: __( 'Activity Post Form', 'gend-society' ),
			icon: 'edit',
			description: __( '“What’s new?” posting, with photos, polls and moods. Signed-in members only.', 'gend-society' ),
			controls: function ( props ) {
				return [ toggle( props, 'loginPrompt', __( 'Ask visitors to sign in', 'gend-society' ) ) ];
			},
		},
		'gend-society/activity-feed': {
			title: __( 'Activity Feed', 'gend-society' ),
			icon: 'rss',
			description: __( 'The posts, with comments, reactions and Load More.', 'gend-society' ),
		},
		'gend-society/activity-sidebar': {
			title: __( 'Activity Sidebar', 'gend-society' ),
			icon: 'align-pull-right',
			description: __( 'The wall’s widgets (Appearance › Widgets › Wall sidebar).', 'gend-society' ),
			controls: function ( props ) {
				return el( C.SelectControl, { label: __( 'Widget area', 'gend-society' ), value: props.attributes.side, options: [
					{ label: __( 'Wall sidebar (right)', 'gend-society' ), value: 'right' },
					{ label: __( 'Wall left sidebar', 'gend-society' ), value: 'left' },
				], onChange: function ( v ) {
					props.setAttributes( { side: v } );
				}, __nextHasNoMarginBottom: true } );
			},
		},
	};

	Object.keys( blocks ).forEach( function ( name ) {
		var b = blocks[ name ];
		if ( wp.blocks.getBlockType( name ) && wp.blocks.getBlockType( name ).edit ) {
			return;
		}
		wp.blocks.registerBlockType( name, {
			apiVersion: 3,
			title: b.title,
			icon: b.icon,
			category: 'widgets',
			description: b.description,
			edit: edit( name, b.title, b.controls ),
			save: function () {
				return null;
			},
		} );
	} );
}( window.wp ) );
