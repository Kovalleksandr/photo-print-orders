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
require_once PPO_PLUGIN_DIR . 'includes/order/ppo-number-generator.php';
require_once PPO_PLUGIN_DIR . 'includes/admin/ppo-cpt-orders.php';
require_once PPO_PLUGIN_DIR . 'includes/cdn/ppo-ajax-cdn-handler.php';
require_once PPO_PLUGIN_DIR . 'includes/form/ppo-form-handler.php';

// Завантаження функцій рендерингу (для шорткодів)
require_once PPO_PLUGIN_DIR . 'includes/order/ppo-render-order.php';
require_once PPO_PLUGIN_DIR . 'includes/delivery/ppo-render-delivery.php';
require_once PPO_PLUGIN_DIR . 'includes/payment/ppo-render-payment.php';

// Файли для інтеграції Нової Пошти
require_once PPO_PLUGIN_DIR . 'includes/delivery/ppo-novaposhta-ajax.php';

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

    // ФІКС: Enqueue CSS тільки на сторінках з PPO шорткодами (оптимізація)
    global $post;
    $has_ppo_shortcode = false;
    
    if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'ppo_order_form')) {
        $has_ppo_shortcode = true;
    }

    if ($has_ppo_shortcode) {
        wp_enqueue_style(
            'ppo-forms',
            PPO_PLUGIN_URL . 'assets/ppo-forms.css',
            [],
            filemtime(PPO_PLUGIN_DIR . 'assets/ppo-forms.css')
        );
    }

    // Підключення скрипта Нової Пошти лише на сторінці доставки
    if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'ppo_delivery_form')) {
        wp_enqueue_script('jquery-ui-autocomplete');
        wp_enqueue_script(
            'ppo-nova-poshta-script', 
            PPO_PLUGIN_URL . 'includes/delivery/ppo-nova-poshta-script.js', 
            ['jquery', 'jquery-ui-autocomplete'], 
            filemtime(PPO_PLUGIN_DIR . 'includes/delivery/ppo-nova-poshta-script.js'), 
            true
        );
    }

    // Передача даних PHP в JavaScript (Локалізація)
    wp_localize_script('ppo-ajax-script', 'ppo_ajax_object', [
        'ajax_url'          => admin_url('admin-ajax.php'),
        'nonce'             => wp_create_nonce('ppo_file_upload_nonce'),
        'np_nonce'          => wp_create_nonce('ppo_np_nonce'),
        'min_sum'           => MIN_ORDER_SUM,
        'prices'            => PHOTO_PRICES,
        'max_files'         => MAX_FILES_PER_UPLOAD,
        // Передаємо поточні дані сесії для ініціалізації JS
        'session_formats'   => isset($_SESSION['ppo_formats']) ? array_filter($_SESSION['ppo_formats'], 'is_array') : new stdClass(),
        'session_total'     => $_SESSION['ppo_total'] ?? 0,
    ]);

    // Локалізація для скрипта Нової Пошти
    if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'ppo_delivery_form')) {
        wp_localize_script('ppo-nova-poshta-script', 'ppo_ajax', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ppo_np_nonce')
        ]);
    }
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

// AJAX-обробники для Нової Пошти
add_action('wp_ajax_ppo_np_search_settlements', 'ppo_handle_np_ajax');
add_action('wp_ajax_nopriv_ppo_np_search_settlements', 'ppo_handle_np_ajax');
add_action('wp_ajax_ppo_np_search_streets', 'ppo_handle_np_ajax');
add_action('wp_ajax_nopriv_ppo_np_search_streets', 'ppo_handle_np_ajax');
add_action('wp_ajax_ppo_np_get_divisions', 'ppo_handle_np_ajax');
add_action('wp_ajax_nopriv_ppo_np_get_divisions', 'ppo_handle_np_ajax');

// ====================================================================
// 5. АДМІН-СТОРІНКА ДЛЯ НАЛАШТУВАНЬ API НОВОЇ ПОШТИ
// ====================================================================

add_action('admin_menu', 'ppo_add_np_settings');
function ppo_add_np_settings() {
    add_submenu_page('options-general.php', 'Нова Пошта', 'Нова Пошта', 'manage_options', 'ppo-np-settings', 'ppo_np_settings_page');
}


