<?php
// includes/admin/ppo-cpt-orders.php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Реєстрація Custom Post Type 'ppo_order'
 */
function ppo_register_order_cpt() {
    $labels = [
        'name'               => 'Замовлення друку',
        'singular_name'      => 'Замовлення',
        'menu_name'          => 'Друк фото (PPO)',
        'all_items'          => 'Всі замовлення',
        'add_new'            => 'Додати замовлення',
        'edit_item'          => 'Редагувати замовлення',
    ];

    $args = [
        'labels'             => $labels,
        'public'             => false,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'query_var'          => true,
        'rewrite'            => ['slug' => 'ppo-order'],
        'capability_type'    => 'post',
        'has_archive'        => false,
        'hierarchical'       => false,
        'menu_icon'          => 'dashicons-cart',
        'supports'           => ['title'],
    ];

    register_post_type('ppo_order', $args);
}
add_action('init', 'ppo_register_order_cpt');

/**
 * Реєстрація кастомних статусів
 */
function ppo_register_custom_order_statuses() {
    register_post_status('ppo_paid', [
        'label'                     => '✅ Оплачено',
        'public'                    => true,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop('Оплачено <span class="count">(%s)</span>', 'Оплачено <span class="count">(%s)</span>'),
    ]);
    register_post_status('pending_payment', [
        'label'                     => '⏳ Очікує оплати',
        'public'                    => true,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
    ]);
}
add_action('init', 'ppo_register_custom_order_statuses');

/**
 * Приховування стандартних мета-боксів WordPress
 */
function ppo_remove_default_meta_boxes() {
    $cpt = 'ppo_order';
    remove_meta_box('postcustom', $cpt, 'normal'); 
    remove_meta_box('slugdiv', $cpt, 'normal'); 
    remove_meta_box('commentstatusdiv', $cpt, 'normal'); 
    remove_meta_box('commentsdiv', $cpt, 'normal'); 
    remove_meta_box('revisionsdiv', $cpt, 'normal'); 
    remove_meta_box('authordiv', $cpt, 'normal'); 
}
add_action('admin_menu', 'ppo_remove_default_meta_boxes');

/**
 * Додавання мета-боксу з деталями замовлення
 */
function ppo_add_order_meta_boxes() {
    add_meta_box(
        'ppo_order_details',
        'Деталі замовлення',
        'ppo_order_details_callback',
        'ppo_order',
        'normal',
        'high'
    );
}
add_action('add_meta_boxes', 'ppo_add_order_meta_boxes');

/**
 * Callback для відображення деталей замовлення (Тут лише ОДНА функція)
 */
function ppo_order_details_callback($post) {
    // 1. Отримання даних
    $ppo_order_id = get_post_meta($post->ID, 'ppo_order_id', true);
    $ppo_total = get_post_meta($post->ID, 'ppo_total', true);
    $ppo_contact_info = get_post_meta($post->ID, 'ppo_contact_info', true);
    $ppo_delivery_details = get_post_meta($post->ID, 'ppo_delivery_details', true);
    $ppo_payment_status = get_post_meta($post->ID, 'ppo_payment_status', true);
    $ppo_payment_date = get_post_meta($post->ID, 'ppo_payment_date', true);
    $ppo_formats_data = get_post_meta($post->ID, 'ppo_formats', true);

    // 2. Безпечне форматування дати
    $formatted_date = 'Н/Д';
    if ($ppo_payment_date) {
        $timestamp = is_numeric($ppo_payment_date) ? $ppo_payment_date : strtotime($ppo_payment_date);
        $formatted_date = $timestamp ? date('d.m.Y H:i', $timestamp) : $ppo_payment_date;
    }

    // 3. Форматування доставки
    $delivery_display = 'Н/Д';
    if (!empty($ppo_delivery_details) && is_array($ppo_delivery_details)) {
        if (($ppo_delivery_details['type'] ?? '') === 'Нова Пошта (Відділення/Поштомат)') {
            $delivery_display = sprintf('Нова Пошта: %s, %s', 
                $ppo_delivery_details['city_name'] ?? '', 
                $ppo_delivery_details['warehouse_name'] ?? '');
        } else {
            $delivery_display = $ppo_delivery_details['type'] ?? 'Н/Д';
        }
    }
    ?>
    <style>
        .ppo-admin-details h4 { border-bottom: 1px solid #eee; padding-bottom: 5px; margin-top: 20px; }
        .ppo-admin-details p { margin: 5px 0; }
        .ppo-admin-details strong { display: inline-block; width: 150px; }
        .ppo-format-block { background: #f9f9f9; border: 1px solid #e5e5e5; padding: 10px; margin-bottom: 10px; }
    </style>

    <div class="ppo-admin-details">
        <h3>Замовлення #<?php echo esc_html($ppo_order_id); ?></h3>

        <h4>👤 Клієнт</h4>
        <p><strong>Ім'я:</strong> <?php echo esc_html($ppo_contact_info['name'] ?? 'Н/Д'); ?></p>
        <p><strong>Телефон:</strong> <?php echo esc_html($ppo_contact_info['phone'] ?? 'Н/Д'); ?></p>
        <p><strong>Email:</strong> <?php echo esc_html($ppo_contact_info['email'] ?? 'Н/Д'); ?></p>

        <h4>🚚 Доставка</h4>
        <p><strong>Адреса:</strong> <?php echo esc_html($delivery_display); ?></p>

        <h4>💳 Оплата</h4>
        <p><strong>Сума:</strong> <?php echo number_format(floatval($ppo_total), 2, '.', ''); ?> грн</p>
        <p><strong>Статус:</strong> <?php echo esc_html($ppo_payment_status); ?></p>
        <p><strong>Дата оплати:</strong> <?php echo esc_html($formatted_date); ?></p>

        <h4>🖼️ Файли</h4>
        <?php if (!empty($ppo_formats_data) && is_array($ppo_formats_data)): ?>
            <?php foreach ($ppo_formats_data as $format => $data): 
                if ($format === 'order_folder_path') continue; ?>
                <div class="ppo-format-block">
                    <strong>Формат: <?php echo esc_html($format); ?></strong>
                    <ul>
                        <?php foreach (($data['files'] ?? []) as $file): ?>
                            <li>
                                <?php echo esc_html($file['name']); ?> — <b><?php echo esc_html($file['copies']); ?> шт.</b>
                                <br><small>CDN: <?php echo esc_html($file['cdn_path']); ?></small>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p>Файли відсутні.</p>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Збереження (заглушка)
 */
function ppo_save_order_meta($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;
}
add_action('save_post_ppo_order', 'ppo_save_order_meta');