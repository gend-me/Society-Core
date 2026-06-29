<?php
/**
 * Phase 63-02 — Hosting > Chain Gas Rates admin sub-tab.
 *
 * Super-admin only operator surface for managing per-rail fiat -> DGEN
 * conversion rates used by Plan 63-01's Gend_CP_Fiat_Gas_Charger. Lists 7
 * default rails (stripe / paypal / btcpay_btc / btcpay_lightning / evm_eth
 * / evm_usdc / coinbase_usdc) + any operator-added custom rails; inline
 * edit per row; deactivate per row; add-custom-rail form below the table.
 *
 * Talks to Gend_CP_Fiat_Gas_Charger static methods via admin-post.php
 * handlers (NOT REST per phase invariant — preserves the ZERO new REST
 * routes constraint locked at Phase 63 planning).
 *
 * DOM nodes namespaced `.gs-fiat-gas-rates-*`. NO new keyframes. NO new
 * CDN. CSS lives in inc/admin-style.php (appended block).
 *
 * Counsel-gate: re-uses EXISTING GS_CHAIN_GAS_PUBLIC (Plan 50.1.1-05;
 * NO new flag introduced in Phase 63).
 *
 * @package gend-society
 * @phase   63
 * @plan    02
 * @since   1.0.0 (Phase 63-02)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Defensive: if Plan 63-01 class hasn't reached the PVC yet (or has been
// rolled back), degrade silently — never fatal in wp-admin.
// project_hub_plugin_sync_gotcha rationale applies.
if ( ! class_exists( 'Gend_CP_Fiat_Gas_Charger' ) ) {
	return;
}

/* ============================================================
   Tab registration — 3 layered patterns (filter / action / fallback
   submenu). Whichever the host gend-society Hosting tab surface
   honours wins; the rest are no-ops.
   ============================================================ */

// Option 1 — existing gdc_hosting_tabs filter (preferred if it exists).
add_filter( 'gdc_hosting_tabs', function ( $tabs ) {
	if ( ! is_array( $tabs ) ) {
		$tabs = array();
	}
	$tabs['chain-gas-rates'] = array(
		'label'    => __( 'Chain Gas Rates', 'gend-society' ),
		'callback' => 'gs_fiat_gas_rates_render',
		'cap'      => 'manage_network_options',
	);
	return $tabs;
} );

// Option 2 — existing gdc_hosting_tab_render_{slug} action hook.
add_action( 'gdc_hosting_tab_render_chain-gas-rates', 'gs_fiat_gas_rates_render' );

// Option 3 — fallback submenu (parent=null hides from nav but keeps the
// URL routable at /wp-admin/admin.php?page=gs-chain-gas-rates). Matches
// feedback_blog_manager_admin_tabs philosophy of NEVER adding new
// top-level menu pages.
add_action( 'admin_menu', function () {
	if ( ! is_super_admin() ) {
		return;
	}
	add_submenu_page(
		null,
		__( 'Chain Gas Rates', 'gend-society' ),
		__( 'Chain Gas Rates', 'gend-society' ),
		'manage_network_options',
		'gs-chain-gas-rates',
		'gs_fiat_gas_rates_render'
	);
} );

// Mirror to network admin so super-admins can land on the page on the
// network admin URL too.
add_action( 'network_admin_menu', function () {
	if ( ! is_super_admin() ) {
		return;
	}
	add_submenu_page(
		null,
		__( 'Chain Gas Rates', 'gend-society' ),
		__( 'Chain Gas Rates', 'gend-society' ),
		'manage_network_options',
		'gs-chain-gas-rates',
		'gs_fiat_gas_rates_render'
	);
} );

/* ============================================================
   Render function — editable table + add-custom-rail form
   ============================================================ */

