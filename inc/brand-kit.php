<?php
/**
 * Brand kit: Voice & Tone, App Styling and Cast Members.
 *
 * The three written parts of the brand, next to the logo, colours and type the
 * Design Studio already edits. They are filled in on the studio's Branding
 * screens, shown as chapters of the Brand Guidelines document
 * (?gs_studio_specimen=1), and turned into a plain-text brief that AI requests
 * on this site get automatically (aipa_system_context), so copy and images stay
 * on brand without restating it in every prompt.
 *
 * App Styling describes the look (for the guide and the AI); it does not
 * restyle the site.
 *
 * @package GendSociety
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option holding the kit: array( 'voice' => …, 'style' => …, 'cast' => … ).
 */
const GEND_SOCIETY_BRAND_KIT = 'gend_society_brand_kit';

/* ── Choices ────────────────────────────────────────────────────────────── */

/**
 * Fixed choices the screens and the guide share.
 *
 * @return array
 */
function gend_society_bk_choices() {
	return array(
		'sliders'  => array(
			'formality'  => array( __( 'Formal', 'gend-society' ), __( 'Casual', 'gend-society' ) ),
			'humour'     => array( __( 'Serious', 'gend-society' ), __( 'Playful', 'gend-society' ) ),
			'respect'    => array( __( 'Respectful', 'gend-society' ), __( 'Irreverent', 'gend-society' ) ),
			'enthusiasm' => array( __( 'Matter-of-fact', 'gend-society' ), __( 'Enthusiastic', 'gend-society' ) ),
		),
		'contexts' => array(
			'marketing' => __( 'Marketing & ads', 'gend-society' ),
			'product'   => __( 'In the app', 'gend-society' ),
			'support'   => __( 'Help & support', 'gend-society' ),
			'social'    => __( 'Social posts', 'gend-society' ),
			'email'     => __( 'Email', 'gend-society' ),
			'errors'    => __( 'Errors & bad news', 'gend-society' ),
		),
		'rules'    => array(
			'spelling'     => array(
				'label' => __( 'Spelling', 'gend-society' ),
				'opts'  => array(
					'en-CA' => __( 'Canadian English', 'gend-society' ),
					'en-US' => __( 'American English', 'gend-society' ),
					'en-GB' => __( 'British English', 'gend-society' ),
				),
			),
			'headings'     => array(
				'label' => __( 'Headings', 'gend-society' ),
				'opts'  => array(
					'sentence' => __( 'Sentence case', 'gend-society' ),
					'title'    => __( 'Title Case', 'gend-society' ),
					'upper'    => __( 'UPPERCASE', 'gend-society' ),
				),
			),
			'person'       => array(
				'label' => __( 'We speak as', 'gend-society' ),
				'opts'  => array(
					'we'    => __( '“We” to “you”', 'gend-society' ),
					'brand' => __( 'The brand name', 'gend-society' ),
					'i'     => __( '“I” (a founder or host)', 'gend-society' ),
				),
			),
			'contractions' => array(
				'label' => __( 'Contractions', 'gend-society' ),
				'opts'  => array(
					'yes' => __( 'Use them (we’re, you’ll)', 'gend-society' ),
					'no'  => __( 'Avoid them', 'gend-society' ),
				),
			),
			'emoji'        => array(
				'label' => __( 'Emoji', 'gend-society' ),
				'opts'  => array(
					'never'     => __( 'Never', 'gend-society' ),
					'sparingly' => __( 'Sparingly, social only', 'gend-society' ),
					'freely'    => __( 'Freely', 'gend-society' ),
				),
			),
			'exclaim'      => array(
				'label' => __( 'Exclamation marks', 'gend-society' ),
				'opts'  => array(
					'never' => __( 'Never', 'gend-society' ),
					'rare'  => __( 'Rarely, for real wins', 'gend-society' ),
					'ok'    => __( 'Where they fit', 'gend-society' ),
				),
			),
		),
		'styles'   => array(
			'flat'          => array( __( 'Flat', 'gend-society' ), __( 'Solid fills, no shadows or gradients; clean and fast.', 'gend-society' ) ),
			'minimal'       => array( __( 'Minimal', 'gend-society' ), __( 'Lots of white space, hairline rules, type does the work.', 'gend-society' ) ),
			'glassmorphism' => array( __( 'Glassmorphism', 'gend-society' ), __( 'Frosted, translucent panels over colour, with soft light edges.', 'gend-society' ) ),
			'claymorphism'  => array( __( 'Claymorphism', 'gend-society' ), __( 'Soft, puffy 3D shapes with rounded corners and inner highlights.', 'gend-society' ) ),
			'neumorphism'   => array( __( 'Neumorphism', 'gend-society' ), __( 'Shapes pressed into or raised from the background with twin shadows.', 'gend-society' ) ),
			'material'      => array( __( 'Material', 'gend-society' ), __( 'Paper-like layers with clear elevation and bold colour.', 'gend-society' ) ),
			'brutalism'     => array( __( 'Neo-brutalism', 'gend-society' ), __( 'Thick outlines, hard offset shadows, loud colour, raw type.', 'gend-society' ) ),
			'skeuomorphic'  => array( __( 'Skeuomorphic', 'gend-society' ), __( 'Real-world textures, materials and depth.', 'gend-society' ) ),
			'custom'        => array( __( 'Your own', 'gend-society' ), __( 'Name and describe a style of your own.', 'gend-society' ) ),
		),
		'traits'   => array(
			'corners' => array(
				'label' => __( 'Corners', 'gend-society' ),
				'opts'  => array(
					'sharp'   => __( 'Sharp', 'gend-society' ),
					'soft'    => __( 'Soft', 'gend-society' ),
					'rounded' => __( 'Rounded', 'gend-society' ),
					'pill'    => __( 'Pill', 'gend-society' ),
				),
			),
			'depth'   => array(
				'label' => __( 'Depth', 'gend-society' ),
				'opts'  => array(
					'flat'     => __( 'Flat', 'gend-society' ),
					'subtle'   => __( 'Subtle shadows', 'gend-society' ),
					'layered'  => __( 'Layered', 'gend-society' ),
					'floating' => __( 'Floating', 'gend-society' ),
				),
			),
			'buttons' => array(
				'label' => __( 'Buttons', 'gend-society' ),
				'opts'  => array(
					'solid'    => __( 'Solid', 'gend-society' ),
					'outline'  => __( 'Outline', 'gend-society' ),
					'gradient' => __( 'Gradient', 'gend-society' ),
					'glass'    => __( 'Glass', 'gend-society' ),
					'soft'     => __( 'Soft tint', 'gend-society' ),
				),
			),
			'forms'   => array(
				'label' => __( 'Form fields', 'gend-society' ),
				'opts'  => array(
					'outlined'  => __( 'Outlined', 'gend-society' ),
					'filled'    => __( 'Filled', 'gend-society' ),
					'underline' => __( 'Underline', 'gend-society' ),
					'glass'     => __( 'Glass', 'gend-society' ),
				),
			),
			'cards'   => array(
				'label' => __( 'Cards', 'gend-society' ),
				'opts'  => array(
					'bordered' => __( 'Bordered', 'gend-society' ),
					'elevated' => __( 'Elevated', 'gend-society' ),
					'glass'    => __( 'Glass', 'gend-society' ),
					'clay'     => __( 'Clay', 'gend-society' ),
					'flat'     => __( 'Flat tint', 'gend-society' ),
				),
			),
			'icons'   => array(
				'label' => __( 'Icons', 'gend-society' ),
				'opts'  => array(
					'line'    => __( 'Line', 'gend-society' ),
					'filled'  => __( 'Filled', 'gend-society' ),
					'duotone' => __( 'Duotone', 'gend-society' ),
					'3d'      => __( '3D', 'gend-society' ),
				),
			),
			'imagery' => array(
				'label' => __( 'Imagery', 'gend-society' ),
				'opts'  => array(
					'photo'        => __( 'Photography', 'gend-society' ),
					'illustration' => __( 'Illustration', 'gend-society' ),
					'3d'           => __( '3D renders', 'gend-society' ),
					'abstract'     => __( 'Abstract & gradients', 'gend-society' ),
					'mixed'        => __( 'A mix', 'gend-society' ),
				),
			),
			'motion'  => array(
				'label' => __( 'Motion', 'gend-society' ),
				'opts'  => array(
					'none'        => __( 'None', 'gend-society' ),
					'subtle'      => __( 'Subtle', 'gend-society' ),
					'expressive'  => __( 'Expressive', 'gend-society' ),
				),
			),
		),
		'kinds'    => array(
			'character'    => __( 'Character', 'gend-society' ),
			'mascot'       => __( 'Mascot', 'gend-society' ),
			'avatar'       => __( 'Avatar', 'gend-society' ),
			'agent'        => __( 'AI agent', 'gend-society' ),
			'spokesperson' => __( 'Spokesperson', 'gend-society' ),
			'member'       => __( 'Real member', 'gend-society' ),
		),
		'channels' => array(
			'website' => __( 'Website', 'gend-society' ),
			'app'     => __( 'In the app', 'gend-society' ),
			'social'  => __( 'Social', 'gend-society' ),
			'ads'     => __( 'Ads', 'gend-society' ),
			'email'   => __( 'Email', 'gend-society' ),
			'support' => __( 'Support', 'gend-society' ),
			'video'   => __( 'Video', 'gend-society' ),
			'events'  => __( 'Events', 'gend-society' ),
		),
	);
}

