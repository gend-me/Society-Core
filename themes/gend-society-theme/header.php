<?php
/**
 * Classic get_header() bridge for this block theme.
 *
 * BuddyPress / Youzify / Elementor templates call get_header(). A block theme
 * normally has no header.php, so WordPress falls back to
 * wp-includes/theme-compat/header.php (the old "Kubrick" stub) and the pages
 * lose the Site Editor header. This file renders the header template part
 * instead. It lives in the theme itself, so updates can't remove it any more.
 *
 * @package GenD_Society_Theme
 */
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>" />
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<?php
// A web app's community pages sit in the site's own layout, so its header and footer styles apply.
$gend_society_wrap = function_exists( 'gend_society_hb_community' ) && gend_society_hb_community();
$GLOBALS['gend_society_theme_wrapped'] = $gend_society_wrap;
if ( $gend_society_wrap ) {
	echo '<div class="wp-site-blocks"><header class="wp-block-template-part">';
	block_template_part( 'header' );
	echo '</header><main class="gs-community-main">';
} else {
	block_template_part( 'header' );
}
?>
