<?php
/**
 * Completed Contracts — Overview › Completed Contracts sub-tab.
 *
 * Lists every completed task contract the displayed member has delivered:
 *   1. Task contracts (authoritative) — completed PSOO tasks assigned to the
 *      member. This same pm_meta store backs both the projects dashboard and
 *      the sales-team "smart contracts" view, so it covers both surfaces.
 *      Rendered via the projects plugin's own panel renderer.
 *   2. Sales proposals — completed sales-team proposals the member authored
 *      (acted as the salesperson on), whose linked WooCommerce order(s) cleared.
 *
 * Every cross-plugin call is guarded so the panel degrades gracefully when a
 * plugin is inactive. Rendered by gdc_render_completed_contracts_panel(), called
 * from the Overview orchestrator in member-profile-pages.php.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Render the Completed Contracts panel for a displayed member.
 */
function gdc_render_completed_contracts_panel( $displayed_user_id ) {
    $displayed_user_id = (int) $displayed_user_id;
    if ( ! $displayed_user_id ) return;

    echo '<div class="gdc-contracts">';

    // ── 1. Task contracts (projects / PSOO) ─────────────────────────────────
    if ( class_exists( 'PSOO_PM_Tasks' ) && function_exists( 'psoo_render_vendor_completed_contracts_panel' ) ) {
        $tasks = PSOO_PM_Tasks::get_for_user( $displayed_user_id, array( 'status' => '1', 'per_page' => 100 ) );
        if ( ! is_array( $tasks ) ) $tasks = array();
        // psoo_render_vendor_completed_contracts_panel() returns a full HTML
        // string (its own styled header + table, incl. an empty state).
        echo psoo_render_vendor_completed_contracts_panel( $displayed_user_id, $tasks ); // phpcs:ignore WordPress.Security.EscapeOutput
    } else {
        echo '<p class="gs-portfolio-empty">' . esc_html__( 'Task contract history is unavailable — the projects plugin is required.', 'gend-society' ) . '</p>';
    }

    // ── 2. Completed sales proposals (sales-team) ────────────────────────────
    $sales = gdc_get_user_completed_sales_proposals( $displayed_user_id );
    if ( ! empty( $sales ) ) {
        ?>
        <section class="gdc-contracts-sales">
            <header class="gdc-contracts-sales-head">
                <h3><?php esc_html_e( 'Completed Sales Contracts', 'gend-society' ); ?></h3>
                <span class="gdc-contracts-pill"><?php printf( esc_html__( '%s completed', 'gend-society' ), esc_html( number_format_i18n( count( $sales ) ) ) ); ?></span>
            </header>
            <div class="gdc-contracts-sales-list">
                <?php foreach ( $sales as $row ) : ?>
                    <div class="gdc-contracts-sales-item">
                        <div class="gdc-contracts-sales-title">
                            <?php if ( ! empty( $row['url'] ) ) : ?>
                                <a href="<?php echo esc_url( $row['url'] ); ?>"><?php echo esc_html( $row['title'] ); ?></a>
                            <?php else : ?>
                                <?php echo esc_html( $row['title'] ); ?>
                            <?php endif; ?>
                        </div>
                        <span class="gdc-contracts-sales-badge"><?php esc_html_e( 'Completed', 'gend-society' ); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
    }

    echo '</div>';

    gdc_contracts_panel_styles();
}

/**
 * Completed sales-team proposals authored by the member. A proposal is
 * "completed" when ST_Proposals::stp_get_proposal_banner_data() reports its
 * aggregate linked-order status as 'completed'.
 *
 * @return array<int,array{title:string,url:string,id:int}>
 */
function gdc_get_user_completed_sales_proposals( $user_id ) {
    $user_id = (int) $user_id;
    if ( ! $user_id ) return array();
    if ( ! post_type_exists( 'st_proposal' ) ) return array();
    if ( ! class_exists( 'ST_Proposals' ) || ! method_exists( 'ST_Proposals', 'stp_get_proposal_banner_data' ) ) {
        return array();
    }

    $ids = get_posts( array(
        'post_type'      => 'st_proposal',
        'author'         => $user_id,
        'post_status'    => 'publish',
        'posts_per_page' => 100,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ) );
    if ( empty( $ids ) ) return array();

    $out = array();
    foreach ( $ids as $id ) {
        $data = ST_Proposals::stp_get_proposal_banner_data( $id );
        if ( is_array( $data ) && isset( $data['status'] ) && $data['status'] === 'completed' ) {
            $out[] = array(
                'id'    => (int) $id,
                'title' => isset( $data['title'] ) && $data['title'] !== '' ? (string) $data['title'] : get_the_title( $id ),
                'url'   => (string) get_permalink( $id ),
            );
        }
    }
    return $out;
}

/**
 * Styling for the sales-contracts section appended below the PSOO panel.
 */
function gdc_contracts_panel_styles() {
    static $done = false;
    if ( $done ) return;
    $done = true;
    ?>
    <style>
    .gdc-contracts-sales { max-width:1200px; margin:0 auto; padding:0 20px 40px; font-family:"Inter",sans-serif; }
    .gdc-contracts-sales-head { display:flex; align-items:center; gap:14px; margin-bottom:18px; }
    .gdc-contracts-sales-head h3 { color:#fff; font-size:1.1rem; font-weight:800; margin:0; letter-spacing:0.5px; }
    .gdc-contracts-pill { background:rgba(182,8,201,0.12); border:1px solid rgba(182,8,201,0.4); color:#d56ee0; font-size:0.72rem; font-weight:700; letter-spacing:1px; text-transform:uppercase; padding:5px 12px; border-radius:100px; }
    .gdc-contracts-sales-list { display:flex; flex-direction:column; gap:10px; }
    .gdc-contracts-sales-item { display:flex; align-items:center; justify-content:space-between; gap:14px; background:rgba(11,14,20,0.45); border:1px solid rgba(255,255,255,0.08); border-radius:14px; padding:16px 18px; }
    .gdc-contracts-sales-title a { color:#f8fafc; text-decoration:none; font-weight:700; }
    .gdc-contracts-sales-title a:hover { color:#cc1ee1; }
    .gdc-contracts-sales-title { color:#f8fafc; font-weight:700; }
    .gdc-contracts-sales-badge { flex:0 0 auto; background:rgba(34,197,94,0.12); border:1px solid rgba(34,197,94,0.4); color:#4ade80; font-size:0.7rem; font-weight:800; letter-spacing:1px; text-transform:uppercase; padding:5px 12px; border-radius:100px; }
    </style>
    <?php
}
