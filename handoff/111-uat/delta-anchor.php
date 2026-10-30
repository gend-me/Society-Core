<?php
/**
 * Phase 111-05 battery: the watermark is read at job START and a delta export carries what changed since (MIG-13, part 1).
 *
 *   wp eval-file delta-anchor.php <section>[,<section>...]        (C:/tmp/livepull/phase111/tools/sc-uat-111.sh bed delta-anchor <sections>)
 *
 * Sections: anchor-at-start between-phases-edit delta-posts delta-comments no-drop no-options manifest-shape
 *
 * Each section builds its own scenario (a full export, controlled edits, a delta export from the stored base), checks it
 * and removes every row, option, term, job and staging folder it made. Last line "ALL PASS (<n>)" or "FAILURES: <n>".
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run with wp eval-file\n";
	exit( 1 );
}

$GLOBALS['gs111'] = array(
	'pass'    => 0,
	'fail'    => 0,
	'cleanup' => array(),
);
function gs111_assert( $c, $id, $t = '', $d = '' ) {
	if ( $c ) {
		$GLOBALS['gs111']['pass']++;
		echo 'PASS: ' . $id . ( '' !== $t ? ' ' . $t : '' ) . "\n";
	} else {
		$GLOBALS['gs111']['fail']++;
		echo 'FAIL: ' . $id . ( '' !== $t ? ' ' . $t : '' ) . ( '' !== $d ? ' -- ' . $d : '' ) . "\n";
	}
	return (bool) $c;
}
function gs111_info( $t ) {
	echo 'INFO: ' . $t . "\n";
}
function gs111_on_cleanup( callable $fn ) {
	$GLOBALS['gs111']['cleanup'][] = $fn;
}
function gs111_cleanup() {
	foreach ( array_reverse( $GLOBALS['gs111']['cleanup'] ) as $fn ) {
		try {
			$fn();
		} catch ( \Throwable $e ) {
			gs111_info( 'cleanup failed: ' . $e->getMessage() );
		}
	}
	$GLOBALS['gs111']['cleanup'] = array();
}

const GS111_FULL_JOB  = '0e111e05-aaaa-4bbb-8ccc-dddddddddd01';
const GS111_DELTA_JOB = '0e111e05-aaaa-4bbb-8ccc-dddddddddd02';

function gs111_reset_job() {
	$job = get_option( 'gend_society_migration_job', null );
	if ( is_array( $job ) && function_exists( 'gend_society_migration_export_wipe' ) ) {
		gend_society_migration_export_wipe( $job );
	}
	delete_option( 'gend_society_migration_job' );
	delete_option( 'gend_society_migration_lock' );
	wp_clear_scheduled_hook( 'gend_society_migration_export_tick' );
}

/** Deliver a job receipt the way 110-12 does. */
function gs111_start( $id, $mode = null ) {
	gs111_reset_job();
	$rec = array(
		'job_id'        => $id,
		'export_secret' => str_repeat( 'e', 64 ),
		'received_at'   => time(),
	);
	if ( null !== $mode ) {
		$rec['mode'] = $mode;
	}
	add_option( 'gend_society_migration_job', $rec, '', false );
	do_action( 'gend_society_migration_job_received', get_option( 'gend_society_migration_job' ) );
	return gend_society_migration_export_job();
}

function gs111_run( $n = 3000 ) {
	$r = array( 'status' => 'none' );
	for ( $i = 0; $i < $n; $i++ ) {
		$r = gend_society_migration_export_tick( 'cron' );
		if ( in_array( $r['status'], array( 'done', 'failed', 'none', 'idle' ), true ) ) {
			break;
		}
	}
	return $r;
}

function gs111_sql( array $job, $kind = 'db' ) {
	$out = '';
	foreach ( (array) $job['parts'][ $kind ] as $p ) {
		$gz = gzopen( $job['staging'] . '/' . $p['file'], 'rb' );
		while ( $gz && ! gzeof( $gz ) ) {
			$out .= gzread( $gz, 1048576 );
		}
		if ( $gz ) {
			gzclose( $gz );
		}
	}
	return $out;
}

