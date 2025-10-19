<?php
/**
 * Plugin Name: Photo Print Orders
 * Description: Забезпечує процес замовлення друку фотографій із завантаженням файлів на CDN Express, управлінням сесією та оформленням доставки.
 * Version: 1.0
 * Author: Your Name
 * License: GPL2
 */

if (!defined('ABSPATH')) {
    exit;
}

// ====================================================================
// 1. КОНФІГУРАЦІЯ ТА ВКЛЮЧЕННЯ ФАЙЛІВ
// ====================================================================

// Визначення шляху до плагіна
define('PPO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PPO_PLUGIN_URL', plugin_dir_url(__FILE__));

// Завантаження конфігурації, класу CDN та допоміжних класів/функцій
require_once PPO_PLUGIN_DIR . 'ppo-config.php';
require_once PPO_PLUGIN_DIR . 'ppo-cdn-express-uploader.php';
require_once PPO_PLUGIN_DIR . 'includes/ppo-number-generator.php';
require_once PPO_PLUGIN_DIR . 'includes/ppo-cpt-orders.php';
require_once PPO_PLUGIN_DIR . 'includes/ppo-ajax-cdn-handler.php';
require_once PPO_PLUGIN_DIR . 'includes/ppo-form-handler.php';

// Завантаження функцій рендерингу (для шорткодів)
require_once PPO_PLUGIN_DIR . 'includes/ppo-render-order.php';
require_once PPO_PLUGIN_DIR . 'includes/ppo-render-delivery.php';
require_once PPO_PLUGIN_DIR . 'includes/ppo-render-payment.php';


// ====================================================================
// 2. УПРАВЛІННЯ СЕСІЯМИ ТА ОЧИЩЕННЯМ
// ====================================================================

/**
 * Ініціалізація сесії PHP, якщо вона ще не запущена
 */
function ppo_start_session() {
    if (!session_id()) {
        session_start();
    }
}
add_action('init', 'ppo_start_session', 1);

/**
 * Очищення сесії замовлення, якщо передано параметр clear_session=1
 */
function ppo_check_clear_session() {
    if (isset($_GET['clear_session']) && $_GET['clear_session'] == 1) {
        unset($_SESSION['ppo_order_id']);
        unset($_SESSION['ppo_formats']);
        unset($_SESSION['ppo_total']);
        unset($_SESSION['ppo_delivery_address']);
        
        // Перенаправлення, щоб уникнути повторного очищення при оновленні сторінки
        wp_redirect(esc_url_raw(remove_query_arg('clear_session')));
        exit;
    }
}
add_action('init', 'ppo_check_clear_session');


// ====================================================================
// 3. ПІДКЛЮЧЕННЯ СКРИПТІВ ТА СТИЛІВ
// ====================================================================

/**
 * Підключення AJAX-скрипту для роботи форми замовлення
 */
function ppo_enqueue_scripts() {
    // Включаємо jQuery
    wp_enqueue_script('jquery'); 

    // Реєструємо та підключаємо наш AJAX-скрипт
    wp_enqueue_script(
        'ppo-ajax-script', 
        PPO_PLUGIN_URL . 'ppo-ajax-script.js', 
        ['jquery'], 
        filemtime(PPO_PLUGIN_DIR . 'ppo-ajax-script.js'), 
        true
    );

    // Передача даних PHP в JavaScript (Локалізація)
    wp_localize_script('ppo-ajax-script', 'ppo_ajax_object', [
        'ajax_url'          => admin_url('admin-ajax.php'),
        'nonce'             => wp_create_nonce('ppo_file_upload_nonce'),
        'min_sum'           => MIN_ORDER_SUM,
        'prices'            => PHOTO_PRICES,
        'max_files'         => MAX_FILES_PER_UPLOAD,
        // Передаємо поточні дані сесії для ініціалізації JS
        'session_formats'   => isset($_SESSION['ppo_formats']) ? array_filter($_SESSION['ppo_formats'], 'is_array') : new stdClass(),
        'session_total'     => $_SESSION['ppo_total'] ?? 0,
    ]);
}
add_action('wp_enqueue_scripts', 'ppo_enqueue_scripts');

// ====================================================================
// 4. РЕЄСТРАЦІЯ ШОРТКОДІВ
// ====================================================================

add_shortcode('ppo_order_form', 'ppo_render_order_form');
add_shortcode('ppo_delivery_form', 'ppo_render_delivery_form');
add_shortcode('ppo_payment_form', 'ppo_render_payment_form');
// ====================================================================
// ІНІЦІАЛІЗАЦІЯ ФУНКЦІЙ З ІНШИХ ФАЙЛІВ
// ====================================================================

// Реєстрація CPT
add_action('init', 'ppo_register_order_cpt');
// Реєстрація AJAX-обробника
add_action('wp_ajax_ppo_file_upload', 'ppo_ajax_file_upload');
add_action('wp_ajax_nopriv_ppo_file_upload', 'ppo_ajax_file_upload');
// Реєстрація обробників форм POST
add_action('init', 'ppo_handle_forms');