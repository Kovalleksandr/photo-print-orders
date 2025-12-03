<?php
// includes/payment/ppo-render-payment.php

if (!defined('ABSPATH')) {
    exit;
}

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
    // Перевірка констант
    if (!defined('LIQPAY_PUBLIC_KEY') || !defined('LIQPAY_PRIVATE_KEY')) {
         return '<p class="ppo-message ppo-message-error">Помилка: Ключі LiqPay не визначено у ppo-config.php.</p>';
    }

    $public_key = LIQPAY_PUBLIC_KEY; 
    $private_key = LIQPAY_PRIVATE_KEY;

    // Перевірка наявності класу 'LiqPay' без простору імен
    if (!class_exists('LiqPay')) {
        return '<p class="ppo-message ppo-message-error">Помилка: Клас LiqPay SDK не знайдено. Перевірте встановлення Composer.</p>';
    }
    
    try {
        // Ініціалізація класу 'LiqPay'
        // ПРИМІТКА: Клас LiqPay має бути завантажений через Composer або іншим способом
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
        error_log('LiqPay Error: ' . $e->getMessage()); 
        return '<p class="ppo-message ppo-message-error">Помилка ініціалізації LiqPay: ' . esc_html($e->getMessage()) . '</p>';
    }
}


/**
 * Функція для отримання мітки опції для відображення
 *
 * @param string $key Ключ опції ('glossy', 'matte', 'none', 'yes').
 * @return string Відформатована мітка.
 */
function get_option_label_payment(string $key): string {
    $map = [
        'glossy' => 'Глянець',
        'matte' => 'Матовий',
        'none' => 'Без рамки',
        'yes' => 'З рамкою',
    ];
    return $map[$key] ?? $key;
}


/**
 * Функція для рендерингу сторінки оплати.
 * Викликається шорткодом [ppo_payment_form].
 */
