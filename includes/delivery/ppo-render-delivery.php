<?php
// includes/form/ppo-delivery-form.php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Функція рендерингу шорткоду [ppo_delivery_form] (Крок 2: Доставка).
 */
function ppo_render_delivery_form() {
    
    // Перевірка наявності активного замовлення
    if (!isset($_SESSION['ppo_order_id'])) {
        return '<div class="ppo-message ppo-message-error"><p>Замовлення не знайдено. Будь ласка, почніть із <a href="' . esc_url(home_url('/order/')) . '">форми замовлення</a>.</p></div>';
    }
    
    ob_start();

    // Отримання контактних даних із сесії (для автозаповнення)
    $contact_info = $_SESSION['ppo_contact_info'] ?? [];
    $city_name = $_SESSION['ppo_delivery_details_array']['city_name'] ?? '';
    $warehouse_name = $_SESSION['ppo_delivery_details_array']['warehouse_name'] ?? '';

    // Отримання повідомлення про помилку з URL
    $error_message = sanitize_text_field($_GET['error'] ?? '');
    if (!empty($error_message)) {
        echo '<div class="ppo-message ppo-message-error" style="border-left: 5px solid red;"><p><strong>Помилка:</strong> ' . esc_html(urldecode($error_message)) . '</p></div>';
    }
    ?>

    <h2>Крок 2: Доставка та контактні дані</h2>
    
    <form method="post" action="">
        <?php wp_nonce_field('ppo_delivery_action', 'ppo_delivery_nonce'); ?>
        
        <h3>Контактні дані отримувача</h3>
        <div class="ppo-form-group">
            <label for="contact_name">ПІБ (повністю)</label>
            <input type="text" id="contact_name" name="contact_name" value="<?php echo esc_attr($contact_info['name'] ?? ''); ?>" required>
        </div>
        <div class="ppo-form-group">
            <label for="contact_phone">Телефон</label>
            <input type="tel" id="contact_phone" name="contact_phone" value="<?php echo esc_attr($contact_info['phone'] ?? ''); ?>" required>
        </div>
        <div class="ppo-form-group">
            <label for="contact_email">Email</label>
            <input type="email" id="contact_email" name="contact_email" value="<?php echo esc_attr($contact_info['email'] ?? ''); ?>" required>
        </div>

        <h3>Спосіб доставки</h3>
        <div class="ppo-form-group">
            <label>
                <input type="radio" name="delivery_type" value="novaposhta_warehouse" checked required>
                Нова Пошта (Відділення або Поштомат)
            </label>
        </div>
        
        <div id="np_delivery_fields">
            <h3>Адреса Нової Пошти</h3>
            
            <div class="ppo-form-group ppo-autocomplete-container">
                <label for="np-city-name">Місто (Населений пункт)</label>
                <input type="text" id="np-city-name" name="np_city_name" value="<?php echo esc_attr($city_name); ?>" placeholder="Почніть вводити назву міста..." required autocomplete="off">
                <input type="hidden" id="np-city-ref" name="np_city_ref" value="<?php echo esc_attr($_SESSION['ppo_delivery_details_array']['city_ref'] ?? ''); ?>" required>
                <ul id="np-city-list" class="ppo-autocomplete-list" style="display: none;"></ul>
            </div>

            <div class="ppo-form-group ppo-autocomplete-container">
                <label for="np-warehouse-name">Відділення / Поштомат</label>
                <input type="text" id="np-warehouse-name" name="np_warehouse_name" value="<?php echo esc_attr($warehouse_name); ?>" placeholder="Оберіть місто, а потім почніть вводити назву відділення..." required autocomplete="off" <?php echo empty($city_name) ? 'disabled' : ''; ?>>
                <input type="hidden" id="np-warehouse-ref" name="np_warehouse_ref" value="<?php echo esc_attr($_SESSION['ppo_delivery_details_array']['warehouse_ref'] ?? ''); ?>" required>
                <ul id="np-warehouse-list" class="ppo-autocomplete-list" style="display: none;"></ul>
            </div>
        </div>

        <div class="ppo-buttons-container">
            <a href="<?php echo esc_url(home_url('/order/')); ?>" class="ppo-button ppo-button-secondary">← Назад до форматів</a>
            <input type="submit" name="ppo_delivery_submit" value="Далі: До оплати →" class="ppo-button ppo-button-primary">
        </div>
    </form>

    <?php 
    // Підключаємо скрипти Нової Пошти
    if (class_exists('PPO_NovaPoshta_JS_Handler')) {
        PPO_NovaPoshta_JS_Handler::enqueue_scripts();
    }
    ?>

    <?php
    return ob_get_clean();
}

// Реєструємо шорткод
add_shortcode('ppo_delivery_form', 'ppo_render_delivery_form');