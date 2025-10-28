<?php
/**
 * Функція рендерингу шорткоду [ppo_payment_form] (Крок 3: Оплата та підтвердження).
 */

if (!defined('ABSPATH')) {
    exit;
}

function ppo_render_payment_form() {
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
        .ppo-payment-box { border: 1px solid #ccc; padding: 20px; border-radius: 5px; margin-top: 20px; background: #f9f9f9; }
    </style>';
    
    if (!isset($_SESSION['ppo_order_id'])) {
        return '<div class="ppo-message ppo-message-error"><p>Замовлення не знайдено. Будь ласка, почніть із <a href="' . esc_url(home_url('/order/')) . '">форми замовлення</a>.</p></div>';
    }
    
    ob_start();
    $order_id = $_SESSION['ppo_order_id'];
    $total = $_SESSION['ppo_total'] ?? 0;
    
    // Фільтруємо, щоб показувати лише реальні формати
    $session_formats = array_filter($_SESSION['ppo_formats'] ?? [], 'is_array');
    
    // --- Обробка повідомлень про успіх/помилку ---

    if (isset($_GET['success']) && $_GET['success'] === 'bank_transfer_submitted'):
        // Успішне підтвердження банківського переказу
    ?>
        <div class="ppo-message ppo-message-success">
            <h2>✅ Замовлення №<?php echo esc_html($order_id); ?> успішно оформлено!</h2>
            <p>Ваше замовлення прийнято в обробку. Загальна сума: **<?php echo esc_html($total); ?> грн**.</p>
        </div>
        
        <div class="ppo-payment-box">
            <h3>Оплата за реквізитами (Банківський переказ)</h3>
            <p>Будь ласка, здійсніть переказ на суму **<?php echo esc_html($total); ?> грн** за наступними реквізитами:</p>
            
            <p>
                **Отримувач:** ФОП Приклад Прикладович<br>
                **ІПН:** 0000000000<br>
                **Рахунок IBAN:** UA000000000000000000000000000<br>
                **Призначення платежу:** Оплата замовлення №<?php echo esc_html($order_id); ?>
            </p>
            <p>Після надходження коштів ми почнемо друк. Наші менеджери зв'яжуться з вами.</p>
        </div>
        
        <p class="ppo-buttons-container"><a href="<?php echo esc_url(home_url('/order/?clear_session=1')); ?>" class="ppo-button ppo-button-secondary">Створити нове замовлення</a></p>

    <?php elseif (isset($_GET['success']) && $_GET['success'] === 'payment_success'):
        // Успішна оплата LiqPay (повернення з result_url)
    ?>
        <div class="ppo-message ppo-message-success">
            <h2>🥳 Оплата успішна!</h2>
            <p>Замовлення **№<?php echo esc_html($order_id); ?>** успішно сплачено на суму **<?php echo esc_html($total); ?> грн**.</p>
            <p>Ми отримали підтвердження і розпочинаємо роботу. Очікуйте повідомлення від наших менеджерів!</p>
        </div>
        
        <p class="ppo-buttons-container"><a href="<?php echo esc_url(home_url('/order/?clear_session=1')); ?>" class="ppo-button ppo-button-primary">Створити нове замовлення</a></p>

    <?php elseif (isset($_SESSION['ppo_liqpay_form'])):
        // Відображення форми LiqPay після вибору "карткою"
        $liqpay_form = $_SESSION['ppo_liqpay_form'];
        unset($_SESSION['ppo_liqpay_form']); // Видаляємо форму з сесії, щоб не відображалася знову

    ?>
        <h2>Крок 3: Оплата замовлення №<?php echo esc_html($order_id); ?></h2>
        <p>Для завершення замовлення **№<?php echo esc_html($order_id); ?>** на суму **<?php echo esc_html($total); ?> грн** натисніть кнопку "Оплатити LiqPay". Ви будете перенаправлені на платіжну сторінку.</p>
        
        <div class="ppo-payment-box" style="text-align: center;">
            <?php echo $liqpay_form; ?>
        </div>
        <p class="ppo-buttons-container">
            <a href="<?php echo esc_url(home_url('/orderpagedelivery/')); ?>" class="ppo-button ppo-button-secondary">← Назад до доставки</a>
        </p>
        
    <?php else: 
        // Відображення початкової форми вибору методу оплати
    ?>
        <h2>Крок 3: Оплата та підтвердження</h2>
        <p>Ваше замовлення **№<?php echo esc_html($order_id); ?>**:</p>
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
            
            <label><input type="radio" name="payment_method" value="card" required checked> Оплата карткою (LiqPay)</label><br>
            <label><input type="radio" name="payment_method" value="bank_transfer" required> Оплата за реквізитами (Банківський переказ)</label><br><br>
            
            <div class="ppo-buttons-container">
                <a href="<?php echo esc_url(home_url('/orderpagedelivery/')); ?>" class="ppo-button ppo-button-secondary">← Назад до доставки</a>
                <input type="submit" name="ppo_submit_payment" value="Підтвердити замовлення" class="ppo-button ppo-button-primary">
            </div>
        </form>
    <?php endif;

    return ob_get_clean();
}