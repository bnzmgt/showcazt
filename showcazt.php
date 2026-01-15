<?php
/*
Plugin Name:  Showcazt
Description:  Simple plugin to manage affiliate products
Version:      1.0.0
Author:       Distrapps
License:      GPL2
License URI:  https://www.gnu.org/licenses/gpl-2.0.html
*/

// =====================
// Activation: create custom table
// =====================
function showcazt_activate() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // Product table
    $products_table = $wpdb->prefix . 'showcazt_products';
    $sql_products = "CREATE TABLE IF NOT EXISTS $products_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        product_id bigint(20) NOT NULL,
        affiliate_link text NOT NULL,
        price varchar(100) DEFAULT '' NOT NULL,
        images longtext,
        is_active tinyint(1) NOT NULL DEFAULT 1,
        PRIMARY KEY  (id),
        KEY product_id (product_id)
    ) $charset_collate;";

    // Click tracking table
    $clicks_table = $wpdb->prefix . 'showcazt_clicks';
    $sql_clicks = "CREATE TABLE IF NOT EXISTS $clicks_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        product_id bigint(20) NOT NULL,
        clicked_at datetime NOT NULL,
        PRIMARY KEY (id),
        KEY product_id (product_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_products);
    dbDelta($sql_clicks);
}
register_activation_hook(__FILE__, 'showcazt_activate');

// =====================
// Helpers (hybrid storage)
// =====================
function showcazt_update_product_data($post_id, $link, $price, $images) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'showcazt_products';

    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table_name WHERE product_id = %d",
        $post_id
    ));

    if ($exists) {
        $wpdb->update(
            $table_name,
            [
                'affiliate_link' => $link,
                'price'          => $price,
                'images'         => $images,
            ],
            [ 'product_id' => $post_id ],
            [ '%s', '%s', '%s' ],
            [ '%d' ]
        );
    } else {
        $wpdb->insert(
            $table_name,
            [
                'product_id'     => $post_id,
                'affiliate_link' => $link,
                'price'          => $price,
                'images'         => $images,
            ],
            [ '%d', '%s', '%s', '%s' ]
        );
    }
}

function showcazt_get_product_data($post_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'showcazt_products';

    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE product_id = %d",
        $post_id
    ));
}

// =====================
// Fallback URL Handler
// =====================
function showcazt_get_fallback_url() {
    return home_url();
}

// =====================
// Register Custom Post Type
// =====================
function showcazt_register_post_type() {
    register_post_type('showcazt_product', array(
        'labels'      => array(
            'name'          => __('Showcase', 'showcase'),
            'singular_name' => __('Showcase Product', 'showcase'),
            'add_new_item'  => 'Add New Product',
        ),
        'public'      => false,
        'show_ui'    => true,
        'show_in_menu' => true,
        'has_archive' => true,
        'supports'    => array('title', 'thumbnail'),
        'menu_icon'   => 'dashicons-cart',

        'publicly_queryable' => false,
        'exclude_from_search' => true,
        'has_archive' => false,
        'rewrite' => false,
    ));
}
add_action('init', 'showcazt_register_post_type');

// =====================
// Register Custom Taxonomy (Category)
// =====================
function showcazt_register_taxonomy() {
    register_taxonomy('showcazt_category', 'showcazt_product', array(
        'label'        => __('Categories', 'showcase'),
        'rewrite'      => array('slug' => 'showcazt-category'),
        'hierarchical' => true,
    ));
}
add_action('init', 'showcazt_register_taxonomy');

// =====================
// Link Wrapper Rewrite Rule
// =====================
add_action('init', function () {
    add_rewrite_rule(
        '^go/([^/]+)/?$',
        'index.php?showcazt_go=$matches[1]',
        'top'
    );
});

add_filter('query_vars', function ($vars) {
    $vars[] = 'showcazt_go';
    return $vars;
});

add_filter('showcazt_fallback_url', function () {
    return showcazt_get_fallback_url();
});

