/**
 * Centromex Delivery - Admin JavaScript
 */

(function($) {
    'use strict';

    // Enable/disable Mark Ready button based on zone selection
    $(document).on('change', '.zone-select', function() {
        var $card = $(this).closest('.order-card');
        var $btn = $card.find('.mark-ready-btn');
        $btn.prop('disabled', !$(this).val());
    });

    // Mark order as ready
    $(document).on('click', '.mark-ready-btn', function(e) {
        e.preventDefault();

        var $btn = $(this);
        var $card = $btn.closest('.order-card');
        var orderId = $card.data('order-id');
        var zone = $card.find('.zone-select').val();
        var bagCount = $card.find('.bag-count').val() || 1;

        if (!zone) {
            alert('Please select a zone');
            return;
        }

        $btn.prop('disabled', true).text('Saving...');

        $.ajax({
            url: centromexDelivery.ajaxUrl,
            type: 'POST',
            data: {
                action: 'centromex_mark_ready',
                nonce: centromexDelivery.nonce,
                order_id: orderId,
                zone: zone,
                bag_count: bagCount
            },
            success: function(response) {
                if (response.success) {
                    // Move card to Ready column or reload
                    location.reload();
                } else {
                    alert(response.data.message || 'Error marking order as ready');
                    $btn.prop('disabled', false).text('Mark Ready');
                }
            },
            error: function() {
                alert('Error connecting to server');
                $btn.prop('disabled', false).text('Mark Ready');
            }
        });
    });

})(jQuery);
