/* GenD Society - Profile Styling blocks (inc/profile-styling.php). Rendered on the
   server; the editor shows a live preview of your own profile. */
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

	function preview( name ) {
		return function ( props ) {
			return el( 'div', be.useBlockProps(), el( SSR, { block: name, attributes: props.attributes, skipBlockSupportAttributes: true } ) );
		};
	}
	function panel( title, children ) {
		return el( be.InspectorControls, null, el( C.PanelBody, { title: title, initialOpen: true }, children ) );
	}
	function withPanel( name, controls ) {
		return function ( props ) {
			return el( wp.element.Fragment, null, panel( __( 'Settings', 'gend-society' ), controls( props ) ), preview( name )( props ) );
		};
	}
	function reg( name, opts ) {
		wp.blocks.registerBlockType( name, Object.assign( { save: function () {
			return null;
		} }, opts ) );
	}

	reg( 'gend-society/profile-header-classic', { icon: 'id-alt', description: __( 'The full GenD profile header: identity, balances, groups and the tab bar.', 'gend-society' ), edit: preview( 'gend-society/profile-header-classic' ) } );
	reg( 'gend-society/profile-tab-content', { icon: 'welcome-widgets-menus', description: __( 'Where the tab’s own content appears in a tab layout.', 'gend-society' ), edit: preview( 'gend-society/profile-tab-content' ) } );
	reg( 'gend-society/profile-handle', { icon: 'admin-users', edit: preview( 'gend-society/profile-handle' ) } );
	reg( 'gend-society/profile-member-type', { icon: 'awards', edit: preview( 'gend-society/profile-member-type' ) } );
	reg( 'gend-society/profile-actions', { icon: 'button', description: __( 'Connect and message buttons for visitors, Edit profile for the member.', 'gend-society' ), edit: preview( 'gend-society/profile-actions' ) } );
	reg( 'gend-society/profile-nav', { icon: 'menu', description: __( 'The profile’s tabs, with the labels, order and hidden tabs set in Profile Menu.', 'gend-society' ), edit: preview( 'gend-society/profile-nav' ) } );

	reg( 'gend-society/profile-avatar', {
		icon: 'format-image',
		edit: withPanel( 'gend-society/profile-avatar', function ( props ) {
			return [
				el( C.RangeControl, { key: 'size', label: __( 'Size (px)', 'gend-society' ), min: 24, max: 400, value: props.attributes.size, onChange: function ( v ) {
					props.setAttributes( { size: v } );
				} } ),
				el( C.ToggleControl, { key: 'round', label: __( 'Round', 'gend-society' ), checked: props.attributes.round, onChange: function ( v ) {
					props.setAttributes( { round: v } );
				} } ),
			];
		} ),
	} );

	reg( 'gend-society/profile-name', {
		icon: 'heading',
		edit: withPanel( 'gend-society/profile-name', function ( props ) {
			return el( C.SelectControl, { label: __( 'Heading level', 'gend-society' ), value: String( props.attributes.level ), options: [ 1, 2, 3, 4, 5, 6 ].map( function ( n ) {
				return { label: 'H' + n, value: String( n ) };
			} ), onChange: function ( v ) {
				props.setAttributes( { level: parseInt( v, 10 ) } );
			} } );
		} ),
	} );

	reg( 'gend-society/profile-bio', {
		icon: 'text',
		edit: withPanel( 'gend-society/profile-bio', function ( props ) {
			return el( C.TextControl, { label: __( 'Profile field name', 'gend-society' ), help: __( 'The name of a profile field, e.g. Bio. Falls back to the biographical info.', 'gend-society' ), value: props.attributes.field, onChange: function ( v ) {
				props.setAttributes( { field: v } );
			} } );
		} ),
	} );

	reg( 'gend-society/profile-cover', {
		icon: 'cover-image',
		description: __( 'The member’s cover image. Put the avatar, name and buttons inside it.', 'gend-society' ),
		edit: function ( props ) {
			var a = props.attributes;
			var blockProps = be.useBlockProps( { style: { minHeight: a.minHeight + 'px', display: 'flex', flexDirection: 'column', justifyContent: 'flex-end', background: 'linear-gradient(rgba(0,0,0,' + a.overlay / 100 + '),rgba(0,0,0,' + a.overlay / 100 + ')), linear-gradient(135deg,#6ec1e4,#b608c9)' } } );
			return el( wp.element.Fragment, null,
				panel( __( 'Cover', 'gend-society' ), [
					el( C.RangeControl, { key: 'h', label: __( 'Minimum height (px)', 'gend-society' ), min: 80, max: 800, value: a.minHeight, onChange: function ( v ) {
						props.setAttributes( { minHeight: v } );
					} } ),
					el( C.RangeControl, { key: 'o', label: __( 'Darken (%)', 'gend-society' ), min: 0, max: 90, value: a.overlay, onChange: function ( v ) {
						props.setAttributes( { overlay: v } );
					} } ),
				] ),
				el( 'div', blockProps, el( be.InnerBlocks, { templateLock: false } ) )
			);
		},
		save: function () {
			return el( be.InnerBlocks.Content );
		},
	} );
}( window.wp ) );
