<?php
// includes/delivery/ppo-novaposhta-ajax.php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Єдиний обробник для всіх AJAX-запитів Нової Пошти.
 */
function ppo_handle_np_ajax() {
    // Перевірка Nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ppo_np_nonce')) {
        wp_send_json_error(['message' => 'Nonce validation failed.'], 403);
        wp_die();
    }
    
    // Внутрішній action_type визначає, який метод API викликати
    $action_type = sanitize_text_field($_POST['action_type'] ?? ''); 
    $np_api = new PPO_NovaPoshta_API(NP_API_KEY); // Константа з ppo-config.php

    $response_data = [];
    $is_successful = false;

    // --- 1. Пошук населених пунктів (Autocomplete для міст) ---
    if ($action_type === 'searchSettlements') { 
        $city_name_part = sanitize_text_field($_POST['term'] ?? '');
        
        if (!empty($city_name_part)) {
            $api_response = $np_api->searchSettlements($city_name_part); 

            if (isset($api_response['success']) && $api_response['success']) {
                $is_successful = true;
                $results = [];
                
                // searchSettlements повертає data[0]['Addresses']
                $addresses = $api_response['data'][0]['Addresses'] ?? [];

                foreach ($addresses as $settlement) {
                    $results[] = [
                        'label' => $settlement['Present'] ?? $settlement['SettlementDescription'], 
                        // Ref населеного пункту, який ми будемо використовувати для пошуку відділень
                        'value' => $settlement['Ref'], 
                        'city_name' => $settlement['SettlementDescription'],
                    ];
                }
                $response_data = $results;
            } else {
                $response_data = [];
            }
        }

    // --- 2. Пошук відділень (Autocomplete для відділень) ---
    } elseif ($action_type === 'getWarehouses') {
        $city_ref = sanitize_text_field($_POST['city_ref'] ?? '');
        $term = sanitize_text_field($_POST['term'] ?? ''); // Введений текст для фільтрації на клієнті
        
        if (!empty($city_ref)) {
            $api_response = $np_api->getWarehousesByCityRef($city_ref);

            if (isset($api_response['success']) && $api_response['success']) {
                $is_successful = true;
                $results = [];
                
                // Обробка та фільтрація відділень
                foreach ($api_response['data'] as $warehouse) {
                    // Можна додати фільтрацію за введеним 'term'
                    if (empty($term) || stripos($warehouse['Description'], $term) !== false || stripos($warehouse['ShortAddress'], $term) !== false) {
                        $results[] = [
                            'label' => $warehouse['Description'] . ' (' . $warehouse['ShortAddress'] . ')',
                            'value' => $warehouse['Ref'], // Ref відділення
                            'address' => $warehouse['ShortAddress'],
                        ];
                    }
                }
                $response_data = $results;
            } else {
                $response_data = [];
            }
        }
    }

    if ($is_successful) {
        wp_send_json($response_data);
    } else {
        // Якщо API повернуло помилку, або не було результатів
        wp_send_json_error(['message' => 'No data received or API error.', 'details' => $api_response['errors'] ?? []]);
    }

    wp_die();
}