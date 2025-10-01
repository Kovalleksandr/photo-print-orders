<?php
/**
 * Plugin Name: Photo Print Orders
 * Description: Плагін для замовлення друку фото з завантаженням файлів у Google Drive.
 * Version: 3.6 (Оновлена версія з конфігураційним файлом)
 * Author: Your Name
 */

if (!defined('ABSPATH')) {
    exit;
}

// ====================================================================
// 1. ПІДКЛЮЧЕННЯ КОНФІГУРАЦІЇ
// ====================================================================
/**
 * Підключення конфігураційного файлу з константами, ключами та цінами.
 * Дані переміщено для безпеки та зручності.
 */
require_once plugin_dir_path(__FILE__) . 'ppo-config.php';


// ====================================================================
// 2. ПІДКЛЮЧЕННЯ БІБЛІОТЕК ТА АВТОЗАВАНТАЖЕННЯ
// ====================================================================
// 🛑 ОБОВ'ЯЗКОВЕ ПІДКЛЮЧЕННЯ АВТОЗАВАНТАЖУВАЧА GOOGLE API (Composer)
if (file_exists(plugin_dir_path(__FILE__) . 'vendor/autoload.php')) {
    require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';
} else {
    error_log('Помилка: Google API Client бібліотека не встановлена. Використайте "composer require google/apiclient:^2.0"');
}
// Підключення класу-завантажувача
require_once plugin_dir_path(__FILE__) . 'ppo-google-drive-uploader.php'; 


// ====================================================================
// 3. СЕСІЇ ТА ОЧИЩЕННЯ
// ====================================================================
add_action('init', 'ppo_start_session', 1);
function ppo_start_session() {
    if (!session_id() && !defined('DOING_CRON') && !defined('WP_CLI')) {
        session_start();
    }
}
add_action('init', function() {
    if (isset($_GET['clear_session']) && $_GET['clear_session'] === '1') {
        session_destroy();
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
        }
        wp_safe_redirect(home_url('/order/'));
        exit;
    }
});

// ====================================================================
// 4. РЕЄСТРАЦІЯ POST TYPE ТА КОЛОНОК
// ====================================================================
add_action('init', 'ppo_register_order_post_type');
function ppo_register_order_post_type() {
    register_post_type('photo_order', [
        'labels' => [
            'name' => 'Замовлення фото',
            'singular_name' => 'Замовлення фото',
        ],
        'public' => false,
        'show_ui' => true,
        'supports' => ['title'],
    ]);
}
add_filter('manage_photo_order_posts_columns', function($columns) {
    $columns['details'] = 'Деталі замовлення';
    return $columns;
});
add_action('manage_photo_order_posts_custom_column', function($column, $post_id) {
    if ($column === 'details') {
        $formats = get_post_meta($post_id, 'ppo_formats', true);
        $total = get_post_meta($post_id, 'ppo_total', true);
        $address = get_post_meta($post_id, 'ppo_address', true);
        $drive_folder_id = get_post_meta($post_id, 'ppo_drive_folder_id', true);
        
        if ($formats) {
            echo '<strong>Формати:</strong><br>';
            foreach ($formats as $format => $details) {
                 if (is_array($details) && isset($details['total_copies'])) {
                     echo esc_html("$format: {$details['total_copies']} копій, {$details['total_price']} грн<br>");
                 }
            }
        }
        if ($total) {
            echo '<strong>Сума:</strong> ' . esc_html($total) . ' грн<br>';
        }
        if ($address) {
            echo '<strong>Адреса:</strong> ' . esc_html($address);
        }
        if ($drive_folder_id) {
             echo '<strong>Drive ID:</strong> ' . esc_html($drive_folder_id) . '<br>';
        }
    }
}, 10, 2);

// ====================================================================
// 5. РЕЄСТРАЦІЯ ШОРТКОДІВ ТА СКРИПТІВ
// ====================================================================
add_shortcode('photo_print_order_form', 'ppo_render_order_form');
add_shortcode('photo_print_delivery_form', 'ppo_render_delivery_form');
add_shortcode('photo_print_payment_form', 'ppo_render_payment_form');

