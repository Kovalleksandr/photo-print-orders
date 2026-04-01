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
function ppo_generate_liqpay_form($amount, $ppo_order_id) {
    if (!class_exists('LiqPay')) return 'Помилка: LiqPay SDK не знайдено.';

    $liqpay = new LiqPay(LIQPAY_PUBLIC_KEY, LIQPAY_PRIVATE_KEY);
    $params = [
        'action'         => 'pay',
        'amount'         => number_format($amount, 2, '.', ''),
        'currency'       => 'UAH',
        'description'    => "Оплата замовлення №$ppo_order_id",
        'order_id'       => $ppo_order_id,
        'version'        => '3',
        'result_url'     => add_query_arg('order_id', $ppo_order_id, home_url('/order-payment-success/')),
        'server_url'     => home_url('/liqpay-callback/'),
        'language'       => 'uk'
    ];

    return $liqpay->cnb_form($params);
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
    $delivery_method_type = $delivery_details['type'] ?? 'N/A'; 
    $address_details = '';

    if ($delivery_method_type === 'Самовивіз') {
        $method_name = 'Самовивіз';
        // Припускаємо, що адреса самовивозу зберігається тут або є константою
        $address_details = 'Точка видачі: [Адреса вашого магазину/точки] (Спосіб: ' . $method_name . ')'; 
    } elseif ($delivery_method_type === 'Нова Пошта (Відділення/Поштомат)') {
        $method_name = 'Нова Пошта';
        $city = $delivery_details['city_name'] ?? 'Н/Д'; 
        $warehouse_full = $delivery_details['warehouse_name'] ?? 'Н/Д';
        
        // ----------------------------------------------------
        // >>> ОНОВЛЕНА ЛОГІКА: Розбір та формування адреси для багаторядкового виведення
        // Бажаний формат: "Нова Пошта <br> Київ, <br> Відділення №49 (до 30 кг) <br> вул. Йорданська, 1"
        // ----------------------------------------------------
        
        $address_parts = [];
        
        // 1. Додаємо тип доставки
        $address_parts[] = '<strong>' . esc_html($method_name) . '</strong>';
        
        // 2. Додаємо місто
        if ($city !== 'Н/Д') {
            $address_parts[] = esc_html($city) . ',';
        }
        
        // 3. Обробка повного рядка відділення
        if ($warehouse_full !== 'Н/Д') {
            $trimmed_warehouse = $warehouse_full;
            
            // a) Обрізаємо зайвий хвіст (наприклад, (Київ, Йорданська, 1))
            $last_open_bracket_pos = strrpos($warehouse_full, '(');
            if ($last_open_bracket_pos !== false) {
                 $trimmed_warehouse = trim(substr($warehouse_full, 0, $last_open_bracket_pos));
                 $trimmed_warehouse = rtrim($trimmed_warehouse, ', ');
            }
            
            // b) Розділяємо на 'Відділення' і 'вул.'
            // Використовуємо ": " як роздільник, щоб отримати "Відділення №49 (до 30 кг)" та "вул. Йорданська, 1, ..."
            $parts = explode(': ', $trimmed_warehouse, 2);
            
            $warehouse_info = trim($parts[0] ?? ''); // Відділення №49 (до 30 кг)
            $street_info = trim($parts[1] ?? '');    // вул. Йорданська, 1, озеро"Вербне"(Оболонь)

            // c) Обрізаємо зайві деталі вулиці, залишаючи "вул. ХХХ, Y"
            $street_info_trimmed = $street_info;
            // Використовуємо регулярку для надійного пошуку "вул. [Назва], [Номер]"
            if (!empty($street_info) && preg_match('/(вул\..+?\d+)/u', $street_info, $matches)) {
                $street_info_trimmed = trim($matches[1]);
            }
            
            // d) Додаємо частини до масиву
            if (!empty($warehouse_info)) {
                $address_parts[] = esc_html($warehouse_info);
            }
            if (!empty($street_info_trimmed)) {
                $address_parts[] = esc_html($street_info_trimmed);
            }
        }
        
        // 4. Формуємо кінцевий HTML-рядок з переносами рядків
        $address_details = implode('<br>', $address_parts);
        
        // ----------------------------------------------------
        // <<< КІНЕЦЬ НОВОЇ ЛОГІКИ
        // ----------------------------------------------------

    }
    
    // Якщо жоден із відомих типів не спрацював, але тип заданий
    if (empty($address_details) && $delivery_method_type !== 'N/A') {
        // У цьому випадку виводимо як раніше, щоб не втратити інфо
        $address_details = 'Спосіб: ' . esc_html($delivery_method_type);
    } elseif (empty($address_details) && $delivery_method_type === 'N/A') {
        $address_details = 'Адреса доставки не вказана.';
    }

    ob_start();
    ?>
    <div class="ppo-order-form-container ppo-payment-page">
        <div class="ppo-step-block ppo-order-header-block ppo-standard-block">
            <h2 class="ppo-order-title">
                <span>💳 Крок 3: Оплата замовлення</span>
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
                // Вивід АДРЕСИ. Виводиться без esc_html, бо містить теги <br> та <strong>
                if (!empty($address_details)): ?>
                    <li>
                        <strong>Адреса:</strong> <li><?php echo $address_details; ?></li>
                    </li>
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
function ppo_render_payment_result() {
    $ppo_order_id = sanitize_text_field($_GET['order_id'] ?? '');
    
    if (empty($ppo_order_id)) {
        return '<p class="ppo-error">Помилка: ID замовлення відсутній.</p>';
    }

    // ПЕРЕВІРКА СТАТУСУ ЧЕРЕЗ API (миттєве оновлення)
    if (class_exists('LiqPay')) {
        try {
            $liqpay = new LiqPay(LIQPAY_PUBLIC_KEY, LIQPAY_PRIVATE_KEY);
            $res = $liqpay->api("request", [
                'action'   => 'status',
                'version'  => '3',
                'order_id' => $ppo_order_id
            ]);

            if (isset($res->status) && in_array($res->status, ['success', 'sandbox', 'wait_accept'])) {
                $query = new WP_Query([
                    'post_type'  => 'ppo_order',
                    'meta_query' => [['key' => 'ppo_order_id', 'value' => $ppo_order_id]]
                ]);

                if ($query->have_posts()) {
                    $pid = $query->posts[0]->ID;
                    wp_update_post(['ID' => $pid, 'post_status' => 'ppo_paid']);
                    update_post_meta($pid, 'ppo_payment_status', 'paid');
                    update_post_meta($pid, 'ppo_payment_date', current_time('mysql'));
                }
            }
        } catch (Exception $e) {
            error_log('LiqPay API Error: ' . $e->getMessage());
        }
    }

    // Вивід результату для користувача
    $status = 'pending';
    $query = new WP_Query(['post_type' => 'ppo_order', 'meta_key' => 'ppo_order_id', 'meta_value' => $ppo_order_id]);
    if ($query->have_posts()) {
        $status = get_post_meta($query->posts[0]->ID, 'ppo_payment_status', true);
    }

    ob_start();
    ?>
    <div class="ppo-payment-result">
        <?php if ($status === 'paid'): ?>
            <div class="ppo-success">✅ Дякуємо! Ваше замовлення №<?php echo esc_html($ppo_order_id); ?> успішно оплачено.</div>
        <?php else: ?>
            <div class="ppo-wait">⏳ Ми очікуємо підтвердження оплати. Зачекайте або оновіть сторінку через хвилину.</div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

add_shortcode('ppo_payment_result', 'ppo_render_payment_result');

// РЕЄСТРАЦІЯ ШОРТКОДУ 
if (function_exists('add_shortcode')) {
    add_shortcode('ppo_payment_result', 'ppo_render_payment_result');
    add_shortcode('ppo_payment_form', 'ppo_render_payment_form');
}