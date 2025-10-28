<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/api/class-ppo-novaposhta-api.php';

add_action('wp_ajax_ppo_np_search_settlements', 'ppo_handle_np_ajax');
add_action('wp_ajax_nopriv_ppo_np_search_settlements', 'ppo_handle_np_ajax');
add_action('wp_ajax_ppo_np_search_streets', 'ppo_handle_np_ajax');
add_action('wp_ajax_nopriv_ppo_np_search_streets', 'ppo_handle_np_ajax');
add_action('wp_ajax_ppo_np_get_divisions', 'ppo_handle_np_ajax');
add_action('wp_ajax_nopriv_ppo_np_get_divisions', 'ppo_handle_np_ajax');

function ppo_handle_np_ajax() {
    if (!wp_verify_nonce($_POST['nonce'], 'ppo_np_nonce')) {
        wp_send_json_error('Помилка безпеки');
    }

    error_log('NP AJAX: Before require');
    require_once __DIR__ . '/api/class-ppo-novaposhta-api.php';
    error_log('NP AJAX: After require');
    if (class_exists('PPO_NovaPoshta_API')) {
        error_log('NP AJAX: Class exists');
    } else {
        error_log('NP AJAX: Class NOT exists');
    }


    $api = new PPO_NovaPoshta_API();
    $action = sanitize_text_field($_POST['action']);

    error_log('NP Handler: Action = ' . $action);

    switch ($action) {
        case 'ppo_np_search_settlements':
            $query = sanitize_text_field($_POST['query']);
            if (method_exists($api, 'search_settlements')) {
                error_log('NP Method search_settlements exists');
            } else {
                error_log('NP Method search_settlements NOT exists');
            }
            $result = $api->search_settlements($query);
            wp_send_json($result);
            break;
        case 'ppo_np_search_streets':
            $settlement_ref = sanitize_text_field($_POST['settlement_ref']);
            $query = sanitize_text_field($_POST['query']);
            $result = $api->search_streets($settlement_ref, $query);
            wp_send_json($result);
            break;
        case 'ppo_np_get_divisions':
            $settlement_ref = sanitize_text_field($_POST['settlement_ref']);
            $category = sanitize_text_field($_POST['category'] ?? 'PostBranch');
            $result = $api->get_divisions($settlement_ref, 100, $category);
            wp_send_json($result);
            break;
        default:
            wp_send_json_error('Невірна дія');
    }
}
?>