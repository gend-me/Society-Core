<?php
/**
 * What the approved style guide reaches beyond the theme: the store's own pages, its emails and the social emails.
 * Only a site set up through the chat has a brand saved. Every other site is left as it is.
 *
 * @package GenD_Society
 */

defined( 'ABSPATH' ) || exit;

const GEND_SOCIETY_SB_OPTION = 'gend_society_setup_brand';

/** The saved colours, or nothing when this site wasn't set up through the chat. */
function gend_society_sb_brand() {
	$brand = get_option( GEND_SOCIETY_SB_OPTION );
	return is_array( $brand ) ? $brand : array();
}

/**
 * Saves the style guide's colours and puts them on the store emails.
 *
 * @param array $colors primary, background, text, heading, link, caption.
 * @return array
 */
function gend_society_sb_save( array $colors ) {
	$brand = array();
	foreach ( array( 'primary', 'background', 'text', 'heading', 'link', 'caption' ) as $role ) {
		$hex = sanitize_hex_color( (string) ( $colors[ $role ] ?? '' ) );
		if ( $hex ) {
			$brand[ $role ] = $hex;
		}
	}
	if ( ! isset( $brand['primary'], $brand['background'], $brand['text'] ) ) {
		return array( 'error' => 'primary, background and text must be exact #RRGGBB colours.' );
	}
	$brand += array( 'heading' => $brand['text'], 'link' => $brand['primary'], 'caption' => $brand['text'] );
	foreach ( array( 'heading_stack', 'body_stack' ) as $stack ) {
		if ( ! empty( $colors[ $stack ] ) && preg_match( '/^[A-Za-z0-9 ,\'"-]{2,120}$/', (string) $colors[ $stack ] ) ) {
			$brand[ $stack ] = str_replace( '"', "'", (string) $colors[ $stack ] );
		}
	}
	$saved = gend_society_sb_brand();
	if ( isset( $saved['template_logo'] ) ) {
		$brand['template_logo'] = $saved['template_logo'];
	} elseif ( ! $saved && gend_society_sb_logo_id() && time() - (int) get_post_time( 'U', true, gend_society_sb_logo_id() ) > DAY_IN_SECONDS ) {
		// Already old when setup reached the style guide, so it came with the template.
		$brand['template_logo'] = gend_society_sb_logo_id();
	}
	update_option( GEND_SOCIETY_SB_OPTION, $brand, false );
	gend_society_sb_store_emails( $brand );
	gend_society_sb_placeholder( $brand );
	return array( 'saved' => array_keys( $brand ) );
}

/** The site's name as people read it. */
function gend_society_sb_site_name() {
	return wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
}

/** The logo's media id. */
function gend_society_sb_logo_id() {
	$id = (int) get_theme_mod( 'custom_logo' );
	return $id ? $id : (int) get_option( 'site_logo' );
}

/** The site's own logo, when it has one. The one a new site came with stays out of its emails. */
function gend_society_sb_logo_url() {
	$id = gend_society_sb_logo_id();
	if ( ! $id || (int) ( gend_society_sb_brand()['template_logo'] ?? 0 ) === $id ) {
		return '';
	}
	$url = wp_get_attachment_image_url( $id, 'medium' );
	return $url ? (string) $url : '';
}

/** Mixes two #RRGGBB colours. Amount is how much of the second. */
function gend_society_sb_mix( $a, $b, $amount ) {
	$a = sscanf( $a, '#%02x%02x%02x' );
	$b = sscanf( $b, '#%02x%02x%02x' );
	$out = '#';
	foreach ( array( 0, 1, 2 ) as $i ) {
		$out .= sprintf( '%02x', (int) round( $a[ $i ] + ( $b[ $i ] - $a[ $i ] ) * $amount ) );
	}
	return $out;
}

/** Dark or light words, whichever reads on this colour. */
function gend_society_sb_on( $hex ) {
	$c = sscanf( $hex, '#%02x%02x%02x' );
	return ( 0.299 * $c[0] + 0.587 * $c[1] + 0.114 * $c[2] ) > 150 ? '#14181f' : '#ffffff';
}

add_filter( 'woocommerce_email_styles', 'gend_society_sb_store_email_css', 20 );
/** The store emails' design. WooCommerce inlines this when it sends. */
function gend_society_sb_store_email_css( $css ) {
	$b = gend_society_sb_brand();
	if ( ! $b ) {
		return $css;
	}
	$on   = gend_society_sb_on( $b['primary'] );
	$line = gend_society_sb_mix( $b['background'], $b['text'], 0.14 );
	$head = $b['heading_stack'] ?? 'Georgia, serif';
	$body = $b['body_stack'] ?? 'Helvetica, Arial, sans-serif';
	return $css . "
#template_header_image{background-color:{$b['primary']};padding:30px 36px;text-align:left;border-radius:14px 14px 0 0;}
#template_header_image p,#template_header_image .email-logo-text{margin:0;color:{$on};font-family:{$head};font-size:24px;font-weight:700;}
#template_header_image img{max-height:64px;}
#template_container{background-color:{$b['background']};border:1px solid {$line};border-top:0;border-radius:0 0 14px 14px;}
#template_header,#header_wrapper{background-color:{$b['background']};}
#header_wrapper h1,#template_header h1{color:{$b['heading']};font-family:{$head};font-size:30px;line-height:1.2;}
#body_content,#body_content_inner,#body_content_inner p,#body_content table td,#body_content table th,.font-family{font-family:{$body};color:{$b['text']};}
#body_content h2,#body_content h3{color:{$b['heading']};font-family:{$head};}
#body_content a,.link{color:{$b['link']};}
.email-order-details thead th,#body_content table.email-order-details th{border-bottom:2px solid {$b['primary']};color:{$b['heading']};}
.email-introduction{border-bottom:1px solid {$line};padding-bottom:18px;}
#template_footer,#template_footer td,#credit{color:{$b['caption']};font-family:{$body};}
";
}

/** WooCommerce reads its emails' look from these, so its own settings screen still shows and can change them. */
function gend_society_sb_store_emails( array $brand ) {
	$values = array(
		'woocommerce_email_base_color'            => $brand['primary'],
		'woocommerce_email_background_color'      => gend_society_sb_mix( $brand['background'], $brand['text'], 0.06 ),
		'woocommerce_email_body_background_color' => $brand['background'],
		'woocommerce_email_text_color'            => $brand['text'],
		'woocommerce_email_footer_text_color'     => $brand['caption'],
		'woocommerce_email_from_name'             => gend_society_sb_site_name(),
		'woocommerce_email_header_image'          => gend_society_sb_logo_url(),
	);
	foreach ( $values as $option => $value ) {
		update_option( $option, $value );
	}
}

/** The name and logo can change after the guide is saved, so the emails follow. */
function gend_society_sb_identity_changed() {
	$brand = gend_society_sb_brand();
	if ( $brand ) {
		gend_society_sb_store_emails( $brand );
	}
}
add_action( 'update_option_blogname', 'gend_society_sb_identity_changed' );
add_action( 'update_option_site_logo', 'gend_society_sb_identity_changed' );
add_action( 'add_option_site_logo', 'gend_society_sb_identity_changed' );

/** A product with no picture yet shows the brand's colours, not a grey box. */
function gend_society_sb_placeholder( array $brand ) {
	if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'imagecreatetruecolor' ) ) {
		return;
	}
	$size  = 800;
	$image = imagecreatetruecolor( $size, $size );
	$from  = sscanf( gend_society_sb_mix( $brand['background'], $brand['primary'], 0.14 ), '#%02x%02x%02x' );
	$to    = sscanf( gend_society_sb_mix( $brand['background'], $brand['primary'], 0.42 ), '#%02x%02x%02x' );
	for ( $y = 0; $y < $size; $y++ ) {
		$at = $y / $size;
		imageline( $image, 0, $y, $size, $y, imagecolorallocate( $image, (int) ( $from[0] + ( $to[0] - $from[0] ) * $at ), (int) ( $from[1] + ( $to[1] - $from[1] ) * $at ), (int) ( $from[2] + ( $to[2] - $from[2] ) * $at ) ) );
	}
	ob_start();
	imagepng( $image );
	$bytes = (string) ob_get_clean();
	imagedestroy( $image );
	$file = wp_upload_bits( 'product-placeholder.png', null, $bytes );
	if ( ! empty( $file['error'] ) ) {
		return;
	}
	$id = wp_insert_attachment( array( 'post_title' => 'Product placeholder', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), $file['file'] );
	if ( ! $id || is_wp_error( $id ) ) {
		return;
	}
	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file['file'] ) );
	$old = (int) get_option( 'gend_society_setup_placeholder' );
	if ( $old ) {
		wp_delete_attachment( $old, true );
	}
	update_option( 'gend_society_setup_placeholder', $id, false );
	update_option( 'woocommerce_placeholder_image', $id );
}

add_filter( 'render_block_core/query-title', 'gend_society_sb_shop_head', 10, 2 );
/** The catalogue opens with a hero holding its search, then the categories. Search works without scripts, and scripts make it live. */
function gend_society_sb_shop_head( $html, $block ) {
	if ( ! gend_society_sb_brand() || ! function_exists( 'is_shop' ) || ! ( is_shop() || is_product_taxonomy() || ( is_search() && 'product' === get_query_var( 'post_type' ) ) ) ) {
		return $html;
	}
	$title = is_shop() ? woocommerce_page_title( false ) : wp_strip_all_tags( $html );
	$lead  = (string) get_option( 'blogdescription' );
	$now   = get_queried_object();
	$pills = '<a class="gs-store-pill' . ( is_shop() ? ' is-now' : '' ) . '" href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '">' . esc_html__( 'All', 'gend-society' ) . '</a>';
	$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'parent' => 0, 'exclude' => array( (int) get_option( 'default_product_cat' ) ) ) );
	foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
		if ( ! (int) $term->count ) {
			continue;
		}
		$on     = $now instanceof WP_Term && (int) $now->term_id === (int) $term->term_id;
		$pills .= '<a class="gs-store-pill' . ( $on ? ' is-now' : '' ) . '" href="' . esc_url( get_term_link( $term ) ) . '">' . esc_html( $term->name ) . ' <span>' . (int) $term->count . '</span></a>';
	}
	return '<div class="gs-store-hero alignfull"><div class="gs-store-hero-inner"><h1>' . esc_html( $title ) . '</h1>'
		. ( '' !== $lead ? '<p>' . esc_html( $lead ) . '</p>' : '' )
		. '<form class="gs-store-search" role="search" action="' . esc_url( home_url( '/' ) ) . '" method="get" data-store="' . esc_url( rest_url( 'wc/store/v1/products' ) ) . '">'
		. '<input type="search" name="s" autocomplete="off" placeholder="' . esc_attr__( 'Search products', 'gend-society' ) . '" aria-label="' . esc_attr__( 'Search products', 'gend-society' ) . '" value="' . esc_attr( get_search_query() ) . '">'
		. '<input type="hidden" name="post_type" value="product"><button type="submit">' . esc_html__( 'Search', 'gend-society' ) . '</button><div class="gs-store-results" hidden></div></form></div></div>'
		. '<nav class="gs-store-pills" aria-label="' . esc_attr__( 'Product categories', 'gend-society' ) . '">' . $pills . '</nav>';
}

add_action( 'wp_enqueue_scripts', 'gend_society_sb_store_css', 22 );
add_action( 'wp_enqueue_scripts', 'gend_society_sb_dashboard_css', 99 );
/** The sales team dashboard and the wallet in the web app's colours, after their own sheets. */
function gend_society_sb_dashboard_css() {
	if ( gend_society_sb_brand() ) {
		wp_enqueue_style( 'gend-society-brand-dashboards', GEND_SOCIETY_URL . 'assets/brand-dashboards.css', array(), GEND_SOCIETY_VERSION . '.' . filemtime( GEND_SOCIETY_DIR . 'assets/brand-dashboards.css' ) );
	}
}

