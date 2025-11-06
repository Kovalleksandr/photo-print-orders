<?php
// includes/payment/ppo-liqpay-callback.php

if (!defined('ABSPATH')) {
    exit;
}

// Потрібен для використання класу LiqPay
// Ви повинні переконатися, що цей файл підключений після composer/autoload.php
if (!class_exists('LiqPay')) {
    error_log('LiqPay FATAL: LiqPay SDK class not available for callback.');
    return;
}

/**
 * Реєстрація WP Rewrite Endpoint для обробки серверного callback від LiqPay.
 */
function ppo_register_liqpay_callback_endpoint() {
    // Реєструємо 'liqpay-callback' як кінцеву точку, доступну лише з додаванням /feed/
    // Це дозволяє WordPress правильно ініціалізувати систему, але працювати як зовнішня точка.
    add_rewrite_endpoint('liqpay-callback', EP_ROOT);
}
add_action('init', 'ppo_register_liqpay_callback_endpoint');


/**
 * Обробка POST-запиту від LiqPay.
 * Ця функція викликається при відвідуванні URL /liqpay-callback/.
 */
function ppo_handle_liqpay_callback() {
    // 1. Перевірка, чи це наш endpoint і чи це POST-запит
    if (!isset($GLOBALS['wp_query']) || !isset($GLOBALS['wp_query']->query_vars['liqpay-callback'])) {
        return;
    }
    
    // Переконаємось, що ми обробляємо POST-запит
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['data']) || empty($_POST['signature'])) {
        http_response_code(400);
        die('Invalid LiqPay callback request.');
    }

    // Зупиняємо стандартну роботу WordPress, виводимо лише чистий результат
    header('Content-Type: text/plain');
    
    $public_key = LIQPAY_PUBLIC_KEY; 
    $private_key = LIQPAY_PRIVATE_KEY;
    
    $data = sanitize_text_field($_POST['data']);
    $signature = sanitize_text_field($_POST['signature']);

    try {
        $liqpay = new LiqPay($public_key, $private_key);
        
        // 2. Валідація підпису LiqPay
        $expected_signature = $liqpay->str_to_sign($private_key . $data . $private_key);

        if ($expected_signature !== $signature) {
            http_response_code(403);
            die('LiqPay Callback: Signature verification failed.');
        }

        // 3. Декодування даних та отримання статусу
        $response = json_decode(base64_decode($data), true);
        
        $order_id_code = $response['order_id'] ?? null;
        $liqpay_status = $response['status'] ?? 'unknown';
        $amount = $response['amount'] ?? 0;
        $transaction_id = $response['payment_id'] ?? ''; // ID транзакції LiqPay
        
        if (empty($order_id_code)) {
            http_response_code(400);
            die('LiqPay Callback: Order ID is missing.');
        }

        // 4. Пошук замовлення в CPT за унікальним кодом
        $posts = get_posts([
            'post_type'  => 'ppo_order',
            'meta_key'   => 'ppo_order_id',
            'meta_value' => $order_id_code,
            'posts_per_page' => 1,
            'fields'     => 'ids',
        ]);
        
        if (empty($posts)) {
            // Замовлення не знайдено, можливо, користувач його видалив або помилка
            error_log('LiqPay Callback Error: Order ID ' . $order_id_code . ' not found in DB.');
            http_response_code(200); // Повертаємо 200, щоб LiqPay не намагався знову
            die('Order not found.');
        }
        
        $post_id = $posts[0];
        $new_post_status = get_post_status($post_id);
        $payment_status_meta = 'pending';

        // 5. Оновлення статусу замовлення
        if ($liqpay_status === 'success' || $liqpay_status === 'sandbox') {
            $new_post_status = 'ppo_paid'; 
            $payment_status_meta = 'paid';
            $post_title_suffix = ' - Оплачено';
        } elseif ($liqpay_status === 'failure' || $liqpay_status === 'error' || $liqpay_status === 'reversed') {
            $new_post_status = 'ppo_failed';
            $payment_status_meta = 'failed';
            $post_title_suffix = ' - Помилка оплати';
        } elseif ($liqpay_status === 'processing' || $liqpay_status === 'wait_secure' || $liqpay_status === 'wait_accept') {
            $new_post_status = 'pending_payment'; // Залишаємось, але оновлюємо мета
            $payment_status_meta = 'pending';
            $post_title_suffix = ' - В обробці';
        } else {
            // Інші статуси, які не вимагають зміни CPT статусу
            http_response_code(200);
            die('Status: ' . $liqpay_status . ' processed.');
        }
        
        // Оновлення CPT статусу
        wp_update_post([
            'ID'          => $post_id,
            'post_status' => $new_post_status,
            'post_title'  => 'Замовлення #' . $order_id_code . $post_title_suffix,
        ]);
        
        // Оновлення мета-даних платежу
        update_post_meta($post_id, 'ppo_payment_status', $payment_status_meta);
        update_post_meta($post_id, 'ppo_total_paid', $amount);
        update_post_meta($post_id, 'ppo_payment_date', time());
        update_post_meta($post_id, 'ppo_liqpay_payment_id', $transaction_id);

        // 6. Успішне завершення
        http_response_code(200);
        die('LiqPay Callback: Payment Status ' . $liqpay_status . ' processed for order ' . $order_id_code . '.');

    } catch (\Exception $e) {
        // Логування помилок SDK або обробки
        error_log('LiqPay Callback Exception: ' . $e->getMessage());
        http_response_code(500);
        die('Internal Server Error.');
    }
}
add_action('template_redirect', 'ppo_handle_liqpay_callback');