/**
 * Two palette colours for the style samples: the first one dark enough for
 * white text, and a second for gradients.
 *
 * @return array{0:string,1:string} Hex colours.
 */
function gend_society_bk_accents() {
	$palette = function_exists( 'wp_get_global_settings' ) ? wp_get_global_settings( array( 'color', 'palette' ) ) : array();
	$hexes   = array();
	foreach ( array( 'custom', 'theme' ) as $origin ) {
		foreach ( isset( $palette[ $origin ] ) ? (array) $palette[ $origin ] : array() as $c ) {
			if ( isset( $c['color'] ) && preg_match( '/^#([0-9a-f]{6})$/i', $c['color'], $m ) ) {
				$hexes[] = strtolower( $c['color'] );
			}
		}
	}
	$lum  = function ( $hex ) {
		$r = hexdec( substr( $hex, 1, 2 ) ) / 255;
		$g = hexdec( substr( $hex, 3, 2 ) ) / 255;
		$b = hexdec( substr( $hex, 5, 2 ) ) / 255;
		return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
	};
	// Greys (text and background neutrals) are not brand colours.
	$chroma = function ( $hex ) {
		$c = array( hexdec( substr( $hex, 1, 2 ) ), hexdec( substr( $hex, 3, 2 ) ), hexdec( substr( $hex, 5, 2 ) ) );
		return ( max( $c ) - min( $c ) ) / 255;
	};
	$main   = '#6a5cff';
	foreach ( $hexes as $hex ) {
		$l = $lum( $hex );
		// A colour dark enough for white text.
		if ( $l < 0.42 && $chroma( $hex ) > 0.15 ) {
			$main = $hex;
			break;
		}
	}
	$second = '#ff6fae';
	foreach ( $hexes as $hex ) {
		if ( $hex !== $main && $chroma( $hex ) > 0.15 && $lum( $hex ) < 0.9 ) {
			$second = $hex;
			break;
		}
	}
	return array( $main, $second );
}

