<?php
// includes/admin/ppo-cpt-orders.php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Реєстрація Custom Post Type 'ppo_order' для зберігання замовлень.
 */
function ppo_register_order_cpt() {
    $labels = [
        'name'                  => _x('Print Orders', 'Post type general name', 'photo-print-orders'),
        'singular_name'         => _x('Print Order', 'Post type singular name', 'photo-print-orders'),
        'menu_name'             => _x('Print Orders', 'Admin Menu text', 'photo-print-orders'),
        'name_admin_bar'        => _x('Print Order', 'Add New on Toolbar', 'photo-print-orders'),
        'add_new'               => __('Add New', 'photo-print-orders'),
        'add_new_item'          => __('Add New Print Order', 'photo-print-orders'),
        'new_item'              => __('New Print Order', 'photo-print-orders'),
        'edit_item'             => __('Edit Print Order', 'photo-print-orders'),
        'view_item'             => __('View Print Order', 'photo-print-orders'),
        'all_items'             => __('All Print Orders', 'photo-print-orders'),
        'search_items'          => __('Search Print Orders', 'photo-print-orders'),
        'parent_item_colon'     => __('Parent Print Orders:', 'photo-print-orders'),
        'not_found'             => __('No print orders found.', 'photo-print-orders'),
        'not_found_in_trash'    => __('No print orders found in Trash.', 'photo-print-orders'),
    ];

    $args = [
        'labels'                => $labels,
        'public'                => false, // Не публічний, тільки в адмінці
        'show_ui'               => true,  // Відображати в UI адмінки
        'show_in_menu'          => true,  // Показувати в меню адмінки
        'query_var'             => true,
        'rewrite'               => ['slug' => 'ppo-order'],
        'capability_type'       => 'post',
        'has_archive'           => false,
        'hierarchical'          => false,
        'menu_position'         => null,
        'menu_icon'             => 'dashicons-cart',
        'supports'              => ['title', 'custom-fields'], // Підтримка заголовка та кастомних полів
    ];

    register_post_type('ppo_order', $args);
}
add_action('init', 'ppo_register_order_cpt');

/**
 * Реєстрація кастомних статусів замовлення.
 */
function ppo_register_custom_order_statuses() {
    register_post_status('pending_payment', [
        'label'                     => _x('Очікує оплати', 'Post Status Label', 'photo-print-orders'),
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop('Очікує оплати <span class="count">(%s)</span>', 'Очікують оплати <span class="count">(%s)</span>', 'photo-print-orders'),
    ]);

    register_post_status('ppo_paid', [
        'label'                     => _x('Оплачено', 'Post Status Label', 'photo-print-orders'),
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop('Оплачено <span class="count">(%s)</span>', 'Оплачено <span class="count">(%s)</span>', 'photo-print-orders'),
    ]);
    
    register_post_status('ppo_processing', [
        'label'                     => _x('В обробці', 'Post Status Label', 'photo-print-orders'),
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop('В обробці <span class="count">(%s)</span>', 'В обробці <span class="count">(%s)</span>', 'photo-print-orders'),
    ]);
    
    register_post_status('ppo_failed', [
        'label'                     => _x('Помилка оплати', 'Post Status Label', 'photo-print-orders'),
        'public'                    => false,
        'exclude_from_search'       => true,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop('Помилка оплати <span class="count">(%s)</span>', 'Помилки оплати <span class="count">(%s)</span>', 'photo-print-orders'),
    ]);
}
add_action('init', 'ppo_register_custom_order_statuses');

/**
 * Приховування стандартних мета-боксів WordPress.
 */
function ppo_remove_default_meta_boxes() {
    $cpt = 'ppo_order';
    
    // 1. Приховати "Произвольные поля"
    remove_meta_box('postcustom', $cpt, 'normal'); 
    
    // 2. Приховати "Ярлик" (Slug)
    remove_meta_box('slugdiv', $cpt, 'normal'); 
    
    // Приховати інші непотрібні поля (якщо вони були)
    remove_meta_box('commentstatusdiv', $cpt, 'normal'); 
    remove_meta_box('commentsdiv', $cpt, 'normal'); 
    remove_meta_box('revisionsdiv', $cpt, 'normal'); 
    remove_meta_box('authordiv', $cpt, 'normal'); 
}
add_action('admin_menu', 'ppo_remove_default_meta_boxes');

/**
 * Додавання мета-боксів для деталей замовлення в адмінці.
 */
function ppo_add_order_meta_boxes() {
    add_meta_box(
        'ppo_order_details',
        __('Order Details', 'photo-print-orders'),
        'ppo_order_details_callback',
        'ppo_order',
        'normal',
        'high'
    );
}
add_action('add_meta_boxes', 'ppo_add_order_meta_boxes');

/**
 * Callback для мета-боксу: відображення деталей замовлення (ПОКРАЩЕНА ЧИТАБЕЛЬНІСТЬ).
 */
