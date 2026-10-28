/* GenD Society - dashboard templates show their page in the Content block (inc/design-studio-templates.php). */
( function () {
	'use strict';

	var previews = window.gsTemplatePreviews || {};
	var el = wp.element.createElement;
	var useSelect = wp.data.useSelect;
	var useBlockProps = wp.blockEditor.useBlockProps;

	// The slug of the template being edited, when no page is.
	function useTemplateSlug( context ) {
		return useSelect( function ( select ) {
			if ( context && context.postId ) {
				return '';
			}
			var ed = select( 'core/editor' );
			if ( ! ed || ed.getCurrentPostType() !== 'wp_template' ) {
				return '';
			}
			var rec = select( 'core' ).getEditedEntityRecord( 'postType', 'wp_template', ed.getCurrentPostId() );
			return ( rec && rec.slug ) || '';
		}, [ context && context.postId ] );
	}

	function Preview( props ) {
		var p = previews[ props.slug ];
		var blockProps = useBlockProps( { className: 'gs-tpl-preview' } );
		var ref = wp.element.useRef();
		// The preview page posts its height (it runs in the canvas frame, so listen on that window).
		wp.element.useEffect( function () {
			var f = ref.current;
			var win = f && f.ownerDocument.defaultView;
			if ( ! win ) {
				return;
			}
			var onMsg = function ( e ) {
				if ( e.source === f.contentWindow && e.data && e.data.gsPreviewHeight ) {
					f.style.height = Math.max( 240, Math.min( 6000, e.data.gsPreviewHeight ) ) + 'px';
				}
			};
			win.addEventListener( 'message', onMsg );
			return function () {
				win.removeEventListener( 'message', onMsg );
			};
		}, [] );
		return el( 'div', blockProps,
			el( 'div', { style: { font: '600 11px/1 system-ui,sans-serif', letterSpacing: '.06em', textTransform: 'uppercase', color: '#8b98b0', padding: '8px 0' } }, p.label ),
			el( 'iframe', { src: p.url, title: p.label, tabIndex: -1, ref: ref, style: { display: 'block', width: '100%', height: '480px', border: 0, borderRadius: '10px', pointerEvents: 'none', background: '#0b0e14' } } )
		);
	}

	wp.hooks.addFilter( 'blocks.registerBlockType', 'gend-society/template-content-preview', function ( settings, name ) {
		if ( name !== 'core/post-content' || ! settings.edit ) {
			return settings;
		}
		var Edit = settings.edit;
		settings.edit = function ( props ) {
			var slug = useTemplateSlug( props.context );
			return slug && previews[ slug ] ? el( Preview, { slug: slug } ) : el( Edit, props );
		};
		return settings;
	} );
} )();
