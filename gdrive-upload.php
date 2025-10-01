<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';

function getClient()
{
    $client = new Google_Client();
    $client->setAuthConfig(plugin_dir_path(__FILE__) . 'photo-print-orders-910e7da304e1.json');
    $client->addScope(Google_Service_Drive::DRIVE_FILE);
    $client->setAccessType('offline');
    return $client;
}

/**
 * Створює папку на Google Drive
 *
 * @param string $folderName
 * @param string $parentId
 * @return string ID створеної папки
 */
function createDriveFolder($folderName, $parentId = null)
{
    $client = getClient();
    $service = new Google_Service_Drive($client);

    // Перевіряємо чи існує така папка вже
    $query = sprintf(
        "mimeType='application/vnd.google-apps.folder' and name='%s' and '%s' in parents and trashed=false",
        addslashes($folderName),
        $parentId ? $parentId : ROOT_FOLDER_ID
    );
    $results = $service->files->listFiles(['q' => $query]);

    if (count($results->getFiles()) > 0) {
        return $results->getFiles()[0]->getId(); // якщо є — повертаємо існуючу
    }

    // Інакше створюємо нову
    $fileMetadata = new Google_Service_Drive_DriveFile([
        'name' => $folderName,
        'mimeType' => 'application/vnd.google-apps.folder',
        'parents' => [$parentId ? $parentId : ROOT_FOLDER_ID]
    ]);

    $folder = $service->files->create($fileMetadata, [
        'fields' => 'id'
    ]);

    return $folder->id;
}

/**
 * Завантажує файл у Google Drive
 *
 * @param string $name
 * @param string $path
 * @param string $parentId
 * @return string ID завантаженого файлу
 */
function uploadFileToDrive($name, $path, $parentId)
{
    $client = getClient();
    $service = new Google_Service_Drive($client);

    $fileMetadata = new Google_Service_Drive_DriveFile([
        'name' => $name,
        'parents' => [$parentId]
    ]);

    $content = file_get_contents($path);

    $file = $service->files->create($fileMetadata, [
        'data' => $content,
        'mimeType' => mime_content_type($path),
        'uploadType' => 'multipart',
        'fields' => 'id'
    ]);

    return $file->id;
}
