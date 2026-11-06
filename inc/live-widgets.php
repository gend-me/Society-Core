<?php
/**
 * Live dashboard widgets for web apps: [gs_live type="..."] shows the site's own numbers from its store, projects,
 * proposals and community, in the style guide's colours, animated as each comes into view. Team widgets show only to
 * the site's team; my_orders and my_projects show the viewer's own. A widget whose plugin isn't on shows nothing, so a
 * dashboard grows by itself when a feature is added. The team's numbers are cached for five minutes.
 *
 * @package gend-society
 */

defined( 'ABSPATH' ) || exit;

/** Every widget, with what it shows: the chat reads this to build dashboards. */
const GEND_SOCIETY_LIVE_TYPES = array(
	'sales'        => 'Revenue, orders, customers and average order, with a revenue chart (team)',
	'top_products' => 'Best-selling products (team)',
	'projects'     => 'Each delivery project with its task progress and milestones (team)',
	'tasks'        => 'Open tasks, soonest due first, with overdue ones marked (team)',
	'pipeline'     => 'Proposals by stage: draft, sent, viewed, agreed (team)',
	'community'    => 'Members, new members, activity and groups, once the community is on (team)',
	'my_orders'    => "The viewer's own recent orders (customers)",
	'my_projects'  => "The viewer's own deliveries and where each one is (customers)",
);

add_shortcode( 'gs_live', 'gend_society_live_shortcode' );
function gend_society_live_shortcode( $atts ) {
	$a    = shortcode_atts( array( 'type' => '', 'days' => 30, 'title' => '' ), $atts, 'gs_live' );
	$type = sanitize_key( (string) $a['type'] );
	if ( ! isset( GEND_SOCIETY_LIVE_TYPES[ $type ] ) ) {
		return '';
	}
	$mine = 0 === strpos( $type, 'my_' );
	if ( $mine ? ! is_user_logged_in() : ! gend_society_live_is_team() ) {
		return '';
	}
	$body = call_user_func( 'gend_society_live_' . $type, max( 7, min( 365, (int) $a['days'] ) ) );
	if ( '' === $body ) {
		return '';
	}
	gend_society_live_assets();
	$title = trim( sanitize_text_field( (string) $a['title'] ) );
	return '<div class="gs-live gs-live--' . esc_attr( str_replace( '_', '-', $type ) ) . '">'
		. ( '' !== $title ? '<h3 class="gs-live__title">' . esc_html( $title ) . '</h3>' : '' ) . $body . '</div>';
}

/** The site's team: whoever runs its orders or its content. */
function gend_society_live_is_team() {
	return current_user_can( 'edit_shop_orders' ) || current_user_can( 'edit_others_posts' );
}

function gend_society_live_assets() {
	foreach ( array( 'css', 'js' ) as $ext ) {
		$file = 'assets/live-widgets.' . $ext;
		$ver  = GEND_SOCIETY_VERSION . '.' . (int) @filemtime( GEND_SOCIETY_DIR . $file );
		if ( 'css' === $ext ) {
			wp_enqueue_style( 'gend-society-live', GEND_SOCIETY_URL . $file, array(), $ver );
		} else {
			wp_enqueue_script( 'gend-society-live', GEND_SOCIETY_URL . $file, array(), $ver, true );
		}
	}
}

/** The team's numbers, five minutes at a time. */
function gend_society_live_cached( $key, callable $make ) {
	$k   = 'gs_live_' . md5( get_current_blog_id() . ':' . $key );
	$got = get_transient( $k );
	if ( is_array( $got ) ) {
		return $got;
	}
	$got = (array) $make();
	set_transient( $k, $got, 5 * MINUTE_IN_SECONDS );
	return $got;
}

/** A number that counts up into place. The finished number is the text, so it reads right without the script. */
function gend_society_live_num( $value, $decimals = 0, $prefix = '' ) {
	$text = $prefix . number_format_i18n( (float) $value, $decimals );
	return '<b class="gs-live__num" data-count="' . esc_attr( (string) round( (float) $value, $decimals ) ) . '" data-decimals="' . (int) $decimals . '" data-prefix="' . esc_attr( $prefix ) . '">' . esc_html( $text ) . '</b>';
}

function gend_society_live_tiles( array $tiles ) {
	$out = '<div class="gs-live__tiles">';
	foreach ( $tiles as $i => $t ) {
		$out .= '<div class="gs-live__tile" style="--i:' . (int) $i . '"><span>' . esc_html( $t[0] ) . '</span>' . $t[1] . '</div>';
	}
	return $out . '</div>';
}

