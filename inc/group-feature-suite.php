<?php
/**
 * Feature Suite — BuddyPress group tab renderer.
 *
 * Rendered on gend.me at /groups/<slug>/feature-suite/. Replaces the
 * wp-admin-flavoured gs_render_feature_cards_widget() that just listed
 * locally-active plugins on the hub (useless on the hub side because the
 * "linked web app" is a different install).
 *
 * Data flow:
 *   group → groupmeta gdc_membership_id  → wu_get_membership()
 *                                        → gdc_resolve_membership_dashboard_plan()
 *                                            (current plan slug, e.g. dashboard-store-owner)
 *                                        → gdc_get_feature_catalog() + gdc_feature_status_for_membership()
 *                                            (per-feature status: included | upgrade_required | addon)
 *                                        → gdc_get_container_active_plugins($install_id)
 *                                            (best-effort overlay: is the plugin actually turned on?)
 *
 * Surface:
 *   Plan badge ("You're on Store Owner — $70/mo")  +  primary upgrade CTA
 *   Filter bar (status, category, plan tier, text search)
 *   Card grid: each card carries data-* attrs the filter JS reads
 *
 * Upgrade CTAs point at gend.me's checkout
 * (/my-account/membership/<id>/?ui=embed&group=dashboard&plan=<slug>)
 * — same target the container-side feature-upgrade.php uses, since
 * we're already ON gend.me here we link instead of popping.
 */

defined('ABSPATH') || exit;

/**
 * Top-level renderer for the Feature Suite group tab. Called from
 * GS_Group_Tab_Feature_Suite::display() in group-app-tabs.php.
 */
