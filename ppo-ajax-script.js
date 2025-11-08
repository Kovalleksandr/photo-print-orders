jQuery(document).ready(function($) {

    // ====================================================================
    // 0. КОНСТАНТИ, ХЕЛПЕРИ ТА ЗМІННІ
    // ====================================================================

    // --- Функція для контролю видимості опцій формату (ЗАЛИШАЄМО БЕЗ ЗМІН) ---
    /**
     * Функція для контролю видимості контейнера опцій типу паперу та рамки.
     */
    function toggleFormatOptionsVisibility() {
        const quantitiesContainer = document.getElementById('photo-quantities');
        const optionsContainer = document.getElementById('ppo-format-options');

        if (!quantitiesContainer || !optionsContainer) {
            return; 
        }

        // Перевіряємо, чи є в контейнері фото якісні елементи (завантажені фото).
        const hasPhotos = accumulatedFiles.files.length > 0;

        // Показуємо опції, лише якщо немає файлів для поточного завантаження.
        if (hasPhotos) {
            optionsContainer.style.display = 'none';
        } else {
            // Якщо фото немає, відображаємо контейнер опцій
            optionsContainer.style.display = ''; // або 'block'
        }
    }

    /**
     * Функція-хелпер для отримання людської назви опції
     */
    function getOptionLabel(key) {
        const map = {
            'gloss': 'Глянець',
            'matte': 'Матовий',
            'frameoff': 'Без рамки',
            'frameon': 'З рамкою',
        };
        return map[key] ?? '';
    }

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
    const $frameOptions = $('input[name="ppo_frame_option"]');   // Рамка/Без рамки
    
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

    // --- Допоміжні функції (Повідомлення, Модальні вікна) ---

    function clearMessages() {
        $messages.empty();
    }

    function displayMessage(message, type) {
        clearMessages();
        const $alert = $('<div>')
            .addClass('ppo-message ppo-message-' + type)
            .html('<p>' + message + '</p>');
        $messages.append($alert);
    }

    function showModal(message) {
        $modalMessage.text(message);
        $successModal.removeClass('show').show(); 
        $('body').addClass('ppo-modal-open'); 

        setTimeout(function() {
            $successModal.addClass('show');
        }, 10);

        // Перевіряємо, чи вже прив'язані обробники, щоб не викликати hideModal багато разів
        $modalOk.off('click').on('click', hideModal);
        $modalClose.off('click').on('click', hideModal);
        $successModal.off('click').on('click', function(e) {
            if (e.target === this) {
                hideModal();
            }
        });
        $(document).off('keydown.modal').on('keydown.modal', function(e) {
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

    // ====================================================================
    // 3. ФУНКЦІЯ ОНОВЛЕННЯ ДЕТАЛЕЙ ЗАМОВЛЕННЯ (ВИРІШЕННЯ ПРОБЛЕМИ)
    // ====================================================================
    
    /**
     * Динамічно оновлює блок "Деталі замовлення" (список збережених форматів)
     */
    function updateSummaryList() {
        const listContainer = $('#ppo-formats-list-container');
        const formatsList = $('#ppo-formats-list');
        const sessionTotalSpan = $('#ppo-session-total');
        let totalCopies = 0;
        
        formatsList.empty(); 

        if ($.isEmptyObject(sessionFormats)) {
            listContainer.hide();
            sessionTotalSpan.html('0.00 грн');
            return;
        }

        // Обробляємо та відображаємо кожен формат
        for (const key in sessionFormats) {
            // Перевіряємо, що ключ є коректним об'єктом формату
            if (sessionFormats.hasOwnProperty(key) && typeof sessionFormats[key] === 'object' && sessionFormats[key].format) {
                const details = sessionFormats[key];
                
                // Логіка для відображення опцій (10x15 (Глянець, Без рамки))
                const parts = key.split('_');
                const formatName = parts[0];
                const finishLabel = getOptionLabel(parts[1] ?? '');
                const frameLabel = getOptionLabel(parts[2] ?? '');
                let displayKey = formatName;
                
                if (finishLabel || frameLabel) {
                     displayKey += ' (' + [finishLabel, frameLabel].filter(Boolean).join(', ') + ')';
                }
                
                // Створення елементу списку
                const listItem = $('<li>').html(`
                    <strong>${displayKey}:</strong> 
                    ${details.total_copies} копій, 
                    <span class="ppo-price">${details.total_price.toFixed(2)} грн</span>
                `);
                formatsList.append(listItem);
                
                totalCopies += details.total_copies;
            }
        }
        
        // Оновлюємо загальну суму та кількість копій
        sessionTotalSpan.html(`${sessionTotal.toFixed(2)} грн <small>(Всього копій: ${totalCopies})</small>`);

        // Показуємо блок деталей замовлення
        listContainer.show();
    }
    
    // ====================================================================
    // 4. ІНШІ ФУНКЦІЇ ДЛЯ ЛОГІКИ
    // ====================================================================


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
        
        // Округлення до 0.01 грн для уникнення float-помилок
        const roundedCurrentUploadTotalPrice = Math.round(currentUploadTotalPrice * 100) / 100;

        // Загальна сума формату (поточна сесія + нове завантаження)
        const sessionFormatDetails = sessionFormats[fullFormatKey] || { total_price: 0 };
        const totalSumForFormatFloat = sessionFormatDetails.total_price + roundedCurrentUploadTotalPrice;
        
        // Округлення загальної суми формату до 0.01 грн
        const roundedTotalSumForFormat = Math.round(totalSumForFormatFloat * 100) / 100;
        
        // Чи є вже збережені файли для цього формату?
        const hasExistingUploads = sessionFormatDetails.total_price > 0;

        // Оновлення відображення
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
        const maxFiles = maxFilesPerUpload; // для читабельності
        
        if (currentFiles.length === 0) {
            // Якщо немає файлів, повертаємо клікабельне посилання з підказкою для Drag & Drop
            const $link = $('<p>')
                .attr('id', 'ppo-add-photos-link')
                .css({
                    'text-align': 'center', 'color': '#0073aa', 'cursor': 'pointer', 
                    'text-decoration': 'underline', 'font-weight': 'bold', 'padding': '10px 0'
                })
                .text('Натисніть тут, щоб додати фото (або перетягніть файли сюди)')
                .on('click', function(e) {
                    e.preventDefault();
                    $hiddenFileInput.click();
                });
            $quantitiesContainer.append($link);
            
            updateCurrentUploadSummary();
            toggleFormatOptionsVisibility(); 
            return;
        }

        // ОНОВЛЕННЯ: Додаємо кнопку "Додати ще" в кінець списку 
        let addLinkText = `Натисніть тут, щоб додати ще фото (додано ${currentFiles.length} з ${maxFiles})`;
        
        const $addMoreLink = $('<p>')
            .attr('id', 'ppo-add-photos-link')
            .html(currentFiles.length >= maxFiles ? `Максимум файлів досягнуто (${currentFiles.length})` : addLinkText)
            .css({
                'text-align': 'center',
                'color': currentFiles.length >= maxFiles ? '#ccc' : '#0073aa',
                'cursor': currentFiles.length >= maxFiles ? 'default' : 'pointer',
                'text-decoration': currentFiles.length >= maxFiles ? 'none' : 'underline',
                'font-weight': 'bold',
                'padding': '10px 0'
            })
            .on('click', function(e) {
                if (currentFiles.length < maxFiles) {
                    e.preventDefault();
                    $hiddenFileInput.click();
                }
            });

        $.each(currentFiles, function(i, file) {
            const $item = $('<div class="photo-item">');
            
            // ... (HTML рендеринг файлу: мініатюра, назва, input, кнопка видалення)
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

            const $label = $('<label>')
                .attr('for', 'copies_' + i)
                .text(file.name);

            const $input = $('<input>')
                .attr({
                    type: 'number',
                    name: 'copies_count_input[]', 
                    id: 'copies_' + i,
                    value: 1,
                    min: 1
                })
                .on('input change', updateCurrentUploadSummary);
            
            const $removeButton = $('<button type="button" class="remove-file-btn" style="background:none; border:none; color:red; cursor:pointer;">&times;</button>')
                .data('file-index', i)
                .on('click', function() {
                    removeFileFromList(i); 
                });
            
            $item.append($label, $input, $removeButton);
            $quantitiesContainer.append($item);
        });

        // Додаємо посилання "Додати ще" в кінець
        $quantitiesContainer.append($addMoreLink);

        updateCurrentUploadSummary();
        toggleFormatOptionsVisibility(); 
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

    // ====================================================================
    // 5. ОБРОБНИКИ ПОДІЙ
    // ====================================================================
    
    // 0. НОВИЙ ОБРОБНИК КЛІКУ ПОСИЛАННЯ (Потрібно, якщо початковий елемент вже існує в DOM)
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
        const $warningLink = $('<p>')
            .attr('id', 'ppo-add-photos-link')
            .css({'text-align': 'center', 'color': '#cc0000', 'font-weight': 'bold', 'padding': '10px 0'})
            .text('УВАГА! Опції змінено. Оберіть формат та додайте фото заново.')
            .on('click', function(e) {
                e.preventDefault();
                $hiddenFileInput.click();
            });

        $quantitiesContainer.html($warningLink);
        
        // Приховуємо контейнер, оскільки формат скинуто
        $quantitiesParent.hide();
        
        updateCurrentUploadSummary();
        displayMessage('Вибір опцій впливає на назву папки. Будь ласка, оберіть формат та додайте фото заново.', 'warning');
        
        toggleFormatOptionsVisibility();
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
        
        // !!! ОНОВЛЕНО: Ініціалізуємо рендеринг з порожнім списком (покаже посилання "додати")
        renderFileQuantities();

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
        
        // Скидання опцій на дефолтні значення
        $('#finish-gloss').prop('checked', true);
        $('#frame-off').prop('checked', true);
        
        accumulatedFiles = new DataTransfer();
        $hiddenFileInput[0].files = accumulatedFiles.files;
        $formatSelect.val(''); 
        
        // Перерендеринг порожнього списку
        renderFileQuantities();

        $sumWarning.hide();
        $submitButton.prop('disabled', true);
        
        $quantitiesParent.hide(); 
        
        $currentUploadSummarySingle.hide();
        $currentUploadSummaryTotal.hide();
        
        $currentUploadSum.text('0.00'); 
        $formatTotalSum.text('0.00'); 
        clearMessages();
        
        updateCurrentUploadSummary();
        toggleFormatOptionsVisibility();
    });


    // 4. Обробка відправки форми (AJAX) 
    $form.on('submit', function(e) {
        e.preventDefault();

        if (accumulatedFiles.files.length === 0) { 
            displayMessage('Будь ласка, додайте фото для завантаження.', 'error');
            return;
        }

        // ... (Код підготовки та відправки AJAX-запиту)
        $loader.hide();
        $submitButton.prop('disabled', true);
        clearMessages();

        $progressContainer.show();
        $progressFill.width('0%').removeClass('processing'); 
        $progressText.text('0%').removeClass('processing-text');

        // Збір даних форми
        const formData = new FormData();
        formData.append('action', 'ppo_file_upload');
        formData.append('ppo_ajax_nonce', nonce);
        formData.append('format', $formatSelect.val());
        
        formData.append('ppo_finish_option', $('input[name="ppo_finish_option"]:checked').val());
        formData.append('ppo_frame_option', $('input[name="ppo_frame_option"]:checked').val());
        
        for (let i = 0; i < accumulatedFiles.files.length; i++) { 
            formData.append('photos[]', accumulatedFiles.files[i]);
        }
        
        // Збір копій
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
                // ... (Код обробки прогресу: xhr.upload.addEventListener)
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
                
                return xhr;
            },
            success: function(response) {
                
                $progressContainer.hide();
                
                // Очищення форми
                accumulatedFiles = new DataTransfer();
                $hiddenFileInput[0].files = accumulatedFiles.files;
                $quantitiesContainer.empty();
                $formatSelect.val(''); 
                
                $('#finish-gloss').prop('checked', true);
                $('#frame-off').prop('checked', true);
                
                if (response.success) {
                    showModal(response.data.message);
                    
                    // !!! ВИРІШЕННЯ ПРОБЛЕМИ "ПРОПАЖІ": Оновлення глобальної сесії JS та списку
                    sessionFormats = response.data.formats;
                    sessionTotal = parseFloat(response.data.total) || 0; 
                    
                    updateSummaryList(); // ОНОВЛЮЄ БЛОК ЗБЕРЕЖЕНИХ ДЕТАЛЕЙ ЗАМОВЛЕННЯ
                } else {
                    displayMessage(response.data.message, 'error');
                    $submitButton.prop('disabled', false); 
                }
                
                // Очищаємо підсумок поточного завантаження та приховуємо контейнери
                $currentUploadSum.text('0.00'); 
                $formatTotalSum.text('0.00'); 
                $currentUploadSummarySingle.hide();
                $currentUploadSummaryTotal.hide();
                
                $quantitiesParent.hide(); 

                toggleFormatOptionsVisibility();
            },
            error: function(xhr, status, error) {
                
                $progressContainer.hide();
                const errorMessage = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message 
                                                         ? xhr.responseJSON.data.message 
                                                         : 'Помилка завантаження. Перевірте консоль.';
                displayMessage(errorMessage, 'error');
                $submitButton.prop('disabled', false);

                toggleFormatOptionsVisibility();
            }
        });
    });

    // ====================================================================
    // 6. ІНІЦІАЛІЗАЦІЯ
    // ====================================================================
    
    if (!$formatSelect.val()) {
        $quantitiesParent.hide(); 
    }
    updateCurrentUploadSummary(); 
    updateSummaryList(); // Ініціалізуємо відображення деталей сесії
    toggleFormatOptionsVisibility();
});