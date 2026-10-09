<?php
/**
 * Module loader: walks inc/bootstrap/manifest.php in order and requires each
 * module whose tier is allowed in the current runtime mode and whose needs
 * are met.
 *
 * Rules (do not change without re-proving hub load-order equivalence):
 *   - order is the manifest order; nothing is reordered or deduplicated
 *   - every require is file_exists-guarded (hub PVC partial-deploy defence:
 *     a missing file degrades instead of fataling and auto-deactivating)
 *   - an entry's 'after' callable runs right after its require (the
 *     add_action / ::init() lines that used to follow it in gend-society.php)
 *   - a 'group' entry registers ONE action at its position; the action
 *     requires the members in order once its required class exists
 *   - no output, no option writes, no exception handling
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'gend_society_module_record' ) ) {
	/**
	 * Record a loaded/skipped module for gend_society_loaded_modules(). Internal helper.
	 *
	 * @param string $file   Path relative to GS_DIR.
	 * @param string $reason '' when loaded, else 'tier' | 'needs' | 'missing'.
	 */
	function gend_society_module_record( string $file, string $reason = '' ): void {
		if ( ! isset( $GLOBALS['gend_society_module_state'] ) ) {
			$GLOBALS['gend_society_module_state'] = array(
				'loaded'  => array(),
				'skipped' => array(),
			);
		}
		if ( '' === $reason ) {
			$GLOBALS['gend_society_module_state']['loaded'][] = $file;
		} else {
			$GLOBALS['gend_society_module_state']['skipped'][ $file ] = $reason;
		}
	}
}

if ( ! function_exists( 'gend_society_module_allowed' ) ) {
	/**
	 * Tier + needs check for one manifest entry. Internal helper.
	 *
	 * @param array    $entry Manifest entry.
	 * @param string[] $tiers Allowed tiers.
	 * @return string '' when allowed, else the skip reason.
	 */
	function gend_society_module_allowed( array $entry, array $tiers ): string {
		if ( ! in_array( $entry['tier'], $tiers, true ) ) {
			return 'tier';
		}
		if ( ! gend_society_module_needs_met( isset( $entry['needs'] ) ? (array) $entry['needs'] : array() ) ) {
			return 'needs';
		}
		return '';
	}
}

if ( ! function_exists( 'gend_society_require_module' ) ) {
	/**
	 * Require one allowed module (file_exists-guarded) and run its 'after'
	 * callable. Internal helper.
	 *
	 * @param array $entry Manifest entry.
	 */
	function gend_society_require_module( array $entry ): void {
		$file = $entry['file'];
		if ( ! file_exists( GEND_SOCIETY_DIR . $file ) ) {
			gend_society_module_record( $file, 'missing' );
			return;
		}
		require_once GEND_SOCIETY_DIR . $file;
		gend_society_module_record( $file );
		if ( isset( $entry['after'] ) && is_callable( $entry['after'] ) ) {
			call_user_func( $entry['after'] );
		}
	}
}

if ( ! function_exists( 'gend_society_load_modules' ) ) {
	/**
	 * Load every module allowed in the current runtime mode, in manifest order.
	 * Called once, from gend-society.php.
	 */
	function gend_society_load_modules(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		$manifest = require GEND_SOCIETY_DIR . 'inc/bootstrap/manifest.php';
		$tiers    = gend_society_mode_tiers( gend_society_runtime_mode() );

		foreach ( $manifest['modules'] as $entry ) {
			if ( isset( $entry['group'] ) ) {
				// Deferred group (e.g. the BP group tabs on bp_include): decide
				// tier/needs now, require later, inside ONE action registered here.
				$members = array();
				foreach ( $entry['modules'] as $member ) {
					$reason = gend_society_module_allowed( $member, $tiers );
					if ( '' === $reason ) {
						$members[] = $member;
					} else {
						gend_society_module_record( $member['file'], $reason );
					}
				}
				if ( empty( $members ) ) {
					continue;
				}
				$requires_class = $entry['requires_class'];
				add_action(
					$entry['hook'],
					static function () use ( $members, $requires_class ) {
						if ( ! class_exists( $requires_class ) ) {
							return;
						}
						foreach ( $members as $member ) {
							gend_society_require_module( $member );
						}
					}
				);
				continue;
			}

			$reason = gend_society_module_allowed( $entry, $tiers );
			if ( '' !== $reason ) {
				gend_society_module_record( $entry['file'], $reason );
				continue;
			}
			gend_society_require_module( $entry );
		}
	}
}

if ( ! function_exists( 'gend_society_loaded_modules' ) ) {
	/**
	 * What the loader did this request (diagnostics / UAT). Group members are
	 * listed under 'loaded' once their hook has actually run.
	 *
	 * @return array{mode:string,source:string,loaded:string[],skipped:array<string,string>}
	 */
	function gend_society_loaded_modules(): array {
		$state = isset( $GLOBALS['gend_society_module_state'] )
			? $GLOBALS['gend_society_module_state']
			: array( 'loaded' => array(), 'skipped' => array() );
		return array(
			'mode'    => gend_society_runtime_mode(),
			'source'  => gend_society_runtime_mode_source(),
			'loaded'  => $state['loaded'],
			'skipped' => $state['skipped'],
		);
	}
}
