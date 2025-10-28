// Оновлений includes\delivery\ppo-nova-poshta-script.js
// Додано fallback для полів v2.0 (Present як Description, але оскільки API нормалізує, зміни мінімальні)
jQuery(document).ready(function($) {
    var ajaxurl = ppo_ajax.ajaxurl;
    var nonce = ppo_ajax.nonce;

    // Автокомпліт міста
    $('#ppo_np_city').autocomplete({
        source: function(request, response) {
            $.post(ajaxurl, {
                action: 'ppo_np_search_settlements',
                query: request.term,
                nonce: nonce
            }, function(data) {
                if (data.success) {
                    response($.map(data.data, function(item) {
                        return {
                            label: item.Description || item.Present || item.name,  // Fallback для v2.0
                            value: item.Description || item.Present || item.name,
                            ref: item.Ref || item.ref
                        };
                    }));
                }
            });
        },
        select: function(event, ui) {
            $('#ppo_np_city_ref').val(ui.item.ref);
            $('#ppo_np_street').val('');
            $('#ppo_np_division').html('<option value="">Оберіть відділення</option>');
            loadDivisions(ui.item.ref);
        }
    });

    // Автокомпліт вулиці
    $('#ppo_np_street').autocomplete({
        source: function(request, response) {
            var cityRef = $('#ppo_np_city_ref').val();
            if (!cityRef) return;
            $.post(ajaxurl, {
                action: 'ppo_np_search_streets',
                settlement_ref: cityRef,
                query: request.term,
                nonce: nonce
            }, function(data) {
                if (data.success) {
                    response($.map(data.data, function(item) {
                        return {
                            label: item.Description || item.Present || item.description,
                            value: item.Description || item.Present || item.description,
                            ref: item.Ref || item.ref
                        };
                    }));
                }
            });
        },
        select: function(event, ui) {
            $('#ppo_np_street_ref').val(ui.item.ref);
        }
    }).on('focus', function() {
        if (!$('#ppo_np_city_ref').val()) {
            alert('Спочатку оберіть місто');
            $(this).blur();
        }
    });

    // Завантаження відділень
    function loadDivisions(cityRef) {
        $.post(ajaxurl, {
            action: 'ppo_np_get_divisions',
            settlement_ref: cityRef,
            category: 'PostBranch',
            nonce: nonce
        }, function(data) {
            if (data.success) {
                var select = $('#ppo_np_division');
                select.html('<option value="">Оберіть відділення</option>');
                $.each(data.data, function(i, item) {
                    var option = $('<option>').val(item.Ref || item.ref).attr('data-id', item.id || item.SiteKey).text((item.number || item.SiteKey || '') + ' - ' + (item.Description || item.name || '') + ' (' + (item.address || item.ShortAddress || '') + ')');
                    select.append(option);
                });
            } else {
                alert('Помилка завантаження відділень: ' + (data.error || 'Unknown'));
            }
        });
    }

    // Збереження division_id
    $('#ppo_np_division').change(function() {
        var selected = $(this).find('option:selected');
        $('#ppo_np_division_id').val(selected.data('id'));
    });
});