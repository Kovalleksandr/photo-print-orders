jQuery(document).ready(function($) {

    // Отримання даних з об'єкта локалізації WP
    const ajaxUrl = ppo_ajax_object.ajax_url;
    const nonce = ppo_ajax_object.nonce;
    const minSum = ppo_ajax_object.min_sum;
    const prices = ppo_ajax_object.prices;
    const redirectDelivery = ppo_ajax_object.redirect_delivery;
    const maxFilesPerUpload = ppo_ajax_object.max_files; 

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
    
    // НОВІ ЕЛЕМЕНТИ ПІДСУМКІВ
    const $currentUploadSummarySingle = $('#current-upload-summary-single');
    const $currentUploadSummaryTotal = $('#current-upload-summary-total');

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
        
        // Приховуємо підсумки та попередження, якщо формат не обрано
        if (!selectedFormat) {
            $currentUploadSummarySingle.hide();
            $currentUploadSummaryTotal.hide();
            $sumWarning.hide();
            $submitButton.prop('disabled', true);
            return;
        }

        const pricePerPhoto = parseFloat(prices[selectedFormat] || 0);
        let currentUploadTotalCopies = 0;
        let currentUploadTotalPrice = 0;
        let currentUploadTotalFiles = 0; 

        // Збираємо дані про копії з динамічних полів
        $quantitiesContainer.find('input[type="number"]').each(function() {
            const copies = parseInt($(this).val()) || 1;
            currentUploadTotalCopies += copies;
            currentUploadTotalPrice += copies * pricePerPhoto;
            currentUploadTotalFiles++; 
        });

        // Загальна сума формату (поточна сесія + нове завантаження)
        const sessionFormatDetails = sessionFormats[selectedFormat] || { total_price: 0 };
        const totalSumForFormat = sessionFormatDetails.total_price + currentUploadTotalPrice;
        
        // Чи є вже збережені файли для цього формату?
        const hasExistingUploads = sessionFormatDetails.total_price > 0;

        // Оновлення відображення
        $currentUploadSum.text(currentUploadTotalPrice.toFixed(0));
        $formatTotalSum.text(totalSumForFormat.toFixed(0));

        // 1. ЛОГІКА ВІДОБРАЖЕННЯ ПІДСУМКІВ
        if (currentUploadTotalFiles > 0) {
            if (hasExistingUploads) {
                // Вже є збережені файли: показуємо загальний підсумок
                $currentUploadSummarySingle.hide();
                $currentUploadSummaryTotal.show();
            } else {
                // Перше завантаження: показуємо лише поточний підсумок
                $currentUploadSummaryTotal.hide();
                $currentUploadSummarySingle.show();
            }
        } else {
            // Файли не обрано, приховуємо обидва
            $currentUploadSummarySingle.hide();
            $currentUploadSummaryTotal.hide();
        }

        // 2. ЛОГІКА ПЕРЕВІРКИ МІНІМАЛЬНОЇ СУМИ (оновлено)
        const shouldEnableButton = currentUploadTotalCopies > 0 && totalSumForFormat >= minSum;
        
        if (totalSumForFormat < minSum && currentUploadTotalFiles > 0) {
            $sumWarning.show();
        } else {
            $sumWarning.hide();
        }

        // Керування кнопкою
        $submitButton.prop('disabled', !shouldEnableButton);
    }

    /**
     * Рендерить список обраних файлів з полями для копій
     * @param {FileList} fileList - Список файлів, обраних у формі
     */
    function renderFileQuantities(fileList) {
        $quantitiesContainer.empty();
        
        if (fileList.length === 0) {
            $quantitiesContainer.html('<p style="text-align: center; color: #667;">Не вибрано жодного файлу.</p>');
            // Якщо немає файлів, оновлюємо підсумки (що приховає їх)
            updateCurrentUploadSummary();
            return;
        }

        $.each(fileList, function(i, file) {
            const $item = $('<div class="photo-item">');
            
            // Контейнер для мініатюри 
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
                    name: 'copies_count_input[]', 
                    id: 'copies_' + i,
                    value: 1,
                    min: 1
                })
                .on('input change', updateCurrentUploadSummary);
            
            // Кнопка видалення
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
        
        // Перерендеринг списку копій та оновлення підсумку
        renderFileQuantities(input.files);
    }

    /**
     * Оновлює підсумкову таблицю замовлення
     */
    function updateSummaryList() {
        const $list = $('#ppo-formats-list');
        $list.empty();
        
        let totalCopiesOverall = 0;

        // Фільтруємо системні ключі (наприклад, order_folder_path)
        for (const format in sessionFormats) {
            if (format.includes('folder_path') || !sessionFormats.hasOwnProperty(format) || typeof sessionFormats[format] !== 'object') continue;
            
            const details = sessionFormats[format];
            const $listItem = $('<li>')
                .text(`${format}: ${details.total_copies} копій, ${details.total_price.toFixed(0)} грн`);
            $list.append($listItem);
            totalCopiesOverall += details.total_copies;
        }

        $('#ppo-session-total').html(`${sessionTotal.toFixed(0)} грн <small>(Всього копій: ${totalCopiesOverall})</small>`);
        
        // Показуємо/ховаємо контейнер підсумків
        if (totalCopiesOverall > 0) {
             $('#ppo-formats-list-container').show();
        } else {
             $('#ppo-formats-list-container').hide();
        }
    }
    
    // --- Обробники подій ---

    // 1. При виборі формату (очищаємо поле файлів та оновлюємо підсумок)
    $formatSelect.on('change', function() {
        $photosInput.val(''); // Очищаємо вибрані файли
        $quantitiesContainer.html('<p style="text-align: center; color: #666;">Виберіть фото для цього формату.</p>');
        
        // Приховуємо підсумки та попередження (це робить updateCurrentUploadSummary)
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
        if (files.length > maxFilesPerUpload) { 
            displayMessage('Максимум ' + maxFilesPerUpload + ' файлів дозволено за одне завантаження.', 'error');
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
        
        // Додаткове приховування для чистоти інтерфейсу
        $currentUploadSummarySingle.hide();
        $currentUploadSummaryTotal.hide();
        
        $currentUploadSum.text('0');
        $formatTotalSum.text('0');
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
        const formData = new FormData();
        formData.append('action', 'ppo_file_upload');
        formData.append('ppo_ajax_nonce', nonce);
        formData.append('format', selectedFormat);
        
        // Додаємо файли 
        for (let i = 0; i < $photosInput[0].files.length; i++) {
             formData.append('photos[]', $photosInput[0].files[i]);
        }
        
        // Збираємо копії окремим масивом
        const copiesArray = [];
        $quantitiesContainer.find('input[type="number"]').each(function() {
            copiesArray.push($(this).val());
        });
        formData.append('copies', JSON.stringify(copiesArray)); 
        
        // AJAX запит
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
                
                // Очищаємо підсумок поточного завантаження після успіху/помилки
                $currentUploadSum.text('0');
                $formatTotalSum.text('0');
                $currentUploadSummarySingle.hide();
                $currentUploadSummaryTotal.hide();
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
    // Викликаємо оновлення, щоб приховати підсумки, якщо сторінка завантажується без вибраного формату
    updateCurrentUploadSummary(); 
});