// ====================================================================
// 6. Клас LiqPay
// ====================================================================

require_once PPO_PLUGIN_DIR . 'includes/payment/ppo-liqpay-class.php';

// ====================================================================
// 9. LIQPAY CALLBACK ENDPOINT (ОБРОБКА СТАТУСУ ПЛАТЕЖУ)
// ====================================================================

/**
 * Реєструє REST API маршрут для прийому Callback від LiqPay.
 */
function ppo_register_liqpay_callback_route() {
    register_rest_route('ppo/v1', '/callback/', array(
        'methods'             => 'POST',
        'callback'            => 'ppo_handle_liqpay_callback',
        'permission_callback' => '__return_true', // Відкритий доступ, бо LiqPay не авторизується
    ));
}
add_action('rest_api_init', 'ppo_register_liqpay_callback_route');

/**
 * Обробляє дані, надіслані LiqPay про статус платежу.
 * @param WP_REST_Request $request Об'єкт запиту.
 */
function ppo_handle_liqpay_callback(WP_REST_Request $request) {
    // 1. Отримуємо дані
    $data      = $request->get_param('data');
    $signature = $request->get_param('signature');
    
    // Перевірка наявності даних
    if (empty($data) || empty($signature)) {
        return new WP_REST_Response(['message' => 'Invalid Request (missing data/signature)'], 400);
    }
    
    // 2. Ініціалізуємо клас LiqPay для перевірки підпису
    $liqpay = new PPO_LiqPay(LIQPAY_PUBLIC_KEY, LIQPAY_PRIVATE_KEY);
    
    // 3. Перевіряємо підпис
    // Ми використовуємо той самий метод, що й для генерації форми, для перевірки
    // (Хоча в реальному SDK є окремий метод для перевірки, тут використовуємо обгортку)
    $expected_signature = base64_encode(sha1(LIQPAY_PRIVATE_KEY . $data . LIQPAY_PRIVATE_KEY, 1));

    if ($signature !== $expected_signature) {
        // Логування помилки
        error_log('LiqPay Callback Error: Signature mismatch for data: ' . $data);
        return new WP_REST_Response(['message' => 'Signature mismatch'], 403);
    }

    // 4. Декодуємо дані
    $decoded_data = json_decode(base64_decode($data), true);
    $order_id = $decoded_data['order_id'] ?? null;
    $status   = $decoded_data['status'] ?? 'unknown';

    if (!$order_id) {
        return new WP_REST_Response(['message' => 'Missing Order ID'], 400);
    }

    // 5. Оновлюємо статус замовлення в WordPress
    $posts = get_posts([
        'post_type'  => 'ppo_order',
        'meta_key'   => 'ppo_order_id',
        'meta_value' => $order_id,
        'posts_per_page' => 1,
        'fields'     => 'ids',
    ]);

    if (!empty($posts)) {
        $post_id = $posts[0];
        $new_status = 'pending_payment';

        if ($status === 'success' || $status === 'sandbox') {
            $new_status = 'processing';
            $post_title = 'Замовлення #' . $order_id . ' - Оплачено (Обробка)';
            // Можна додати додаткову логіку: надіслати email клієнту/адміністратору
        } elseif ($status === 'failure' || $status === 'error' || $status === 'reversed') {
            $new_status = 'failed';
            $post_title = 'Замовлення #' . $order_id . ' - Помилка оплати';
        } else {
            // Інші статуси (wait_accept, hold_wait, processing)
            $new_status = 'pending_payment';
            $post_title = 'Замовлення #' . $order_id . ' - Очікує оплати (LiqPay: ' . $status . ')';
        }
        
        // Оновлюємо пост
        wp_update_post([
            'ID'          => $post_id,
            'post_status' => $new_status,
            'post_title'  => $post_title,
        ]);
        
        // Зберігаємо повний лог LiqPay
        update_post_meta($post_id, 'ppo_liqpay_status', $status);
        update_post_meta($post_id, 'ppo_liqpay_callback_data', $decoded_data);
        
        return new WP_REST_Response(['message' => 'Order status updated to ' . $new_status], 200);
    }

    return new WP_REST_Response(['message' => 'Order not found in DB'], 404);
}