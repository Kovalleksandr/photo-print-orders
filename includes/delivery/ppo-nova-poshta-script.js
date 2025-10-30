// includes/delivery/ppo-nova-poshta-script.js

jQuery(document).ready(function($) {
    var $cityInput = $('#np-city-name');
    var $cityRef = $('#np-city-ref');
    var $cityHiddenName = $('#np-city-name-hidden');
    var $warehouseInput = $('#np-warehouse-name');
    var $warehouseRef = $('#np-warehouse-ref');
    var $saveBtn = $('#save-delivery-btn');

    // ----------------------------------------------------
    // 1. Autocomplete для Населеного пункту (Міста) - ЧИСТА ВЕРСІЯ
    // ----------------------------------------------------
    $cityInput.autocomplete({
        minLength: 2,
        source: function(request, response) {
            $cityRef.val('');
            $cityHiddenName.val('');
            $warehouseInput.val('').prop('disabled', true);
            $warehouseRef.val('');
            $saveBtn.prop('disabled', true); 

            $.ajax({
                url: ppo_np_ajax.ajaxurl,
                type: 'post',
                dataType: 'json',
                data: {
                    action: 'ppo_np_search_settlements',
                    action_type: 'searchSettlements',
                    nonce: ppo_np_ajax.nonce,
                    term: request.term 
                },
                success: function(data) {
                    response(data.success === false ? [] : data);
                },
                error: function(xhr, status, error) {
                    response([]);
                }
            });
        },
        select: function(event, ui) {
            $cityRef.val(ui.item.value); 
            $cityHiddenName.val(ui.item.city_name); 
            $cityInput.val(ui.item.city_name); 

            // Після вибору міста активуємо поле відділення
            $warehouseInput.val('').prop('disabled', false).focus();
            
            return false;
        }
    });

    // ----------------------------------------------------
    // 2. Autocomplete для Відділення - ДІАГНОСТИКА
    // ----------------------------------------------------
    $warehouseInput.autocomplete({
        minLength: 1,
        source: function(request, response) {
            var cityRef = $cityRef.val();
            
            // --- ЛОГ 1: Перевіряємо, чи отримали Ref міста ---
            console.log('--- Warehouse Search Start ---');
            console.log('1. City Ref, що надсилається:', cityRef);
            console.log('2. Пошуковий термін:', request.term);

            if (!cityRef) {
                console.error('Warehouse Search Failed: City Ref порожній. Перевірте, чи коректно вибрано місто.');
                response([]);
                return;
            }

            $warehouseRef.val(''); 
            $saveBtn.prop('disabled', true); 

            $.ajax({
                url: ppo_np_ajax.ajaxurl,
                type: 'post',
                dataType: 'json',
                data: {
                    action: 'ppo_np_get_divisions',
                    action_type: 'getWarehouses',
                    nonce: ppo_np_ajax.nonce,
                    city_ref: cityRef,
                    term: request.term
                },
                success: function(data) {
                    // --- ЛОГ 2: Перевіряємо дані, що прийшли з сервера ---
                    console.log('3. Warehouse AJAX Success. Отримані дані:', data);

                    if (data.success === false) {
                        response([]);
                    } else {
                        // Додаємо перевірку на порожній масив, хоча успіх
                        if (Array.isArray(data) && data.length > 0) {
                             response(data);
                        } else {
                             console.log('4. Warehouse Search Result: Порожній масив (немає відділень або неправильний формат).');
                             response([]);
                        }
                    }
                },
                error: function(xhr, status, error) {
                    // --- ЛОГ 3: Помилка AJAX ---
                    console.error("4. AJAX Error (Warehouse Search):", status, error);
                    response([]);
                }
            });
        },
        select: function(event, ui) {
            // --- ЛОГ 4: Перевіряємо вибраний об'єкт ---
            console.log('5. Warehouse Selected Item:', ui.item);
            
            $warehouseRef.val(ui.item.value);
            $(this).val(ui.item.label);
            $saveBtn.prop('disabled', false);
            
            return false;
        }
    });
});