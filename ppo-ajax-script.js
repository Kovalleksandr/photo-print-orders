jQuery(document).ready(function($) {

    // ====================================================================
    // 0. КОНСТАНТИ, ХЕЛПЕРИ ТА ЗМІННІ
    // ====================================================================

    function getSelectedFormatValue() {
        return $('input[name="format"]:checked').val();
    }
    
    function toggleFormatOptionsVisibility() {
        const optionsContainer = document.getElementById('ppo-step-1'); 
        if (!optionsContainer) { return; }

        const hasFiles = accumulatedFiles.files.length > 0;
        
        // Крок 1 (вибір формату) має бути видимим, якщо немає файлів.
        if (hasFiles) {
            optionsContainer.style.display = 'none';
        } else {
            optionsContainer.style.display = '';
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
    const minSum = parseFloat(ppo_ajax_object.min_sum); 
    const prices = ppo_ajax_object.prices;
    const redirectDelivery = ppo_ajax_object.redirect_delivery;
    const maxFilesPerUpload = ppo_ajax_object.max_files; 

    // Зберігаємо формати та загальну суму в JS
    let sessionFormats = ppo_ajax_object.session_formats;
    let sessionTotal = parseFloat(ppo_ajax_object.session_total) || 0; 
    
    // Масив для накопичення файлів
    let accumulatedFiles = new DataTransfer(); 
    
    // --- Елементи DOM ---
    const $form = $('#photo-print-order-form');
    
    const $finishOptions = $('input[name="paper"]'); 
    const $frameOptions = $('input[name="frame"]'); 
    
    const $quantitiesContainer = $('#photo-quantities');
    const $quantitiesParent = $('#ppo-step-2'); 

    const $currentUploadSum = $('#current-upload-sum');
    const $formatTotalSum = $('#format-total-sum');
    const $sumWarning = $('#sum-warning');
    const $submitButton = $('#submit-order');
    const $loader = $('#ppo-loader');
    const $messages = $('#ppo-alert-messages'); 
    const $clearFormButton = $('#clear-form');
    
    const $currentUploadSummarySingle = $('.ppo-current-upload-summary-single');
    const $currentUploadSummaryTotal = $('.ppo-current-upload-summary-total');

    const $hiddenFileInput = $('#ppo-hidden-file-input'); 
    
    const $progressContainer = $('#ppo-progress-container');
    const $progressFill = $('#ppo-progress-fill');
    const $progressText = $('#ppo-progress-text');

    const $successModal = $('#ppo-success-modal'); 
    const $modalMessage = $('#ppo-modal-message');
    const $modalClose = $('.ppo-modal-close');
    const $modalOk = $('#ppo-modal-ok');

    // --- Допоміжні функції (Messages, Modal) ---
    
    function clearMessages() { $messages.empty(); }

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
     * Формує повний ключ формату та повертає перетворені значення для бек-енду.
     */
    function getFullFormatKey(format) {
        // ВИПРАВЛЕНО: Якщо опції не вибрані, jQuery поверне '', але для коректних розрахунків
        // на фронт-енді ми використовуємо фактично вибрані значення.
        const rawPaper = $('input[name="paper"]:checked').val() || ''; 
        const rawFrame = $('input[name="frame"]:checked').val() || ''; 
        
        // 1. Папір (glossy -> gloss)
        const paper = (rawPaper === 'glossy') ? 'gloss' : rawPaper;
        
        // 2. Рамка (yes -> frameon, none -> frameoff)
        let frame = rawFrame;
        if (rawFrame === 'yes') {
            frame = 'frameon';
        } else if (rawFrame === 'none') {
            frame = 'frameoff';
        }
        
        return {
            fullKey: `${format}_${paper}_${frame}`,
            paper: paper,
            frame: frame
        };
    }

    // ====================================================================
    // 3. ФУНКЦІЯ ОНОВЛЕННЯ ДЕТАЛЕЙ ЗАМОВЛЕННЯ (Підсумок)
    // ====================================================================
    
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

        for (const key in sessionFormats) {
            if (sessionFormats.hasOwnProperty(key) && typeof sessionFormats[key] === 'object' && sessionFormats[key].format) {
                const details = sessionFormats[key];
                
                const parts = key.split('_');
                const formatName = parts[0];
                const paperLabel = getOptionLabel(parts[1] ?? ''); 
                const frameLabel = getOptionLabel(parts[2] ?? '');
                let displayKey = formatName;
                
                if (paperLabel || frameLabel) {
                     displayKey += ' (' + [paperLabel, frameLabel].filter(Boolean).join(', ') + ')';
                }
                
                const listItem = $('<li>').html(`
                    <strong>${displayKey}:</strong> 
                    ${details.total_copies} копій, 
                    <span class="ppo-price">${details.total_price.toFixed(2)} грн</span>
                `);
                formatsList.append(listItem);
                
                totalCopies += details.total_copies;
            }
        }
        
        sessionTotalSpan.html(`${sessionTotal.toFixed(2)} грн <small>(Всього копій: ${totalCopies})</small>`);
        listContainer.show();
    }
    
    // ====================================================================
    // 4. ФУНКЦІЇ ДЛЯ ЛОГІКИ ЗАВАНТАЖЕННЯ (Крок 2)
    // ====================================================================

    function updateCurrentUploadSummary() {
        const selectedFormat = getSelectedFormatValue(); 
        
        if (!selectedFormat) {
            $currentUploadSummarySingle.hide();
            $currentUploadSummaryTotal.hide();
            $sumWarning.hide();
            $submitButton.prop('disabled', true);
            $quantitiesParent.hide();
            return;
        }

        if (selectedFormat) { 
             $quantitiesParent.show(); 
        } else {
             $quantitiesParent.hide();
        }
        
        const formatData = getFullFormatKey(selectedFormat);
        const fullFormatKey = formatData.fullKey;

        const pricePerPhoto = parseFloat(prices[selectedFormat] || 0);
        let currentUploadTotalCopies = 0;
        let currentUploadTotalPrice = 0;
        let currentUploadTotalFiles = accumulatedFiles.files.length; 

        $quantitiesContainer.find('input[type="number"]').each(function() {
            const copies = parseInt($(this).val()) || 1;
            currentUploadTotalCopies += copies;
            currentUploadTotalPrice += copies * pricePerPhoto;
        });
        
        const roundedCurrentUploadTotalPrice = Math.round(currentUploadTotalPrice * 100) / 100;

        const sessionFormatDetails = sessionFormats[fullFormatKey] || { total_price: 0 };
        const existingTotal = Math.round(sessionFormatDetails.total_price * 100) / 100;
        const totalSumForFormatFloat = existingTotal + roundedCurrentUploadTotalPrice;
        
        const roundedTotalSumForFormat = Math.round(totalSumForFormatFloat * 100) / 100;
        
        const hasExistingUploads = existingTotal > 0;

        $currentUploadSum.text(roundedCurrentUploadTotalPrice.toFixed(2));
        $formatTotalSum.text(roundedTotalSumForFormat.toFixed(2));

        if (currentUploadTotalFiles > 0) {
            if (hasExistingUploads) {
                $currentUploadSummarySingle.show();
                $currentUploadSummaryTotal.show();
            } else {
                $currentUploadSummaryTotal.hide();
                $currentUploadSummarySingle.show();
            }
            $quantitiesContainer.show();
        } else {
            $currentUploadSummarySingle.hide();
            $currentUploadSummaryTotal.hide();
        }

        let shouldEnableButton = false;
        
        if (existingTotal >= minSum) {
            shouldEnableButton = currentUploadTotalCopies > 0;
            $sumWarning.hide();
        } else {
            shouldEnableButton = currentUploadTotalCopies > 0 && roundedTotalSumForFormat >= minSum;
            
            if (currentUploadTotalFiles > 0 && roundedTotalSumForFormat < minSum) {
                $sumWarning.show();
            } else {
                $sumWarning.hide();
            }
        }

        $submitButton.prop('disabled', !shouldEnableButton);
    }

    function renderFileQuantities(newFiles = null) {
        // Якщо формат не вибраний, ми не рендеримо, але приймаємо файли (якщо дозволено)
        if (!getSelectedFormatValue() && accumulatedFiles.files.length === 0) { 
             // Якщо немає формату і немає файлів, то дозволяємо завантажувати,
             // але просимо обрати формат. Це буде оброблено в обробнику кліку/drop.
             
        }

        if (newFiles && newFiles.length > 0) {
            for (let i = 0; i < newFiles.length; i++) {
                accumulatedFiles.items.add(newFiles[i]);
            }
            $hiddenFileInput[0].files = accumulatedFiles.files;
        }

        $quantitiesContainer.empty();
        const currentFiles = accumulatedFiles.files;
        const maxFiles = maxFilesPerUpload; 
        
        $quantitiesParent.show(); 
        $quantitiesContainer.show();

        if (currentFiles.length === 0) {
            const $link = $('<p>')
                .attr('id', 'ppo-add-photos-link')
                .addClass('ppo-add-photos-link')
                .css({
                    'text-align': 'center', 'color': '#0073aa', 'cursor': 'pointer', 
                    'text-decoration': 'underline', 'font-weight': 'bold', 'padding': '10px 0'
                })
                .text('Натисніть тут, щоб додати фото (або перетягніть файли сюди)');
            
            $quantitiesContainer.append($link);
            
            updateCurrentUploadSummary();
            toggleFormatOptionsVisibility(); 
            return;
        }

        let addLinkText = `Натисніть тут, щоб додати ще фото (додано ${currentFiles.length} з ${maxFiles})`;
        
        const $addMoreLink = $('<p>')
            .attr('id', 'ppo-add-photos-link')
            .addClass('ppo-add-photos-link')
            .html(currentFiles.length >= maxFiles ? `Максимум файлів досягнуто (${currentFiles.length})` : addLinkText)
            .css({
                'text-align': 'center',
                'color': currentFiles.length >= maxFiles ? '#ccc' : '#0073aa',
                'cursor': currentFiles.length >= maxFiles ? 'default' : 'pointer',
                'text-decoration': currentFiles.length >= maxFiles ? 'none' : 'underline',
                'font-weight': 'bold',
                'padding': '10px 0'
            }); 
            
        $.each(currentFiles, function(i, file) {
            const $item = $('<div class="photo-item">');
            
             const $thumbContainer = $('<div class="photo-thumbnail-container">');
            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    $thumbContainer.html('<img src="' + e.target.result + '" alt="Мініатюра">');
                };
                reader.readAsDataURL(file);
            } else {
                $thumbContainer.html('📄');
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

        $quantitiesContainer.append($addMoreLink);

        updateCurrentUploadSummary();
        toggleFormatOptionsVisibility(); 
    }
    
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
    
    $quantitiesContainer.on('click', '#ppo-add-photos-link', function(e) {
        e.preventDefault();
        
        const currentFiles = accumulatedFiles.files;
        const maxFiles = maxFilesPerUpload; 

        const selectedFormat = getSelectedFormatValue(); 

        if (!selectedFormat) {
            displayMessage('Будь ласка, спочатку оберіть формат фото.', 'warning');
            return;
        }

        if (currentFiles.length < maxFiles) {
            $hiddenFileInput.click();
        } else {
             displayMessage(`Максимум файлів досягнуто (${currentFiles.length}). Будь ласка, збережіть замовлення або видаліть деякі фото.`, 'warning');
        }
    });
    
    function handleOptionChange() {
        // При зміні паперу/рамки скидаємо вибір формату, щоб примусити користувача обрати формат
        $('input[name="format"]').prop('checked', false); 
        
        accumulatedFiles = new DataTransfer();
        $hiddenFileInput[0].files = accumulatedFiles.files;
        
        $quantitiesContainer.empty();
        const $warningLink = $('<p>')
             .attr('id', 'ppo-add-photos-link')
             .addClass('ppo-add-photos-link')
             .css({'text-align': 'center', 'color': '#cc0000', 'font-weight': 'bold', 'padding': '10px 0'})
             .text('УВАГА! Опції змінено. Оберіть формат та додайте фото заново.');
        
        $quantitiesContainer.html($warningLink).show(); 
        $quantitiesParent.hide();
        
        updateCurrentUploadSummary();
        displayMessage('Вибір опцій впливає на назву папки. Будь ласка, оберіть формат та додайте фото заново.', 'warning');
        toggleFormatOptionsVisibility();
    }

    $finishOptions.on('change', handleOptionChange);
    $frameOptions.on('change', handleOptionChange);

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
        
        const selectedFormat = getSelectedFormatValue(); 
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

    $('input[name="format"]').on('change', function() { 
        accumulatedFiles = new DataTransfer();
        $hiddenFileInput[0].files = accumulatedFiles.files;
        
        renderFileQuantities(); 

        const selectedFormat = getSelectedFormatValue(); 
        
        if (selectedFormat) {
            $quantitiesParent.show();
        } else {
            $quantitiesParent.hide();
        }
        
        updateCurrentUploadSummary(); 
        toggleFormatOptionsVisibility();
    });

    $hiddenFileInput.on('change', function() { 
        const selectedFormat = getSelectedFormatValue(); 
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
    
    $clearFormButton.on('click', function(e) {
        e.preventDefault();
        
        // ВСТАНОВЛЕННЯ ДЕФОЛТНИХ ОПЦІЙ (Глянець, Без рамки)
        $('#paper-g').prop('checked', true); 
        $('#frame-none').prop('checked', true); 
        
        // Скидаємо вибір формату
        $('input[name="format"]').prop('checked', false); 
        
        accumulatedFiles = new DataTransfer();
        $hiddenFileInput[0].files = accumulatedFiles.files;
        
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
        
        const selectedFormat = getSelectedFormatValue(); 
        
        // Додаткова перевірка, якщо опції паперу чи рамки не вибрані (хоча дефолтні мають бути)
        const rawPaper = $('input[name="paper"]:checked').val();
        const rawFrame = $('input[name="frame"]:checked').val();
        if (!rawPaper || !rawFrame) {
            displayMessage('Будь ласка, оберіть тип паперу та опцію рамки.', 'error');
            return;
        }
        
        const formatData = getFullFormatKey(selectedFormat);
        const fullFormatKey = formatData.fullKey;

        // Перевірка мінімальної суми 
        const pricePerPhoto = parseFloat(prices[selectedFormat] || 0);
        let currentUploadTotalPrice = 0;
        
        $quantitiesContainer.find('input[type="number"]').each(function() {
            const copies = parseInt($(this).val()) || 1;
            currentUploadTotalPrice += copies * pricePerPhoto;
        });
        
        const roundedCurrentUploadTotalPrice = Math.round(currentUploadTotalPrice * 100) / 100;
        const sessionFormatDetails = sessionFormats[fullFormatKey] || { total_price: 0 };
        
        const existingTotal = Math.round(sessionFormatDetails.total_price * 100) / 100;

        if (existingTotal < minSum) {
            const roundedTotalSumForFormat = Math.round((existingTotal + roundedCurrentUploadTotalPrice) * 100) / 100;

            if (roundedTotalSumForFormat < minSum) {
                displayMessage('Недостатня сума! Додайте більше фото або копій, щоб досягти мінімуму ' + minSum + ' грн.', 'error');
                $submitButton.prop('disabled', false).text('Зберегти замовлення');
                $clearFormButton.prop('disabled', false);
                return;
            }
        }

        $loader.show();
        $submitButton.prop('disabled', true).text('Завантаження...');
        $clearFormButton.prop('disabled', true);
        clearMessages();

        $progressContainer.show();
        $progressFill.width('0%').removeClass('processing'); 
        $progressText.text('0%').removeClass('processing-text');
        
        // Складання FormData
        const formData = new FormData();
        
        formData.append('action', 'ppo_file_upload');
        formData.append('ppo_ajax_nonce', nonce);
        
        // Надсилаємо на бек-енд перетворені старі ключі
        formData.append('format', selectedFormat); 
        formData.append('paper', formatData.paper); 
        formData.append('frame', formatData.frame); 
        
        for (let i = 0; i < accumulatedFiles.files.length; i++) { 
             formData.append('photos[]', accumulatedFiles.files[i]);
        }
        
        const copiesArray = [];
        $quantitiesContainer.find('input[type="number"]').each(function() {
             copiesArray.push($(this).val());
        });
        formData.append('copies', JSON.stringify(copiesArray));
        
        
        // AJAX-запит
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

                xhr.upload.addEventListener("progress", function(evt) {
                    if (evt.lengthComputable) {
                        const percentComplete = Math.round((evt.loaded / evt.total) * 100);
                        $progressFill.width(percentComplete + '%');
                        $progressText.text(percentComplete + '%');

                        if (percentComplete >= 100 && !uploadComplete) {
                            uploadComplete = true;
                            $progressFill.width('100%').addClass('processing'); 
                            $progressText.text('Завантажено! Обробка на сервері...').addClass('processing-text');
                        }
                    }
                }, false);
                return xhr;
            },
            success: function(response) {
                $loader.hide();
                $progressContainer.hide();
                
                accumulatedFiles = new DataTransfer();
                $hiddenFileInput[0].files = accumulatedFiles.files;
                $quantitiesContainer.empty();
                
                // Скидаємо вибір формату
                $('input[name="format"]').prop('checked', false); 
                
                // ВСТАНОВЛЕННЯ ДЕФОЛТНИХ ОПЦІЙ (Глянець, Без рамки)
                $('#paper-g').prop('checked', true);
                $('#frame-none').prop('checked', true);

                if (response.success) {
                    sessionFormats = response.data.formats; 
                    sessionTotal = parseFloat(response.data.total) || 0;
                    updateSummaryList();

                    showModal(response.data.message || 'Фото успішно додано до замовлення! Додайте ще фото або оформіть доставку.');
                } else {
                    displayMessage(response.data.message || 'Виникла помилка при обробці файлів.', 'error');
                }
                
                $currentUploadSum.text('0.00'); 
                $formatTotalSum.text('0.00'); 
                $currentUploadSummarySingle.hide();
                $currentUploadSummaryTotal.hide();
                $quantitiesParent.hide();

                $submitButton.prop('disabled', false).text('Зберегти замовлення');
                $clearFormButton.prop('disabled', false);
                updateCurrentUploadSummary();
                toggleFormatOptionsVisibility();
            },
            error: function(xhr, status, error) {
                $loader.hide();
                $progressContainer.hide();
                const errorMessage = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message 
                                     ? xhr.responseJSON.data.message 
                                     : 'Помилка сервера: ' + status + ' ' + error + '. Перевірте консоль.';
                displayMessage(errorMessage, 'error');

                $submitButton.prop('disabled', false).text('Зберегти замовлення');
                $clearFormButton.prop('disabled', false);

                accumulatedFiles = new DataTransfer();
                $hiddenFileInput[0].files = accumulatedFiles.files;
                
                renderFileQuantities();
                updateCurrentUploadSummary();
                toggleFormatOptionsVisibility();
            }
        });
    });

    // ====================================================================
    // 6. ІНІЦІАЛІЗАЦІЯ (ПРИ ЗАВАНТАЖЕННІ СТОРІНКИ)
    // ====================================================================
    
    // Скидаємо вибір формату
    $('input[name="format"]').prop('checked', false); 

    // ВСТАНОВЛЕННЯ ДЕФОЛТНИХ ОПЦІЙ (Глянець, Без рамки)
    $('#paper-g').prop('checked', true);
    $('#frame-none').prop('checked', true);

    if (!getSelectedFormatValue()) {
        $quantitiesParent.hide(); 
    }
    updateCurrentUploadSummary(); 
    updateSummaryList();
    toggleFormatOptionsVisibility();
});