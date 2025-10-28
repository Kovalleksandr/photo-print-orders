<?php
/**
 * Рендеринг форми доставки (Крок 2: Вибір доставки).
 * Використовується в шорткоді [ppo_delivery_form].
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Шорткод для форми доставки.
 */
function ppo_render_delivery_form($atts) {
    // Ініціалізація сесії (якщо не запущена)
    if (!session_id()) {
        session_start();
    }

    // Перевірка наявності замовлення в сесії
    if (!isset($_SESSION['ppo_total']) || $_SESSION['ppo_total'] < MIN_ORDER_SUM) {
        return '<div class="ppo-error">Помилка: Замовлення не ініціалізовано. <a href="' . esc_url(home_url('/order/')) . '">Повернутися до замовлення</a></div>';
    }

    // Обробка помилок з URL
    $error_message = '';
    if (isset($_GET['error'])) {
        $error_message = urldecode($_GET['error']);
    }

    ob_start();
    ?>
    <div class="ppo-delivery-form">
        <?php if ($error_message): ?>
            <div class="ppo-error alert alert-danger"><?php echo esc_html($error_message); ?></div>
        <?php endif; ?>

        <h2>Крок 2: Оформлення доставки</h2>
        <p>Сума замовлення: <strong><?php echo number_format($_SESSION['ppo_total'], 2); ?> грн</strong></p>

        <form method="post" action="">
            <input type="hidden" name="ppo_submit_delivery" value="1">
            <?php wp_nonce_field('ppo_delivery_nonce', 'ppo_nonce'); ?>

            <!-- Вибір методу доставки -->
            <div class="ppo-field">
                <label for="delivery_method">Метод доставки:</label>
                <select name="delivery_method" id="delivery_method" required>
                    <option value="nova_poshta" selected>Нова Пошта (відділення)</option>
                    <option value="pickup">Самовивіз з ательє</option>
                    <option value="other">Інший спосіб (кур'єр/пошта)</option>
                </select>
            </div>

            <!-- Блок для Нової Пошти -->
            <div id="nova_poshta_fields">
                <?php do_action('ppo_render_delivery'); // Рендерить поля NP ?>

                <!-- Ім'я та телефон одержувача (глобальні для NP) -->
                <div class="ppo-field">
                    <label for="np_recipient_name">Ім'я одержувача:</label>
                    <input type="text" id="np_recipient_name" name="np_recipient_name" placeholder="ПІБ" required>
                </div>
                <div class="ppo-field">
                    <label for="np_recipient_phone">Телефон одержувача:</label>
                    <input type="tel" id="np_recipient_phone" name="np_recipient_phone" placeholder="+380 XX XXX XX XX" required>
                </div>
            </div>

            <!-- Блок для інших методів -->
            <div id="other_fields" style="display: none;">
                <div class="ppo-field">
                    <label for="delivery_details">Деталі доставки:</label>
                    <textarea id="delivery_details" name="delivery_details" rows="3" placeholder="Вкажіть адресу, дату/час самовивозу або інструкції для кур'єра" required></textarea>
                </div>
            </div>

            <div class="ppo-submit">
                <button type="submit" class="btn btn-primary">Наступний крок: Оплата</button>
                <a href="<?php echo esc_url(home_url('/order/')); ?>" class="btn btn-secondary">Повернутися до замовлення</a>
            </div>
        </form>
    </div>

    <script>
        jQuery(document).ready(function($) {
            $('#delivery_method').change(function() {
                if ($(this).val() === 'nova_poshta') {
                    $('#nova_poshta_fields').show();
                    $('#other_fields').hide();
                } else {
                    $('#nova_poshta_fields').hide();
                    $('#other_fields').show();
                }
            });
        });
    </script>

    <style>
        .ppo-delivery-form { max-width: 600px; margin: 0 auto; padding: 20px; }
        .ppo-field { margin-bottom: 15px; }
        .ppo-field label { display: block; font-weight: bold; margin-bottom: 5px; }
        .ppo-field input, .ppo-field select, .ppo-field textarea { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        .ppo-submit { text-align: center; margin-top: 20px; }
        .btn { padding: 10px 20px; margin: 0 5px; text-decoration: none; border-radius: 4px; cursor: pointer; border: none; }
        .btn-primary { background: #007cba; color: white; }
        .btn-secondary { background: #6c757d; color: white; }
        .ppo-error { background: #f8d7da; color: #721c24; padding: 10px; border-radius: 4px; margin-bottom: 20px; }
        .ui-autocomplete { max-height: 200px; overflow-y: auto; }
    </style>
    <?php
    return ob_get_clean();
}
add_shortcode('ppo_delivery_form', 'ppo_render_delivery_form');

/**
 * Hook для рендерингу полів Нової Пошти (місто, вулиця, відділення).
 */
function ppo_render_delivery_fields() {
    wp_nonce_field('ppo_np_nonce', 'ppo_np_nonce'); // Nonce для AJAX
    ?>
    <div class="ppo-field">
        <label for="ppo_np_city">Місто:</label>
        <input type="text" id="ppo_np_city" name="np_city" placeholder="Введіть назву міста" required>
        <input type="hidden" id="ppo_np_city_ref" name="np_city_ref">
    </div>
    <div class="ppo-field">
        <label for="ppo_np_street">Вулиця (необов'язково):</label>
        <input type="text" id="ppo_np_street" name="np_street" placeholder="Введіть назву вулиці">
        <input type="hidden" id="ppo_np_street_ref" name="np_street_ref">
    </div>
    <div class="ppo-field">
        <label for="ppo_np_division">Відділення:</label>
        <select id="ppo_np_division" name="np_division_ref" required>
            <option value="">Оберіть після вибору міста</option>
        </select>
        <input type="hidden" id="ppo_np_division_id" name="np_division_id">
    </div>
    <?php
}
add_action('ppo_render_delivery', 'ppo_render_delivery_fields');
?>