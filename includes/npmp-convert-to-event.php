<?php
/**
 * File path: includes/npmp-convert-to-event.php
 *
 * Adds "Convert to Event" functionality for posts and pages.
 * Converts any post/page into an npmp_event, preserving content,
 * and prompting for event-specific details (date, time, location).
 */
defined( 'ABSPATH' ) || exit;

/**
 * Add "Convert to Event" to the post row actions on Posts and Pages list tables.
 */
add_filter( 'post_row_actions', 'npmp_add_convert_to_event_action', 10, 2 );
add_filter( 'page_row_actions', 'npmp_add_convert_to_event_action', 10, 2 );

function npmp_add_convert_to_event_action( $actions, $post ) {
	if ( ! current_user_can( 'edit_post', $post->ID ) ) {
		return $actions;
	}

	// Only show on posts and pages.
	if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
		return $actions;
	}

	$url = wp_nonce_url(
		admin_url( 'admin.php?action=npmp_convert_to_event&post_id=' . $post->ID ),
		'npmp_convert_event_' . $post->ID
	);

	$actions['npmp_convert_event'] = sprintf(
		'<a href="%s" class="npmp-convert-event" data-post-id="%d" title="%s">%s</a>',
		esc_url( $url ),
		$post->ID,
		esc_attr__( 'Convert this post into a calendar event', 'nonprofit-manager' ),
		esc_html__( 'Convert to Event', 'nonprofit-manager' )
	);

	return $actions;
}

/**
 * Add "Convert to Event" to the Gutenberg editor sidebar via admin_footer script.
 */
add_action( 'admin_footer-post.php', 'npmp_convert_event_editor_button' );
add_action( 'admin_footer-post-new.php', 'npmp_convert_event_editor_button' );

