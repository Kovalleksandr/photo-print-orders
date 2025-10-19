<?php
/**
 * Створення Custom Post Type (CPT) для замовлень та функціоналу адмінки.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Реєстрація типу запису 'ppo_order'
 */
function ppo_register_order_cpt() {
    $labels = [
        'name'               => 'Замовлення Фотодруку',
        'singular_name'      => 'Замовлення',
        'menu_name'          => 'Замовлення Фотодруку',
        'add_new'            => 'Створити нове',
        'add_new_item'       => 'Створити нове замовлення',
        'edit_item'          => 'Редагувати замовлення',
        'new_item'           => 'Нове замовлення',
        'view_item'          => 'Переглянути замовлення',
        'search_items'       => 'Шукати замовлення',
        'not_found'          => 'Замовлень не знайдено',
        'not_found_in_trash' => 'В кошику замовлень не знайдено',
    ];

    $args = [
        'labels'              => $labels,
        'public'              => false, // Замовлення не повинні бути публічними
        'show_ui'             => true,
        'show_in_menu'        => true,
        'menu_position'       => 20,
        'menu_icon'           => 'dashicons-camera-alt',
        'supports'            => ['title'], // Використовуємо title лише для ID
        'has_archive'         => false,
        'rewrite'             => false,
        'capabilities'        => [
            'create_posts' => false, // Забороняємо ручне створення
        ],
        'map_meta_cap'        => true,
        'exclude_from_search' => true,
    ];

    register_post_type('ppo_order', $args);
}

/**
 * Додавання кастомних колонок у список замовлень.
 */
function ppo_set_custom_edit_ppo_order_columns($columns) {
    unset($columns['date']); // Приховуємо стандартну колонку "Дата"
    $columns['ppo_id']       = '№ Замовлення';
    $columns['ppo_total']    = 'Сума (грн)';
    $columns['ppo_status']   = 'Статус';
    $columns['ppo_delivery'] = 'Доставка';
    $columns['ppo_files']    = 'Файли';
    $columns['date']         = 'Створено'; // Повертаємо назад в кінці

    return $columns;
}
add_filter('manage_ppo_order_posts_columns', 'ppo_set_custom_edit_ppo_order_columns');

/**
 * Виведення даних у кастомні колонки.
 */
function ppo_custom_ppo_order_column($column, $post_id) {
    $order_meta = get_post_meta($post_id, 'ppo_order_data', true);
    
    // Перевіряємо, чи є метадані
    if (empty($order_meta) || !is_array($order_meta)) {
        echo 'N/A';
        return;
    }

    switch ($column) {
        case 'ppo_id':
            echo esc_html($order_meta['order_id'] ?? $post_id);
            break;
        case 'ppo_total':
            echo '<strong>' . esc_html($order_meta['total'] ?? 0) . '</strong>';
            break;
        case 'ppo_status':
            // За замовчуванням "Нове", якщо не вказано
            $status = esc_html($order_meta['status'] ?? 'new'); 
            echo '<span style="padding: 3px 8px; border-radius: 3px; font-weight: bold; ' . ppo_get_status_style($status) . '">' . ppo_get_status_text($status) . '</span>';
            break;
        case 'ppo_delivery':
            $address = esc_html($order_meta['delivery_address'] ?? 'Самовивіз/Не вказано');
            echo '<small>' . mb_substr($address, 0, 40) . (mb_strlen($address) > 40 ? '...' : '') . '</small>';
            break;
        case 'ppo_files':
            $folder_path = esc_html($order_meta['order_folder_path'] ?? 'N/A');
            $cdn_link = 'https://' . PPO_CDN_HOST . $folder_path;
            
            $total_copies = array_sum(array_column(array_filter($order_meta['formats'] ?? [], 'is_array'), 'total_copies'));
            
            echo '<a href="' . esc_url($cdn_link) . '" target="_blank" title="Відкрити на CDN" style="font-weight: bold;">';
            echo $total_copies . ' фото';
            echo '</a>';
            break;
    }
}
add_action('manage_ppo_order_posts_custom_column', 'ppo_custom_ppo_order_column', 10, 2);

/**
 * Допоміжна функція: Стилі для статусу
 */
function ppo_get_status_style($status) {
    switch ($status) {
        case 'completed':
            return 'background-color: #e6ffe6; color: green; border: 1px solid green;';
        case 'processing':
            return 'background-color: #fff3cd; color: orange; border: 1px solid orange;';
        case 'new':
        default:
            return 'background-color: #e0f7fa; color: #0073aa; border: 1px solid #0073aa;';
    }
}

