<?php
    // includes/payment/ppo-render-payment.php

    /**
     * Генерує унікальний Order ID для LiqPay.
     * За замовчуванням, використовує ID замовлення з CPT.
     *
     * @param string $ppo_order_id ID замовлення з CPT.
     * @return string Унікальний ID для LiqPay.
     */
    function ppo_generate_liqpay_order_id(string $ppo_order_id): string {
        // Якщо вам потрібно забезпечити унікальність при повторній оплаті (наприклад, LiqPay не дозволяє повторно використовувати order_id),
        // можна додати суфікс:
        // return $ppo_order_id . '-' . time();
        
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
        // ВАЖЛИВО: Ці ключі потрібно буде винести в ppo-config.php або налаштування плагіна!
        // Використовуємо тестові ключі
        $public_key = LIQPAY_PUBLIC_KEY; 
        $private_key = LIQPAY_PRIVATE_KEY;

        // Перевірка наявності класу після автозавантаження
        if (!class_exists('LiqPay')) {
            return '<p class="ppo-message ppo-message-error">Помилка: Клас LiqPay SDK не знайдено. Перевірте встановлення Composer.</p>';
        }
        
        try {
            $liqpay = new LiqPay($public_key, $private_key);
            
            $description = sprintf('Оплата замовлення фотодруку №%s', $ppo_order_id);
            $liqpay_order_id = ppo_generate_liqpay_order_id($ppo_order_id);
            
            // URL-и для LiqPay
            $payment_success_url = esc_url(home_url('/order-payment-success/')); // URL для клієнта після оплати (потрібно створити таку сторінку)
            $server_callback_url = esc_url(home_url('/liqpay-callback/'));     // Наш Endpoint для серверних сповіщень

            $params = [
                'action'        => 'pay',
                'amount'        => number_format($amount, 2, '.', ''),
                'currency'      => 'UAH',
                'description'   => $description,
                'order_id'      => $liqpay_order_id,
                'version'       => '3', // Використовуємо version 3 для CNB (Checkout National Bank)
                
                'result_url'    => $payment_success_url, 
                'server_url'    => $server_callback_url, 
                'language'      => 'uk',
                'customer'      => $ppo_order_id, // Додатковий параметр для ідентифікації
            ];

            // Генеруємо форму. SDK автоматично створює 'data' та 'signature'.
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
            return '<p class="ppo-message ppo-message-error">Помилка: Немає активного замовлення або суми до сплати.</p><a href="' . esc_url(home_url('/orderpage/')) . '">Повернутися до замовлення</a>';
        }

        $ppo_order_id = sanitize_text_field($_SESSION['ppo_order_id']);
        $total_amount = floatval($_SESSION['ppo_total']);
        
        // 2. Додаткова перевірка: якщо замовлення вже оплачене, показуємо повідомлення
        // (Припускаємо, що у вас є функція для перевірки статусу замовлення)
        /* if (ppo_is_order_paid($ppo_order_id)) {
            return '<p class="ppo-message ppo-message-success">Ваше замовлення №' . esc_html($ppo_order_id) . ' вже успішно оплачено.</p>';
        }
        */
        
        ob_start();
        ?>
        <div class="ppo-payment-container">
            <h2>💳 Оплата замовлення №<?php echo esc_html($ppo_order_id); ?></h2>
            
            <p class="ppo-summary">Загальна сума до сплати: <strong><?php echo number_format($total_amount, 2, '.', ' '); ?> грн</strong></p>

            <div class="ppo-payment-method-block">
                <h4 class="ppo-method-title">Сплатити карткою через LiqPay</h4>
                
                <?php 
                // 3. Генерація форми LiqPay
                echo ppo_generate_liqpay_form($total_amount, $ppo_order_id);
                ?>

                <p class="ppo-note">Натискаючи кнопку "Сплатити", ви будете перенаправлені на захищену сторінку LiqPay.</p>
            </div>
            
            <div class="ppo-back-link">
                <a href="<?php echo esc_url(home_url('/orderpagedelivery/')); ?>">
                    &leftarrow; Повернутися до вибору доставки
                </a>
            </div>
            
        </div>
        <?php
        return ob_get_clean();
    }