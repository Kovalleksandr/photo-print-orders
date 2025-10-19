<?php
/**
 * Функція рендерингу шорткоду [ppo_delivery_form] (Крок 2: Доставка).
 */

if (!defined('ABSPATH')) {
    exit;
}

function ppo_render_delivery_form() {
    if (!isset($_SESSION['ppo_order_id']) || empty(array_filter($_SESSION['ppo_formats'] ?? [], 'is_array'))) {
        return '<div class="ppo-message ppo-message-error"><p>Замовлення не знайдено. Будь ласка, почніть із <a href="' . esc_url(home_url('/order/')) . '">форми замовлення</a>.</p></div>';
    }
    
    ob_start();
    if (isset($_GET['error'])) {
        echo '<div class="ppo-message ppo-message-error"><p>' . esc_html(urldecode($_GET['error'])) . '</p></div>';
    }
    ?>
    <style>
        /* Стилі для форми: кнопки, повідомлення */
        .ppo-button { display: inline-block !important; padding: 8px 16px; margin: 5px; text-decoration: none; border-radius: 3px; font-size: 14px; visibility: visible !important; }
        .ppo-button-primary { background: #0073aa; color: white; }
        .ppo-button-primary:hover { background: #005177; }
        .ppo-button-secondary { background: #f7f7f7; color: #0073aa; border: 1px solid #0073aa; }
        .ppo-message { padding: 10px; margin: 10px 0; border-radius: 3px; }
        .ppo-message-error { color: red; background: #ffebee; }
        .ppo-buttons-container { margin-top: 15px; }
        .ppo-delivery-form-container label { display: block; margin-top: 10px; font-weight: bold; }
    </style>
    <div class="ppo-delivery-form-container">
        <h2>Крок 2: Оформлення доставки</h2>
        <p>Ваше замовлення **№<?php echo esc_html($_SESSION['ppo_order_id']); ?>** на суму **<?php echo esc_html($_SESSION['ppo_total'] ?? 0); ?> грн** готове. Вкажіть адресу доставки.</p>
        
        <form method="post">
            <?php wp_nonce_field('ppo_delivery_nonce', 'ppo_nonce'); ?>
            
            <label for="address">Адреса доставки (напр., Нова Пошта, УкрПошта, кур'єр):</label>
            <textarea name="address" id="address" rows="5" required style="width: 100%; padding: 10px;"><?php echo esc_textarea($_SESSION['ppo_delivery_address'] ?? ''); ?></textarea>
            
            <div class="ppo-buttons-container">
                <a href="<?php echo esc_url(home_url('/order/')); ?>" class="ppo-button ppo-button-secondary">← Назад до замовлення</a>
                <input type="submit" name="ppo_submit_delivery" value="Перейти до оплати" class="ppo-button ppo-button-primary">
            </div>
        </form>
    </div>
    <?php
    return ob_get_clean();
}