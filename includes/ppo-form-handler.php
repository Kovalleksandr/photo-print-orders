<?php
/**
 * Обробка POST-запитів для форми доставки та підтвердження оплати.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Головний обробник форм.
 */
function ppo_handle_forms() {
    // 1. Обробка натискання кнопки "Оформити доставку" (редирект з кроку 1)
    if (isset($_POST['ppo_go_to_delivery'])) {
        ppo_handle_go_to_delivery();
    }
    
    // 2. Обробка форми доставки (Крок 2)
    if (isset($_POST['ppo_submit_delivery'])) {
        ppo_handle_delivery_submission();
    }

    // 3. Обробка форми оплати/підтвердження (Крок 3)
    if (isset($_POST['ppo_submit_payment'])) {
        ppo_handle_payment_submission();
    }
}

/**
 * Обробка переходу до доставки (перевірка наявності мінімального замовлення).
 */
function ppo_handle_go_to_delivery() {
    if (!isset($_SESSION['ppo_formats']) || empty(array_filter($_SESSION['ppo_formats'] ?? [], 'is_array'))) {
        $error_message = urlencode('Ви не додали жодного формату фотографій до замовлення.');
        wp_redirect(esc_url(home_url('/order/')) . '?error=' . $error_message);
        exit;
    }

    // Перевіряємо, чи є мінімальна сума
    if (($_SESSION['ppo_total'] ?? 0) < MIN_ORDER_SUM) {
        $error_message = urlencode('Мінімальна сума замовлення становить ' . MIN_ORDER_SUM . ' грн. Будь ласка, додайте ще фото.');
        wp_redirect(esc_url(home_url('/order/')) . '?error=' . $error_message);
        exit;
    }

    // Перенаправлення на сторінку доставки
    wp_redirect(esc_url(home_url('/orderpagedelivery/')));
    exit;
}


/**
 * Обробка форми доставки (Крок 2).
 */
function ppo_handle_delivery_submission() {
    check_admin_referer('ppo_delivery_nonce', 'ppo_nonce');
    
    $address = sanitize_textarea_field($_POST['address'] ?? '');

    if (empty($address)) {
        $error_message = urlencode('Будь ласка, вкажіть адресу доставки.');
        wp_redirect(esc_url(home_url('/orderpagedelivery/')) . '?error=' . $error_message);
        exit;
    }

    $_SESSION['ppo_delivery_address'] = $address;

    // Перенаправлення на сторінку оплати
    wp_redirect(esc_url(home_url('/orderpagepayment/')));
    exit;
}

/**
 * Обробка форми оплати/підтвердження (Крок 3).
 * Створює запис у CPT і очищає сесію.
 */