add_action('template_redirect', function () {
    $slug = get_query_var('showcazt_go');
    if (!$slug) return;

    $post = get_page_by_path($slug, OBJECT, 'showcazt_product');
    if (!$post) {
        wp_redirect(home_url(), 302);
        exit;
    }

    $data = showcazt_get_product_data($post->ID);
    if (!$data || empty($data->affiliate_link)) {
        wp_redirect(home_url(), 302);
        exit;
    }

    if (isset($data->is_active) && (int) $data->is_active === 0) {
        wp_redirect(home_url(), 302);
        exit;
    }

    // Click tracking
    global $wpdb;
    $wpdb->insert(
        $wpdb->prefix . 'showcazt_clicks',
        [
            'product_id' => (int) $post->ID,
            'clicked_at' => current_time('mysql')
        ],
        ['%d', '%s']
    );

    wp_redirect(esc_url_raw($data->affiliate_link), 302);
    exit;
});

// =====================
// Add Custom Meta Fields
// =====================
function showcazt_add_meta_boxes() {
    add_meta_box(
        'showcazt_product_details',
        'Showcase Product Details',
        'showcazt_product_details_callback',
        'showcazt_product',
        'normal',
        'high'
    );
}
add_action('add_meta_boxes', 'showcazt_add_meta_boxes');

function showcazt_product_details_callback($post) {
    $data  = showcazt_get_product_data($post->ID);
    $link  = $data ? $data->affiliate_link : '';
    $price = $data ? $data->price : '';
    $images = $data ? $data->images : '';
    $is_active = isset($data->is_active) ? (int) $data->is_active : 1;
    ?>
    <?php wp_nonce_field('showcazt_save_product', 'showcazt_nonce'); ?>
    <p>
        <label>
            <input type="checkbox" name="showcazt_is_active" value="1" <?php checked($is_active, 1); ?>>
            Product Active
        </label>
    </p>
    <p>
        <label for="showcazt_affiliate_link">Affiliate Link :</label>
        <input type="text" name="showcazt_affiliate_link" value="<?php echo esc_attr($link); ?>" class="widefat" />
    </p>
    <p>
        <label for="showcazt_product_price">Price :</label>
        <input type="text" name="showcazt_product_price" value="<?php echo esc_attr($price); ?>" class="widefat" />
    </p>
    <p>
        <label for="showcazt_product_images">Product Images (one per line) :</label>
        <textarea name="showcazt_product_images" class="widefat" rows="5"><?php echo esc_textarea($images); ?></textarea>
    </p>
    <?php
}

// =====================
// Save Meta Data (hybrid)
// =====================
function showcazt_save_product_data($post_id) {
    if (
        !isset($_POST['showcazt_nonce']) ||
        !wp_verify_nonce($_POST['showcazt_nonce'], 'showcazt_save_product')
    ) {
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

    $link   = isset($_POST['showcazt_affiliate_link']) ? sanitize_text_field($_POST['showcazt_affiliate_link']) : '';
    $price  = isset($_POST['showcazt_product_price']) ? sanitize_text_field($_POST['showcazt_product_price']) : '';
    $images = isset($_POST['showcazt_product_images']) ? sanitize_textarea_field($_POST['showcazt_product_images']) : '';

    // =====================
    // Save product data
    // =====================
    if ($link || $price || $images) {
        showcazt_update_product_data($post_id, $link, $price, $images);
    }

    // =====================
    // Save active status
    // =====================
    global $wpdb;
    $is_active = isset($_POST['showcazt_is_active']) ? 1 : 0;

    $wpdb->update(
        $wpdb->prefix . 'showcazt_products',
        ['is_active' => $is_active],
        ['product_id' => $post_id],
        ['%d'],
        ['%d']
    );

}
add_action('save_post', 'showcazt_save_product_data');

// =====================
// Click Count Retrieval
// =====================
function showcazt_get_click_count($post_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'showcazt_clicks';

    return (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE product_id = %d",
            $post_id
        )
    );
}

