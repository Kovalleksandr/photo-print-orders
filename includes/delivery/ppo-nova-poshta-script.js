// includes/delivery/ppo-nova-poshta-script.js

jQuery(document).ready(function($) {
    var $cityInput = $('#np-city-name');
    var $cityRef = $('#np-city-ref');
    var $cityHiddenName = $('#np-city-name-hidden');
    var $warehouseInput = $('#np-warehouse-name');
    var $warehouseRef = $('#np-warehouse-ref');

    // ----------------------------------------------------
    // 1. Autocomplete для Населеного пункту (Міста)
    // ----------------------------------------------------
    $cityInput.autocomplete({
        minLength: 2,
        source: function(request, response) {
            $cityRef.val(''); // Очищаємо Ref при введенні нового тексту
            $warehouseInput.val('').prop('disabled', true); // Вимикаємо поле відділення
            $warehouseRef.val('');

            $.ajax({
                url: ppo_np_ajax.ajaxurl,
                type: 'post',
                dataType: 'json',
                data: {
                    action: 'ppo_np_search_settlements', // Хук, визначений у PHP
                    action_type: 'searchSettlements',   // Внутрішній тип дії
                    nonce: ppo_np_ajax.nonce,
                    term: request.term 
                },
                success: function(data) {
                    if (data.success === false) {
                         // Обробка помилки від PHP (наприклад, API Error)
                         response([]);
                         console.error('NP City Search Error:', data.data.details);
                    } else {
                        response(data);
                    }
                },
                error: function(xhr, status, error) {
                    console.error("AJAX Error (City Search):", status, error);
                    response([]);
                }
            });
        },
        select: function(event, ui) {
            // Встановлюємо Ref та назву міста
            $cityRef.val(ui.item.value); 
            $cityHiddenName.val(ui.item.city_name); 

            // Дозволяємо пошук відділень
            $warehouseInput.prop('disabled', false).focus();
            
            // Відображаємо label, а не value
            $(this).val(ui.item.city_name); 

            return false;
        }
    });

    // ----------------------------------------------------
    // 2. Autocomplete для Відділення
    // ----------------------------------------------------
    $warehouseInput.autocomplete({
        minLength: 1,
        source: function(request, response) {
            var cityRef = $cityRef.val();
            
            // Якщо Ref міста не вибраний, пошук відділень неможливий
            if (!cityRef) {
                $warehouseInput.val('Спочатку виберіть населений пункт');
                response([]);
                return;
            }

            $warehouseRef.val(''); // Очищаємо Ref відділення при введенні нового тексту

            $.ajax({
                url: ppo_np_ajax.ajaxurl,
                type: 'post',
                dataType: 'json',
                data: {
                    action: 'ppo_np_get_divisions', // Хук, визначений у PHP
                    action_type: 'getWarehouses',    // Внутрішній тип дії
                    nonce: ppo_np_ajax.nonce,
                    city_ref: cityRef,
                    term: request.term // Додатковий термін для фільтрації на сервері (або клієнті)
                },
                success: function(data) {
                    if (data.success === false) {
                        response([]);
                        console.error('NP Warehouse Search Error:', data.data.details);
                    } else {
                        response(data);
                    }
                },
                error: function(xhr, status, error) {
                    console.error("AJAX Error (Warehouse Search):", status, error);
                    response([]);
                }
            });
        },
        select: function(event, ui) {
            // Встановлюємо Ref відділення
            $warehouseRef.val(ui.item.value);

            // Відображаємо повну адресу у полі
            $(this).val(ui.item.label);
            
            // Тут можна активувати кнопку відправки форми, якщо потрібно
            
            return false;
        }
    });

    // Додаємо обробник для відображення назви міста, а не Ref після Autocomplete
    $cityInput.on('autocompleteselect', function(event, ui) {
        $(this).val(ui.item.city_name);
        return false;
    });
});