<?php
/**
 * Клас для роботи з Google Drive API, створення папок та завантаження файлів.
 */
class PPO_Google_Drive_Uploader {
    private $service;
    private $rootFolderId;

    /**
     * Конструктор: Ініціалізує Google Client та Drive Service за допомогою Refresh Token.
     * @param string $clientId 
     * @param string $clientSecret
     * @param string $refreshToken 
     * @param string $rootFolderId ID кореневої папки, куди будуть завантажуватися всі замовлення.
     */
    public function __construct(
        $clientId, 
        $clientSecret, 
        $refreshToken, 
        $rootFolderId
    ) {
        $this->rootFolderId = $rootFolderId;

        if (!class_exists('Google\Client')) {
             throw new \Exception('Google Client library is not loaded. Please run composer install.');
        }

        $client = new Google\Client();
        
        // Встановлюємо облікові дані програми
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        
        // Встановлюємо Refresh Token для отримання нового Access Token
        $client->refreshToken($refreshToken); 

        // Оновлюємо Access Token
        $accessToken = $client->getAccessToken();
        
        if (is_array($accessToken) && isset($accessToken['error'])) {
             // Може статися, якщо Refresh Token анульовано
             throw new \Exception('Refresh Token помилка: ' . ($accessToken['error_description'] ?? 'невідома помилка.'));
        }
        
        $client->setAccessToken($accessToken); 
        
        // Створення сервісу Google Drive
        $this->service = new Google\Service\Drive($client);
    }

    /**
     * Створює папку, якщо вона не існує, або повертає ID існуючої.
     * @param string $folderName Назва папки.
     * @param string|null $parentId ID батьківської папки (за замовчуванням - коренева папка).
     * @return string ID створеної або існуючої папки.
     * @throws \Exception Якщо не вдалося створити папку або батьківський ID недійсний.
     */
    public function create_folder($folderName, $parentId = null) {
        $parent = $parentId ?: $this->rootFolderId; 

        if (empty($parent)) {
            throw new \Exception("Батьківський ID недійсний.");
        }

        // 1. Шукаємо існуючу папку
        $existingFolder = $this->find_folder_by_name($folderName, $parent);
        if ($existingFolder) {
            return $existingFolder->id;
        }

        // 2. Створюємо нову папку
        try {
            $fileMetadata = new Google\Service\Drive\DriveFile([
                'name' => $folderName,
                'mimeType' => 'application/vnd.google-apps.folder',
                'parents' => [$parent]
            ]);
            
            $folder = $this->service->files->create($fileMetadata, [
                'fields' => 'id'
            ]);
            
            return $folder->id;
        } catch (\Google\Service\Exception $e) {
            // Перехоплюємо API-помилки
            $errorDetails = json_decode($e->getMessage(), true);
            $message = $errorDetails['error']['message'] ?? 'Невідома помилка API Drive.';
            throw new \Exception("Помилка створення папки: $message (Parent ID: $parent)");
        }
    }

    /**
     * Шукає папку за назвою в межах батьківської папки.
     * @param string $folderName Назва папки.
     * @param string $parentId ID батьківської папки.
     * @return \Google\Service\Drive\DriveFile|null
     */
    private function find_folder_by_name($folderName, $parentId) {
        $query = "name = '$folderName' and mimeType = 'application/vnd.google-apps.folder' and '$parentId' in parents and trashed = false";

        $parameters = [
            'q' => $query,
            'spaces' => 'drive',
            'fields' => 'files(id, name)',
        ];

        try {
            $results = $this->service->files->listFiles($parameters);
            if (count($results->getFiles()) > 0) {
                return $results->getFiles()[0]; // Повертаємо першу знайдену
            }
            return null;
        } catch (\Exception $e) {
            // Помилка API при пошуку (часто 404, якщо батьківський ID недійсний/недоступний)
            throw new \Exception("Помилка пошуку папки $folderName у ID $parentId: " . $e->getMessage());
        }
    }

    /**
     * Завантажує файл на Google Drive.
     * @param string $filePath Шлях до тимчасового файлу.
     * @param string $fileName Ім'я файлу.
     * @param string $folderId ID папки, куди завантажувати.
     * @return \Google\Service\Drive\DriveFile Об'єкт завантаженого файлу.
     * @throws \Exception
     */
    public function upload_file($filePath, $fileName, $folderId) {
        try {
            $fileMetadata = new Google\Service\Drive\DriveFile([
                'name' => $fileName,
                'parents' => [$folderId]
            ]);

            $content = file_get_contents($filePath);
            
            $mimeType = mime_content_type($filePath);

            $file = $this->service->files->create($fileMetadata, [
                'data' => $content,
                'mimeType' => $mimeType,
                'uploadType' => 'multipart',
                'fields' => 'id, webViewLink'
            ]);

            return $file;
        } catch (\Exception $e) {
            throw new \Exception("Помилка завантаження файлу $fileName: " . $e->getMessage());
        }
    }
}