/** A labelled bar. null for under way with no measure yet: it moves instead of claiming a share. */
function gend_society_live_bar( $label, $pct, $note, $i ) {
	$bar = null === $pct ? '<div class="gs-live__bar is-moving"><i></i></div>'
		: '<div class="gs-live__bar"><i style="--w:' . esc_attr( (string) round( max( 0, min( 100, (float) $pct ) ), 1 ) ) . '%"></i></div>';
	return '<li class="gs-live__row" style="--i:' . (int) $i . '"><div class="gs-live__row-head"><b>' . esc_html( $label ) . '</b><span>' . esc_html( $note ) . '</span></div>' . $bar . '</li>';
}

function gend_society_live_empty( $text ) {
	return '<p class="gs-live__empty">' . esc_html( $text ) . '</p>';
}

// ---- Store ----

/** Paid orders in the period. */
function gend_society_live_orders( $days ) {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return null;
	}
	return wc_get_orders(
		array(
			'status'       => array_map( static fn( $s ) => 'wc-' . $s, wc_get_is_paid_statuses() ),
			'type'         => 'shop_order',
			'date_created' => '>' . ( time() - $days * DAY_IN_SECONDS ),
			'limit'        => 1000,
		)
	);
}

function gend_society_live_sales( $days ) {
	$data = gend_society_live_cached(
		'sales:' . $days,
		static function () use ( $days ) {
			$orders = gend_society_live_orders( $days );
			if ( null === $orders ) {
				return array( 'off' => 1 );
			}
			$by_day = array();
			for ( $d = $days - 1; $d >= 0; $d-- ) {
				$by_day[ wp_date( 'Y-m-d', time() - $d * DAY_IN_SECONDS ) ] = 0.0;
			}
			$revenue = 0.0;
			$people  = array();
			foreach ( $orders as $o ) {
				$total    = (float) $o->get_total() - (float) $o->get_total_refunded();
				$revenue += $total;
				$day      = $o->get_date_created() ? $o->get_date_created()->date_i18n( 'Y-m-d' ) : '';
				if ( isset( $by_day[ $day ] ) ) {
					$by_day[ $day ] += $total;
				}
				$people[ $o->get_customer_id() ? 'u' . $o->get_customer_id() : 'e' . strtolower( (string) $o->get_billing_email() ) ] = 1;
			}
			$count = count( $orders );
			return array( 'revenue' => $revenue, 'orders' => $count, 'customers' => count( $people ), 'avg' => $count ? $revenue / $count : 0, 'days' => array_values( $by_day ) );
		}
	);
	if ( ! empty( $data['off'] ) ) {
		return '';
	}
	$cur = function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) : '';
	/* translators: %d: number of days */
	$span = sprintf( __( 'Last %d days', 'gend-society' ), $days );
	return gend_society_live_tiles(
		array(
			array( __( 'Revenue', 'gend-society' ), gend_society_live_num( $data['revenue'], 2, $cur ) ),
			array( __( 'Orders', 'gend-society' ), gend_society_live_num( $data['orders'] ) ),
			array( __( 'Customers', 'gend-society' ), gend_society_live_num( $data['customers'] ) ),
			array( __( 'Average order', 'gend-society' ), gend_society_live_num( $data['avg'], 2, $cur ) ),
		)
	) . gend_society_live_chart( (array) $data['days'], $span ) . ( $data['orders'] ? '' : gend_society_live_empty( __( 'Your first sale will show here as soon as it comes in.', 'gend-society' ) ) );
}

/** The days as an area that draws itself in. */
function gend_society_live_chart( array $values, $label ) {
	$n = count( $values );
	if ( $n < 2 ) {
		return '';
	}
	$w   = 600;
	$h   = 150;
	$max = max( 1, max( $values ) );
	$pts = array();
	foreach ( array_values( $values ) as $i => $v ) {
		$pts[] = round( $i * $w / ( $n - 1 ), 1 ) . ',' . round( $h - 8 - ( (float) $v / $max ) * ( $h - 24 ), 1 );
	}
	$line = 'M' . implode( ' L', $pts );
	return '<figure class="gs-live__chart"><svg viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" role="img" aria-label="' . esc_attr( $label ) . '">'
		. '<path class="gs-live__area" d="' . esc_attr( $line . ' L' . $w . ',' . $h . ' L0,' . $h . ' Z' ) . '"/>'
		. '<path class="gs-live__line" pathLength="1" d="' . esc_attr( $line ) . '"/></svg><figcaption>' . esc_html( $label ) . '</figcaption></figure>';
}

