<?php
/**
 * Клас для роботи з API CDN Express (авторизація, створення папок, завантаження файлів)
 */

if (!defined('ABSPATH')) {
    exit;
}

class PPO_CDN_Express_Uploader {

    private $host;
    private $login;
    private $password;
    private $root_path;

    public function __construct($host, $login, $password, $root_path = '/') {
        $this->host = $host;
        $this->login = $login;
        $this->password = $password;
        // Переконуємось, що кореневий шлях закінчується на /
        $this->root_path = rtrim($root_path, '/') . '/';
    }

    /**
     * Виконує авторизацію та отримує токен.
     * Токен зберігається в кеші WordPress на 2 години.
     * @return string Токен доступу.
     * @throws \Exception
     */
    private function get_auth_token() {
        $cache_key = 'ppo_cdn_auth_token';
        $token = get_transient($cache_key);

        if ($token) {
            return $token;
        }

        $url = 'https://' . $this->host . '/~/api/auth';
        
        $response = wp_remote_post($url, [
            'method' => 'PUT',
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'login' => $this->login,
                'password' => $this->password,
            ],
            'timeout' => 15, // таймаут на 15 секунд
        ]);

        if (is_wp_error($response)) {
            throw new \Exception('Помилка авторизації CDN: ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data['data']['token'])) {
             // Спроба отримати помилку із заголовка 401
             $status_code = wp_remote_retrieve_response_code($response);
             $error_message = ($status_code === 401) ? 'Invalid credential \'login\' or \'password\'.' : $body;
             throw new \Exception('CDN API: Не вдалося отримати токен. ' . $error_message);
        }

        $token = $data['data']['token'];
        // Зберігаємо токен на 3 години мінус 5 хвилин для запасу
        set_transient($cache_key, $token, HOUR_IN_SECONDS * 3 - MINUTE_IN_SECONDS * 5); 

        return $token;
    }

    /**
     * Створює каталог у сховищі.
     * @param string $folder_name Ім'я нової папки.
     * @param string $parent_path Шлях до батьківської папки.
     * @return string Повний шлях до створеної папки.
     * @throws \Exception
     */
    public function create_folder($folder_name, $parent_path = null) {
        $token = $this->get_auth_token();
        
        // Формуємо повний шлях
        $parent = rtrim($parent_path ?: $this->root_path, '/');
        $full_path = $parent . '/' . sanitize_title($folder_name);
        
        // 1. Спочатку перевіряємо, чи існує каталог, щоб уникнути 400 'File exists'
        if ($this->check_existence($full_path)) {
            return $full_path; 
        }

        $url = 'https://' . $this->host . '/~/api/directory';
        
        $response = wp_remote_post($url, [
            'method' => 'PUT',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'path' => $full_path,
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            throw new \Exception('Помилка створення CDN-каталогу: ' . $response->get_error_message());
        }
        
        $status = wp_remote_retrieve_response_code($response);
        
        if ($status === 200) {
            return $full_path;
        } else {
            $body = wp_remote_retrieve_body($response);
            throw new \Exception("CDN API: Помилка створення каталогу $full_path. Статус $status. Відповідь: $body");
        }
    }
    
    /**
     * Перевіряє існування файлу або каталогу.
     * @param string $path Шлях для перевірки.
     * @return bool True, якщо існує (статус 200), false, якщо ні (статус 404).
     */
    private function check_existence($path) {
        $token = $this->get_auth_token();
        
        $url = 'https://' . $this->host . '/~/api/file';
        
        $response = wp_remote_get($url . '?path=' . urlencode($path), [
            'method' => 'HEAD',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
            ],
            'timeout' => 10,
        ]);
        
        if (is_wp_error($response)) {
             // Ігноруємо помилки мережі тут, вважаємо, що не існує
             return false;
        }

        $status = wp_remote_retrieve_response_code($response);
        return $status === 200;
    }


    /**
     * Завантажує файл у сховище CDN.
     * @param string $tmp_name Тимчасовий шлях до файлу.
     * @param string $filename Оригінальне ім'я файлу.
     * @param string $parent_path Шлях до папки, куди завантажувати.
     * @return object Об'єкт з інформацією про завантажений файл.
     * @throws \Exception
     */
    public function upload_file($tmp_name, $filename, $parent_path) {
        $token = $this->get_auth_token();
        
        $full_path = rtrim($parent_path, '/') . '/' . basename($filename);
        $url = 'https://' . $this->host . '/~/api/file?path=' . urlencode($full_path);
        
        // 🛑 УВАГА: WordPress HTTP API не підтримує CURLOPT_PUT/CURLOPT_INFILE, 
        // тому необхідно використовувати чистий cURL для цього методу.
        if (!function_exists('curl_init')) {
            throw new \Exception('Для завантаження файлів потрібна бібліотека cURL.');
        }

        $file_handle = fopen($tmp_name, 'rb');
        if (!$file_handle) {
            throw new \Exception("Не вдалося відкрити тимчасовий файл: $tmp_name");
        }
        
        $curl = curl_init();
        
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => "PUT",
            CURLOPT_PUT => true,
            CURLOPT_INFILE => $file_handle,
            CURLOPT_INFILESIZE => filesize($tmp_name),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_TIMEOUT => 300, // 5 хвилин для великих файлів
        ]);
        
        $response_body = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($curl);
        curl_close($curl);
        fclose($file_handle);

        if ($http_code === 200) {
            // Успішно завантажено. Повертаємо об'єкт у форматі, схожому на Google Drive, 
            // для мінімальних змін в основному плагіні.
            return (object) [
                'id' => $full_path, // Використовуємо шлях як ID
                'webViewLink' => 'https://' . $this->host . $full_path, 
                'path' => $full_path,
            ];
        } else {
            $error_detail = $curl_error ?: $response_body;
            throw new \Exception("CDN API: Помилка завантаження файлу $filename. Статус $http_code. Деталі: $error_detail");
        }
    }
}