function npmp_convert_event_editor_button() {
	global $post;
	if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post->ID ) ) {
		return;
	}
	?>
	<script>
	document.addEventListener('DOMContentLoaded', function() {
		// Add button to the classic editor publish box or sidebar.
		var publishBox = document.getElementById('misc-publishing-actions');
		if (publishBox) {
			var div = document.createElement('div');
			div.className = 'misc-pub-section';
			div.innerHTML = '<a href="#" id="npmp-convert-event-btn" class="button" style="width:100%;text-align:center;margin-top:4px;">' +
				'<?php echo esc_js( __( 'Convert to Event', 'nonprofit-manager' ) ); ?>' + '</a>';
			publishBox.appendChild(div);
		}

		// For Gutenberg: add to post status panel after a brief delay for React render.
		setTimeout(function() {
			var panel = document.querySelector('.edit-post-post-status');
			if (!panel) panel = document.querySelector('.editor-post-panel__section');
			if (panel && !document.getElementById('npmp-convert-event-btn')) {
				var btn = document.createElement('button');
				btn.id = 'npmp-convert-event-btn';
				btn.className = 'components-button is-secondary';
				btn.style.cssText = 'width:100%;justify-content:center;margin-top:8px;';
				btn.textContent = '<?php echo esc_js( __( 'Convert to Event', 'nonprofit-manager' ) ); ?>';
				panel.appendChild(btn);
			}
		}, 1500);

		document.addEventListener('click', function(e) {
			if (e.target && e.target.id === 'npmp-convert-event-btn') {
				e.preventDefault();
				npmpShowConvertModal(<?php echo (int) $post->ID; ?>);
			}
		});
	});

	function npmpShowConvertModal(postId) {
		if (document.getElementById('npmp-convert-modal')) return;

		var overlay = document.createElement('div');
		overlay.id = 'npmp-convert-modal';
		overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:100000;display:flex;align-items:center;justify-content:center;';

		var modal = document.createElement('div');
		modal.style.cssText = 'background:#fff;border-radius:8px;padding:24px;max-width:480px;width:90%;max-height:90vh;overflow-y:auto;';
		modal.innerHTML = '<h2 style="margin:0 0 16px;"><?php echo esc_js( __( 'Convert to Event', 'nonprofit-manager' ) ); ?></h2>' +
			'<p style="color:#666;margin:0 0 16px;"><?php echo esc_js( __( 'This will create a new event with the same title and content. The original post will not be deleted.', 'nonprofit-manager' ) ); ?></p>' +
			'<div style="margin-bottom:12px;">' +
				'<label style="display:block;font-weight:600;margin-bottom:4px;"><?php echo esc_js( __( 'Event Start', 'nonprofit-manager' ) ); ?></label>' +
				'<input type="datetime-local" id="npmp-convert-start" style="width:100%;" required />' +
			'</div>' +
			'<div style="margin-bottom:12px;">' +
				'<label style="display:block;font-weight:600;margin-bottom:4px;"><?php echo esc_js( __( 'Event End', 'nonprofit-manager' ) ); ?></label>' +
				'<input type="datetime-local" id="npmp-convert-end" style="width:100%;" />' +
			'</div>' +
			'<div style="margin-bottom:12px;">' +
				'<label style="display:block;font-weight:600;margin-bottom:4px;"><?php echo esc_js( __( 'Location', 'nonprofit-manager' ) ); ?></label>' +
				'<input type="text" id="npmp-convert-location" style="width:100%;" placeholder="<?php echo esc_attr__( 'Address, venue, or online link', 'nonprofit-manager' ); ?>" />' +
			'</div>' +
			'<div style="margin-bottom:12px;">' +
				'<label><input type="checkbox" id="npmp-convert-delete" /> <?php echo esc_js( __( 'Delete the original post after conversion', 'nonprofit-manager' ) ); ?></label>' +
			'</div>' +
			'<div id="npmp-convert-status" style="margin-bottom:12px;display:none;"></div>' +
			'<div style="display:flex;gap:8px;justify-content:flex-end;">' +
				'<button type="button" id="npmp-convert-cancel" class="button"><?php echo esc_js( __( 'Cancel', 'nonprofit-manager' ) ); ?></button>' +
				'<button type="button" id="npmp-convert-submit" class="button button-primary"><?php echo esc_js( __( 'Create Event', 'nonprofit-manager' ) ); ?></button>' +
			'</div>';

		overlay.appendChild(modal);
		document.body.appendChild(overlay);

		overlay.addEventListener('click', function(e) { if (e.target === overlay) closeModal(); });
		document.getElementById('npmp-convert-cancel').addEventListener('click', closeModal);
		document.getElementById('npmp-convert-submit').addEventListener('click', function() { submitConvert(postId); });

		function closeModal() { overlay.remove(); }

		function submitConvert(pid) {
			var start = document.getElementById('npmp-convert-start').value;
			if (!start) {
				showStatus('<?php echo esc_js( __( 'Please set an event start date/time.', 'nonprofit-manager' ) ); ?>', 'error');
				return;
			}

			var btn = document.getElementById('npmp-convert-submit');
			btn.disabled = true;
			btn.textContent = '<?php echo esc_js( __( 'Converting...', 'nonprofit-manager' ) ); ?>';

			var formData = new FormData();
			formData.append('action', 'npmp_convert_to_event');
			formData.append('nonce', '<?php echo esc_js( wp_create_nonce( 'npmp_convert_event_' . $post->ID ) ); ?>');
			formData.append('post_id', pid);
			formData.append('start', start);
			formData.append('end', document.getElementById('npmp-convert-end').value);
			formData.append('location', document.getElementById('npmp-convert-location').value);
			formData.append('delete_original', document.getElementById('npmp-convert-delete').checked ? '1' : '0');

			fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
				method: 'POST',
				body: formData
			})
			.then(function(r) { return r.json(); })
			.then(function(data) {
				if (data.success) {
					showStatus('<?php echo esc_js( __( 'Event created!', 'nonprofit-manager' ) ); ?> <a href="' + data.data.edit_url + '"><?php echo esc_js( __( 'Edit Event', 'nonprofit-manager' ) ); ?></a>', 'success');
					btn.style.display = 'none';
					setTimeout(function() {
						if (data.data.delete_original) window.location.href = '<?php echo esc_url( admin_url( 'edit.php?post_type=npmp_event' ) ); ?>';
					}, 1500);
				} else {
					showStatus(data.data || '<?php echo esc_js( __( 'Conversion failed.', 'nonprofit-manager' ) ); ?>', 'error');
					btn.disabled = false;
					btn.textContent = '<?php echo esc_js( __( 'Create Event', 'nonprofit-manager' ) ); ?>';
				}
			})
			.catch(function() {
				showStatus('<?php echo esc_js( __( 'Network error. Please try again.', 'nonprofit-manager' ) ); ?>', 'error');
				btn.disabled = false;
				btn.textContent = '<?php echo esc_js( __( 'Create Event', 'nonprofit-manager' ) ); ?>';
			});
		}

		function showStatus(msg, type) {
			var el = document.getElementById('npmp-convert-status');
			el.style.display = 'block';
			el.style.padding = '8px 12px';
			el.style.borderRadius = '4px';
			el.style.background = type === 'error' ? '#fef2f2' : '#f0fdf4';
			el.style.color = type === 'error' ? '#991b1b' : '#166534';
			el.style.border = '1px solid ' + (type === 'error' ? '#fca5a5' : '#86efac');
			el.innerHTML = msg;
		}
	}
	</script>
	<?php
}