/**
 * Допоміжна функція: Текст для статусу
 */
function ppo_get_status_text($status) {
    switch ($status) {
        case 'completed':
            return 'Виконано';
        case 'processing':
            return 'В роботі';
        case 'new':
            return 'Нове';
        default:
            return ucfirst($status);
    }
}

/**
 * Додавання метабокса для відображення деталей замовлення.
 */
function ppo_add_order_metabox() {
    add_meta_box(
        'ppo_order_details_metabox',
        'Деталі Замовлення та Файли',
        'ppo_display_order_metabox',
        'ppo_order',
        'normal',
        'high'
    );
}
add_action('add_meta_boxes', 'ppo_add_order_metabox');

/**
 * Виведення вмісту метабокса.
 */
function ppo_display_order_metabox($post) {
    $order_meta = get_post_meta($post->ID, 'ppo_order_data', true);
    
    if (empty($order_meta)) {
        echo '<p>Деталі замовлення відсутні.</p>';
        return;
    }
    
    $formats = array_filter($order_meta['formats'] ?? [], 'is_array');
    $delivery_address = esc_html($order_meta['delivery_address'] ?? 'Не вказано');
    $payment_method = esc_html($order_meta['payment_method'] ?? 'Не вказано');
    $order_folder_path = esc_html($order_meta['order_folder_path'] ?? 'N/A');
    $cdn_link = 'https://' . PPO_CDN_HOST . $order_folder_path;

    echo '<h4>Основна інформація</h4>';
    echo '<p><strong>№ Замовлення:</strong> ' . esc_html($order_meta['order_id'] ?? 'N/A') . '</p>';
    echo '<p><strong>Дата/Час:</strong> ' . esc_html($order_meta['timestamp'] ?? 'N/A') . '</p>';
    echo '<p><strong>Статус:</strong> <span style="font-weight: bold; color: ' . (ppo_get_status_text($order_meta['status'] ?? 'new') === 'Нове' ? '#0073aa' : 'green') . '">' . ppo_get_status_text($order_meta['status'] ?? 'new') . '</span></p>';
    echo '<p><strong>Загальна Сума:</strong> <strong style="font-size: 1.2em;">' . esc_html($order_meta['total'] ?? 0) . ' грн</strong></p>';

    echo '<h4>Деталі Замовлення</h4>';
    if (!empty($formats)) {
        echo '<ul>';
        foreach ($formats as $format => $details) {
            echo '<li><strong>' . esc_html($format) . ':</strong> ' . esc_html($details['total_copies']) . ' копій, ' . esc_html($details['total_price']) . ' грн</li>';
            echo '<ol style="margin-left: 20px;">';
            foreach ($details['files'] as $file) {
                 echo '<li>' . esc_html($file['name']) . ' (x' . esc_html($file['copies']) . ')</li>';
            }
            echo '</ol></li>';
        }
        echo '</ul>';
    } else {
        echo '<p>Формати не вказані.</p>';
    }

    echo '<h4>Доставка та Оплата</h4>';
    echo '<p><strong>Адреса доставки:</strong> <br>' . nl2br($delivery_address) . '</p>';
    echo '<p><strong>Метод оплати:</strong> ' . $payment_method . '</p>';

    echo '<h4>Посилання на Файли</h4>';
    echo '<p><strong>Шлях на CDN:</strong> ' . $order_folder_path . '</p>';
    echo '<p><a href="' . esc_url($cdn_link) . '" target="_blank" class="button button-primary">Відкрити папку на CDN Express</a></p>';
}

/**
 * Встановлення заголовка CPT як ID замовлення (для зручності).
 */
function ppo_set_order_title($post_id) {
    // Перевірка, що це наш CPT і що ми не перебуваємо у циклі
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return $post_id;
    if (get_post_type($post_id) !== 'ppo_order') return $post_id;
    if (wp_is_post_revision($post_id)) return $post_id;
    
    $order_meta = get_post_meta($post_id, 'ppo_order_data', true);

    if (isset($order_meta['order_id']) && get_the_title($post_id) !== $order_meta['order_id']) {
        remove_action('save_post', 'ppo_set_order_title');
        wp_update_post([
            'ID'         => $post_id,
            'post_title' => $order_meta['order_id'],
        ]);
        add_action('save_post', 'ppo_set_order_title');
    }
}
add_action('save_post', 'ppo_set_order_title');