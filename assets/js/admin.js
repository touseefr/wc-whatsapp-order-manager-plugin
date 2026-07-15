/* WC WhatsApp Order Manager – Admin JS */
(function ($) {
    'use strict';

    /* ── Toggle API sections when radio changes ── */
    function toggleApiSections() {
        var selected = $('input[name="wcwom_wa_api"]:checked').val();
        $('.wcwom-api-section').hide();
        if (selected === 'twilio')    $('#wcwom-api-twilio').show();
        if (selected === 'callmebot') $('#wcwom-api-callmebot').show();
    }

    $('input[name="wcwom_wa_api"]').on('change', toggleApiSections);
    toggleApiSections(); /* run on load */

    /* ── Send Test Message ── */
    $('#wcwom-send-test').on('click', function () {
        var $btn    = $(this);
        var $result = $('#wcwom-test-result');
        var phone   = $('#wcwom_test_phone').val().trim();

        if (!phone) {
            $result.removeClass('success').addClass('error').text('Please enter a phone number.');
            return;
        }

        $btn.prop('disabled', true).text('Sending…');
        $result.removeClass('success error').text('');

        $.post(wcwomAdmin.ajax_url, {
            action:  'wcwom_send_test',
            nonce:   wcwomAdmin.nonce,
            phone:   phone,
            message: '👋 Test from WC WhatsApp Order Manager! Your configuration is working correctly.'
        }, function (res) {
            $btn.prop('disabled', false).text('📱 Send Test');
            if (res.success) {
                if (res.data && res.data.link) {
                    $result.removeClass('error').addClass('success')
                           .html('✅ ' + res.data.message + ' <a href="' + res.data.link + '" target="_blank">Open WhatsApp →</a>');
                } else {
                    $result.removeClass('error').addClass('success').text('✅ ' + (res.data.message || 'Sent!'));
                }
            } else {
                $result.removeClass('success').addClass('error')
                       .text('❌ ' + (res.data && res.data.message ? res.data.message : 'Error sending message.'));
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('📱 Send Test');
            $result.removeClass('success').addClass('error').text('❌ Request failed. Check your internet connection.');
        });
    });

})(jQuery);