/**
 * AJAX handler: convert a post/page to an event.
 */
add_action( 'wp_ajax_npmp_convert_to_event', 'npmp_ajax_convert_to_event' );

function npmp_ajax_convert_to_event() {
	$post_id = absint( $_POST['post_id'] ?? 0 );

	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'npmp_convert_event_' . $post_id ) ) {
		wp_send_json_error( __( 'Invalid security token.', 'nonprofit-manager' ) );
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( __( 'Permission denied.', 'nonprofit-manager' ) );
	}

	$source = get_post( $post_id );
	if ( ! $source ) {
		wp_send_json_error( __( 'Source post not found.', 'nonprofit-manager' ) );
	}

	$start    = sanitize_text_field( wp_unslash( $_POST['start'] ?? '' ) );
	$end      = sanitize_text_field( wp_unslash( $_POST['end'] ?? '' ) );
	$location = sanitize_text_field( wp_unslash( $_POST['location'] ?? '' ) );
	$delete   = '1' === ( $_POST['delete_original'] ?? '0' );

	if ( empty( $start ) ) {
		wp_send_json_error( __( 'Event start date is required.', 'nonprofit-manager' ) );
	}

	// Format datetime for storage (Y-m-d H:i:s).
	$start_formatted = gmdate( 'Y-m-d H:i:s', strtotime( $start ) );
	$end_formatted   = $end ? gmdate( 'Y-m-d H:i:s', strtotime( $end ) ) : '';

	// Create the event post.
	$event_id = wp_insert_post(
		array(
			'post_type'    => 'npmp_event',
			'post_status'  => 'publish',
			'post_title'   => $source->post_title,
			'post_content' => $source->post_content,
			'post_excerpt' => $source->post_excerpt,
			'post_author'  => get_current_user_id(),
		),
		true
	);

	if ( is_wp_error( $event_id ) ) {
		wp_send_json_error( $event_id->get_error_message() );
	}

	// Set event meta.
	update_post_meta( $event_id, '_npmp_event_start', $start_formatted );
	if ( $end_formatted ) {
		update_post_meta( $event_id, '_npmp_event_end', $end_formatted );
	}
	if ( $location ) {
		update_post_meta( $event_id, '_npmp_event_location', $location );
	}

	// Copy featured image if one exists.
	$thumbnail_id = get_post_thumbnail_id( $post_id );
	if ( $thumbnail_id ) {
		set_post_thumbnail( $event_id, $thumbnail_id );
	}

	// Copy categories/tags to event categories if the taxonomy exists.
	$categories = wp_get_post_categories( $post_id, array( 'fields' => 'names' ) );
	if ( ! empty( $categories ) && taxonomy_exists( 'npmp_event_category' ) ) {
		wp_set_object_terms( $event_id, $categories, 'npmp_event_category' );
	}

	// Optionally delete the original.
	if ( $delete ) {
		wp_trash_post( $post_id );
	}

	wp_send_json_success(
		array(
			'event_id'        => $event_id,
			'edit_url'        => get_edit_post_link( $event_id, 'raw' ),
			'delete_original' => $delete,
		)
	);
}

/**
 * Handle direct link conversion (from post row actions).
 */
add_action( 'admin_action_npmp_convert_to_event', 'npmp_handle_direct_convert' );

function npmp_handle_direct_convert() {
	$post_id = absint( $_GET['post_id'] ?? 0 );

	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'npmp_convert_event_' . $post_id ) ) {
		wp_die( esc_html__( 'Invalid security token.', 'nonprofit-manager' ) );
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		wp_die( esc_html__( 'Permission denied.', 'nonprofit-manager' ) );
	}

	// Redirect to the post editor with a flag to open the conversion modal.
	wp_safe_redirect( admin_url( 'post.php?post=' . $post_id . '&action=edit&npmp_convert=1' ) );
	exit;
}

/**
 * Auto-open the convert modal when redirected from row actions.
 */
add_action( 'admin_footer-post.php', 'npmp_auto_open_convert_modal' );

function npmp_auto_open_convert_modal() {
	if ( empty( $_GET['npmp_convert'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only trigger.
		return;
	}
	global $post;
	if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
		return;
	}
	?>
	<script>
	document.addEventListener('DOMContentLoaded', function() {
		setTimeout(function() {
			if (typeof npmpShowConvertModal === 'function') {
				npmpShowConvertModal(<?php echo (int) $post->ID; ?>);
			}
		}, 500);
	});
	</script>
	<?php
}
