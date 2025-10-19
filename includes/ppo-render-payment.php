<?php
/**
 * Функція рендерингу шорткоду [ppo_payment_form] (Крок 3: Оплата та підтвердження).
 */

if (!defined('ABSPATH')) {
    exit;
}

function ppo_render_payment_form() {
    if (!isset($_SESSION['ppo_order_id']) || empty($_SESSION['ppo_delivery_address'])) {
        // Забезпечуємо стилі для повідомлення про помилку
        $style = '<style>.ppo-message { padding: 10px; margin: 10px 0; border-radius: 3px; } .ppo-message-error { color: red; background: #ffebee; }</style>';
        return $style . '<div class="ppo-message ppo-message-error"><p>Неповні дані. Почніть з <a href="' . esc_url(home_url('/orderpagedelivery/')) . '">доставки</a>.</p></div>';
    }
    
    ob_start();
    $total = $_SESSION['ppo_total'] ?? 0;
    
    // Фільтруємо, щоб показувати лише реальні формати, а не order_folder_path
    $session_formats = array_filter($_SESSION['ppo_formats'] ?? [], 'is_array');
    
    // Включаємо базові стилі, якщо вони не були включені раніше
    echo '<style>
        .ppo-button { display: inline-block !important; padding: 8px 16px; margin: 5px; text-decoration: none; border-radius: 3px; font-size: 14px; visibility: visible !important; }
        .ppo-button-primary { background: #0073aa; color: white; }
        .ppo-button-secondary { background: #f7f7f7; color: #0073aa; border: 1px solid #0073aa; }
        .ppo-total-sum { font-weight: bold; margin: 10px 0; }
        .ppo-message { padding: 10px; margin: 10px 0; border-radius: 3px; }
        .ppo-message-success { color: green; background: #e8f5e8; }
        .ppo-message-error { color: red; background: #ffebee; }
        .ppo-buttons-container { margin-top: 15px; }
    </style>';

    if (isset($_GET['success']) && $_GET['success'] === 'order_completed'): ?>
        <div class="ppo-message ppo-message-success">
            <h2>🎉 Замовлення успішно оформлено!</h2>
            <p>Ваше замовлення (**№<?php echo esc_html($_SESSION['ppo_order_id'] ?? 'N/A'); ?>**) прийнято в обробку. Загальна сума: **<?php echo esc_html($total); ?> грн**.</p>
            <p>Наші менеджери зв'яжуться з вами для уточнення деталей оплати та відправлення.</p>
        </div>
        <p><a href="<?php echo esc_url(home_url('/order/?clear_session=1')); ?>" class="ppo-button ppo-button-primary">Створити нове замовлення</a></p>
    <?php else: ?>
        <h2>Крок 3: Оплата та підтвердження</h2>
        <p>Ваше замовлення **№<?php echo esc_html($_SESSION['ppo_order_id']); ?>**:</p>
        <ul>
            <?php foreach ($session_formats as $format => $details): ?>
                <li>**<?php echo esc_html($format); ?>**: <?php echo esc_html($details['total_copies']); ?> копій (<?php echo esc_html($details['total_price']); ?> грн)</li>
            <?php endforeach; ?>
        </ul>
        <p>Адреса доставки: **<?php echo esc_html($_SESSION['ppo_delivery_address'] ?? 'Не вказано'); ?>**</p>
        <p class="ppo-total-sum">Загальна сума до сплати: <span style="font-size: 1.2em;"><?php echo esc_html($total); ?> грн</span></p>

        <p>Виберіть спосіб оплати:</p>
        <form method="post">
            <?php wp_nonce_field('ppo_payment_nonce', 'ppo_nonce'); ?>
            
            <label><input type="radio" name="payment_method" value="card" required checked> Оплата карткою (LiqPay/інший сервіс)</label><br>
            <label><input type="radio" name="payment_method" value="bank_transfer" required> Оплата за реквізитами</label><br><br>
            
            <div class="ppo-buttons-container">
                <a href="<?php echo esc_url(home_url('/orderpagedelivery/')); ?>" class="ppo-button ppo-button-secondary">← Назад до доставки</a>
                <input type="submit" name="ppo_submit_payment" value="Підтвердити замовлення" class="ppo-button ppo-button-primary">
            </div>
        </form>
    <?php endif;

    return ob_get_clean();
}