add_action('wp_enqueue_scripts', 'ppo_enqueue_scripts');
function ppo_enqueue_scripts() {
    wp_register_script('ppo-ajax-script', plugin_dir_url(__FILE__) . 'ppo-ajax-script.js', ['jquery'], '3.5', true);
    wp_enqueue_script('ppo-ajax-script');

    $session_total = array_sum(array_column(array_filter($_SESSION['ppo_formats'] ?? [], 'is_array'), 'total_price'));
    
    wp_localize_script('ppo-ajax-script', 'ppo_ajax_object', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('ppo_ajax_nonce'),
        'min_sum'  => MIN_ORDER_SUM,
        'prices'   => PHOTO_PRICES,
        'session_formats' => array_filter($_SESSION['ppo_formats'] ?? [], 'is_array'),
        'session_total' => $session_total,
        'redirect_delivery' => home_url('/orderpagedelivery/'),
        'redirect_error' => PPO_ERROR_URL,
    ]);
}

// ====================================================================
// 6. ОБРОБКА AJAX ЗАВАНТАЖЕННЯ ФАЙЛІВ
// ====================================================================
add_action('wp_ajax_ppo_file_upload', 'ppo_ajax_file_upload');
add_action('wp_ajax_nopriv_ppo_file_upload', 'ppo_ajax_file_upload');