function gs_render_group_feature_suite($group_id) {
    $group_id = (int) $group_id;
    if (!$group_id) {
        echo '<p style="color:rgba(203,213,245,0.7);">' . esc_html__('No group context — feature suite cannot resolve a membership.', 'gend-society') . '</p>';
        return;
    }

    $membership_id = function_exists('groups_get_groupmeta') ? (int) groups_get_groupmeta($group_id, 'gdc_membership_id') : 0;
    $membership    = ($membership_id && function_exists('wu_get_membership')) ? wu_get_membership($membership_id) : null;

    $current_plan_slug = ($membership && function_exists('gdc_resolve_membership_dashboard_plan'))
        ? gdc_resolve_membership_dashboard_plan($membership)
        : '';
    $plan_catalog = function_exists('gdc_get_dashboard_plan_catalog') ? gdc_get_dashboard_plan_catalog() : array();
    $current_plan = $current_plan_slug !== '' && isset($plan_catalog[$current_plan_slug]) ? $plan_catalog[$current_plan_slug] : null;

    // Linked install id → container-side plugin probe (best effort).
    $install_id = function_exists('gs_group_get_linked_install_id') ? gs_group_get_linked_install_id($group_id) : '';
    $active_on_app = function_exists('gdc_get_container_active_plugins') ? gdc_get_container_active_plugins($install_id) : array();
    $probe_available = !empty($active_on_app);
    $active_lookup = array();
    foreach ($active_on_app as $plugin_file) {
        $active_lookup[strtolower((string) $plugin_file)] = true;
    }

    $features = function_exists('gdc_get_feature_catalog') ? gdc_get_feature_catalog() : array();

    // Pre-compute per-card data so the renderer is one straight pass.
    $cards = array();
    $included_count = 0;
    $upgrade_count  = 0;
    foreach ($features as $slug => $f) {
        $resolved = function_exists('gdc_feature_status_for_membership')
            ? gdc_feature_status_for_membership($slug, $current_plan_slug)
            : array('status' => 'addon', 'unlock_plan' => '');
        $status = $resolved['status'];
        $unlock_plan = $resolved['unlock_plan'];
        $is_active_on_app = $probe_available && isset($active_lookup[strtolower((string) ($f['plugin'] ?? ''))]);

        if ($status === 'included') $included_count++;
        if ($status === 'upgrade_required') $upgrade_count++;

        // Which plan tiers carry this feature (for "Plan tier" filter).
        $tier_slugs = array();
        if (function_exists('gdc_get_plan_feature_map')) {
            foreach (gdc_get_plan_feature_map() as $plan_slug => $included) {
                if (in_array($slug, $included, true)) $tier_slugs[] = $plan_slug;
            }
        }

        $cards[] = array(
            'feature'          => $f,
            'status'           => $status,
            'unlock_plan'      => $unlock_plan,
            'unlock_plan_name' => $unlock_plan && isset($plan_catalog[$unlock_plan]) ? $plan_catalog[$unlock_plan]['name'] : '',
            'is_active_on_app' => $is_active_on_app,
            'tiers'            => $tier_slugs,
        );
    }

    $hub_base = function_exists('gs_oauth_hub_url') ? gs_oauth_hub_url() : (string) get_option('gs_gend_base_url', 'https://gend.me');
    $hub_base = $hub_base ? rtrim($hub_base, '/') : 'https://gend.me';
    $upgrade_url = function ($plan_slug) use ($hub_base, $membership_id) {
        if ($membership_id <= 0) return $hub_base . '/my-account/memberships/';
        $url = $hub_base . '/my-account/membership/' . (int) $membership_id . '/?group=dashboard';
        if ($plan_slug !== '') $url .= '&plan=' . rawurlencode($plan_slug);
        return $url;
    };

    $uid = 'gs-fs-' . $group_id;
    ?>
    <style>
        /* All styles scoped under [data-gs-fs-scope] so Youzify and
           theme CSS can't bleed in. Class names use gs-fs-* prefix to
           avoid collisions with existing dashboard surfaces. */
        [data-gs-fs-scope] {
            --fs-blue:    #6ec1e4;
            --fs-magenta: #b608c9;
            --fs-green:   #00ff88;
            --fs-amber:   #ffb446;
            --fs-glass-bg: rgba(15, 18, 24, 0.45);
            --fs-glass-border: rgba(255,255,255,0.08);
            --fs-ease: cubic-bezier(0.16, 1, 0.3, 1);

            font-family: 'Inter', system-ui, sans-serif;
            color: #fff;
            max-width: 1250px;
            margin: 0 auto;
            padding: 20px;
            box-sizing: border-box;
        }
        [data-gs-fs-scope] * { box-sizing: border-box; }

        /* ── Plan badge / hero header ────────────────────────────── */
        [data-gs-fs-scope] .gs-fs-hero {
            background: var(--fs-glass-bg);
            border: 1px solid var(--fs-glass-border);
            border-radius: 24px;
            backdrop-filter: blur(25px) saturate(160%);
            -webkit-backdrop-filter: blur(25px) saturate(160%);
            padding: 28px clamp(20px, 4vw, 36px);
            margin-bottom: 22px;
            display: grid; grid-template-columns: 1fr auto; gap: 18px;
            align-items: center;
        }
        [data-gs-fs-scope] .gs-fs-hero-head h2 {
            font-size: 1.6rem !important; font-weight: 900 !important;
            text-transform: uppercase !important; letter-spacing: -0.5px !important;
            margin: 0 0 6px 0 !important; color: #fff !important; font-family: inherit !important;
        }
        [data-gs-fs-scope] .gs-fs-hero-head .lede {
            font-size: 0.92rem; opacity: 0.6; margin: 0; color: #fff;
        }
        [data-gs-fs-scope] .gs-fs-plan-pill {
            display: inline-flex; align-items: center; gap: 8px;
            background: linear-gradient(135deg, rgba(110,193,228,0.20), rgba(182,8,201,0.12));
            border: 1px solid rgba(110,193,228,0.4);
            color: #fff;
            padding: 6px 14px; border-radius: 999px;
            font-size: 0.72rem; font-weight: 900;
            text-transform: uppercase; letter-spacing: 0.08em;
            margin-bottom: 10px;
        }
        [data-gs-fs-scope] .gs-fs-plan-pill.is-none {
            background: rgba(255,255,255,0.04);
            border-color: rgba(255,255,255,0.12);
            color: rgba(255,255,255,0.6);
        }
        [data-gs-fs-scope] .gs-fs-hero-cta {
            display: inline-flex; align-items: center; gap: 6px;
            background: linear-gradient(135deg, #b608c9, #7e058a);
            color: #fff !important; padding: 12px 22px; border-radius: 12px;
            text-decoration: none; font-size: 0.82rem; font-weight: 800;
            text-transform: uppercase; letter-spacing: 0.08em;
            box-shadow: 0 10px 30px rgba(182,8,201,0.25);
            transition: transform 0.2s var(--fs-ease);
        }
        [data-gs-fs-scope] .gs-fs-hero-cta:hover { transform: translateY(-2px); color: #fff; }
        [data-gs-fs-scope] .gs-fs-hero-cta.is-max {
            background: rgba(0,255,136,0.10);
            border: 1px solid rgba(0,255,136,0.3);
            color: var(--fs-green) !important;
            box-shadow: none;
            cursor: default;
        }
        [data-gs-fs-scope] .gs-fs-hero-meta {
            display: flex; gap: 18px; margin-top: 14px; flex-wrap: wrap;
        }
        [data-gs-fs-scope] .gs-fs-hero-meta div {
            font-size: 0.78rem; color: rgba(255,255,255,0.55);
        }
        [data-gs-fs-scope] .gs-fs-hero-meta strong { color: #fff; font-weight: 800; }

        /* ── Filter bar ──────────────────────────────────────────── */
        [data-gs-fs-scope] .gs-fs-filterbar {
            background: var(--fs-glass-bg);
            border: 1px solid var(--fs-glass-border);
            border-radius: 18px;
            padding: 16px 18px;
            margin-bottom: 22px;
            display: flex; flex-direction: column; gap: 14px;
        }
        [data-gs-fs-scope] .gs-fs-filterrow {
            display: flex; flex-wrap: wrap; align-items: center; gap: 8px;
        }
        [data-gs-fs-scope] .gs-fs-filterlabel {
            font-size: 0.65rem; font-weight: 900; letter-spacing: 1.4px;
            text-transform: uppercase; color: rgba(255,255,255,0.45);
            margin-right: 6px;
        }
        [data-gs-fs-scope] .gs-fs-pill {
            border: 1px solid rgba(255,255,255,0.10);
            background: rgba(255,255,255,0.03);
            color: rgba(255,255,255,0.7);
            padding: 7px 14px; border-radius: 999px;
            font-size: 0.78rem; font-weight: 700;
            cursor: pointer; transition: all 0.2s var(--fs-ease);
            display: inline-flex; align-items: center; gap: 6px;
        }
        [data-gs-fs-scope] .gs-fs-pill:hover { background: rgba(255,255,255,0.08); color: #fff; }
        [data-gs-fs-scope] .gs-fs-pill.is-active {
            background: linear-gradient(135deg, rgba(110,193,228,0.24), rgba(182,8,201,0.16));
            border-color: rgba(110,193,228,0.45);
            color: #fff;
        }
        [data-gs-fs-scope] .gs-fs-pill .gs-fs-pill-count {
            background: rgba(255,255,255,0.10);
            color: #fff;
            font-size: 0.68rem; font-weight: 800;
            padding: 1px 7px; border-radius: 999px;
        }
        [data-gs-fs-scope] .gs-fs-search {
            background: rgba(0,0,0,0.30);
            border: 1px solid rgba(255,255,255,0.10);
            color: #fff;
            border-radius: 10px;
            padding: 9px 12px;
            font-size: 0.85rem;
            width: 100%; max-width: 320px;
        }
        [data-gs-fs-scope] .gs-fs-search:focus { outline: 0; border-color: rgba(110,193,228,0.55); }
        [data-gs-fs-scope] .gs-fs-search::placeholder { color: rgba(255,255,255,0.4); }

        /* ── Card grid ───────────────────────────────────────────── */
        [data-gs-fs-scope] .gs-fs-grid {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 22px;
        }
        @media (max-width: 1024px) { [data-gs-fs-scope] .gs-fs-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 640px)  { [data-gs-fs-scope] .gs-fs-grid { grid-template-columns: 1fr; } }

        [data-gs-fs-scope] .gs-fs-card {
            position: relative;
            background: var(--fs-glass-bg);
            border: 1px solid var(--fs-glass-border);
            border-radius: 18px;
            overflow: hidden;
            display: flex; flex-direction: column;
            transition: transform 0.2s var(--fs-ease), border-color 0.2s var(--fs-ease), box-shadow 0.2s var(--fs-ease);
        }
        [data-gs-fs-scope] .gs-fs-card:hover {
            transform: translateY(-3px);
            border-color: rgba(255,255,255,0.18);
            box-shadow: 0 18px 40px rgba(0,0,0,0.35);
        }
        [data-gs-fs-scope] .gs-fs-card.is-locked { opacity: 0.92; }
        [data-gs-fs-scope] .gs-fs-card[hidden] { display: none !important; }

        [data-gs-fs-scope] .gs-fs-card-img {
            width: 100%; height: 160px; object-fit: cover; object-position: top center;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        [data-gs-fs-scope] .gs-fs-card-status {
            position: absolute; top: 12px; right: 12px;
            display: inline-flex; align-items: center; gap: 5px;
            padding: 5px 11px; border-radius: 999px;
            font-size: 0.66rem; font-weight: 900;
            text-transform: uppercase; letter-spacing: 0.08em;
            backdrop-filter: blur(8px);
        }
        [data-gs-fs-scope] .gs-fs-card-status.included { background: rgba(16,185,129,0.82); color: #fff; }
        [data-gs-fs-scope] .gs-fs-card-status.upgrade  { background: rgba(255,180,70,0.85); color: #1a1003; }
        [data-gs-fs-scope] .gs-fs-card-status.addon    { background: rgba(182,8,201,0.85); color: #fff; }

        [data-gs-fs-scope] .gs-fs-card-body { padding: 22px; display: flex; flex-direction: column; gap: 10px; flex: 1; }
        [data-gs-fs-scope] .gs-fs-card-title {
            margin: 0; color: #fff; font-size: 1.12rem; font-weight: 800;
        }
        [data-gs-fs-scope] .gs-fs-card-desc {
            margin: 0; font-size: 0.86rem; line-height: 1.5;
            color: rgba(255,255,255,0.62); flex: 1;
        }
        [data-gs-fs-scope] .gs-fs-card-sub {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 0.7rem; font-weight: 700;
            color: rgba(255,255,255,0.5);
            text-transform: uppercase; letter-spacing: 0.06em;
        }
        [data-gs-fs-scope] .gs-fs-card-sub .dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
        [data-gs-fs-scope] .gs-fs-card-sub.is-on  { color: var(--fs-green); }
        [data-gs-fs-scope] .gs-fs-card-sub.is-off { color: rgba(255,255,255,0.4); }

        [data-gs-fs-scope] .gs-fs-card-actions {
            display: flex; flex-wrap: wrap; gap: 8px; margin-top: 6px;
        }
        [data-gs-fs-scope] .gs-fs-btn {
            flex: 1; min-width: 0;
            display: inline-flex; align-items: center; justify-content: center;
            padding: 10px 14px; border-radius: 10px;
            font-size: 0.78rem; font-weight: 800;
            text-transform: uppercase; letter-spacing: 0.06em;
            border: 1px solid rgba(255,255,255,0.10);
            background: rgba(255,255,255,0.04);
            color: #fff; text-decoration: none; cursor: pointer;
            transition: transform 0.18s var(--fs-ease), background 0.18s var(--fs-ease), border-color 0.18s var(--fs-ease);
        }
        [data-gs-fs-scope] .gs-fs-btn:hover { background: rgba(255,255,255,0.08); transform: translateY(-1px); color: #fff; }
        [data-gs-fs-scope] .gs-fs-btn.is-primary {
            background: linear-gradient(135deg, #b608c9, #7e058a);
            border-color: transparent;
        }
        [data-gs-fs-scope] .gs-fs-btn.is-primary:hover { filter: brightness(1.1); }
        [data-gs-fs-scope] .gs-fs-btn.is-secondary {
            border-color: rgba(110,193,228,0.35);
            color: var(--fs-blue);
        }
        [data-gs-fs-scope] .gs-fs-btn-icon {
            flex: 0 0 40px; min-width: 0; padding: 0 0; width: 40px; height: 38px;
        }

        [data-gs-fs-scope] .gs-fs-empty {
            text-align: center; padding: 40px;
            background: var(--fs-glass-bg);
            border: 1px dashed rgba(255,255,255,0.15);
            border-radius: 16px;
            color: rgba(255,255,255,0.55);
        }
    </style>

    <div data-gs-fs-scope id="<?php echo esc_attr($uid); ?>">

        <?php /* ── Hero / plan badge ──────────────────────────────── */ ?>
        <section class="gs-fs-hero">
            <div class="gs-fs-hero-head">
                <?php if ($current_plan) : ?>
                    <span class="gs-fs-plan-pill">
                        ● <?php echo esc_html(sprintf(__('Current plan: %s', 'gend-society'), $current_plan['name'])); ?>
                    </span>
                <?php else : ?>
                    <span class="gs-fs-plan-pill is-none">
                        ○ <?php esc_html_e('No dashboard plan detected', 'gend-society'); ?>
                    </span>
                <?php endif; ?>
                <h2><?php esc_html_e('Feature Suite', 'gend-society'); ?></h2>
                <p class="lede">
                    <?php
                    if ($current_plan) {
                        echo esc_html(sprintf(
                            /* translators: %d included count, %d upgrade-needed count */
                            __('%1$d features included on your plan, %2$d more available with an upgrade.', 'gend-society'),
                            $included_count,
                            $upgrade_count
                        ));
                    } else {
                        esc_html_e('Choose a dashboard plan to unlock features for the linked web app.', 'gend-society');
                    }
                    ?>
                </p>
                <div class="gs-fs-hero-meta">
                    <?php if ($current_plan) : ?>
                        <div>
                            <?php
                            $amt = (float) $current_plan['amount'];
                            $curr = (string) ($current_plan['currency'] ?? 'USD');
                            $price_label = $amt > 0
                                ? '$' . number_format($amt, 2) . ' ' . $curr . ' / mo'
                                : __('Included', 'gend-society');
                            ?>
                            <strong><?php echo esc_html($price_label); ?></strong> <?php esc_html_e('plan rate', 'gend-society'); ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($install_id !== '') : ?>
                        <div>
                            <strong><?php esc_html_e('Linked', 'gend-society'); ?></strong>
                            <?php esc_html_e('to web app', 'gend-society'); ?>
                            <code style="font-family:ui-monospace,Menlo,monospace; font-size:0.72rem; opacity:0.6;"><?php echo esc_html(substr($install_id, 0, 8)); ?>…</code>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php
            // Hero CTA: next-tier upgrade unless already on the top tier.
            $order = function_exists('gdc_get_dashboard_plan_tier_order') ? gdc_get_dashboard_plan_tier_order() : array();
            $cur_idx = $current_plan_slug ? array_search($current_plan_slug, $order, true) : false;
            $next_slug = '';
            if ($cur_idx === false && !empty($order)) {
                $next_slug = $order[0];
            } elseif ($cur_idx !== false && isset($order[$cur_idx + 1])) {
                $next_slug = $order[$cur_idx + 1];
            }
            if ($next_slug && isset($plan_catalog[$next_slug])) :
                ?>
                <a class="gs-fs-hero-cta" href="<?php echo esc_url($upgrade_url($next_slug)); ?>" target="_blank" rel="noopener">
                    <?php echo esc_html(sprintf(__('Upgrade to %s →', 'gend-society'), $plan_catalog[$next_slug]['name'])); ?>
                </a>
            <?php else : ?>
                <span class="gs-fs-hero-cta is-max">✓ <?php esc_html_e('On the top tier', 'gend-society'); ?></span>
            <?php endif; ?>
        </section>

        <?php /* ── Filter bar ────────────────────────────────────── */ ?>
        <section class="gs-fs-filterbar">
            <div class="gs-fs-filterrow">
                <span class="gs-fs-filterlabel"><?php esc_html_e('Status', 'gend-society'); ?></span>
                <button type="button" class="gs-fs-pill is-active" data-gs-filter="status" data-value="all"><?php esc_html_e('All', 'gend-society'); ?> <span class="gs-fs-pill-count"><?php echo (int) count($cards); ?></span></button>
                <button type="button" class="gs-fs-pill" data-gs-filter="status" data-value="included"><?php esc_html_e('Included', 'gend-society'); ?> <span class="gs-fs-pill-count"><?php echo (int) $included_count; ?></span></button>
                <button type="button" class="gs-fs-pill" data-gs-filter="status" data-value="upgrade_required"><?php esc_html_e('Upgrade unlocks', 'gend-society'); ?> <span class="gs-fs-pill-count"><?php echo (int) $upgrade_count; ?></span></button>
                <button type="button" class="gs-fs-pill" data-gs-filter="status" data-value="addon"><?php esc_html_e('Add-on', 'gend-society'); ?> <span class="gs-fs-pill-count"><?php echo (int) max(0, count($cards) - $included_count - $upgrade_count); ?></span></button>
            </div>

            <div class="gs-fs-filterrow">
                <span class="gs-fs-filterlabel"><?php esc_html_e('Category', 'gend-society'); ?></span>
                <?php
                $categories = array();
                foreach ($cards as $c) {
                    $cat = (string) ($c['feature']['category'] ?? 'dashboard');
                    $categories[$cat] = ($categories[$cat] ?? 0) + 1;
                }
                ?>
                <button type="button" class="gs-fs-pill is-active" data-gs-filter="category" data-value="all"><?php esc_html_e('All', 'gend-society'); ?></button>
                <?php foreach ($categories as $cat => $count) : ?>
                    <button type="button" class="gs-fs-pill" data-gs-filter="category" data-value="<?php echo esc_attr($cat); ?>">
                        <?php echo esc_html(ucfirst($cat)); ?>
                        <span class="gs-fs-pill-count"><?php echo (int) $count; ?></span>
                    </button>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($plan_catalog)) : ?>
                <div class="gs-fs-filterrow">
                    <span class="gs-fs-filterlabel"><?php esc_html_e('Plan tier', 'gend-society'); ?></span>
                    <button type="button" class="gs-fs-pill is-active" data-gs-filter="tier" data-value="all"><?php esc_html_e('Any', 'gend-society'); ?></button>
                    <?php foreach ($plan_catalog as $slug => $plan) : ?>
                        <button type="button" class="gs-fs-pill" data-gs-filter="tier" data-value="<?php echo esc_attr($slug); ?>">
                            <?php echo esc_html($plan['name']); ?>
                            <span class="gs-fs-pill-count"><?php echo (int) count($plan['features']); ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="gs-fs-filterrow">
                <span class="gs-fs-filterlabel"><?php esc_html_e('Search', 'gend-society'); ?></span>
                <input type="search" class="gs-fs-search" placeholder="<?php esc_attr_e('Filter by name or description…', 'gend-society'); ?>" data-gs-fs-search />
            </div>
        </section>

        <?php /* ── Card grid ─────────────────────────────────────── */ ?>
        <section class="gs-fs-grid" data-gs-fs-grid>
            <?php foreach ($cards as $card) :
                $f = $card['feature'];
                $status = $card['status'];
                $is_active_on_app = $card['is_active_on_app'];
                $unlock_plan_name = $card['unlock_plan_name'];
                $unlock_plan = $card['unlock_plan'];
                $tiers_attr = implode(',', $card['tiers']);
                $haystack = strtolower($f['name'] . ' ' . $f['description']);

                $status_label = '';
                $status_class = '';
                switch ($status) {
                    case 'included':
                        $status_label = __('Included', 'gend-society');
                        $status_class = 'included';
                        break;
                    case 'upgrade_required':
                        $status_label = __('Upgrade', 'gend-society');
                        $status_class = 'upgrade';
                        break;
                    case 'addon':
                        $status_label = __('Add-on', 'gend-society');
                        $status_class = 'addon';
                        break;
                }
                ?>
                <article class="gs-fs-card <?php echo $status !== 'included' ? 'is-locked' : ''; ?>"
                         data-status="<?php echo esc_attr($status); ?>"
                         data-category="<?php echo esc_attr((string) ($f['category'] ?? 'dashboard')); ?>"
                         data-tiers="<?php echo esc_attr($tiers_attr); ?>"
                         data-haystack="<?php echo esc_attr($haystack); ?>">

                    <span class="gs-fs-card-status <?php echo esc_attr($status_class); ?>"><?php echo esc_html($status_label); ?></span>

                    <?php if (!empty($f['image'])) : ?>
                        <img src="<?php echo esc_url($f['image']); ?>" alt="<?php echo esc_attr($f['name']); ?>" class="gs-fs-card-img" loading="lazy" />
                    <?php endif; ?>

                    <div class="gs-fs-card-body">
                        <h3 class="gs-fs-card-title"><?php echo esc_html($f['name']); ?></h3>
                        <p class="gs-fs-card-desc"><?php echo esc_html($f['description']); ?></p>

                        <?php if ($probe_available) : ?>
                            <span class="gs-fs-card-sub <?php echo $is_active_on_app ? 'is-on' : 'is-off'; ?>">
                                <span class="dot"></span>
                                <?php
                                if ($is_active_on_app) {
                                    esc_html_e('Active on linked web app', 'gend-society');
                                } else {
                                    esc_html_e('Not installed on linked web app', 'gend-society');
                                }
                                ?>
                            </span>
                        <?php endif; ?>

                        <div class="gs-fs-card-actions">
                            <?php if ($status === 'included') : ?>
                                <?php
                                $app_admin = '';
                                if ($install_id !== '' && function_exists('gs_group_get_linked_install_id')) {
                                    // Resolve the linked site URL via groupmeta lookup again — gs_group_tab_open
                                    // already does this; we duplicate the read here so the CTA points at the
                                    // customer's wp-admin instead of the marketing page.
                                    $mid = (int) groups_get_groupmeta($group_id, 'gdc_membership_id');
                                    if ($mid && function_exists('wu_get_membership')) {
                                        $mem = wu_get_membership($mid);
                                        if ($mem && method_exists($mem, 'get_sites')) {
                                            $sites = $mem->get_sites();
                                            if (!empty($sites[0]) && method_exists($sites[0], 'get_active_site_url')) {
                                                $app_admin = (string) $sites[0]->get_active_site_url();
                                            }
                                        }
                                    }
                                }
                                if ($app_admin) :
                                    ?>
                                    <a class="gs-fs-btn is-secondary" href="<?php echo esc_url(trailingslashit($app_admin) . 'wp-admin/plugins.php'); ?>" target="_blank" rel="noopener">
                                        <?php esc_html_e('Manage in app', 'gend-society'); ?>
                                    </a>
                                <?php else : ?>
                                    <span class="gs-fs-btn" style="opacity:0.55; cursor:default;">
                                        ✓ <?php esc_html_e('Included on plan', 'gend-society'); ?>
                                    </span>
                                <?php endif; ?>
                            <?php elseif ($status === 'upgrade_required') : ?>
                                <a class="gs-fs-btn is-primary" href="<?php echo esc_url($upgrade_url($unlock_plan)); ?>" target="_blank" rel="noopener">
                                    <?php echo esc_html(sprintf(__('Upgrade to %s', 'gend-society'), $unlock_plan_name)); ?>
                                </a>
                            <?php else : ?>
                                <a class="gs-fs-btn is-primary" href="<?php echo esc_url($upgrade_url('')); ?>" target="_blank" rel="noopener">
                                    <?php esc_html_e('Add to plan', 'gend-society'); ?>
                                </a>
                            <?php endif; ?>

                            <?php if (!empty($f['link'])) : ?>
                                <a class="gs-fs-btn gs-fs-btn-icon" href="<?php echo esc_url($f['link']); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e('More info', 'gend-society'); ?>">
                                    <span class="dashicons dashicons-info"></span>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>

        <div class="gs-fs-empty" data-gs-fs-empty hidden>
            <?php esc_html_e('No features match the current filters.', 'gend-society'); ?>
        </div>

    </div>

    <script>
    (function () {
        var root = document.getElementById('<?php echo esc_js($uid); ?>');
        if (!root) return;
        var grid  = root.querySelector('[data-gs-fs-grid]');
        var empty = root.querySelector('[data-gs-fs-empty]');
        var search = root.querySelector('[data-gs-fs-search]');
        if (!grid) return;

        var state = { status: 'all', category: 'all', tier: 'all', q: '' };

        function applyFilters() {
            var q = state.q.trim().toLowerCase();
            var visible = 0;
            grid.querySelectorAll('.gs-fs-card').forEach(function (card) {
                var match = true;
                if (state.status   !== 'all' && card.getAttribute('data-status')   !== state.status)   match = false;
                if (state.category !== 'all' && card.getAttribute('data-category') !== state.category) match = false;
                if (state.tier     !== 'all') {
                    var tiers = (card.getAttribute('data-tiers') || '').split(',');
                    if (tiers.indexOf(state.tier) === -1) match = false;
                }
                if (q !== '') {
                    var hay = card.getAttribute('data-haystack') || '';
                    if (hay.indexOf(q) === -1) match = false;
                }
                card.hidden = !match;
                if (match) visible++;
            });
            if (empty) empty.hidden = (visible !== 0);
        }

        root.querySelectorAll('[data-gs-filter]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var group = btn.getAttribute('data-gs-filter');
                var value = btn.getAttribute('data-value');
                if (!group || value == null) return;
                state[group] = value;
                root.querySelectorAll('[data-gs-filter="' + group + '"]').forEach(function (other) {
                    other.classList.toggle('is-active', other === btn);
                });
                applyFilters();
            });
        });
        if (search) {
            search.addEventListener('input', function () { state.q = search.value || ''; applyFilters(); });
        }
    })();
    </script>
    <?php
}
