<?php
// includes/delivery/ppo-delivery-form-handler.php

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

    // 4. Збір та очищення контактних даних
    $order_id_code = $_SESSION['ppo_order_id'];
    $delivery_type = sanitize_text_field($_POST['delivery_type'] ?? '');
    
    $contact_name = sanitize_text_field($_POST['contact_name'] ?? '');
    $contact_phone = sanitize_text_field($_POST['contact_phone'] ?? '');
    $contact_email = sanitize_email($_POST['contact_email'] ?? '');
    
    $delivery_data = [];
    $display_address = ''; // Змінна для збереження рядка адреси для відображення
    $is_valid = true;
    $error_message = '';

    // Валідація основних контактних даних
    if (empty($contact_name) || empty($contact_phone) || !is_email($contact_email)) {
        $error_message = 'Будь ласка, заповніть коректно контактні дані (ПІБ, телефон, email).';
        $is_valid = false;
    }

    // 5. Обробка даних: Тільки Нова Пошта (Відділення/Поштомат)
    
    if ($is_valid && $delivery_type === 'novaposhta_warehouse') {
        $np_city_ref = sanitize_text_field($_POST['np_city_ref'] ?? '');
        $np_city_name = sanitize_text_field($_POST['np_city_name'] ?? '');
        $np_warehouse_ref = sanitize_text_field($_POST['np_warehouse_ref'] ?? '');
        $np_warehouse_name = sanitize_text_field($_POST['np_warehouse_name'] ?? '');

        if (empty($np_city_ref) || empty($np_warehouse_ref)) {
            $error_message = 'Будь ласка, оберіть місто та відділення Нової Пошти.';
            $is_valid = false;
        } else {
            // Масив для збереження в базу даних
            $delivery_data = [
                'type' => 'Нова Пошта (Відділення/Поштомат)',
                'city_ref' => $np_city_ref,
                'city_name' => $np_city_name,
                'warehouse_ref' => $np_warehouse_ref,
                'warehouse_name' => $np_warehouse_name,
            ];
            // Рядок, який буде виведений на сторінці оплати
            $display_address = "Нова Пошта: {$np_city_name}, {$np_warehouse_name}";
        }
    } else {
        // Якщо обрано некоректний тип доставки або не обрано взагалі
        $error_message = 'Будь ласка, оберіть коректний спосіб доставки (Нова Пошта).';
        $is_valid = false;
    }
    
    // 6. Обробка помилок
    if (!$is_valid) {
        // Зберігаємо контактні дані назад у сесію для перенаправлення
        $_SESSION['ppo_contact_info'] = [
            'name' => $contact_name,
            'phone' => $contact_phone,
            'email' => $contact_email,
        ];
        ppo_delivery_redirect_error($error_message);
    }

    // 7. Збереження даних у сесії
    $_SESSION['ppo_delivery_type'] = $delivery_type;
    $_SESSION['ppo_delivery_address'] = $display_address; 
    $_SESSION['ppo_delivery_details_array'] = $delivery_data; 
    $_SESSION['ppo_contact_info'] = [
        'name' => $contact_name,
        'phone' => $contact_phone,
        'email' => $contact_email,
    ];
    
    // 8. Оновлення/Створення Custom Post Type (CPT)
    $posts = get_posts([
        'post_type'  => 'ppo_order',
        'meta_key'   => 'ppo_order_id',
        'meta_value' => $order_id_code,
        'posts_per_page' => 1,
        'fields'     => 'ids',
    ]);
    
    $post_id = !empty($posts) ? $posts[0] : 0;
    
    // *** КРИТИЧНЕ ВИПРАВЛЕННЯ: Створюємо CPT, якщо його не знайдено ***
    if (!$post_id) {
        // Створюємо новий запис
        $post_args = [
            'post_title'    => 'Замовлення #' . $order_id_code . ' - Створення',
            'post_status'   => 'draft',
            'post_type'     => 'ppo_order',
        ];
        $post_id = wp_insert_post($post_args, true);

        if (is_wp_error($post_id)) {
            ppo_delivery_redirect_error('Помилка сервера: Не вдалося створити запис замовлення.');
        }

        // Обов'язково зберігаємо унікальний код замовлення
        update_post_meta($post_id, 'ppo_order_id', $order_id_code);
        // Зберігаємо формати та суму, які мають бути в сесії з попередніх кроків
        if (isset($_SESSION['ppo_formats'])) {
             update_post_meta($post_id, 'ppo_formats', $_SESSION['ppo_formats']);
        }
        if (isset($_SESSION['ppo_total'])) {
             update_post_meta($post_id, 'ppo_total', floatval($_SESSION['ppo_total']));
        }
    }
    
    // Тепер оновлюємо CPT, який вже існує або щойно створений
    if ($post_id) { 
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
    } else {
        // Фолбек на випадок, якщо CPT не створився з невідомої причини
        ppo_delivery_redirect_error('Помилка: Не вдалося ініціалізувати замовлення для оплати.');
    }

    // 9. Перенаправлення на сторінку оплати
    $redirect_url = home_url('/orderpagepayment/'); 
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

// Додаємо дію обробки форми
add_action('init', 'ppo_handle_delivery_form_submit');