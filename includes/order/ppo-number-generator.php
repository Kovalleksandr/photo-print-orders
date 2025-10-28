<?php
/**
 * Функції для генерації унікального номера замовлення.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Генерує унікальний номер замовлення
 * Формат: YYYYMMDD-XXXX (наприклад, 20251019-0001)
 * @return string Унікальний ідентифікатор замовлення.
 */
function ppo_generate_order_number() {
    // Формат: YYYYMMDD
    $date_prefix = current_time('Ymd'); 
    
    // Отримання та оновлення лічильника
    $counter_key = 'ppo_order_counter_' . $date_prefix;
    $current_counter = get_option($counter_key, 0);
    $new_counter = $current_counter + 1;
    
    // Зберігаємо новий лічильник. 
    // Використовуємо autoload = no, щоб не вантажити його на кожній сторінці.
    update_option($counter_key, $new_counter, 'no'); 

    // Форматування номера (4 цифри з нулями на початку)
    $order_number = $date_prefix . '-' . str_pad($new_counter, 4, '0', STR_PAD_LEFT);

    return $order_number;
}

/**
 * Допоміжна функція: скидання лічильника замовлень
 * Потрібна для перевірки (не використовується в основному процесі)
 */
function ppo_reset_daily_counter() {
    $date_prefix = current_time('Ymd');
    $counter_key = 'ppo_order_counter_' . $date_prefix;
    update_option($counter_key, 0, 'no');
}