if ( ! function_exists( 'gs_fiat_gas_rates_render' ) ) {
	function gs_fiat_gas_rates_render() {
		if ( ! is_super_admin() || ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'gend-society' ), 403 );
		}
		$rates = Gend_CP_Fiat_Gas_Charger::get_active_rates( true ); // include inactive
		$rails = defined( 'GEND_CP_FIAT_GAS_RAILS' ) ? GEND_CP_FIAT_GAS_RAILS : array();
		$flag  = defined( 'GS_CHAIN_GAS_PUBLIC' ) && GS_CHAIN_GAS_PUBLIC;
		?>
		<div class="wrap gs-fiat-gas-rates-wrap">
			<h1><?php esc_html_e( 'Chain Gas Rates', 'gend-society' ); ?></h1>
			<p class="description gs-fiat-gas-rates-intro">
				<?php esc_html_e( 'Per-rail fiat-to-DGEN conversion rates for Phase 63 chain-gas accounting. Operator-tunable. Continues under the GS_CHAIN_GAS_PUBLIC counsel-gate (no payment is ever blocked on insufficient gas — v1.0 hard rule).', 'gend-society' ); ?>
			</p>
			<p class="gs-fiat-gas-rates-flag-state">
				<?php esc_html_e( 'GS_CHAIN_GAS_PUBLIC:', 'gend-society' ); ?>
				<strong class="<?php echo $flag ? 'gs-fiat-gas-rates-active' : 'gs-fiat-gas-rates-inactive'; ?>">
					<?php echo $flag ? esc_html__( 'ON', 'gend-society' ) : esc_html__( 'OFF (flag-off short-circuit active)', 'gend-society' ); ?>
				</strong>
			</p>
			<table class="gs-fiat-gas-rates-table widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Rail', 'gend-society' ); ?></th>
						<th><?php esc_html_e( 'Currency', 'gend-society' ); ?></th>
						<th><?php esc_html_e( 'DGEN per unit', 'gend-society' ); ?></th>
						<th><?php esc_html_e( 'Gas unit multiplier', 'gend-society' ); ?></th>
						<th><?php esc_html_e( 'Active', 'gend-society' ); ?></th>
						<th><?php esc_html_e( 'Notes', 'gend-society' ); ?></th>
						<th><?php esc_html_e( 'Updated by', 'gend-society' ); ?></th>
						<th><?php esc_html_e( 'Updated at', 'gend-society' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'gend-society' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					if ( empty( $rates ) ) :
						?>
						<tr class="gs-fiat-gas-rates-row gs-fiat-gas-rates-empty">
							<td colspan="9"><?php esc_html_e( 'No rate rows yet — run the activation hook or add a custom rail below.', 'gend-society' ); ?></td>
						</tr>
						<?php
					endif;
					foreach ( $rates as $row ) :
						$rail   = (string) ( $row['rail'] ?? '' );
						$active = (int) ( $row['active'] ?? 1 );
						?>
						<tr class="gs-fiat-gas-rates-row" data-rail="<?php echo esc_attr( $rail ); ?>">
							<td class="gs-fiat-gas-rates-cell-rail" data-label="<?php esc_attr_e( 'Rail', 'gend-society' ); ?>">
								<strong><?php echo esc_html( $rail ); ?></strong>
							</td>
							<td colspan="8" class="gs-fiat-gas-rates-cell-edit">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gs-fiat-gas-rates-form">
									<?php wp_nonce_field( 'gs_fiat_gas_rate_save_' . $rail ); ?>
									<input type="hidden" name="action" value="gs_fiat_gas_rate_save" />
									<input type="hidden" name="rail" value="<?php echo esc_attr( $rail ); ?>" />
									<span class="gs-fiat-gas-rates-inline-grid">
										<label class="gs-fiat-gas-rates-inline-field">
											<span class="screen-reader-text"><?php esc_html_e( 'Currency', 'gend-society' ); ?></span>
											<input type="text" name="currency" class="gs-fiat-gas-rates-input-currency" value="<?php echo esc_attr( $row['currency'] ?? '' ); ?>" maxlength="8" required />
										</label>
										<label class="gs-fiat-gas-rates-inline-field">
											<span class="screen-reader-text"><?php esc_html_e( 'DGEN per unit', 'gend-society' ); ?></span>
											<input type="number" step="0.00000001" min="0" name="dgen_per_unit" class="gs-fiat-gas-rates-input-amount" value="<?php echo esc_attr( $row['dgen_per_unit'] ?? '' ); ?>" required />
										</label>
										<label class="gs-fiat-gas-rates-inline-field">
											<span class="screen-reader-text"><?php esc_html_e( 'Gas unit multiplier', 'gend-society' ); ?></span>
											<input type="number" step="0.0001" min="0.0001" name="gas_unit_multiplier" class="gs-fiat-gas-rates-input-amount" value="<?php echo esc_attr( $row['gas_unit_multiplier'] ?? '1.0000' ); ?>" required />
										</label>
										<span class="gs-fiat-gas-rates-cell-active" data-label="<?php esc_attr_e( 'Active', 'gend-society' ); ?>">
											<?php
											echo $active
												? '<span class="gs-fiat-gas-rates-active">' . esc_html__( 'Active', 'gend-society' ) . '</span>'
												: '<span class="gs-fiat-gas-rates-inactive">' . esc_html__( 'Inactive', 'gend-society' ) . '</span>';
											?>
										</span>
										<label class="gs-fiat-gas-rates-inline-field gs-fiat-gas-rates-inline-notes">
											<span class="screen-reader-text"><?php esc_html_e( 'Notes', 'gend-society' ); ?></span>
											<input type="text" name="notes" class="gs-fiat-gas-rates-input-notes" value="<?php echo esc_attr( $row['notes'] ?? '' ); ?>" maxlength="255" />
										</label>
										<span class="gs-fiat-gas-rates-cell-updated-by" data-label="<?php esc_attr_e( 'Updated by', 'gend-society' ); ?>">
											<?php echo (int) ( $row['updated_by'] ?? 0 ); ?>
										</span>
										<span class="gs-fiat-gas-rates-cell-updated-at" data-label="<?php esc_attr_e( 'Updated at', 'gend-society' ); ?>">
											<?php echo esc_html( (string) ( $row['updated_at'] ?? '' ) ); ?>
										</span>
										<span class="gs-fiat-gas-rates-cell-actions">
											<button type="submit" class="button button-primary gs-fiat-gas-rates-save">
												<?php esc_html_e( 'Save', 'gend-society' ); ?>
											</button>
										</span>
									</span>
								</form>
								<?php if ( $active ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gs-fiat-gas-rates-form-deactivate">
										<?php wp_nonce_field( 'gs_fiat_gas_rate_deactivate_' . $rail ); ?>
										<input type="hidden" name="action" value="gs_fiat_gas_rate_deactivate" />
										<input type="hidden" name="rail" value="<?php echo esc_attr( $rail ); ?>" />
										<button type="submit" class="button button-secondary gs-fiat-gas-rates-deactivate"
											onclick="return confirm('<?php echo esc_js( __( 'Deactivate this rail? Future payments via this rail will not record gas events.', 'gend-society' ) ); ?>');">
											<?php esc_html_e( 'Deactivate', 'gend-society' ); ?>
										</button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2 class="gs-fiat-gas-rates-add-heading"><?php esc_html_e( 'Add / Replace rail', 'gend-society' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gs-fiat-gas-rates-add-form">
				<?php wp_nonce_field( 'gs_fiat_gas_rate_save_new' ); ?>
				<input type="hidden" name="action" value="gs_fiat_gas_rate_save" />
				<label><?php esc_html_e( 'Rail', 'gend-society' ); ?>
					<select name="rail" required>
						<?php foreach ( $rails as $r ) : ?>
							<option value="<?php echo esc_attr( $r ); ?>"><?php echo esc_html( $r ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label><?php esc_html_e( 'Currency', 'gend-society' ); ?>
					<input type="text" name="currency" maxlength="8" required />
				</label>
				<label><?php esc_html_e( 'DGEN per unit', 'gend-society' ); ?>
					<input type="number" step="0.00000001" min="0" name="dgen_per_unit" required />
				</label>
				<label><?php esc_html_e( 'Gas unit multiplier', 'gend-society' ); ?>
					<input type="number" step="0.0001" min="0.0001" name="gas_unit_multiplier" value="1.0000" required />
				</label>
				<label><?php esc_html_e( 'Notes', 'gend-society' ); ?>
					<input type="text" name="notes" maxlength="255" />
				</label>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Add / Replace rail', 'gend-society' ); ?></button>
			</form>
		</div>
		<?php
	}
}

/* ============================================================
   admin-post.php handlers — gs_fiat_gas_rate_save / _deactivate

   Both handlers gate on super-admin + manage_network_options +
   per-rail nonce + rail whitelist (GEND_CP_FIAT_GAS_RAILS).
   ============================================================ */

add_action( 'admin_post_gs_fiat_gas_rate_save', 'gs_fiat_gas_rate_save_handler' );
add_action( 'admin_post_gs_fiat_gas_rate_deactivate', 'gs_fiat_gas_rate_deactivate_handler' );

if ( ! function_exists( 'gs_fiat_gas_rate_save_handler' ) ) {
	function gs_fiat_gas_rate_save_handler() {
		if ( ! is_super_admin() || ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'gend-society' ), 403 );
		}
		$rail  = isset( $_POST['rail'] ) ? sanitize_text_field( wp_unslash( $_POST['rail'] ) ) : '';
		$rails = defined( 'GEND_CP_FIAT_GAS_RAILS' ) ? GEND_CP_FIAT_GAS_RAILS : array();
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		$ok    = wp_verify_nonce( $nonce, 'gs_fiat_gas_rate_save_' . $rail )
			|| wp_verify_nonce( $nonce, 'gs_fiat_gas_rate_save_new' );
		if ( ! $ok ) {
			wp_die( esc_html__( 'Bad nonce.', 'gend-society' ), 403 );
		}
		if ( ! in_array( $rail, $rails, true ) ) {
			gs_fiat_gas_rates_set_notice( 'error', __( 'Invalid rail.', 'gend-society' ) );
			wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=gs-chain-gas-rates' ) );
			exit;
		}
		$currency            = isset( $_POST['currency'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['currency'] ) ) ) : '';
		$dgen_per_unit       = isset( $_POST['dgen_per_unit'] ) ? (float) $_POST['dgen_per_unit'] : 0.0;
		$gas_unit_multiplier = isset( $_POST['gas_unit_multiplier'] ) ? (float) $_POST['gas_unit_multiplier'] : 1.0;
		$notes               = isset( $_POST['notes'] ) ? sanitize_text_field( wp_unslash( $_POST['notes'] ) ) : '';

		$result = Gend_CP_Fiat_Gas_Charger::set_rate(
			$rail,
			$currency,
			$dgen_per_unit,
			$gas_unit_multiplier,
			$notes,
			get_current_user_id()
		);
		if ( ! empty( $result['ok'] ) ) {
			gs_fiat_gas_rates_set_notice(
				'success',
				sprintf(
					/* translators: %s: rail slug just saved */
					__( 'Saved rate for %s.', 'gend-society' ),
					$rail
				)
			);
		} else {
			gs_fiat_gas_rates_set_notice(
				'error',
				sprintf(
					/* translators: %s: failure reason from set_rate */
					__( 'Failed to save: %s', 'gend-society' ),
					(string) ( $result['reason'] ?? 'unknown' )
				)
			);
		}
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=gs-chain-gas-rates' ) );
		exit;
	}
}

