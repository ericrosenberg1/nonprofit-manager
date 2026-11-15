/* global ajaxurl */
jQuery(function ($) {
    const $levelInputs = $('.npmp-newsletter-level');
    const $allCheckbox = $('.npmp-newsletter-all');
    const $specificCheckboxes = $('.npmp-newsletter-specific');
    const $specificLevelsContainer = $('.npmp-newsletter-specific-levels');
    const $preheader = $('#npmp-newsletter-preheader');
    const $audienceLabel = $('.npmp-newsletter-audience-label');
    // Pro's Groups picker. Groups narrow the audience: no level picked means
    // members of those groups (Pro's npmp_pro_groups_filter_recipients()).
    const $groupInputs = $('.npmp-newsletter-audience-extra input[name="npmp_newsletter_groups[]"]');

    const groupNames = () => {
        const names = [];
        $groupInputs.filter(':checked').not(':disabled').each(function () {
            let name = $(this).data('label');
            if (!name) {
                // Older Pro prints the name as the label's text, then a count.
                const $label = $(this).closest('label').clone();
                $label.find('.description').remove();
                name = $.trim($label.text());
            }
            if (name) {
                names.push(String(name));
            }
        });
        return names;
    };

    // Joins names the way wp_sprintf( '%l' ) does, with the words the page
    // prints (npmp_newsletter_list_words()), so the live label matches the
    // one Pro saves: "A and B", "A, B, and C".
    const joinList = (items) => {
        const between = $audienceLabel.data('listBetween');
        const last = $audienceLabel.data('listLast');
        const two = $audienceLabel.data('listTwo');
        const words = {
            between: typeof between === 'string' ? between : ', ',
            last: typeof last === 'string' ? last : ', and ',
            two: typeof two === 'string' ? two : ' and '
        };
        if (items.length < 2) {
            return items.join('');
        }
        if (items.length === 2) {
            return items[0] + words.two + items[1];
        }
        let out = items[0];
        for (let i = 1; i < items.length - 1; i++) {
            out += words.between + items[i];
        }
        return out + words.last + items[items.length - 1];
    };

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
        const groups = groupNames();
        const everyone = !payload.levels.length || payload.levels.includes('__all__');

        const names = [];
        $levelInputs.filter(':checked').not('.npmp-newsletter-all').each(function () {
            const label = $(this).data('label');
            if (label) {
                names.push(label);
            }
        });

        if (groups.length) {
            // The same words Pro saves after the save (npmp_pro_groups_audience_label()).
            const template = everyone || !names.length
                ? String($audienceLabel.data('groupsOnly') || '%s').replace('%s', joinList(groups))
                : String($audienceLabel.data('groupsWithin') || '%1$s in %2$s').replace('%1$s', names.join(', ')).replace('%2$s', joinList(groups));
            $audienceLabel.text(template);
            return;
        }

        if (everyone) {
            $audienceLabel.text($audienceLabel.data('default') || '');
            return;
        }

        $audienceLabel.text(names.join(', '));
    };

    const setAllMembers = (checked) => {
        $allCheckbox.prop('checked', checked);
        if (checked) {
            // Disable and uncheck specific level checkboxes
            $specificCheckboxes.prop('checked', false).prop('disabled', true);
            $specificLevelsContainer.css({'opacity': '0.5', 'pointer-events': 'none'});
        } else {
            // Enable specific level checkboxes
            $specificCheckboxes.prop('disabled', false);
            $specificLevelsContainer.css({'opacity': '1', 'pointer-events': 'auto'});
        }
    };

    // Handle "All Members" checkbox interaction
    $allCheckbox.on('change', function() {
        setAllMembers($(this).is(':checked'));
        updateAudienceLabel();
    });

    // A picked group narrows the audience, so "All Members" comes off, the
    // same as the editor shows after a reload (npmp_newsletter_has_groups()).
    // Without a level, the send still goes to every member of the group.
    $groupInputs.on('change', function () {
        if ($(this).is(':checked') && $allCheckbox.is(':checked')) {
            setAllMembers(false);
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

        // Add-on audience inputs (Pro's Groups picker) ride along, so the
        // send saves them too. Disabled inputs (the free preview) never do.
        const extra = $('.npmp-newsletter-audience-extra :input').serialize();
        const data = $.param({
            action: action,
            post_id: button.data('postid'),
            nonce: button.data('nonce'),
            levels: levels,
            preheader: payload.preheader
        }) + (extra ? '&' + extra : '');

        return $.post(ajaxurl, data).always(function () {
            button.prop('disabled', false).text(defaultText);
        });
    };

    // We send the test and send answers as plain text (editor.php), so we
    // pass them to alert() as they are and never put them in the page as HTML.
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