add_filter( 'body_class', 'gend_society_sb_body_class' );
/** Marks a branded web app, so the dashboard styles apply only there. */
function gend_society_sb_body_class( $classes ) {
	if ( gend_society_sb_brand() ) {
		$classes[] = 'gs-brand-site';
	}
	return $classes;
}
/** The store's pages, and any built page with a products section. */
function gend_society_sb_store_css() {
	if ( ! gend_society_sb_brand() ) {
		return;
	}
	$post  = is_singular() ? get_post() : null;
	$store = function_exists( 'is_woocommerce' ) && ( is_woocommerce() || is_cart() || is_checkout() || is_account_page() );
	if ( ! $store && ! ( $post && false !== strpos( (string) $post->post_content, 'gs-sec-products' ) ) ) {
		return;
	}
	// The corners and the site's own styling come with it.
	gend_society_bt_enqueue_built_pages();
	wp_enqueue_style( 'gend-society-store-pages', GEND_SOCIETY_URL . 'assets/store-pages.css', array( 'gend-society-built-pages' ), GEND_SOCIETY_VERSION . '.' . filemtime( GEND_SOCIETY_DIR . 'assets/store-pages.css' ) );
	wp_enqueue_script( 'gend-society-store-pages', GEND_SOCIETY_URL . 'assets/store-pages.js', array(), GEND_SOCIETY_VERSION . '.' . filemtime( GEND_SOCIETY_DIR . 'assets/store-pages.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
}

// ---- Social emails ----

add_filter( 'bp_after_email_appearance_settings_parse_args', 'gend_society_sb_social_look' );
/** The colours of the social email template. */
function gend_society_sb_social_look( $settings ) {
	$brand = gend_society_sb_brand();
	if ( ! $brand ) {
		return $settings;
	}
	$footer = array( sprintf( '&copy; %1$s %2$s', date_i18n( 'Y' ), esc_html( gend_society_sb_site_name() ) ) );
	if ( get_privacy_policy_url() ) {
		$footer[] = sprintf( '<a href="%s">%s</a>', esc_url( get_privacy_policy_url() ), esc_html__( 'Privacy Policy', 'gend-society' ) );
	}
	return array_merge(
		(array) $settings,
		array(
			'email_bg'          => gend_society_sb_mix( $brand['background'], $brand['text'], 0.06 ),
			'header_bg'         => $brand['primary'],
			'header_text_color' => gend_society_sb_on( $brand['primary'] ),
			'header_text_size'  => 24,
			'body_text_size'    => 16,
			'body_bg'           => $brand['background'],
			'body_text_color'   => $brand['text'],
			'footer_bg'         => $brand['background'],
			'footer_text_color' => $brand['caption'],
			'highlight_color'   => $brand['primary'],
			'link_text_color'   => $brand['link'],
			'footer_text'       => implode( ' &middot; ', $footer ),
		)
	);
}

add_action( 'bp_send_email', 'gend_society_sb_social_send', 5 );
/**
 * A social email leaving this site carries its name as the sender.
 *
 * @param BP_Email $email Email being sent.
 */
function gend_society_sb_social_send( $email ) {
	$brand = gend_society_sb_brand();
	if ( ! $brand ) {
		return;
	}
	$from = $email->get( 'from' );
	if ( is_object( $from ) ) {
		$email->set_from( $from->get_address(), gend_society_sb_site_name() );
	}
	// A link on a line of its own is the email's main action, so it reads as a button.
	$button = 'display:inline-block;padding:13px 26px;border-radius:999px;background:' . $brand['primary'] . ';color:' . gend_society_sb_on( $brand['primary'] ) . ';font-weight:700;text-decoration:none;';
	$email->set_content_html( preg_replace( '#<p>\s*<a href="([^"]+)">([^<]+)</a>\s*</p>#', '<p style="margin:24px 0"><a href="$1" style="' . esc_attr( $button ) . '">$2</a></p>', (string) $email->get( 'content_html' ) ) );
}

add_filter( 'bp_email_set_template', 'gend_society_sb_social_fonts' );
/** The social template names its fonts in every cell, so the brand's go in the same way. */
function gend_society_sb_social_fonts( $template ) {
	$brand = gend_society_sb_brand();
	return empty( $brand['body_stack'] ) ? $template : str_replace( 'font-family: sans-serif', 'font-family: ' . $brand['body_stack'], (string) $template );
}

add_action( 'bp_before_email_header', 'gend_society_sb_social_logo' );
/** The logo above the site's name. */
function gend_society_sb_social_logo() {
	$url = gend_society_sb_brand() ? gend_society_sb_logo_url() : '';
	if ( $url ) {
		echo '<img src="' . esc_url( $url ) . '" alt="" style="display:block;max-height:64px;max-width:220px;margin:0 auto 10px">';
	}
}

/** This site's own copy of a social email. */
function gend_society_sb_social_post( $type ) {
	if ( ! function_exists( 'bp_get_email_post_type' ) || '' === $type ) {
		return null;
	}
	$posts = get_posts(
		array(
			'post_type'   => bp_get_email_post_type(),
			'post_status' => 'publish',
			'numberposts' => 1,
			'tax_query'   => array( array( 'taxonomy' => bp_get_email_tax_type(), 'field' => 'slug', 'terms' => $type ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);
	return $posts ? $posts[0] : null;
}

/** The social network's features a site can choose, and the component each one switches. */
function gend_society_sb_social_features() {
	return array( 'activity', 'groups', 'friends', 'messages', 'notifications', 'moderation' );
}

/** Does this email belong to a feature the site chose? Account emails always do. */
function gend_society_sb_email_is_on( $type ) {
	$chosen = get_option( 'gend_society_social_features' );
	if ( ! is_array( $chosen ) ) {
		return true;
	}
	foreach ( array( 'friends-' => 'friends', 'groups-' => 'groups', 'messages-' => 'messages', 'activity-' => 'activity', 'yz' => 'moderation' ) as $prefix => $feature ) {
		if ( 0 === strpos( $type, $prefix ) ) {
			return in_array( $feature, $chosen, true );
		}
	}
	return true;
}

/**
 * What the member chose. Members, profiles and settings always stay on, because the site's accounts run on them.
 *
 * @param array $ids Feature ids to have on.
 * @return array
 */
function gend_society_sb_social_features_save( array $ids ) {
	$chosen = array_values( array_intersect( gend_society_sb_social_features(), array_map( 'sanitize_key', $ids ) ) );
	$active = (array) bp_get_option( 'bp-active-components', array() );
	$was    = $active;
	foreach ( array_diff( gend_society_sb_social_features(), array( 'moderation' ) ) as $component ) {
		if ( in_array( $component, $chosen, true ) ) {
			$active[ $component ] = 1;
		} else {
			unset( $active[ $component ] );
		}
	}
	$active += array( 'members' => 1, 'xprofile' => 1, 'settings' => 1 );
	bp_update_option( 'bp-active-components', $active );
	// A feature turned on for the first time needs its tables.
	if ( array_diff_key( $active, $was ) ) {
		$schema = buddypress()->plugin_dir . 'bp-core/admin/bp-core-admin-schema.php';
		if ( file_exists( $schema ) ) {
			require_once $schema;
			if ( function_exists( 'bp_core_install' ) ) {
				bp_core_install( $active );
			}
		}
	}
	update_option( 'gend_society_social_features', $chosen, false );
	return array( 'features' => $chosen );
}

add_filter( 'document_title_parts', 'gend_society_sb_directory_title' );
/** A community directory has no page of its own to name it. */
function gend_society_sb_directory_title( $parts ) {
	if ( gend_society_sb_brand() && function_exists( 'bp_is_directory' ) && bp_is_directory() && function_exists( 'bp_get_directory_title' ) ) {
		$parts['title'] = bp_get_directory_title( bp_current_component() );
	}
	return $parts;
}

add_action( 'wp_enqueue_scripts', 'gend_society_sb_social_css', 99 );
/** The community pages take the style guide. Late, because the dark network styles they carry come first. */
function gend_society_sb_social_css() {
	if ( ! gend_society_sb_brand() || ! function_exists( 'is_buddypress' ) || ! is_buddypress() ) {
		return;
	}
	gend_society_bt_enqueue_built_pages();
	wp_enqueue_style( 'gend-society-community-brand', GEND_SOCIETY_URL . 'assets/community-brand.css', array( 'gend-society-built-pages' ), GEND_SOCIETY_VERSION . '.' . filemtime( GEND_SOCIETY_DIR . 'assets/community-brand.css' ) );
}

/** Every social email this site can word for itself. */
function gend_society_sb_social_emails() {
	$out = array();
	if ( ! function_exists( 'bp_get_email_post_type' ) ) {
		return $out;
	}
	foreach ( get_posts( array( 'post_type' => bp_get_email_post_type(), 'post_status' => 'publish', 'numberposts' => 80 ) ) as $post ) {
		$terms = wp_get_object_terms( $post->ID, bp_get_email_tax_type(), array( 'fields' => 'slugs' ) );
		if ( ! is_wp_error( $terms ) && $terms && gend_society_sb_email_is_on( (string) $terms[0] ) ) {
			$out[] = array( 'type' => (string) $terms[0], 'subject' => $post->post_title, 'content' => $post->post_content );
		}
	}
	return $out;
}

/**
 * Rewords one social email. The tokens carry its links and names, so none may go missing.
 *
 * @param array $a type, subject, content.
 * @return array
 */
function gend_society_sb_social_email_save( array $a ) {
	$post = gend_society_sb_social_post( sanitize_title( (string) ( $a['type'] ?? '' ) ) );
	if ( ! $post ) {
		return array( 'error' => 'No such email.' );
	}
	$content = isset( $a['content'] ) ? wp_kses_post( (string) $a['content'] ) : $post->post_content;
	$pattern = '/\{\{\{?\s*[a-z0-9._-]+\s*\}?\}\}/i';
	preg_match_all( $pattern, $post->post_content, $had );
	preg_match_all( $pattern, $content, $has );
	// The site's and the reader's names are a courtesy. Everything else carries a link or the message itself.
	// Compared by name: two or three braces only change whether the value is escaped.
	$name    = static function ( $token ) {
		return strtolower( trim( $token, "{} 	" ) );
	};
	$kept    = array_map( $name, $has[0] );
	$missing = array_filter( array_unique( $had[0] ), static function ( $token ) use ( $name, $kept ) {
		return ! preg_match( '/^\{+\s*(site|recipient)\./i', $token ) && ! in_array( $name( $token ), $kept, true );
	} );
	if ( $missing ) {
		return array( 'error' => 'Not saved: the wording must keep ' . implode( ', ', $missing ) . '.' );
	}
	$subject = sanitize_text_field( (string) ( $a['subject'] ?? $post->post_title ) );
	$done    = wp_update_post(
		wp_slash(
			array(
				'ID'           => $post->ID,
				'post_title'   => '' !== $subject ? $subject : $post->post_title,
				'post_content' => $content,
				'post_excerpt' => trim( wp_strip_all_tags( $content ) ),
			)
		),
		true
	);
	return is_wp_error( $done ) ? array( 'error' => $done->get_error_message() ) : array( 'saved' => $post->post_name );
}

// ---- A site's own community ----
// The community tables and accounts are shared across the network, so a site set up through the chat lists only its own.

/** Does this site list only its own community? */
function gend_society_sb_scoped() {
	return ! is_main_site() && (bool) gend_society_sb_brand();
}

/** The site's members. Network admins who only manage it aren't, unless it's theirs. @return int[] Never empty, so a site with none lists none. */
function gend_society_sb_member_ids() {
	static $ids = array();
	$blog = get_current_blog_id();
	if ( ! isset( $ids[ $blog ] ) ) {
		$owner         = (string) get_option( 'admin_email' );
		$ids[ $blog ]  = array();
		foreach ( get_users( array( 'blog_id' => $blog, 'fields' => array( 'ID', 'user_login', 'user_email' ) ) ) as $user ) {
			if ( ! is_super_admin( (int) $user->ID ) || 0 === strcasecmp( (string) $user->user_email, $owner ) ) {
				$ids[ $blog ][] = (int) $user->ID;
			}
		}
		$ids[ $blog ] = $ids[ $blog ] ? $ids[ $blog ] : array( 0 );
	}
	return $ids[ $blog ];
}

/** Makes someone a member of a site, without touching a role they already have. */
function gend_society_sb_add_member( $blog, $user_id ) {
	if ( $blog && $user_id && ! is_user_member_of_blog( (int) $user_id, (int) $blog ) ) {
		add_user_to_blog( (int) $blog, (int) $user_id, (string) ( get_blog_option( (int) $blog, 'default_role' ) ?: 'subscriber' ) );
	}
}

add_action( 'user_register', 'gend_society_sb_signed_up', 20 );
add_action( 'bp_core_activated_user', 'gend_society_sb_signed_up', 20 );
/** Signing up on a site, by its form, an invitation or its checkout, makes them its member. */
function gend_society_sb_signed_up( $user_id ) {
	if ( gend_society_sb_scoped() ) {
		gend_society_sb_add_member( get_current_blog_id(), (int) $user_id );
	}
}

/** The site a group belongs to. */
function gend_society_sb_group_blog( $group_id ) {
	return (int) ( groups_get_groupmeta( (int) $group_id, '_gend_site_blog', true ) ?: groups_get_groupmeta( (int) $group_id, 'gdc_site_id', true ) );
}

add_action( 'groups_created_group', 'gend_society_sb_group_created' );
/** A group started on a site belongs to it. */
function gend_society_sb_group_created( $group_id ) {
	if ( gend_society_sb_scoped() ) {
		groups_update_groupmeta( (int) $group_id, '_gend_site_blog', get_current_blog_id() );
	}
}

add_action( 'groups_join_group', 'gend_society_sb_joined_group', 20, 2 );
add_action( 'groups_membership_accepted', 'gend_society_sb_joined_group_reversed', 20, 2 );
add_action( 'groups_accept_invite', 'gend_society_sb_joined_group_reversed', 20, 2 );
/** Joining a site's group, or accepting an invitation to it, makes them the site's member. */
function gend_society_sb_joined_group( $group_id, $user_id ) {
	$blog = gend_society_sb_group_blog( $group_id );
	if ( $blog && ! is_main_site( $blog ) ) {
		gend_society_sb_add_member( $blog, $user_id );
	}
}

function gend_society_sb_joined_group_reversed( $user_id, $group_id ) {
	gend_society_sb_joined_group( $group_id, $user_id );
}

add_filter( 'bp_after_has_members_parse_args', 'gend_society_sb_members_scope' );
/** The members directory. A group's own member list is the group's business. */
function gend_society_sb_members_scope( $args ) {
	if ( ! gend_society_sb_scoped() || ( function_exists( 'bp_is_group' ) && bp_is_group() ) ) {
		return $args;
	}
	$args['include'] = empty( $args['include'] ) ? gend_society_sb_member_ids() : array_values( array_intersect( wp_parse_id_list( $args['include'] ), gend_society_sb_member_ids() ) );
	$args['include'] = $args['include'] ? $args['include'] : array( 0 );
	return $args;
}

add_filter( 'bp_core_get_total_member_count', 'gend_society_sb_member_count' );
add_filter( 'bp_get_total_member_count', 'gend_society_sb_member_count' );
add_filter( 'bp_core_get_active_member_count', 'gend_society_sb_member_count' );
function gend_society_sb_member_count( $count ) {
	if ( ! gend_society_sb_scoped() ) {
		return $count;
	}
	return count( array_filter( gend_society_sb_member_ids() ) );
}

/** The meta clause that picks a site's own groups. */
function gend_society_sb_groups_clause() {
	return array(
		'relation' => 'OR',
		array( 'key' => '_gend_site_blog', 'value' => get_current_blog_id() ),
		array( 'key' => 'gdc_site_id', 'value' => get_current_blog_id() ),
	);
}

add_filter( 'bp_after_has_groups_parse_args', 'gend_society_sb_groups_scope' );
/** The groups directory. */
function gend_society_sb_groups_scope( $args ) {
	if ( gend_society_sb_scoped() ) {
		$existing           = empty( $args['meta_query'] ) ? array() : (array) $args['meta_query'];
		$args['meta_query'] = $existing ? array( 'relation' => 'AND', $existing, gend_society_sb_groups_clause() ) : gend_society_sb_groups_clause(); // phpcs:ignore WordPress.DB.SlowDBQuery
	}
	return $args;
}

add_filter( 'groups_get_total_group_count', 'gend_society_sb_group_count' );
add_filter( 'bp_get_total_group_count', 'gend_society_sb_group_count' );
function gend_society_sb_group_count( $count ) {
	if ( ! gend_society_sb_scoped() ) {
		return $count;
	}
	static $total = array();
	$blog = get_current_blog_id();
	if ( ! isset( $total[ $blog ] ) ) {
		$found          = groups_get_groups( array( 'per_page' => 1, 'show_hidden' => false, 'meta_query' => gend_society_sb_groups_clause() ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$total[ $blog ] = (int) ( $found['total'] ?? 0 );
	}
	return $total[ $blog ];
}

add_action( 'bp_activity_after_save', 'gend_society_sb_activity_saved' );
/** A post made on a site belongs to it. */
function gend_society_sb_activity_saved( $activity ) {
	if ( gend_society_sb_scoped() && ! empty( $activity->id ) ) {
		bp_activity_update_meta( (int) $activity->id, '_gend_site_blog', get_current_blog_id() );
	}
}

add_filter( 'bp_after_has_activities_parse_args', 'gend_society_sb_activity_scope' );
/** The activity feed: by its members, and made here or before posts were marked with their site. */
function gend_society_sb_activity_scope( $args ) {
	if ( ! gend_society_sb_scoped() || ( function_exists( 'bp_is_group' ) && bp_is_group() ) || ( function_exists( 'bp_is_user' ) && bp_is_user() ) ) {
		return $args;
	}
	$args['user_id']    = empty( $args['user_id'] ) ? implode( ',', gend_society_sb_member_ids() ) : $args['user_id'];
	$here               = array(
		'relation' => 'OR',
		array( 'key' => '_gend_site_blog', 'compare' => 'NOT EXISTS' ),
		array( 'key' => '_gend_site_blog', 'value' => get_current_blog_id() ),
	);
	$args['meta_query'] = empty( $args['meta_query'] ) ? $here : array( 'relation' => 'AND', $args['meta_query'], $here ); // phpcs:ignore WordPress.DB.SlowDBQuery
	return $args;
}

// ---- Commissions and delivery ----

/**
 * The site's default referral program, at the rates the member approved. With gend.me Payments on,
 * Gend_CP_Commission_Queue pays these in DGEN from the group's revenue; without it the site issues its own points.
 *
 * @param array $a name, rate, override_rate, return_days, cookie_days.
 * @return array
 */
function gend_society_sb_commission_save( array $a ) {
	if ( ! function_exists( 'aas_get_default_referral_program' ) || ! function_exists( 'aas_save_referral_program' ) ) {
		return array( 'error' => 'The sales team tools are not available on this web app.' );
	}
	$rate     = (float) ( $a['rate'] ?? 0 );
	$override = (float) ( $a['override_rate'] ?? 0 );
	if ( $rate <= 0 || $rate > 50 || $override < 0 || $override > 25 ) {
		return array( 'error' => 'The commission must be above 0 and up to 50%, and a team override up to 25%.' );
	}
	$program                       = aas_get_default_referral_program();
	$program['name']               = sanitize_text_field( (string) ( $a['name'] ?? '' ) ) ?: $program['name'];
	$program['referred_tiers']     = array( array( 'rate' => $rate, 'method' => 'referrals', 'requirement' => 0, 'timeframe' => 'lifetime', 'timevalue' => 0 ) );
	$program['multi_tier_enabled'] = $override > 0;
	$program['team_levels']        = $override > 0 ? array( array( 'level' => 1, 'tiers' => array( array( 'rate' => $override, 'method' => 'referrals', 'requirement' => 0, 'timeframe' => 'lifetime', 'timevalue' => 0 ) ) ) ) : array();
	// The site's return period: commissions wait it out, and so do its store reward holds.
	$return_days                   = max( 0, min( 120, (int) ( $a['return_days'] ?? 14 ) ) );
	update_option( 'rp_store_reward_hold_days', $return_days, false );
	$program['wait_days']          = $return_days;
	$program['cookie_duration']    = max( 1, (int) ( $a['cookie_days'] ?? 30 ) );
	$program['trigger_status']     = 'wc-completed';
	$program['payout_point_type']  = 'mycred_default';
	$saved                         = aas_save_referral_program( $program );
	return array( 'id' => $saved['id'] ?? '', 'rate' => $rate, 'override_rate' => $override, 'return_days' => $return_days );
}

/** The page affiliates work from. Its own slug, because the theme's page-affiliate-dashboard template is empty. */
function gend_society_sb_affiliate_page() {
	$found = get_page_by_path( 'affiliates' );
	if ( $found ) {
		return array( 'id' => (int) $found->ID, 'url' => get_permalink( $found ), 'already' => true );
	}
	$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'affiliates', 'post_title' => 'Affiliates', 'post_content' => "<!-- wp:shortcode -->\n[affiliate_sales_dashboard]\n<!-- /wp:shortcode -->" ), true );
	if ( is_wp_error( $id ) ) {
		return array( 'error' => $id->get_error_message() );
	}
	// Ours, so site_setup_page_save may replace it with the designed page.
	update_post_meta( $id, '_gs_setup_page', time() );
	return array( 'id' => (int) $id, 'url' => get_permalink( $id ) );
}

/**
 * A purchase of this product adds a task to the delivery project.
 *
 * @param array $a product_id, project_id, task_title, task_desc.
 * @return array
 */
function gend_society_sb_product_delivery_save( array $a ) {
	$product = wc_get_product( (int) ( $a['product_id'] ?? 0 ) );
	$project = (int) ( $a['project_id'] ?? 0 );
	if ( ! $product || $project <= 0 ) {
		return array( 'error' => 'product_id and project_id are required.' );
	}
	update_post_meta( $product->get_id(), '_psoo_task_product_project_id', $project );
	update_post_meta( $product->get_id(), '_psoo_task_product_task_title', sanitize_text_field( (string) ( $a['task_title'] ?? '' ) ) );
	update_post_meta( $product->get_id(), '_psoo_task_product_task_desc', sanitize_textarea_field( (string) ( $a['task_desc'] ?? '' ) ) );
	return array( 'product_id' => $product->get_id(), 'project_id' => $project );
}

// ---- Privacy Policy and Terms of Service ----

/**
 * The store's Privacy Policy and Terms of Service, written from what the site knows about the business, and linked
 * wherever the store asks for them: checkout, emails and the footer. A page the member has edited is left alone.
 *
 * @param array $a business_name, contact_email (both optional).
 * @return array
 */
function gend_society_sb_policy_pages( array $a ) {
	$name    = sanitize_text_field( (string) ( $a['business_name'] ?? '' ) );
	$name    = '' !== $name ? $name : gend_society_sb_site_name();
	$email   = sanitize_email( (string) ( $a['contact_email'] ?? '' ) );
	$email   = is_email( $email ) ? $email : (string) get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) );
	$country = function_exists( 'WC' ) && WC()->countries ? (string) WC()->countries->get_base_country() : '';
	$days    = max( 0, (int) get_option( 'rp_store_reward_hold_days', 14 ) );
	$gendme  = class_exists( 'Gend_CP_Commission_Queue' ) && Gend_CP_Commission_Queue::site_group();
	$vars    = array(
		'{business}' => esc_html( $name ),
		'{site}'     => esc_url( home_url( '/' ) ),
		'{email}'    => esc_html( $email ),
		'{days}'     => (string) $days,
		'{date}'     => esc_html( date_i18n( get_option( 'date_format' ) ) ),
		'{pay}'      => $gendme
			? 'Payments are processed by gend.me Payments, which receives your payment details and the amount of your order to complete the payment. We do not see or store your card or wallet details.'
			: 'Payments are processed by the payment provider you choose at checkout, which receives your payment details to complete the payment. We do not store your card details.',
		'{law}'      => 'CA' === $country
			? 'We handle personal information under Canada\'s Personal Information Protection and Electronic Documents Act (PIPEDA) and, for residents of Quebec, Law 25.'
			: 'We handle personal information under the privacy laws that apply where we operate.',
		'{courts}'   => 'CA' === $country ? 'the laws of Canada and of the province where we are based' : 'the laws of the place where we are based',
	);

	$privacy = <<<'HTML'
<!-- wp:group {"layout":{"type":"constrained","contentSize":"780px"},"style":{"spacing":{"padding":{"top":"clamp(40px,6vw,88px)","bottom":"clamp(48px,7vw,96px)","left":"20px","right":"20px"}}}} --><div class="wp-block-group" style="padding-top:clamp(40px,6vw,88px);padding-right:20px;padding-bottom:clamp(48px,7vw,96px);padding-left:20px"><!-- wp:heading {"level":1,"style":{"typography":{"fontSize":"clamp(2.2rem,4.5vw,3.2rem)"}}} --><h1 class="wp-block-heading" style="font-size:clamp(2.2rem,4.5vw,3.2rem)">{title}</h1><!-- /wp:heading -->
<!-- wp:paragraph {"style":{"typography":{"fontSize":"1.05rem","lineHeight":"1.7"}}} --><p style="font-size:1.05rem;line-height:1.7"><em>Last updated {date}</em></p><!-- /wp:paragraph -->
<!-- wp:paragraph {"style":{"typography":{"fontSize":"1.05rem","lineHeight":"1.7"}}} --><p style="font-size:1.05rem;line-height:1.7">This policy explains what personal information {business} collects through {site}, how we use it, and the choices you have. {law}</p><!-- /wp:paragraph -->
<!-- wp:heading {"style":{"typography":{"fontSize":"1.45rem"},"spacing":{"margin":{"top":"1.8em","bottom":"0.5em"}}}} --><h2 class="wp-block-heading" style="margin-top:1.8em;margin-bottom:0.5em;font-size:1.45rem">What we collect</h2><!-- /wp:heading -->
<!-- wp:list {"style":{"typography":{"fontSize":"1.05rem","lineHeight":"1.7"}}} --><ul style="font-size:1.05rem;line-height:1.7"><li>Your name, email address, billing address and phone number when you place an order or create an account.</li><li>Your orders, what you bought and how you paid.</li><li>Messages you send us and forms you fill in.</li><li>Basic technical information such as your browser and pages visited, through cookies, so the site works and we can improve it.</li></ul><!-- /wp:list -->
<!-- wp:heading {"style":{"typography":{"fontSize":"1.45rem"},"spacing":{"margin":{"top":"1.8em","bottom":"0.5em"}}}} --><h2 class="wp-block-heading" style="margin-top:1.8em;margin-bottom:0.5em;font-size:1.45rem">How we use it</h2><!-- /wp:heading -->
<!-- wp:list {"style":{"typography":{"fontSize":"1.05rem","lineHeight":"1.7"}}} --><ul style="font-size:1.05rem;line-height:1.7"><li>To process and deliver your orders, and to provide the services you buy.</li><li>To send you order updates and answer your questions.</li><li>To keep the site secure and prevent fraud.</li><li>To send you news and offers, only if you agree, and you can unsubscribe at any time.</li></ul><!-- /wp:list -->
<!-- wp:heading {"style":{"typography":{"fontSize":"1.45rem"},"spacing":{"margin":{"top":"1.8em","bottom":"0.5em"}}}} --><h2 class="wp-block-heading" style="margin-top:1.8em;margin-bottom:0.5em;font-size:1.45rem">Payments</h2><!-- /wp:heading -->
<!-- wp:paragraph {"style":{"typography":{"fontSize":"1.05rem","lineHeight":"1.7"}}} --><p style="font-size:1.05rem;line-height:1.7">{pay}</p><!-- /wp:paragraph -->
<!-- wp:heading {"style":{"typography":{"fontSize":"1.45rem"},"spacing":{"margin":{"top":"1.8em","bottom":"0.5em"}}}} --><h2 class="wp-block-heading" style="margin-top:1.8em;margin-bottom:0.5em;font-size:1.45rem">Who we share it with</h2><!-- /wp:heading -->
<!-- wp:paragraph {"style":{"typography":{"fontSize":"1.05rem","lineHeight":"1.7"}}} --><p style="font-size:1.05rem;line-height:1.7">We share your information only with the service providers that help us run the store, such as payment processing, hosting and email, and only as needed for that service. We do not sell your personal information. We may disclose it where the law requires.</p><!-- /wp:paragraph -->
<!-- wp:heading {"style":{"typography":{"fontSize":"1.45rem"},"spacing":{"margin":{"top":"1.8em","bottom":"0.5em"}}}} --><h2 class="wp-block-heading" style="margin-top:1.8em;margin-bottom:0.5em;font-size:1.45rem">How long we keep it</h2><!-- /wp:heading -->
<!-- wp:paragraph {"style":{"typography":{"fontSize":"1.05rem","lineHeight":"1.7"}}} --><p style="font-size:1.05rem;line-height:1.7">We keep order records for as long as the law requires for tax and accounting, and other information only as long as we need it for the purposes above. You can ask us to delete your account at any time.</p><!-- /wp:paragraph -->
<!-- wp:heading {"style":{"typography":{"fontSize":"1.45rem"},"spacing":{"margin":{"top":"1.8em","bottom":"0.5em"}}}} --><h2 class="wp-block-heading" style="margin-top:1.8em;margin-bottom:0.5em;font-size:1.45rem">Your choices and rights</h2><!-- /wp:heading -->
<!-- wp:paragraph {"style":{"typography":{"fontSize":"1.05rem","lineHeight":"1.7"}}} --><p style="font-size:1.05rem;line-height:1.7">You can ask to see the personal information we hold about you, correct it, withdraw your consent or ask us to delete it. Email us at {email} and we will respond within 30 days.</p><!-- /wp:paragraph -->
<!-- wp:heading {"style":{"typography":{"fontSize":"1.45rem"},"spacing":{"margin":{"top":"1.8em","bottom":"0.5em"}}}} --><h2 class="wp-block-heading" style="margin-top:1.8em;margin-bottom:0.5em;font-size:1.45rem">Contact us</h2><!-- /wp:heading -->
<!-- wp:paragraph {"style":{"typography":{"fontSize":"1.05rem","lineHeight":"1.7"}}} --><p style="font-size:1.05rem;line-height:1.7">Questions about this policy or your information: {email}.</p><!-- /wp:paragraph -->
</div><!-- /wp:group -->
HTML;

	$terms = <<<'HTML'
<!-- wp:group {"layout":{"type":"constrained","contentSize":"780px"},"style":{"spacing":{"padding":{"top":"clamp(40px,6vw,88px)","bottom":"clamp(48px,7vw,96px)","left":"20px","right":"20px"}}}} --><div class="wp-block-group" style="padding-top:clamp(40px,6vw,88px);padding-right:20px;padding-bottom:clamp(48px,7vw,96px);padding-left:20px"><!-- wp:heading {"level":1,"style":{"typography":{"fontSize":"clamp(2.2rem,4.5vw,3.2rem)"}}} --><h1 class="wp-block-heading" style="font-size:clamp(2.2rem,4.5vw,3.2rem)">{title}</h1><!-- /wp:heading -->
<!-- wp:paragraph {"style":{"typography":{"fontSize":"1.05rem","lineHeight":"1.7"}}} --><p style="font-size:1.05rem;line-height:1.7"><em>Last updated {date}</em></p><!-- /wp:paragraph -->
<!-- wp:paragraph {"style":{"typography":{"fontSize":"1.05rem","lineHeight":"1.7"}}} --><p style="font-size:1.05rem;line-height:1.7">These terms apply when you use {site} or buy from {business}. By placing an order you agree to them.</p><!-- /wp:paragraph -->
<!-- wp:heading {"style":{"typography":{"fontSize":"1.45rem"},"spacing":{"margin":{"top":"1.8em","bottom":"0.5em"}}}} --><h2 class="wp-block-heading" style="margin-top:1.8em;margin-bottom:0.5em;font-size:1.45rem">Orders and prices</h2><!-- /wp:heading -->
<!-- wp:paragraph {"style":{"typography":{"fontSize":"1.05rem","lineHeight":"1.7"}}} --><p style="font-size:1.05rem;line-height:1.7">Prices are shown at checkout in the store's currency and include any taxes stated there. An order is accepted once payment is confirmed. We may decline or cancel an order, for example if a price was shown in error, and we will refund any payment in full.</p><!-- /wp:paragraph -->
<!-- wp:heading {"style":{"typography":{"fontSize":"1.45rem"},"spacing":{"margin":{"top":"1.8em","bottom":"0.5em"}}}} --><h2 class="wp-block-heading" style="margin-top:1.8em;margin-bottom:0.5em;font-size:1.45rem">Payment</h2><!-- /wp:heading -->
<!-- wp:paragraph {"style":{"typography":{"fontSize":"1.05rem","lineHeight":"1.7"}}} --><p style="font-size:1.05rem;line-height:1.7">{pay}</p><!-- /wp:paragraph -->
<!-- wp:heading {"style":{"typography":{"fontSize":"1.45rem"},"spacing":{"margin":{"top":"1.8em","bottom":"0.5em"}}}} --><h2 class="wp-block-heading" style="margin-top:1.8em;margin-bottom:0.5em;font-size:1.45rem">Delivery of products and services</h2><!-- /wp:heading -->
<!-- wp:paragraph {"style":{"typography":{"fontSize":"1.05rem","lineHeight":"1.7"}}} --><p style="font-size:1.05rem;line-height:1.7">Digital products are delivered by email or through your account. Services start as described on the product page; we will contact you to arrange anything we need from you.</p><!-- /wp:paragraph -->
<!-- wp:heading {"style":{"typography":{"fontSize":"1.45rem"},"spacing":{"margin":{"top":"1.8em","bottom":"0.5em"}}}} --><h2 class="wp-block-heading" style="margin-top:1.8em;margin-bottom:0.5em;font-size:1.45rem">Returns and refunds</h2><!-- /wp:heading -->
<!-- wp:paragraph {"style":{"typography":{"fontSize":"1.05rem","lineHeight":"1.7"}}} --><p style="font-size:1.05rem;line-height:1.7">You can ask for a refund within {days} days of your order by contacting {email}. Services already delivered and digital products already downloaded may not be refundable, unless the law says otherwise. Approved refunds go back to the way you paid.</p><!-- /wp:paragraph -->
<!-- wp:heading {"style":{"typography":{"fontSize":"1.45rem"},"spacing":{"margin":{"top":"1.8em","bottom":"0.5em"}}}} --><h2 class="wp-block-heading" style="margin-top:1.8em;margin-bottom:0.5em;font-size:1.45rem">Your account</h2><!-- /wp:heading -->
<!-- wp:paragraph {"style":{"typography":{"fontSize":"1.05rem","lineHeight":"1.7"}}} --><p style="font-size:1.05rem;line-height:1.7">Keep your login details safe; you are responsible for activity on your account. Tell us straight away if you think someone else has used it.</p><!-- /wp:paragraph -->
<!-- wp:heading {"style":{"typography":{"fontSize":"1.45rem"},"spacing":{"margin":{"top":"1.8em","bottom":"0.5em"}}}} --><h2 class="wp-block-heading" style="margin-top:1.8em;margin-bottom:0.5em;font-size:1.45rem">Our content</h2><!-- /wp:heading -->
<!-- wp:paragraph {"style":{"typography":{"fontSize":"1.05rem","lineHeight":"1.7"}}} --><p style="font-size:1.05rem;line-height:1.7">The text, images and other content on this site belong to {business} or its licensors. You may not copy or reuse them without permission.</p><!-- /wp:paragraph -->
<!-- wp:heading {"style":{"typography":{"fontSize":"1.45rem"},"spacing":{"margin":{"top":"1.8em","bottom":"0.5em"}}}} --><h2 class="wp-block-heading" style="margin-top:1.8em;margin-bottom:0.5em;font-size:1.45rem">Liability</h2><!-- /wp:heading -->
<!-- wp:paragraph {"style":{"typography":{"fontSize":"1.05rem","lineHeight":"1.7"}}} --><p style="font-size:1.05rem;line-height:1.7">We provide the site and our products with care, but to the extent the law allows we are not liable for indirect or consequential losses. Nothing in these terms limits rights you have under consumer protection law.</p><!-- /wp:paragraph -->
<!-- wp:heading {"style":{"typography":{"fontSize":"1.45rem"},"spacing":{"margin":{"top":"1.8em","bottom":"0.5em"}}}} --><h2 class="wp-block-heading" style="margin-top:1.8em;margin-bottom:0.5em;font-size:1.45rem">Privacy</h2><!-- /wp:heading -->
<!-- wp:paragraph {"style":{"typography":{"fontSize":"1.05rem","lineHeight":"1.7"}}} --><p style="font-size:1.05rem;line-height:1.7">How we handle your information is set out in our <a href="{privacy}">Privacy Policy</a>.</p><!-- /wp:paragraph -->
<!-- wp:heading {"style":{"typography":{"fontSize":"1.45rem"},"spacing":{"margin":{"top":"1.8em","bottom":"0.5em"}}}} --><h2 class="wp-block-heading" style="margin-top:1.8em;margin-bottom:0.5em;font-size:1.45rem">Changes and governing law</h2><!-- /wp:heading -->
<!-- wp:paragraph {"style":{"typography":{"fontSize":"1.05rem","lineHeight":"1.7"}}} --><p style="font-size:1.05rem;line-height:1.7">We may update these terms; the date above shows the latest version. They are governed by {courts}.</p><!-- /wp:paragraph -->
<!-- wp:heading {"style":{"typography":{"fontSize":"1.45rem"},"spacing":{"margin":{"top":"1.8em","bottom":"0.5em"}}}} --><h2 class="wp-block-heading" style="margin-top:1.8em;margin-bottom:0.5em;font-size:1.45rem">Contact us</h2><!-- /wp:heading -->
<!-- wp:paragraph {"style":{"typography":{"fontSize":"1.05rem","lineHeight":"1.7"}}} --><p style="font-size:1.05rem;line-height:1.7">{email}</p><!-- /wp:paragraph -->
</div><!-- /wp:group -->
HTML;

	$pages = array();
	foreach ( array( 'privacy' => array( 'Privacy Policy', 'privacy-policy', $privacy ), 'terms' => array( 'Terms of Service', 'terms-of-service', $terms ) ) as $key => list( $title, $slug, $body ) ) {
		$content = strtr( $body, $vars + array( '{title}' => esc_html( $title ), '{privacy}' => esc_url( isset( $pages['privacy'] ) ? get_permalink( $pages['privacy'] ) : home_url( '/privacy-policy/' ) ) ) );
		$page    = get_page_by_path( $slug, OBJECT, 'page' );
		if ( ! $page && 'privacy' === $key && (int) get_option( 'wp_page_for_privacy_policy' ) ) {
			$page = get_post( (int) get_option( 'wp_page_for_privacy_policy' ) );
		}
		$ours = $page && ( get_post_meta( $page->ID, '_gs_policy_generated', true ) || 'publish' !== $page->post_status );
		if ( $page && ! $ours ) {
			$pages[ $key ] = (int) $page->ID; // the member's own wording stays
			continue;
		}
		$id = wp_insert_post( array( 'ID' => $page ? (int) $page->ID : 0, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_name' => $slug, 'post_content' => $content ), true );
		if ( is_wp_error( $id ) ) {
			return array( 'error' => $id->get_error_message() );
		}
		update_post_meta( $id, '_gs_policy_generated', time() );
		$pages[ $key ] = (int) $id;
	}
	update_option( 'wp_page_for_privacy_policy', $pages['privacy'] );
	update_option( 'woocommerce_terms_page_id', $pages['terms'] );
	$links  = sprintf( '<a href="%s">Privacy Policy</a> &middot; <a href="%s">Terms of Service</a>', esc_url( get_permalink( $pages['privacy'] ) ), esc_url( get_permalink( $pages['terms'] ) ) );
	$footer = (string) get_option( 'woocommerce_email_footer_text', '' );
	if ( false === strpos( $footer, 'privacy-policy' ) ) {
		update_option( 'woocommerce_email_footer_text', trim( $footer . ( '' !== $footer ? '<br />' : '' ) . $links ) );
	}
	return array( 'privacy_url' => get_permalink( $pages['privacy'] ), 'terms_url' => get_permalink( $pages['terms'] ) );
}

add_filter( 'render_block_core/template-part', 'gend_society_sb_footer_legal', 10, 2 );
/** The footer carries the store's policies, on every page, including footers built before the pages existed. */
function gend_society_sb_footer_legal( $html, $block ) {
	if ( 'footer' !== ( $block['attrs']['slug'] ?? '' ) || ! gend_society_sb_brand() ) {
		return $html;
	}
	$links = array();
	if ( get_privacy_policy_url() ) {
		$links[] = sprintf( '<a href="%s">%s</a>', esc_url( get_privacy_policy_url() ), esc_html__( 'Privacy Policy', 'gend-society' ) );
	}
	$terms = (int) get_option( 'woocommerce_terms_page_id' );
	if ( $terms && 'publish' === get_post_status( $terms ) ) {
		$links[] = sprintf( '<a href="%s">%s</a>', esc_url( get_permalink( $terms ) ), esc_html__( 'Terms of Service', 'gend-society' ) );
	}
	if ( ! $links || false !== strpos( $html, 'gs-legal-links' ) ) {
		return $html;
	}
	$nav = '<nav class="gs-legal-links" aria-label="' . esc_attr__( 'Legal', 'gend-society' ) . '" style="display:flex;flex-wrap:wrap;gap:6px 18px;justify-content:center;padding:14px 0 0;font-size:.85rem;opacity:.85">' . implode( '', $links ) . '</nav>';
	// Inside the footer's own last block, so the links sit on its background.
	$end = strrpos( $html, '</footer>' );
	$at  = false !== $end ? strrpos( substr( $html, 0, $end ), '</div>' ) : false;
	if ( false === $at ) {
		$at = $end;
	}
	return false !== $at ? substr_replace( $html, $nav, $at, 0 ) : $html . $nav;
}
// ---- Setup pages: a branded hero over one feature's shortcode ----

/** The shortcodes a setup page may hold. */
function gend_society_sb_setup_page_tags() {
	return array( 'psoo_dashboard', 'psoo_pm_group_projects', 'affiliate_sales_dashboard', 'affiliate_link', 'affiliate_earnings', 'referral_team', 'gs_affiliate_register', 'gend_wallet', 'chat_form' );
}

/**
 * One shortcode of an allowed tag, rebuilt from its parsed attributes so nothing else rides along.
 *
 * @param string $raw Shortcode as sent.
 * @return string|WP_Error
 */
function gend_society_sb_clean_shortcode( $raw ) {
	$raw = trim( (string) $raw );
	if ( ! preg_match( '/^\[([a-z_]+)(\s[^\[\]]*)?\]$/', $raw, $m ) ) {
		return new WP_Error( 'gs_sc', 'shortcode must be one shortcode, such as [affiliate_sales_dashboard].' );
	}
	if ( ! in_array( $m[1], gend_society_sb_setup_page_tags(), true ) ) {
		return new WP_Error( 'gs_sc', 'That shortcode is not allowed here. Allowed: ' . implode( ', ', gend_society_sb_setup_page_tags() ) . '.' );
	}
	$out  = '[' . $m[1];
	$atts = isset( $m[2] ) ? shortcode_parse_atts( trim( $m[2] ) ) : array();
	foreach ( is_array( $atts ) ? $atts : array() as $k => $v ) {
		$k = sanitize_key( (string) $k );
		$v = trim( str_replace( array( '"', "'", '[', ']', '<', '>' ), '', sanitize_text_field( (string) $v ) ) );
		if ( '' !== $k && ! is_numeric( $k ) ) {
			$out .= ' ' . $k . '="' . $v . '"';
		}
	}
	return $out . ']';
}

/** Is this page content nothing but one allowed shortcode, bare or in a shortcode block? */
function gend_society_sb_only_shortcode( $content ) {
	$c = trim( preg_replace( '#<!--\s*/?wp:shortcode\s*-->#', '', (string) $content ) );
	return (bool) preg_match( '/^\[(' . implode( '|', gend_society_sb_setup_page_tags() ) . ')(\s[^\[\]]*)?\]$/', $c );
}

/** Colours for a hero: the brand's primary with words that read on it, or the theme's contrast and base. */
function gend_society_sb_hero_colors() {
	$b = gend_society_sb_brand();
	if ( ! empty( $b['primary'] ) ) {
		return array( 'bg' => 'brand-primary', 'text' => gend_society_sb_on( $b['primary'] ) );
	}
	return array( 'bg' => 'contrast', 'text' => '' );
}

/**
 * The page's content: a constrained group holding the hero, then the shortcode.
 *
 * @param string $title     Title (plain).
 * @param string $intro     Intro (plain).
 * @param string $shortcode Clean shortcode.
 * @param bool   $hero      With the hero.
 * @return string
 */
function gend_society_sb_setup_page_content( $title, $intro, $shortcode, $hero ) {
	$out = '<!-- wp:group {"layout":{"type":"constrained","contentSize":"1100px"},"style":{"spacing":{"padding":{"top":"clamp(28px,5vw,64px)","bottom":"clamp(40px,6vw,88px)","left":"20px","right":"20px"},"blockGap":"clamp(24px,4vw,40px)"}}} -->'
		. '<div class="wp-block-group gs-setup-page" style="padding-top:clamp(28px,5vw,64px);padding-right:20px;padding-bottom:clamp(40px,6vw,88px);padding-left:20px">';
	if ( $hero ) {
		$c     = gend_society_sb_hero_colors();
		$attrs = array(
			'backgroundColor' => $c['bg'],
			'style'           => array(
				'spacing' => array( 'padding' => array( 'top' => 'clamp(36px,6vw,72px)', 'bottom' => 'clamp(36px,6vw,72px)', 'left' => 'clamp(22px,5vw,56px)', 'right' => 'clamp(22px,5vw,56px)' ) ),
				'border'  => array( 'radius' => '24px' ),
			),
			'layout'          => array( 'type' => 'constrained', 'contentSize' => '760px', 'justifyContent' => 'left' ),
		);
		$class = 'wp-block-group gs-setup-hero has-' . $c['bg'] . '-background-color has-background';
		$style = 'border-radius:24px;';
		if ( '' !== $c['text'] ) {
			$attrs['style']['color'] = array( 'text' => $c['text'] );
			$class                   = 'wp-block-group gs-setup-hero has-text-color has-' . $c['bg'] . '-background-color has-background';
			$style                  .= 'color:' . $c['text'] . ';';
		} else {
			$attrs['textColor'] = 'base';
			$class              = 'wp-block-group gs-setup-hero has-base-color has-text-color has-' . $c['bg'] . '-background-color has-background';
		}
		$style .= 'padding-top:clamp(36px,6vw,72px);padding-right:clamp(22px,5vw,56px);padding-bottom:clamp(36px,6vw,72px);padding-left:clamp(22px,5vw,56px)';
		$out   .= '<!-- wp:group ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES ) . ' --><div class="' . esc_attr( $class ) . '" style="' . esc_attr( $style ) . '">'
			. '<!-- wp:heading {"level":1,"style":{"typography":{"fontSize":"clamp(2rem,4.5vw,3.2rem)","lineHeight":"1.1"},"color":{"text":"inherit"}}} --><h1 class="wp-block-heading" style="color:inherit;font-size:clamp(2rem,4.5vw,3.2rem);line-height:1.1">' . esc_html( $title ) . '</h1><!-- /wp:heading -->';
		if ( '' !== $intro ) {
			$out .= '<!-- wp:paragraph {"style":{"typography":{"fontSize":"1.15rem","lineHeight":"1.65"}}} --><p style="font-size:1.15rem;line-height:1.65;opacity:.92">' . esc_html( $intro ) . '</p><!-- /wp:paragraph -->';
		}
		$out .= '</div><!-- /wp:group -->';
	}
	return $out . "<!-- wp:shortcode -->\n" . $shortcode . "\n<!-- /wp:shortcode --></div><!-- /wp:group -->";
}

/**
 * Creates or updates a setup page. A page with the same address that the member made is returned untouched.
 *
 * @param array $a title, slug, intro, shortcode, hero.
 * @return array
 */
function gend_society_sb_setup_page_save( array $a ) {
	$title = sanitize_text_field( (string) ( $a['title'] ?? '' ) );
	if ( '' === $title ) {
		return array( 'error' => 'title is required.' );
	}
	$sc = gend_society_sb_clean_shortcode( (string) ( $a['shortcode'] ?? '' ) );
	if ( is_wp_error( $sc ) ) {
		return array( 'error' => $sc->get_error_message() );
	}
	$slug  = sanitize_title( (string) ( $a['slug'] ?? '' ) );
	$slug  = '' !== $slug ? $slug : sanitize_title( $title );
	$intro = trim( preg_replace( '/\s+/', ' ', sanitize_textarea_field( (string) ( $a['intro'] ?? '' ) ) ) );
	$intro = function_exists( 'mb_substr' ) ? mb_substr( $intro, 0, 600 ) : substr( $intro, 0, 600 );
	$hero  = ! array_key_exists( 'hero', $a ) || ! empty( $a['hero'] );
	$page  = get_page_by_path( $slug, OBJECT, 'page' );
	if ( $page && 'trash' !== $page->post_status ) {
		$ours = get_post_meta( $page->ID, '_gs_setup_page', true ) || gend_society_sb_only_shortcode( $page->post_content );
		if ( ! $ours ) {
			return array( 'id' => (int) $page->ID, 'url' => get_permalink( $page ), 'kept' => true, 'note' => 'A page the member made already uses this address, so it was left as it is.' );
		}
	} else {
		$page = null;
	}
	$id = wp_insert_post(
		wp_slash(
			array(
				'ID'           => $page ? (int) $page->ID : 0,
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_content' => gend_society_sb_setup_page_content( $title, $intro, $sc, $hero ),
			)
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		return array( 'error' => $id->get_error_message() );
	}
	update_post_meta( $id, '_gs_setup_page', time() );
	return array( 'id' => (int) $id, 'url' => get_permalink( $id ) );
}

// ---- Projects dashboards from the chat ----

const GEND_SOCIETY_SB_DASH_AUDIENCE = 'gend_society_dash_audience';

/** The site's own group: the one gend.me Payments pays from, else the oldest group marked as this site's. */
function gend_society_sb_site_group_id() {
	if ( class_exists( 'Gend_CP_Commission_Queue' ) && method_exists( 'Gend_CP_Commission_Queue', 'site_group' ) ) {
		$g = (int) Gend_CP_Commission_Queue::site_group();
		if ( $g ) {
			return $g;
		}
	}
	if ( ! function_exists( 'groups_get_groups' ) || is_main_site() ) {
		return 0;
	}
	$found = groups_get_groups( array( 'per_page' => 1, 'show_hidden' => true, 'order' => 'ASC', 'orderby' => 'date_created', 'meta_query' => gend_society_sb_groups_clause() ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	return ! empty( $found['groups'] ) ? (int) $found['groups'][0]->id : 0;
}

/** Has this person paid for an order in this site's store? */
function gend_society_sb_is_customer( $user_id ) {
	static $seen = array();
	$user_id = (int) $user_id;
	if ( $user_id <= 0 || ! function_exists( 'wc_get_orders' ) ) {
		return false;
	}
	if ( ! isset( $seen[ $user_id ] ) ) {
		$seen[ $user_id ] = (bool) wc_get_orders( array( 'customer_id' => $user_id, 'status' => wc_get_is_paid_statuses(), 'limit' => 1, 'return' => 'ids' ) );
	}
	return $seen[ $user_id ];
}

/** The staff roles 'team' dashboards list. They're stored as the dashboard's member types, which match a member's role on this site. */
function gend_society_sb_team_roles() {
	return array( 'administrator', 'editor', 'shop_manager' );
}

add_filter( 'psoo_dashboard_user_can_view', 'gend_society_sb_dash_can_view', 20, 4 );
/** Team dashboards also open for the site group's admins and moderators; customer dashboards for anyone with a paid order. */
function gend_society_sb_dash_can_view( $ok, $dash, $user_id, $group_id ) {
	$all = get_option( GEND_SOCIETY_SB_DASH_AUDIENCE, array() );
	$aud = is_array( $all ) && isset( $dash['id'], $all[ $dash['id'] ] ) ? $all[ $dash['id'] ] : '';
	if ( $ok || ! $user_id || '' === $aud ) {
		return $ok;
	}
	if ( 'customers' === $aud ) {
		return gend_society_sb_is_customer( $user_id );
	}
	if ( 'team' === $aud && function_exists( 'groups_is_user_admin' ) ) {
		$g = gend_society_sb_site_group_id();
		return $g && ( groups_is_user_admin( (int) $user_id, $g ) || groups_is_user_mod( (int) $user_id, $g ) );
	}
	return $ok;
}

/**
 * Creates a dashboard, or updates one this tool made.
 *
 * @param array $a id, title, description, audience, show_on_dashboard, sections.
 * @return array
 */
function gend_society_sb_dashboard_save( array $a ) {
	if ( ! function_exists( 'psoo_dash_normalize' ) || ! function_exists( 'psoo_dash_get_all' ) || ! defined( 'PSOO_DASH_OPTION' ) ) {
		return array( 'error' => 'Dashboards are not available on this web app.' );
	}
	$title = sanitize_text_field( (string) ( $a['title'] ?? '' ) );
	if ( '' === $title ) {
		return array( 'error' => 'title is required.' );
	}
	$audience = in_array( $a['audience'] ?? '', array( 'members', 'team', 'customers' ), true ) ? $a['audience'] : 'members';
	psoo_dash_get_all(); // seeds the option on first use
	$all   = get_option( PSOO_DASH_OPTION, array() );
	$all   = is_array( $all ) ? $all : array();
	$marks = get_option( GEND_SOCIETY_SB_DASH_AUDIENCE, array() );
	$marks = is_array( $marks ) ? $marks : array();
	$id    = sanitize_title( (string) ( $a['id'] ?? '' ) );
	if ( '' !== $id ) {
		if ( ! isset( $all[ $id ] ) ) {
			return array( 'error' => 'There is no dashboard with that id.' );
		}
		if ( ! isset( $marks[ $id ] ) ) {
			return array( 'error' => 'That dashboard was made in wp-admin (Projects > Dashboards), so it is changed there.' );
		}
	} else {
		$base = sanitize_title( $title );
		$base = '' !== $base ? $base : 'dashboard';
		$id   = $base;
		for ( $n = 2; isset( $all[ $id ] ); $n++ ) {
			$id = $base . '-' . $n;
		}
	}
	$raw  = current_user_can( 'unfiltered_html' );
	$list = array();
	foreach ( array_slice( (array) ( $a['sections'] ?? array() ), 0, 20 ) as $i => $s ) {
		if ( ! is_array( $s ) ) {
			continue;
		}
		$content = substr( (string) ( $s['content'] ?? '' ), 0, 20000 );
		$content = $raw ? $content : wp_kses_post( $content );
		$stitle  = sanitize_text_field( (string) ( $s['title'] ?? '' ) );
		$list[]  = array(
			'id'         => 's' . ( $i + 1 ),
			'title'      => $stitle,
			'show_title' => '' !== $stitle ? 1 : 0,
			// HTML stays as written; plain text gets paragraphs.
			'mode'       => preg_match( '/<[a-z]/i', $content ) ? 'code' : 'text',
			'width'      => in_array( $s['width'] ?? '', array( 'full', 'two-thirds', 'half', 'third' ), true ) ? $s['width'] : 'full',
			'css_class'  => '',
			'content'    => $content,
		);
	}
	if ( ! $list ) {
		return array( 'error' => 'sections needs at least one section.' );
	}
	$access = array( 'features' => array(), 'member_types' => array(), 'group_consultant' => 0 );
	if ( 'team' === $audience ) {
		$access['member_types'] = gend_society_sb_team_roles();
	} elseif ( 'customers' === $audience ) {
		// WooCommerce's customer role; the filter above adds everyone with a paid order. Without the filter, it stays closed to others.
		$access['member_types'] = array( 'customer' );
	}
	$prev       = isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? $all[ $id ] : array();
	$all[ $id ] = psoo_dash_normalize(
		array(
			'id'                => $id,
			'title'             => $title,
			'description'       => sanitize_text_field( (string) ( $a['description'] ?? '' ) ),
			'icon'              => (string) ( $prev['icon'] ?? 'dashicons-layout' ),
			'show_on_dashboard' => ! empty( $a['show_on_dashboard'] ),
			'order'             => (int) ( $prev['order'] ?? 30 ),
			'access'            => $access,
			'sections'          => $list,
			'updated'           => time(),
		),
		$id
	);
	update_option( PSOO_DASH_OPTION, $all, false );
	$marks[ $id ] = $audience;
	update_option( GEND_SOCIETY_SB_DASH_AUDIENCE, $marks, false );
	return array( 'id' => $id, 'shortcode' => '[psoo_dashboard id="' . $id . '"]', 'audience' => $audience );
}

// ---- Affiliate enrolment ----
// With require_registration on, only people who joined the program (and the site's admins) are affiliates: the
// affiliate screens invite everyone else to join, and links shared by anyone else earn nothing. Branded sub-sites only.

const GEND_SOCIETY_SB_AFFILIATE_OPTION = 'gend_society_affiliate_program';

/** The site's enrolment settings. */
function gend_society_sb_affiliate_settings() {
	$s = get_option( GEND_SOCIETY_SB_AFFILIATE_OPTION, array() );
	$s = is_array( $s ) ? $s : array();
	return array(
		'require_registration' => ! empty( $s['require_registration'] ),
		'registration_page_id' => (int) ( $s['registration_page_id'] ?? 0 ),
		'form_id'              => (int) ( $s['form_id'] ?? 0 ),
		'program_name'         => (string) ( $s['program_name'] ?? '' ),
	);
}

/** What the member called their commission program, for its tabs. */
function gend_society_sb_program_name() {
	$name = gend_society_sb_affiliate_settings()['program_name'];
	return '' !== $name ? $name : __( 'Commissions', 'gend-society' );
}

/** Does this site have a referral program set up? Read straight from the option, which the sales team code fills on first read. */
function gend_society_sb_has_referral_program() {
	$p = get_option( 'aas_referral_programs' );
	return is_array( $p ) && $p && function_exists( 'aas_umk' );
}

/** Is joining required here? */
function gend_society_sb_affiliate_gated() {
	return gend_society_sb_scoped() && function_exists( 'aas_umk' ) && gend_society_sb_affiliate_settings()['require_registration'];
}

/** Is this person an affiliate of this site? Everyone is, when joining isn't required. */
function gend_society_sb_is_affiliate( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
		return false;
	}
	if ( ! gend_society_sb_affiliate_gated() || user_can( $user_id, 'manage_options' ) ) {
		return true;
	}
	return (bool) get_user_meta( $user_id, aas_umk( 'affiliate_enrolled' ), true );
}

/** Enrols someone in this site's program, and makes them a member of the site. */
function gend_society_sb_affiliate_enrol( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 || ! function_exists( 'aas_umk' ) || ! get_userdata( $user_id ) ) {
		return false;
	}
	if ( is_multisite() && ! is_main_site() ) {
		gend_society_sb_add_member( get_current_blog_id(), $user_id );
	}
	if ( ! get_user_meta( $user_id, aas_umk( 'affiliate_enrolled' ), true ) ) {
		update_user_meta( $user_id, aas_umk( 'affiliate_enrolled' ), time() );
		do_action( 'gend_society_affiliate_enrolled', $user_id );
	}
	return true;
}

/**
 * Saves the enrolment settings.
 *
 * @param array $a require_registration, registration_page_id, form_id.
 * @return array
 */
function gend_society_sb_affiliate_setup_save( array $a ) {
	if ( ! function_exists( 'aas_umk' ) ) {
		return array( 'error' => 'The sales team tools are not available on this web app.' );
	}
	if ( ! gend_society_sb_scoped() ) {
		return array( 'error' => 'Affiliate sign-up is for web apps set up through the chat.' );
	}
	$s    = gend_society_sb_affiliate_settings();
	$s['require_registration'] = ! empty( $a['require_registration'] );
	if ( array_key_exists( 'registration_page_id', $a ) ) {
		$pid = (int) $a['registration_page_id'];
		if ( $pid && ( 'page' !== get_post_type( $pid ) || 'trash' === get_post_status( $pid ) ) ) {
			return array( 'error' => 'registration_page_id is not a page on this site.' );
		}
		$s['registration_page_id'] = $pid;
	}
	if ( array_key_exists( 'program_name', $a ) ) {
		$s['program_name'] = mb_substr( sanitize_text_field( (string) $a['program_name'] ), 0, 60 );
	}
	if ( array_key_exists( 'form_id', $a ) ) {
		$fid = (int) $a['form_id'];
		if ( $fid && ! in_array( get_post_type( $fid ), array( 'chat_form', 'basic_form' ), true ) ) {
			return array( 'error' => 'form_id is not a form on this site.' );
		}
		$s['form_id'] = $fid;
	}
	if ( ! $s['registration_page_id'] ) {
		$s['registration_page_id'] = gend_society_sb_find_page_with( 'gs_affiliate_register' );
	}
	update_option( GEND_SOCIETY_SB_AFFILIATE_OPTION, $s, true ); // read on most requests
	$out = $s + array( 'registration_url' => $s['registration_page_id'] ? get_permalink( $s['registration_page_id'] ) : '' );
	if ( $s['require_registration'] && ! $s['registration_page_id'] ) {
		$out['note'] = 'No page holds [gs_affiliate_register] yet. Make one with site_setup_page_save (shortcode gs_affiliate_register) and send its id as registration_page_id.';
	}
	return $out;
}

/** The first published page whose content uses a shortcode. */
function gend_society_sb_find_page_with( $tag ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s ORDER BY ID ASC LIMIT 1", '%' . $wpdb->esc_like( '[' . $tag ) . '%' ) );
}

/** Where people join: the saved page, or any page with the sign-up card. */
function gend_society_sb_affiliate_join_url() {
	$pid = gend_society_sb_affiliate_settings()['registration_page_id'];
	$pid = $pid && 'publish' === get_post_status( $pid ) ? $pid : gend_society_sb_find_page_with( 'gs_affiliate_register' );
	return $pid ? (string) get_permalink( $pid ) : '';
}

/** Where affiliates work: the Affiliates page, or the account page's affiliate tab. */
function gend_society_sb_affiliate_dashboard_url() {
	$page = get_page_by_path( 'affiliates', OBJECT, 'page' );
	if ( $page && 'publish' === $page->post_status ) {
		return (string) get_permalink( $page );
	}
	$pid = gend_society_sb_find_page_with( 'affiliate_sales_dashboard' );
	if ( $pid ) {
		return (string) get_permalink( $pid );
	}
	return function_exists( 'wc_get_account_endpoint_url' ) ? (string) wc_get_account_endpoint_url( 'affiliate' ) : '';
}

/** Styles for the sign-up card, once per page. */
function gend_society_sb_affiliate_card_css() {
	static $done = false;
	if ( $done ) {
		return '';
	}
	$done = true;
	$b    = gend_society_sb_brand();
	$on   = ! empty( $b['primary'] ) ? gend_society_sb_on( $b['primary'] ) : '#ffffff';
	$pri  = ! empty( $b['primary'] ) ? $b['primary'] : '#14181f';
	return '<style>'
		. '.gs-aff-card{max-width:640px;margin:0 auto;padding:clamp(22px,4vw,40px);border-radius:22px;background:var(--wp--preset--color--base,#fff);color:var(--wp--preset--color--contrast,#14181f);border:1px solid color-mix(in srgb,currentColor 14%,transparent);box-shadow:0 18px 50px -24px rgba(0,0,0,.35)}'
		. '.gs-aff-card h2{margin:0 0 .4em;font-size:clamp(1.4rem,3vw,1.9rem);line-height:1.2}'
		. '.gs-aff-card p{margin:0 0 1em;line-height:1.6}'
		. '.gs-aff-facts{display:flex;flex-wrap:wrap;gap:12px;margin:0 0 1.2em;padding:0;list-style:none}'
		. '.gs-aff-facts li{flex:1 1 140px;padding:12px 14px;border-radius:14px;background:color-mix(in srgb,currentColor 6%,transparent)}'
		. '.gs-aff-facts strong{display:block;font-size:1.35rem}'
		. '.gs-aff-actions{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:1em}'
		. '.gs-aff-btn{display:inline-block;padding:12px 24px;border-radius:999px;border:0;cursor:pointer;font-weight:700;text-decoration:none;font-size:1rem;background:var(--wp--preset--color--brand-primary,' . esc_attr( $pri ) . ');color:' . esc_attr( $on ) . '}'
		. '.gs-aff-btn.is-ghost{background:transparent;color:inherit;border:1px solid color-mix(in srgb,currentColor 30%,transparent)}'
		. '.gs-aff-agree{display:flex;gap:10px;align-items:flex-start;line-height:1.5}'
		. '.gs-aff-agree input{margin-top:.3em}'
		. '.gs-aff-card a:not(.gs-aff-btn){color:inherit;text-decoration:underline}'
		. '</style>';
}

add_shortcode( 'gs_affiliate_register', 'gend_society_sb_affiliate_register_shortcode' );
/** The affiliate sign-up card. */
function gend_society_sb_affiliate_register_shortcode() {
	if ( ! function_exists( 'aas_umk' ) ) {
		return '';
	}
	$here = (string) get_permalink();
	$here = '' !== $here ? $here : home_url( '/' );
	$name = gend_society_sb_site_name();
	$css  = gend_society_sb_affiliate_card_css();
	if ( ! is_user_logged_in() ) {
		$buttons = '<a class="gs-aff-btn" href="' . esc_url( wp_login_url( $here ) ) . '">' . esc_html__( 'Log in', 'gend-society' ) . '</a>';
		if ( get_option( 'users_can_register' ) || ( is_multisite() && in_array( get_site_option( 'registration' ), array( 'user', 'all' ), true ) ) ) {
			$buttons .= '<a class="gs-aff-btn is-ghost" href="' . esc_url( add_query_arg( 'redirect_to', rawurlencode( $here ), wp_registration_url() ) ) . '">' . esc_html__( 'Create account', 'gend-society' ) . '</a>';
		}
		return $css . '<div class="gs-aff-card"><h2>' . esc_html( sprintf( /* translators: %s: site name */ __( 'Become a %s affiliate', 'gend-society' ), $name ) ) . '</h2><p>' . esc_html__( 'Log in or create an account to join the affiliate program and get your own referral link.', 'gend-society' ) . '</p><div class="gs-aff-actions">' . $buttons . '</div></div>';
	}
	$uid = get_current_user_id();
	if ( gend_society_sb_is_affiliate( $uid ) ) {
		$dash = gend_society_sb_affiliate_dashboard_url();
		$done = isset( $_GET['gs_aff_joined'] ) ? '<p><strong>' . esc_html__( 'Welcome to the program.', 'gend-society' ) . '</strong></p>' : ''; // phpcs:ignore WordPress.Security.NonceVerification -- display only
		return $css . '<div class="gs-aff-card"><h2>' . esc_html__( 'Your affiliate link', 'gend-society' ) . '</h2>' . $done . '<div class="gs-aff-link">' . do_shortcode( '[affiliate_link]' ) . '</div>'
			. ( '' !== $dash ? '<div class="gs-aff-actions"><a class="gs-aff-btn" href="' . esc_url( $dash ) . '">' . esc_html__( 'Open your affiliate dashboard', 'gend-society' ) . '</a></div>' : '' ) . '</div>';
	}
	$program = function_exists( 'aas_get_default_referral_program' ) ? (array) aas_get_default_referral_program() : array();
	$rate    = (float) ( $program['referred_tiers'][0]['rate'] ?? 0 );
	$days    = max( 0, (int) get_option( 'rp_store_reward_hold_days', 14 ) );
	$cookie  = (int) ( $program['cookie_duration'] ?? 30 );
	$terms   = (int) get_option( 'woocommerce_terms_page_id' );
	$terms_a = $terms && 'publish' === get_post_status( $terms )
		? sprintf( /* translators: %s: link */ __( 'I agree to the %s and the affiliate program terms above.', 'gend-society' ), '<a href="' . esc_url( get_permalink( $terms ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Terms of Service', 'gend-society' ) . '</a>' )
		: esc_html__( 'I agree to the affiliate program terms above.', 'gend-society' );
	$facts   = '<ul class="gs-aff-facts">'
		. ( $rate > 0 ? '<li><strong>' . esc_html( rtrim( rtrim( number_format( $rate, 2, '.', '' ), '0' ), '.' ) ) . '%</strong>' . esc_html__( 'commission on each sale you refer', 'gend-society' ) . '</li>' : '' )
		. '<li><strong>' . esc_html( (string) $cookie ) . ' ' . esc_html( _n( 'day', 'days', $cookie, 'gend-society' ) ) . '</strong>' . esc_html__( 'your link remembers a visitor', 'gend-society' ) . '</li>'
		. '<li><strong>' . esc_html( (string) $days ) . ' ' . esc_html( _n( 'day', 'days', $days, 'gend-society' ) ) . '</strong>' . esc_html__( 'return period before a commission is paid', 'gend-society' ) . '</li></ul>';
	return $css . '<div class="gs-aff-card"><h2>' . esc_html( sprintf( /* translators: %s: site name */ __( 'Join the %s affiliate program', 'gend-society' ), $name ) ) . '</h2>'
		. '<p>' . esc_html__( 'Share your link. When someone you refer buys, you earn a commission once the return period has passed. Refunded orders earn nothing.', 'gend-society' ) . '</p>' . $facts
		. '<form method="post" action="' . esc_url( $here ) . '">' . wp_nonce_field( 'gs_affiliate_join', 'gs_aff_nonce', true, false )
		. '<input type="hidden" name="gs_affiliate_join" value="1"><label class="gs-aff-agree"><input type="checkbox" name="gs_aff_agree" value="1" required> <span>' . wp_kses( $terms_a, array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true ) ) ) . '</span></label>'
		. '<div class="gs-aff-actions"><button type="submit" class="gs-aff-btn">' . esc_html__( 'Join the program', 'gend-society' ) . '</button></div></form></div>';
}

add_action( 'template_redirect', 'gend_society_sb_affiliate_join_post' );
/** The Join button: nonce, logged in, agreed, and it only ever enrols the person clicking. */
function gend_society_sb_affiliate_join_post() {
	if ( empty( $_POST['gs_affiliate_join'] ) || ! is_user_logged_in() || ! function_exists( 'aas_umk' ) ) {
		return;
	}
	if ( ! isset( $_POST['gs_aff_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gs_aff_nonce'] ) ), 'gs_affiliate_join' ) || empty( $_POST['gs_aff_agree'] ) ) {
		return;
	}
	gend_society_sb_affiliate_enrol( get_current_user_id() );
	update_user_meta( get_current_user_id(), aas_umk( 'affiliate_agreed' ), array( 'at' => time(), 'terms_page' => (int) get_option( 'woocommerce_terms_page_id' ) ) );
	$back = is_singular() ? (string) get_permalink() : home_url( '/' );
	wp_safe_redirect( add_query_arg( 'gs_aff_joined', '1', $back ) );
	exit;
}

add_filter( 'pre_do_shortcode_tag', 'gend_society_sb_affiliate_gate_shortcodes', 10, 2 );
/** Non-affiliates see an invitation to join in place of the affiliate screens. */
function gend_society_sb_affiliate_gate_shortcodes( $out, $tag ) {
	if ( false !== $out || ! in_array( $tag, array( 'affiliate_sales_dashboard', 'affiliate_link', 'affiliate_earnings', 'referral_team' ), true ) || ! gend_society_sb_affiliate_gated() ) {
		return $out;
	}
	if ( is_user_logged_in() && gend_society_sb_is_affiliate( get_current_user_id() ) ) {
		return $out;
	}
	$url = gend_society_sb_affiliate_join_url();
	return gend_society_sb_affiliate_card_css() . '<div class="gs-aff-card gs-aff-invite"><h2>' . esc_html__( 'Join the affiliate program', 'gend-society' ) . '</h2><p>' . esc_html__( 'This is for the program\'s affiliates. Join to get your referral link and earn a commission on the sales you refer.', 'gend-society' ) . '</p>'
		. ( '' !== $url ? '<div class="gs-aff-actions"><a class="gs-aff-btn" href="' . esc_url( $url ) . '">' . esc_html__( 'Join the program', 'gend-society' ) . '</a></div>' : '' ) . '</div>';
}

add_filter( 'aas_resolve_order_referrer', 'gend_society_sb_affiliate_order_referrer', 20, 2 );
/** A sale referred by someone who hasn't joined earns nothing. */
function gend_society_sb_affiliate_order_referrer( $resolved, $order ) {
	if ( is_array( $resolved ) && ! empty( $resolved['ref'] ) && gend_society_sb_affiliate_gated() && ! gend_society_sb_is_affiliate( (int) $resolved['ref'] ) ) {
		$resolved['ref'] = 0;
	}
	return $resolved;
}

add_action( 'user_register', 'gend_society_sb_affiliate_drop_cookie', 9 );
add_action( 'woocommerce_checkout_order_processed', 'gend_society_sb_affiliate_drop_cookie', 9 );
add_action( 'woocommerce_store_api_checkout_order_processed', 'gend_society_sb_affiliate_drop_cookie', 9 );
/** Before the sales team records a referrer at sign-up or checkout: a referral cookie naming a non-affiliate is ignored. */
function gend_society_sb_affiliate_drop_cookie() {
	if ( ! function_exists( 'aas_ck' ) || ! gend_society_sb_affiliate_gated() ) {
		return;
	}
	$name = aas_ck( 'aas_referrer_id' );
	if ( isset( $_COOKIE[ $name ] ) && ! gend_society_sb_is_affiliate( (int) $_COOKIE[ $name ] ) ) {
		unset( $_COOKIE[ $name ] );
	}
}

add_action( 'chat_form_submission', 'gend_society_sb_affiliate_form_submitted', 20, 3 );
/** The program's sign-up form enrols whoever sent it: the logged-in (or just registered) person, else the site member with that address. */
function gend_society_sb_affiliate_form_submitted( $form_id, $answers, $submission_id ) {
	$s = gend_society_sb_affiliate_settings();
	if ( ! $s['form_id'] || (int) $form_id !== $s['form_id'] || ! gend_society_sb_scoped() ) {
		return;
	}
	$uid = get_current_user_id();
	if ( ! $uid ) {
		foreach ( (array) $answers as $answer ) {
			$v = is_array( $answer ) ? ( $answer['answer'] ?? '' ) : $answer;
			if ( is_string( $v ) && is_email( trim( $v ) ) ) {
				$user = get_user_by( 'email', trim( $v ) );
				// Only someone already on this site: an address alone never pulls in an account from elsewhere on the network.
				if ( $user && is_user_member_of_blog( (int) $user->ID, get_current_blog_id() ) ) {
					$uid = (int) $user->ID;
				}
				break;
			}
		}
	}
	if ( $uid ) {
		gend_society_sb_affiliate_enrol( $uid );
	}
}

/** Branded sub-sites with a referral program get the affiliate account tab and profile tab. */
function gend_society_sb_affiliate_tabs_on() {
	return gend_society_sb_scoped() && gend_society_sb_has_referral_program();
}

add_filter( 'woocommerce_get_query_vars', 'gend_society_sb_affiliate_query_var' );
function gend_society_sb_affiliate_query_var( $vars ) {
	if ( gend_society_sb_affiliate_tabs_on() ) {
		$vars['affiliate'] = 'affiliate';
	}
	return $vars;
}

add_action( 'init', 'gend_society_sb_affiliate_flush', 99 );
/** The new account endpoint needs its rewrite rule, once per site. */
function gend_society_sb_affiliate_flush() {
	if ( gend_society_sb_affiliate_tabs_on() && '1' !== get_option( 'gend_society_affiliate_endpoint_flushed' ) ) {
		update_option( 'gend_society_affiliate_endpoint_flushed', '1', true ); // checked on every request
		flush_rewrite_rules( false );
	}
}

add_filter( 'woocommerce_account_menu_items', 'gend_society_sb_affiliate_account_item', 30 );
function gend_society_sb_affiliate_account_item( $items ) {
	if ( ! gend_society_sb_affiliate_tabs_on() ) {
		return $items;
	}
	$out = array();
	foreach ( (array) $items as $k => $v ) {
		if ( 'customer-logout' === $k ) {
			$out['affiliate'] = gend_society_sb_program_name();
		}
		$out[ $k ] = $v;
	}
	if ( ! isset( $out['affiliate'] ) ) {
		$out['affiliate'] = gend_society_sb_program_name();
	}
	return $out;
}

add_filter( 'woocommerce_endpoint_affiliate_title', 'gend_society_sb_affiliate_account_title' );
function gend_society_sb_affiliate_account_title() {
	return gend_society_sb_program_name();
}

add_action( 'woocommerce_account_affiliate_endpoint', 'gend_society_sb_affiliate_account_content' );
function gend_society_sb_affiliate_account_content() {
	if ( gend_society_sb_affiliate_tabs_on() ) {
		echo do_shortcode( '[affiliate_sales_dashboard]' ); // phpcs:ignore WordPress.Security.EscapeOutput -- the sales team's own escaped output
	}
}

// ---- Projects in My Account: the dashboards each customer or team member may follow ----

/** The group the site's Projects page and dashboards belong to. */
function gend_society_sb_projects_group_id() {
	$pid = gend_society_sb_find_page_with( 'psoo_pm_group_projects' );
	if ( $pid && preg_match( '/\[psoo_pm_group_projects[^\]]*group_id="(\d+)"/', (string) get_post_field( 'post_content', $pid ), $m ) ) {
		return (int) $m[1];
	}
	return gend_society_sb_site_group_id();
}

/** The Projects dashboards this viewer may see on this web app, by id. */
function gend_society_sb_account_dashboards( $user_id ) {
	if ( ! $user_id || ! gend_society_sb_scoped() || ! function_exists( 'psoo_dash_get_all' ) || ! function_exists( 'psoo_dash_user_can_view' ) ) {
		return array();
	}
	$group = gend_society_sb_projects_group_id();
	$out   = array();
	foreach ( (array) psoo_dash_get_all() as $id => $d ) {
		if ( is_array( $d ) && psoo_dash_user_can_view( $d, (int) $user_id, $group ) ) {
			$out[ (string) $id ] = $d;
		}
	}
	return $out;
}

add_filter( 'woocommerce_get_query_vars', 'gend_society_sb_projects_query_var' );
function gend_society_sb_projects_query_var( $vars ) {
	if ( gend_society_sb_scoped() ) {
		$vars['my-projects'] = 'my-projects';
	}
	return $vars;
}

add_action( 'init', 'gend_society_sb_projects_flush', 99 );
/** The new account endpoint needs its rewrite rule, once per site. */
function gend_society_sb_projects_flush() {
	if ( gend_society_sb_scoped() && '1' !== get_option( 'gend_society_projects_endpoint_flushed' ) ) {
		update_option( 'gend_society_projects_endpoint_flushed', '1', true ); // checked on every request
		flush_rewrite_rules( false );
	}
}

add_filter( 'woocommerce_account_menu_items', 'gend_society_sb_projects_account_item', 31 );
/** A Projects tab after Orders, for anyone with a dashboard to follow. */
function gend_society_sb_projects_account_item( $items ) {
	if ( ! gend_society_sb_account_dashboards( get_current_user_id() ) ) {
		return $items;
	}
	$out = array();
	foreach ( (array) $items as $k => $v ) {
		$out[ $k ] = $v;
		if ( 'orders' === $k ) {
			$out['my-projects'] = __( 'Projects', 'gend-society' );
		}
	}
	if ( ! isset( $out['my-projects'] ) ) {
		$out = array_slice( $out, 0, 1, true ) + array( 'my-projects' => __( 'Projects', 'gend-society' ) ) + array_slice( $out, 1, null, true );
	}
	return $out;
}

add_filter( 'woocommerce_endpoint_my-projects_title', 'gend_society_sb_projects_account_title' );
function gend_society_sb_projects_account_title() {
	return __( 'Projects', 'gend-society' );
}

add_action( 'woocommerce_account_my-projects_endpoint', 'gend_society_sb_projects_account_content' );
/** One dashboard at a time, with a tab for each the viewer may see. */
function gend_society_sb_projects_account_content() {
	$dash = gend_society_sb_account_dashboards( get_current_user_id() );
	if ( ! $dash ) {
		echo '<p>' . esc_html__( 'There is nothing to follow here yet.', 'gend-society' ) . '</p>';
		return;
	}
	$ids  = array_map( 'strval', array_keys( $dash ) );
	$want = isset( $_GET['dashboard'] ) ? sanitize_key( wp_unslash( $_GET['dashboard'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- choosing which dashboard to view
	$cur  = in_array( $want, $ids, true ) ? $want : $ids[0];
	if ( count( $ids ) > 1 ) {
		echo '<nav class="gs-account-dash-tabs" aria-label="' . esc_attr__( 'Dashboards', 'gend-society' ) . '">';
		foreach ( $dash as $id => $d ) {
			$id = (string) $id;
			printf(
				'<a href="%s"%s>%s</a>',
				esc_url( add_query_arg( 'dashboard', $id, wc_get_account_endpoint_url( 'my-projects' ) ) ),
				$id === $cur ? ' class="is-current" aria-current="page"' : '',
				esc_html( (string) ( $d['title'] ?? $id ) )
			);
		}
		echo '</nav>';
	}
	echo do_shortcode( '[psoo_dashboard id="' . esc_attr( $cur ) . '" group_id="' . (int) gend_society_sb_projects_group_id() . '"]' ); // phpcs:ignore WordPress.Security.EscapeOutput -- the Projects plugin's own escaped output
}

add_filter( 'aas_dashboard_single_referrals', 'gend_society_sb_scoped' );

add_filter( 'psoo_pm_default_group_id', 'gend_society_sb_projects_default_group' );
/** A board on a web app with no group named belongs to the site's own group. */
function gend_society_sb_projects_default_group( $group_id ) {
	return ! $group_id && gend_society_sb_scoped() ? gend_society_sb_projects_group_id() : $group_id;
}

add_action( 'bp_setup_nav', 'gend_society_sb_affiliate_profile_tab', 102 );
/** An Affiliate tab on the member's own profile. */
function gend_society_sb_affiliate_profile_tab() {
	if ( ! function_exists( 'bp_core_new_nav_item' ) || ! function_exists( 'bp_is_my_profile' ) || ! bp_is_my_profile() || ! gend_society_sb_affiliate_tabs_on() ) {
		return;
	}
	bp_core_new_nav_item(
		array(
			'name'                    => gend_society_sb_program_name(),
			'slug'                    => 'affiliate',
			'screen_function'         => 'gend_society_sb_affiliate_profile_screen',
			'position'                => 36,
			'item_css_id'             => 'affiliate',
			'show_for_displayed_user' => false,
			'user_has_access'         => bp_is_my_profile(),
		)
	);
}

function gend_society_sb_affiliate_profile_screen() {
	add_action( 'bp_template_title', '__return_empty_string' );
	add_action( 'bp_template_content', 'gend_society_sb_affiliate_profile_content' );
	bp_core_load_template( 'members/single/plugins' );
}

function gend_society_sb_affiliate_profile_content() {
	if ( bp_is_my_profile() ) {
		echo do_shortcode( '[affiliate_sales_dashboard]' ); // phpcs:ignore WordPress.Security.EscapeOutput -- the sales team's own escaped output
	}
}

// ---- Designed content for a profile or group tab ----

/** The tab's own panel (the feature's screen), which designed content sits above. */
function gend_society_sb_panel_blocks( $content ) {
	preg_match_all( '#<!--\s*wp:(?:gend-society/profile-tab-content|gdc-blocks/endpoint-panel)\b[^>]*?/-->#', (string) $content, $m );
	return $m[0];
}

/** Is a tab page only the stock panel, with nothing a person wrote? */
// ---- The community's own design: headers and directory tops (chat setup) ----
// The Design Studio's Social areas. A header the chat saves becomes the site's default; a directory top goes above the
// listing in that directory's published layout. Both are marked, so the chat may replace its own work without asking.

/** The site's default profile or group header post, if any. */
function gend_society_sb_social_header_post( $type ) {
	$ids = get_posts( array( 'post_type' => $type, 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true, 'meta_key' => '_gdc_is_site_default', 'meta_value' => '1' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	return $ids ? get_post( (int) $ids[0] ) : null;
}

/**
 * Saves the site's own profile or group header and makes it the default.
 *
 * @param array $a scope (profiles|groups), title, content (block markup).
 * @return array
 */
function gend_society_sb_social_header_save( array $a ) {
	$scope = 'groups' === ( $a['scope'] ?? '' ) ? 'groups' : 'profiles';
	$type  = 'groups' === $scope ? 'gdc_group_header' : 'gdc_profile_header';
	if ( ! post_type_exists( $type ) ) {
		return array( 'error' => 'groups' === $scope ? 'Group headers need the Projects app.' : 'Profile headers are not available on this web app.' );
	}
	$content = (string) ( $a['content'] ?? '' );
	$content = current_user_can( 'unfiltered_html' ) ? $content : wp_kses_post( $content );
	if ( '' === trim( $content ) ) {
		return array( 'error' => 'content is required.' );
	}
	if ( 'profiles' === $scope && false !== strpos( $content, 'profile-header-classic' ) ) {
		return array( 'error' => "The classic header is gend.me's own: build the header from the profile blocks." );
	}
	$title = sanitize_text_field( (string) ( $a['title'] ?? '' ) );
	$title = '' !== $title ? $title : ( 'groups' === $scope ? __( 'Group header', 'gend-society' ) : __( 'Profile header', 'gend-society' ) );
	$was   = gend_society_sb_social_header_post( $type );
	// The chat's own earlier header is updated; anything else stays, and the new one becomes the default.
	if ( $was && get_post_meta( $was->ID, '_gs_chat_header', true ) ) {
		$id = wp_update_post( wp_slash( array( 'ID' => $was->ID, 'post_title' => $title, 'post_content' => $content ) ), true );
	} else {
		$id = wp_insert_post( wp_slash( array( 'post_type' => $type, 'post_status' => 'publish', 'post_title' => $title, 'post_content' => $content ) ), true );
	}
	if ( is_wp_error( $id ) ) {
		return array( 'error' => $id->get_error_message() );
	}
	foreach ( get_posts( array( 'post_type' => $type, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) ) as $other ) {
		if ( (int) $other !== (int) $id ) {
			delete_post_meta( $other, '_gdc_is_site_default' );
		}
	}
	update_post_meta( $id, '_gdc_is_site_default', '1' );
	update_post_meta( $id, '_gs_chat_header', time() );
	return array( 'id' => (int) $id, 'scope' => $scope, 'default' => true );
}

/** Replacing a header the member designed themselves needs their confirmation. */
function gend_society_sb_social_header_risk( array $a ) {
	$was = gend_society_sb_social_header_post( 'groups' === ( $a['scope'] ?? '' ) ? 'gdc_group_header' : 'gdc_profile_header' );
	if ( ! $was || get_post_meta( $was->ID, '_gs_chat_header', true ) ) {
		return 'write';
	}
	// gend.me's stock headers: the classic profile header, or the seeded group cards.
	$stock = false !== strpos( $was->post_content, 'profile-header-classic' ) || false !== strpos( $was->post_content, 'group-pm-console-card' );
	return $stock ? 'write' : 'confirm';
}

/** The layouts a directory top can go in: the members and groups directories and the activity feed. */
const GEND_SOCIETY_SB_TOP_LAYOUTS = array( 'members-directory', 'groups-directory', 'activity' );

/** The sidebars the chat can design: their whole content, from profile widget or group blocks only. */
const GEND_SOCIETY_SB_SIDEBARS = array( 'profile-sidebar' => array( 'gend-society/profile-widget' ), 'group-sidebar' => array( 'gend-society/group-members', 'gend-society/group-about' ) );

/**
 * Puts a designed top above a directory or the activity feed, with the listing kept below, and publishes it.
 *
 * @param array $a slug, top (block markup).
 * @return array
 */
function gend_society_sb_social_layout_save( array $a ) {
	if ( ! function_exists( 'gend_society_ds_layouts' ) || ! function_exists( 'gend_society_ds_layout_id' ) ) {
		return array( 'error' => 'Directory layouts are not available on this web app.' );
	}
	$slug = sanitize_key( (string) ( $a['slug'] ?? '' ) );
	$defs = gend_society_ds_layouts();
	if ( isset( GEND_SOCIETY_SB_SIDEBARS[ $slug ] ) ) {
		return gend_society_sb_sidebar_save( $slug, (string) ( $a['content'] ?? '' ) );
	}
	if ( ! in_array( $slug, GEND_SOCIETY_SB_TOP_LAYOUTS, true ) || ! isset( $defs[ $slug ] ) ) {
		return array( 'error' => 'slug is one of: ' . implode( ', ', GEND_SOCIETY_SB_TOP_LAYOUTS ) . '.' );
	}
	$top = (string) ( $a['top'] ?? '' );
	$top = current_user_can( 'unfiltered_html' ) ? $top : wp_kses_post( $top );
	if ( '' === trim( $top ) ) {
		return array( 'error' => 'top is required.' );
	}
	// The listing itself, without the plain title the top replaces.
	$listing = preg_replace( '#^<!-- wp:heading \{"level":1\} -->.*?<!-- /wp:heading -->#s', '', (string) $defs[ $slug ]['content'] );
	$id      = gend_society_ds_layout_id( $slug );
	if ( ! $id ) {
		return array( 'error' => 'The layout could not be made.' );
	}
	$done = wp_update_post( wp_slash( array( 'ID' => $id, 'post_status' => 'publish', 'post_content' => $top . "
" . $listing ) ), true );
	if ( is_wp_error( $done ) ) {
		return array( 'error' => $done->get_error_message() );
	}
	update_post_meta( $id, '_gs_chat_layout', time() );
	return array( 'id' => (int) $id, 'slug' => $slug, 'published' => true );
}

/** A sidebar of profile widgets or group blocks, published as the site's sidebar. Nothing but those blocks is kept. */
function gend_society_sb_sidebar_save( $slug, $content ) {
	$allowed = GEND_SOCIETY_SB_SIDEBARS[ $slug ];
	$kept    = '';
	foreach ( parse_blocks( $content ) as $block ) {
		if ( in_array( $block['blockName'] ?? '', $allowed, true ) ) {
			$kept .= serialize_block( $block );
		}
	}
	if ( '' === $kept ) {
		return array( 'error' => 'content needs at least one of: ' . implode( ', ', $allowed ) . '.' );
	}
	$id = gend_society_ds_layout_id( $slug );
	if ( ! $id ) {
		return array( 'error' => 'The sidebar could not be made.' );
	}
	$done = wp_update_post( wp_slash( array( 'ID' => $id, 'post_status' => 'publish', 'post_content' => $kept ) ), true );
	if ( is_wp_error( $done ) ) {
		return array( 'error' => $done->get_error_message() );
	}
	update_post_meta( $id, '_gs_chat_layout', time() );
	return array( 'id' => (int) $id, 'slug' => $slug, 'published' => true );
}

/** Replacing a layout the member designed themselves needs their confirmation. */
function gend_society_sb_social_layout_risk( array $a ) {
	$slug = sanitize_key( (string) ( $a['slug'] ?? '' ) );
	$was  = function_exists( 'gend_society_ds_layout' ) ? gend_society_ds_layout( $slug ) : null;
	return ! $was || get_post_meta( $was->ID, '_gs_chat_layout', true ) ? 'write' : 'confirm';
}

/**
 * Saves the site's own copy of a Design Studio template (such as page-wallet), which the Studio counts as designed.
 * Without content it starts from the theme's base layout for that template, so the site's header and footer come with it.
 *
 * @param array $a slug, content (optional block markup).
 * @return array
 */
function gend_society_sb_studio_template_save( array $a ) {
	$defs = function_exists( 'gend_society_studio_template_defs' ) ? gend_society_studio_template_defs() : array();
	$slug = sanitize_key( (string) ( $a['slug'] ?? '' ) );
	if ( ! isset( $defs[ $slug ] ) ) {
		return array( 'error' => 'slug is one of: ' . implode( ', ', array_keys( $defs ) ) . '.' );
	}
	$content = (string) ( $a['content'] ?? '' );
	if ( '' === trim( $content ) ) {
		$base    = function_exists( 'get_block_template' ) ? get_block_template( get_stylesheet() . '//' . ( $defs[ $slug ]['base'] ?? 'page' ) ) : null;
		$content = $base ? (string) $base->content : '';
	}
	$content = current_user_can( 'unfiltered_html' ) ? $content : wp_kses_post( $content );
	if ( '' === trim( $content ) ) {
		return array( 'error' => 'The theme has no layout to start from; send content.' );
	}
	$theme = get_stylesheet();
	$was   = get_posts( array( 'post_type' => 'wp_template', 'post_status' => array( 'publish', 'draft' ), 'name' => $slug, 'posts_per_page' => 1, 'no_found_rows' => true, 'tax_query' => array( array( 'taxonomy' => 'wp_theme', 'field' => 'name', 'terms' => $theme ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	$post  = array( 'post_type' => 'wp_template', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => (string) ( $defs[ $slug ]['title'] ?? $slug ), 'post_content' => $content );
	$id    = $was ? wp_update_post( wp_slash( array( 'ID' => $was[0]->ID ) + $post ), true ) : wp_insert_post( wp_slash( $post ), true );
	if ( is_wp_error( $id ) ) {
		return array( 'error' => $id->get_error_message() );
	}
	wp_set_object_terms( $id, $theme, 'wp_theme' );
	update_post_meta( $id, '_gs_chat_template', time() );
	return array( 'id' => (int) $id, 'slug' => $slug, 'saved' => true );
}

/** Replacing a template the member designed themselves needs their confirmation. */
function gend_society_sb_studio_template_risk( array $a ) {
	$was = get_posts( array( 'post_type' => 'wp_template', 'post_status' => array( 'publish', 'draft' ), 'name' => sanitize_key( (string) ( $a['slug'] ?? '' ) ), 'posts_per_page' => 1, 'no_found_rows' => true, 'tax_query' => array( array( 'taxonomy' => 'wp_theme', 'field' => 'name', 'terms' => get_stylesheet() ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	return ! $was || get_post_meta( $was[0]->ID, '_gs_chat_template', true ) ? 'write' : 'confirm';
}

function gend_society_sb_tab_page_is_stock( $content ) {
	$rest = trim( str_replace( gend_society_sb_panel_blocks( $content ), '', (string) $content ) );
	return '' === $rest;
}

/**
 * Finds the page a tab's content goes to.
 *
 * @param array $a scope, tab_slug, audience.
 * @return array|WP_Error { scope, sc, slug, post (WP_Post|null), audience (array|null) }
 */
function gend_society_sb_social_tab_target( array $a ) {
	if ( ! function_exists( 'gend_society_sp_scope' ) || ! function_exists( 'gend_society_sp_menu' ) ) {
		return new WP_Error( 'gs_tab', 'Profile and group pages are not available on this web app.' );
	}
	$scope = 'groups' === ( $a['scope'] ?? '' ) ? 'groups' : 'profiles';
	$slug  = sanitize_key( (string) ( $a['tab_slug'] ?? '' ) );
	$menu  = gend_society_sp_menu( $scope );
	$slugs = wp_list_pluck( $menu, 'slug' );
	if ( '' === $slug || ! in_array( $slug, $slugs, true ) ) {
		$how = 'groups' === $scope
			? 'To add one, send the whole group menu with a new row {"slug":"' . ( $slug ?: 'my-tab' ) . '","label":"…","custom":true} through app_social_page_action (scope groups, action menu), then call this again.'
			: 'Profile tabs come from the features that add them, so a new one can\'t be made here; pick one of the existing tabs.';
		return new WP_Error( 'gs_tab', 'There is no "' . $slug . '" tab in the ' . $scope . ' menu. Tabs: ' . implode( ', ', $slugs ) . '. ' . $how );
	}
	$sc       = gend_society_sp_scope( $scope );
	$audience = ! empty( $a['audience'] ) && ( is_array( $a['audience'] ) || is_string( $a['audience'] ) ) ? gend_society_sp_clean_audience( $a['audience'] ) : null;
	$post     = null;
	if ( $audience ) {
		$json = wp_json_encode( $audience );
		// Not the cached gend_society_sp_audience_pages(): a page made earlier in this request must be found too.
		$pages = get_posts( array( 'post_type' => $sc['page_type'], 'post_status' => 'publish', 'posts_per_page' => 50, 'no_found_rows' => true, 'meta_query' => array( array( 'key' => $sc['slug_key'], 'value' => $slug ), array( 'key' => '_gs_chat_tab_content', 'compare' => 'EXISTS' ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		foreach ( $pages as $p ) {
			if ( get_post_meta( $p->ID, '_gs_chat_tab_content', true ) && wp_json_encode( gend_society_sp_clean_audience( get_post_meta( $p->ID, '_gs_audience', true ) ) ) === $json ) {
				$post = $p;
				break;
			}
		}
	} else {
		$post = gend_society_sp_default_page( $scope, $slug );
	}
	return compact( 'scope', 'sc', 'slug', 'post', 'audience' );
}

/** Replacing a page the member wrote themselves needs their confirmation. */
function gend_society_sb_social_tab_risk( array $a ) {
	$t = gend_society_sb_social_tab_target( $a );
	if ( is_wp_error( $t ) || ! $t['post'] ) {
		return 'write';
	}
	return get_post_meta( $t['post']->ID, '_gs_chat_tab_content', true ) || gend_society_sb_tab_page_is_stock( $t['post']->post_content ) ? 'write' : 'confirm';
}

/**
 * Puts designed content on a profile or group tab. The tab's own panel stays below it.
 *
 * @param array $a scope, tab_slug, title, content, audience.
 * @return array
 */
function gend_society_sb_social_tab_content_save( array $a ) {
	$t = gend_society_sb_social_tab_target( $a );
	if ( is_wp_error( $t ) ) {
		return array( 'error' => $t->get_error_message() );
	}
	$content = (string) ( $a['content'] ?? '' );
	$content = current_user_can( 'unfiltered_html' ) ? $content : wp_kses_post( $content );
	if ( '' === trim( $content ) ) {
		return array( 'error' => 'content is required.' );
	}
	$title = sanitize_text_field( (string) ( $a['title'] ?? '' ) );
	$from  = $t['post'] ? $t['post']->post_content : ( $t['audience'] && gend_society_sp_default_page( $t['scope'], $t['slug'] ) ? gend_society_sp_default_page( $t['scope'], $t['slug'] )->post_content : '' );
	if ( '' === $from && 'profiles' === $t['scope'] ) {
		$from = (string) apply_filters( 'gend_society_ps_default_tab_content', '<!-- wp:gend-society/profile-tab-content /-->', $t['slug'] );
	}
	// A tab getting its first page keeps the feature's own screen under the new content.
	if ( ! $t['post'] && ! gend_society_sb_panel_blocks( $from ) ) {
		$from .= 'groups' === $t['scope'] ? '<!-- wp:gdc-blocks/endpoint-panel ' . wp_json_encode( array( 'slug' => $t['slug'] ) ) . ' /-->' : '<!-- wp:gend-society/profile-tab-content /-->';
	}
	$panels = array_diff( gend_society_sb_panel_blocks( $from ), gend_society_sb_panel_blocks( $content ) );
	$body   = $content . ( $panels ? "\n" . implode( "\n", $panels ) : '' );
	if ( $t['post'] ) {
		$u = array( 'ID' => (int) $t['post']->ID, 'post_content' => $body );
		if ( '' !== $title ) {
			$u['post_title'] = $title;
		}
		$id = wp_update_post( wp_slash( $u ), true );
	} else {
		$id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => $t['sc']['page_type'],
					'post_status'  => 'publish',
					'post_title'   => '' !== $title ? $title : $t['slug'],
					'post_content' => $body,
					'menu_order'   => $t['audience'] ? count( gend_society_sp_audience_pages( $t['scope'], $t['slug'] ) ) + 1 : 0,
				)
			),
			true
		);
		if ( ! is_wp_error( $id ) ) {
			update_post_meta( $id, $t['sc']['slug_key'], $t['slug'] );
			if ( $t['audience'] ) {
				update_post_meta( $id, '_gs_audience', wp_slash( wp_json_encode( $t['audience'] ) ) );
			} else {
				update_post_meta( $id, '_gdc_is_site_default', '1' );
			}
		}
	}
	if ( is_wp_error( $id ) ) {
		return array( 'error' => $id->get_error_message() );
	}
	update_post_meta( $id, '_gs_chat_tab_content', time() );
	return array( 'id' => (int) $id, 'scope' => $t['scope'], 'tab' => $t['slug'], 'kept_panel' => (bool) $panels, 'audience' => $t['audience'] ? $t['audience'] : 'everyone (the tab\'s default page)' );
}

// ---- Sales Team template proposals ----

/**
 * Creates, or updates by id, a Sales Team template proposal (Offer Templates: _stp_subtab = templates). A new one is a
 * draft: a template is bought from its own public page, so customers can't reach it until the owner publishes it.
 * Milestone prices in the plugin come from the products on a milestone; amount and days are kept on the milestone
 * and shown in its description so the owner sees the agreed figures.
 *
 * @param array $a id, title, content, milestones [{title, description, amount, days, product_ids}], product_ids.
 * @return array
 */
function gend_society_sb_proposal_template_save( array $a ) {
	if ( ! post_type_exists( 'st_proposal' ) ) {
		return array( 'error' => 'The sales team tools are not available on this web app.' );
	}
	$id = (int) ( $a['id'] ?? 0 );
	if ( $id ) {
		if ( 'st_proposal' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			return array( 'error' => 'You cannot edit that proposal.' );
		}
		if ( 'templates' !== get_post_meta( $id, '_stp_subtab', true ) ) {
			return array( 'error' => 'That proposal is not a template, so it is changed in the Sales Team screen.' );
		}
	}
	$title = sanitize_text_field( (string) ( $a['title'] ?? '' ) );
	if ( ! $id && '' === $title ) {
		return array( 'error' => 'title is required.' );
	}
	$kses    = static function ( $html ) {
		return current_user_can( 'unfiltered_html' ) ? (string) $html : wp_kses_post( (string) $html );
	};
	$product = static function ( $pid ) {
		$pid = absint( $pid );
		$p   = $pid && function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
		return $p && 'trash' !== get_post_status( $pid ) ? $pid : 0;
	};
	$milestones = null;
	if ( array_key_exists( 'milestones', $a ) ) {
		$milestones = array();
		$top        = array_values( array_filter( array_map( $product, (array) ( $a['product_ids'] ?? array() ) ) ) );
		foreach ( array_slice( (array) $a['milestones'], 0, 20 ) as $i => $m ) {
			$m      = (array) $m;
			$amount = isset( $m['amount'] ) && is_numeric( $m['amount'] ) ? max( 0.0, round( (float) $m['amount'], 2 ) ) : 0.0;
			$days   = isset( $m['days'] ) && is_numeric( $m['days'] ) ? max( 0, (int) $m['days'] ) : 0;
			$ids    = array_values( array_filter( array_map( $product, (array) ( $m['product_ids'] ?? array() ) ) ) );
			if ( 0 === $i ) {
				$ids = array_values( array_unique( array_merge( $ids, $top ) ) );
			}
			$terms = array();
			if ( $amount > 0 ) {
				$terms[] = function_exists( 'wc_price' ) ? wp_strip_all_tags( wc_price( $amount ) ) : number_format( $amount, 2 );
			}
			if ( $days > 0 ) {
				/* translators: %d: days */
				$terms[] = sprintf( _n( '%d day', '%d days', $days, 'gend-society' ), $days );
			}
			$milestones[] = array(
				'title'       => sanitize_text_field( (string) ( $m['title'] ?? '' ) ),
				'description' => $kses( $m['description'] ?? '' ) . ( $terms ? '<p class="gs-ms-terms"><strong>' . esc_html( implode( ' · ', $terms ) ) . '</strong></p>' : '' ),
				'products'    => array_map(
					static function ( $pid ) {
						return array( 'product_id' => $pid, 'quantity' => 1, 'workspace_id' => 0, 'task_id' => 0 );
					},
					$ids
				),
				'order_id'    => '',
				'amount'      => $amount,
				'days'        => $days,
			);
		}
	} elseif ( ! empty( $a['product_ids'] ) && $id ) {
		return array( 'error' => 'Send product_ids together with milestones.' );
	}
	$post = array( 'ID' => $id, 'post_type' => 'st_proposal' );
	if ( '' !== $title ) {
		$post['post_title'] = $title;
	}
	if ( array_key_exists( 'content', $a ) ) {
		$post['post_content'] = $kses( $a['content'] );
	}
	if ( ! $id ) {
		$post['post_status'] = 'draft';
		$post               += array( 'post_content' => '' );
	}
	$saved = $id ? wp_update_post( wp_slash( $post ), true ) : wp_insert_post( wp_slash( $post ), true );
	if ( is_wp_error( $saved ) ) {
		return array( 'error' => $saved->get_error_message() );
	}
	$saved = (int) $saved;
	update_post_meta( $saved, '_stp_subtab', 'templates' );
	if ( null !== $milestones ) {
		if ( $milestones ) {
			update_post_meta( $saved, '_stp_milestones', $milestones );
		} else {
			delete_post_meta( $saved, '_stp_milestones' );
		}
	}
	// The plugin locks the payment type once set; a template with milestones is paid by milestone.
	if ( ! get_post_meta( $saved, '_stp_payment_type', true ) ) {
		update_post_meta( $saved, '_stp_payment_type', $milestones ? 'milestone' : 'task' );
	}
	wp_cache_delete( $saved, 'post_meta' );
	$edit = get_edit_post_link( $saved, 'raw' );
	return array(
		'id'       => $saved,
		'edit_url' => $edit ? $edit : admin_url( 'admin.php?page=st_sales_team' ),
		'status'   => get_post_status( $saved ),
		'total'    => $milestones ? array_sum( wp_list_pluck( $milestones, 'amount' ) ) : null,
	);
}