function gend_society_live_top_products( $days ) {
	$data = gend_society_live_cached(
		'top:' . $days,
		static function () use ( $days ) {
			$orders = gend_society_live_orders( $days );
			if ( null === $orders ) {
				return array( 'off' => 1 );
			}
			$qty = array();
			foreach ( $orders as $o ) {
				foreach ( $o->get_items() as $item ) {
					$name         = (string) $item->get_name();
					$qty[ $name ] = ( $qty[ $name ] ?? 0 ) + (int) $item->get_quantity();
				}
			}
			arsort( $qty );
			return array( 'rows' => array_slice( $qty, 0, 5, true ) );
		}
	);
	if ( ! empty( $data['off'] ) ) {
		return '';
	}
	if ( ! $data['rows'] ) {
		return gend_society_live_empty( __( 'Your best sellers will show here once orders come in.', 'gend-society' ) );
	}
	$top = max( $data['rows'] );
	$out = '<ul class="gs-live__rows">';
	$i   = 0;
	foreach ( $data['rows'] as $name => $q ) {
		/* translators: %s: number sold */
		$out .= gend_society_live_bar( (string) $name, $q / max( 1, $top ) * 100, sprintf( _n( '%s sold', '%s sold', $q, 'gend-society' ), number_format_i18n( $q ) ), $i++ );
	}
	return $out . '</ul>';
}

// ---- Projects ----

/** This site's Projects tables, or '' when Projects isn't set up here. */
function gend_society_live_pm() {
	global $wpdb;
	$t = $wpdb->prefix . 'pm_projects';
	return $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ? $wpdb->prefix . 'pm_' : '';
}

function gend_society_live_projects( $days ) {
	$data = gend_society_live_cached(
		'projects',
		static function () {
			global $wpdb;
			$pm = gend_society_live_pm();
			if ( '' === $pm ) {
				return array( 'off' => 1 );
			}
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb->prefix only
			$rows = $wpdb->get_results(
				"SELECT p.id, p.title,
					(SELECT COUNT(*) FROM {$pm}tasks t WHERE t.project_id = p.id) AS tasks,
					(SELECT COUNT(*) FROM {$pm}tasks t WHERE t.project_id = p.id AND t.status = 1) AS done,
					(SELECT COUNT(*) FROM {$pm}boards b WHERE b.project_id = p.id AND b.type = 'milestone') AS milestones
				FROM {$pm}projects p WHERE p.status <> 3 ORDER BY p.updated_at DESC LIMIT 8",
				ARRAY_A
			);
			// phpcs:enable
			return array( 'rows' => (array) $rows );
		}
	);
	if ( ! empty( $data['off'] ) ) {
		return '';
	}
	if ( ! $data['rows'] ) {
		return gend_society_live_empty( __( 'Your delivery projects will show here.', 'gend-society' ) );
	}
	$out = '<ul class="gs-live__rows">';
	foreach ( $data['rows'] as $i => $r ) {
		$tasks = (int) $r['tasks'];
		$done  = (int) $r['done'];
		/* translators: 1: tasks done, 2: all tasks, 3: milestones */
		$note = sprintf( __( '%1$d of %2$d tasks · %3$d milestones', 'gend-society' ), $done, $tasks, (int) $r['milestones'] );
		$out .= gend_society_live_bar( (string) $r['title'], $tasks ? $done / $tasks * 100 : 0, $note, $i );
	}
	return $out . '</ul>';
}

