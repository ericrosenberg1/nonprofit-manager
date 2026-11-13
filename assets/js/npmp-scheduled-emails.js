/* global jQuery, npmpScheduledEmails */
/**
 * Scheduled emails: the event box (test, skip, update), "Send later" on
 * newsletters, and the custom-hours field on both timing pickers.
 */
jQuery(function ($) {
    var cfg = window.npmpScheduledEmails || {};

    // Show the hours box only when "Custom" is picked.
    function syncCustom($select) {
        $select.siblings('.npmp-sched-custom').toggle($select.val() === 'custom');
    }
    $('.npmp-sched-timing').each(function () {
        syncCustom($(this));
    }).on('change', function () {
        syncCustom($(this));
    });

    // Event box buttons.
    $(document).on('click', '.npmp-sched-action', function () {
        var $button = $(this);
        var $wrap = $button.closest('.npmp-sched-actions');
        var $box = $button.closest('.inside');
        var $message = $box.find('.npmp-sched-message');
        var confirmText = $button.data('confirm');
        if (confirmText && !window.confirm(confirmText)) {
            return;
        }
        var label = $button.text();
        $button.prop('disabled', true).text(cfg.working || label);
        $.post(cfg.ajaxUrl, {
            action: 'npmp_sched_email_event',
            op: $button.data('op'),
            event_id: $wrap.data('event'),
            nonce: $wrap.data('nonce'),
            body_id: $box.find('.npmp-sched-body').val() || '',
            subject: $box.find('#npmp-sched-subject').val() || ''
        }).done(function (res) {
            var data = (res && res.data) || {};
            $message.text(data.message || cfg.failed || '');
            if (res && res.success && data.summary) {
                $box.find('.npmp-sched-summary').text(data.summary);
                $button.remove();
            }
        }).fail(function () {
            $message.text(cfg.failed || '');
        }).always(function () {
            $button.prop('disabled', false).text(label);
        });
    });

    // The block editor saves an event without reloading the page, so the
    // event box (free's or Pro's) would keep showing the plan from before the
    // save. Once the editor finishes saving the boxes, ask for their send
    // times again.
    function refreshLive() {
        $('.npmp-sched-live[data-event]').each(function () {
            var $live = $(this);
            $.post(cfg.ajaxUrl, {
                action: 'npmp_sched_email_box_status',
                event_id: $live.attr('data-event'),
                nonce: $live.attr('data-nonce')
            }).done(function (res) {
                if (res && res.success && res.data && typeof res.data.html === 'string') {
                    $live.html(res.data.html);
                }
            });
        });
    }
    var wpData = window.wp && window.wp.data;
    if (wpData && typeof wpData.subscribe === 'function' && $('.npmp-sched-live[data-event]').length) {
        var wasSaving = false;
        wpData.subscribe(function () {
            var editPost = wpData.select('core/edit-post');
            var editor = wpData.select('core/editor');
            var saving = false;
            if (editPost && typeof editPost.isSavingMetaBoxes === 'function') {
                saving = !!editPost.isSavingMetaBoxes();
            } else if (editor && typeof editor.isSavingPost === 'function') {
                saving = !!editor.isSavingPost() && !(typeof editor.isAutosavingPost === 'function' && editor.isAutosavingPost());
            }
            if (wasSaving && !saving) {
                refreshLive();
            }
            wasSaving = saving;
        });
    }

    // "Send later" on newsletters. Sends the same audience fields the
    // Send button sends, so the saved audience matches what's on screen.
    var $later = $('.npmp-send-later');
    if (!$later.length) {
        return;
    }
    var $status = $later.find('.npmp-send-later-status');
    var $cancel = $('#npmp-send-later-cancel');

    function audience() {
        var levels = $('.npmp-newsletter-level:checked').map(function () {
            return $(this).val();
        }).get();
        return {
            levels: levels.length ? levels : ['__npmp_all__'],
            preheader: $('#npmp-newsletter-preheader').val() || ''
        };
    }

    function send(extra, $button) {
        var label = $button.text();
        var payload = $.extend({
            action: 'npmp_send_later',
            post_id: $later.data('postid'),
            nonce: $later.data('nonce')
        }, audience(), extra);
        // Add-on audience inputs (Pro's Groups picker) ride along.
        var more = $('.npmp-newsletter-audience-extra :input').serialize();
        $button.prop('disabled', true).text(cfg.working || label);
        return $.post(cfg.ajaxUrl, $.param(payload) + (more ? '&' + more : '')).done(function (res) {
            var data = (res && res.data) || {};
            $status.text(data.message || cfg.failed || '');
        }).fail(function () {
            $status.text(cfg.failed || '');
        }).always(function () {
            $button.prop('disabled', false).text(label);
        });
    }

    $('#npmp-send-later-save').on('click', function () {
        var $button = $(this);
        var at = $('#npmp-send-later-at').val();
        if (!at) {
            $('#npmp-send-later-at').trigger('focus');
            return;
        }
        if ($button.data('confirm') && !window.confirm($button.data('confirm'))) {
            return;
        }
        send({ at: at }, $button).done(function (res) {
            if (res && res.success) {
                $cancel.prop('hidden', false);
            }
        });
    });

    $cancel.on('click', function () {
        var $button = $(this);
        send({ cancel: 1 }, $button).done(function (res) {
            if (res && res.success) {
                $button.prop('hidden', true);
                $('#npmp-send-later-at').val('');
            }
        });
    });
});
