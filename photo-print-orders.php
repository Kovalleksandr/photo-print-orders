<?php
/**
 * Plugin Name: Photo Print Orders
 * Description: Забезпечує процес замовлення друку фотографій із завантаженням файлів на CDN Express, управлінням сесією та оформленням доставки.
 * Version: 1.0
 * Author: Gemini
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

// Завантаження конфігурації
require_once PPO_PLUGIN_DIR . 'ppo-config.php';

// Завантаження класів та допоміжних функцій
require_once PPO_PLUGIN_DIR . 'ppo-cdn-express-uploader.php';
require_once PPO_PLUGIN_DIR . 'includes/order/ppo-number-generator.php';
require_once PPO_PLUGIN_DIR . 'includes/admin/ppo-cpt-orders.php';
require_once PPO_PLUGIN_DIR . 'includes/cdn/ppo-ajax-cdn-handler.php';

// Обробники форм (включаючи ppo-form-handler.php, який ви створювали)
require_once PPO_PLUGIN_DIR . 'includes/form/ppo-form-handler.php';
require_once PPO_PLUGIN_DIR . 'includes/form/ppo-delivery-form-handler.php';

// Завантаження функцій рендерингу (для шорткодів)
require_once PPO_PLUGIN_DIR . 'includes/order/ppo-render-order.php';
require_once PPO_PLUGIN_DIR . 'includes/delivery/ppo-render-delivery.php';
require_once PPO_PLUGIN_DIR . 'includes/payment/ppo-render-payment.php';

// Файли для інтеграції Нової Пошти
require_once PPO_PLUGIN_DIR . 'includes/delivery/api/ppo-nova-poshta-api.php';
require_once PPO_PLUGIN_DIR . 'includes/delivery/ppo-novaposhta-ajax.php';

// Клас LiqPay
require_once PPO_PLUGIN_DIR . 'includes/payment/ppo-liqpay-class.php';

// ====================================================================
// 2. УПРАВЛІННЯ СЕСІЯМИ ТА ОЧИЩЕННЯМ
// ====================================================================

/**
 * Ініціалізація сесії PHP, якщо вона ще не запущена
 */
function ppo_start_session() {
    if (!session_id() && !headers_sent()) {
        session_start();
    }
}
add_action('init', 'ppo_start_session', 1);

/**
 * Очищення сесії замовлення, якщо передано параметр clear_session=1
 */
