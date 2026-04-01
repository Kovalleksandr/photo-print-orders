<?php
/**
 * Plugin Name: Photo Print Orders
 * Description: Забезпечує процес замовлення друку фотографій із завантаженням файлів на CDN Express, управлінням сесією та оформленням доставки.
 * Version: 1.1
 * Author: Gemini & Nataliia
 * License: GPL2
 */

if (!defined('ABSPATH')) {
    exit;
}

// 1. КОНФІГУРАЦІЯ ТА ШЛЯХИ
define('PPO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PPO_PLUGIN_URL', plugin_dir_url(__FILE__));

// Автозавантаження Composer (LiqPay SDK)
$composer_autoload = PPO_PLUGIN_DIR . 'vendor/autoload.php';
if (file_exists($composer_autoload)) {
    require_once $composer_autoload;
}

// Підключення всіх модулів
require_once PPO_PLUGIN_DIR . 'ppo-config.php';
require_once PPO_PLUGIN_DIR . 'includes/admin/ppo-cpt-orders.php';
require_once PPO_PLUGIN_DIR . 'includes/payment/ppo-render-payment.php';
require_once PPO_PLUGIN_DIR . 'includes/payment/ppo-liqpay-callback.php';
require_once PPO_PLUGIN_DIR . 'ppo-cdn-express-uploader.php';
require_once PPO_PLUGIN_DIR . 'includes/order/ppo-number-generator.php';
require_once PPO_PLUGIN_DIR . 'includes/cdn/ppo-ajax-cdn-handler.php';
require_once PPO_PLUGIN_DIR . 'includes/order/ppo-form-handler.php';
require_once PPO_PLUGIN_DIR . 'includes/delivery/ppo-delivery-form-handler.php';
require_once PPO_PLUGIN_DIR . 'includes/order/ppo-render-order.php';
require_once PPO_PLUGIN_DIR . 'includes/delivery/ppo-render-delivery.php';
require_once PPO_PLUGIN_DIR . 'includes/delivery/api/ppo-nova-poshta-api.php';
require_once PPO_PLUGIN_DIR . 'includes/delivery/ppo-novaposhta-ajax.php';

// 2. СЕСІЇ ТА ОЧИЩЕННЯ
add_action('init', function() {
    if (!session_id() && !headers_sent()) {
        session_start();
    }
}, 1);

// Очищення сесії за параметром clear_session=1
add_action('init', function() {
    if (isset($_GET['clear_session']) && $_GET['clear_session'] == 1) {
        $keys = ['ppo_order_id', 'ppo_formats', 'ppo_total', 'ppo_delivery_address', 'ppo_delivery_type'];
        foreach ($keys as $key) unset($_SESSION[$key]);
        wp_redirect(esc_url_raw(remove_query_arg('clear_session')));
        exit;
    }
});

// 3. СКРИПТИ ТА СТИЛІ
add_action('wp_enqueue_scripts', function() {
    global $post;
    if (!is_a($post, 'WP_Post')) return;

    $has_shortcode = has_shortcode($post->post_content, 'ppo_order_form') || 
                     has_shortcode($post->post_content, 'ppo_delivery_form') || 
                     has_shortcode($post->post_content, 'ppo_payment_form');

    if (!$has_shortcode) return;

    wp_enqueue_style('ppo-forms', PPO_PLUGIN_URL . 'assets/ppo-forms.css', [], '1.0');
    wp_enqueue_script('ppo-ajax-script', PPO_PLUGIN_URL . 'ppo-ajax-script.js', ['jquery'], '1.0', true);

    wp_localize_script('ppo-ajax-script', 'ppo_ajax_object', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('ppo_file_upload_nonce'),
        'prices'   => PHOTO_PRICES,
    ]);
});

// 4. ШОРТКОДИ
add_shortcode('ppo_order_form', 'ppo_render_order_form');
add_shortcode('ppo_delivery_form', 'ppo_render_delivery_form');
add_shortcode('ppo_payment_form', 'ppo_render_payment_form');
add_shortcode('ppo_payment_result', 'ppo_render_payment_result');