function gs111_manifest( array $job ) {
	$m = json_decode( (string) file_get_contents( $job['staging'] . '/' . $job['manifest']['file'] ), true );
	return is_array( $m ) ? $m : array();
}

/** Insert a post with a controlled modified time (GMT string). */
function gs111_post( $args, $modified_gmt ) {
	global $wpdb;
	$id = wp_insert_post( array_merge( array( 'post_status' => 'publish', 'post_type' => 'post', 'post_title' => 'gs111 fixture', 'post_content' => 'body' ), $args ), true );
	if ( is_wp_error( $id ) || ! $id ) {
		return 0;
	}
	$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => $modified_gmt, 'post_modified' => $modified_gmt ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB
	gs111_on_cleanup(
		function () use ( $id ) {
			wp_delete_post( $id, true );
		}
	);
	return (int) $id;
}

/**
 * The scenario every section shares. A full export runs (job start -> base option), controlled fixtures are placed
 * relative to the base start time T, then a delta job is exported from the stored base. Returns the facts.
 */
function gs111_scenario() {
	global $wpdb;
	gs111_on_cleanup( 'gs111_reset_job' );
	gs111_on_cleanup(
		function () {
			delete_option( 'gend_society_migration_base' );
			delete_option( 'gs111_opt' );
		}
	);
	delete_option( 'gend_society_migration_base' );
	$S = array();

	// Posts that exist BEFORE the base export (IDs at or below the base max), so only their date can select them.
	$S['untouched'] = gs111_post( array( 'post_title' => 'gs111 untouched' ), '2001-01-01 00:00:00' );
	$S['outside']   = gs111_post( array( 'post_title' => 'gs111 outside overlap' ), '2001-01-01 00:00:00' );
	$S['inside']    = gs111_post( array( 'post_title' => 'gs111 inside overlap' ), '2001-01-01 00:00:00' );
	$S['between']   = gs111_post( array( 'post_title' => 'gs111 between' ), '2001-01-01 00:00:00' );
	$S['edited']    = gs111_post( array( 'post_title' => 'gs111 edited "q" \'s', 'post_content' => "line1\nline2\r\nCOMMENT */ -- end" ), '2001-01-01 00:00:00' );
	$full        = gs111_start( GS111_FULL_JOB );
	$S['full_start'] = $full;
	$S['T']      = (string) ( $full['watermark']['taken_at_gmt'] ?? '' );
	$T           = strtotime( $S['T'] . ' UTC' );
	$one = function () {
		return 0; // one unit of work per tick: the db phase has begun and is not finished
	};
	add_filter( 'gend_society_migration_tick_budget', $one );
	$S['tick1'] = gend_society_migration_export_tick( 'cron' );
	remove_filter( 'gend_society_migration_tick_budget', $one );
	// Edits AFTER the job start, BEFORE the content phase starts (the research anchor bug window).
	$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', $T + 2 ), 'post_modified' => gmdate( 'Y-m-d H:i:s', $T + 2 ) ), array( 'ID' => $S['between'] ) ); // phpcs:ignore WordPress.DB
	$r            = gs111_run();
	$S['full_end'] = gend_society_migration_export_job();
	$S['full_r']  = $r;
	$S['full_manifest'] = gs111_manifest( $S['full_end'] );
	$S['base']    = get_option( 'gend_society_migration_base', array() );
	$S['max_post_at_base'] = (int) ( $S['base']['watermark']['max_post_id'] ?? 0 );
	$S['max_comment_at_base'] = (int) ( $S['base']['watermark']['max_comment_id'] ?? 0 );
	gs111_reset_job();

	$fmt = function ( $off ) use ( $T ) {
		return gmdate( 'Y-m-d H:i:s', $T + $off );
	};
	// Fixtures; the "new" ones are created after the base so their IDs are above the base max.
	foreach ( array( 'untouched' => -2 * 86400, 'outside' => -400, 'inside' => -200, 'edited' => 10 ) as $k => $off ) {
		$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => $fmt( $off ), 'post_modified' => $fmt( $off ) ), array( 'ID' => $S[ $k ] ) ); // phpcs:ignore WordPress.DB
	}
	$S['new_old']   = gs111_post( array( 'post_title' => 'gs111 new with old date' ), $fmt( -3 * 86400 ) ); // ID above the base max, date old
	$S['auto']      = gs111_post( array( 'post_title' => 'gs111 auto', 'post_status' => 'auto-draft' ), $fmt( 20 ) );
	$S['revision']  = gs111_post( array( 'post_title' => 'gs111 rev', 'post_type' => 'revision', 'post_parent' => $S['edited'], 'post_name' => $S['edited'] . '-revision-v1', 'post_status' => 'inherit' ), $fmt( 20 ) );
	add_post_meta( $S['edited'], 'gs111_meta', "multi\nline 'quoted' value" );
	add_post_meta( $S['untouched'], 'gs111_meta', 'untouched meta' );
	wp_set_object_terms( $S['edited'], array( 'gs111-term' ), 'category' );
	gs111_on_cleanup(
		function () {
			$t = get_term_by( 'name', 'gs111-term', 'category' );
			if ( $t ) {
				wp_delete_term( $t->term_id, 'category' );
			}
		}
	);
	update_option( 'gs111_opt', 'changed after the base', false );

	$cid = function ( $args, $date ) use ( $wpdb ) {
		$id = wp_insert_comment( array_merge( array( 'comment_post_ID' => 1, 'comment_content' => 'gs111 comment', 'comment_author' => 'gs111', 'comment_author_email' => 'gs111@example.test', 'comment_approved' => '1' ), $args, array( 'comment_date' => $date, 'comment_date_gmt' => $date ) ) );
		gs111_on_cleanup(
			function () use ( $id ) {
				wp_delete_comment( $id, true );
			}
		);
		return (int) $id;
	};
	$S['c_new']   = $cid( array( 'comment_content' => "new\ncomment" ), $fmt( 30 ) );
	$S['c_old']   = $cid( array(), $fmt( -2 * 86400 ) ); // ID above the base max, so the ID rule still takes it
	$S['c_inside'] = $cid( array(), $fmt( -100 ) );
	add_comment_meta( $S['c_new'], 'gs111_cm', "cm\nvalue" );

	$delta = gs111_start( GS111_DELTA_JOB, 'delta' );
	$S['delta_start'] = $delta;
	$r = gs111_run();
	$S['delta_r']   = $r;
	$S['delta_end'] = gend_society_migration_export_job();
	$S['sql']       = is_array( $S['delta_end'] ) && ! empty( $S['delta_end']['staging'] ) ? gs111_sql( $S['delta_end'] ) : '';
	$S['dmanifest'] = is_array( $S['delta_end'] ) && ! empty( $S['delta_end']['manifest'] ) ? gs111_manifest( $S['delta_end'] ) : array();
	return $S;
}