function ppo_check_clear_session() {
    if (isset($_GET['clear_session']) && $_GET['clear_session'] == 1) {
        // Очищаємо всі ключі, пов'язані із замовленням
        unset($_SESSION['ppo_order_id']);
        unset($_SESSION['ppo_formats']);
        unset($_SESSION['ppo_total']);
        unset($_SESSION['ppo_delivery_address']);
        unset($_SESSION['ppo_delivery_type']);
        
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
 * Підключення AJAX-скриптів та стилів
 */
function ppo_enqueue_scripts() {
    global $post;
    $is_order_page = is_a($post, 'WP_Post') && (
        has_shortcode($post->post_content, 'ppo_order_form') || 
        has_shortcode($post->post_content, 'ppo_delivery_form') || 
        has_shortcode($post->post_content, 'ppo_payment_form')
    );

    if (!$is_order_page) {
        return;
    }
    
    // Включаємо jQuery
    wp_enqueue_script('jquery'); 

    // Реєструємо та підключаємо наш основний AJAX-скрипт
    wp_enqueue_script(
        'ppo-ajax-script', 
        PPO_PLUGIN_URL . 'ppo-ajax-script.js', 
        ['jquery'], 
        filemtime(PPO_PLUGIN_DIR . 'ppo-ajax-script.js'), 
        true
    );

    // Підключаємо стилі
    wp_enqueue_style(
        'ppo-forms',
        PPO_PLUGIN_URL . 'assets/ppo-forms.css',
        [],
        filemtime(PPO_PLUGIN_DIR . 'assets/ppo-forms.css')
    );

    // Підключення скрипта Нової Пошти лише на сторінці доставки
    if (has_shortcode($post->post_content, 'ppo_delivery_form')) {
        // Підключаємо стилі для jQuery UI Autocomplete
        wp_enqueue_style('jquery-ui-css', 'https://ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/smoothness/jquery-ui.css');
        
        wp_enqueue_script('jquery-ui-autocomplete');
        wp_enqueue_script(
            'ppo-nova-poshta-script', 
            PPO_PLUGIN_URL . 'includes/delivery/ppo-nova-poshta-script.js', 
            ['jquery', 'jquery-ui-autocomplete'], 
            filemtime(PPO_PLUGIN_DIR . 'includes/delivery/ppo-nova-poshta-script.js'), 
            true
        );

        // Локалізація для скрипта Нової Пошти
        wp_localize_script('ppo-nova-poshta-script', 'ppo_np_ajax', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ppo_np_nonce')
        ]);
    }

    // Передача даних PHP в JavaScript (Локалізація)
    wp_localize_script('ppo-ajax-script', 'ppo_ajax_object', [
        'ajax_url'            => admin_url('admin-ajax.php'),
        'nonce'               => wp_create_nonce('ppo_file_upload_nonce'),
        'np_nonce'            => wp_create_nonce('ppo_np_nonce'), 
        'min_sum'             => MIN_ORDER_SUM,
        'prices'              => PHOTO_PRICES,
        'max_files'           => MAX_FILES_PER_UPLOAD,
        'session_formats'     => isset($_SESSION['ppo_formats']) ? array_filter($_SESSION['ppo_formats'], 'is_array') : new stdClass(),
        'session_total'       => $_SESSION['ppo_total'] ?? 0,
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
// 5. ОБРОБНИКИ ФОРМ ТА КНОПОК
// ====================================================================

// Реєстрація CPT
add_action('init', 'ppo_register_order_cpt');

// Обробник форми після завантаження фото (визначено в ppo-form-handler.php)
add_action('init', 'ppo_handle_forms'); 

// Обробник натискання кнопки "Оформіть доставку" (яку ми щойно додали!)
function ppo_handle_go_to_delivery_button() {
    if (!isset($_POST['ppo_go_to_delivery'])) {
        return;
    }

    // Перевірка безпеки
    if (!isset($_POST['ppo_nonce']) || !wp_verify_nonce($_POST['ppo_nonce'], 'ppo_delivery_nonce')) {
        $error_message = urlencode('Помилка безпеки. Спробуйте оновити сторінку.');
        wp_redirect(esc_url_raw(add_query_arg('error', $error_message)));
        exit;
    }

    // Перевірка, чи є активне замовлення
    if (empty($_SESSION['ppo_order_id']) || empty($_SESSION['ppo_formats'])) {
        $error_message = urlencode('Перед оформленням доставки необхідно додати фотографії до замовлення.');
        wp_redirect(esc_url_raw(add_query_arg('error', $error_message)));
        exit;
    }
    
    // Перенаправлення на сторінку доставки
    $delivery_url = home_url('/orderpagedelivery/'); // ЗАМІНІТЬ НА ВАШ ФАКТИЧНИЙ SLUG
    wp_redirect(esc_url_raw($delivery_url));
    exit;
}
add_action('init', 'ppo_handle_go_to_delivery_button');

// Обробник форми доставки (визначено в ppo-delivery-form-handler.php)
add_action('init', 'ppo_handle_delivery_form_submit'); 

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
// 7. LIQPAY CALLBACK ENDPOINT
// ====================================================================

/**
 * Реєструє REST API маршрут для прийому Callback від LiqPay.
 */
function ppo_register_liqpay_callback_route() {
    register_rest_route('ppo/v1', '/callback/', array(
        'methods'             => 'POST',
        'callback'            => 'ppo_handle_liqpay_callback',
        'permission_callback' => '__return_true', 
    ));
}
add_action('rest_api_init', 'ppo_register_liqpay_callback_route');

/**
 * Обробляє дані, надіслані LiqPay про статус платежу.
 * Примітка: Логіка обробника має бути розміщена у файлі ppo-liqpay-class.php або ppo-render-payment.php,
 * але для повноти демонстрації розміщуємо заглушку. 
 * Реальна функція ppo_handle_liqpay_callback має бути визначена.
 */
function ppo_handle_liqpay_callback(WP_REST_Request $request) {
    // 1. Отримуємо дані
    $data      = $request->get_param('data');
    $signature = $request->get_param('signature');
    
    // ... Логіка перевірки підпису та оновлення статусу замовлення в БД (як обговорювалося раніше) ...
    
    // Якщо все добре, повертаємо 200
    return new WP_REST_Response(['message' => 'Callback processed'], 200);
}


// ====================================================================
// 8. АДМІН-СТОРІНКА ДЛЯ НАЛАШТУВАНЬ API НОВОЇ ПОШТИ
// ====================================================================

add_action('admin_menu', 'ppo_add_np_settings');
function ppo_add_np_settings() {
    // Припускаємо, що ppo_np_settings_page визначено в includes/admin/
    add_submenu_page('options-general.php', 'Налаштування Нової Пошти', 'Нова Пошта (PPO)', 'manage_options', 'ppo-np-settings', 'ppo_np_settings_page');
}