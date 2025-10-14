<?php
/**
 * Plugin Name: Photo Print Orders
 * Description: Плагін для замовлення друку фото з завантаженням файлів у CDN Express.
 * Version: 4.2 (Інтеграція унікального номера замовлення ДДММРРNNN)
 * Author: Помічник із програмування
 */

if (!defined('ABSPATH')) {
    exit;
}

// ====================================================================
// 1. ПІДКЛЮЧЕННЯ КОНФІГУРАЦІЇ ТА БІБЛІОТЕК
// ====================================================================
// Всі константи (PPO_CDN_HOST, PPO_CDN_LOGIN, PPO_CDN_PASSWORD, PPO_ERROR_URL тощо) 
// повинні бути визначені у ppo-config.php.
require_once plugin_dir_path(__FILE__) . 'ppo-config.php';
require_once plugin_dir_path(__FILE__) . 'ppo-cdn-express-uploader.php'; 

// Перевірка наявності необхідних констант після підключення
if (!defined('PPO_CDN_HOST') || !defined('PPO_CDN_LOGIN') || !defined('PPO_CDN_PASSWORD')) {
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p>Помилка Photo Print Orders: Не визначено CDN облікові дані. Перевірте ppo-config.php.</p></div>';
    });
    return; // Зупиняємо виконання плагіна, якщо конфігурація неповна
}

// ====================================================================
// НОВА ФУНКЦІЯ: ГЕНЕРАЦІЯ НОМЕРА (ДДММРРNNN)
// ====================================================================
/**
 * Генерує новий номер замовлення у форматі ДДММРРNNN.
 * Зберігає та оновлює порядковий номер на поточну дату в опціях WordPress.
 *
 * @return string Новий 9-значний номер замовлення.
 */
function ppo_generate_order_number() {
    $date_part = date('dmy'); // ДДММРР
    $option_name = 'ppo_daily_order_counter_' . $date_part;

    // 1. Отримати поточний лічильник
    $current_count = get_option($option_name, 0); 
    
    // 2. Збільшити лічильник
    $new_count = $current_count + 1;

    // 3. Зберегти оновлений лічильник у базу даних
    if ($current_count === 0) {
        add_option($option_name, $new_count, '', 'no');
    } else {
        update_option($option_name, $new_count);
    }
    
    // 4. Форматування порядкового номера (001, 002... 999)
    $counter_part = str_pad($new_count, 3, '0', STR_PAD_LEFT);

    // 5. Повний номер
    $order_number = $date_part . $counter_part;

    return $order_number;
}


// ====================================================================
// 2. СЕСІЇ ТА ОЧИЩЕННЯ
// ====================================================================
add_action('init', 'ppo_start_session', 1);
function ppo_start_session() {
    if (!session_id() && !defined('DOING_CRON') && !defined('WP_CLI')) {
        session_start();
    }
}
add_action('init', function() {
    // Функція для очищення всієї сесії замовлення
    if (isset($_GET['clear_session']) && $_GET['clear_session'] === '1') {
        session_destroy();
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
        }
        // Перенаправлення на головну сторінку замовлення
        wp_safe_redirect(home_url('/order/'));
        exit;
    }
});

