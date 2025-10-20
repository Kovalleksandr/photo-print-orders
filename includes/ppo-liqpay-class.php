<?php
/**
 * Клас-обгортка для роботи з LiqPay (Client-Server інтеграція)
 */

if (!defined('ABSPATH')) {
    exit;
}

class PPO_LiqPay {
    
    protected $public_key;
    protected $private_key;

    public function __construct($public_key, $private_key) {
        $this->public_key = $public_key;
        $this->private_key = $private_key;
    }

    /**
     * Генерує підпис (Signature) для даних LiqPay
     * @param string $data Закодована base64 JSON-стрічка з параметрами
     * @return string Підпис у форматі base64
     */
    protected function cnb_signature($data) {
        // Підпис = base64_encode( sha1( private_key + data + private_key ) )
        return base64_encode(sha1($this->private_key . $data . $this->private_key, 1));
    }

    /**
     * Генерує HTML-форму для переходу на сторінку оплати LiqPay (Client-Server)
     * @param array $params Параметри платежу (amount, order_id, description, currency, тощо)
     * @return string HTML-форма для POST-запиту
     */
    public function cnb_form($params) {
        // Додаємо обов'язкові параметри
        $params['public_key'] = $this->public_key;
        $params['version']    = 3; // Використовуємо версію API 3

        // Кодуємо JSON
        $json_data = json_encode($params);
        $data      = base64_encode($json_data);
        
        // Генеруємо підпис
        $signature = $this->cnb_signature($data);

        // Формуємо HTML
        $html = '<form method="POST" action="https://www.liqpay.ua/api/3/checkout" accept-charset="utf-8">';
        $html .= '<input type="hidden" name="data" value="' . esc_attr($data) . '" />';
        $html .= '<input type="hidden" name="signature" value="' . esc_attr($signature) . '" />';
        $html .= '<input type="submit" class="ppo-button ppo-button-primary" value="Оплатити LiqPay" style="width: 100%;">';
        $html .= '</form>';

        return $html;
    }
}