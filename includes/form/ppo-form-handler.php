<?php
// includes/form/ppo-form-handler.php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Обробляє дані основної форми замовлення (після завантаження файлів)
 * та створює/оновлює пост замовлення в CPT.
 */
function ppo_handle_forms() {
    // Перевіряємо, чи був надісланий наш POST-запит для фіналізації замовлення
    if (!isset($_POST['ppo_final_order_submit']) || $_POST['ppo_final_order_submit'] !== '1') {
        return;
    }

    // 1. Перевірка безпеки та даних
    if (!isset($_POST['ppo_final_order_nonce']) || !wp_verify_nonce($_POST['ppo_final_order_nonce'], 'ppo_final_order_action')) {
        wp_die('Security check failed.');
    }
    
    $format = sanitize_text_field($_POST['final_format'] ?? '');
    $files_data_json = wp_unslash($_POST['files_data'] ?? '[]');
    $total_sum = floatval($_POST['final_total_sum'] ?? 0);
    $total_copies = intval($_POST['final_total_copies'] ?? 0);
    $order_folder_path = sanitize_text_field($_POST['order_folder_path'] ?? '');

    if (empty($format) || $total_sum <= 0 || empty($order_folder_path)) {
        // У цьому випадку це означає, що JS не передав усі необхідні дані.
        wp_die('Invalid order data received.');
    }
    
    // 2. Генерація або отримання ID замовлення
    $order_id_code = $_SESSION['ppo_order_id'] ?? ppo_generate_order_number();

    // 3. Оновлення сесії
    $_SESSION['ppo_order_id'] = $order_id_code;
    $_SESSION['ppo_total'] = $total_sum;
    
    // Зберігаємо деталі формату
    $_SESSION['ppo_formats'][$format] = [
        'files_data' => json_decode($files_data_json, true),
        'total_price' => $total_sum,
        'total_copies' => $total_copies,
        'order_folder_path' => $order_folder_path,
    ];
    
    // 4. Створення/Оновлення Custom Post Type (CPT)
    
    // Шукаємо, чи існує замовлення в БД за order_id_code
    $posts = get_posts([
        'post_type'  => 'ppo_order',
        'meta_key'   => 'ppo_order_id',
        'meta_value' => $order_id_code,
        'posts_per_page' => 1,
        'fields'     => 'ids',
    ]);
    
    $post_id = !empty($posts) ? $posts[0] : 0;
    
    $post_data = [
        'post_title'    => 'Замовлення #' . $order_id_code . ' - Очікує доставки',
        'post_type'     => 'ppo_order',
        'post_status'   => 'pending_delivery',
    ];

    if ($post_id) {
        $post_data['ID'] = $post_id;
        wp_update_post($post_data);
    } else {
        $post_id = wp_insert_post($post_data);
    }

    if (!is_wp_error($post_id)) {
        // Зберігаємо основні мета-дані, які нам потрібні
        update_post_meta($post_id, 'ppo_order_id', $order_id_code);
        update_post_meta($post_id, 'ppo_total_sum', $total_sum);
        update_post_meta($post_id, 'ppo_total_copies', $total_copies);
        update_post_meta($post_id, 'ppo_order_data_session', $_SESSION['ppo_formats']); // Зберігаємо всю сесію замовлення
        update_post_meta($post_id, 'ppo_cdn_root_path', $order_folder_path);
    }

    // 5. Перенаправлення на сторінку доставки
    // Припускаємо, що сторінка доставки має URL /orderpagedelivery/
    $redirect_url = home_url('/orderpagedelivery/'); 
    wp_redirect($redirect_url);
    exit;
}

// Примітка: Хук add_action('init', 'ppo_handle_forms'); вже є у photo-print-orders.php,
// тому тут його повторювати не потрібно.