function gend_society_live_tasks( $days ) {
	$data = gend_society_live_cached(
		'tasks',
		static function () {
			global $wpdb;
			$pm = gend_society_live_pm();
			if ( '' === $pm ) {
				return array( 'off' => 1 );
			}
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb->prefix only
			$rows = $wpdb->get_results(
				"SELECT t.title, t.due_date, p.title AS project FROM {$pm}tasks t LEFT JOIN {$pm}projects p ON p.id = t.project_id
				WHERE t.status = 0 ORDER BY (t.due_date IS NULL), t.due_date ASC, t.created_at DESC LIMIT 8",
				ARRAY_A
			);
			$open = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pm}tasks WHERE status = 0" );
			$late = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$pm}tasks WHERE status = 0 AND due_date IS NOT NULL AND due_date < %s", current_time( 'mysql' ) ) );
			$done = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$pm}tasks WHERE status = 1 AND completed_at >= %s", wp_date( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
			// phpcs:enable
			return array( 'rows' => (array) $rows, 'open' => $open, 'late' => $late, 'done' => $done );
		}
	);
	if ( ! empty( $data['off'] ) ) {
		return '';
	}
	$out = gend_society_live_tiles(
		array(
			array( __( 'Open', 'gend-society' ), gend_society_live_num( $data['open'] ) ),
			array( __( 'Overdue', 'gend-society' ), gend_society_live_num( $data['late'] ) ),
			array( __( 'Done in 30 days', 'gend-society' ), gend_society_live_num( $data['done'] ) ),
		)
	);
	if ( ! $data['rows'] ) {
		return $out . gend_society_live_empty( __( 'Nothing is waiting. New orders add their delivery tasks here.', 'gend-society' ) );
	}
	$out .= '<ul class="gs-live__list">';
	$now  = current_time( 'timestamp' );
	foreach ( $data['rows'] as $i => $r ) {
		$due  = $r['due_date'] ? strtotime( (string) $r['due_date'] ) : 0;
		$late = $due && $due < $now;
		$out .= '<li style="--i:' . (int) $i . '"' . ( $late ? ' class="is-late"' : '' ) . '><b>' . esc_html( (string) $r['title'] ) . '</b><span>' . esc_html( (string) ( $r['project'] ?? '' ) ) . '</span>'
			. '<em>' . esc_html( $due ? ( $late ? __( 'Overdue', 'gend-society' ) . ' · ' : '' ) . wp_date( get_option( 'date_format' ), $due ) : __( 'No due date', 'gend-society' ) ) . '</em></li>';
	}
	return $out . '</ul>';
}

// ---- Proposals ----

function gend_society_live_pipeline( $days ) {
	$data = gend_society_live_cached(
		'pipeline',
		static function () {
			if ( ! post_type_exists( 'st_proposal' ) ) {
				return array( 'off' => 1 );
			}
			$stages = array( 'draft' => 0, 'sent' => 0, 'viewed' => 0, 'agreed' => 0 );
			$ids    = get_posts( array( 'post_type' => 'st_proposal', 'post_status' => array( 'publish', 'draft', 'pending', 'private' ), 'numberposts' => 500, 'fields' => 'ids' ) );
			foreach ( $ids as $id ) {
				if ( 'template' === get_post_meta( $id, '_stp_subtab', true ) ) {
					continue;
				}
				$s             = (string) get_post_meta( $id, '_stp_assigned_stage', true );
				$s             = isset( $stages[ $s ] ) ? $s : 'draft';
				$stages[ $s ] += 1;
			}
			return array( 'stages' => $stages );
		}
	);
	if ( ! empty( $data['off'] ) ) {
		return '';
	}
	$labels = array( 'draft' => __( 'Drafts', 'gend-society' ), 'sent' => __( 'Sent', 'gend-society' ), 'viewed' => __( 'Viewed', 'gend-society' ), 'agreed' => __( 'Agreed', 'gend-society' ) );
	$top    = max( 1, max( $data['stages'] ) );
	$out    = '<ul class="gs-live__rows gs-live__funnel">';
	$i      = 0;
	foreach ( $data['stages'] as $s => $n ) {
		$out .= gend_society_live_bar( $labels[ $s ], $n / $top * 100, number_format_i18n( $n ), $i++ );
	}
	$out .= '</ul>';
	return array_sum( $data['stages'] ) ? $out : $out . gend_society_live_empty( __( 'Proposals you send will move along here as clients view and agree to them.', 'gend-society' ) );
}

// ---- Community ----