// 5. LIQPAY REWRITE RULES
add_action('init', function() {
    add_rewrite_rule('^liqpay-callback/?$', 'index.php?liqpay_callback=1', 'top');
});

add_filter('query_vars', function($vars) {
    $vars[] = 'liqpay_callback';
    return $vars;
});

add_action('template_redirect', function() {
    if (get_query_var('liqpay_callback')) {
        ppo_handle_liqpay_callback();
        exit;
    }
});

register_activation_hook(__FILE__, 'flush_rewrite_rules');
register_deactivation_hook(__FILE__, 'flush_rewrite_rules');
// ====================================================================
// 6. AJAX-ОБРОБНИКИ (CDN та Нова Пошта)
// ====================================================================

// AJAX-обробник для CDN завантаження (визначено в ppo-ajax-cdn-handler.php)
add_action('wp_ajax_ppo_file_upload', 'ppo_ajax_file_upload');
add_action('wp_ajax_nopriv_ppo_file_upload', 'ppo_ajax_file_upload');

// AJAX-обробники для Нової Пошти (Викликають ppo_handle_np_ajax)
add_action('wp_ajax_ppo_np_search_settlements', 'ppo_handle_np_ajax');
add_action('wp_ajax_nopriv_ppo_np_search_settlements', 'ppo_handle_np_ajax');

add_action('wp_ajax_ppo_np_get_divisions', 'ppo_handle_np_ajax');
add_action('wp_ajax_nopriv_ppo_np_get_divisions', 'ppo_handle_np_ajax');


// ====================================================================
// 7. LIQPAY CALLBACK ENDPOINT (Оновлено для традиційного WP Endpoint)
// ====================================================================

/**
 * Реєструє rewrite rule для LiqPay Callback URL.
 */
function ppo_register_liqpay_callback_url() {
    // URL: ваш_сайт/liqpay-callback/
    add_rewrite_rule('^liqpay-callback/?$', 'index.php?liqpay_callback=1', 'top');
}
add_action('init', 'ppo_register_liqpay_callback_url');

/**
 * Додає query var для розпізнавання LiqPay Callback.
 */
add_filter('query_vars', 'ppo_add_liqpay_callback_query_var');
function ppo_add_liqpay_callback_query_var($vars) {
    $vars[] = 'liqpay_callback';
    return $vars;
}

/**
 * Обробляє запит, якщо це LiqPay Callback URL.
 */
add_action('template_redirect', 'ppo_handle_liqpay_request');
function ppo_handle_liqpay_request() {
    if (get_query_var('liqpay_callback')) {
        // Логіка знаходиться у includes/payment/ppo-liqpay-callback.php
        ppo_handle_liqpay_callback();
        exit; // Важливо, щоб LiqPay отримав чистий відповідь "OK"
    }
}

/**
 * Оновлення rewrite rules при активації плагіна.
 */
register_activation_hook(__FILE__, 'ppo_activate_plugin_liqpay');
function ppo_activate_plugin_liqpay() {
    ppo_register_liqpay_callback_url();
    flush_rewrite_rules();
}

/**
 * Очищення rewrite rules при деактивації плагіна.
 */
register_deactivation_hook(__FILE__, 'ppo_deactivate_plugin_liqpay');
function ppo_deactivate_plugin_liqpay() {
    flush_rewrite_rules();
}


// ====================================================================
// 8. АДМІН-СТОРІНКА ДЛЯ НАЛАШТУВАНЬ API НОВОЇ ПОШТИ
// ====================================================================

add_action('admin_menu', 'ppo_add_np_settings');
function ppo_add_np_settings() {
    // Припускаємо, що ppo_np_settings_page визначено в includes/admin/
    add_submenu_page('options-general.php', 'Налаштування Нової Пошти', 'Нова Пошта (PPO)', 'manage_options', 'ppo-np-settings', 'ppo_np_settings_page');
}


