<?php
// includes/payment/ppo-liqpay-callback.php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Обробка Callback від LiqPay
 */
function ppo_handle_liqpay_callback() {
    // LiqPay надсилає дані в POST параметрах 'data' та 'signature'
    if (empty($_POST['data']) || empty($_POST['signature'])) {
        error_log('PPO LiqPay Error: Empty callback data.');
        die('No data');
    }

    $data_base64 = $_POST['data'];
    $signature   = $_POST['signature'];

    // Перевірка підпису для безпеки
    $expected_signature = base64_encode(sha1(
        LIQPAY_PRIVATE_KEY . $data_base64 . LIQPAY_PRIVATE_KEY, 
        true
    ));

    if ($signature !== $expected_signature) {
        error_log('PPO LiqPay Error: Invalid signature.');
        die('Invalid signature');
    }

    $data = json_decode(base64_decode($data_base64), true);
    $order_id = $data['order_id'] ?? '';
    $status   = $data['status'] ?? ''; // success, sandbox, wait_accept, failure

    if (!$order_id) {
        die('No order ID');
    }

    // Шукаємо замовлення в базі за мета-полем ppo_order_id
    $query = new WP_Query([
        'post_type'  => 'ppo_order',
        'meta_query' => [
            [
                'key'   => 'ppo_order_id',
                'value' => $order_id,
            ]
        ],
        'posts_per_page' => 1
    ]);

    if ($query->have_posts()) {
        $post_id = $query->posts[0]->ID;

        if (in_array($status, ['success', 'sandbox', 'wait_accept'])) {
            // Оновлюємо статус поста на оплачений
            wp_update_post([
                'ID'          => $post_id,
                'post_status' => 'ppo_paid'
            ]);

            // Зберігаємо деталі оплати в мета-поля
            update_post_meta($post_id, 'ppo_payment_status', 'paid');
            update_post_meta($post_id, 'ppo_payment_date', current_time('mysql'));
            update_post_meta($post_id, 'ppo_liqpay_raw_data', $data); // Для логів
        } else {
            // Якщо оплата не пройшла
            update_post_meta($post_id, 'ppo_payment_status', 'failed');
            wp_update_post([
                'ID'          => $post_id,
                'post_status' => 'ppo_failed'
            ]);
        }
    }

    // LiqPay очікує відповідь 200 OK
    die('OK');
}