function ppo_ajax_file_upload() {
    if (!isset($_POST['ppo_ajax_nonce']) || !wp_verify_nonce($_POST['ppo_ajax_nonce'], 'ppo_ajax_nonce')) {
        wp_send_json_error(['message' => 'Помилка безпеки.'], 403);
    }

    $format = sanitize_text_field($_POST['format']);
    $order_id = sanitize_text_field($_POST['order_id']);
    $copies_json = stripslashes($_POST['copies']);
    $copies = json_decode($copies_json, true) ?? [];
    $files = $_FILES['photos'];
    $price_per_photo = PHOTO_PRICES[$format] ?? 0;

    // --- ФІЛЬТРАЦІЯ ТА ПЕРЕВІРКА ---
    $files_to_move = [];
    $valid_file_index = 0;
    foreach ($files['name'] as $key => $filename) {
         if ($files['error'][$key] !== UPLOAD_ERR_OK || empty($files['tmp_name'][$key])) {
              continue; 
         }
         if (!in_array($files['type'][$key], ALLOWED_MIME_TYPES)) {
             wp_send_json_error(['message' => 'Дозволені лише JPEG або PNG файли.'], 400);
         }
         $copies_count = isset($copies[$valid_file_index]) ? intval($copies[$valid_file_index]) : 1;
         $copies_count = max(1, $copies_count); 
         
         $files_to_move[] = [
             'name' => $filename,
             'tmp_name' => $files['tmp_name'][$key],
             'copies_count' => $copies_count, 
         ];
         $valid_file_index++; 
    }
    if ($valid_file_index === 0) {
        wp_send_json_error(['message' => 'Не знайдено жодного файлу для завантаження.'], 400);
    }
    if ($valid_file_index > MAX_FILES_PER_UPLOAD) {
        wp_send_json_error(['message' => 'Максимум ' . MAX_FILES_PER_UPLOAD . ' файлів дозволено.'], 400);
    }
    
    // Перерахунок
    $photo_count = 0; 
    $total_sum_current_upload = 0; 
    foreach ($files_to_move as $file) {
        $copies_val = $file['copies_count'];
        $photo_count += $copies_val;
        $total_sum_current_upload += $copies_val * $price_per_photo;
    }
    
    // Ініціалізація сесії та перевірка мінімальної суми
    $_SESSION['ppo_order_id'] = $_SESSION['ppo_order_id'] ?? $order_id;
    $_SESSION['ppo_formats'] = $_SESSION['ppo_formats'] ?? []; 
    $_SESSION['ppo_total'] = $_SESSION['ppo_total'] ?? 0;

    $current_format_total_in_session = $_SESSION['ppo_formats'][$format]['total_price'] ?? 0;
    $new_format_total_sum = $current_format_total_in_session + $total_sum_current_upload;

    if ($total_sum_current_upload > 0 && $new_format_total_sum < MIN_ORDER_SUM) {
        $message = "Мінімальна сума замовлення для формату $format — " . MIN_ORDER_SUM . " грн. Ваша сума (з цими фото): " . round($new_format_total_sum, 0) . " грн. Додайте ще фото.";
        wp_send_json_error(['message' => $message], 400);
    }
    
    // ====================================================================
    // ЛОГІКА ЗБЕРЕЖЕННЯ: GOOGLE DRIVE (Ініціалізація та Завантаження)
    // ====================================================================
    try {
        if (!class_exists('Google\Client')) {
             throw new \Exception('Google API Client бібліотека недоступна. Встановіть через Composer.');
        }

        $uploader = new PPO_Google_Drive_Uploader(
            PPO_GOOGLE_DRIVE_CLIENT_ID,
            PPO_GOOGLE_DRIVE_CLIENT_SECRET,
            PPO_GOOGLE_DRIVE_REFRESH_TOKEN,
            PPO_GOOGLE_DRIVE_ROOT_FOLDER_ID
        );
    } catch (\Exception $e) {
        error_log('Помилка ініціалізації Drive: ' . $e->getMessage());
        wp_send_json_error(['message' => 'Помилка ініціалізації Drive: ' . $e->getMessage()], 500);
    }

    $format_folder_id = null;
    $all_upload_success = true;

    // Створення папки замовлення
    $order_folder_id = $_SESSION['ppo_formats']['order_folder_id'] ?? null;
    try {
        if (!$order_folder_id) {
            $order_folder_id = $uploader->create_folder('Замовлення-' . $order_id);
            $_SESSION['ppo_formats']['order_folder_id'] = $order_folder_id;
        }
    } catch (\Exception $e) {
        wp_send_json_error(['message' => 'Помилка створення папки замовлення на Drive: ' . $e->getMessage()], 500);
    }
    
    // Створення папки формату та завантаження файлів
    try {
        $format_folder_id = $uploader->create_folder($format, $order_folder_id);
    } catch (\Exception $e) {
        wp_send_json_error(['message' => 'Помилка створення папки формату на Drive: ' . $e->getMessage()], 500);
    }
    
    $uploaded_files = [];
    foreach ($files_to_move as $file) {
        $copies_val = $file['copies_count'];
        $copies_folder_name = $copies_val . ' копій';
        
        try {
            $copies_folder_id = $uploader->create_folder($copies_folder_name, $format_folder_id);

            $uploaded_file_info = $uploader->upload_file(
                $file['tmp_name'], 
                $file['name'], 
                $copies_folder_id
            );

            $uploaded_files[] = [
                'name' => $file['name'],
                'copies' => $copies_val,
                'drive_id' => $uploaded_file_info->id,
                'drive_link' => $uploaded_file_info->webViewLink,
                'drive_folder_id' => $copies_folder_id,
            ];
            
        } catch (\Exception $e) {
            $all_upload_success = false;
            error_log('Помилка завантаження файлу ' . $file['name'] . ' на Drive: ' . $e->getMessage());
        }
    }
    
    // 4. Збереження/оновлення в сесії та фінальний результат
    if (isset($_SESSION['ppo_formats'][$format]) && is_array($_SESSION['ppo_formats'][$format])) {
        $_SESSION['ppo_formats'][$format]['total_copies'] += $photo_count;
        $_SESSION['ppo_formats'][$format]['total_price'] += $total_sum_current_upload;
        $_SESSION['ppo_formats'][$format]['files'] = array_merge(
            $_SESSION['ppo_formats'][$format]['files'] ?? [], 
            $uploaded_files
        );
    } else {
        $_SESSION['ppo_formats'][$format] = [
            'total_copies' => $photo_count,
            'total_price' => $total_sum_current_upload,
            'drive_folder_id' => $format_folder_id,
            'files' => $uploaded_files,
        ];
    }
    
    $_SESSION['ppo_total'] += $total_sum_current_upload;
    
    if (!$all_upload_success && $total_sum_current_upload == 0) {
        wp_send_json_error(['message' => 'Жоден файл не був успішно завантажений на Google Drive. Перевірте логи.'], 500);
    }
    
    wp_send_json_success([
        'message' => 'Замовлення збережено на Google Drive! Додайте ще фото або оформіть доставку.',
        'formats' => array_filter($_SESSION['ppo_formats'] ?? [], 'is_array'),
        'total' => $_SESSION['ppo_total'],
    ]);
}

