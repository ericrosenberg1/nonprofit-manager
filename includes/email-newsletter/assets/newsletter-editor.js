jQuery(function ($) {
    $('#np-send-test').on('click', function () {
        const button = $(this);
        const postId = button.data('postid');
        const nonce = button.data('nonce');

        button.prop('disabled', true).text('Sending...');
        $.post(ajaxurl, {
            action: 'np_send_test_newsletter',
            post_id: postId,
            nonce: nonce
        }, function (res) {
            alert(res.data || 'Unknown response');
            button.prop('disabled', false).text('Send Test to Me');
        });
    });

    $('#np-send-newsletter').on('click', function () {
        if (!confirm('Are you sure you want to send this newsletter to all members?')) return;

        const button = $(this);
        const postId = button.data('postid');
        const nonce = button.data('nonce');

        button.prop('disabled', true).text('Queuing...');
        $.post(ajaxurl, {
            action: 'np_send_newsletter_now',
            post_id: postId,
            nonce: nonce
        }, function (res) {
            alert(res.data || 'Unknown response');
            button.prop('disabled', false).text('Send to All Members');
        });
    });
});
