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
        'labels'             => $labels,
        'public'             => false, // Не публічний, тільки в адмінці
        'show_ui'            => true,  // Відображати в UI адмінки
        'show_in_menu'       => true,  // Показувати в меню адмінки
        'query_var'          => true,
        'rewrite'            => ['slug' => 'ppo-order'],
        'capability_type'    => 'post',
        'has_archive'        => false,
        'hierarchical'       => false,
        'menu_position'      => null,
        'menu_icon'          => 'dashicons-cart',
        'supports'           => ['title', 'custom-fields'], // Підтримка заголовка та кастомних полів
    ];

    register_post_type('ppo_order', $args);
}
add_action('init', 'ppo_register_order_cpt');

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
 * Callback для мета-боксу: відображення деталей замовлення.
 */
function ppo_order_details_callback($post) {
    // Отримання мета-даних
    $ppo_order_id = get_post_meta($post->ID, 'ppo_order_id', true);
    $ppo_formats = get_post_meta($post->ID, 'ppo_formats', true);
    $ppo_total = get_post_meta($post->ID, 'ppo_total', true);
    $ppo_delivery_type = get_post_meta($post->ID, 'ppo_delivery_type', true);
    $ppo_delivery_address = get_post_meta($post->ID, 'ppo_delivery_address', true);
    $ppo_payment_status = get_post_meta($post->ID, 'ppo_payment_status', true);
    $ppo_total_paid = get_post_meta($post->ID, 'ppo_total_paid', true);
    $ppo_payment_date = get_post_meta($post->ID, 'ppo_payment_date', true);
    $payment_date_formatted = $ppo_payment_date ? date('d.m.Y H:i', $ppo_payment_date) : 'Н/Д';

    ?>
    <div class="ppo-meta-details">
        <p><strong>Order ID (Meta):</strong> <?php echo esc_html($ppo_order_id); ?></p>
        <p><strong>Formats:</strong> <?php echo esc_html(print_r($ppo_formats, true)); ?></p>
        <p><strong>Total Amount:</strong> <?php echo esc_html($ppo_total); ?> грн</p>
        <p><strong>Delivery Type:</strong> <?php echo esc_html($ppo_delivery_type); ?></p>
        <p><strong>Delivery Address:</strong> <?php echo esc_html($ppo_delivery_address); ?></p>
        <p><strong>Payment Status:</strong> <?php echo esc_html($ppo_payment_status); ?></p>
        <p><strong>Total Paid:</strong> <?php echo esc_html($ppo_total_paid); ?> грн</p>
        <p><strong>Payment Date:</strong> <?php echo esc_html($payment_date_formatted); ?></p>
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