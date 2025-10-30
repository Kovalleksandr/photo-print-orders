<?php
// includes/form/ppo-delivery-form-handler.php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Обробляє POST-запит з форми доставки.
 */
function ppo_handle_delivery_form_submit() {
    // 1. Перевірка, чи була надіслана наша форма
    if (!isset($_POST['ppo_delivery_submit'])) {
        return;
    }

    // 2. Перевірка безпеки (Nonce)
    if (!isset($_POST['ppo_delivery_nonce']) || !wp_verify_nonce($_POST['ppo_delivery_nonce'], 'ppo_delivery_action')) {
        ppo_delivery_redirect_error('Security check failed. Try again.');
    }

    // 3. Перевірка наявності активного замовлення в сесії
    if (empty($_SESSION['ppo_order_id'])) {
        ppo_delivery_redirect_error('Active order not found. Please start a new order.');
    }

    // 4. Збір та очищення даних
    $order_id_code = $_SESSION['ppo_order_id'];
    $delivery_type = sanitize_text_field($_POST['delivery_type'] ?? '');
    
    // Контактні дані
    $contact_name = sanitize_text_field($_POST['contact_name'] ?? '');
    $contact_phone = sanitize_text_field($_POST['contact_phone'] ?? '');
    $contact_email = sanitize_email($_POST['contact_email'] ?? '');
    
    $delivery_data = [];
    $is_valid = true;
    $error_message = '';

    // Валідація основних контактних даних
    if (empty($contact_name) || empty($contact_phone) || !is_email($contact_email)) {
        $error_message = 'Будь ласка, заповніть коректно контактні дані (ПІБ, телефон, email).';
        $is_valid = false;
    }

    // 5. Обробка даних залежно від типу доставки
    
    if ($is_valid && $delivery_type === 'novaposhta_warehouse') {
        // Доставка Новою Поштою (відділення)
        $np_city_ref = sanitize_text_field($_POST['np_city_ref'] ?? '');
        $np_city_name = sanitize_text_field($_POST['np_city_name'] ?? '');
        $np_warehouse_ref = sanitize_text_field($_POST['np_warehouse_ref'] ?? '');
        $np_warehouse_name = sanitize_text_field($_POST['np_warehouse_name'] ?? '');

        if (empty($np_city_ref) || empty($np_warehouse_ref)) {
            $error_message = 'Будь ласка, оберіть місто та відділення Нової Пошти.';
            $is_valid = false;
        } else {
            $delivery_data = [
                'type' => 'Нова Пошта (Відділення)',
                'city_ref' => $np_city_ref,
                'city_name' => $np_city_name,
                'warehouse_ref' => $np_warehouse_ref,
                'warehouse_name' => $np_warehouse_name,
            ];
        }
    } elseif ($is_valid && $delivery_type === 'novaposhta_address') {
         // Доставка Новою Поштою (адресна) - Якщо ви це реалізуєте пізніше
        $error_message = 'Адресна доставка Новою Поштою тимчасово недоступна.';
        $is_valid = false;

    } elseif ($is_valid && $delivery_type === 'self_pickup') {
        // Самовивіз
        $delivery_data = ['type' => 'Самовивіз'];

    } elseif ($is_valid) {
        $error_message = 'Будь ласка, оберіть коректний спосіб доставки.';
        $is_valid = false;
    }
    
    // 6. Обробка помилок
    if (!$is_valid) {
        ppo_delivery_redirect_error($error_message);
    }

    // 7. Збереження даних у сесії
    $_SESSION['ppo_delivery_type'] = $delivery_type;
    $_SESSION['ppo_delivery_address'] = $delivery_data;
    $_SESSION['ppo_contact_info'] = [
        'name' => $contact_name,
        'phone' => $contact_phone,
        'email' => $contact_email,
    ];
    
    // 8. Оновлення Custom Post Type (CPT)
    $posts = get_posts([
        'post_type'  => 'ppo_order',
        'meta_key'   => 'ppo_order_id',
        'meta_value' => $order_id_code,
        'posts_per_page' => 1,
        'fields'     => 'ids',
    ]);
    
    if (!empty($posts)) {
        $post_id = $posts[0];
        
        // Оновлення статусу та заголовка
        wp_update_post([
            'ID'          => $post_id,
            'post_status' => 'pending_payment', // Переходимо до оплати
            'post_title'  => 'Замовлення #' . $order_id_code . ' - Очікує оплати',
        ]);
        
        // Зберігаємо мета-дані доставки
        update_post_meta($post_id, 'ppo_delivery_type', $delivery_type);
        update_post_meta($post_id, 'ppo_delivery_details', $delivery_data);
        update_post_meta($post_id, 'ppo_contact_info', $_SESSION['ppo_contact_info']);
    }

    // 9. Перенаправлення на сторінку оплати
    $redirect_url = home_url('/orderpagepayment/'); // Замініть на ваш фактичний slug сторінки оплати
    wp_redirect(esc_url_raw($redirect_url));
    exit;
}

/**
 * Допоміжна функція для перенаправлення з повідомленням про помилку.
 * @param string $message Повідомлення про помилку.
 */
function ppo_delivery_redirect_error($message) {
    $error_url = home_url('/orderpagedelivery/'); // Повертаємо на сторінку доставки
    $redirect_url = add_query_arg('error', urlencode($message), $error_url);
    wp_redirect(esc_url_raw($redirect_url));
    exit;
}