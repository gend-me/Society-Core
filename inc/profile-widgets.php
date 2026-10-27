<?php
/**
 * Profile Widget block: any one of the profile's Overview widgets
 * (portfolio, skills, services, slideshow, video, quote, contact details,
 * custom widgets …) as a block, so profile tab layouts arrange them freely.
 *
 * Members keep filling their widgets in from their profile settings; the
 * block shows the displayed member's widget with the wall's own markup.
 * A new Overview tab layout starts with the widgets the Overview shows
 * today, in the same order.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The widgets a Profile Widget block can show: name => label.
 *
 * @return array
 */
function gend_society_pw_list() {
	if ( ! function_exists( 'youzify_profile_widgets' ) || ! function_exists( 'youzify_get_profile_widget_args' ) ) {
		return array();
	}
	$list = array();
	foreach ( array_keys( (array) youzify_profile_widgets() ) as $name ) {
		$args          = youzify_get_profile_widget_args( $name );
		$list[ $name ] = ! empty( $args['name'] ) ? ucfirst( wp_strip_all_tags( (string) $args['name'] ) ) : $name;
	}
	$custom = function_exists( 'youzify_option' ) ? youzify_option( 'youzify_custom_widgets' ) : array();
	foreach ( is_array( $custom ) ? $custom : array() as $name => $w ) {
		/* translators: %s: custom widget name */
		$list[ $name ] = sprintf( __( 'Custom: %s', 'gend-society' ), isset( $w['name'] ) ? wp_strip_all_tags( (string) $w['name'] ) : $name );
	}
	return $list;
}

add_action( 'init', 'gend_society_pw_register', 22 );
/**
 * Registers the Profile Widget block.
 */
function gend_society_pw_register() {
	$js = 'assets/profile-widget-blocks.js';
	wp_register_script( 'gend-society-profile-widget-blocks', GEND_SOCIETY_URL . $js, array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ), GEND_SOCIETY_VERSION . '.' . filemtime( GEND_SOCIETY_DIR . $js ), true );
	wp_set_script_translations( 'gend-society-profile-widget-blocks', 'gend-society' );
	register_block_type(
		'gend-society/profile-widget',
		array(
			'api_version'     => 3,
			'title'           => __( 'Profile Widget', 'gend-society' ),
			'category'        => 'widgets',
			'editor_script'   => 'gend-society-profile-widget-blocks',
			'render_callback' => 'gend_society_pw_render',
			'attributes'      => array(
				'widget' => array(
					'type'    => 'string',
					'default' => 'about_me',
				),
				'title'  => array(
					'type'    => 'string',
					'default' => 'default',
				),
			),
			'supports'        => array(
				'spacing' => array(
					'margin'  => true,
					'padding' => true,
				),
				'align'   => array( 'wide', 'full' ),
			),
		)
	);
}

add_action( 'enqueue_block_editor_assets', 'gend_society_pw_editor_data' );
/**
 * The widget list for the block's picker.
 */
function gend_society_pw_editor_data() {
	$opts = array();
	foreach ( gend_society_pw_list() as $value => $label ) {
		$opts[] = array(
			'value' => $value,
			'label' => $label,
		);
	}
	wp_add_inline_script( 'gend-society-profile-widget-blocks', 'window.gsProfileWidgets=' . wp_json_encode( $opts ) . ';', 'before' );
}

/**
 * The widget's object. Youzify loads widget classes with a plain include, so
 * reuse a class already loaded (the Overview may have rendered it this request).
 *
 * @param string $name Widget name.
 * @return object|null
 */