/* ── Storage ────────────────────────────────────────────────────────────── */

/**
 * The saved kit, with every field present.
 *
 * @return array
 */
function gend_society_bk_get() {
	$kit = get_option( GEND_SOCIETY_BRAND_KIT, array() );
	$kit = is_array( $kit ) ? $kit : array();
	return array(
		'voice' => gend_society_bk_clean_voice( isset( $kit['voice'] ) ? $kit['voice'] : array() ),
		'style' => gend_society_bk_clean_style( isset( $kit['style'] ) ? $kit['style'] : array() ),
		'cast'  => gend_society_bk_clean_cast( isset( $kit['cast'] ) ? $kit['cast'] : array() ),
	);
}

/**
 * One line of text.
 *
 * @param mixed $v   Value.
 * @param int   $max Max length.
 * @return string
 */
function gend_society_bk_line( $v, $max = 200 ) {
	return mb_substr( sanitize_text_field( is_scalar( $v ) ? (string) $v : '' ), 0, $max );
}

/**
 * A paragraph of text.
 *
 * @param mixed $v   Value.
 * @param int   $max Max length.
 * @return string
 */
function gend_society_bk_text( $v, $max = 1500 ) {
	return mb_substr( sanitize_textarea_field( is_scalar( $v ) ? (string) $v : '' ), 0, $max );
}

/**
 * A list of short words or phrases.
 *
 * @param mixed $v   Value (array or comma/newline separated text).
 * @param int   $max Max items.
 * @return string[]
 */
function gend_society_bk_list( $v, $max = 40 ) {
	if ( is_string( $v ) ) {
		$v = preg_split( '/[\n,]+/', $v );
	}
	$out = array();
	foreach ( is_array( $v ) ? $v : array() as $item ) {
		$item = gend_society_bk_line( $item, 80 );
		if ( '' !== $item && ! in_array( $item, $out, true ) ) {
			$out[] = $item;
		}
	}
	return array_slice( $out, 0, $max );
}

/**
 * A choice from a fixed set.
 *
 * @param mixed  $v    Value.
 * @param array  $opts Allowed keys => labels.
 * @param string $def  Default.
 * @return string
 */
function gend_society_bk_pick( $v, $opts, $def ) {
	$v = is_scalar( $v ) ? (string) $v : '';
	return isset( $opts[ $v ] ) ? $v : $def;
}

/**
 * Clean Voice & Tone.
 *
 * @param array $in Raw.
 * @return array
 */