// ====================================================================
// 3. РЕЄСТРАЦІЯ POST TYPE ТА КОЛОНОК
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
        // Title буде оновлюватися на номер замовлення
        'supports' => ['title'], 
        'menu_icon' => 'dashicons-format-gallery',
    ]);
}
add_filter('manage_photo_order_posts_columns', function($columns) {
    // Додаємо нову колонку для номера
    $new_columns = [];
    $new_columns['cb'] = $columns['cb'];
    $new_columns['title'] = 'Номер Замовлення'; // Перейменовуємо стандартний Title
    $new_columns['details'] = 'Деталі замовлення';
    $new_columns['cdn_path'] = 'CDN Шлях';
    unset($columns['date']);
    unset($columns['title']);
    return array_merge($new_columns, $columns);
});
add_action('manage_photo_order_posts_custom_column', function($column, $post_id) {
    switch ($column) {
        case 'details':
            // Отримуємо метадані
            $formats = get_post_meta($post_id, 'ppo_formats', true);
            $total = get_post_meta($post_id, 'ppo_total', true);
            $address = get_post_meta($post_id, 'ppo_address', true);
            
            if ($formats) {
                echo '<strong>Формати:</strong><br>';
                foreach ($formats as $format => $details) {
                    // Фільтруємо технічний ключ 'order_folder_path'
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
            break;
        case 'cdn_path':
            $cdn_path = get_post_meta($post_id, 'ppo_cdn_folder_path', true); 
            // ОНОВЛЕНО: Формування URL відповідно до шаблону https://print.cdn.express/~/o/141025002
            // Припускаємо, що PPO_CDN_HOST — це "print.cdn.express", а $cdn_path — це "/141025002"
            if ($cdn_path) {
                // Видаляємо скісну риску на початку, якщо вона є
                $clean_cdn_path = ltrim($cdn_path, '/'); 
                // Створюємо посилання: https://print.cdn.express/~/o/НОМЕР_ЗАМОВЛЕННЯ
                $full_url = 'https://' . PPO_CDN_HOST . '/~/o/' . esc_attr($clean_cdn_path); 
                echo '<a href="' . esc_url($full_url) . '" target="_blank">' . esc_html($cdn_path) . '</a>';
            } else {
                echo 'N/A';
            }
            break;
    }
}, 10, 2);


// ====================================================================
// 3.5. АДМІНКА: МЕТАБОКС ДЛЯ ДЕТАЛЕЙ ЗАМОВЛЕННЯ (Оновлено стилі таблиці)
// ====================================================================
add_action('add_meta_boxes', 'ppo_add_order_details_metabox');
function ppo_add_order_details_metabox() {
    add_meta_box(
        'ppo_order_details_metabox',
        'Деталі Замовлення та CDN',
        'ppo_render_order_details_metabox',
        'photo_order',
        'normal',
        'high'
    );
}

function ppo_render_order_details_metabox($post) {
    // 1. Отримання всіх збережених метаданих (без змін)
    $order_number = get_post_meta($post->ID, 'ppo_order_number', true);
    // ... (решта змінних метаданих) ...
    $cdn_folder_path = get_post_meta($post->ID, 'ppo_cdn_folder_path', true);
    $formats_data = get_post_meta($post->ID, 'ppo_formats', true);
    
    // Формування посилання CDN (без змін)
    $cdn_link = 'N/A';
    // ...
    // ... (код формування $cdn_link) ...
    $payment_label = $payment_method === 'card' ? 'Оплата карткою (LiqPay/інший)' : 'Оплата за реквізитами';

    ?>
    <style>
        .ppo-details-table th, .ppo-details-table td {
            padding: 8px 10px;
            border: 1px solid #ddd;
            text-align: left;
        }
        .ppo-details-table th {
            width: 30%; /* Залишаємо для загальної таблиці */
            background-color: #f7f7f7;
        }
        .ppo-details-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        /* НОВІ СТИЛІ: Для деталізації по форматах та файлах */
        #ppo_formats_table th.col-format { width: 15%; }
        #ppo_formats_table th.col-copies { width: 15%; }
        #ppo_formats_table th.col-price { width: 15%; }
        #ppo_formats_table th.col-details { width: 55%; }
        /* Кінець нових стилів */

        .ppo-formats-list {
            list-style: disc;
            margin-left: 20px;
        }
    </style>
    
    <h3>Загальна інформація про замовлення</h3>
    <table class="ppo-details-table">
        </table>
    
    <h3>Деталізація по форматах та файлах</h3>
    <?php if (!empty($formats_data) && is_array($formats_data)): ?>
        <table class="ppo-details-table" id="ppo_formats_table">
            <thead>
                <tr>
                    <th class="col-format">Формат</th>
                    <th class="col-copies">Всього Копій</th>
                    <th class="col-price">Сума за Формат</th>
                    <th class="col-details">Деталі Файлів</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                // Фільтруємо, щоб показати лише формати, ігноруючи order_folder_path
                foreach (array_filter($formats_data, 'is_array') as $format => $details): 
                ?>
                    <tr>
                        <td><strong><?php echo esc_html($format); ?></strong></td>
                        <td><?php echo esc_html($details['total_copies']); ?></td>
                        <td><?php echo esc_html($details['total_price']); ?> грн</td>
                        <td>
                            <?php if (!empty($details['files']) && is_array($details['files'])): ?>
                                <ul class="ppo-formats-list">
                                    <?php foreach ($details['files'] as $file): ?>
                                        <li>
                                            **<?php echo esc_html($file['name']); ?>** (<?php echo esc_html($file['copies']); ?> копій). 
                                            <small>Шлях: <?php echo esc_html($file['cdn_folder_path']); ?></small>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                Немає даних про файли.
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>Деталі форматів відсутні.</p>
    <?php endif;
}



// ====================================================================
// 4. РЕЄСТРАЦІЯ ШОРТКОДІВ ТА СКРИПТІВ
// ====================================================================
add_shortcode('photo_print_order_form', 'ppo_render_order_form');
add_shortcode('photo_print_delivery_form', 'ppo_render_delivery_form');
add_shortcode('photo_print_payment_form', 'ppo_render_payment_form');

add_action('wp_enqueue_scripts', 'ppo_enqueue_scripts');
function ppo_enqueue_scripts() {
    wp_register_script('ppo-ajax-script', plugin_dir_url(__FILE__) . 'ppo-ajax-script.js', ['jquery'], '4.2', true);
    wp_enqueue_script('ppo-ajax-script');

    // Фільтруємо системні ключі перед передачею в JS
    $session_formats_filtered = array_filter($_SESSION['ppo_formats'] ?? [], 'is_array');
    $session_total = array_sum(array_column($session_formats_filtered, 'total_price'));
    
    wp_localize_script('ppo-ajax-script', 'ppo_ajax_object', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('ppo_ajax_nonce'),
        'max_files' => MAX_FILES_PER_UPLOAD,
        'min_sum'  => MIN_ORDER_SUM,
        'prices'   => PHOTO_PRICES,
        'session_formats' => $session_formats_filtered,
        'session_total' => $session_total,
        'redirect_delivery' => home_url('/orderpagedelivery/'),
        'redirect_error' => PPO_ERROR_URL,
    ]);
}

// ====================================================================
// 5. ОБРОБКА AJAX ЗАВАНТАЖЕННЯ ФАЙЛІВ
// ====================================================================
add_action('wp_ajax_ppo_file_upload', 'ppo_ajax_file_upload');
add_action('wp_ajax_nopriv_ppo_file_upload', 'ppo_ajax_file_upload');

function ppo_ajax_file_upload() {
    // 1. Перевірка безпеки та вхідних даних
    if (!isset($_POST['ppo_ajax_nonce']) || !wp_verify_nonce($_POST['ppo_ajax_nonce'], 'ppo_ajax_nonce')) {
        wp_send_json_error(['message' => 'Помилка безпеки.'], 403);
    }

    $format = sanitize_text_field($_POST['format']);
    // $order_id = sanitize_text_field($_POST['order_id']); // ВИДАЛЕНО: order_id генерується нижче
    $copies_json = stripslashes($_POST['copies']);
    $copies = json_decode($copies_json, true) ?? [];
    $files = $_FILES['photos'];
    $price_per_photo = PHOTO_PRICES[$format] ?? 0;

    // --- Фільтрація та перевірка файлів (логіка не змінена) ---
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
             'name' => sanitize_file_name($filename),
             'tmp_name' => $files['tmp_name'][$key],
             'copies_count' => $copies_count, 
         ];
         $valid_file_index++; 
    }
    
    if ($valid_file_index === 0) {
        wp_send_json_error(['message' => 'Не знайдено жодного файлу для завантаження.'], 400);
    }
    if ($valid_file_index > MAX_FILES_PER_UPLOAD) {
        wp_send_json_error(['message' => 'Максимум ' . MAX_FILES_PER_UPLOAD . ' файлів дозволено за раз.'], 400);
    }
    
    // Перерахунок суми
    $photo_count = 0; 
    $total_sum_current_upload = 0; 
    foreach ($files_to_move as $file) {
        $copies_val = $file['copies_count'];
        $photo_count += $copies_val;
        $total_sum_current_upload += $copies_val * $price_per_photo;
    }
    
    // ====================================================================
    // ОНОВЛЕННЯ 1: ГЕНЕРАЦІЯ НОМЕРА ЗАМОВЛЕННЯ
    // ====================================================================
    if (!isset($_SESSION['ppo_order_id'])) {
        // Якщо це перше завантаження у сесії, генеруємо новий номер
        $_SESSION['ppo_order_id'] = ppo_generate_order_number(); 
    }
    $current_order_id = $_SESSION['ppo_order_id'];
    
    // Ініціалізація інших сесійних змінних
    $_SESSION['ppo_formats'] = $_SESSION['ppo_formats'] ?? []; 
    $_SESSION['ppo_total'] = $_SESSION['ppo_total'] ?? 0;

    $current_format_total_in_session = $_SESSION['ppo_formats'][$format]['total_price'] ?? 0;
    $new_format_total_sum = $current_format_total_in_session + $total_sum_current_upload;

    // Перевірка мінімальної суми
    if ($total_sum_current_upload > 0 && $new_format_total_sum < MIN_ORDER_SUM) {
        $message = "Мінімальна сума замовлення для формату $format — " . MIN_ORDER_SUM . " грн. Ваша сума (з цими фото): " . round($new_format_total_sum, 0) . " грн. Додайте ще фото.";
        wp_send_json_error(['message' => $message], 400);
    }
    
    // ====================================================================
    // 6. ЛОГІКА ЗБЕРЕЖЕННЯ: CDN Express (Ініціалізація та Завантаження)
    // ====================================================================
    try {
        if (!class_exists('PPO_CDN_Express_Uploader')) {
             throw new \Exception('CDN Uploader class is missing.');
        }

        $uploader = new PPO_CDN_Express_Uploader(
            PPO_CDN_HOST,
            PPO_CDN_LOGIN,
            PPO_CDN_PASSWORD,
            PPO_CDN_ROOT_PATH
        );
    } catch (\Exception $e) {
        error_log('Помилка ініціалізації CDN: ' . $e->getMessage());
        wp_send_json_error(['message' => 'Помилка ініціалізації CDN: ' . $e->getMessage()], 500);
    }

    $format_folder_path = null;
    $all_upload_success = true;
    
    // ОНОВЛЕННЯ 2: Використовуємо згенерований $current_order_id для назви папки
    $order_folder_name = $current_order_id;  

    // 1. Створення папки замовлення 
    $order_folder_path = $_SESSION['ppo_formats']['order_folder_path'] ?? null;
    try {
        if (!$order_folder_path) {
            $order_folder_path = $uploader->create_folder($order_folder_name, PPO_CDN_ROOT_PATH);
            // Зберігаємо шлях у сесії
            $_SESSION['ppo_formats']['order_folder_path'] = $order_folder_path; 
        }
    } catch (\Exception $e) {
        error_log('CDN Error (Order Folder): ' . $e->getMessage());
        wp_send_json_error(['message' => 'Помилка створення папки замовлення на CDN: ' . $e->getMessage()], 500);
    }
    
    // 2. Створення папки формату
    try {
        $format_folder_path = $uploader->create_folder($format, $order_folder_path);
    } catch (\Exception $e) {
        error_log('CDN Error (Format Folder): ' . $e->getMessage());
        wp_send_json_error(['message' => 'Помилка створення папки формату на CDN: ' . $e->getMessage()], 500);
    }
    
    // 3. Створення папок копій та завантаження файлів
    $uploaded_files = [];
    foreach ($files_to_move as $file) {
        $copies_val = $file['copies_count'];
        $copies_folder_name = $copies_val;
        
        try {
            // Створення папки для копій
            $copies_folder_path = $uploader->create_folder($copies_folder_name, $format_folder_path);

            // Завантаження файлу
            $uploaded_file_info = $uploader->upload_file(
                $file['tmp_name'], 
                $file['name'], 
                $copies_folder_path
            );

            $uploaded_files[] = [
                'name' => $file['name'],
                'copies' => $copies_val,
                'cdn_path' => $uploaded_file_info->path, // Шлях у сховищі
                'cdn_link' => $uploaded_file_info->webViewLink, // Пряме посилання
                'cdn_folder_path' => $copies_folder_path,
            ];
            
        } catch (\Exception $e) {
            $all_upload_success = false;
            error_log('CDN Error (Upload File ' . $file['name'] . '): ' . $e->getMessage());
            // Продовжуємо спроби з іншими файлами, але фіксуємо помилку
        }
    }
    
    // 4. Збереження/оновлення в сесії 
    if ($total_sum_current_upload > 0 && !empty($uploaded_files)) {
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
                'cdn_folder_path' => $format_folder_path, 
                'files' => $uploaded_files,
            ];
        }
        
        $_SESSION['ppo_total'] += $total_sum_current_upload;
    }
    
    if (!$all_upload_success && empty($uploaded_files)) {
        // Якщо жоден файл не завантажено
        wp_send_json_error(['message' => 'Критична помилка. Жоден файл не був успішно завантажений на CDN. Перевірте логи.'], 500);
    }
    
    // Відповідь для клієнта
    wp_send_json_success([
        'message' => 'Замовлення ' . $current_order_id . ' збережено на CDN! Додайте ще фото або оформіть доставку.',
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
    
    // ОНОВЛЕННЯ 3: Отримуємо номер та шлях CDN з сесії
    $order_number = $_SESSION['ppo_order_id'] ?? null; 
    $cdn_folder_path = $_SESSION['ppo_formats']['order_folder_path'] ?? null; 
    
    if (empty($order_number) || empty($session_formats) || empty($_SESSION['ppo_delivery_address'])) {
        wp_safe_redirect(add_query_arg('error', urlencode('Неповні дані для замовлення.'), home_url('/order/')));
        exit;
    }

    // Створення посту замовлення
    $post_args = [
        'post_type' => 'photo_order',
        // ОНОВЛЕННЯ 4: Використовуємо номер як заголовок (буде видно в адмінці)
        'post_title' => 'Замовлення №' . $order_number, 
        'post_status' => 'pending', // Змінено на pending для ручної обробки
    ];
    
    $order_id = wp_insert_post($post_args);

    if (is_wp_error($order_id)) {
        wp_safe_redirect(add_query_arg('error', urlencode('Помилка створення замовлення.'), $referer_url));
        exit;
    }

    // Збереження метаданих
    update_post_meta($order_id, 'ppo_formats', $session_formats);
    update_post_meta($order_id, 'ppo_total', $_SESSION['ppo_total']);
    update_post_meta($order_id, 'ppo_address', $_SESSION['ppo_delivery_address']);
    update_post_meta($order_id, 'ppo_payment_method', sanitize_text_field($_POST['payment_method'] ?? 'card'));
    
    // ОНОВЛЕННЯ 5: Зберігаємо сам номер та шлях CDN
    if ($order_number) {
        update_post_meta($order_id, 'ppo_order_number', $order_number);
    }
    if ($cdn_folder_path) {
        update_post_meta($order_id, 'ppo_cdn_folder_path', $cdn_folder_path); 
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
    // ОНОВЛЕННЯ 6: order_id відображаємо як placeholder, оскільки він генерується на сервері
    $order_id = isset($_SESSION['ppo_order_id']) ? $_SESSION['ppo_order_id'] : 'Буде згенеровано при завантаженні...';
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
            // Фільтруємо системні ключі, такі як order_folder_path
            $display_formats = array_filter($session_formats, 'is_array');
            $total_copies_overall = array_sum(array_column($display_formats, 'total_copies'));
        }
        ?>
        <div id="ppo-formats-list-container" style="<?php echo $has_order ? '' : 'display: none;'; ?>">
            <h3>Додані формати:</h3>
            <ul id="ppo-formats-list">
                <?php if ($has_order): ?>
                    <?php 
                    // Відображаємо лише формати, ігноруючи технічні ключі
                    foreach ($session_formats as $key => $details): 
                         if (is_array($details)):
                    ?>
                        <li><?php echo esc_html($key . ': ' . $details['total_copies'] . ' копій, ' . $details['total_price'] . ' грн'); ?></li>
                    <?php 
                        endif; 
                    endforeach; 
                    ?>
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
        <p>Ваше замовлення **№<?php echo esc_html($_SESSION['ppo_order_id']); ?>** на суму **<?php echo esc_html($_SESSION['ppo_total'] ?? 0); ?> грн** готове. Вкажіть адресу доставки.</p>
        
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
    
    // Фільтруємо, щоб показувати лише реальні формати, а не order_folder_path
    $session_formats = array_filter($_SESSION['ppo_formats'] ?? [], 'is_array');
    
    if (isset($_GET['success']) && $_GET['success'] === 'order_completed'): ?>
        <div class="ppo-message ppo-message-success">
            <h2>🎉 Замовлення успішно оформлено!</h2>
            <p>Ваше замовлення (**№<?php echo esc_html($_SESSION['ppo_order_id'] ?? 'N/A'); ?>**) прийнято в обробку. Загальна сума: **<?php echo esc_html($total); ?> грн**.</p>
            <p>Наші менеджери зв'яжуться з вами для уточнення деталей оплати та відправлення.</p>
        </div>
        <p><a href="<?php echo esc_url(home_url('/order/?clear_session=1')); ?>" class="ppo-button ppo-button-primary">Створити нове замовлення</a></p>
    <?php else: ?>
        <h2>Крок 3: Оплата та підтвердження</h2>
        <p>Ваше замовлення **№<?php echo esc_html($_SESSION['ppo_order_id']); ?>**:</p>
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