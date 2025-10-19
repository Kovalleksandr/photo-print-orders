<?php
/**
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
    // ОНОВЛЕННЯ 6: order_id відображаємо як placeholder, оскільки він генерується на сервері
    $order_id = isset($_SESSION['ppo_order_id']) ? $_SESSION['ppo_order_id'] : 'Буде згенеровано при завантаженні...';
    $min_order_sum = MIN_ORDER_SUM;
    $photo_prices = PHOTO_PRICES;
    
    ?>
    <style>
        /* Стилі для форми: кнопки, повідомлення, лоадер, контейнери файлів */
        .ppo-button { display: inline-block !important; padding: 8px 16px; margin: 5px; text-decoration: none; border-radius: 3px; font-size: 14px; visibility: visible !important; }
        .ppo-button-primary { background: #0073aa; color: white; }
        .ppo-button-primary:hover { background: #005177; }
        .ppo-button-secondary { background: #f7f7f7; color: #0073aa; border: 1px solid #0073aa; }
        .ppo-button-secondary:hover { background: #e0e0e0; }
        .ppo-total-sum { font-weight: bold; margin: 10px 0; }
        .ppo-buttons-container { margin-top: 15px; }
        .ppo-message { padding: 10px; margin: 10px 0; border-radius: 3px; }
        .ppo-message-success { color: green; background: #e8f5e8; }
        .ppo-message-error { color: red; background: #ffebee; }
        .ppo-message-warning { color: orange; background: #fff3cd; }
        .ppo-loader { display: none; margin-left: 10px; border: 3px solid #f3f3f3; border-top: 3px solid #0073aa; border-radius: 50%; width: 20px; height: 20px; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        
        #photo-quantities { 
            margin-top: 10px; 
            border: 1px solid #ccc; 
            padding: 10px; 
            border-radius: 4px;
            max-height: 400px;
            overflow-y: auto;
            background: #f9f9f9;
        }
        .photo-item {
            border-bottom: 1px solid #eee;
            padding: 8px 0;
            display: flex; 
            align-items: center; 
            justify-content: space-between;
        }
        .photo-item:last-child {
            border-bottom: none;
        }
        .photo-item label {
            font-weight: normal; 
            font-size: 0.9em; 
            display: inline-block; 
            width: 40%; 
            flex-grow: 1; 
            text-overflow: ellipsis; 
            overflow: hidden; 
            white-space: nowrap;
            margin-right: 10px;
        }
        .photo-item input[type="number"] {
            width: 70px; 
            margin: 0 10px; 
            padding: 5px; 
            text-align: center; 
            flex-shrink: 0;
            box-sizing: border-box;
        }
        .photo-thumbnail-container {
            width: 40px; 
            height: 40px; 
            border: 1px solid #ccc; 
            display: inline-flex; 
            margin-right: 10px; 
            flex-shrink: 0; 
            position: relative;
            overflow: hidden;
            align-items: center;
            justify-content: center;
        }
        .photo-thumbnail-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
    </style>

    <div id="ppo-alert-messages">
        <?php if (isset($_GET['error'])) echo '<div class="ppo-message ppo-message-error"><p>' . esc_html(urldecode($_GET['error'])) . '</p></div>'; ?>
        <?php if (isset($_GET['success']) && $_GET['success'] === 'format_added') echo '<div class="ppo-message ppo-message-success"><p>Замовлення збережено! Додайте ще фото або оформіть доставку.</p></div>'; ?>
    </div>
    
    <p>Виберіть формат і до **<?php echo MAX_FILES_PER_UPLOAD; ?>** фото, вкажіть кількість копій (сума ≥<?php echo $min_order_sum; ?> грн), потім натисніть "**Зберегти замовлення**".</p>
    
    <a href="<?php echo esc_url(home_url('/order/?clear_session=1')); ?>" class="ppo-button ppo-button-secondary" style="margin-bottom: 15px;">Очистити всю сесію замовлення</a>
    
    <form id="photo-print-order-form" enctype="multipart/form-data">
        <label for="format">Оберіть формат фото:</label>
        <select name="format" id="format" required style="width: 100%; padding: 10px; margin-bottom: 15px;">
            <option value="">-- виберіть --</option>
            <?php foreach ($photo_prices as $format => $price): ?>
                <option value="<?php echo esc_attr($format); ?>" data-price="<?php echo esc_attr($price); ?>">
                    <?php echo esc_html($format . " см — " . $price . " грн/шт"); ?>
                </option>
            <?php endforeach; ?>
        </select>


        <div id="photo-upload-controls" style="display: none;">
            <label for="photos">Виберіть фото (максимум <?php echo MAX_FILES_PER_UPLOAD; ?>):</label>
            <input type="file" name="photos[]" id="photos" multiple accept="image/jpeg,image/png" style="width: 100%; padding: 10px 0;">
        </div>

        
        <div id="photo-quantities-container" style="display: none;">
            <h4>Кількість копій та видалення</h4>
            <div id="photo-quantities">
                <p style="text-align: center; color: #666;">Виберіть формат та фото для відображення списку.</p>
            </div>
            
            <p id="sum-warning" class="ppo-message ppo-message-warning" style="display: none;">
                Недостатня сума! Додайте більше фото або копій, щоб досягти мінімуму <?php echo $min_order_sum; ?> грн для цього формату.
            </p>

            <p class="ppo-total-sum" id="current-upload-summary-single" style="display: none;">
                Ви вибрали фото на суму: <span id="current-upload-sum">0</span> грн
            </p>
            <p class="ppo-total-sum" id="current-upload-summary-total" style="display: none;">
                Загальна сума для вибраного формату (з поточним): <span id="format-total-sum">0</span> грн (мін. <?php echo $min_order_sum; ?> грн)
            </p>
        </div>

        <div style="display: flex; align-items: center;">
            <button type="submit" name="ppo_submit_order" class="ppo-button ppo-button-primary" id="submit-order" disabled>Зберегти замовлення</button>
            <div id="ppo-loader" class="ppo-loader"></div>
            <button type="button" id="clear-form" class="ppo-button ppo-button-secondary">Очистити</button>
        </div>
    </form>

    <div id="ppo-summary">
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
        <div id="ppo-formats-list-container" style="<?php echo $has_order ? '' : 'display: none;'; ?>">
            <h3>Додані формати:</h3>
            <ul id="ppo-formats-list">
                <?php if ($has_order): ?>
                    <?php 
                    // Відображаємо лише формати, ігноруючи технічні ключі
                    foreach ($session_formats as $key => $details): 
                        if (is_array($details)):
                    ?>
                        <li><?php echo esc_html($key . ': ' . $details['total_copies'] . ' копій, ' . $details['total_price'] . ' грн'); ?></li>
                    <?php 
                        endif; 
                    endforeach; 
                    ?>
                <?php endif; ?>
            </ul>
            <p class="ppo-total-sum">
                Загальна сума замовлення: <span id="ppo-session-total"><?php echo esc_html($session_total_display); ?> грн <small>(Всього копій: <?php echo $total_copies_overall; ?>)</small></span>
            </p>
            <div class="ppo-buttons-container">
                <form method="post" style="display: inline;">
                    <?php wp_nonce_field('ppo_delivery_nonce', 'ppo_nonce'); ?>
                    <input type="submit" name="ppo_go_to_delivery" value="Оформити доставку" class="ppo-button ppo-button-primary">
                </form>
                <a href="<?php echo esc_url(home_url('/order/')); ?>" class="ppo-button ppo-button-secondary">Додати ще фото</a>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}