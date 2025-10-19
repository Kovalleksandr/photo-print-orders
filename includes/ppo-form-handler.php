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
function ppo_handle_payment_submission() {
    check_admin_referer('ppo_payment_nonce', 'ppo_nonce');
    
    $payment_method = sanitize_text_field($_POST['payment_method'] ?? 'card');
    
    if (!isset($_SESSION['ppo_order_id']) || empty(array_filter($_SESSION['ppo_formats'] ?? [], 'is_array'))) {
        $error_message = urlencode('Замовлення не знайдено в сесії. Почніть спочатку.');
        wp_redirect(esc_url(home_url('/order/')) . '?error=' . $error_message);
        exit;
    }
    
    // 1. Збір фінальних даних замовлення
    $order_data = [
        'order_id'         => $_SESSION['ppo_order_id'],
        'total'            => $_SESSION['ppo_total'] ?? 0,
        'formats'          => $_SESSION['ppo_formats'], // Містить order_folder_path та деталі
        'delivery_address' => $_SESSION['ppo_delivery_address'] ?? 'Самовивіз/Не вказано',
        'payment_method'   => $payment_method,
        'status'           => 'new', // Початковий статус
        'timestamp'        => current_time('mysql'),
    ];

    // 2. Створення запису CPT
    $post_data = [
        'post_title'    => $order_data['order_id'], // Тимчасовий title
        'post_type'     => 'ppo_order',
        'post_status'   => 'publish',
    ];
    
    $post_id = wp_insert_post($post_data);

    if (is_wp_error($post_id)) {
        error_log("PPO CPT Creation Error: " . $post_id->get_error_message());
        $error_message = urlencode('Помилка збереження замовлення. Спробуйте пізніше.');
        wp_redirect(esc_url(home_url('/orderpagepayment/')) . '?error=' . $error_message);
        exit;
    }

    // 3. Збереження метаданих
    update_post_meta($post_id, 'ppo_order_data', $order_data);
    
    // Оновлюємо title на фінальний ID
    ppo_set_order_title($post_id); 

    // 4. Очищення сесії після успішного оформлення
    unset($_SESSION['ppo_order_id']);
    unset($_SESSION['ppo_formats']);
    unset($_SESSION['ppo_total']);
    unset($_SESSION['ppo_delivery_address']);

    // 5. Перенаправлення на сторінку успіху
    wp_redirect(esc_url(home_url('/orderpagepayment/')) . '?success=order_completed');
    exit;
}