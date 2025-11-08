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
    let sessionTotal = parseFloat(ppo_ajax_object.session_total) || 0; 
    
    // НОВЕ: Масив для накопичення файлів (щоб додавати поступово)
    let accumulatedFiles = new DataTransfer(); 
    
    // --- Елементи DOM ---
    const $form = $('#photo-print-order-form');
    const $formatSelect = $('#format');
    
    // !!! НОВІ ЕЛЕМЕНТИ ДЛЯ ОПЦІЙ
    const $finishOptions = $('input[name="ppo_finish_option"]'); // Глянець/Матовий
    const $frameOptions = $('input[name="ppo_frame_option"]');   // Рамка/Без рамки
    
    const $quantitiesContainer = $('#photo-quantities');
    const $currentUploadSum = $('#current-upload-sum');
    const $formatTotalSum = $('#format-total-sum');
    const $sumWarning = $('#sum-warning');
    const $submitButton = $('#submit-order');
    const $loader = $('#ppo-loader');
    const $messages = $('#ppo-alert-messages');
    const $clearFormButton = $('#clear-form');
    
    // НОВІ ЕЛЕМЕНТИ ПІДСУМКІВ
    const $currentUploadSummarySingle = $('.ppo-current-upload-summary-single');
    const $currentUploadSummaryTotal = $('.ppo-current-upload-summary-total');

    // !!! НОВІ ЕЛЕМЕНТИ ДЛЯ ЛОГІКИ ПОСИЛАННЯ
    const $hiddenFileInput = $('#ppo-hidden-file-input'); 
    const $addPhotosLink = $('#ppo-add-photos-link'); 
    const $quantitiesParent = $('#photo-quantities-container'); 

    // ІНТЕГРОВАНО: Елементи для прогресу
    const $progressContainer = $('#ppo-progress-container');
    const $progressFill = $('#ppo-progress-fill');
    const $progressText = $('#ppo-progress-text');

    // ІНТЕГРОВАНО: Елементи для модального вікна (success/error)
    const $successModal = $('#ppo-success-modal');
    const $modalMessage = $('#ppo-modal-message');
    const $modalClose = $('.ppo-modal-close');
    const $modalOk = $('#ppo-modal-ok');

    // --- Допоміжні функції ---

    /**
     * Очищає контейнер повідомлень
     */
    function clearMessages() {
        $messages.empty();
    }

    /**
     * Відображає повідомлення користувачеві (для помилок/попереджень — верх форми)
     */
    function displayMessage(message, type) {
        clearMessages();
        const $alert = $('<div>')
            .addClass('ppo-message ppo-message-' + type)
            .html('<p>' + message + '</p>');
        $messages.append($alert);
    }

    // ІНТЕГРОВАНО: Функція для показу модального вікна 
    function showModal(message) {
        $modalMessage.text(message);
        $successModal.removeClass('show').show(); 
        $('body').addClass('ppo-modal-open'); 

        setTimeout(function() {
            $successModal.addClass('show');
        }, 10);

        setTimeout(function() {
            hideModal();
        }, 2000);

        $successModal.on('click', function(e) {
            if (e.target === this) {
                hideModal();
            }
        });

        $modalOk.on('click', function() {
            hideModal();
        });

        $modalClose.on('click', hideModal);
        $(document).on('keydown.modal', function(e) {
            if (e.key === 'Escape') {
                hideModal();
            }
        });
    }

    function hideModal() {
        $successModal.removeClass('show'); 
        setTimeout(function() { 
            $successModal.hide();
            $('body').removeClass('ppo-modal-open');
            $successModal.off('click'); 
            $(document).off('keydown.modal');
        }, 300); 
    }
    
    /**
     * Формує повний ключ формату: {format}_{finish}_{frame}
     */
    function getFullFormatKey(format) {
        const finish = $('input[name="ppo_finish_option"]:checked').val() || '';
        const frame = $('input[name="ppo_frame_option"]:checked').val() || '';
        return `${format}_${finish}_${frame}`;
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
        
        // !!! ЗМІНА: Використовуємо повний ключ формату для пошуку в sessionFormats
        const fullFormatKey = getFullFormatKey(selectedFormat);

        const pricePerPhoto = parseFloat(prices[selectedFormat] || 0);
        let currentUploadTotalCopies = 0;
        let currentUploadTotalPrice = 0;
        let currentUploadTotalFiles = accumulatedFiles.files.length; 

        // Збираємо дані про копії з динамічних полів
        $quantitiesContainer.find('input[type="number"]').each(function() {
            const copies = parseInt($(this).val()) || 1;
            currentUploadTotalCopies += copies;
            currentUploadTotalPrice += copies * pricePerPhoto;
        });
        
        // !!! ЗМІНА Округлення до 0.01 грн для уникнення float-помилок
        const roundedCurrentUploadTotalPrice = Math.round(currentUploadTotalPrice * 100) / 100;

        // Загальна сума формату (поточна сесія + нове завантаження)
        // !!! ЗМІНА: Використовуємо повний ключ для пошуку
        const sessionFormatDetails = sessionFormats[fullFormatKey] || { total_price: 0 };
        const totalSumForFormatFloat = sessionFormatDetails.total_price + roundedCurrentUploadTotalPrice;
        
        // !!! ЗМІНА Округлення загальної суми формату до 0.01 грн
        const roundedTotalSumForFormat = Math.round(totalSumForFormatFloat * 100) / 100;
        
        // Чи є вже збережені файли для цього формату?
        const hasExistingUploads = sessionFormatDetails.total_price > 0;

        // Оновлення відображення
        // !!! ЗМІНА .toFixed(0) на .toFixed(2)
        $currentUploadSum.text(roundedCurrentUploadTotalPrice.toFixed(2));
        $formatTotalSum.text(roundedTotalSumForFormat.toFixed(2));

        // 1. ЛОГІКА ВІДОБРАЖЕННЯ ПІДСУМКІВ
        if (currentUploadTotalFiles > 0) {
            if (hasExistingUploads) {
                $currentUploadSummarySingle.hide();
                $currentUploadSummaryTotal.show();
            } else {
                $currentUploadSummaryTotal.hide();
                $currentUploadSummarySingle.show();
            }
        } else {
            $currentUploadSummarySingle.hide();
            $currentUploadSummaryTotal.hide();
        }

        // 2. ЛОГІКА ПЕРЕВІРКИ МІНІМАЛЬНОЇ СУМИ
        const shouldEnableButton = currentUploadTotalCopies > 0 && roundedTotalSumForFormat >= minSum;
        
        if (roundedTotalSumForFormat < minSum && currentUploadTotalFiles > 0) {
            $sumWarning.show();
        } else {
            $sumWarning.hide();
        }

        // Керування кнопкою
        $submitButton.prop('disabled', !shouldEnableButton);
    }

    /**
     * Рендерить список обраних файлів з полями для копій (з накопичених файлів)
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
            // Якщо немає файлів, повертаємо клікабельне посилання з підказкою для Drag & Drop
            $quantitiesContainer.html('<p id="ppo-add-photos-link" style="text-align: center; color: #0073aa; cursor: pointer; text-decoration: underline; font-weight: bold; padding: 10px 0;">Натисніть тут, щоб додати фото (або перетягніть файли сюди)</p>');
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
     */
    function removeFileFromList(indexToRemove) {
        const dt = new DataTransfer();
        const files = accumulatedFiles.files;
        
        for (let i = 0; i < files.length; i++) {
            if (i !== indexToRemove) {
                dt.items.add(files[i]);
            }
        }
        accumulatedFiles = dt; 
        $hiddenFileInput[0].files = accumulatedFiles.files; 
        
        renderFileQuantities();
    }

    /**
     * Оновлює підсумкову таблицю замовлення
     */
    function updateSummaryList() {
        const $list = $('#ppo-formats-list');
        $list.empty();
        
        let totalCopiesOverall = 0;
        
        // Допоміжна функція для відображення ключа
        function getOptionLabel(key) {
            const map = {
                'gloss': 'Глянець',
                'matte': 'Матовий',
                'frameoff': 'Без рамки',
                'frameon': 'З рамкою',
            };
            return map[key] || '';
        }

        // Фільтруємо системні ключі (наприклад, order_folder_path)
        for (const fullKey in sessionFormats) {
            if (fullKey.includes('folder_path') || !sessionFormats.hasOwnProperty(fullKey) || typeof sessionFormats[fullKey] !== 'object') continue;
            
            const details = sessionFormats[fullKey];
            
            // !!! НОВЕ: Розбір повного ключа для відображення
            const keyParts = fullKey.split('_');
            const formatName = keyParts[0] || fullKey;
            const finishLabel = getOptionLabel(keyParts[1]);
            const frameLabel = getOptionLabel(keyParts[2]);
            
            let displayKey = formatName;
            const options = [finishLabel, frameLabel].filter(Boolean).join(', ');
            if (options) {
                displayKey += ` (${options})`;
            }
            
            // !!! ЗМІНА .toFixed(0) на .toFixed(2)
            const $listItem = $('<li>')
                .text(`${displayKey}: ${details.total_copies} копій, ${details.total_price.toFixed(2)} грн`);
            $list.append($listItem);
            totalCopiesOverall += details.total_copies;
        }

        // !!! ЗМІНА .toFixed(0) на .toFixed(2)
        $('#ppo-session-total').html(`${sessionTotal.toFixed(2)} грн <small>(Всього копій: ${totalCopiesOverall})</small>`);
        
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
        $hiddenFileInput.click(); 
    });
    
    // !!! НОВЕ: Обробник зміни опцій (тип паперу або рамка)
    function handleOptionChange() {
        // Очищаємо накопичені файли
        accumulatedFiles = new DataTransfer();
        $hiddenFileInput[0].files = accumulatedFiles.files;
        
        // Скидаємо вибір формату
        $formatSelect.val('');
        
        // Відображаємо, що потрібно заново вибрати
        $quantitiesContainer.html('<p id="ppo-add-photos-link" style="text-align: center; color: #cc0000; font-weight: bold; padding: 10px 0;">УВАГА! Опції змінено. Оберіть формат та додайте фото заново.</p>');
        $('#ppo-add-photos-link').on('click', function(e) {
             e.preventDefault();
             $hiddenFileInput.click();
        });
        
        // Приховуємо контейнер, оскільки формат скинуто
        $quantitiesParent.hide();
        
        updateCurrentUploadSummary();
        displayMessage('Вибір опцій впливає на назву папки. Будь ласка, оберіть формат та додайте фото заново.', 'warning');
    }

    $finishOptions.on('change', handleOptionChange);
    $frameOptions.on('change', handleOptionChange);

    // ІНТЕГРОВАНО: Drag & Drop обробники на контейнері
    $quantitiesParent.on('dragover dragenter', function(e) {
        e.preventDefault();
        e.originalEvent.dataTransfer.dropEffect = 'copy';
        $(this).addClass('drag-over');
    }).on('dragleave dragend', function(e) {
        e.preventDefault();
        $(this).removeClass('drag-over');
    }).on('drop', function(e) {
        e.preventDefault();
        $(this).removeClass('drag-over');
        
        const selectedFormat = $formatSelect.val();
        const droppedFiles = e.originalEvent.dataTransfer.files;
        
        clearMessages();

        if (!selectedFormat) {
            displayMessage('Будь ласка, спочатку оберіть формат фото.', 'warning');
            return;
        }
        if (droppedFiles.length + accumulatedFiles.files.length > maxFilesPerUpload) {
            displayMessage('Максимум ' + maxFilesPerUpload + ' файлів дозволено за одне завантаження.', 'error');
            return;
        }
        
        renderFileQuantities(droppedFiles);
    });

    // 1. При виборі формату (очищаємо поле файлів та оновлюємо підсумок)
    $formatSelect.on('change', function() {
        const selectedFormat = $(this).val();

        // НОВЕ: При зміні формату очищуємо accumulatedFiles 
        accumulatedFiles = new DataTransfer();
        $hiddenFileInput[0].files = accumulatedFiles.files;
        
        // !!! ОНОВЛЕНО: Початковий вміст тепер клікабельне посилання 
        $quantitiesContainer.html('<p id="ppo-add-photos-link" style="text-align: center; color: #0073aa; cursor: pointer; text-decoration: underline; font-weight: bold; padding: 10px 0;">Натисніть тут, щоб додати фото (або перетягніть файли сюди)</p>');
        $('#ppo-add-photos-link').on('click', function(e) {
            e.preventDefault();
            $hiddenFileInput.click();
        });
        
        if (selectedFormat) {
            $quantitiesParent.show(); 
        } 
        else {
            $quantitiesParent.hide(); 
        }

        updateCurrentUploadSummary();
    });

    // 2. При виборі файлів (рендеримо поля копій з append)
    $hiddenFileInput.on('change', function() { 
        const selectedFormat = $formatSelect.val();
        const newFiles = this.files; 

        clearMessages();

        if (!selectedFormat) {
            displayMessage('Будь ласка, спочатку оберіть формат фото.', 'warning');
            this.value = ''; 
            return;
        }
        if (newFiles.length + accumulatedFiles.files.length > maxFilesPerUpload) {
            displayMessage('Максимум ' + maxFilesPerUpload + ' файлів дозволено за одне завантаження.', 'error');
            this.value = ''; 
            return;
        }
        
        renderFileQuantities(newFiles);
    });
    
    // 3. Обробка натискання кнопки "Очистити"
    $clearFormButton.on('click', function(e) {
        e.preventDefault();
        
        // !!! ДОДАНО: Скидання опцій на дефолтні значення
        $('#finish-gloss').prop('checked', true);
        $('#frame-off').prop('checked', true);
        
        accumulatedFiles = new DataTransfer();
        $hiddenFileInput[0].files = accumulatedFiles.files;
        $formatSelect.val(''); 
        
        $quantitiesContainer.html('<p id="ppo-add-photos-link" style="text-align: center; color: #0073aa; cursor: pointer; text-decoration: underline; font-weight: bold; padding: 10px 0;">Натисніть тут, щоб додати фото (або перетягніть файли сюди)</p>');
        $('#ppo-add-photos-link').on('click', function(e) {
            e.preventDefault();
            $hiddenFileInput.click();
        });

        $sumWarning.hide();
        $submitButton.prop('disabled', true);
        
        $quantitiesParent.hide(); 
        
        $currentUploadSummarySingle.hide();
        $currentUploadSummaryTotal.hide();
        
        $currentUploadSum.text('0.00'); // !!! ЗМІНА: 0.00
        $formatTotalSum.text('0.00'); // !!! ЗМІНА: 0.00
        clearMessages();
    });


    // 4. Обробка відправки форми (AJAX) 
    $form.on('submit', function(e) {
        e.preventDefault();

        const selectedFormat = $formatSelect.val();
        if (accumulatedFiles.files.length === 0) { 
            displayMessage('Будь ласка, додайте фото для завантаження.', 'error');
            return;
        }

        $loader.hide();
        $submitButton.prop('disabled', true);
        clearMessages();

        $progressContainer.show();
        $progressFill.width('0%').removeClass('processing'); 
        $progressText.text('0%').removeClass('processing-text');

        // Збираємо дані форми
        const formData = new FormData();
        formData.append('action', 'ppo_file_upload');
        formData.append('ppo_ajax_nonce', nonce);
        formData.append('format', selectedFormat);
        
        // !!! НОВЕ: ДОДАЄМО ВИБРАНІ ОПЦІЇ
        formData.append('ppo_finish_option', $('input[name="ppo_finish_option"]:checked').val());
        formData.append('ppo_frame_option', $('input[name="ppo_frame_option"]:checked').val());
        
        // Додаємо файли з accumulated 
        for (let i = 0; i < accumulatedFiles.files.length; i++) { 
            formData.append('photos[]', accumulatedFiles.files[i]);
        }
        
        // Збираємо копії окремим масивом
        const copiesArray = [];
        $quantitiesContainer.find('input[type="number"]').each(function() {
            copiesArray.push($(this).val());
        });
        formData.append('copies', JSON.stringify(copiesArray)); 
        
        // AJAX запит з прогресом
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            xhr: function() { 
                const xhr = new window.XMLHttpRequest();
                let uploadComplete = false; 
                
                xhr.upload.addEventListener('progress', function(evt) {
                    if (evt.lengthComputable) {
                        const percent = Math.round((evt.loaded / evt.total) * 100);
                        $progressFill.width(percent + '%');
                        $progressText.text(percent + '%');
                        
                        if (percent >= 100 && !uploadComplete) {
                            uploadComplete = true;
                            $progressFill.width('100%').addClass('processing'); 
                            $progressText.text('Завантажено! Обробка на сервері...').addClass('processing-text');
                        }
                    }
                }, false);
                
                xhr.addEventListener('progress', function(evt) {
                    if (evt.lengthComputable && uploadComplete) {
                        const percent = 100 + Math.round((evt.loaded / evt.total) * 10); 
                        if (percent > 100) $progressFill.width('100%');
                    }
                }, false);
                
                return xhr;
            },
            success: function(response) {
                
                $progressContainer.hide();
                
                // НОВЕ: Очищуємо accumulated після успіху 
                accumulatedFiles = new DataTransfer();
                $hiddenFileInput[0].files = accumulatedFiles.files;
                $quantitiesContainer.empty();
                $formatSelect.val(''); 
                
                // !!! ДОДАНО: Скидання опцій на дефолтні значення
                $('#finish-gloss').prop('checked', true);
                $('#frame-off').prop('checked', true);
                
                if (response.success) {
                    showModal(response.data.message);
                    
                    // Оновлення глобальної сесії JS
                    sessionFormats = response.data.formats;
                    sessionTotal = parseFloat(response.data.total) || 0; 
                    
                    updateSummaryList(); 
                } else {
                    displayMessage(response.data.message, 'error');
                    $submitButton.prop('disabled', false); 
                }
                
                // Очищаємо підсумок поточного завантаження після успіху/помилки
                $currentUploadSum.text('0.00'); // !!! ЗМІНА: 0.00
                $formatTotalSum.text('0.00'); // !!! ЗМІНА: 0.00
                $currentUploadSummarySingle.hide();
                $currentUploadSummaryTotal.hide();
                
                // Після успішного завантаження приховуємо контейнер
                $quantitiesParent.hide(); 
            },
            error: function(xhr, status, error) {
                
                $progressContainer.hide();
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
        $quantitiesParent.hide(); 
    }
    updateCurrentUploadSummary(); 
    
    updateSummaryList(); 
});