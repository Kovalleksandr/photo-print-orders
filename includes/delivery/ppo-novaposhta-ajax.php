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
    
    // !!! УВАГА: Ми використовуємо NP_API_KEY, як визначено у вашому ppo-config.php
    $action_type = sanitize_text_field($_POST['action_type'] ?? ''); 
    $np_api = new PPO_NovaPoshta_API(NP_API_KEY); 

    $response_data = [];
    $is_successful = false;
    $api_response = []; // Ініціалізуємо змінну для зберігання відповіді API

    // --- 1. Пошук населених пунктів (Autocomplete для міст) ---
    if ($action_type === 'searchSettlements') { 
        $city_name_part = sanitize_text_field($_POST['term'] ?? '');
        
        if (!empty($city_name_part)) {
            $api_response = $np_api->searchSettlements($city_name_part); 

            if (isset($api_response['success']) && $api_response['success']) {
                $is_successful = true;
                $results = [];
                
                // searchSettlements повертає data[0]['Addresses']
                $addresses_container = $api_response['data'][0]['Addresses'] ?? [];

                foreach ($addresses_container as $settlement) {
                    $city_name_full = $settlement['Present'] ?? $settlement['MainDescription']; // Наприклад, "м. Київ, Київська обл."
                    $city_ref_value = $settlement['Ref'] ?? ''; // Реф міста (це SettlementRef)
                    
                    if (!empty($city_ref_value)) {
                        $results[] = [
                            'label' => $city_name_full, 
                            'value' => $city_ref_value, 
                            // Значення, яке буде вставлено у видиме поле
                            'city_name' => $settlement['MainDescription'] ?? $settlement['Present'], 
                        ];
                    }
                }
                $response_data = $results;
            } else {
                $response_data = [];
            }
        }

    // --- 2. Пошук відділень (Autocomplete для відділень) ---
    } elseif ($action_type === 'getWarehouses') {
        $city_ref = sanitize_text_field($_POST['city_ref'] ?? ''); // Тут приходить SettlementRef
        $term = sanitize_text_field($_POST['term'] ?? ''); 
        
        if (!empty($city_ref)) {
            // Тепер getWarehousesByCityRef використовує SettlementRef у запиті
            $api_response = $np_api->getWarehousesByCityRef($city_ref);

            if (isset($api_response['success']) && $api_response['success']) {
                $is_successful = true;
                $results = [];
                
                foreach ($api_response['data'] as $warehouse) {
                    // Використовуємо регіональні поля для кращого пошуку
                    $description = $warehouse['Description'] ?? $warehouse['DescriptionRu'];
                    $short_address = $warehouse['ShortAddress'] ?? $warehouse['ShortAddressRu'];
                    
                    if (empty($term) || stripos($description, $term) !== false || stripos($short_address, $term) !== false) {
                        $results[] = [
                            'label' => $description . ' (' . $short_address . ')',
                            'value' => $warehouse['Ref'], // Ref відділення
                            'address' => $short_address,
                        ];
                    }
                }
                $response_data = $results;
            } else {
                $response_data = $api_response;
            }
        }
    }

    if ($is_successful) {
        wp_send_json($response_data);
    } else {
        // Відправляємо деталі помилки API Нової Пошти назад у консоль для діагностики
        $error_details = $api_response['errors'] ?? ['No data received or API error (details unavailable).'];

        wp_send_json_error([
            'message' => 'Nova Poshta API Error',
            'details' => $error_details 
        ]);
    }

    wp_die();
}