// ====================================================================
// 7. ОБРОБКА ТРАНЗИТНИХ ФОРМ (ДОСТАВКА/ОПЛАТА)
// ====================================================================
add_action('init', 'ppo_handle_forms');
function ppo_handle_forms() {
    $error_redirect_url = add_query_arg('error', urlencode('Помилка безпеки.'), PPO_ERROR_URL); 

    if (isset($_POST['ppo_go_to_delivery'])) {
        if (!isset($_POST['ppo_nonce']) || !wp_verify_nonce($_POST['ppo_nonce'], 'ppo_delivery_nonce')) {
            wp_safe_redirect($error_redirect_url); 
            exit;
        }
        wp_safe_redirect(home_url('/orderpagedelivery/'));
        exit;
    }
    if (isset($_POST['ppo_submit_delivery'])) {
        ppo_handle_delivery_submission();
    }
    if (isset($_POST['ppo_submit_payment'])) {
        ppo_handle_payment_submission();
    }
}

function ppo_handle_delivery_submission() {
    $referer_url = wp_get_referer() ?: home_url('/orderpagedelivery/');
    $error_redirect_url = add_query_arg('error', urlencode('Помилка безпеки.'), PPO_ERROR_URL); 
    
    if (!isset($_POST['ppo_nonce']) || !wp_verify_nonce($_POST['ppo_nonce'], 'ppo_delivery_nonce')) {
        wp_safe_redirect($error_redirect_url);
        exit;
    }

    if (!isset($_SESSION['ppo_order_id']) || empty(array_filter($_SESSION['ppo_formats'] ?? [], 'is_array'))) {
        wp_safe_redirect(add_query_arg('error', urlencode('Сесія замовлення неактивна.'), home_url('/order/')));
        exit;
    }

    $address = sanitize_textarea_field($_POST['address']);
    if (empty($address)) {
        wp_safe_redirect(add_query_arg('error', urlencode('Помилка: вкажіть адресу доставки.'), $referer_url));
        exit;
    }

    $_SESSION['ppo_delivery_address'] = $address;
    wp_safe_redirect(home_url('/payment/'));
    exit;
}

function ppo_handle_payment_submission() {
    $referer_url = wp_get_referer() ?: home_url('/payment/');
    $error_redirect_url = add_query_arg('error', urlencode('Помилка безпеки.'), PPO_ERROR_URL); 
    
    if (!isset($_POST['ppo_nonce']) || !wp_verify_nonce($_POST['ppo_nonce'], 'ppo_payment_nonce')) {
        wp_safe_redirect($error_redirect_url);
        exit;
    }
    
    $session_formats = array_filter($_SESSION['ppo_formats'] ?? [], 'is_array');
    $order_folder_id = $_SESSION['ppo_formats']['order_folder_id'] ?? null;
    
    if (!isset($_SESSION['ppo_order_id']) || empty($session_formats) || empty($_SESSION['ppo_delivery_address'])) {
        wp_safe_redirect(add_query_arg('error', urlencode('Неповні дані для замовлення.'), home_url('/order/')));
        exit;
    }

    // Створення посту замовлення
    $order_id = wp_insert_post([
        'post_type' => 'photo_order',
        'post_title' => 'Замовлення ' . $_SESSION['ppo_order_id'],
        'post_status' => 'publish',
    ]);

    if (is_wp_error($order_id)) {
        wp_safe_redirect(add_query_arg('error', urlencode('Помилка створення замовлення.'), $referer_url));
        exit;
    }

    // Збереження метаданих
    update_post_meta($order_id, 'ppo_formats', $session_formats);
    update_post_meta($order_id, 'ppo_total', $_SESSION['ppo_total']);
    update_post_meta($order_id, 'ppo_address', $_SESSION['ppo_delivery_address']);
    update_post_meta($order_id, 'ppo_payment_method', sanitize_text_field($_POST['payment_method'] ?? 'card'));
    
    // Збереження ID папки Drive
    if ($order_folder_id) {
         update_post_meta($order_id, 'ppo_drive_folder_id', $order_folder_id);
    }

    // Очищення сесії після успішного збереження
    unset($_SESSION['ppo_order_id'], $_SESSION['ppo_formats'], $_SESSION['ppo_total'], $_SESSION['ppo_delivery_address']);

    // Перенаправлення
    wp_safe_redirect(add_query_arg('success', 'order_completed', home_url('/payment/')));
    exit;
}

