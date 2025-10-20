<?php
/**
 * Обробник AJAX-запитів для завантаження файлів на CDN.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Основна функція обробки завантаження файлів через AJAX.
 */
function ppo_ajax_file_upload() {
    // Перевірка nonce
    check_ajax_referer('ppo_file_upload_nonce', 'ppo_ajax_nonce');
    
    // Перевірка, чи не перевищено ліміт
    if (empty($_FILES['photos']['name'][0])) {
        wp_send_json_error(['message' => 'Не знайдено жодного файлу для завантаження.'], 400);
        wp_die();
    }
    
    // Перевірка на обмеження
    if (count($_FILES['photos']['name']) > MAX_FILES_PER_UPLOAD) {
        wp_send_json_error(['message' => 'Перевищено ліміт завантаження: ' . MAX_FILES_PER_UPLOAD . ' файлів.'], 400);
        wp_die();
    }
    
    // Перевірка даних форми
    $format = sanitize_text_field($_POST['format']);
    $copies_json = isset($_POST['copies']) ? stripslashes($_POST['copies']) : '[]';
    $copies_array = json_decode($copies_json, true);
    
    if (empty($format) || !isset(PHOTO_PRICES[$format])) {
        wp_send_json_error(['message' => 'Недійсний формат фотографій.'], 400);
        wp_die();
    }

    // Ініціалізація сесії
    if (!isset($_SESSION['ppo_order_id'])) {
        $_SESSION['ppo_order_id'] = ppo_generate_order_number();
        $_SESSION['ppo_formats'] = [];
        $_SESSION['ppo_total'] = 0;
    }
    
    // Ініціалізація змінних сесії для формату, якщо не існує
    if (!isset($_SESSION['ppo_formats'][$format])) {
        $_SESSION['ppo_formats'][$format] = [
            'price' => PHOTO_PRICES[$format],
            'total_copies' => 0,
            'total_price' => 0,
            'files' => [], // Масив: ['name' => 'file.jpg', 'copies' => 1, 'cdn_path' => '...']
        ];
    }
    
    $current_format = &$_SESSION['ppo_formats'][$format];
    $cdn_uploader = new PPO_CDN_Express_Uploader(PPO_CDN_HOST, PPO_CDN_LOGIN, PPO_CDN_PASSWORD, PPO_CDN_ROOT_PATH);

    $total_price_current_upload = 0;
    $total_copies_current_upload = 0;
    $files_to_add = [];

    // Визначаємо шлях до папки замовлення на CDN (формат: [ORDER_ID]/[FORMAT])
    $order_folder_name = $_SESSION['ppo_order_id'];
    $format_folder_name = sanitize_title($format); 
    
    // ДЕБАГ: Логування базових даних
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("PPO AJAX Debug: Order ID = '" . $order_folder_name . "', Format = '" . $format . "', Format Folder = '" . $format_folder_name . "', Files Count = " . count($_FILES['photos']['name']));
    }
    
    try {
        // 1. Створення кореневої папки замовлення (якщо не існує)
        $order_folder_path = $cdn_uploader->create_folder($order_folder_name, PPO_CDN_ROOT_PATH);
        // Зберігаємо шлях у сесії
        $_SESSION['ppo_formats']['order_folder_path'] = $order_folder_path; 
        
        // ДЕБАГ: Логування після створення order папки
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("PPO AJAX Debug: Order Folder Path = '" . $order_folder_path . "'");
        }
        
        // 2. Створення папки для поточного формату
        $full_format_path = $cdn_uploader->create_folder($format_folder_name, $order_folder_path);

        // ДЕБАГ: Логування після створення format папки
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("PPO AJAX Debug: Full Format Path = '" . $full_format_path . "'");
        }

        // 3. Завантаження та обробка кожного файлу (ТЕПЕР ПЕР-ФАЙЛ ПАПКА ЗА КОПІЯМИ)
        for ($i = 0; $i < count($_FILES['photos']['name']); $i++) {
            $filename = sanitize_file_name($_FILES['photos']['name'][$i]);
            $tmp_name = $_FILES['photos']['tmp_name'][$i];
            $file_type = $_FILES['photos']['type'][$i];
            $copies = isset($copies_array[$i]) ? intval($copies_array[$i]) : 1;
            
            // Валідація
            if ($copies < 1) $copies = 1;
            if (!in_array($file_type, ALLOWED_MIME_TYPES)) {
                throw new \Exception("Файл '{$filename}' має недопустимий тип: {$file_type}. Дозволено: " . implode(', ', ALLOWED_MIME_TYPES));
            }

            // НОВЕ: Створення підпапки за кількістю копій для цього файлу (e.g., '1', '12')
            $copies_folder_name = (string) $copies;  // "1", "12" тощо
            $copies_folder_path = $cdn_uploader->create_folder($copies_folder_name, $full_format_path);

            // ДЕБАГ: Логування для кожної підпапки
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("PPO AJAX Debug: Copies for file " . $i . " = '" . $copies . "', Copies Folder Name = '" . $copies_folder_name . "', Copies Path = '" . $copies_folder_path . "'");
            }

            // Завантаження файлу на CDN у copies-папку
            $cdn_file_info = $cdn_uploader->upload_file($tmp_name, $filename, $copies_folder_path);
            
            // ДЕБАГ: Логування для кожного файлу
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("PPO AJAX Debug: Uploading file '" . $filename . "' to path '" . $cdn_file_info->path . "' (copies: " . $copies . ")");
            }
            
            // Розрахунок підсумків для цього файлу
            $file_price = $copies * $current_format['price'];
            $total_price_current_upload += $file_price;
            $total_copies_current_upload += $copies;

            // Збереження даних про файл
            $files_to_add[] = [
                'name'      => $filename,
                'copies'    => $copies,
                'cdn_path'  => $cdn_file_info->path,
            ];
        }

        // 4. Оновлення сесії
        $current_format['total_copies'] += $total_copies_current_upload;
        $current_format['total_price'] += $total_price_current_upload;
        $current_format['files'] = array_merge($current_format['files'], $files_to_add);
        
        // Перерахунок загальної суми замовлення
        $_SESSION['ppo_total'] = 0;
        foreach (array_filter($_SESSION['ppo_formats'] ?? [], 'is_array') as $details) {
            $_SESSION['ppo_total'] += $details['total_price'];
        }
        
        // Успішне завершення
        wp_send_json_success([
            'message' => 'Успішно додано ' . count($files_to_add) . ' фото (' . $total_copies_current_upload . ' копій) до формату ' . $format . '.',
            'formats' => array_filter($_SESSION['ppo_formats'] ?? [], 'is_array'),
            'total'   => $_SESSION['ppo_total'],
        ]);

    } catch (\Exception $e) {
        // Логування помилки CDN
        error_log("PPO CDN Error ({$_SESSION['ppo_order_id']}): " . $e->getMessage());
        
        // Повернення помилки на клієнт
        wp_send_json_error(['message' => 'Критична помилка завантаження: ' . $e->getMessage()], 500);
    }
}