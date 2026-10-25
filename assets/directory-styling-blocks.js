/* GenD Society - Directory blocks (inc/directory-styling.php): server-rendered with live previews. */
( function ( wp ) {
	'use strict';
	if ( ! wp || ! wp.blocks ) {
		return;
	}
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var be = wp.blockEditor;
	var C = wp.components;
	var SSR = wp.serverSideRender;

	function directory( name, icon, description ) {
		wp.blocks.registerBlockType( name, {
			icon: icon,
			description: description,
			edit: function ( props ) {
				var a = props.attributes;
				var set = function ( key ) {
					return function ( v ) {
						var o = {};
						o[ key ] = v;
						props.setAttributes( o );
					};
				};
				return el( wp.element.Fragment, null,
					el( be.InspectorControls, null,
						el( C.PanelBody, { title: __( 'Layout', 'gend-society' ), initialOpen: true },
							el( C.RangeControl, { label: __( 'Columns', 'gend-society' ), min: 1, max: 6, value: a.columns, onChange: set( 'columns' ) } ),
							el( C.RangeControl, { label: __( 'Per page', 'gend-society' ), min: 4, max: 96, value: a.perPage, onChange: set( 'perPage' ) } )
						),
						el( C.PanelBody, { title: __( 'Show', 'gend-society' ), initialOpen: true },
							el( C.ToggleControl, { label: __( 'Search box', 'gend-society' ), checked: a.showSearch, onChange: set( 'showSearch' ) } ),
							el( C.ToggleControl, { label: __( 'Type filter', 'gend-society' ), checked: a.showTypes, onChange: set( 'showTypes' ) } ),
							el( C.ToggleControl, { label: __( 'Sort menu', 'gend-society' ), checked: a.showSort, onChange: set( 'showSort' ) } ),
							el( C.ToggleControl, { label: __( 'Details (type, activity, members)', 'gend-society' ), checked: a.showMeta, onChange: set( 'showMeta' ) } ),
							el( C.ToggleControl, { label: __( 'Connect / Join button', 'gend-society' ), checked: a.showButton, onChange: set( 'showButton' ) } )
						)
					),
					el( 'div', be.useBlockProps(), el( SSR, { block: name, attributes: a, skipBlockSupportAttributes: true } ) )
				);
			},
			save: function () {
				return null;
			},
		} );
	}

	directory( 'gend-society/members-directory', 'groups', __( 'Searchable grid of members with type filter, sorting and pages.', 'gend-society' ) );
	directory( 'gend-society/groups-directory', 'networking', __( 'Searchable grid of groups with type filter, sorting and pages.', 'gend-society' ) );

	wp.blocks.registerBlockType( 'gend-society/original-content', {
		icon: 'media-document',
		description: __( 'What this page shows today, e.g. the activity feed with posting and reactions.', 'gend-society' ),
		edit: function () {
			return el( 'div', be.useBlockProps(), el( SSR, { block: 'gend-society/original-content' } ) );
		},
		save: function () {
			return null;
		},
	} );
}( window.wp ) );
