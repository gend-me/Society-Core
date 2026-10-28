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
