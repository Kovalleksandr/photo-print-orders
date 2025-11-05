<?php
// includes/payment/ppo-liqpay-callback.php

/**
 * Основна функція обробки серверного сповіщення (Callback) від LiqPay.
 * Викликається з хука template_redirect у photo-print-orders.php.
 */
function ppo_handle_liqpay_callback() {
    
    // 1. Перевірка методу запиту. LiqPay завжди надсилає POST.
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        // Якщо це не POST (наприклад, GET-запит від браузера), 
        // просто виходимо з кодом 200, щоб не відображати помилку.
        http_response_code(200); 
        die('OK');
    }

    // Відключаємо відображення помилок, щоб не засмічувати відповідь для LiqPay
    @ini_set('display_errors', 'Off');
    
    // Перевірка, чи отримали ми POST-дані
    if (empty($_POST['data']) || empty($_POST['signature'])) {
        http_response_code(400); // Помилковий запит
        // Повертаємо "OK", щоб LiqPay не повторював запит, але це краще логувати
        // error_log('LiqPay Callback: Missing data or signature in POST.');
        die('OK'); 
    }

    // ВАЖЛИВО: Ці ключі повинні бути ідентичні тим, що використовуються в ppo-render-payment.php
    // У бойовому середовищі їх слід отримувати з налаштувань плагіна або ppo-config.php.
    $public_key = LIQPAY_PUBLIC_KEY; 
    $private_key = LIQPAY_PRIVATE_KEY;

    try {
        $data_base64 = $_POST['data'];
        $signature_received = $_POST['signature'];
        
        // 2. Верифікація підпису
        // Формула: base64_encode(sha1(private_key + data + private_key, 1))
        $calculated_signature = base64_encode( sha1($private_key . $data_base64 . $private_key, true) );

        if ($calculated_signature !== $signature_received) {
            http_response_code(403); // Доступ заборонено
            // error_log('LiqPay Callback: Invalid signature received.');
            die('Invalid signature'); // LiqPay буде повторювати запит, якщо не отримає "OK"
        }

        // 3. Декодування та отримання даних
        $data = base64_decode($data_base64);
        $data = json_decode($data, true);
        
        $order_id = sanitize_text_field($data['order_id'] ?? '');
        $status = sanitize_text_field($data['status'] ?? 'unknown');
        $payment_amount = floatval($data['amount'] ?? 0);
        
        // 4. Пошук замовлення в CPT 'ppo_order' за унікальним номером
        // Припускаємо, що CPT назва замовлення == order_id
        $order_post = get_page_by_title($order_id, OBJECT, 'ppo_order');
        if (!$order_post) {
            // error_log("LiqPay Callback: Order ID {$order_id} not found.");
            http_response_code(404);
            die('Order not found');
        }
        
        // 5. Оновлення статусу замовлення 
        $current_payment_status = get_post_meta($order_post->ID, 'ppo_payment_status', true);
        
        // Логування повних даних для аудиту
        update_post_meta($order_post->ID, 'ppo_liqpay_last_callback_data', $data);

        if ($status === 'success' || $status === 'sandbox') {
            if ($current_payment_status !== 'paid') {
                // Платіж успішний
                update_post_meta($order_post->ID, 'ppo_payment_status', 'paid');
                update_post_meta($order_post->ID, 'ppo_total_paid', $payment_amount);
                update_post_meta($order_post->ID, 'ppo_payment_method', 'LiqPay');
                update_post_meta($order_post->ID, 'ppo_payment_date', time());
                
                // Оновлення статусу CPT (якщо ви визначите 'ppo-paid')
                wp_update_post([
                    'ID'          => $order_post->ID,
                    'post_status' => 'ppo-paid', 
                ]);
            }
        } elseif (in_array($status, ['failure', 'error', 'reversed'])) {
             // Помилка оплати або повернення коштів
             update_post_meta($order_post->ID, 'ppo_payment_status', 'failed');
        } elseif ($status === 'processing' || $status === 'wait_secure') {
             // Очікування / обробка
             update_post_meta($order_post->ID, 'ppo_payment_status', 'pending');
        }
        
    } catch (\Exception $e) {
        // Логування критичної помилки обробки
        // error_log('LiqPay Callback Fatal Error: ' . $e->getMessage());
        http_response_code(500);
        die('OK');
    }

    // 6. LiqPay очікує відповідь "OK" у разі успішної обробки
    echo "OK";
    exit;
}