// ====================================================================
// 7. ОБРОБКА КРОКУ 3: ОПЛАТА
// ====================================================================
function ppo_handle_payment_submission() {
    if (!isset($_POST['ppo_submit_payment']) || !isset($_POST['ppo_nonce']) || !wp_verify_nonce($_POST['ppo_nonce'], 'ppo_payment_nonce')) {
        return; // Вихід, якщо запит недійсний
    }

    if (!isset($_SESSION['ppo_order_id'])) {
        $error_message = urlencode('Помилка сесії. Спробуйте почати замовлення спочатку.');
        wp_redirect(esc_url(home_url('/orderpagepayment/')) . '?error=' . $error_message);
        exit;
    }

    $payment_method = sanitize_text_field($_POST['payment_method'] ?? 'bank_transfer');

    // 1. Створюємо CPT "ppo_order" в базі даних WordPress
    $order_id = $_SESSION['ppo_order_id'];
    $total_sum = $_SESSION['ppo_total'] ?? 0;
    
    // Перевіряємо, чи існує вже CPT. Якщо так - оновлюємо.
    $existing_posts = get_posts([
        'post_type'  => 'ppo_order',
        'meta_key'   => 'ppo_order_id',
        'meta_value' => $order_id,
        'posts_per_page' => 1,
        'fields'     => 'ids',
    ]);

    if (!empty($existing_posts)) {
        $post_id = $existing_posts[0];
        // Оновлюємо статус, адресу та метод оплати
        $order_post_data = [
            'ID'          => $post_id,
            'post_status' => 'pending_payment', // Новий статус для очікування оплати
            'post_title'  => 'Замовлення #' . $order_id . ' - Очікує оплати',
        ];
        wp_update_post($order_post_data);
        update_post_meta($post_id, 'ppo_payment_method', $payment_method);
    } else {
        // Якщо CPT не існує (не повинно статися, але для безпеки), створюємо його.
        // Ця логіка повторюється з кроку 2, але тут вона фінальна.
        $order_post_data = [
            'post_title'   => 'Замовлення #' . $order_id . ' - Очікує оплати',
            'post_status'  => 'pending_payment',
            'post_type'    => 'ppo_order',
        ];

        $post_id = wp_insert_post($order_post_data);

        if (is_wp_error($post_id)) {
            $error_message = urlencode('Помилка при збереженні замовлення в базу даних: ' . $post_id->get_error_message());
            wp_redirect(esc_url(home_url('/orderpagepayment/')) . '?error=' . $error_message);
            exit;
        }
        
        // Зберігаємо мета-дані
        update_post_meta($post_id, 'ppo_order_id', $order_id);
        update_post_meta($post_id, 'ppo_total_sum', $total_sum);
        update_post_meta($post_id, 'ppo_delivery_address', $_SESSION['ppo_delivery_address'] ?? 'Не вказано');
        update_post_meta($post_id, 'ppo_order_data', $_SESSION['ppo_formats']);
        update_post_meta($post_id, 'ppo_payment_method', $payment_method);
        update_post_meta($post_id, 'ppo_files_path', PPO_UPLOAD_DIR . $order_id);
    }
    
    // 2. Логіка перенаправлення/оплати
    
    if ($payment_method === 'card') {
        // Ініціалізуємо LiqPay
        $liqpay = new PPO_LiqPay(LIQPAY_PUBLIC_KEY, LIQPAY_PRIVATE_KEY);

        // Параметри платежу LiqPay
        $params = array(
            'action'        => 'pay',
            'amount'        => $total_sum,
            'currency'      => 'UAH',
            'description'   => 'Оплата замовлення фотодруку №' . $order_id,
            'order_id'      => $order_id,
            'language'      => 'uk',
            'server_url'    => LIQPAY_SERVER_URL,
            'result_url'    => LIQPAY_RESULT_URL,
            // Додайте інші параметри за потреби (напр. phone, email)
        );

        // Зберігаємо форму LiqPay у сесію, щоб відобразити її на сторінці payment
        $_SESSION['ppo_liqpay_form'] = $liqpay->cnb_form($params);
        
        // Перенаправляємо на сторінку оплати, де буде відображена форма
        wp_redirect(esc_url(home_url('/orderpagepayment/')));
        exit;

    } elseif ($payment_method === 'bank_transfer') {
        // Перенаправлення на сторінку успіху (для банківського переказу),
        // де відобразяться реквізити.
        
        // Оновлюємо статус замовлення на "on-hold" (стандарт WooCommerce для очікування платежу)
        wp_update_post([
            'ID'          => $post_id,
            'post_status' => 'on-hold',
            'post_title'  => 'Замовлення #' . $order_id . ' - Очікує банківського переказу',
        ]);
        
        // Очищаємо сесію (окрім order_id для фінального відображення)
        unset($_SESSION['ppo_formats']);
        unset($_SESSION['ppo_total']);
        unset($_SESSION['ppo_delivery_address']);
        
        wp_redirect(esc_url(home_url('/orderpagepayment/')) . '?success=bank_transfer_submitted');
        exit;
    }
}
add_action('wp_loaded', 'ppo_handle_payment_submission');