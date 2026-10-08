<?php
/**
 * SEO title + meta description for pages, posts and other public content.
 *
 * The network had no SEO plugin after SmartCrawl was removed, so no page
 * had a meta description or social-share tags. This adds:
 *
 *   - an "SEO" box on the editor of every public post type (title +
 *     description, with length counters), stored as _gs_seo_title and
 *     _gs_seo_description (also exposed in the REST API, so Leo can set
 *     them with its seo.update action);
 *   - the SEO title as the browser/search title;
 *   - <meta name="description">, Open Graph and Twitter card tags, falling
 *     back to the excerpt / page text, the site tagline or the term
 *     description when no SEO description is set.
 *
 * Member profiles are skipped: Youzify prints its own Open Graph tags there.
 *
 * @package GenD_Society
 */

defined('ABSPATH') || exit;

const GS_SEO_TITLE_KEY = '_gs_seo_title';
const GS_SEO_DESC_KEY  = '_gs_seo_description';

function gs_seo_post_types() {
    $types = array_values(array_diff(get_post_types(array('public' => true)), array('attachment')));
    return (array) apply_filters('gs_seo_post_types', $types);
}

add_action('init', function () {
    foreach (gs_seo_post_types() as $type) {
        foreach (array(GS_SEO_TITLE_KEY, GS_SEO_DESC_KEY) as $key) {
            register_post_meta($type, $key, array(
                'type'              => 'string',
                'single'            => true,
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_text_field',
                'auth_callback'     => function ($allowed, $meta_key, $post_id) {
                    return current_user_can('edit_post', $post_id);
                },
            ));
        }
    }
}, 20);

/* ---------------------------------------------------------------- editor */

add_action('add_meta_boxes', function () {
    foreach (gs_seo_post_types() as $type) {
        add_meta_box('gs-seo', __('SEO', 'gend-society'), 'gs_seo_render_box', $type, 'normal', 'default');
    }
});

function gs_seo_render_box($post) {
    wp_nonce_field('gs_seo_save', 'gs_seo_nonce');
    $title = (string) get_post_meta($post->ID, GS_SEO_TITLE_KEY, true);
    $desc  = (string) get_post_meta($post->ID, GS_SEO_DESC_KEY, true);
    ?>
    <p>
        <label for="gs-seo-title"><strong><?php esc_html_e('SEO title', 'gend-society'); ?></strong>
        <span class="gs-seo-count" data-for="gs-seo-title" data-max="60"></span></label><br>
        <input type="text" id="gs-seo-title" name="gs_seo_title" value="<?php echo esc_attr($title); ?>" class="widefat" placeholder="<?php echo esc_attr(get_the_title($post)); ?>">
        <span class="description"><?php esc_html_e('Shown in search results and the browser tab. Leave empty to use the page title. About 60 characters.', 'gend-society'); ?></span>
    </p>
    <p>
        <label for="gs-seo-description"><strong><?php esc_html_e('Meta description', 'gend-society'); ?></strong>
        <span class="gs-seo-count" data-for="gs-seo-description" data-max="160"></span></label><br>
        <textarea id="gs-seo-description" name="gs_seo_description" rows="3" class="widefat"><?php echo esc_textarea($desc); ?></textarea>
        <span class="description"><?php esc_html_e('The summary search engines and social shares show. Leave empty to use the excerpt. About 155-160 characters.', 'gend-society'); ?></span>
    </p>
    <script>
    (function () {
        document.querySelectorAll('.gs-seo-count').forEach(function (el) {
            var field = document.getElementById(el.getAttribute('data-for'));
            var max = parseInt(el.getAttribute('data-max'), 10);
            if (!field) { return; }
            var update = function () {
                var n = field.value.length;
                el.textContent = ' (' + n + '/' + max + ')';
                el.style.color = n > max ? '#b32d2e' : '#646970';
            };
            field.addEventListener('input', update);
            update();
        });
    })();
    </script>
    <?php
}

