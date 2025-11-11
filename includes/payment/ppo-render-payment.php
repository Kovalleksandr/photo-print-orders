<?php
// includes/payment/ppo-render-payment.php

// !!! ВИДАЛЕНО: use LiqPay\LiqPay; - щоб уникнути проблем із просторами імен !!!

/**
 * Генерує унікальний Order ID для LiqPay.
 * За замовчуванням, використовує ID замовлення з CPT.
 *
 * @param string $ppo_order_id ID замовлення з CPT.
 * @return string Унікальний ID для LiqPay.
 */
function ppo_generate_liqpay_order_id(string $ppo_order_id): string {
    return $ppo_order_id;
}


/**
 * Функція для генерації HTML-форми LiqPay за допомогою офіційного SDK.
 *
 * @param float $amount Сума платежу.
 * @param string $ppo_order_id Унікальний ID замовлення з CPT.
 * @return string HTML-форма LiqPay або повідомлення про помилку.
 */
function ppo_generate_liqpay_form(float $amount, string $ppo_order_id): string {
    $public_key = LIQPAY_PUBLIC_KEY; 
    $private_key = LIQPAY_PRIVATE_KEY;

    // ВИПРАВЛЕНО: Перевірка наявності класу 'LiqPay' без простору імен
    if (!class_exists('LiqPay')) {
        return '<p class="ppo-message ppo-message-error">Помилка: Клас LiqPay SDK не знайдено. Перевірте встановлення Composer.</p>';
    }
    
    try {
        // ВИПРАВЛЕНО: Ініціалізація класу 'LiqPay'
        // Припускаємо, що клас LiqPay визначено у глобальному просторі імен, оскільки `use` видалено.
        $liqpay = new LiqPay($public_key, $private_key);
        
        $description = sprintf('Оплата замовлення фотодруку №%s', $ppo_order_id);
        $liqpay_order_id = ppo_generate_liqpay_order_id($ppo_order_id);
        
        // URL-и для LiqPay
        $payment_success_url = esc_url(add_query_arg('order_id', $liqpay_order_id, home_url('/order-payment-success/'))); 
        $server_callback_url = esc_url(home_url('/liqpay-callback/'));     

        $params = [
            'action'        => 'pay',
            'amount'        => number_format($amount, 2, '.', ''),
            'currency'      => 'UAH',
            'description'   => $description,
            'order_id'      => $liqpay_order_id,
            'version'       => '3', 
            
            'result_url'    => $payment_success_url, 
            'server_url'    => $server_callback_url, 
            'language'      => 'uk',
            'customer'      => $ppo_order_id, 
        ];

        return $liqpay->cnb_form($params);

    } catch (\Exception $e) {
        return '<p class="ppo-message ppo-message-error">Помилка ініціалізації LiqPay: ' . esc_html($e->getMessage()) . '</p>';
    }
}


/**
 * Функція для рендерингу сторінки оплати.
 * Викликається шорткодом [ppo_payment_form].
 */