function gend_society_bk_clean_voice( $in ) {
	$in  = is_array( $in ) ? $in : array();
	$c   = gend_society_bk_choices();
	$out = array(
		'essence'  => gend_society_bk_text( isset( $in['essence'] ) ? $in['essence'] : '', 400 ),
		'audience' => gend_society_bk_text( isset( $in['audience'] ) ? $in['audience'] : '', 800 ),
		'traits'   => array(),
		'sliders'  => array(),
		'tone'     => array(),
		'use'      => gend_society_bk_list( isset( $in['use'] ) ? $in['use'] : array() ),
		'avoid'    => gend_society_bk_list( isset( $in['avoid'] ) ? $in['avoid'] : array() ),
		'never'    => gend_society_bk_list( isset( $in['never'] ) ? $in['never'] : array(), 20 ),
		'rules'    => array(),
		'examples' => array(),
	);
	foreach ( isset( $in['traits'] ) && is_array( $in['traits'] ) ? array_slice( $in['traits'], 0, 6 ) : array() as $t ) {
		$t = is_array( $t ) ? $t : array();
		$row = array(
			'trait' => gend_society_bk_line( isset( $t['trait'] ) ? $t['trait'] : '', 60 ),
			'is'    => gend_society_bk_text( isset( $t['is'] ) ? $t['is'] : '', 300 ),
			'isnt'  => gend_society_bk_text( isset( $t['isnt'] ) ? $t['isnt'] : '', 300 ),
		);
		if ( '' !== $row['trait'] ) {
			$out['traits'][] = $row;
		}
	}
	foreach ( $c['sliders'] as $k => $ends ) {
		$v                    = isset( $in['sliders'][ $k ] ) ? (int) $in['sliders'][ $k ] : 3;
		$out['sliders'][ $k ] = max( 1, min( 5, $v ) );
	}
	foreach ( $c['contexts'] as $k => $label ) {
		$out['tone'][ $k ] = gend_society_bk_text( isset( $in['tone'][ $k ] ) ? $in['tone'][ $k ] : '', 400 );
	}
	$defaults = array(
		'spelling'     => 'en-CA',
		'headings'     => 'sentence',
		'person'       => 'we',
		'contractions' => 'yes',
		'emoji'        => 'sparingly',
		'exclaim'      => 'rare',
	);
	foreach ( $c['rules'] as $k => $rule ) {
		$out['rules'][ $k ] = gend_society_bk_pick( isset( $in['rules'][ $k ] ) ? $in['rules'][ $k ] : '', $rule['opts'], $defaults[ $k ] );
	}
	foreach ( isset( $in['examples'] ) && is_array( $in['examples'] ) ? array_slice( $in['examples'], 0, 12 ) : array() as $e ) {
		$e   = is_array( $e ) ? $e : array();
		$row = array(
			'context' => gend_society_bk_line( isset( $e['context'] ) ? $e['context'] : '', 80 ),
			'do'      => gend_society_bk_text( isset( $e['do'] ) ? $e['do'] : '', 400 ),
			'dont'    => gend_society_bk_text( isset( $e['dont'] ) ? $e['dont'] : '', 400 ),
		);
		if ( '' !== $row['do'] || '' !== $row['dont'] ) {
			$out['examples'][] = $row;
		}
	}
	return $out;
}

/**
 * Clean App Styling.
 *
 * @param array $in Raw.
 * @return array
 */
function gend_society_bk_clean_style( $in ) {
	$in       = is_array( $in ) ? $in : array();
	$c        = gend_society_bk_choices();
	$defaults = array(
		'corners' => 'rounded',
		'depth'   => 'subtle',
		'buttons' => 'solid',
		'forms'   => 'outlined',
		'cards'   => 'bordered',
		'icons'   => 'line',
		'imagery' => 'photo',
		'motion'  => 'subtle',
	);
	$out      = array(
		'preset'      => gend_society_bk_pick( isset( $in['preset'] ) ? $in['preset'] : '', $c['styles'], '' ),
		'customName'  => gend_society_bk_line( isset( $in['customName'] ) ? $in['customName'] : '', 60 ),
		'description' => gend_society_bk_text( isset( $in['description'] ) ? $in['description'] : '', 1200 ),
		'imageryNote' => gend_society_bk_text( isset( $in['imageryNote'] ) ? $in['imageryNote'] : '', 600 ),
		'do'          => gend_society_bk_list( isset( $in['do'] ) ? $in['do'] : array(), 12 ),
		'dont'        => gend_society_bk_list( isset( $in['dont'] ) ? $in['dont'] : array(), 12 ),
	);
	foreach ( $c['traits'] as $k => $t ) {
		$out[ $k ] = gend_society_bk_pick( isset( $in[ $k ] ) ? $in[ $k ] : '', $t['opts'], $defaults[ $k ] );
	}
	return $out;
}

/**
 * Clean Cast Members.
 *
 * @param array $in Raw list.
 * @return array
 */
function gend_society_bk_clean_cast( $in ) {
	$c   = gend_society_bk_choices();
	$out = array();
	foreach ( is_array( $in ) ? array_slice( array_values( $in ), 0, 40 ) : array() as $m ) {
		$m    = is_array( $m ) ? $m : array();
		$name = gend_society_bk_line( isset( $m['name'] ) ? $m['name'] : '', 80 );
		if ( '' === $name ) {
			continue;
		}
		$id    = isset( $m['id'] ) ? sanitize_key( $m['id'] ) : '';
		$chans = array();
		foreach ( isset( $m['channels'] ) && is_array( $m['channels'] ) ? $m['channels'] : array() as $ch ) {
			if ( isset( $c['channels'][ $ch ] ) ) {
				$chans[] = $ch;
			}
		}
		$out[] = array(
			'id'          => '' !== $id ? $id : 'cast_' . wp_generate_password( 8, false, false ),
			'name'        => $name,
			'kind'        => gend_society_bk_pick( isset( $m['kind'] ) ? $m['kind'] : '', $c['kinds'], 'character' ),
			'role'        => gend_society_bk_line( isset( $m['role'] ) ? $m['role'] : '', 120 ),
			'image'       => isset( $m['image'] ) ? esc_url_raw( trim( (string) $m['image'] ) ) : '',
			'personality' => gend_society_bk_text( isset( $m['personality'] ) ? $m['personality'] : '', 600 ),
			'look'        => gend_society_bk_text( isset( $m['look'] ) ? $m['look'] : '', 600 ),
			'voice'       => gend_society_bk_text( isset( $m['voice'] ) ? $m['voice'] : '', 600 ),
			'use'         => gend_society_bk_text( isset( $m['use'] ) ? $m['use'] : '', 600 ),
			'avoid'       => gend_society_bk_text( isset( $m['avoid'] ) ? $m['avoid'] : '', 600 ),
			'channels'    => array_values( array_unique( $chans ) ),
			'consent'     => ! empty( $m['consent'] ),
			'active'      => ! isset( $m['active'] ) || ! empty( $m['active'] ),
		);
	}
	return $out;
}

