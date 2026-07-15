/* WC WhatsApp Order Manager – Order Tracking JS */
(function ($) {
    'use strict';

    $('#wcwom-track-form').on('submit', function (e) {
        e.preventDefault();

        var $form    = $(this);
        var $btn     = $('#wcwom-track-btn');
        var $text    = $btn.find('.wcwom-btn-text');
        var $loader  = $btn.find('.wcwom-btn-loader');
        var $result  = $('#wcwom-track-result');

        var order_id = $('#wcwom_order_id').val().trim();
        var email    = $('#wcwom_email').val().trim();

        if (!order_id || !email) {
            $result.show().html('<div class="wcwom-error">⚠️ Please fill in all fields.</div>');
            return;
        }

        $btn.prop('disabled', true);
        $text.hide();
        $loader.show();
        $result.hide().html('');

        $.post(wcwomTrack.ajax_url, {
            action:   'wcwom_track',
            nonce:    wcwomTrack.nonce,
            order_id: order_id,
            email:    email
        }, function (res) {
            $btn.prop('disabled', false);
            $text.show();
            $loader.hide();
            $result.show();

            if (res.success) {
                $result.html(res.data.html);
            } else {
                var msg = (res.data && res.data.message) ? res.data.message : wcwomTrack.error;
                $result.html('<div class="wcwom-error">❌ ' + msg + '</div>');
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $text.show();
            $loader.hide();
            $result.show().html('<div class="wcwom-error">❌ ' + wcwomTrack.error + '</div>');
        });
    });

})(jQuery);