/** Does an INSERT IGNORE into $table for the row with primary $id exist in $sql? */
function gs111_has_row( $sql, $table, $id ) {
	return (bool) preg_match( '/^INSERT IGNORE INTO `' . preg_quote( $table, '/' ) . '` \(`[^)]*\) VALUES \(\'' . (int) $id . '\',/m', $sql );
}

$S = array();

$S['anchor-at-start'] = function () {
	gs111_on_cleanup( 'gs111_reset_job' );
	delete_option( 'gend_society_migration_base' );
	$before = time();
	$job    = gs111_start( GS111_FULL_JOB );
	$wm     = (array) ( $job['watermark'] ?? array() );
	gs111_assert( ! empty( $wm['taken_at_gmt'] ) && preg_match( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $wm['taken_at_gmt'] ), 'anchor-at-start-set', 'watermark.taken_at_gmt is stored when the job starts (' . ( $wm['taken_at_gmt'] ?? '' ) . ')' );
	gs111_assert( isset( $wm['max_post_id'], $wm['max_comment_id'], $wm['max_order_id'], $wm['posts_modified_gmt'] ), 'anchor-at-start-keys', 'max ids and posts_modified_gmt still there' );
	$t = strtotime( ( $wm['taken_at_gmt'] ?? '' ) . ' UTC' );
	gs111_assert( $t >= $before - 2 && $t <= time() + 2, 'anchor-at-start-time', 'taken_at_gmt is now, not later' );
	gs111_assert( 'full' === ( $job['mode'] ?? '' ), 'anchor-at-start-mode', 'a receipt without mode is a full job' );
	gs111_assert( 'db' === ( $job['phase'] ?? '' ) && empty( $job['parts']['db'] ) && empty( $job['cur'] ) && ! isset( $job['content'] ), 'anchor-at-start-before-parts', 'no part and no content phase exists yet' );
	$taken = $wm['taken_at_gmt'] ?? '';
	sleep( 2 );
	$r   = gs111_run();
	$end = gend_society_migration_export_job();
	gs111_assert( 'done' === $r['status'], 'anchor-at-start-done', 'full export finished', wp_json_encode( $r ) );
	gs111_assert( $taken === ( $end['watermark']['taken_at_gmt'] ?? '' ), 'anchor-at-start-kept', 'content_init did not overwrite the watermark (' . ( $end['watermark']['taken_at_gmt'] ?? '' ) . ')' );
	$m = gs111_manifest( $end );
	gs111_assert( $taken === ( $m['watermark']['taken_at_gmt'] ?? '' ) && 2 === (int) ( $m['format'] ?? 0 ) && ! isset( $m['mode'] ), 'anchor-at-start-manifest', 'full manifest: format 2, watermark with taken_at_gmt, no delta keys' );
	$b = get_option( 'gend_society_migration_base', array() );
	gs111_assert( ( $b['job_id'] ?? '' ) === GS111_FULL_JOB && ( $b['watermark']['taken_at_gmt'] ?? '' ) === $taken && ! preg_match( '/secret|token|export_secret/i', wp_json_encode( $b ) ), 'anchor-at-start-base', 'base option holds job id + watermark, no secret' );
	// A job started before 111-05 (no watermark) still gets one at content start.
	$old = $end;
	unset( $old['watermark'], $old['content'] );
	gend_society_migration_content_init( $old );
	gs111_assert( ! empty( $old['watermark']['taken_at_gmt'] ), 'anchor-at-start-legacy', 'content_init fills a missing watermark (in-flight jobs)' );
	$keep = array( 'taken_at_gmt' => '2001-01-01 00:00:00' );
	$old2 = array_merge( $end, array( 'watermark' => $keep ) );
	unset( $old2['content'] );
	gend_society_migration_content_init( $old2 );
	gs111_assert( '2001-01-01 00:00:00' === $old2['watermark']['taken_at_gmt'], 'anchor-at-start-no-overwrite', 'content_init leaves an existing watermark alone' );
	delete_option( 'gend_society_migration_base' );
};

