jQuery(document).ready(function($) {

    // Отримання даних з об'єкта локалізації WP
    const ajaxUrl = ppo_ajax_object.ajax_url;
    const nonce = ppo_ajax_object.nonce;
    const minSum = ppo_ajax_object.min_sum;
    const prices = ppo_ajax_object.prices;
    const redirectDelivery = ppo_ajax_object.redirect_delivery;

    // Зберігаємо формати та загальну суму в JS для швидкого оновлення інтерфейсу
    let sessionFormats = ppo_ajax_object.session_formats;
    let sessionTotal = parseFloat(ppo_ajax_object.session_total);
    
    // --- Елементи DOM ---
    const $form = $('#photo-print-order-form');
    const $formatSelect = $('#format');
    const $photosInput = $('#photos');
    const $quantitiesContainer = $('#photo-quantities');
    const $currentUploadSum = $('#current-upload-sum');
    const $formatTotalSum = $('#format-total-sum');
    const $sumWarning = $('#sum-warning');
    const $submitButton = $('#submit-order');
    const $loader = $('#ppo-loader');
    const $messages = $('#ppo-alert-messages');
    const $clearFormButton = $('#clear-form');

    // --- Допоміжні функції ---

    /**
     * Очищає контейнер повідомлень
     */
    function clearMessages() {
        $messages.empty();
    }

    /**
     * Відображає повідомлення користувачеві
     * @param {string} message - Текст повідомлення
     * @param {string} type - 'success', 'error', 'warning'
     */
    function displayMessage(message, type) {
        clearMessages();
        const $alert = $('<div>')
            .addClass('ppo-message ppo-message-' + type)
            .html('<p>' + message + '</p>');
        $messages.append($alert);
    }
    
    /**
     * Перераховує загальну суму для поточного формату та оновлює DOM
     */
    function updateCurrentUploadSummary() {
        const selectedFormat = $formatSelect.val();
        if (!selectedFormat) return;

        const pricePerPhoto = parseFloat(prices[selectedFormat] || 0);
        let currentUploadTotalCopies = 0;
        let currentUploadTotalPrice = 0;

        // Збираємо дані про копії з динамічних полів
        $quantitiesContainer.find('input[type="number"]').each(function() {
            const copies = parseInt($(this).val()) || 1;
            currentUploadTotalCopies += copies;
            currentUploadTotalPrice += copies * pricePerPhoto;
        });

        // Загальна сума формату (поточна сесія + нове завантаження)
        const sessionFormatDetails = sessionFormats[selectedFormat] || { total_price: 0 };
        const totalSumForFormat = sessionFormatDetails.total_price + currentUploadTotalPrice;
        
        // Оновлення відображення
        $currentUploadSum.text(currentUploadTotalPrice.toFixed(0));
        $formatTotalSum.text(totalSumForFormat.toFixed(0));

        // Перевірка мінімальної суми та керування кнопкою
        if (totalSumForFormat < minSum) {
            $sumWarning.show();
            $submitButton.prop('disabled', true);
        } else {
            $sumWarning.hide();
            $submitButton.prop('disabled', false);
        }

        // Керування кнопкою
        if (currentUploadTotalCopies === 0) {
            $submitButton.prop('disabled', true);
        } else if (totalSumForFormat >= minSum) {
             $submitButton.prop('disabled', false);
        }
    }

    /**
     * Рендерить список обраних файлів з полями для копій
     * @param {FileList} fileList - Список файлів, обраних у формі
     */
    function renderFileQuantities(fileList) {
        $quantitiesContainer.empty();
        
        if (fileList.length === 0) {
            $quantitiesContainer.html('<p style="text-align: center; color: #666;">Не вибрано жодного файлу.</p>');
            return;
        }

        $.each(fileList, function(i, file) {
            const $item = $('<div class="photo-item">');
            
            // Контейнер для мініатюри (якщо можливо)
            const $thumbContainer = $('<div class="photo-thumbnail-container">');
            if (file.type.startsWith('image/')) {
                 const reader = new FileReader();
                 reader.onload = function(e) {
                      $thumbContainer.html('<img src="' + e.target.result + '" alt="Мініатюра">');
                 };
                 reader.readAsDataURL(file);
            } else {
                 $thumbContainer.text('📄'); // Іконка за замовчуванням
            }
            $item.append($thumbContainer);

            // Назва файлу
            const $label = $('<label>')
                .attr('for', 'copies_' + i)
                .text(file.name);

            // Поле для кількості копій
            const $input = $('<input>')
                .attr({
                    type: 'number',
                    name: 'copies[]',
                    id: 'copies_' + i,
                    value: 1,
                    min: 1
                })
                .on('input change', updateCurrentUploadSummary);
            
            // Кнопка видалення (видаляє файл з fileList, перерендерить)
            const $removeButton = $('<button type="button" class="remove-file-btn" style="background:none; border:none; color:red; cursor:pointer;">&times;</button>')
                .data('file-index', i)
                .on('click', function() {
                    removeFileFromList($photosInput[0], i);
                });
            
            $item.append($label, $input, $removeButton);
            $quantitiesContainer.append($item);
        });

        updateCurrentUploadSummary();
    }
    
    /**
     * Видаляє файл зі списку file input
     * @param {HTMLInputElement} input - Елемент input type="file"
     * @param {number} indexToRemove - Індекс файлу для видалення
     */
    function removeFileFromList(input, indexToRemove) {
        const dt = new DataTransfer();
        const files = input.files;
        
        for (let i = 0; i < files.length; i++) {
            if (i !== indexToRemove) {
                dt.items.add(files[i]);
            }
        }
        input.files = dt.files; // Оновлюємо FileList
        
        // Перерендеринг списку копій
        renderFileQuantities(input.files);
    }

    /**
     * Оновлює підсумкову таблицю замовлення
     */
    function updateSummaryList() {
        const $list = $('#ppo-formats-list');
        $list.empty();
        
        let totalCopiesOverall = 0;

        for (const format in sessionFormats) {
            if (format === 'order_folder_id' || !sessionFormats.hasOwnProperty(format)) continue;
            
            const details = sessionFormats[format];
            const $listItem = $('<li>')
                .text(`${format}: ${details.total_copies} копій, ${details.total_price.toFixed(0)} грн`);
            $list.append($listItem);
            totalCopiesOverall += details.total_copies;
        }

        $('#ppo-session-total').html(`${sessionTotal.toFixed(0)} грн <small>(Всього копій: ${totalCopiesOverall})</small>`);
        $('#ppo-formats-list-container').show();
    }
    
    // --- Обробники подій ---

    // 1. При виборі формату (очищаємо поле файлів та оновлюємо підсумок)
    $formatSelect.on('change', function() {
        $photosInput.val(''); // Очищаємо вибрані файли
        $quantitiesContainer.html('<p style="text-align: center; color: #666;">Виберіть фото для цього формату.</p>');
        updateCurrentUploadSummary();
    });

    // 2. При виборі файлів (рендеримо поля копій)
    $photosInput.on('change', function() {
        const selectedFormat = $formatSelect.val();
        const files = this.files;

        clearMessages();

        if (!selectedFormat) {
            displayMessage('Будь ласка, спочатку оберіть формат фото.', 'warning');
            this.value = null; // Очищуємо поле
            return;
        }
        if (files.length > 20) {
            displayMessage('Максимум 20 файлів дозволено за одне завантаження.', 'error');
            this.value = null; 
            return;
        }
        
        // Рендеримо новий список
        renderFileQuantities(files);
    });
    
    // 3. Обробка натискання кнопки "Очистити"
    $clearFormButton.on('click', function(e) {
         e.preventDefault();
         $photosInput.val(''); // Очистити поле вибору файлів
         $formatSelect.val(''); // Очистити вибір формату
         $quantitiesContainer.html('<p style="text-align: center; color: #666;">Виберіть формат та фото для відображення списку.</p>');
         $sumWarning.hide();
         $submitButton.prop('disabled', true);
         updateCurrentUploadSummary();
         clearMessages();
    });


    // 4. Обробка відправки форми (AJAX)
    $form.on('submit', function(e) {
        e.preventDefault();

        const selectedFormat = $formatSelect.val();
        if (!$photosInput[0].files.length) {
             displayMessage('Будь ласка, додайте фото для завантаження.', 'error');
             return;
        }

        $loader.show();
        $submitButton.prop('disabled', true);
        clearMessages();

        // Збираємо дані форми
        const formData = new FormData(this);
        formData.append('action', 'ppo_file_upload');
        formData.append('ppo_ajax_nonce', nonce);
        
        // Збираємо копії окремим масивом (важливо для коректної передачі)
        const copiesArray = [];
        $quantitiesContainer.find('input[type="number"]').each(function() {
             copiesArray.push($(this).val());
        });
        // Передаємо JSON-рядок копій
        formData.append('copies', JSON.stringify(copiesArray)); 
        
        // Видаляємо дублююче поле name='copies[]'
        formData.delete('copies[]');
        
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                $loader.hide();
                $photosInput.val(''); // Очищуємо поле вводу файлів
                $quantitiesContainer.empty();
                $formatSelect.val(''); // Очищуємо вибір формату
                
                if (response.success) {
                    displayMessage(response.data.message, 'success');
                    
                    // Оновлення глобальної сесії JS
                    sessionFormats = response.data.formats;
                    sessionTotal = parseFloat(response.data.total);
                    
                    updateSummaryList(); // Оновлюємо підсумок замовлення
                } else {
                    displayMessage(response.data.message, 'error');
                    $submitButton.prop('disabled', false); // Повертаємо можливість відправки
                }
            },
            error: function(xhr, status, error) {
                $loader.hide();
                const errorMessage = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message 
                                   ? xhr.responseJSON.data.message 
                                   : 'Помилка завантаження. Перевірте консоль.';
                displayMessage(errorMessage, 'error');
                $submitButton.prop('disabled', false);
            }
        });
    });

    // 5. Ініціалізація: оновлення підсумкової суми при завантаженні сторінки
    updateSummaryList();
    updateCurrentUploadSummary(); // На випадок, якщо потрібен рендер на старті

});