function ppo_render_payment_form(): string {
    // 1. Перевірка сесії
    if (empty($_SESSION['ppo_order_id']) || empty($_SESSION['ppo_total'])) {
        return '<div class="ppo-order-form-container"><div class="ppo-step-block"><p class="ppo-message ppo-message-error">Помилка: Немає активного замовлення або суми до сплати.</p><a href="' . esc_url(home_url('/order/')) . '" class="ppo-button ppo-button-secondary">Повернутися до замовлення</a></div></div>';
    }

    $ppo_order_id = sanitize_text_field($_SESSION['ppo_order_id']);
    $total_amount = floatval($_SESSION['ppo_total']);
    $delivery_page_url = home_url('/orderpagedelivery/'); // SLUG сторінки доставки
    $order_page_url = home_url('/order/'); // SLUG сторінки замовлення (Крок 1)
    
    // Отримання даних з сесії
    $session_formats = array_filter($_SESSION['ppo_formats'] ?? [], 'is_array');
    $contact_info = $_SESSION['ppo_contact_info'] ?? [];
    $delivery_details = $_SESSION['ppo_delivery_details_array'] ?? []; // Масив деталей доставки
    
    $total_copies_overall = array_sum(array_column($session_formats, 'total_copies'));
    $has_order = !empty($session_formats);
    
    // Логіка визначення адреси для відображення
    // Використовуємо ключ 'type', який ідентифікує тип доставки
    $delivery_method_type = $delivery_details['type'] ?? 'N/A'; 
    $address_details = '';

    if ($delivery_method_type === 'Самовивіз') {
        $method_name = 'Самовивіз';
        // Припускаємо, що адреса самовивозу зберігається тут або є константою
        $address_details = 'Точка видачі: [Адреса вашого магазину/точки] (Спосіб: ' . $method_name . ')'; 
    } elseif ($delivery_method_type === 'Нова Пошта (Відділення/Поштомат)') {
        $method_name = 'Нова Пошта';
        // Використовуємо ключі 'city_name' та 'warehouse_name', знайдені у ppo-cpt-orders.php
        $city = $delivery_details['city_name'] ?? 'Н/Д'; 
        $warehouse_display = $delivery_details['warehouse_name'] ?? 'Н/Д';
        
        // Формуємо рядок адреси для НП
        if ($city !== 'Н/Д' || $warehouse_display !== 'Н/Д') {
             // Виводимо повний рядок адреси, як у вас в адмінці
             $address_details = 'Місто: ' . esc_html($city) . ', Відділення: ' . esc_html($warehouse_display) . ' (Спосіб: ' . $method_name . ')';
        }
    }
    
    // Якщо жоден із відомих типів не спрацював, але тип заданий
    if (empty($address_details) && $delivery_method_type !== 'N/A') {
        $address_details = 'Спосіб: ' . esc_html($delivery_method_type);
    } elseif (empty($address_details) && $delivery_method_type === 'N/A') {
        $address_details = 'Адреса доставки не вказана.';
    }

    ob_start();
    ?>
    <div class="ppo-order-form-container ppo-payment-page">
        <div class="ppo-step-block ppo-order-header-block ppo-standard-block">
            <h2 class="ppo-order-title">
                <span>💳 Оплата замовлення</span>
                <span class="ppo-order-id-display">№<?php echo esc_html($ppo_order_id); ?></span>
            </h2>
        </div>
        <div class="ppo-step-block ppo-summary-block ppo-delivery-summary-block ppo-standard-block">
            <h3>ДЕТАЛІ ДОСТАВКИ</h3>
            
            <ul class="ppo-info-list">
                <?php if (!empty($contact_info['name'])): ?>
                    <li><strong>Отримувач:</strong> <?php echo esc_html($contact_info['name']); ?></li>
                <?php endif; ?>
                <?php if (!empty($contact_info['phone'])): ?>
                    <li><strong>Телефон:</strong> <?php echo esc_html($contact_info['phone']); ?></li>
                <?php endif; ?>
                <?php if (!empty($contact_info['email'])): ?>
                    <li><strong>Email:</strong> <?php echo esc_html($contact_info['email']); ?></li>
                <?php endif; ?>
            </ul>

            <ul class="ppo-info-list ppo-delivery-info-list">
                <?php 
                // Вивід АДРЕСИ. Вона тепер містить деталі НП або самовивозу
                if (!empty($address_details)): ?>
                    <li><strong>Адреса:</strong> <?php echo esc_html($address_details); ?></li>
                <?php endif; ?>
                
                <?php if (!empty($delivery_details['comment'])): ?>
                    <li class="ppo-comment"><strong>Коментар:</strong> <?php echo esc_html($delivery_details['comment']); ?></li>
                <?php endif; ?>
            </ul>
            
            <div class="ppo-action-buttons-bottom">
                <a href="<?php echo esc_url($delivery_page_url); ?>" class="ppo-button ppo-button-secondary ppo-edit-delivery-btn">РЕДАГУВАТИ</a>
            </div>
        </div>
        <div class="ppo-step-block ppo-payment-info-block ppo-standard-block">
            <h3>ДЕТАЛІ ЗАМОВЛЕННЯ</h3>
            
            <div id="ppo-formats-list-container" class="ppo-formats-list-container" style="<?php echo $has_order ? '' : 'display: none;'; ?>">
                <ul id="ppo-formats-list" class="ppo-formats-list">
                    <?php if ($has_order): ?>
                        <?php 
                        foreach ($session_formats as $key => $details): 
                            if (is_array($details)):
                                $key_parts = explode('_', $key, 3);
                                $format_name = $key_parts[0] ?? $key;
                                $finish_label = get_option_label_payment($key_parts[1] ?? '');
                                $frame_label = get_option_label_payment($key_parts[2] ?? '');
                                $display_key = $format_name;
                                if ($finish_label || $frame_label) {
                                    $display_key .= ' (' . trim("{$finish_label}, {$frame_label}", ', ') . ')';
                                }
                        ?>
                                <li><?php echo esc_html($display_key . ': ' . $details['total_copies'] . ' шт., ' . number_format($details['total_price'], 2, '.', '') . ' грн.'); ?></li>
                        <?php 
                            endif; 
                        endforeach; 
                        ?>
                    <?php endif; ?>
                </ul>
                <p class="ppo-total-sum ppo-summary-text">
                    Загальна сума до сплати: 
                    <span id="ppo-session-total">
                        <strong><?php echo esc_html(number_format($total_amount, 2, '.', '')); ?> грн </strong>
                        <small>(Всього шт.: <?php echo esc_html($total_copies_overall); ?>)</small>
                    </span>
                </p>
            </div>
            <div class="ppo-payment-method-block">
                <h4 class="ppo-method-title">метод оплати: LiqPay (Оплата карткою)</h4>
            </div>
            
            <div class="ppo-action-buttons-bottom">
                 <a href="<?php echo esc_url($order_page_url); ?>" class="ppo-button ppo-button-secondary ppo-edit-order-btn">РЕДАГУВАТИ</a>
            </div>
        </div> 
        <div class="ppo-payment-button-wrapper ppo-action-button">
            <?php 
            echo ppo_generate_liqpay_form($total_amount, $ppo_order_id);
            ?>
        </div>
        
        <p class="ppo-note">Натискаючи кнопку "Сплатити", ви будете перенаправлені на захищену сторінку LiqPay.</p>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Шорткод для відображення результату платежу: [ppo_payment_result]
 * Викликається після успішної або невдалої оплати.
 */
function ppo_render_payment_result(): string {
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
    // Використовуємо глобальну функцію WP_Query
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
                <p class="ppo-message ppo-message-success">✅ Оплата успішна! Сума: **<?php echo number_format(floatval($total_paid), 2, '.', ' '); ?> грн** Дата: **<?php echo esc_html($payment_date_formatted); ?>**.</p>
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
            
            <div class="ppo-buttons-container">
                <a href="<?php echo esc_url(home_url('/orderpage/')); ?>" class="ppo-button ppo-button-secondary">Повернутися до головної сторінки замовлень</a>
            </div>
        </div>
    </div>
    <?php
    
    // ОЧИЩЕННЯ СЕСІЇ ПІСЛЯ ЗАВЕРШЕННЯ ЗАМОВЛЕННЯ/ОПЛАТИ.
    unset($_SESSION['ppo_order_id']);
    unset($_SESSION['ppo_total']);
    unset($_SESSION['ppo_formats']);
    unset($_SESSION['ppo_contact_info']);
    unset($_SESSION['ppo_delivery_details_array']);

    return ob_get_clean();
}

// РЕЄСТРАЦІЯ ШОРТКОДУ 
if (function_exists('add_shortcode')) {
    add_shortcode('ppo_payment_result', 'ppo_render_payment_result');
    add_shortcode('ppo_payment_form', 'ppo_render_payment_form');
}