function ppo_render_payment_form() {
    // 1. Перевірка сесії
    if (empty($_SESSION['ppo_order_id']) || empty($_SESSION['ppo_total'])) {
        // Додано контейнер для стилю
        return '<div class="ppo-order-form-container"><div class="ppo-step-block"><p class="ppo-message ppo-message-error">Помилка: Немає активного замовлення або суми до сплати.</p><a href="' . esc_url(home_url('/order/')) . '" class="ppo-button ppo-button-secondary">Повернутися до замовлення</a></div></div>';
    }

    $ppo_order_id = sanitize_text_field($_SESSION['ppo_order_id']);
    $total_amount = floatval($_SESSION['ppo_total']);
    
    ob_start();
    ?>
    <div class="ppo-order-form-container ppo-payment-page">
        <h2>💳 Крок 3: Оплата замовлення №<?php echo esc_html($ppo_order_id); ?></h2>
        
        <div class="ppo-step-block ppo-payment-info-block">
            <h3>Деталі платежу</h3>
            
            <p class="ppo-total-sum ppo-summary">Загальна сума до сплати: <strong><?php echo number_format($total_amount, 2, '.', ' '); ?> грн</strong></p>

            <div class="ppo-payment-method-block">
                <h4 class="ppo-method-title">Сплатити карткою через LiqPay</h4>
                
                <?php 
                // 3. Генерація форми LiqPay
                echo ppo_generate_liqpay_form($total_amount, $ppo_order_id);
                ?>

                <p class="ppo-note">Натискаючи кнопку "Сплатити", ви будете перенаправлені на захищену сторінку LiqPay.</p>
            </div>
        </div>
        
        <div class="ppo-buttons-container ppo-back-link">
            <a href="<?php echo esc_url(home_url('/orderpagedelivery/')); ?>" class="ppo-button ppo-button-secondary">
                &leftarrow; Повернутися до вибору доставки
            </a>
        </div>
        
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Шорткод для відображення результату платежу: [ppo_payment_result]
 * Показує статус оплати на основі мета-даних замовлення.
 */
function ppo_render_payment_result() {
    // 1. Отримання order_id з GET (пріоритет) або сесії
    $ppo_order_id = sanitize_text_field($_GET['order_id'] ?? ($_SESSION['ppo_order_id'] ?? ''));

    if (empty($ppo_order_id)) {
        return '<div class="ppo-order-form-container"><p class="ppo-message ppo-message-error">Помилка: ID замовлення не знайдено. Спробуйте повернутися до сторінки замовлення.</p></div>';
    }

    // 2. Пошук замовлення в CPT 'ppo_order' за мета-значенням 'ppo_order_id'
    $args = [
        'post_type'      => 'ppo_order',
        'posts_per_page' => 1,
        'post_status'    => 'any',
        'meta_query'     => [
            [
                'key'     => 'ppo_order_id',
                'value'   => $ppo_order_id,
                'compare' => '=',
            ],
        ],
    ];
    $order_query = new WP_Query($args);
    
    if (!$order_query->have_posts()) {
        return '<div class="ppo-order-form-container"><div class="ppo-step-block"><p class="ppo-message ppo-message-error">Замовлення №' . esc_html($ppo_order_id) . ' не знайдено. Можливо, платіж ще оброблюється — перевірте пізніше або зверніться до підтримки.</p></div></div>';
    }

    $order_post = $order_query->posts[0];

    // 3. Отримання статусу платежу з мета-даних
    $payment_status = get_post_meta($order_post->ID, 'ppo_payment_status', true);
    $total_paid = get_post_meta($order_post->ID, 'ppo_total_paid', true);
    $payment_date = get_post_meta($order_post->ID, 'ppo_payment_date', true);
    $payment_date_formatted = $payment_date ? date('d.m.Y H:i', $payment_date) : 'Н/Д';

    ob_start();
    ?>
    <div class="ppo-order-form-container ppo-payment-result-container">
        <div class="ppo-step-block ppo-result-block">
            <h2>Результат оплати замовлення №<?php echo esc_html($ppo_order_id); ?></h2>
            
            <?php if ($payment_status === 'paid'): ?>
                <p class="ppo-message ppo-message-success">✅ Оплата успішна! Сума: <?php echo number_format(floatval($total_paid), 2, '.', ' '); ?> грн. Дата: <?php echo esc_html($payment_date_formatted); ?>.</p>
                <p>Ваше замовлення оброблюється. Ви отримаєте підтвердження на email.</p>
            <?php elseif ($payment_status === 'failed'): ?>
                <p class="ppo-message ppo-message-error">❌ Помилка оплати. Спробуйте ще раз або зверніться до підтримки.</p>
                <div class="ppo-buttons-container">
                    <a href="<?php echo esc_url(home_url('/orderpagepayment/')); ?>" class="ppo-button ppo-button-primary">Повернутися до оплати</a>
                </div>
            <?php elseif ($payment_status === 'pending'): ?>
                <p class="ppo-message ppo-message-warning">⏳ Платіж в обробці. Будь ласка, зачекайте або перевірте пізніше.</p>
            <?php else: ?>
                <p class="ppo-message ppo-message-info">ℹ️ Статус платежу невідомий. Перевірте замовлення в особистому кабінеті.</p>
            <?php endif; ?>
            
            <div class="ppo-buttons-container ppo-back-link">
                <a href="<?php echo esc_url(home_url('/orderpage/')); ?>" class="ppo-button ppo-button-secondary">Повернутися до головної сторінки замовлень</a>
            </div>
        </div>
    </div>
    <?php
    
    // ОЧИЩЕННЯ СЕСІЇ ПІСЛЯ ЗАВЕРШЕННЯ ЗАМОВЛЕННЯ/ОПЛАТИ.
    // Це вирішує проблему відображення залишків даних (суми '0') на сторінці нового замовлення.
    unset($_SESSION['ppo_order_id']);
    unset($_SESSION['ppo_total']);
    unset($_SESSION['ppo_formats']); // Додано для повного очищення деталей замовлення
    unset($_SESSION['ppo_contact_info']); // Додано для очищення контактних даних
    unset($_SESSION['ppo_delivery_details_array']); // Додано для очищення деталей доставки

    return ob_get_clean();
}

// РЕЄСТРАЦІЯ ШОРТКОДУ 
if (function_exists('add_shortcode')) {
    add_shortcode('ppo_payment_result', 'ppo_render_payment_result');
    add_shortcode('ppo_payment_form', 'ppo_render_payment_form');
}