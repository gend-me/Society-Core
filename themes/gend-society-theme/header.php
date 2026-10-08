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
<?php block_template_part( 'header' ); ?>