/* ── AI brief ───────────────────────────────────────────────────────────── */

/**
 * The kit as a plain-text brief for AI prompts. Empty when nothing is filled in.
 *
 * @param string[] $parts Any of voice, style, cast.
 * @return string
 */
function gend_society_brand_brief( $parts = array( 'voice', 'style', 'cast' ) ) {
	$kit   = gend_society_bk_get();
	$c     = gend_society_bk_choices();
	$name  = get_bloginfo( 'name' );
	$lines = array();

	if ( in_array( 'voice', $parts, true ) ) {
		$v   = $kit['voice'];
		$sub = array();
		if ( $v['essence'] ) {
			$sub[] = 'Brand essence: ' . $v['essence'];
		}
		if ( $v['audience'] ) {
			$sub[] = 'Audience: ' . $v['audience'];
		}
		foreach ( $v['traits'] as $t ) {
			$sub[] = 'Trait "' . $t['trait'] . '": ' . ( $t['is'] ? 'we are ' . $t['is'] : '' ) . ( $t['isnt'] ? '; we are not ' . $t['isnt'] : '' );
		}
		$tone = array();
		foreach ( $c['sliders'] as $k => $ends ) {
			$val = $v['sliders'][ $k ];
			if ( 3 !== $val ) {
				$tone[] = ( $val < 3 ? ( 1 === $val ? 'very ' : '' ) . strtolower( $ends[0] ) : ( 5 === $val ? 'very ' : '' ) . strtolower( $ends[1] ) );
			}
		}
		if ( $tone ) {
			$sub[] = 'Overall tone: ' . implode( ', ', $tone ) . '.';
		}
		foreach ( $c['contexts'] as $k => $label ) {
			if ( $v['tone'][ $k ] ) {
				$sub[] = 'Tone for ' . $label . ': ' . $v['tone'][ $k ];
			}
		}
		if ( $v['use'] ) {
			$sub[] = 'Preferred words: ' . implode( ', ', $v['use'] ) . '.';
		}
		if ( $v['avoid'] ) {
			$sub[] = 'Avoid these words: ' . implode( ', ', $v['avoid'] ) . '.';
		}
		if ( $v['never'] ) {
			$sub[] = 'Never say or claim: ' . implode( '; ', $v['never'] ) . '.';
		}
		if ( $sub ) {
			$rules = array();
			foreach ( $c['rules'] as $k => $rule ) {
				$rules[] = $rule['label'] . ': ' . wp_strip_all_tags( $rule['opts'][ $v['rules'][ $k ] ] );
			}
			$sub[] = 'Writing rules: ' . implode( '; ', $rules ) . '.';
			foreach ( $v['examples'] as $e ) {
				$sub[] = 'Example' . ( $e['context'] ? ' (' . $e['context'] . ')' : '' ) . ': write "' . $e['do'] . '"' . ( $e['dont'] ? ', not "' . $e['dont'] . '"' : '' ) . '.';
			}
			$lines[] = "VOICE & TONE\n- " . implode( "\n- ", $sub );
		}
	}

	if ( in_array( 'style', $parts, true ) && $kit['style']['preset'] ) {
		$s     = $kit['style'];
		$style = 'custom' === $s['preset'] ? ( $s['customName'] ? $s['customName'] : 'a custom style' ) : $c['styles'][ $s['preset'] ][0];
		$sub   = array( 'Visual style: ' . $style . ( $s['description'] ? ' - ' . $s['description'] : ( 'custom' !== $s['preset'] ? ' - ' . $c['styles'][ $s['preset'] ][1] : '' ) ) );
		$spec  = array();
		foreach ( $c['traits'] as $k => $t ) {
			$spec[] = $t['label'] . ': ' . strtolower( $t['opts'][ $s[ $k ] ] );
		}
		$sub[] = implode( '; ', $spec ) . '.';
		if ( $s['imageryNote'] ) {
			$sub[] = 'Imagery: ' . $s['imageryNote'];
		}
		if ( $s['do'] ) {
			$sub[] = 'Do: ' . implode( '; ', $s['do'] ) . '.';
		}
		if ( $s['dont'] ) {
			$sub[] = "Don't: " . implode( '; ', $s['dont'] ) . '.';
		}
		$lines[] = "APP STYLING\n- " . implode( "\n- ", $sub );
	}

	if ( in_array( 'cast', $parts, true ) ) {
		$sub = array();
		foreach ( $kit['cast'] as $m ) {
			// Real members only with their consent on file.
			if ( ! $m['active'] || ( 'member' === $m['kind'] && ! $m['consent'] ) ) {
				continue;
			}
			$bits = array( $m['name'] . ' (' . strtolower( $c['kinds'][ $m['kind'] ] ) . ( $m['role'] ? ', ' . $m['role'] : '' ) . ')' );
			foreach ( array(
				'personality' => 'personality',
				'look'        => 'looks',
				'voice'       => 'speaks',
				'use'         => 'use when',
				'avoid'       => 'avoid when',
			) as $k => $label ) {
				if ( $m[ $k ] ) {
					$bits[] = $label . ': ' . $m[ $k ];
				}
			}
			if ( $m['channels'] ) {
				$bits[] = 'channels: ' . implode( ', ', array_map(
					function ( $ch ) use ( $c ) {
						return strtolower( $c['channels'][ $ch ] );
					},
					$m['channels']
				) );
			}
			$sub[] = implode( '; ', $bits );
		}
		if ( $sub ) {
			$lines[] = "CAST MEMBERS (use only these recurring characters and people, as described)\n- " . implode( "\n- ", $sub );
		}
	}

	if ( ! $lines ) {
		return '';
	}
	return '=== ' . strtoupper( $name ) . " BRAND GUIDELINES (follow these in everything you write or design) ===\n" . implode( "\n\n", $lines ) . "\n=== END BRAND GUIDELINES ===";
}