// =====================
// Admin Column: Click Stats
// =====================
add_filter('manage_showcazt_product_posts_columns', function ($columns) {
    $columns['showcazt_clicks'] = 'Clicks';
    return $columns;
});

add_action('manage_showcazt_product_posts_custom_column', function ($column, $post_id) {
    if ($column === 'showcazt_clicks') {
        echo esc_html(showcazt_get_click_count($post_id));
    }
}, 10, 2);

// =====================
// Enqueue Swiper.js and custom script
// =====================
function showcazt_enqueue_scripts() {
    wp_enqueue_style('swiper-css', 'https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css');
    wp_enqueue_script('swiper-js', 'https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js', array(), null, true);
    wp_enqueue_script('showcazt-custom-js', plugins_url('slider.js', __FILE__), array('swiper-js'), null, true);
}
add_action('wp_enqueue_scripts', 'showcazt_enqueue_scripts');

// =====================
// Shortcode to Display Showcase Products
// =====================
function showcazt_display_products($atts) {
    $limit = !empty($atts['limit']) ? (int) $atts['limit'] : 10;
    $atts = shortcode_atts(array(
        'id'        => '',
        'category'  => '',
        'container' => ''
    ), $atts, 'showcazt_products');

    $args = array(
        'post_type'      => 'showcazt_product',
        'posts_per_page' => $limit,
        'orderby'        => 'rand'
    );

    if (!empty($atts['id'])) {
        $args['p'] = intval($atts['id']);
        $args['posts_per_page'] = 1;
    } elseif (!empty($atts['category'])) {
        $args['tax_query'] = array(
            array(
                'taxonomy' => 'showcazt_category',
                'field'    => 'slug',
                'terms'    => $atts['category']
            )
        );
    }

    $query = new WP_Query($args);
    $container_id = !empty($atts['container']) ? ' id="' . esc_attr($atts['container']) . '"' : '';

    $output = '<div class="showcazt-products"' . $container_id . '>';

    while ($query->have_posts()) {
        $query->the_post();
        $data   = showcazt_get_product_data(get_the_ID());
        $link   = $data ? $data->affiliate_link : '';
        $price  = $data ? $data->price : '';
        $images = $data ? $data->images : '';

        $image_list = !empty($images) ? explode("\n", trim($images)) : [];
        $single_class = !empty($atts['id']) ? 'showcazt-single-product' : 'showcazt-product';

        $output .= "<div class='{$single_class}'>
                        <a href='" . esc_url(home_url('/go/' . get_post_field('post_name', get_the_ID()))) . "' target='_blank' rel='nofollow sponsored noopener'>";

        if (!empty($image_list)) {
            $output .= "<div class='swiper show-slider'>
                            <div class='swiper-wrapper'>";
            foreach ($image_list as $img) {
                $output .= "<div class='swiper-slide'>
                                <div class='rte'>
                                    <img src='" . esc_url(trim($img)) . "' alt='" . esc_attr(get_the_title()) . "'>
                                </div>
                            </div>";
            }
            $output .= "    </div>
                            <div class='swiper-button-next'></div>
                            <div class='swiper-button-prev'></div>
                            <div class='swiper-pagination'></div>
                        </div>";
        }

        $output .= "<div class='showcazt-info'>
                        <h3 class='product-name'>" . esc_html(get_the_title()) . "</h3>";

        if (!empty($price)) {
            $formatted_price = 'Rp ' . number_format((float) $price, 0, ',', '.');
            $output .= "<p class='product-price'>" . esc_html($formatted_price) . "</p>";
        }

        $output .= "    </div>
                        </a>
                    </div>";

        if (!empty($atts['id'])) {
            break;
        }
    }
    wp_reset_postdata();

    $output .= '</div>';

    return $output;
}
add_shortcode('showcazt_products', 'showcazt_display_products');

add_action('wp_head', function () {
    if (is_post_type_archive('showcazt_product') || is_tax('showcazt_category')) {
        echo '<meta name="robots" content="noindex,follow">';
    }
});
