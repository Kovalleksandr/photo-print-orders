jQuery(document).ready(function($) {

    // --- 1. ГЛОБАЛЬНІ ЗМІННІ ---
    const ajax_url = ppo_ajax_object.ajax_url;
    const nonce = ppo_ajax_object.nonce;
    const prices = ppo_ajax_object.prices;
    const min_sum = ppo_ajax_object.min_sum;
    const max_files = 20;

    // Сховище для файлів, обраних у поточному завантаженні (перед відправкою на сервер)
    let currentFiles = [];

    // --- 2. ФУНКЦІЇ ДЛЯ ОБЧИСЛЕННЯ ТА ВІДОБРАЖЕННЯ ---

    /**
     * Генерує HTML-розмітку для одного файлу в списку.
     * @param {string} fileName - Оригінальне ім'я файлу.
     * @param {number} index - Індекс файлу в масиві currentFiles.
     * @param {string} fileURL - URL для відображення мініатюри (створюється локально).
     * @returns {string} HTML-рядок.
     */
    function renderPhotoItem(fileName, index, fileURL) {
        // Унікальний ID для елементів, щоб відстежувати їх для видалення/редагування
        const uniqueId = `file-${index}`;
        
        // Використовуємо `<div class="photo-thumbnail-container">` з CSS, визначеним у PHP
        return `
            <div class="photo-item" data-index="${index}" id="${uniqueId}">
                <div style="display: flex; align-items: center; width: 65%;">
                    <div class="photo-thumbnail-container">
                        <img src="${fileURL}" alt="Мініатюра ${fileName}" loading="lazy">
                    </div>
                    <label title="${fileName}">${fileName}</label>
                </div>
                <div style="display: flex; align-items: center;">
                    <span style="white-space: nowrap;">Кількість:</span>
                    <input type="number" 
                           class="file-copies" 
                           data-index="${index}" 
                           value="1" 
                           min="1" 
                           required>
                    <button type="button" class="remove-file-btn ppo-button-secondary" data-index="${index}" style="margin-left: 10px; padding: 4px 8px;">
                        X
                    </button>
                </div>
            </div>
        `;
    }

    /**
     * Перераховує загальну суму та копії для поточного завантаження.
     */
    function recalculateSums() {
        const format = $('#format').val();
        const pricePerPhoto = prices[format] || 0;
        let currentUploadSum = 0;
        let currentUploadCopies = 0;
        const sessionTotal = ppo_ajax_object.session_total || 0;
        let formatSessionTotal = 0;

        // Отримуємо поточну суму для обраного формату в сесії
        if (ppo_ajax_object.session_formats[format]) {
            formatSessionTotal = ppo_ajax_object.session_formats[format].total_price;
        }

        // Ітеруємо по всіх полях кількості копій у формі
        $('.file-copies').each(function() {
            const copies = parseInt($(this).val()) || 0;
            const filePrice = copies * pricePerPhoto;
            
            currentUploadCopies += copies;
            currentUploadSum += filePrice;
        });

        const newFormatTotal = formatSessionTotal + currentUploadSum;
        
        // Оновлення полів на сторінці
        $('#current-upload-sum').text(currentUploadSum.toFixed(0));
        $('#format-total-sum').text(newFormatTotal.toFixed(0));

        // Перевірка на мінімальну суму
        if (newFormatTotal < min_sum && currentUploadSum > 0) {
            $('#sum-warning').show();
            $('#submit-order').prop('disabled', true);
        } else {
            $('#sum-warning').hide();
            // Кнопка відправки активна, якщо є файли
            $('#submit-order').prop('disabled', currentUploadSum === 0);
        }
        
        return { currentUploadSum, newFormatTotal };
    }
    
    /**
     * Оновлює підсумки сесії після успішної AJAX-відповіді.
     * @param {object} updatedData - Дані, отримані з сервера.
     */
    function updateSessionSummary(updatedData) {
        const $formatsList = $('#ppo-formats-list');
        $formatsList.empty();
        
        let totalCopiesOverall = 0;
        
        // Оновлюємо глобальні дані JS
        ppo_ajax_object.session_formats = updatedData.formats;
        ppo_ajax_object.session_total = updatedData.total;
        
        for (const format in updatedData.formats) {
            if (updatedData.formats.hasOwnProperty(format)) {
                const details = updatedData.formats[format];
                $formatsList.append(`
                    <li>${format}: ${details.total_copies} копій, ${details.total_price} грн</li>
                `);
                totalCopiesOverall += details.total_copies;
            }
        }
        
        $('#ppo-session-total').html(
            `${updatedData.total.toFixed(0)} грн <small>(Всього копій: ${totalCopiesOverall})</small>`
        );
        $('#ppo-formats-list-container').show();
    }


    // --- 3. ОБРОБНИКИ ПОДІЙ ---

    // 3.1. Вибір файлів (показуємо список та мініатюри)
    $('#photos').on('change', function(e) {
        const files = e.target.files;
        const $quantitiesDiv = $('#photo-quantities');
        $quantitiesDiv.empty();
        currentFiles = []; // Очищуємо попередній список

        if (files.length > max_files) {
             alert(`Ви можете завантажити максимум ${max_files} файлів за раз.`);
             this.value = ''; // Скидаємо вибрані файли
             currentFiles = [];
             recalculateSums();
             return;
        }

        if (files.length > 0) {
             $quantitiesDiv.append('<h4>Список обраних фото</h4>');
        } else {
             $quantitiesDiv.append('<p style="text-align: center; color: #666;">Виберіть формат та фото для відображення списку.</p>');
        }
        
        for (let i = 0; i < files.length; i++) {
            const file = files[i];
            
            // Створюємо локальний URL для відображення мініатюри
            const fileURL = URL.createObjectURL(file);
            
            // Зберігаємо файл у тимчасовому масиві
            currentFiles.push(file);

            // Генеруємо HTML-елемент
            $quantitiesDiv.append(renderPhotoItem(file.name, i, fileURL));
        }

        recalculateSums();
    });

    // 3.2. Вибір формату або зміна кількості копій (перерахунок)
    $('#format, #photo-quantities').on('change', '.file-copies, #format', function() {
        // Якщо файли не вибрано, просто деактивуємо кнопку та ховаємо попередження
        if (currentFiles.length === 0) {
            $('#submit-order').prop('disabled', true);
            $('#sum-warning').hide();
            $('#current-upload-sum').text(0);
            return;
        }
        recalculateSums();
    });
    
    // 3.3. Видалення файлу зі списку
    $('#photo-quantities').on('click', '.remove-file-btn', function() {
        const indexToRemove = parseInt($(this).data('index'));
        
        // 1. Видалення з DOM
        $(`#file-${indexToRemove}`).remove();
        
        // 2. Видалення з масиву currentFiles (використовуємо splice)
        if (indexToRemove > -1) {
            currentFiles.splice(indexToRemove, 1);
        }
        
        // 3. Перерахунок індексів та перемалювання списку
        const $quantitiesDiv = $('#photo-quantities');
        $quantitiesDiv.empty();
        
        if (currentFiles.length > 0) {
            currentFiles.forEach((file, newIndex) => {
                const fileURL = URL.createObjectURL(file);
                 $quantitiesDiv.append(renderPhotoItem(file.name, newIndex, fileURL));
            });
        } else {
             $quantitiesDiv.append('<p style="text-align: center; color: #666;">Список пустий. Додайте фото.</p>');
        }

        recalculateSums();
    });
    
    // 3.4. Очищення форми
    $('#clear-form').on('click', function() {
        $('#photo-print-order-form')[0].reset();
        $('#photo-quantities').empty().append('<p style="text-align: center; color: #666;">Виберіть формат та фото для відображення списку.</p>');
        currentFiles = [];
        ppo_ajax_object.session_total = array_sum_of_format_prices(ppo_ajax_object.session_formats); // Повертаємо загальну суму сесії
        recalculateSums();
    });
    
    // 3.5. Відправка форми через AJAX
    $('#photo-print-order-form').on('submit', function(e) {
        e.preventDefault();

        const format = $('#format').val();
        
        if (!format || currentFiles.length === 0) {
            alert('Будь ласка, виберіть формат та додайте фотографії.');
            return;
        }
        
        // Перевіряємо мінімальну суму перед відправкою
        const { newFormatTotal } = recalculateSums();
        if (newFormatTotal < min_sum) {
            alert(`Загальна сума для формату ${format} повинна бути не менше ${min_sum} грн. Додайте ще копій або фото.`);
            return;
        }
        
        $('#submit-order').prop('disabled', true);
        $('#ppo-loader').show();

        // Формуємо масив кількості копій для відправки на сервер
        const copiesArray = $('.file-copies').map(function() {
            return parseInt($(this).val());
        }).get();

        // Створюємо FormData для відправки файлів
        const formData = new FormData();
        formData.append('action', 'ppo_file_upload');
        formData.append('ppo_ajax_nonce', nonce);
        formData.append('format', format);
        formData.append('order_id', $('#order_id_input').val());
        // Надсилаємо масив кількості копій у JSON-форматі
        formData.append('copies', JSON.stringify(copiesArray)); 
        
        // Додаємо файли з нашого масиву currentFiles
        currentFiles.forEach((file, index) => {
             // Використовуємо спільне ім'я масиву 'photos[]'
             formData.append('photos[]', file, file.name); 
        });

        $.ajax({
            url: ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                $('#ppo-loader').hide();
                if (response.success) {
                    $('#ppo-alert-messages').html(`
                        <div class="ppo-message ppo-message-success">
                            <p>${response.data.message}</p>
                        </div>
                    `);
                    // Оновлення блоку підсумків сесії
                    updateSessionSummary(response.data);
                    
                    // Очищення форми та списку файлів після успішного завантаження
                    $('#clear-form').click();
                    
                } else {
                    $('#submit-order').prop('disabled', false);
                    $('#ppo-alert-messages').html(`
                        <div class="ppo-message ppo-message-error">
                            <p>Помилка: ${response.data.message}</p>
                        </div>
                    `);
                }
            },
            error: function(xhr, status, error) {
                $('#ppo-loader').hide();
                $('#submit-order').prop('disabled', false);
                console.error("AJAX Error:", status, error);
                $('#ppo-alert-messages').html(`
                    <div class="ppo-message ppo-message-error">
                        <p>Виникла помилка зв'язку з сервером. (${xhr.status})</p>
                    </div>
                `);
            }
        });
    });
    
    // --- 4. ДОПОМІЖНІ ФУНКЦІЇ ---

    // Функція для перерахунку загальної суми для JS (якщо потрібен скидання форми)
    function array_sum_of_format_prices(formats) {
        let total = 0;
        for (const format in formats) {
            if (formats.hasOwnProperty(format)) {
                 total += formats[format].total_price;
            }
        }
        return total;
    }

    // --- 5. ІНІЦІАЛІЗАЦІЯ ПІД ЧАС ЗАВАНТАЖЕННЯ СТОРІНКИ ---
    
    // Переконаємося, що загальна сума відображається коректно при завантаженні (якщо є сесія)
    if (ppo_ajax_object.session_total > 0) {
        $('#ppo-formats-list-container').show();
    }
});