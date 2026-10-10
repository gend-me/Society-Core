<?php
/**
 * Local readiness checks (PHP version, HTTPS, permalinks, cron, ...).
 *
 * Filled by plan 106-04. Contract stub from 106-01: final signatures, safe
 * no-op bodies. Manifest: tier customer, needs []. Pure local checks, no
 * remote call; reused by the Phase 110 migration pre-flight.
 *
 * Result shape:
 *   array(
 *     'checked_at' => int,
 *     'checks'     => array(
 *       array(
 *         'id'      => string,
 *         'label'   => string,
 *         'status'  => 'pass' | 'warn' | 'fail',
 *         'message' => string,
 *         'blocker' => bool,
 *       ),
 *       ...
 *     ),
 *   )
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'gend_society_readiness_run' ) ) {
	/**
	 * Run (or return the cached) readiness checks.
	 *
	 * @param bool $force Re-run even when a recent result is stored.
	 * @return array{checked_at:int,checks:array<int,array{id:string,label:string,status:string,message:string,blocker:bool}>}
	 */
	function gend_society_readiness_run( bool $force = false ): array {
		unset( $force );
		return array(
			'checked_at' => time(),
			'checks'     => array(),
		);
	}
}

if ( ! function_exists( 'gend_society_readiness_last' ) ) {
	/**
	 * Last stored readiness result, or null when never run.
	 *
	 * @return array|null
	 */
	function gend_society_readiness_last(): ?array {
		return null;
	}
}

if ( ! function_exists( 'gend_society_readiness_render_card' ) ) {
	/**
	 * Echo the readiness card.
	 *
	 * @param array|null $result Result from gend_society_readiness_run(); null = last stored.
	 */
	function gend_society_readiness_render_card( ?array $result = null ): void {
		unset( $result );
	}
}
