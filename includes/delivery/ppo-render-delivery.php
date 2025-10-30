<?php
// includes/delivery/ppo-render-delivery.php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Рендерить форму вибору доставки "Нова Пошта".
 * @return string HTML-форма.
 */
function ppo_render_delivery_form() {
    // Перевірка, чи є активна сесія замовлення (OrderID)
    if (!isset($_SESSION['ppo_order_id'])) {
        return '<div class="ppo-delivery-alert ppo-error">Спочатку оформіть замовлення.</div>';
    }

    // Отримання попередньо збережених даних, якщо є
    $saved_delivery = $_SESSION['ppo_delivery_address'] ?? [];
    
    // !!! УВАГА: Використовуємо коректні ключі з сесії для відображення
    $saved_city_name = $saved_delivery['city_description'] ?? ''; 
    $saved_city_ref = $saved_delivery['settlement_ref'] ?? '';
    $saved_warehouse_description = $saved_delivery['warehouse_description'] ?? '';
    $saved_warehouse_ref = $saved_delivery['warehouse_ref'] ?? '';

    ob_start();
    ?>
    <div id="ppo-delivery-form-container" class="ppo-form-container">
        <h2>🚚 Оформлення доставки (Нова Пошта)</h2>
        <div id="ppo-delivery-alert-messages"></div>
        
        <form id="nova-poshta-delivery-form" method="post">
            
            <input type="hidden" name="ppo_delivery_nonce" value="<?php echo wp_create_nonce('ppo_delivery_action'); ?>">
            <input type="hidden" name="action" value="ppo_save_delivery">
            
            <div class="ppo-form-group">
                <label for="np-city-name">Населений пункт:</label>
                <input 
                    type="text" 
                    id="np-city-name" 
                    name="city_search" 
                    value="<?php echo esc_attr($saved_city_name); ?>"
                    placeholder="Почніть вводити назву міста/селища" 
                    required 
                    class="ppo-input-field"
                >
                <input type="hidden" id="np-city-ref" name="settlement_ref" value="<?php echo esc_attr($saved_city_ref); ?>" required>
                <input type="hidden" id="np-city-name-hidden" name="np_city_name" value="<?php echo esc_attr($saved_city_name); ?>">
            </div>

            <div class="ppo-form-group">
                <label for="np-warehouse-name">Відділення / Поштомат:</label>
                <input 
                    type="text" 
                    id="np-warehouse-name" 
                    name="warehouse_search" 
                    value="<?php echo esc_attr($saved_warehouse_description); ?>"
                    placeholder="Введіть номер або назву відділення" 
                    required 
                    <?php echo empty($saved_city_ref) ? 'disabled' : ''; ?> 
                    class="ppo-input-field"
                >
                <input type="hidden" id="np-warehouse-ref" name="warehouse_ref" value="<?php echo esc_attr($saved_warehouse_ref); ?>" required>
            </div>
            
            <div class="ppo-form-group">
                <label for="recipient_name">ПІБ отримувача:</label>
                <input 
                    type="text" 
                    id="recipient_name" 
                    name="recipient_name" 
                    placeholder="Іванов Іван Іванович" 
                    required 
                    class="ppo-input-field"
                >
            </div>

            <div class="ppo-form-group">
                <label for="recipient_phone">Телефон отримувача:</label>
                <input 
                    type="tel" 
                    id="recipient_phone" 
                    name="recipient_phone" 
                    placeholder="+380XXXXXXXXX" 
                    pattern="^\+380\d{9}$"
                    required 
                    class="ppo-input-field"
                >
            </div>
            
            <button type="submit" id="save-delivery-btn" class="ppo-submit-btn" disabled>
                Зберегти адресу та перейти до оплати
            </button>
            <div id="ppo-delivery-loader" class="ppo-loader" style="display: none;"></div>
        </form>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Обробка POST-запиту на збереження адреси доставки у сесії та оновлення замовлення.
 */
function ppo_handle_delivery_form() {
    if (!isset($_POST['action']) || $_POST['action'] !== 'ppo_save_delivery') {
        return;
    }
    
    // Перевірка Nonce та наявності ID замовлення
    if (!isset($_POST['ppo_delivery_nonce']) || !wp_verify_nonce($_POST['ppo_delivery_nonce'], 'ppo_delivery_action') || !isset($_SESSION['ppo_order_id'])) {
        wp_die('Security check failed or Order ID missing.');
    }
    
    // 1. Очищення та валідація даних
    $settlement_ref = sanitize_text_field($_POST['settlement_ref'] ?? '');
    $warehouse_ref = sanitize_text_field($_POST['warehouse_ref'] ?? '');
    $city_search = sanitize_text_field($_POST['city_search'] ?? '');
    $warehouse_search = sanitize_text_field($_POST['warehouse_search'] ?? '');
    $recipient_name = sanitize_text_field($_POST['recipient_name'] ?? '');
    $recipient_phone = sanitize_text_field($_POST['recipient_phone'] ?? '');

    if (empty($settlement_ref) || empty($warehouse_ref) || empty($recipient_name) || empty($recipient_phone)) {
        // У реальному житті краще використовувати AJAX для валідації і не wp_die
        wp_die('Будь ласка, заповніть усі обов\'язкові поля доставки.');
    }

    // 2. Збереження даних у сесії
    $_SESSION['ppo_delivery_address'] = [
        'city_description' => $city_search,
        'settlement_ref' => $settlement_ref,
        'warehouse_description' => $warehouse_search,
        'warehouse_ref' => $warehouse_ref,
        'recipient_name' => $recipient_name,
        'recipient_phone' => $recipient_phone,
    ];
    
    // 3. Оновлення посту замовлення (CRITICAL)
    // Знайдіть пост замовлення за $_SESSION['ppo_order_id']
    $order_id_code = $_SESSION['ppo_order_id'];
    $posts = get_posts([
        'post_type' => 'ppo_order',
        'meta_key' => 'ppo_order_id',
        'meta_value' => $order_id_code,
        'posts_per_page' => 1,
        'fields' => 'ids',
    ]);

    if (!empty($posts)) {
        $post_id = $posts[0];
        
        // Зберігаємо мета-дані доставки
        update_post_meta($post_id, 'ppo_np_settlement_ref', $settlement_ref);
        update_post_meta($post_id, 'ppo_np_warehouse_ref', $warehouse_ref);
        update_post_meta($post_id, 'ppo_delivery_address_full', "{$city_search}, {$warehouse_search}");
        update_post_meta($post_id, 'ppo_recipient_name', $recipient_name);
        update_post_meta($post_id, 'ppo_recipient_phone', $recipient_phone);
        
        // Оновлюємо статус/заголовок, якщо потрібно
        wp_update_post([
            'ID' => $post_id,
            'post_title' => 'Замовлення #' . $order_id_code . ' - Очікує оплати',
            'post_status' => 'pending_payment',
        ]);
    }

    // 4. Перенаправлення на сторінку оплати
    // Припускаємо, що сторінка оплати має URL /orderpagepayment/
    $redirect_url = home_url('/orderpagepayment/'); 
    wp_redirect($redirect_url);
    exit;
}

add_action('init', 'ppo_handle_delivery_form');