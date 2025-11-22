/* global ajaxurl */
jQuery(function ($) {
    const $levelInputs = $('.npmp-newsletter-level');
    const $allCheckbox = $('.npmp-newsletter-all');
    const $specificCheckboxes = $('.npmp-newsletter-specific');
    const $specificLevelsContainer = $('.npmp-newsletter-specific-levels');
    const $preheader = $('#npmp-newsletter-preheader');
    const $audienceLabel = $('.npmp-newsletter-audience-label');

    const gatherAudience = () => {
        return {
            levels: $levelInputs.filter(':checked').map((_, el) => $(el).val()).get(),
            preheader: $preheader.length ? $preheader.val() : ''
        };
    };

    const updateAudienceLabel = () => {
        if (!$audienceLabel.length) {
            return;
        }

        const payload = gatherAudience();

        if (!payload.levels.length || payload.levels.includes('__all__')) {
            $audienceLabel.text($audienceLabel.data('default') || '');
            return;
        }

        const names = [];
        $levelInputs.filter(':checked').not('.npmp-newsletter-all').each(function () {
            const label = $(this).data('label');
            if (label) {
                names.push(label);
            }
        });

        $audienceLabel.text(names.join(', '));
    };

    // Handle "All Members" checkbox interaction
    $allCheckbox.on('change', function() {
        if ($(this).is(':checked')) {
            // Disable and uncheck specific level checkboxes
            $specificCheckboxes.prop('checked', false).prop('disabled', true);
            $specificLevelsContainer.css({'opacity': '0.5', 'pointer-events': 'none'});
        } else {
            // Enable specific level checkboxes
            $specificCheckboxes.prop('disabled', false);
            $specificLevelsContainer.css({'opacity': '1', 'pointer-events': 'auto'});
        }
        updateAudienceLabel();
    });

    // If any specific checkbox is clicked, uncheck "All Members"
    $specificCheckboxes.on('change', function() {
        if ($(this).is(':checked')) {
            $allCheckbox.prop('checked', false);
        }
        updateAudienceLabel();
    });

    $levelInputs.on('change', updateAudienceLabel);
    updateAudienceLabel();

    const request = (button, action) => {
        const defaultText = button.data('default') || button.text();
        const workingText = button.data('working') || defaultText;
        const payload = gatherAudience();
        const levels = payload.levels.length ? payload.levels : ['__npmp_all__'];

        button.prop('disabled', true).text(workingText);

        return $.post(
            ajaxurl,
            {
                action: action,
                post_id: button.data('postid'),
                nonce: button.data('nonce'),
                levels: levels,
                preheader: payload.preheader
            }
        ).always(function () {
            button.prop('disabled', false).text(defaultText);
        });
    };

    $('#npmp-send-test').on('click', function () {
        const button = $(this);

        request(button, 'npmp_send_test_newsletter')
            .done(function (res) {
                if (res && res.success) {
                    alert(res.data || 'Test email sent.');
                } else {
                    alert((res && res.data) || 'Request failed.');
                }
            })
            .fail(function () {
                alert('Unable to send test email.');
            });
    });

    $('#npmp-send-newsletter').on('click', function () {
        const button = $(this);
        const message = button.data('confirm');

        if (message && !window.confirm(message)) {
            return;
        }

        request(button, 'npmp_send_newsletter_now')
            .done(function (res) {
                if (res && res.success) {
                    alert(res.data || 'Newsletter queued.');
                } else {
                    alert((res && res.data) || 'Request failed.');
                }
            })
            .fail(function () {
                alert('Unable to queue newsletter.');
            });
    });
});