add_action('save_post', function ($post_id) {
    if (!isset($_POST['gs_seo_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['gs_seo_nonce'])), 'gs_seo_save')) {
        return;
    }
    if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($post_id) || !current_user_can('edit_post', $post_id)) {
        return;
    }
    foreach (array('gs_seo_title' => GS_SEO_TITLE_KEY, 'gs_seo_description' => GS_SEO_DESC_KEY) as $field => $key) {
        $value = isset($_POST[$field]) ? sanitize_text_field(wp_unslash($_POST[$field])) : '';
        if ($value === '') {
            delete_post_meta($post_id, $key);
        } else {
            update_post_meta($post_id, $key, $value);
        }
    }
});

/* -------------------------------------------------------------- frontend */

// Youzify prints "profile" Open Graph tags (with an empty og:url) on every
// page; keep them on member profiles only, where this file prints nothing.
add_filter('youzify_display_open_graph_tags', function ($show) {
    return (function_exists('bp_is_user') && bp_is_user()) ? $show : false;
}, 20);

add_filter('pre_get_document_title', function ($title) {
    if (is_singular()) {
        $seo = trim((string) get_post_meta(get_queried_object_id(), GS_SEO_TITLE_KEY, true));
        if ($seo !== '') {
            return $seo;
        }
    }
    return $title;
}, 20);

/**
 * Plain-text summary of at most $max characters.
 */
function gs_seo_trim($text, $max = 160) {
    $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags(strip_shortcodes(html_entity_decode((string) $text, ENT_QUOTES, 'UTF-8')), true)));
    if (mb_strlen($text) <= $max) {
        return $text;
    }
    $cut = mb_substr($text, 0, $max - 1);
    $space = mb_strrpos($cut, ' ');
    return rtrim($space > 80 ? mb_substr($cut, 0, $space) : $cut, " ,.;:-") . '…';
}

/**
 * @return array{title:string,description:string,url:string,image:string,type:string}
 */
function gs_seo_current() {
    $out = array('title' => wp_get_document_title(), 'description' => '', 'url' => '', 'image' => '', 'type' => 'website');

    if (is_singular()) {
        $post = get_queried_object();
        $desc = trim((string) get_post_meta($post->ID, GS_SEO_DESC_KEY, true));
        if ($desc === '' && is_front_page() && get_bloginfo('description') !== '') {
            // A static front page's text usually starts with section labels;
            // the site tagline is a better summary.
            $desc = get_bloginfo('description');
        }
        if ($desc === '') {
            $desc = has_excerpt($post) ? $post->post_excerpt : $post->post_content;
        }
        $out['description'] = gs_seo_trim($desc);
        $out['url'] = (string) get_permalink($post);
        $out['type'] = $post->post_type === 'post' ? 'article' : 'website';
        if (has_post_thumbnail($post)) {
            $out['image'] = (string) get_the_post_thumbnail_url($post, 'large');
        }
    } elseif (is_front_page() || is_home()) {
        $out['description'] = gs_seo_trim(get_bloginfo('description'));
        $out['url'] = home_url('/');
    } elseif (is_category() || is_tag() || is_tax()) {
        $out['description'] = gs_seo_trim(term_description());
        $link = get_term_link(get_queried_object());
        $out['url'] = is_wp_error($link) ? '' : (string) $link;
    }

    if ($out['image'] === '' && has_site_icon()) {
        $out['image'] = (string) get_site_icon_url(512);
    }
    return (array) apply_filters('gs_seo_current', $out);
}

add_action('wp_head', function () {
    if (is_admin() || is_feed() || is_404() || (function_exists('bp_is_user') && bp_is_user())) {
        return;
    }
    $seo = gs_seo_current();
    $tags = array();
    if ($seo['description'] !== '') {
        $tags[] = sprintf('<meta name="description" content="%s">', esc_attr($seo['description']));
    }
    $tags[] = sprintf('<meta property="og:site_name" content="%s">', esc_attr(get_bloginfo('name')));
    $tags[] = sprintf('<meta property="og:type" content="%s">', esc_attr($seo['type']));
    $tags[] = sprintf('<meta property="og:title" content="%s">', esc_attr($seo['title']));
    if ($seo['description'] !== '') {
        $tags[] = sprintf('<meta property="og:description" content="%s">', esc_attr($seo['description']));
    }
    if ($seo['url'] !== '') {
        $tags[] = sprintf('<meta property="og:url" content="%s">', esc_url($seo['url']));
    }
    if ($seo['image'] !== '') {
        $tags[] = sprintf('<meta property="og:image" content="%s">', esc_url($seo['image']));
    }
    $tags[] = sprintf('<meta name="twitter:card" content="%s">', $seo['image'] !== '' ? 'summary_large_image' : 'summary');
    $tags[] = sprintf('<meta name="twitter:title" content="%s">', esc_attr($seo['title']));
    if ($seo['description'] !== '') {
        $tags[] = sprintf('<meta name="twitter:description" content="%s">', esc_attr($seo['description']));
    }
    echo "\n<!-- GenD SEO -->\n" . implode("\n", $tags) . "\n";
}, 1);
