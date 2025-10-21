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
    let sessionTotal = parseFloat(ppo_ajax_object.session_total) || 0;  // ФІКС: || 0 для уникнення NaN
    
    // НОВЕ: Масив для накопичення файлів (щоб додавати поступово)
    let accumulatedFiles = new DataTransfer();  // Початковий порожній DataTransfer для input.files
    
    // --- Елементи DOM ---
    const $form = $('#photo-print-order-form');
    const $formatSelect = $('#format');
    
    // !!! ОНОВЛЕНО: Старий $photosInput ВИДАЛЕНО
    // const $photosInput = $('#photos'); 

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
    // const $photoUploadControls = $('#photo-upload-controls'); // ВИДАЛЕНО, оскільки контейнер #photo-upload-controls більше не використовується

    // !!! НОВІ ЕЛЕМЕНТИ ДЛЯ ЛОГІКИ ПОСИЛАННЯ
    const $hiddenFileInput = $('#ppo-hidden-file-input'); // Нове приховане поле
    const $addPhotosLink = $('#ppo-add-photos-link');     // Нове клікабельне посилання
    const $quantitiesParent = $('#photo-quantities-container'); // Батьківський контейнер для логіки видимості

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
        let currentUploadTotalFiles = accumulatedFiles.files.length;  // НОВЕ: Використовуємо накопичені файли

        // Збираємо дані про копії з динамічних полів
        $quantitiesContainer.find('input[type="number"]').each(function() {
            const copies = parseInt($(this).val()) || 1;
            currentUploadTotalCopies += copies;
            currentUploadTotalPrice += copies * pricePerPhoto;
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
     * Рендерить список обраних файлів з полями для копій (з накопичених файлів)
     * @param {FileList} newFiles - Нові файли для append (якщо є)
     */
    function renderFileQuantities(newFiles = null) {
        // НОВЕ: Якщо newFiles передані, append до accumulated
        if (newFiles && newFiles.length > 0) {
            for (let i = 0; i < newFiles.length; i++) {
                accumulatedFiles.items.add(newFiles[i]);
            }
            // Оновлюємо input.files
            $hiddenFileInput[0].files = accumulatedFiles.files;
        }

        $quantitiesContainer.empty();
        const currentFiles = accumulatedFiles.files;
        
        if (currentFiles.length === 0) {
            // Якщо немає файлів, повертаємо клікабельне посилання
            $quantitiesContainer.html('<p id="ppo-add-photos-link" style="text-align: center; color: #0073aa; cursor: pointer; text-decoration: underline; font-weight: bold; padding: 10px 0;">Натисніть тут, щоб додати фото</p>');
            // Повторно прив'язуємо клік до посилання, якщо воно було рендерено
            $('#ppo-add-photos-link').on('click', function(e) {
                e.preventDefault();
                $hiddenFileInput.click();
            });
            updateCurrentUploadSummary();
            return;
        }

        // ОНОВЛЕННЯ: Показуємо лічильник у посиланні (якщо < max)
        let addLinkText = `Натисніть тут, щоб додати ще фото (додано ${currentFiles.length} з ${maxFilesPerUpload})`;
        if (currentFiles.length >= maxFilesPerUpload) {
            addLinkText = `Максимум файлів досягнуто (${currentFiles.length})`;
        }

        // Додаємо кнопку "Додати ще" в кінець списку (завжди видиму)
        const $addMoreLink = $('<p>')
            .attr('id', 'ppo-add-photos-link')
            .html(addLinkText)
            .css({
                'text-align': 'center',
                'color': currentFiles.length >= maxFilesPerUpload ? '#ccc' : '#0073aa',
                'cursor': currentFiles.length >= maxFilesPerUpload ? 'default' : 'pointer',
                'text-decoration': currentFiles.length >= maxFilesPerUpload ? 'none' : 'underline',
                'font-weight': 'bold',
                'padding': '10px 0'
            })
            .on('click', function(e) {
                if (currentFiles.length < maxFilesPerUpload) {
                    e.preventDefault();
                    $hiddenFileInput.click();
                }
            });

        $.each(currentFiles, function(i, file) {
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
                $thumbContainer.html('📄'); // Іконка за замовчуванням
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
                    // НОВЕ: Видаляємо з accumulatedFiles
                    removeFileFromList(i); 
                });
            
            $item.append($label, $input, $removeButton);
            $quantitiesContainer.append($item);
        });

        // Додаємо посилання "Додати ще" в кінець
        $quantitiesContainer.append($addMoreLink);

        updateCurrentUploadSummary();
    }
    
    /**
     * Видаляє файл зі списку накопичених файлів
     * @param {number} indexToRemove - Індекс файлу для видалення
     */
    function removeFileFromList(indexToRemove) {
        const dt = new DataTransfer();
        const files = accumulatedFiles.files;
        
        for (let i = 0; i < files.length; i++) {
            if (i !== indexToRemove) {
                dt.items.add(files[i]);
            }
        }
        accumulatedFiles = dt;  // НОВЕ: Оновлюємо accumulated
        $hiddenFileInput[0].files = accumulatedFiles.files; // Синхронізуємо input
        
        // Перерендеринг списку копій та оновлення підсумку
        renderFileQuantities();
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
    
    // 0. НОВИЙ ОБРОБНИК КЛІКУ ПОСИЛАННЯ
    $addPhotosLink.on('click', function(e) {
        e.preventDefault();
        $hiddenFileInput.click(); // Викликаємо клік на прихованому полі
    });

    // 1. При виборі формату (очищаємо поле файлів та оновлюємо підсумок)
    $formatSelect.on('change', function() {
        const selectedFormat = $(this).val();

        // НОВЕ: При зміні формату очищуємо accumulatedFiles (щоб почати новий batch для формату)
        accumulatedFiles = new DataTransfer();
        $hiddenFileInput[0].files = accumulatedFiles.files;
        
        // !!! ОНОВЛЕНО: Початковий вміст тепер клікабельне посилання (повторний рендеринг)
        $quantitiesContainer.html('<p id="ppo-add-photos-link" style="text-align: center; color: #0073aa; cursor: pointer; text-decoration: underline; font-weight: bold; padding: 10px 0;">Натисніть тут, щоб додати фото</p>');
        $('#ppo-add-photos-link').on('click', function(e) {
            e.preventDefault();
            $hiddenFileInput.click();
        });
        
        if (selectedFormat) {
            // Якщо формат обрано, показуємо контейнер кількості
            $quantitiesParent.show(); // !!! ВИКОРИСТОВУЄМО БАТЬКІВСЬКИЙ КОНТЕЙНЕР #photo-quantities-container
        } 
        else {
            // Якщо скинуто до "-- виберіть --", приховуємо все
            $quantitiesParent.hide(); // !!! ВИКОРИСТОВУЄМО БАТЬКІВСЬКИЙ КОНТЕЙНЕР
        }

    // Приховуємо підсумки та попередження (це робить updateCurrentUploadSummary)
    updateCurrentUploadSummary();
});

    // 2. При виборі файлів (рендеримо поля копій з append)
    $hiddenFileInput.on('change', function() { // !!! ОНОВЛЕНО: Обробляємо нове поле
        const selectedFormat = $formatSelect.val();
        const newFiles = this.files;  // НОВЕ: Тільки нові файли

        clearMessages();

        if (!selectedFormat) {
            displayMessage('Будь ласка, спочатку оберіть формат фото.', 'warning');
            this.value = ''; // Очищуємо тільки цей вибір
            return;
        }
        if (newFiles.length + accumulatedFiles.files.length > maxFilesPerUpload) {  // НОВЕ: Перевіряємо з accumulated
            displayMessage('Максимум ' + maxFilesPerUpload + ' файлів дозволено за одне завантаження.', 'error');
            this.value = ''; 
            return;
        }
        
        // НОВЕ: Append і рендер з накопиченими
        renderFileQuantities(newFiles);
    });
    
    // 3. Обробка натискання кнопки "Очистити"
    $clearFormButton.on('click', function(e) {
        e.preventDefault();
        // НОВЕ: Очищуємо accumulatedFiles
        accumulatedFiles = new DataTransfer();
        $hiddenFileInput[0].files = accumulatedFiles.files;
        $formatSelect.val(''); 
        
        // !!! ОНОВЛЕНО: Початковий вміст тепер клікабельне посилання
        $quantitiesContainer.html('<p id="ppo-add-photos-link" style="text-align: center; color: #0073aa; cursor: pointer; text-decoration: underline; font-weight: bold; padding: 10px 0;">Натисніть тут, щоб додати фото</p>');
        $('#ppo-add-photos-link').on('click', function(e) {
            e.preventDefault();
            $hiddenFileInput.click();
        });

        $sumWarning.hide();
        $submitButton.prop('disabled', true);
        
        // ДОДАНО: Приховування секції завантаження
        $quantitiesParent.hide(); // !!! ВИКОРИСТОВУЄМО БАТЬКІВСЬКИЙ КОНТЕЙНЕР
        
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
        if (accumulatedFiles.files.length === 0) { // НОВЕ: Перевіряємо накопичені
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
        
        // Додаємо файли з accumulated 
        for (let i = 0; i < accumulatedFiles.files.length; i++) { // НОВЕ: Беремо з accumulated
            formData.append('photos[]', accumulatedFiles.files[i]);
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
                // НОВЕ: Очищуємо accumulated після успіху (файли збережено на сервері)
                accumulatedFiles = new DataTransfer();
                $hiddenFileInput[0].files = accumulatedFiles.files;
                $quantitiesContainer.empty();
                $formatSelect.val(''); // Очищуємо вибір формату
                
                if (response.success) {
                    displayMessage(response.data.message, 'success');
                    
                    // Оновлення глобальної сесії JS
                    sessionFormats = response.data.formats;
                    sessionTotal = parseFloat(response.data.total) || 0;  // ФІКС: || 0
                    
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
                
                // Після успішного завантаження приховуємо контейнер
                $quantitiesParent.hide(); // !!! ПРИХОВУЄМО КОНТЕЙНЕР
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
    if (!$formatSelect.val()) {
        $quantitiesParent.hide(); // !!! ВИКОРИСТОВУЄМО БАТЬКІВСЬКИЙ КОНТЕЙНЕР
    }
    updateCurrentUploadSummary(); 
    
    // ФІКС: НОВИЙ ВИКЛИК НА INIT - оновлює сесійний підсумок і показує контейнер, якщо є збережені фото
    updateSummaryList();  // Це забезпечує видимість #ppo-formats-list-container після reload, якщо sessionFormats не порожній
});