add_filter( 'aipa_system_context', 'gend_society_bk_ai_context', 15, 2 );
/**
 * Adds the brand brief to AI chats made on this site. Requests relayed from
 * another install (they carry X-Gend-Source-Blog) are left alone: their brand
 * lives on their own site.
 *
 * @param mixed $system System prompt (other callers pass an array; left as is).
 * @param array $ctx    Request context.
 * @return mixed
 */
function gend_society_bk_ai_context( $system, $ctx = array() ) {
	if ( ! is_string( $system ) || isset( $_SERVER['HTTP_X_GEND_SOURCE_BLOG'] ) ) {
		return $system;
	}
	$wf = is_array( $ctx ) && isset( $ctx['workflow'] ) ? (string) $ctx['workflow'] : '';
	if ( 'code' === $wf ) {
		return $system;
	}
	// Image and video work needs the look and the cast, not the copy rules.
	$brief = gend_society_brand_brief( in_array( $wf, array( 'image', 'video' ), true ) ? array( 'style', 'cast' ) : array( 'voice', 'style', 'cast' ) );
	return '' === $brief ? $system : $system . "\n\n" . $brief . "\n";
}

/* ── REST ───────────────────────────────────────────────────────────────── */

add_action( 'rest_api_init', 'gend_society_bk_routes' );
/**
 * Routes: the kit (design editors), and the brief (anyone who writes content).
 */
function gend_society_bk_routes() {
	$design = function () {
		return current_user_can( 'edit_theme_options' );
	};
	register_rest_route(
		'gend-society/v1',
		'/brand-kit',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => function () {
					return rest_ensure_response(
						array(
							'kit'     => gend_society_bk_get(),
							'choices' => gend_society_bk_choices(),
							'accents' => gend_society_bk_accents(),
						)
					);
				},
				'permission_callback' => $design,
			),
			array(
				'methods'             => 'POST',
				'callback'            => 'gend_society_bk_save',
				'permission_callback' => $design,
			),
		)
	);
	register_rest_route(
		'gend-society/v1',
		'/brand-kit/brief',
		array(
			'methods'             => 'GET',
			'callback'            => function ( WP_REST_Request $req ) {
				$parts = array_intersect( array( 'voice', 'style', 'cast' ), array_map( 'sanitize_key', explode( ',', (string) ( $req['parts'] ? $req['parts'] : 'voice,style,cast' ) ) ) );
				return rest_ensure_response( array( 'brief' => gend_society_brand_brief( array_values( $parts ) ) ) );
			},
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		)
	);
}

/**
 * Saves one part of the kit.
 *
 * @param WP_REST_Request $req Request: { part: voice|style|cast, data }.
 * @return WP_REST_Response|WP_Error
 */
function gend_society_bk_save( WP_REST_Request $req ) {
	$body = (array) $req->get_json_params();
	$part = isset( $body['part'] ) ? (string) $body['part'] : '';
	if ( ! in_array( $part, array( 'voice', 'style', 'cast' ), true ) ) {
		return new WP_Error( 'gs_bk_part', __( 'Unknown part of the brand kit.', 'gend-society' ), array( 'status' => 400 ) );
	}
	$data          = isset( $body['data'] ) ? $body['data'] : array();
	$kit           = gend_society_bk_get();
	$fn            = 'gend_society_bk_clean_' . $part;
	$kit[ $part ]  = $fn( $data );
	update_option( GEND_SOCIETY_BRAND_KIT, $kit, false );
	return rest_ensure_response( array( 'kit' => $kit ) );
}

/* ── Brand Guidelines chapters ──────────────────────────────────────────── */

/**
 * The guide's chapters from the kit (for the style guide page in design-studio.php).
 *
 * @return array Id => array( label, html ).
 */