function gend_society_live_community( $days ) {
	if ( ! function_exists( 'bp_is_active' ) || ! bp_is_active( 'members' ) ) {
		return '';
	}
	$data = gend_society_live_cached(
		'community:' . $days,
		static function () use ( $days ) {
			global $wpdb;
			$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
			$q     = new WP_User_Query( array( 'blog_id' => get_current_blog_id(), 'number' => 1, 'fields' => 'ID', 'count_total' => true ) );
			$new   = new WP_User_Query( array( 'blog_id' => get_current_blog_id(), 'number' => 1, 'fields' => 'ID', 'count_total' => true, 'date_query' => array( array( 'after' => $since, 'column' => 'user_registered' ) ) ) );
			$out   = array( 'members' => (int) $q->get_total(), 'new' => (int) $new->get_total(), 'activity' => -1, 'groups' => -1 );
			if ( bp_is_active( 'activity' ) ) {
				$t               = buddypress()->activity->table_name;
				$out['activity'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE date_recorded >= %s AND is_spam = 0", $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- BuddyPress table name
			}
			// Groups are shared across the network: count this site's own.
			if ( function_exists( 'gend_society_ch_group_count' ) ) {
				$out['groups'] = gend_society_ch_group_count();
			}
			return $out;
		}
	);
	$tiles = array(
		array( __( 'Members', 'gend-society' ), gend_society_live_num( $data['members'] ) ),
		/* translators: %d: number of days */
		array( sprintf( __( 'New in %d days', 'gend-society' ), $days ), gend_society_live_num( $data['new'] ) ),
	);
	if ( $data['activity'] >= 0 ) {
		$tiles[] = array( __( 'Posts and updates', 'gend-society' ), gend_society_live_num( $data['activity'] ) );
	}
	if ( $data['groups'] >= 0 ) {
		$tiles[] = array( __( 'Groups', 'gend-society' ), gend_society_live_num( $data['groups'] ) );
	}
	return gend_society_live_tiles( $tiles );
}

// ---- The viewer's own ----

function gend_society_live_my_orders( $days ) {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return '';
	}
	$orders = wc_get_orders( array( 'customer_id' => get_current_user_id(), 'limit' => 6, 'type' => 'shop_order' ) );
	if ( ! $orders ) {
		return gend_society_live_empty( __( 'Your orders will show here.', 'gend-society' ) );
	}
	$out = '<ul class="gs-live__list">';
	foreach ( $orders as $i => $o ) {
		/* translators: %s: order number */
		$out .= '<li style="--i:' . (int) $i . '"><b><a href="' . esc_url( $o->get_view_order_url() ) . '">' . esc_html( sprintf( __( 'Order #%s', 'gend-society' ), $o->get_order_number() ) ) . '</a></b>'
			. '<span>' . esc_html( $o->get_date_created() ? $o->get_date_created()->date_i18n( get_option( 'date_format' ) ) : '' ) . '</span>'
			. '<em class="gs-live__chip gs-live__chip--' . esc_attr( $o->get_status() ) . '">' . esc_html( wc_get_order_status_name( $o->get_status() ) ) . '</em>'
			. '<strong>' . wp_kses_post( $o->get_formatted_order_total() ) . '</strong></li>';
	}
	return $out . '</ul>';
}

/** What the viewer bought that's delivered as a project, and where each delivery is. */
function gend_society_live_my_projects( $days ) {
	global $wpdb;
	$pm = gend_society_live_pm();
	if ( '' === $pm || ! function_exists( 'wc_get_orders' ) ) {
		return '';
	}
	$tasks = array();
	foreach ( wc_get_orders( array( 'customer_id' => get_current_user_id(), 'limit' => 20, 'type' => 'shop_order' ) ) as $o ) {
		foreach ( $o->get_items() as $item ) {
			$tid = (int) $item->get_meta( '_psoo_pm_task_id' );
			if ( $tid ) {
				$tasks[ $tid ] = array( 'product' => (string) $item->get_name(), 'date' => $o->get_date_created() ? $o->get_date_created()->date_i18n( get_option( 'date_format' ) ) : '' );
			}
		}
	}
	if ( ! $tasks ) {
		return gend_society_live_empty( __( 'When you buy a service, its delivery shows here with every step.', 'gend-society' ) );
	}
	$ids = implode( ',', array_map( 'intval', array_keys( $tasks ) ) );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb->prefix, ids cast to int
	$rows = $wpdb->get_results( "SELECT t.id, t.status, t.project_id, (SELECT COUNT(*) FROM {$pm}boards b WHERE b.project_id = t.project_id AND b.type = 'milestone') AS milestones FROM {$pm}tasks t WHERE t.id IN ($ids)", OBJECT_K );
	$out  = '<ul class="gs-live__rows">';
	$i    = 0;
	foreach ( $tasks as $tid => $t ) {
		$done = isset( $rows[ $tid ] ) && 1 === (int) $rows[ $tid ]->status;
		/* translators: %s: date bought */
		$note = ( $done ? __( 'Delivered', 'gend-society' ) : __( 'In progress', 'gend-society' ) ) . ' · ' . sprintf( __( 'Bought %s', 'gend-society' ), $t['date'] );
		$out .= gend_society_live_bar( $t['product'], $done ? 100 : null, $note, $i++ );
	}
	return $out . '</ul>';
}
