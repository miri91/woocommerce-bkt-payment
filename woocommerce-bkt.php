<?php

/**
 * Plugin Name:     Woocommerce BKT Albania Gateway
 * Plugin URI:      https://4xd.net
 * Description:     BKT ( Banka Kombetare Tregetare ) payment gateway for woocommerce
 * Author:          Miri ( miri@4xd.net )
 * Author URI:      https://4xd.net
 * Text Domain:     4xd
 * Domain Path:     /languages
 * Version:         1.0.0
 *
 * @package         Woocommerce_Bkt_Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once(plugin_basename('vendor/autoload.php'));

const WC_GATEWAY_BKT_VERSION = '1.0.0';
const WC_GATEWAY_BKT_PLUGIN_FILE = __FILE__;
define("WC_GATEWAY_BKT_PLUGIN_PATH", untrailingslashit(plugin_dir_path(WC_GATEWAY_BKT_PLUGIN_FILE)));
define("CWC_VERSION", wc_version());

function wc_version()
{
    if (defined('WC_VERSION')) {
        return WC_VERSION;
    }
    return 1;
}

function bkt_start()
{
    /**
     * if plugins is currently load then return.
     */
    if (defined('WC_GATEWAY_BKT_LOADED') && WC_GATEWAY_BKT_LOADED) {
        return;
    }

    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . '/wp-admin/includes/plugin.php';
    }

    /**
     * If WooCommerce Plugin is not active, let users know.
     */
    if (!is_plugin_active('woocommerce/woocommerce.php')) {
        add_action(
            'admin_notices',
            function () {
                echo '<div class="error notice">
					<p>' . esc_html__('BKT Payment Gateway: WooCommerce plugin should be enabled.', 'woocommerce-bkt') . '</p>
				</div>';
            }
        );
        return;
    }

    add_action('plugins_loaded', 'wc_bkt_plugin_loaded');
    add_filter('woocommerce_payment_gateways', 'woocommerce_add_bkt_gateway');
    add_action( 'wp_enqueue_scripts', 'enqueue_css');

    define('WC_GATEWAY_BKT_LOADED', true);
}

function enqueue_css() {
    $plugin_url = plugin_dir_url( __FILE__ );
    wp_enqueue_style( 'bkt_style',  plugins_url('/css/bkt-style.css', __FILE__), false, '1.0.0', 'all');
}

function wc_bkt_plugin_loaded () {
    require_once __DIR__ . '/includes/_4xd_WC_BKT_Invoice.php';
    require_once __DIR__ . '/includes/_4xd_WC_BKT_Payment_Gateway.php';
    require_once __DIR__ . '/includes/_4xd_WC_BKT_Errors.php';

    $locale = is_admin() && function_exists('get_user_locale') ? get_user_locale() : get_locale();
    $locale = apply_filters('plugin_locale', $locale, 'woocommerce-bkt');
    unload_textdomain('woocommerce-bkt');
    load_textdomain('woocommerce-bkt', WP_LANG_DIR . '/woocommerce-bkt/woocommerce-bkt-' . $locale . '.mo');
    load_plugin_textdomain('woocommerce-bkt', false, plugin_basename(dirname(WC_GATEWAY_BKT_PLUGIN_FILE)) . '/languages');
}

function woocommerce_add_bkt_gateway($methods)
{
    $methods[] = '_4xd_WC_BKT_Payment_Gateway';
    return $methods;
}

bkt_start();