function gend_society_bk_guide_chapters() {
	$kit = gend_society_bk_get();
	$c   = gend_society_bk_choices();
	$e   = 'esc_html';
	$out = array();

	// Style.
	$s     = $kit['style'];
	$html  = '';
	if ( $s['preset'] ) {
		$name = 'custom' === $s['preset'] ? ( $s['customName'] ? $s['customName'] : __( 'Our own style', 'gend-society' ) ) : $c['styles'][ $s['preset'] ][0];
		$desc = $s['description'] ? $s['description'] : ( 'custom' !== $s['preset'] ? $c['styles'][ $s['preset'] ][1] : '' );
		$html .= '<div class="gsg-bk-hero"><div class="gsg-label">' . $e( __( 'Style family', 'gend-society' ) ) . '</div><div class="gsg-bk-big">' . $e( $name ) . '</div>' . ( $desc ? '<p class="gsg-bk-lede">' . $e( $desc ) . '</p>' : '' ) . '</div>';
		$cls   = 'gsg-bk-demo is-' . sanitize_html_class( $s['preset'] ) . ' c-' . sanitize_html_class( $s['corners'] ) . ' d-' . sanitize_html_class( $s['depth'] ) . ' b-' . sanitize_html_class( $s['buttons'] ) . ' f-' . sanitize_html_class( $s['forms'] ) . ' k-' . sanitize_html_class( $s['cards'] );
		$acc   = gend_society_bk_accents();
		$html .= '<div class="' . esc_attr( $cls ) . '" style="--bk-accent:' . esc_attr( $acc[0] ) . ';--bk-accent-2:' . esc_attr( $acc[1] ) . '"><div class="gsg-bk-demo-stage">'
			. '<div class="gsg-bk-card"><div class="gsg-bk-card-k">' . $e( __( 'Card', 'gend-society' ) ) . '</div><div class="gsg-bk-card-t">' . $e( __( 'Launch your web app', 'gend-society' ) ) . '</div><div class="gsg-bk-card-p">' . $e( __( 'Everything you need, in one place.', 'gend-society' ) ) . '</div>'
			. '<label class="gsg-bk-field"><span>' . $e( __( 'Email', 'gend-society' ) ) . '</span><i>' . $e( __( 'you@example.com', 'gend-society' ) ) . '</i></label>'
			. '<div class="gsg-bk-btns"><span class="gsg-bk-btn">' . $e( __( 'Get started', 'gend-society' ) ) . '</span><span class="gsg-bk-btn is-2">' . $e( __( 'Learn more', 'gend-society' ) ) . '</span></div></div>'
			. '</div></div>';
		$html .= '<div class="gsg-bk-specs">';
		foreach ( $c['traits'] as $k => $t ) {
			$html .= '<div class="gsg-bk-spec"><span>' . $e( $t['label'] ) . '</span><b>' . $e( $t['opts'][ $s[ $k ] ] ) . '</b></div>';
		}
		$html .= '</div>';
		if ( $s['imageryNote'] ) {
			$html .= '<div class="gsg-bk-note"><div class="gsg-label">' . $e( __( 'Imagery', 'gend-society' ) ) . '</div><p>' . nl2br( $e( $s['imageryNote'] ) ) . '</p></div>';
		}
		if ( $s['do'] || $s['dont'] ) {
			$html .= gend_society_bk_dodont( $s['do'], $s['dont'] );
		}
	}
	$out['style'] = array( __( 'App styling', 'gend-society' ), __( 'The visual language of every screen, asset and campaign: the family it belongs to and the rules that keep it consistent.', 'gend-society' ), $html );

	// Voice.
	$v    = $kit['voice'];
	$html = '';
	if ( $v['essence'] ) {
		$html .= '<blockquote class="gsg-bk-essence">' . $e( $v['essence'] ) . '</blockquote>';
	}
	if ( $v['audience'] ) {
		$html .= '<div class="gsg-bk-note"><div class="gsg-label">' . $e( __( 'Who we talk to', 'gend-society' ) ) . '</div><p>' . nl2br( $e( $v['audience'] ) ) . '</p></div>';
	}
	if ( $v['traits'] ) {
		$html .= '<div class="gsg-bk-traits">';
		foreach ( $v['traits'] as $i => $t ) {
			$html .= '<div class="gsg-bk-trait"><span class="gsg-num">' . $e( sprintf( '%02d', $i + 1 ) ) . '</span><div class="gsg-bk-trait-t">' . $e( $t['trait'] ) . '</div>'
				. ( $t['is'] ? '<p><b>' . $e( __( 'We are', 'gend-society' ) ) . '</b>' . $e( $t['is'] ) . '</p>' : '' )
				. ( $t['isnt'] ? '<p class="is-not"><b>' . $e( __( 'We are not', 'gend-society' ) ) . '</b>' . $e( $t['isnt'] ) . '</p>' : '' ) . '</div>';
		}
		$html .= '</div>';
	}
	$html .= '<div class="gsg-bk-sliders">';
	foreach ( $c['sliders'] as $k => $ends ) {
		$html .= '<div class="gsg-bk-slider"><span>' . $e( $ends[0] ) . '</span><div class="gsg-bk-track">' . str_repeat( '<i></i>', 5 ) . '<b style="left:' . ( ( $v['sliders'][ $k ] - 1 ) * 25 ) . '%"></b></div><span>' . $e( $ends[1] ) . '</span></div>';
	}
	$html .= '</div>';
	$tone = array_filter( $v['tone'] );
	if ( $tone ) {
		$html .= '<div class="gsg-bk-tone">';
		foreach ( $tone as $k => $t ) {
			$html .= '<div class="gsg-bk-tone-row"><div class="gsg-label">' . $e( $c['contexts'][ $k ] ) . '</div><p>' . $e( $t ) . '</p></div>';
		}
		$html .= '</div>';
	}
	if ( $v['use'] || $v['avoid'] || $v['never'] ) {
		$html .= '<div class="gsg-bk-words">';
		foreach ( array(
			'use'   => __( 'Words we use', 'gend-society' ),
			'avoid' => __( 'Words we avoid', 'gend-society' ),
			'never' => __( 'Never say or claim', 'gend-society' ),
		) as $k => $label ) {
			if ( $v[ $k ] ) {
				$html .= '<div class="gsg-bk-wordset is-' . esc_attr( $k ) . '"><div class="gsg-label">' . $e( $label ) . '</div><div class="gsg-bk-chips">';
				foreach ( $v[ $k ] as $w ) {
					$html .= '<span>' . $e( $w ) . '</span>';
				}
				$html .= '</div></div>';
			}
		}
		$html .= '</div>';
	}
	$html .= '<div class="gsg-bk-specs">';
	foreach ( $c['rules'] as $k => $rule ) {
		$html .= '<div class="gsg-bk-spec"><span>' . $e( $rule['label'] ) . '</span><b>' . $e( $rule['opts'][ $v['rules'][ $k ] ] ) . '</b></div>';
	}
	$html .= '</div>';
	foreach ( $v['examples'] as $ex ) {
		$html .= '<div class="gsg-bk-example">' . ( $ex['context'] ? '<div class="gsg-label">' . $e( $ex['context'] ) . '</div>' : '' ) . gend_society_bk_dodont( $ex['do'] ? array( $ex['do'] ) : array(), $ex['dont'] ? array( $ex['dont'] ) : array() ) . '</div>';
	}
	$filled = $v['essence'] || $v['audience'] || $v['traits'] || $tone || $v['use'] || $v['avoid'] || $v['examples'];
	$out['voice'] = array( __( 'Voice & tone', 'gend-society' ), __( 'One voice everywhere, tuned to the moment. These rules guide every headline, button, email and reply, written by people or by AI.', 'gend-society' ), $filled ? $html : '' );

	// Cast.
	$html = '';
	$cast = array_filter(
		$kit['cast'],
		function ( $m ) {
			return $m['active'];
		}
	);
	if ( $cast ) {
		$html .= '<div class="gsg-bk-cast">';
		foreach ( $cast as $m ) {
			$html .= '<article class="gsg-bk-person">'
				. '<div class="gsg-bk-portrait">' . ( $m['image'] ? '<img src="' . esc_url( $m['image'] ) . '" alt="">' : '<span>' . $e( mb_substr( $m['name'], 0, 1 ) ) . '</span>' ) . '</div>'
				. '<div class="gsg-bk-person-body"><div class="gsg-label">' . $e( $c['kinds'][ $m['kind'] ] ) . ( 'member' === $m['kind'] && ! $m['consent'] ? ' · ' . $e( __( 'consent pending', 'gend-society' ) ) : '' ) . '</div>'
				. '<div class="gsg-bk-person-name">' . $e( $m['name'] ) . '</div>' . ( $m['role'] ? '<div class="gsg-bk-person-role">' . $e( $m['role'] ) . '</div>' : '' );
			foreach ( array(
				'personality' => __( 'Personality', 'gend-society' ),
				'look'        => __( 'Look', 'gend-society' ),
				'voice'       => __( 'Voice', 'gend-society' ),
				'use'         => __( 'Use when', 'gend-society' ),
				'avoid'       => __( 'Avoid when', 'gend-society' ),
			) as $k => $label ) {
				if ( $m[ $k ] ) {
					$html .= '<p><b>' . $e( $label ) . '</b>' . $e( $m[ $k ] ) . '</p>';
				}
			}
			if ( $m['channels'] ) {
				$html .= '<div class="gsg-bk-chips">';
				foreach ( $m['channels'] as $ch ) {
					$html .= '<span>' . $e( $c['channels'][ $ch ] ) . '</span>';
				}
				$html .= '</div>';
			}
			$html .= '</div></article>';
		}
		$html .= '</div>';
	}
	$out['cast'] = array( __( 'Cast', 'gend-society' ), __( 'The recurring faces of the brand: who they are, how they look and sound, and when each one appears.', 'gend-society' ), $html );
	return array(
		'voice' => $out['voice'],
		'style' => $out['style'],
		'cast'  => $out['cast'],
	);
}

/**
 * Do / don't pair.
 *
 * @param string[] $do   Do items.
 * @param string[] $dont Don't items.
 * @return string
 */
function gend_society_bk_dodont( $do, $dont ) {
	$col = function ( $items, $cls, $label ) {
		if ( ! $items ) {
			return '';
		}
		$h = '<div class="gsg-bk-dd ' . $cls . '"><div class="gsg-label">' . esc_html( $label ) . '</div><ul>';
		foreach ( $items as $i ) {
			$h .= '<li>' . esc_html( $i ) . '</li>';
		}
		return $h . '</ul></div>';
	};
	return '<div class="gsg-bk-dodont">' . $col( $do, 'is-do', __( 'Do', 'gend-society' ) ) . $col( $dont, 'is-dont', __( 'Don’t', 'gend-society' ) ) . '</div>';
}
