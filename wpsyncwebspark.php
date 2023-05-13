<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @since             1.0.0
 * @package           Wpsyncwebspark
 *
 * @wordpress-plugin
 * Plugin Name:       wpsync-webspark
 * Plugin URI:        http://webspark/
 * Description:       Плагин синхронизации базы товаров с остатками
 * Version:           1.0.0
 * Author:            Alex Huriev
 * Author URI:        http://webspark/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       wpsyncwebspark
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Update it as you release new versions.
 */
define( 'WPSYNCWEBSPARK_VERSION', '1.0.0' );

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-wpsyncwebspark-activator.php
 */
function activate_wpsyncwebspark() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-wpsyncwebspark-activator.php';
	Wpsyncwebspark_Activator::activate();

    if (!wp_next_scheduled('custom_cron_update_products')) {
        wp_schedule_event(time(), 'hourly', 'custom_cron_update_products');
    }

}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-wpsyncwebspark-deactivator.php
 */
function deactivate_wpsyncwebspark() {

    wp_clear_scheduled_hook('custom_cron_update_products');

	require_once plugin_dir_path( __FILE__ ) . 'includes/class-wpsyncwebspark-deactivator.php';
	Wpsyncwebspark_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_wpsyncwebspark' );
register_deactivation_hook( __FILE__, 'deactivate_wpsyncwebspark' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-wpsyncwebspark.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_wpsyncwebspark() {

	$plugin = new Wpsyncwebspark();
	$plugin->run();

}
run_wpsyncwebspark();


function getProducts() {
    $url = "https://wp.webspark.dev/wp-api/products";

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => 'Content-Type: application/json'
        ]
    ]);

    $response = file_get_contents($url, false, $context);

    if ($response === false) {
        return null;
    } else {
        $products = json_decode($response, true);
        return $products;
    }

}

add_action('custom_cron_update_products', 'update_products_function');

function delete_product_if_ended($products) {
    $args = array(
        'post_type' => 'product',
        'posts_per_page' => -1
    );
    $products_query = new WP_Query($args);

    while ($products_query->have_posts()) {
        $products_query->the_post();
        $product_id = get_the_ID();
        $product_sku = get_post_meta($product_id, '_sku', true);

        // Проверяем, есть ли товар с таким sku в JSON-массиве товаров
        $product_found = false;
        foreach ($products['data'] as $product) {

            if ($product['sku'] === $product_sku) {
                $product_found = true;
                break;
            }
        }

        if (!$product_found) {
            wp_delete_post($product_id, true);
        }
    }

    wp_reset_postdata();
}

function update_products_function() {

    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    $products = getProducts();

    if (!empty($products))  {

        delete_product_if_ended($products);

        foreach ($products['data'] as $product){

            $existingProduct = wc_get_product_id_by_sku($product['sku']);

            if (!$existingProduct) {
                $newProduct = new WC_Product();

                $newProduct->set_name($product['name']);
                $newProduct->set_description($product['description']);
                $newProduct->set_regular_price($product['price']);
                $newProduct->set_sku($product['sku']);
                $newProduct->set_manage_stock(true);
                $newProduct->set_stock_quantity($product['in_stock']);

                if (!empty($product['picture'])) {
                    $url = $product['picture'] . '?ext=.jpeg';
                    $imageId = media_sideload_image($url, 0, '', 'id');
                    $newProduct->set_image_id($imageId);
                }

                $newProductId = $newProduct->save();

            } else {

                $_pf = new WC_Product_Factory();

                $existProduct = $_pf->get_product($existingProduct);

                $updated = false;

                if ($existProduct->get_name() !== $product['name']) {
                    $existProduct->set_name($product['name']);
                    $updated = true;
                }

                if ($existProduct->get_description() !== $product['description']) {
                    $existProduct->set_description($product['description']);
                    $updated = true;
                }

                if ($existProduct->get_regular_price() !== $product['price']) {
                    $existProduct->set_regular_price($product['price']);
                    $updated = true;
                }

                if ($existProduct->get_stock_quantity() !== $product['in_stock']) {
                    $existProduct->set_stock_quantity($product['in_stock']);
                    $updated = true;
                }

                if (!empty($product['picture'])) {
                    $url = $product['picture'] . '?ext=.jpeg';
                    $imageId = media_sideload_image($url, 0, '', 'id');
                    $existProduct->set_image_id($imageId);
                    $updated = true;
                }

                if ($updated) {
                    $existProduct->save();
                }
            }

        }
    }
}

