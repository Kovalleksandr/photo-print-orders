<?php

// Оновлений includes\delivery\api\class-ppo-novaposhta-api.php
// Повністю переписаний на v2.0 API Нової Пошти (JSON POST з apiKey у body)
// Видалено JWT, додана нормалізація даних для сумісності з JS і формою


/**
 * Клас для API Нової Пошти v2.0
 * Використовуємо офіційне JSON API, POST-запити, кешування transients.
 */

if (!defined('ABSPATH')) {
    exit;
}

class PPO_NovaPoshta_API {
    private $api_key;
    private $endpoint = 'https://api.novaposhta.ua/v2.0/json/';

    public function __construct($api_key = '') {
        $this->api_key = $api_key ?: get_option('ppo_np_api_key', NP_API_KEY);  // З конфігу або опції
    }

    /**
     * Базовий POST-запит до API v2.0
     */
    private function request($model_name, $called_method, $method_properties = []) {
        $body = [
            'apiKey' => $this->api_key,
            'modelName' => $model_name,
            'calledMethod' => $called_method,
            'methodProperties' => $method_properties
        ];

        $cache_key = 'np_' . md5(json_encode($body));
        if ($cached = get_transient($cache_key)) {
            return ['success' => true, 'data' => $cached];
        }

        $response = wp_remote_post($this->endpoint, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($body),
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            error_log('NP API Error: ' . $response->get_error_message());
            return ['success' => false, 'error' => $response->get_error_message()];
        }

        $status = wp_remote_retrieve_response_code($response);
        $body_resp = wp_remote_retrieve_body($response);
        $data = json_decode($body_resp, true);

        if ($status !== 200 || !$data['success']) {
            $error = implode('; ', $data['errors'] ?? ['HTTP ' . $status . ': Помилка API']);
            error_log('NP API Error: ' . $error);
            return ['success' => false, 'error' => $error];
        }

        set_transient($cache_key, $data['data'], HOUR_IN_SECONDS);
        return ['success' => true, 'data' => $data['data']];
    }

    /**
     * Пошук населених пунктів (searchSettlements)
     * Нормалізуємо до формату: Description, Ref (використовуємо DeliveryCity як Ref для доставки)
     */
    public function search_settlements($query, $limit = 20) {
        $props = [
            'Page' => 1,
            'Limit' => $limit,
            'FindByString' => $query,
            'Warehouse' => 1  // Тільки з відділеннями
        ];
        $result = $this->request('Address', 'searchSettlements', $props);

        if (!$result['success']) {
            return $result;
        }

        $normalized = [];
        foreach ($result['data'][0]['Addresses'] ?? [] as $item) {
            $normalized[] = [
                'Description' => $item['Present'],  // Повна назва з областю
                'Ref' => $item['DeliveryCity'] ?: $item['Ref']  // Ref для доставки (CityRef)
            ];
        }
        $result['data'] = $normalized;
        return $result;
    }

    /**
     * Пошук вулиць у населеному пункті (searchSettlementStreets)
     * Нормалізуємо до Description, Ref
     */
    public function search_streets($settlement_ref, $query, $limit = 20) {
        $props = [
            'SettlementRef' => $settlement_ref,
            'FindByString' => $query,
            'Limit' => $limit
        ];
        $result = $this->request('Address', 'searchSettlementStreets', $props);

        if (!$result['success']) {
            return $result;
        }

        $normalized = [];
        foreach ($result['data'][0]['Addresses'] ?? [] as $item) {
            $normalized[] = [
                'Description' => $item['Present'],
                'Ref' => $item['Ref']
            ];
        }
        $result['data'] = $normalized;
        return $result;
    }

    /**
     * Отримання відділень (getWarehouses)
     * Нормалізуємо до number (SiteKey), Description (name), address (ShortAddress), Ref
     */
    public function get_divisions($settlement_ref, $limit = 100, $category = 'PostBranch') {
        $props = [
            'CityRef' => $settlement_ref,
            'Page' => 1,
            'Limit' => $limit
            // Якщо потрібно фільтр за типом: 'TypeOfWarehouseRef' => '841339c7-591a-42e2-8234-7a0a00f0ed6f' для вантажних тощо
        ];
        $result = $this->request('AddressGeneral', 'getWarehouses', $props);

        if (!$result['success']) {
            return $result;
        }

        $normalized = [];
        foreach ($result['data'] as $item) {
            $normalized[] = [
                'id' => $item['SiteKey'],
                'number' => $item['SiteKey'],
                'Description' => $item['Description'],
                'name' => $item['Description'],  // Для сумісності
                'address' => $item['ShortAddress'],
                'Ref' => $item['Ref']
            ];
        }
        $result['data'] = $normalized;
        return $result;
    }

    /**
     * Розрахунок вартості доставки (getDocumentPrice)
     */
    public function calculate_delivery_cost($city_ref, $warehouse_ref, $weight = 0.5, $volume = '0.001') {
        $props = [
            'CitySender' => NP_SENDER_CITY_REF,
            'CityRecipient' => $city_ref,
            'Weight' => $weight,
            'ServiceType' => 'WarehouseWarehouse',  // Відділення-відділення
            'CargoType' => 'Parcel',  // Посилка (для фото)
            'Cost' => 0,  // Оціночна вартість (страхування)
            'SeatsAmount' => 1
            // Volume не безпосередньо, але можна додати якщо потрібно
        ];
        $result = $this->request('InternetDocument', 'getDocumentPrice', $props);

        if (!$result['success']) {
            return $result;
        }

        $cost = $result['data'][0]['Cost'] ?? 0;
        $result['data'] = ['cost' => $cost];
        return $result;
    }
}
?>