function ppo_order_details_callback($post) {
    // Отримання мета-даних
    $ppo_order_id = get_post_meta($post->ID, 'ppo_order_id', true);
    $ppo_total = get_post_meta($post->ID, 'ppo_total', true);
    
    $ppo_contact_info = get_post_meta($post->ID, 'ppo_contact_info', true);
    $ppo_delivery_details = get_post_meta($post->ID, 'ppo_delivery_details', true);
    
    $ppo_payment_status = get_post_meta($post->ID, 'ppo_payment_status', true);
    $ppo_total_paid = get_post_meta($post->ID, 'ppo_total_paid', true);
    $ppo_payment_date = get_post_meta($post->ID, 'ppo_payment_date', true);
    $payment_date_formatted = $ppo_payment_date ? date('d.m.Y H:i', $ppo_payment_date) : 'Н/Д';

    // Дані про формати та файли (зберігаються під ключем 'ppo_formats')
    $ppo_formats_data = get_post_meta($post->ID, 'ppo_formats', true);
    
    // Форматування адреси доставки
    $delivery_address_display = 'Н/Д';
    if (!empty($ppo_delivery_details) && is_array($ppo_delivery_details)) {
        if ($ppo_delivery_details['type'] === 'Нова Пошта (Відділення/Поштомат)') {
            $delivery_address_display = sprintf(
                'Нова Пошта: %s, %s',
                esc_html($ppo_delivery_details['city_name'] ?? 'Місто'),
                esc_html($ppo_delivery_details['warehouse_name'] ?? 'Відділення')
            );
        } else {
             $delivery_address_display = esc_html($ppo_delivery_details['type'] ?? 'Н/Д');
        }
    }
    
    // Форматування контактів
    $contact_name = $ppo_contact_info['name'] ?? 'Н/Д';
    $contact_phone = $ppo_contact_info['phone'] ?? 'Н/Д';
    $contact_email = $ppo_contact_info['email'] ?? 'Н/Д';
    
    ?>
    <style>
        .ppo-meta-details p {
            margin: 0 0 5px 0;
            padding: 0;
        }
        .ppo-meta-details strong {
            display: inline-block;
            min-width: 120px;
        }
        .ppo-files-list {
            margin-top: 5px;
            padding: 10px;
            border: 1px solid #eee;
            background: #f9f9f9;
        }
        .ppo-files-list h4 {
            margin-top: 0;
            margin-bottom: 5px;
            border-bottom: 1px dashed #ddd;
            padding-bottom: 5px;
        }
        .ppo-files-list ul {
            margin: 0;
            padding-left: 20px;
        }
        .ppo-section {
            margin-top: 15px;
            padding-top: 10px;
            border-top: 1px solid #ddd;
        }
    </style>
    <div class="ppo-meta-details">
        <h3>Деталі Замовлення #<?php echo esc_html($ppo_order_id); ?></h3>
        
        <div class="ppo-section">
            <h4>Контактна Інформація</h4>
            <p><strong>Ім'я:</strong> <?php echo esc_html($contact_name); ?></p>
            <p><strong>Телефон:</strong> <?php echo esc_html($contact_phone); ?></p>
            <p><strong>Email:</strong> <?php echo esc_html($contact_email); ?></p>
        </div>
        
        <div class="ppo-section">
            <h4>Доставка</h4>
            <p><strong>Тип Доставки:</strong> <?php echo esc_html($ppo_delivery_details['type'] ?? 'Н/Д'); ?></p>
            <p><strong>Адреса:</strong> <?php echo $delivery_address_display; ?></p>
        </div>
        
        <div class="ppo-section">
            <h4>Оплата</h4>
            <p><strong>Сума Замовлення:</strong> <strong><?php echo number_format(floatval($ppo_total), 2, '.', ' '); ?> грн</strong></p>
            <p><strong>Статус Оплати:</strong> <?php echo esc_html($ppo_payment_status); ?></p>
            <p><strong>Сплачено:</strong> <?php echo number_format(floatval($ppo_total_paid), 2, '.', ' '); ?> грн</p>
            <p><strong>Дата Оплати:</strong> <?php echo esc_html($payment_date_formatted); ?></p>
        </div>

        <div class="ppo-section">
            <h4>Список Файлів для Друку</h4>
            <?php if (!empty($ppo_formats_data) && is_array($ppo_formats_data)): ?>
                <?php unset($ppo_formats_data['order_folder_path']); // Приховуємо службовий ключ ?>
                
                <?php foreach ($ppo_formats_data as $format => $format_data): ?>
                    <div class="ppo-files-list">
                        <h4>Формат <?php echo esc_html($format); ?> (Ціна: <?php echo esc_html($format_data['price'] ?? 'Н/Д'); ?> грн/шт, Загальна ціна: <?php echo esc_html($format_data['total_price'] ?? 'Н/Д'); ?> грн)</h4>
                        <ul>
                            <?php 
                            $files_array = $format_data['files'] ?? [];
                            foreach ($files_array as $file_item): 
                            ?>
                                <li>
                                    **<?php echo esc_html($file_item['name'] ?? 'Н/Д'); ?>** (Кількість: <?php echo esc_html($file_item['copies'] ?? 1); ?> шт) 
                                    <br><small>Шлях CDN: <?php echo esc_html($file_item['cdn_path'] ?? 'Н/Д'); ?></small>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>Дані про файли відсутні.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/**
 * Збереження мета-даних при збереженні поста (якщо потрібно ручне редагування).
 */
function ppo_save_order_meta($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    // Тут можна додати збереження, якщо є форми в мета-боксі
}
add_action('save_post_ppo_order', 'ppo_save_order_meta');