// ====================================================================
// 8. ФУНКЦІЇ РЕНДЕРУ ШОРТКОДІВ
// ====================================================================
function ppo_render_order_form() {
    ob_start();
    $order_id = isset($_SESSION['ppo_order_id']) ? $_SESSION['ppo_order_id'] : 'ORDER-' . wp_generate_uuid4();
    $min_order_sum = MIN_ORDER_SUM;
    $photo_prices = PHOTO_PRICES;
    
    ?>
    <style>
        /* Стилі для форми: кнопки, повідомлення, лоадер, контейнери файлів */
        .ppo-button { display: inline-block !important; padding: 8px 16px; margin: 5px; text-decoration: none; border-radius: 3px; font-size: 14px; visibility: visible !important; }
        .ppo-button-primary { background: #0073aa; color: white; }
        .ppo-button-primary:hover { background: #005177; }
        .ppo-button-secondary { background: #f7f7f7; color: #0073aa; border: 1px solid #0073aa; }
        .ppo-button-secondary:hover { background: #e0e0e0; }
        .ppo-total-sum { font-weight: bold; margin: 10px 0; }
        .ppo-buttons-container { margin-top: 15px; }
        .ppo-message { padding: 10px; margin: 10px 0; border-radius: 3px; }
        .ppo-message-success { color: green; background: #e8f5e8; }
        .ppo-message-error { color: red; background: #ffebee; }
        .ppo-message-warning { color: orange; background: #fff3cd; }
        .ppo-loader { display: none; margin-left: 10px; border: 3px solid #f3f3f3; border-top: 3px solid #0073aa; border-radius: 50%; width: 20px; height: 20px; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        
        #photo-quantities { 
            margin-top: 10px; 
            border: 1px solid #ccc; 
            padding: 10px; 
            border-radius: 4px;
            max-height: 400px;
            overflow-y: auto;
            background: #f9f9f9;
        }
        .photo-item {
            border-bottom: 1px solid #eee;
            padding: 8px 0;
            display: flex; 
            align-items: center; 
            justify-content: space-between;
        }
        .photo-item:last-child {
            border-bottom: none;
        }
        .photo-item label {
            font-weight: normal; 
            font-size: 0.9em; 
            display: inline-block; 
            width: 40%; 
            flex-grow: 1; 
            text-overflow: ellipsis; 
            overflow: hidden; 
            white-space: nowrap;
            margin-right: 10px;
        }
        .photo-item input[type="number"] {
            width: 70px; 
            margin: 0 10px; 
            padding: 5px; 
            text-align: center; 
            flex-shrink: 0;
            box-sizing: border-box;
        }
        .photo-thumbnail-container {
            width: 40px; 
            height: 40px; 
            border: 1px solid #ccc; 
            display: inline-flex; 
            margin-right: 10px; 
            flex-shrink: 0; 
            position: relative;
            overflow: hidden;
            align-items: center;
            justify-content: center;
        }
        .photo-thumbnail-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
    </style>

    <div id="ppo-alert-messages">
        <?php if (isset($_GET['error'])) echo '<div class="ppo-message ppo-message-error"><p>' . esc_html(urldecode($_GET['error'])) . '</p></div>'; ?>
        <?php if (isset($_GET['success']) && $_GET['success'] === 'format_added') echo '<div class="ppo-message ppo-message-success"><p>Замовлення збережено! Додайте ще фото або оформіть доставку.</p></div>'; ?>
    </div>
    
    <p>Виберіть формат і до **<?php echo MAX_FILES_PER_UPLOAD; ?>** фото, вкажіть кількість копій (сума ≥<?php echo $min_order_sum; ?> грн), потім натисніть "**Зберегти замовлення**".</p>
    
    <a href="<?php echo esc_url(home_url('/order/?clear_session=1')); ?>" class="ppo-button ppo-button-secondary" style="margin-bottom: 15px;">Очистити всю сесію замовлення</a>
    
    <form id="photo-print-order-form" enctype="multipart/form-data">
        <label for="format">Оберіть формат фото:</label>
        <select name="format" id="format" required style="width: 100%; padding: 10px; margin-bottom: 15px;">
            <option value="">-- виберіть --</option>
            <?php foreach ($photo_prices as $format => $price): ?>
                <option value="<?php echo esc_attr($format); ?>" data-price="<?php echo esc_attr($price); ?>">
                    <?php echo esc_html($format . " см — " . $price . " грн/шт"); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="photos">Виберіть фото (максимум <?php echo MAX_FILES_PER_UPLOAD; ?>):</label>
        <input type="file" name="photos[]" id="photos" multiple accept="image/jpeg,image/png" style="width: 100%; padding: 10px 0;">

        <div id="photo-quantities-container">
            <h4>Кількість копій та видалення</h4>
            <div id="photo-quantities">
                <p style="text-align: center; color: #666;">Виберіть формат та фото для відображення списку.</p>
            </div>
            
            <p id="sum-warning" class="ppo-message ppo-message-warning" style="display: none;">
                Недостатня сума! Додайте більше фото або копій, щоб досягти мінімуму <?php echo $min_order_sum; ?> грн для цього формату.
            </p>

            <p class="ppo-total-sum">Сума поточного завантаження: <span id="current-upload-sum">0</span> грн</p>
            <p class="ppo-total-sum">Загальна сума для вибраного формату (з поточним): <span id="format-total-sum">0</span> грн (мін. <?php echo $min_order_sum; ?> грн)</p>
        </div>

        <input type="hidden" name="order_id" value="<?php echo esc_attr($order_id); ?>" id="order_id_input">
        <div style="display: flex; align-items: center;">
            <button type="submit" name="ppo_submit_order" class="ppo-button ppo-button-primary" id="submit-order" disabled>Зберегти замовлення</button>
            <div id="ppo-loader" class="ppo-loader"></div>
            <button type="button" id="clear-form" class="ppo-button ppo-button-secondary">Очистити</button>
        </div>
    </form>

    <div id="ppo-summary">
        <?php 
        $session_formats = array_filter($_SESSION['ppo_formats'] ?? [], 'is_array');
        $has_order = !empty($session_formats);
        $total_copies_overall = 0;
        $session_total_display = $_SESSION['ppo_total'] ?? 0;
        if ($has_order) {
            $total_copies_overall = array_sum(array_column($session_formats, 'total_copies'));
        }
        ?>
        <div id="ppo-formats-list-container" style="<?php echo $has_order ? '' : 'display: none;'; ?>">
            <h3>Додані формати:</h3>
            <ul id="ppo-formats-list">
                <?php if ($has_order): ?>
                    <?php foreach ($session_formats as $format => $details): ?>
                        <li><?php echo esc_html($format . ': ' . $details['total_copies'] . ' копій, ' . $details['total_price'] . ' грн'); ?></li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
            <p class="ppo-total-sum">
                Загальна сума замовлення: <span id="ppo-session-total"><?php echo esc_html($session_total_display); ?> грн <small>(Всього копій: <?php echo $total_copies_overall; ?>)</small></span>
            </p>
            <div class="ppo-buttons-container">
                <form method="post" style="display: inline;">
                    <?php wp_nonce_field('ppo_delivery_nonce', 'ppo_nonce'); ?>
                    <input type="submit" name="ppo_go_to_delivery" value="Оформити доставку" class="ppo-button ppo-button-primary">
                </form>
                <a href="<?php echo esc_url(home_url('/order/')); ?>" class="ppo-button ppo-button-secondary">Додати ще фото</a>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function ppo_render_delivery_form() {
    if (!isset($_SESSION['ppo_order_id']) || empty(array_filter($_SESSION['ppo_formats'] ?? [], 'is_array'))) {
        return '<div class="ppo-message ppo-message-error"><p>Замовлення не знайдено. Будь ласка, почніть із <a href="' . esc_url(home_url('/order/')) . '">форми замовлення</a>.</p></div>';
    }
    
    ob_start();
    if (isset($_GET['error'])) {
        echo '<div class="ppo-message ppo-message-error"><p>' . esc_html(urldecode($_GET['error'])) . '</p></div>';
    }
    ?>
    <div class="ppo-delivery-form-container">
        <h2>Крок 2: Оформлення доставки</h2>
        <p>Ваше замовлення на суму **<?php echo esc_html($_SESSION['ppo_total'] ?? 0); ?> грн** готове. Вкажіть адресу доставки.</p>
        
        <form method="post">
            <?php wp_nonce_field('ppo_delivery_nonce', 'ppo_nonce'); ?>
            
            <label for="address">Адреса доставки (напр., Нова Пошта, УкрПошта, кур'єр):</label>
            <textarea name="address" id="address" rows="5" required style="width: 100%; padding: 10px;"><?php echo esc_textarea($_SESSION['ppo_delivery_address'] ?? ''); ?></textarea>
            
            <div class="ppo-buttons-container">
                <a href="<?php echo esc_url(home_url('/order/')); ?>" class="ppo-button ppo-button-secondary">← Назад до замовлення</a>
                <input type="submit" name="ppo_submit_delivery" value="Перейти до оплати" class="ppo-button ppo-button-primary">
            </div>
        </form>
    </div>
    <?php
    return ob_get_clean();
}

function ppo_render_payment_form() {
    if (!isset($_SESSION['ppo_order_id']) || empty($_SESSION['ppo_delivery_address'])) {
        return '<div class="ppo-message ppo-message-error"><p>Неповні дані. Почніть з <a href="' . esc_url(home_url('/orderpagedelivery/')) . '">доставки</a>.</p></div>';
    }
    
    ob_start();
    $total = $_SESSION['ppo_total'] ?? 0;
    $session_formats = array_filter($_SESSION['ppo_formats'] ?? [], 'is_array');
    
    if (isset($_GET['success']) && $_GET['success'] === 'order_completed'): ?>
        <div class="ppo-message ppo-message-success">
            <h2>🎉 Замовлення успішно оформлено!</h2>
            <p>Ваше замовлення (**<?php echo esc_html($_SESSION['ppo_order_id'] ?? 'N/A'); ?>**) прийнято в обробку. Загальна сума: **<?php echo esc_html($total); ?> грн**.</p>
            <p>Наші менеджери зв'яжуться з вами для уточнення деталей оплати та відправлення.</p>
        </div>
        <p><a href="<?php echo esc_url(home_url('/order/?clear_session=1')); ?>" class="ppo-button ppo-button-primary">Створити нове замовлення</a></p>
    <?php else: ?>
        <h2>Крок 3: Оплата та підтвердження</h2>
        <p>Ваше замовлення:</p>
        <ul>
            <?php foreach ($session_formats as $format => $details): ?>
                <li>**<?php echo esc_html($format); ?>**: <?php echo esc_html($details['total_copies']); ?> копій (<?php echo esc_html($details['total_price']); ?> грн)</li>
            <?php endforeach; ?>
        </ul>
        <p>Адреса доставки: **<?php echo esc_html($_SESSION['ppo_delivery_address'] ?? 'Не вказано'); ?>**</p>
        <p class="ppo-total-sum">Загальна сума до сплати: <span style="font-size: 1.2em;"><?php echo esc_html($total); ?> грн</span></p>

        <p>Виберіть спосіб оплати:</p>
        <form method="post">
            <?php wp_nonce_field('ppo_payment_nonce', 'ppo_nonce'); ?>
            
            <label><input type="radio" name="payment_method" value="card" required checked> Оплата карткою (LiqPay/інший сервіс)</label><br>
            <label><input type="radio" name="payment_method" value="bank_transfer" required> Оплата за реквізитами</label><br><br>
            
            <div class="ppo-buttons-container">
                <a href="<?php echo esc_url(home_url('/orderpagedelivery/')); ?>" class="ppo-button ppo-button-secondary">← Назад до доставки</a>
                <input type="submit" name="ppo_submit_payment" value="Підтвердити замовлення" class="ppo-button ppo-button-primary">
            </div>
        </form>
    <?php endif;

    return ob_get_clean();
}