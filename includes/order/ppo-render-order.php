<?php
/** includes\order\ppo-render-order.php
 * Функція рендерингу шорткоду [ppo_order_form] (Крок 1: Замовлення та завантаження).
 */

if (!defined('ABSPATH')) {
    exit;
}

// ====================================================================
// 8. ФУНКЦІЇ РЕНДЕРУ ШОРТКОДІВ
// ====================================================================
function ppo_render_order_form() {
    ob_start();
    // order_id відображаємо як placeholder, оскільки він генерується на сервері
    $order_id = isset($_SESSION['ppo_order_id']) ? $_SESSION['ppo_order_id'] : 'Буде згенеровано при завантаженні...';
    $min_order_sum = MIN_ORDER_SUM;
    $photo_prices = PHOTO_PRICES;
    
    // !!! ФУНКЦІЯ: Збираємо поточні опції сесії для відображення в підсумках
    function get_option_label($key) {
        $map = [
            'gloss' => 'Глянець',
            'matte' => 'Матовий',
            'frameoff' => 'Без рамки',
            'frameon' => 'З рамкою',
        ];
        return $map[$key] ?? $key;
    }
    
    ?>
    <div class="ppo-order-form-container">
        
        <div id="ppo-alert-messages">
            <?php if (isset($_GET['error'])): ?>
                <div class="ppo-message ppo-message-error"><p><?php echo esc_html(urldecode($_GET['error'])); ?></p></div>
            <?php endif; ?>
            <?php if (isset($_GET['success']) && $_GET['success'] === 'format_added'): ?>
                <div class="ppo-message ppo-message-success"><p>Замовлення збережено! Додайте ще фото або оформіть доставку.</p></div>
            <?php endif; ?>
        </div>

        <div id="ppo-success-modal" class="ppo-modal" style="display: none;">
            <div class="ppo-modal-content">
                <div class="ppo-modal-header">
                    <h2>Успіх!</h2>
                    <span class="ppo-modal-close">&times;</span>
                </div>
                <div class="ppo-modal-body">
                    <p id="ppo-modal-message"></p>
                </div>
                <div class="ppo-modal-footer">
                    <button id="ppo-modal-ok" class="ppo-button ppo-button-primary">OK</button>
                </div>
            </div>
        </div>
        
        
        <p>Мінімальна сума замовлення для одного формату <?php echo $min_order_sum; ?> грн.</p>
        <p>Завантажуйте по <?php echo MAX_FILES_PER_UPLOAD; ?> фото.</p>
        
        <form id="photo-print-order-form" enctype="multipart/form-data">
            <input 
                type="file" 
                id="ppo-hidden-file-input" 
                name="ppo_file_upload[]" 
                multiple 
                accept="image/jpeg,image/png" 
                class="ppo-hidden-file-input"
            >
            
            <div id="ppo-step-1" class="ppo-step-block"> 
                
                <h3>1. Опції друку та формат</h3>

                <div id="ppo-format-options"> 
                
                    <div class="ppo-option-group">
                        <label>Оберіть тип паперу:</label><br>
                        <input type="radio" id="finish-gloss" name="ppo_finish_option" value="gloss" checked>
                        <label for="finish-gloss">Глянцевий (Gloss)</label>
                        
                        <input type="radio" id="finish-matte" name="ppo_finish_option" value="matte">
                        <label for="finish-matte">Матовий (Matte)</label>
                    </div>
                    
                    <div class="ppo-option-group">
                        <label>Оберіть наявність рамки:</label><br>
                        <input type="radio" id="frame-off" name="ppo_frame_option" value="frameoff" checked>
                        <label for="frame-off">Без рамки</label>
                        
                        <input type="radio" id="frame-on" name="ppo_frame_option" value="frameon">
                        <label for="frame-on">З рамкою</label>
                    </div>
                    
                    <div class="ppo-option-group ppo-format-select-group" style="border-bottom: none; margin-bottom: 0; padding-bottom: 0;">
                        <label for="format">Оберіть формат фото:</label>
                        <select name="format" id="format" required class="ppo-format-select">
                            <option value="">-- виберіть --</option>
                            <?php foreach ($photo_prices as $format => $price): ?>
                                <option value="<?php echo esc_attr($format); ?>" data-price="<?php echo esc_attr($price); ?>">
                                    <?php echo esc_html($format . " см — " . $price . " грн/шт"); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                </div>
            </div>
            
            <div id="ppo-step-2" class="ppo-step-block">
                
                <h3>2. Завантаження та копії</h3>

                <div id="photo-quantities-container" class="ppo-quantities-container">
                    <h4>Кількість копій та видалення</h4>
                    <div id="photo-quantities" class="ppo-photo-quantities">
                        <p id="ppo-add-photos-link" class="ppo-add-photos-link">
                            Натисніть тут, щоб додати фото (максимум <?php echo MAX_FILES_PER_UPLOAD; ?>)
                        </p>
                    </div>
                    
                    <p id="sum-warning" class="ppo-message ppo-message-warning ppo-sum-warning">
                        Недостатня сума! Додайте більше фото або копій, щоб досягти мінімуму <?php echo $min_order_sum; ?> грн для цього формату.
                    </p>

                    <p class="ppo-total-sum ppo-current-upload-summary-single">
                        Ви вибрали фото на суму: <span id="current-upload-sum">0</span> грн
                    </p>
                    <p class="ppo-total-sum ppo-current-upload-summary-total">
                        Загальна сума для вибраного формату (з поточним): <span id="format-total-sum">0</span> грн (мін. <?php echo $min_order_sum; ?> грн)
                    </p>

                    <div class="ppo-buttons-in-quantities">
                        <button type="submit" name="ppo_submit_order" class="ppo-button ppo-button-primary" id="submit-order" disabled>Зберегти замовлення</button>
                        <div id="ppo-loader" class="ppo-loader"></div>
                        
                        <div id="ppo-progress-container" class="ppo-progress-container" style="display: none; margin: 10px 0;">
                            <div id="ppo-progress-bar" class="ppo-progress-bar">
                                <div id="ppo-progress-fill" class="ppo-progress-fill"></div>
                            </div>
                            <span id="ppo-progress-text" class="ppo-progress-text">0%</span>
                        </div>
                        
                        <button type="button" id="clear-form" class="ppo-button ppo-button-secondary">Очистити</button>
                    </div>
                </div>
            </div>
        </form>

        <div id="ppo-summary" class="ppo-step-block ppo-summary-block">
            <?php 
            $session_formats = array_filter($_SESSION['ppo_formats'] ?? [], 'is_array');
            $has_order = !empty($session_formats);
            $total_copies_overall = 0;
            $session_total_display = $_SESSION['ppo_total'] ?? 0;
            if ($has_order) {
                // Фільтруємо системні ключі, такі як order_folder_path
                $display_formats = array_filter($session_formats, 'is_array');
                $total_copies_overall = array_sum(array_column($display_formats, 'total_copies'));
            }
            ?>
            <h3>Деталі замовлення:</h3>

            <div id="ppo-formats-list-container" class="ppo-formats-list-container" style="<?php echo $has_order ? '' : 'display: none;'; ?>">
                <ul id="ppo-formats-list" class="ppo-formats-list">
                    <?php if ($has_order): ?>
                        <?php 
                        // Відображаємо лише формати, ігноруючи технічні ключі
                        foreach ($session_formats as $key => $details): 
                            if (is_array($details)):
                                // !!! ЗМІНА: Розбираємо ключ для коректного відображення опцій
                                $key_parts = explode('_', $key, 3);
                                $format_name = $key_parts[0] ?? $key;
                                $finish_label = get_option_label($key_parts[1] ?? '');
                                $frame_label = get_option_label($key_parts[2] ?? '');
                                $display_key = $format_name;
                                if ($finish_label || $frame_label) {
                                    $display_key .= ' (' . trim("{$finish_label}, {$frame_label}", ', ') . ')';
                                }
                        ?>
                                <li><?php echo esc_html($display_key . ': ' . $details['total_copies'] . ' копій, ' . number_format($details['total_price'], 2, '.', '') . ' грн'); ?></li>
                        <?php 
                            endif; 
                        endforeach; 
                        ?>
                    <?php endif; ?>
                </ul>
                <p class="ppo-total-sum">
                    Загальна сума замовлення: <span id="ppo-session-total"><?php echo esc_html(number_format($session_total_display, 2, '.', '')); ?> грн <small>(Всього копій: <?php echo esc_html($total_copies_overall); ?>)</small></span>
                </p>
                <div class="ppo-buttons-container">
                    <form method="post" style="display: inline;">
                        <?php wp_nonce_field('ppo_delivery_nonce', 'ppo_nonce'); ?>
                        <input type="submit" name="ppo_go_to_delivery" value="Оформіть доставку" class="ppo-button ppo-button-primary">
                    </form>
                    <a href="<?php echo esc_url(home_url('/order/?clear_session=1')); ?>" class="ppo-button ppo-button-secondary ppo-clear-session-link">Видалити замовлення</a>
                </div>
            </div>
        </div>
    </div>

    <?php
    
    return ob_get_clean();
}