function gend_society_pw_object( $name ) {
	$defaults = function_exists( 'youzify_profile_widgets' ) ? (array) youzify_profile_widgets() : array();
	if ( isset( $defaults[ $name ]['class'] ) ) {
		$class = $defaults[ $name ]['class'];
		if ( ! class_exists( $class ) && ! empty( $defaults[ $name ]['file'] ) && defined( 'YOUZIFY_CORE' ) ) {
			$file = YOUZIFY_CORE . 'widgets/youzify-widgets/class-youzify-' . $defaults[ $name ]['file'] . '.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
		return class_exists( $class ) ? new $class() : null;
	}
	if ( function_exists( 'youzify_is_custom_widget' ) && youzify_is_custom_widget( $name ) && defined( 'YOUZIFY_CORE' ) ) {
		require_once YOUZIFY_CORE . 'widgets/youzify-widgets/class-youzify-custom-widgets.php';
		return class_exists( 'Youzify_Profile_Custom_Widget' ) ? new Youzify_Profile_Custom_Widget( $name ) : null;
	}
	return null;
}

/**
 * Profile Widget block.
 *
 * @param array $attrs Block attributes.
 * @return string
 */
function gend_society_pw_render( $attrs ) {
	$name    = isset( $attrs['widget'] ) ? sanitize_key( $attrs['widget'] ) : '';
	$list    = gend_society_pw_list();
	$label   = isset( $list[ $name ] ) ? $list[ $name ] : $name;
	$preview = function_exists( 'gend_society_pv_view' ) && 'block' === gend_society_pv_view();
	$empty   = static function ( $why ) use ( $label ) {
		return '<div class="gdc-ps-placeholder"><strong>' . esc_html( $label ) . '</strong><span>' . esc_html( $why ) . '</span></div>';
	};
	if ( ! isset( $list[ $name ] ) || ! function_exists( 'youzify_widgets' ) ) {
		return $preview ? $empty( __( 'This widget isn’t available.', 'gend-society' ) ) : '';
	}
	if ( ! function_exists( 'bp_is_user' ) || ! bp_is_user() ) {
		return $empty( __( 'Shown on member profiles.', 'gend-society' ) );
	}
	$object = gend_society_pw_object( $name );
	if ( ! $object ) {
		return '';
	}
	$args = youzify_get_profile_widget_args( $name );
	if ( ! is_array( $args ) || empty( $args['id'] ) ) {
		$args = array(
			'id'   => $name,
			'name' => $label,
			'icon' => 'fas fa-puzzle-piece',
		);
	}
	if ( isset( $attrs['title'] ) && in_array( $attrs['title'], array( 'on', 'off' ), true ) ) {
		$args['display_title'] = $attrs['title'];
	}
	ob_start();
	youzify_widgets()->youzify_widget_core( $name, $object, $args );
	$html = (string) ob_get_clean();
	if ( '' === trim( $html ) ) {
		return $preview ? $empty( __( 'Empty for this member: they haven’t filled it in yet (it’s hidden on their profile).', 'gend-society' ) ) : '';
	}
	return '<div ' . get_block_wrapper_attributes( array( 'class' => 'gdc-profile-widget' ) ) . '>' . $html . '</div>';
}

/**
 * A new Profile sidebar layout starts with today's sidebar widgets, in order.
 *
 * @return string
 */
function gend_society_pw_default_sidebar() {
	$fallback = array_fill_keys( array( 'login', 'user_balance', 'user_badges', 'gamipress_user_balance', 'gamipress_user_badges', 'about_me', 'wall_media', 'social_networks', 'friends', 'flickr', 'groups', 'recent_posts', 'user_tags', 'email', 'address', 'website', 'phone' ), 'visible' );
	$widgets  = function_exists( 'youzify_option' ) ? youzify_option( 'youzify_profile_sidebar_widgets', $fallback ) : $fallback;
	$out      = '';
	foreach ( is_array( $widgets ) ? $widgets : array() as $name => $visibility ) {
		if ( 'visible' === $visibility ) {
			$out .= '<!-- wp:gend-society/profile-widget ' . wp_json_encode( array( 'widget' => (string) $name ) ) . ' /-->';
		}
	}
	return $out;
}

/**
 * Whether the profile's right sidebar is being built, and whether a layout replaces its widgets.
 *
 * @param string|null $set 'open', 'close' or 'replace'.
 * @return array { open: bool, replace: bool }
 */
function gend_society_pw_sidebar_state( $set = null ) {
	static $state = array(
		'open'    => false,
		'replace' => false,
	);
	if ( 'open' === $set ) {
		$state = array(
			'open'    => true,
			'replace' => false,
		);
	} elseif ( 'close' === $set ) {
		$state['open'] = false;
	} elseif ( 'replace' === $set ) {
		$state['replace'] = true;
	}
	return $state;
}

add_action(
	'youzify_before_right_sidebar_widgets',
	static function () {
		gend_society_pw_sidebar_state( 'open' );
	}
);

add_filter( 'youzify_profile_sidebar_widgets', 'gend_society_pw_sidebar_widgets', 99 );
/**
 * A published Profile sidebar layout takes the place of the right sidebar's
 * widgets. Youzify only asks for them when the visitor may see the profile.
 *
 * @param array $widgets Sidebar widgets (name => visibility).
 * @return array
 */
function gend_society_pw_sidebar_widgets( $widgets ) {
	$state = gend_society_pw_sidebar_state();
	if ( ! $state['open'] || ! function_exists( 'gend_society_ds_layout' ) || ( function_exists( 'gend_society_pv_view' ) && 'original' === gend_society_pv_view() ) ) {
		return $widgets;
	}
	if ( ! gend_society_pw_sidebar_post() ) {
		return $widgets;
	}
	gend_society_pw_sidebar_state( 'replace' );
	return array();
}

/**
 * The Profile sidebar layout to show (a page can pick another sidebar).
 *
 * @return WP_Post|null
 */
function gend_society_pw_sidebar_post() {
	/** This filter is documented in inc/social-pages.php (gend_society_sp_chosen_sidebar). */
	return apply_filters( 'gend_society_sp_sidebar_layout', gend_society_ds_layout( 'profile-sidebar' ), 'profiles' );
}

add_action( 'youzify_after_right_sidebar_widgets', 'gend_society_pw_sidebar_layout', 0 );
/**
 * Prints the Profile sidebar layout where the widgets were.
 */
function gend_society_pw_sidebar_layout() {
	$state = gend_society_pw_sidebar_state( 'close' );
	$tpl   = $state['replace'] ? gend_society_pw_sidebar_post() : null;
	if ( $tpl ) {
		echo '<div class="gdc-bp-layout gdc-bp-layout--profile-sidebar">' . do_blocks( $tpl->post_content ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered blocks of an admin-authored layout.
	}
}

add_filter( 'gend_society_ps_default_tab_content', 'gend_society_pw_default_overview', 10, 2 );
/**
 * A new Overview tab layout starts with today's Overview widgets, in order.
 *
 * @param string $content Default content.
 * @param string $slug    Tab slug.
 * @return string
 */
function gend_society_pw_default_overview( $content, $slug ) {
	if ( 'overview' !== $slug || ! function_exists( 'youzify_option' ) ) {
		return $content;
	}
	$widgets = apply_filters( 'youzify_profile_main_widgets', youzify_option( 'youzify_profile_main_widgets', array_fill_keys( array( 'slideshow', 'project', 'skills', 'portfolio', 'quote', 'instagram', 'services', 'post', 'link', 'video', 'reviews' ), 'visible' ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the Overview's own filter, so the layout starts with what it shows.
	$out     = '';
	foreach ( is_array( $widgets ) ? $widgets : array() as $name => $visibility ) {
		if ( 'visible' === $visibility ) {
			$out .= '<!-- wp:gend-society/profile-widget ' . wp_json_encode( array( 'widget' => (string) $name ) ) . ' /-->';
		}
	}
	return '' === $out ? $content : $out;
}
