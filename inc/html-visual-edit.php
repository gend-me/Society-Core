<?php
/**
 * Custom HTML blocks, visual and editable in place, in every block editor.
 *
 * The block shows as the page looks, in a live frame that runs its own
 * scripts (animations, tabs, scroll scenes). Text is edited by clicking it,
 * images through "Replace image"; changes are written back into the block's
 * HTML. "Edit HTML" in the block toolbar shows the code. See
 * assets/html-visual-edit.js.
 *
 * @package GenD_Society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'enqueue_block_editor_assets', 'gend_society_hv_editor_assets' );
/**
 * The editor script.
 */
function gend_society_hv_editor_assets() {
	if ( ! is_admin() ) {
		return;
	}
	$js = 'assets/html-visual-edit.js';
	wp_enqueue_script( 'gend-society-html-visual', GEND_SOCIETY_URL . $js, array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-hooks', 'wp-i18n', 'wp-compose', 'wp-data', 'wp-api-fetch', 'wp-components', 'wp-notices' ), GEND_SOCIETY_VERSION . '.' . filemtime( GEND_SOCIETY_DIR . $js ), true );
	wp_add_inline_script(
		'gend-society-html-visual',
		'window.gsHtmlVisual=' . wp_json_encode(
			array(
				'siteUrl'  => home_url( '/' ),
				// {{tokens}} are filled by Live HTML, where it is installed.
				'liveHtml' => function_exists( 'gend_society_lh_fill' ) && current_user_can( 'edit_theme_options' ),
			)
		) . ';',
		'before'
	);
	if ( function_exists( 'wp_enqueue_media' ) && current_user_can( 'upload_files' ) ) {
		wp_enqueue_media();
	}
	wp_register_style( 'gend-society-html-visual-media', false, array(), GEND_SOCIETY_VERSION );
	wp_enqueue_style( 'gend-society-html-visual-media' );
	wp_add_inline_style( 'gend-society-html-visual-media', gend_society_hv_media_css() );
}

/**
 * The "Replace image" picker: dark like the studio, plain image cards (the
 * optimizer's library-page tools stay on the Media Library page), and a bar
 * showing the image being replaced.
 *
 * @return string
 */
function gend_society_hv_media_css() {
	$m = '.gs-hv-media';
	return $m . ' .media-modal{inset:40px;border-radius:18px;overflow:hidden;box-shadow:0 30px 80px rgba(0,0,0,.55),0 0 0 1px rgba(110,193,228,.22)}'
		. $m . ' .media-modal-content,' . $m . ' .media-frame,' . $m . ' .media-frame-content,' . $m . ' .media-frame-router,' . $m . ' .media-frame-title,' . $m . ' .media-frame-toolbar .media-toolbar,' . $m . ' .attachments-browser .media-toolbar{background:#0f141d!important;color:#e6edf7}'
		. $m . ' .media-frame-title h1{color:#fff!important;font:700 20px/1.2 system-ui,sans-serif;padding:0 24px}'
		. $m . ' .media-modal-close{color:#94a3b8}' . $m . ' .media-modal-close:hover,' . $m . ' .media-modal-close:focus{color:#fff;background:transparent;box-shadow:none}'
		. $m . ' .media-modal-close .media-modal-icon:before{color:inherit}'
		// Tabs.
		. $m . ' .media-frame-router{border-bottom:1px solid rgba(255,255,255,.08)}'
		. $m . ' .media-router{padding:0 16px}'
		. $m . ' .media-router .media-menu-item{background:transparent!important;color:#94a3b8!important;border:0!important;border-bottom:2px solid transparent!important;border-radius:0;margin:0 6px 0 0;padding:10px 12px;font:600 14px system-ui,sans-serif;box-shadow:none!important}'
		. $m . ' .media-router .media-menu-item:hover{color:#fff!important}'
		. $m . ' .media-router .media-menu-item.active{color:#fff!important;border-bottom-color:#6ec1e4!important}'
		. $m . ' .media-router .media-menu-item.active:after{display:none}'
		// Filters and search.
		. $m . ' .media-toolbar label,' . $m . ' .media-toolbar .media-attachments-filter-heading{color:#94a3b8!important;font:600 11px system-ui,sans-serif;letter-spacing:.06em;text-transform:uppercase}'
		. $m . ' .media-toolbar select,' . $m . ' .media-toolbar input[type=search],' . $m . ' .media-sidebar input,' . $m . ' .media-sidebar textarea,' . $m . ' .media-sidebar select{background:#0b0e14!important;color:#e6edf7!important;border:1px solid rgba(255,255,255,.14)!important;border-radius:8px!important;min-height:34px;box-shadow:none!important}'
		. $m . ' .media-toolbar select:focus,' . $m . ' .media-toolbar input[type=search]:focus,' . $m . ' .media-sidebar input:focus,' . $m . ' .media-sidebar textarea:focus{border-color:#6ec1e4!important;outline:0}'
		// Image cards.
		. $m . ' .attachments-browser .attachments{background:transparent;padding:4px 8px}'
		. $m . ' .attachments-browser .attachment{padding:6px;margin:0}'
		. $m . ' .attachments-browser .gmo-lib-chrome{display:none!important}'
		. $m . ' .attachments-browser li.attachment:after{display:none!important}'
		. $m . ' .attachment-preview{position:relative;border-radius:12px;overflow:hidden;background:#151b26!important;box-shadow:0 0 0 1px rgba(255,255,255,.07)!important;transition:box-shadow .15s,transform .15s}'
		. $m . ' .attachment:hover .attachment-preview{box-shadow:0 0 0 2px rgba(110,193,228,.6)!important}'
		. $m . ' .attachment.details .attachment-preview,' . $m . ' .attachment.selected .attachment-preview{box-shadow:0 0 0 3px #97e46e,0 8px 22px rgba(151,228,110,.18)!important}'
		. $m . ' .attachment:focus,' . $m . ' .attachment.selected:focus{box-shadow:none!important;outline:0}'
		. $m . ' .attachment-preview .thumbnail:after{box-shadow:none!important}'
		. $m . ' .attachment-preview .gmo-lib-title{position:absolute!important;left:0;right:0;bottom:0;top:auto!important;margin:0!important;padding:22px 10px 8px!important;background:linear-gradient(transparent,rgba(5,8,14,.92))!important;color:#e6edf7!important;font:600 12px/1.3 system-ui,sans-serif!important;text-align:left!important;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;border:0!important;pointer-events:none}'
		. $m . ' .attachment .check{background:#97e46e!important;border-radius:50%!important;box-shadow:0 0 0 2px #0f141d!important;top:12px!important;right:12px!important}'
		. $m . ' .attachment .check .media-modal-icon{filter:brightness(0)}'
		// Selected image details.
		. $m . ' .media-sidebar{background:#0b0e14!important;color:#cbd5e1;border-left:1px solid rgba(255,255,255,.08)!important}'
		. $m . ' .media-sidebar h2,' . $m . ' .media-sidebar .setting .name,' . $m . ' .media-sidebar label span{color:#94a3b8!important}'
		. $m . ' .attachment-details .filename{color:#fff;font-weight:600}'
		. $m . ' .attachment-details .uploaded,' . $m . ' .attachment-details .file-size,' . $m . ' .attachment-details .dimensions{color:#94a3b8}'
		. $m . ' .attachment-details .thumbnail img{border-radius:10px}'
		. $m . ' .media-sidebar a,' . $m . ' .media-sidebar .button-link{color:#6ec1e4}'
		. $m . ' .media-sidebar .button-link.delete-attachment{color:#f87171}'
		// Upload tab.
		. $m . ' .uploader-inline,' . $m . ' .uploader-inline-content{background:transparent;color:#e6edf7}'
		. $m . ' .uploader-inline h2{color:#fff}'
		. $m . ' .uploader-inline .browser{background:transparent!important;color:#6ec1e4!important;border:1px solid #6ec1e4!important;border-radius:999px!important}'
		. $m . ' .uploader-inline .max-upload-size{color:#94a3b8}'
		// Bottom bar: the image being replaced, and the button.
		. $m . ' .media-frame-toolbar .media-toolbar{border-top:1px solid rgba(255,255,255,.08)}'
		. $m . ' .media-toolbar .button-primary{background:#97e46e!important;border:0!important;color:#0b0e14!important;border-radius:999px!important;padding:0 22px!important;font-weight:700;box-shadow:none!important}'
		. $m . ' .media-toolbar .button-primary[disabled]{background:rgba(255,255,255,.1)!important;color:#64748b!important}'
		. $m . ' .gs-hv-current{position:absolute;left:16px;bottom:0;height:60px;display:flex;align-items:center;gap:12px;z-index:200;max-width:55%;pointer-events:none}'
		. $m . ' .gs-hv-current[hidden]{display:none}'
		. $m . ' .gs-hv-current img{width:72px;height:44px;object-fit:cover;border-radius:8px;box-shadow:0 0 0 1px rgba(255,255,255,.18);background:#151b26}'
		. $m . ' .gs-hv-current span{display:flex;flex-direction:column;min-width:0;line-height:1.3}'
		. $m . ' .gs-hv-current small{color:#94a3b8;font:600 10px system-ui,sans-serif;letter-spacing:.08em;text-transform:uppercase}'
		. $m . ' .gs-hv-current b{color:#e6edf7;font:600 13px system-ui,sans-serif;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}';
}

add_action( 'enqueue_block_assets', 'gend_society_hv_canvas_styles' );
/**
 * The frame, its hint and the code box, in the editor canvas.
 */
function gend_society_hv_canvas_styles() {
	if ( ! is_admin() ) {
		return;
	}
	$css = '.gs-hv-stage{position:relative}.gs-hv-frame{display:block;width:100%;height:420px;border:0;border-radius:10px;background:transparent;will-change:transform}'
		. '.gs-lh-hint{margin:0 0 6px;padding:8px 12px;border-radius:10px;background:rgba(110,193,228,.12);color:#2f7fa3;font:500 12px/1.4 system-ui,sans-serif}'
		. '.gs-lh-loading{padding:18px;color:#8a94a6;font:500 13px system-ui,sans-serif}'
		. '.gs-hv-code textarea{display:block;width:100%;box-sizing:border-box;padding:14px;border:1px solid rgba(110,193,228,.45);border-radius:10px;background:#0b0e14;color:#d7e3f4;font:12.5px/1.55 ui-monospace,Menlo,Consolas,monospace;tab-size:2;resize:vertical}';
	wp_register_style( 'gend-society-html-visual', false, array(), GEND_SOCIETY_VERSION );
	wp_enqueue_style( 'gend-society-html-visual' );
	wp_add_inline_style( 'gend-society-html-visual', $css );
}