if ( ! function_exists( 'gs_fiat_gas_rate_deactivate_handler' ) ) {
	function gs_fiat_gas_rate_deactivate_handler() {
		if ( ! is_super_admin() || ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'gend-society' ), 403 );
		}
		$rail = isset( $_POST['rail'] ) ? sanitize_text_field( wp_unslash( $_POST['rail'] ) ) : '';
		check_admin_referer( 'gs_fiat_gas_rate_deactivate_' . $rail );
		$rails = defined( 'GEND_CP_FIAT_GAS_RAILS' ) ? GEND_CP_FIAT_GAS_RAILS : array();
		if ( ! in_array( $rail, $rails, true ) ) {
			gs_fiat_gas_rates_set_notice( 'error', __( 'Invalid rail.', 'gend-society' ) );
			wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=gs-chain-gas-rates' ) );
			exit;
		}
		$result = Gend_CP_Fiat_Gas_Charger::deactivate_rate( $rail, get_current_user_id() );
		if ( ! empty( $result['ok'] ) ) {
			gs_fiat_gas_rates_set_notice(
				'success',
				sprintf(
					/* translators: %s: rail slug just deactivated */
					__( 'Deactivated rate for %s.', 'gend-society' ),
					$rail
				)
			);
		} else {
			gs_fiat_gas_rates_set_notice(
				'error',
				sprintf(
					/* translators: %s: failure reason from deactivate_rate */
					__( 'Failed to deactivate: %s', 'gend-society' ),
					(string) ( $result['reason'] ?? 'unknown' )
				)
			);
		}
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=gs-chain-gas-rates' ) );
		exit;
	}
}

