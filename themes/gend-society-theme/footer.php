<?php
/**
 * Classic get_footer() bridge; companion to header.php.
 *
 * @package GenD_Society_Theme
 */
?>
<?php
if ( ! empty( $GLOBALS['gend_society_theme_wrapped'] ) ) {
	echo '</main><footer class="wp-block-template-part">';
	block_template_part( 'footer' );
	echo '</footer></div>';
} else {
	block_template_part( 'footer' );
}
?>
<?php wp_footer(); ?>
</body>
</html>
