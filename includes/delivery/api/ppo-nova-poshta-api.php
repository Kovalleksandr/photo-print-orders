<?php
// includes/delivery/api/ppo-nova-poshta-api.php

if (!defined('ABSPATH')) {
    exit;
}

class PPO_NovaPoshta_API {
    private $api_key;
    private $api_url = 'https://api.novaposhta.ua/v2.0/json/';

    public function __construct($api_key) {
        $this->api_key = $api_key;
    }

    /**
     * Надсилає запит до API Нової Пошти.
     * @param array $request_data Дані запиту.
     * @return array Результат запиту.
     */
    private function sendRequest($request_data) {
        $args = [
            'body'    => json_encode($request_data),
            'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
            'timeout' => 45,
        ];

        $response = wp_remote_post($this->api_url, $args);

        if (is_wp_error($response)) {
            return ['success' => false, 'errors' => ['WP Error: ' . $response->get_error_message()]];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
             return ['success' => false, 'errors' => ['JSON decode error. Response: ' . $body]];
        }
        
        return $data;
    }

    /**
     * Шукає населені пункти (міста) за введеним рядком для Autocomplete.
     * ВИПРАВЛЕНО: Використовуємо Address/searchSettlements.
     * * @param string $city_name Частина назви міста для пошуку.
     * @return array
     */
    public function searchSettlements($city_name) {
        $properties = [
            'CityName' => $city_name,
            'Limit' => 10, 
            'Page' => 1
        ];
        
        $request = [
            'apiKey'       => $this->api_key,
            'modelName'    => 'Address', 
            'calledMethod' => 'searchSettlements',
            'methodProperties' => $properties
        ];
        
        return $this->sendRequest($request);
    }
    
    /**
     * Шукає відділення (Warehouses) за CityRef (Ref населеного пункту).
     * @param string $city_ref Ref міста.
     * @return array
     */
    public function getWarehousesByCityRef($settlement_ref) {
        // Властивості містять лише Ref міста
        $properties = [
            'SettlementRef' => $settlement_ref,

        ];
        
        $request = [
            'apiKey'       => $this->api_key,
            'modelName'    => 'AddressGeneral', 
            'calledMethod' => 'getWarehouses',
            'methodProperties' => $properties
        ];
        
        return $this->sendRequest($request);
    }
    // Тут можна додати інші методи API, наприклад, для розрахунку вартості доставки.
}