/* ============================================================
   admin_notices — render the per-user transient set by handlers
   ============================================================ */

if ( ! function_exists( 'gs_fiat_gas_rates_set_notice' ) ) {
	function gs_fiat_gas_rates_set_notice( $type, $msg ) {
		$uid = (int) get_current_user_id();
		if ( $uid <= 0 ) {
			return;
		}
		set_transient(
			'gs_fiat_gas_admin_notice_' . $uid,
			array(
				'type' => (string) $type,
				'msg'  => (string) $msg,
			),
			60
		);
	}
}

add_action( 'admin_notices', function () {
	$uid = (int) get_current_user_id();
	if ( $uid <= 0 ) {
		return;
	}
	$notice = get_transient( 'gs_fiat_gas_admin_notice_' . $uid );
	if ( ! is_array( $notice ) ) {
		return;
	}
	delete_transient( 'gs_fiat_gas_admin_notice_' . $uid );
	$cls = ( ( $notice['type'] ?? '' ) === 'success' ) ? 'notice-success' : 'notice-error';
	echo '<div class="notice ' . esc_attr( $cls ) . ' is-dismissible gs-fiat-gas-rates-notice">'
		. '<p>' . esc_html( (string) ( $notice['msg'] ?? '' ) ) . '</p>'
		. '</div>';
} );