$S['between-phases-edit'] = function () {
	$S = gs111_scenario();
	gs111_assert( 'running' === ( $S['tick1']['status'] ?? '' ) && 'db' === ( $S['tick1']['phase'] ?? '' ), 'between-db-begun', 'the edit happens while the db phase is still running', wp_json_encode( $S['tick1'] ) );
	gs111_assert( 'done' === ( $S['full_r']['status'] ?? '' ), 'between-full-done', 'full export done', wp_json_encode( array( $S['tick1'], $S['full_r'] ) ) );
	$T = $S['T'];
	global $wpdb;
	$e = (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_modified_gmt FROM {$wpdb->posts} WHERE ID = %d", $S['between'] ) ); // phpcs:ignore WordPress.DB
	gs111_assert( $T <= $e, 'between-edit-after-anchor', 'the edit (' . $e . ') is not before the stored job-start anchor (' . $T . ')' );
	gs111_assert( ( $S['full_end']['watermark']['taken_at_gmt'] ?? '' ) === $T, 'between-anchor-stable', 'the anchor did not move while the phases ran' );
	gs111_assert( 'done' === ( $S['delta_r']['status'] ?? '' ), 'between-delta-done', 'delta job finished', wp_json_encode( $S['delta_r'] ) );
	gs111_assert( gs111_has_row( $S['sql'], 'wp_posts', $S['between'] ), 'between-in-delta', 'the post changed between the DB snapshot and the content start is in the delta from the base' );
	gs111_assert( ( $S['dmanifest']['anchor']['taken_at_gmt'] ?? '' ) === $T, 'between-delta-anchor', 'delta anchor is the base job-start time, not the delta job start' );
	$dstart = (string) ( $S['delta_start']['watermark']['taken_at_gmt'] ?? '' );
	gs111_assert( '' !== $dstart && $dstart >= $T, 'between-own-watermark', 'the delta job has its own watermark for the next catch-up (' . $dstart . ')' );
	// Boundary of the 300 s overlap.
	gs111_assert( gs111_has_row( $S['sql'], 'wp_posts', $S['inside'] ), 'between-inside-overlap', 'a post modified 200 s before the anchor is included' );
	gs111_assert( ! gs111_has_row( $S['sql'], 'wp_posts', $S['outside'] ), 'between-outside-overlap', 'a post modified 400 s before the anchor is not' );
};

$S['delta-posts'] = function () {
	$S   = gs111_scenario();
	$sql = $S['sql'];
	gs111_assert( 'done' === ( $S['delta_r']['status'] ?? '' ), 'posts-done', 'delta job finished', wp_json_encode( $S['delta_r'] ) );
	gs111_assert( 'delta' === ( $S['delta_start']['mode'] ?? '' ) && 'db' === ( $S['delta_start']['phase'] ?? '' ), 'posts-mode', 'receipt mode delta starts a delta job' );
	gs111_assert( gs111_has_row( $sql, 'wp_posts', $S['edited'] ), 'posts-edited', 'the edited post is present' );
	gs111_assert( $S['new_old'] > $S['max_post_at_base'] && gs111_has_row( $sql, 'wp_posts', $S['new_old'] ), 'posts-new-id', 'a new post (ID above the base max) is present even with an old date' );
	gs111_assert( $S['untouched'] <= $S['max_post_at_base'] && ! gs111_has_row( $sql, 'wp_posts', $S['untouched'] ), 'posts-untouched-fixture', 'a fixture post below the base max, modified long before the anchor, is absent' );
	// A truly untouched OLD post (ID below the base max, old date): the sample page / hello world.
	global $wpdb;
	$old_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID <= %d AND post_modified_gmt < %s AND post_type NOT IN ('revision') AND post_status <> 'auto-draft' ORDER BY ID LIMIT 1", $S['max_post_at_base'], gmdate( 'Y-m-d H:i:s', strtotime( $S['T'] . ' UTC' ) - 3600 ) ) ); // phpcs:ignore WordPress.DB
	if ( $old_id ) {
		gs111_assert( ! gs111_has_row( $sql, 'wp_posts', $old_id ), 'posts-untouched', 'an old post below the base max and before the anchor is absent (ID ' . $old_id . ')' );
	} else {
		gs111_info( 'no old post on this bed; untouched check covered by the ID rule' );
	}
	gs111_assert( ! gs111_has_row( $sql, 'wp_posts', $S['revision'] ), 'posts-no-revision', 'a revision modified after the anchor is absent' );
	gs111_assert( ! gs111_has_row( $sql, 'wp_posts', $S['auto'] ), 'posts-no-autodraft', 'an auto-draft modified after the anchor is absent' );
	// Comment-like and newline content stays on one line.
	$lines = explode( "\n", rtrim( $sql, "\n" ) );
	$bad   = array();
	foreach ( $lines as $l ) {
		if ( ! preg_match( '/^(INSERT IGNORE INTO `wp_[a-z_]+` |DELETE FROM `wp_[a-z_]+` WHERE `[a-z_]+` IN \(|SET )/', $l ) ) {
			$bad[] = substr( $l, 0, 80 );
		}
	}
	gs111_assert( ! $bad, 'posts-one-statement-per-line', 'every line starts a statement (newlines in values are escaped)', wp_json_encode( array_slice( $bad, 0, 3 ) ) );
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID = %d", $S['edited'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
	$chk = md5( $row['post_title'] . '|' . $row['post_content'] . '|' . $row['post_modified_gmt'] . '|' . $row['post_status'] );
	$idh = md5( $row['post_name'] . '|' . $row['post_date_gmt'] . '|' . $row['post_type'] . '|' . $row['guid'] );
	gs111_assert( false !== strpos( $sql, '/* chk:' . $chk . ' */ /* id:' . $idh . ' */' ), 'posts-chk-id', 'the edited post line carries the chk and id comments the importer reads' );
	gs111_assert( false === stripos( $sql, 'REPLACE INTO' ), 'posts-no-replace', 'never REPLACE INTO' );
	$del = '/^DELETE FROM `wp_postmeta` WHERE `post_id` IN \([0-9,]*\b' . $S['edited'] . '\b[0-9,]*\);$/m';
	gs111_assert( (bool) preg_match( $del, $sql ), 'posts-meta-delete', 'DELETE FROM wp_postmeta by parent precedes the meta rows' );
	gs111_assert( (bool) preg_match( '/^INSERT IGNORE INTO `wp_postmeta` .*gs111_meta.*multi\\\\nline.* \/\* parent:' . $S['edited'] . ' \*\/$/m', $sql ), 'posts-meta-row', 'the post meta row (escaped newline) is there with its parent comment' );
	gs111_assert( ! preg_match( '/untouched meta/', $sql ), 'posts-meta-untouched', 'meta of an unchanged post is not exported' );
	$term = get_term_by( 'name', 'gs111-term', 'category' );
	$tt   = $term ? (int) $term->term_taxonomy_id : 0;
	gs111_assert( $tt > 0 && (bool) preg_match( '/^INSERT IGNORE INTO `wp_term_relationships` \(`object_id`, `term_taxonomy_id`, `term_order`\) VALUES \(\'' . $S['edited'] . '\', \'' . $tt . '\'/m', $sql ), 'posts-term-rel', 'the edited post term relationship is exported' );
	gs111_assert( (bool) preg_match( '/^DELETE FROM `wp_term_relationships` WHERE `object_id` IN \(/m', $sql ), 'posts-term-delete', 'term relationships are replaced by delete-by-parent' );
	gs111_assert( (int) ( $S['dmanifest']['counts']['posts'] ?? -1 ) === preg_match_all( '/^INSERT IGNORE INTO `wp_posts` /m', $sql ), 'posts-count', 'manifest counts.posts equals the post lines' );
};

$S['delta-comments'] = function () {
	$S   = gs111_scenario();
	$sql = $S['sql'];
	gs111_assert( 'done' === ( $S['delta_r']['status'] ?? '' ), 'comments-done', 'delta job finished' );
	gs111_assert( $S['c_new'] > $S['max_comment_at_base'] && gs111_has_row( $sql, 'wp_comments', $S['c_new'] ), 'comments-new', 'a new comment is present' );
	gs111_assert( gs111_has_row( $sql, 'wp_comments', $S['c_inside'] ), 'comments-inside', 'a comment dated 100 s before the anchor is present (overlap)' );
	gs111_assert( gs111_has_row( $sql, 'wp_comments', $S['c_old'] ), 'comments-newid-olddate', 'a comment with a new ID and an old date is present (ID rule)' );
	global $wpdb;
	$below = (int) $wpdb->get_var( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_ID <= %d AND comment_date_gmt < %s ORDER BY comment_ID LIMIT 1", $S['max_comment_at_base'], gmdate( 'Y-m-d H:i:s', strtotime( $S['T'] . ' UTC' ) - 3600 ) ) ); // phpcs:ignore WordPress.DB
	if ( $below ) {
		gs111_assert( ! gs111_has_row( $sql, 'wp_comments', $below ), 'comments-old-absent', 'an old comment with an ID below the base max and a date before the anchor is absent (ID ' . $below . ')' );
	} else {
		gs111_info( 'no old comment below the base max on this bed' );
	}
	gs111_assert( (bool) preg_match( '/^DELETE FROM `wp_commentmeta` WHERE `comment_id` IN \([0-9,]*\b' . $S['c_new'] . '\b[0-9,]*\);$/m', $sql ), 'comments-meta-delete', 'DELETE FROM wp_commentmeta by parent' );
	gs111_assert( (bool) preg_match( '/^INSERT IGNORE INTO `wp_commentmeta` .*gs111_cm.*cm\\\\nvalue.* \/\* parent:' . $S['c_new'] . ' \*\/$/m', $sql ), 'comments-meta-row', 'comment meta row present with an escaped newline' );
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->comments} WHERE comment_ID = %d", $S['c_new'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
	$chk = md5( $row['comment_content'] . '|' . $row['comment_approved'] . '|' . $row['comment_type'] );
	$idh = md5( $row['comment_post_ID'] . '|' . $row['comment_author'] . '|' . $row['comment_author_email'] . '|' . $row['comment_date_gmt'] );
	gs111_assert( false !== strpos( $sql, '/* chk:' . $chk . ' */ /* id:' . $idh . ' */' ), 'comments-chk-id', 'comment lines carry chk and id comments' );
	gs111_assert( (int) ( $S['dmanifest']['counts']['comments'] ?? -1 ) === preg_match_all( '/^INSERT IGNORE INTO `wp_comments` /m', $sql ), 'comments-count', 'manifest counts.comments equals the comment lines' );
};

$S['no-drop'] = function () {
	$S = gs111_scenario();
	gs111_assert( 'done' === ( $S['delta_r']['status'] ?? '' ) && '' !== $S['sql'], 'nodrop-done', 'delta job finished with SQL (' . strlen( $S['sql'] ) . ' bytes)' );
	$hits = preg_match_all( '/\b(DROP|CREATE|ALTER|TRUNCATE|REPLACE|RENAME|GRANT)\b/i', $S['sql'], $m );
	gs111_assert( 0 === $hits, 'nodrop-none', 'no DROP / CREATE / ALTER / TRUNCATE / REPLACE anywhere in the part bytes', wp_json_encode( $m[0] ?? array() ) );
	$verbs = array();
	foreach ( explode( "\n", rtrim( $S['sql'], "\n" ) ) as $l ) {
		preg_match( '/^(\w+(?: IGNORE)?(?: INTO| FROM)?)/', $l, $mm );
		$verbs[ $mm[1] ?? '?' ] = true;
	}
	$extra = array_diff( array_keys( $verbs ), array( 'SET', 'INSERT IGNORE INTO', 'DELETE FROM' ) );
	gs111_assert( ! $extra, 'nodrop-verbs', 'only SET / INSERT IGNORE INTO / DELETE FROM statements (' . implode( ', ', array_keys( $verbs ) ) . ')', wp_json_encode( array_values( $extra ) ) );
	gs111_assert( ! preg_match( '/^DELETE FROM `wp_(?!postmeta|term_relationships|commentmeta)/m', $S['sql'] ), 'nodrop-delete-children-only', 'DELETE only touches child tables' );
};

$S['no-options'] = function () {
	$S = gs111_scenario();
	gs111_assert( 'done' === ( $S['delta_r']['status'] ?? '' ), 'noopt-done', 'delta job finished' );
	gs111_assert( false === strpos( $S['sql'], 'wp_options' ) && false === strpos( $S['sql'], 'gs111_opt' ) && false === strpos( $S['sql'], 'changed after the base' ), 'noopt-sql', 'no wp_options statement and the option changed after the base is not exported' );
	gs111_assert( ! preg_match( '/`wp_(users|usermeta|options)`/', $S['sql'] ), 'noopt-no-users', 'no wp_users / wp_usermeta / wp_options (users arrive in 111-07)' );
	gs111_assert( ! in_array( 'wp_options', (array) ( $S['dmanifest']['tables'] ?? array() ), true ), 'noopt-manifest', 'manifest tables[] has no wp_options' );
	gs111_assert( empty( $S['delta_end']['tables'] ) && empty( $S['delta_end']['parts']['users'] ), 'noopt-no-table-dump', 'the delta job has no table list and no users parts' );
	gs111_assert( 0 === strpos( $S['sql'], "SET NAMES utf8mb4;" ), 'noopt-header', 'parts start with the usual SET header (no CREATE/DROP)' );
};

$S['manifest-shape'] = function () {
	$S = gs111_scenario();
	$m = $S['dmanifest'];
	gs111_assert( 'done' === ( $S['delta_r']['status'] ?? '' ) && $m, 'shape-done', 'delta manifest written' );
	gs111_assert( 2 === ( $m['format'] ?? 0 ) && 'delta' === ( $m['mode'] ?? '' ), 'shape-format-mode', 'format 2, mode delta' );
	gs111_assert( ( $m['base_job_id'] ?? '' ) === GS111_FULL_JOB && ( $m['job_id'] ?? '' ) === GS111_DELTA_JOB, 'shape-ids', 'base_job_id is the full job, job_id the delta job' );
	gs111_assert( ( $m['anchor']['taken_at_gmt'] ?? '' ) === $S['T'] && 300 === ( $m['anchor']['overlap_s'] ?? 0 ) && 2 === count( (array) ( $m['anchor'] ?? array() ) ), 'shape-anchor', 'anchor {taken_at_gmt = base start, overlap_s 300}' );
	$tables = (array) ( $m['tables'] ?? array() );
	sort( $tables );
	gs111_assert( $tables === array( 'wp_commentmeta', 'wp_comments', 'wp_postmeta', 'wp_posts', 'wp_term_relationships' ), 'shape-tables', 'tables[] lists the five whitelisted tables', wp_json_encode( $tables ) );
	$c = (array) ( $m['counts'] ?? array() );
	gs111_assert( isset( $c['posts'], $c['postmeta'], $c['term_relationships'], $c['comments'], $c['commentmeta'] ) && $c['posts'] >= 2 && $c['comments'] >= 2 && $c['postmeta'] >= 1, 'shape-counts', 'counts{} per table', wp_json_encode( $c ) );
	gs111_assert( isset( $m['watermark']['taken_at_gmt'] ) && isset( $m['parts']['db'][0]['sha256'] ), 'shape-parts', 'own watermark and db parts with sha256 present' );
	$j = $S['delta_end'];
	gs111_assert( hash( 'sha256', (string) file_get_contents( $j['staging'] . '/' . $j['manifest']['file'] ) ) === $j['manifest']['sha256'], 'shape-manifest-sha', 'manifest sha256 matches' );
	$p = $j['parts']['db'][0];
	gs111_assert( hash_file( 'sha256', $j['staging'] . '/' . $p['file'] ) === ( $m['parts']['db'][0]['sha256'] ?? '' ), 'shape-part-sha', 'part sha256 in the manifest matches the file' );
	gs111_assert( false === strpos( (string) wp_json_encode( $m ), str_repeat( 'e', 64 ) ), 'shape-no-secret', 'no export secret in the manifest' );
	// No base: a delta cannot start, it fails cleanly.
	delete_option( 'gend_society_migration_base' );
	$nb = gs111_start( GS111_DELTA_JOB, 'delta' );
	gs111_assert( 'failed' === ( $nb['phase'] ?? '' ) && ! empty( $nb['error'] ), 'shape-no-base', 'a delta with no earlier full export fails with a message' );
};

$GLOBALS['gs111_sections'] = $S;

$gs111_want = array();
foreach ( isset( $args ) ? (array) $args : array() as $a ) {
	foreach ( explode( ',', (string) $a ) as $s ) {
		if ( '' !== trim( $s ) ) {
			$gs111_want[] = trim( $s );
		}
	}
}
$gs111_ok = function_exists( 'gend_society_migration_export_tick' ) && function_exists( 'gend_society_migration_delta_db_step' );
gs111_info( 'delta-anchor on ' . home_url() . ' php=' . PHP_VERSION . ' sections=' . implode( ',', $gs111_want ) );
gs111_assert( $gs111_ok, 'module', 'exporter with delta support loaded' );
foreach ( $gs111_ok ? $gs111_want : array() as $s ) {
	echo '== section ' . $s . "\n";
	if ( ! isset( $S[ $s ] ) ) {
		gs111_assert( false, $s, 'unknown section' );
		continue;
	}
	try {
		$S[ $s ]();
	} catch ( \Throwable $e ) {
		gs111_assert( false, $s, 'threw ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
	} finally {
		gs111_cleanup();
	}
}
echo ( 0 === (int) $GLOBALS['gs111']['fail'] && (int) $GLOBALS['gs111']['pass'] > 0 ) ? 'ALL PASS (' . (int) $GLOBALS['gs111']['pass'] . ")\n" : 'FAILURES: ' . (int) $GLOBALS['gs111']['fail'] . "\n";
