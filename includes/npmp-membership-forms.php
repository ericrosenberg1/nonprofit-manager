<?php
// includes/npmp-membership-forms.php
defined( 'ABSPATH' ) || exit;

/* ====================================================================
 * Helper functions
 * ==================================================================== */

/**
 * Get membership levels as an array.
 *
 * @return array
 */
function npmp_get_membership_levels_array() {
	$levels = get_option( 'npmp_membership_levels', array() );
	if ( is_string( $levels ) ) {
		// Handle old format (newline-separated)
		$levels = array_filter( array_map( 'trim', explode( "\n", $levels ) ) );
	}
	return is_array( $levels ) ? $levels : array();
}

/* ====================================================================
 * Admin settings page
 * ==================================================================== */
function npmp_membership_form_default_settings() {
	return array(
		'signup_heading'          => __( 'Join our Email List', 'nonprofit-manager' ),
		'signup_description'      => '',
		'unsubscribe_heading'     => __( 'Unsubscribe', 'nonprofit-manager' ),
		'unsubscribe_description' => '',
		'name_label'              => __( 'Name', 'nonprofit-manager' ),
		'email_label'             => __( 'Email', 'nonprofit-manager' ),
		'signup_button'           => __( 'Sign Up', 'nonprofit-manager' ),
		'unsubscribe_button'      => __( 'Unsubscribe', 'nonprofit-manager' ),
		'success_message'         => __( 'Thank you! Your request has been processed.', 'nonprofit-manager' ),
		'error_message'           => __( 'Something went wrong. Please try again.', 'nonprofit-manager' ),
		'signup_page_id'          => 0,
		'unsubscribe_page_id'     => 0,
	);
}

function npmp_get_membership_form_settings( $refresh = false ) {
	static $settings = null;

	if ( $refresh ) {
		$settings = null;
	}

	if ( null === $settings ) {
		$settings = wp_parse_args(
			get_option( 'npmp_membership_form_settings', array() ),
			npmp_membership_form_default_settings()
		);
	}

	return $settings;
}

/**
 * Build the setup-status checks shown on the Membership Settings screen.
 *
 * Surfaces the exact silent failure a non-technical admin can hit: an
 * unsubscribe page that is unset, unpublished, or has no form, plus the
 * CAN-SPAM postal-address gap.
 *
 * @return array[] Each row: array( 'status' => ok|warning|error, 'label' => string, 'message' => string ).
 */
function npmp_get_membership_setup_status() {
	$settings = npmp_get_membership_form_settings();
	$rows     = array();

	/* Unsubscribe page — the failure that silently breaks CAN-SPAM links. */
	$unsub_id = absint( $settings['unsubscribe_page_id'] ?? 0 );
	if ( ! $unsub_id ) {
		$rows[] = array(
			'status'  => 'warning',
			'label'   => __( 'Unsubscribe page', 'nonprofit-manager' ),
			'message' => __( 'No unsubscribe page is set, so newsletter unsubscribe links fall back to /unsubscribe — which may not exist. Choose a page in the Unsubscribe Form section below.', 'nonprofit-manager' ),
		);
	} elseif ( 'publish' !== get_post_status( $unsub_id ) ) {
		$rows[] = array(
			'status'  => 'error',
			'label'   => __( 'Unsubscribe page', 'nonprofit-manager' ),
			'message' => __( 'The configured unsubscribe page is missing or not published, so unsubscribe links in your emails will break. Pick a published page below.', 'nonprofit-manager' ),
		);
	} else {
		$has_form = false !== strpos( (string) get_post_field( 'post_content', $unsub_id ), '[npmp_email_unsubscribe]' );
		if ( $has_form ) {
			/* translators: %s: page title */
			$message = sprintf( __( 'Unsubscribe links resolve to “%s”, and the form is on the page.', 'nonprofit-manager' ), get_the_title( $unsub_id ) );
		} else {
			/* translators: %s: page title */
			$message = sprintf( __( 'Unsubscribe links resolve to “%s”. The form is added to the page automatically.', 'nonprofit-manager' ), get_the_title( $unsub_id ) );
		}
		$rows[] = array(
			'status'  => 'ok',
			'label'   => __( 'Unsubscribe page', 'nonprofit-manager' ),
			'message' => $message,
		);
	}

	/* CAN-SPAM postal address. */
	$address = trim( (string) get_option( 'npmp_org_mailing_address', '' ) );
	if ( '' === $address ) {
		$rows[] = array(
			'status'  => 'warning',
			'label'   => __( 'Mailing address', 'nonprofit-manager' ),
			'message' => __( 'No organization mailing address is set. CAN-SPAM requires a physical postal address in newsletter footers; an email address is used as a fallback for now. Add one on the Newsletter Settings screen.', 'nonprofit-manager' ),
		);
	} else {
		$rows[] = array(
			'status'  => 'ok',
			'label'   => __( 'Mailing address', 'nonprofit-manager' ),
			'message' => __( 'A postal mailing address is set for the CAN-SPAM footer.', 'nonprofit-manager' ),
		);
	}

	/* Signup page (informational). */
	$signup_id = absint( $settings['signup_page_id'] ?? 0 );
	if ( $signup_id && 'publish' !== get_post_status( $signup_id ) ) {
		$rows[] = array(
			'status'  => 'error',
			'label'   => __( 'Signup page', 'nonprofit-manager' ),
			'message' => __( 'The configured signup page is missing or not published.', 'nonprofit-manager' ),
		);
	} elseif ( $signup_id ) {
		/* translators: %s: page title */
		$rows[] = array(
			'status'  => 'ok',
			'label'   => __( 'Signup page', 'nonprofit-manager' ),
			'message' => sprintf( __( 'Signup form appears on “%s”.', 'nonprofit-manager' ), get_the_title( $signup_id ) ),
		);
	}

	return $rows;
}

/**
 * Render the setup-status card on the Membership Settings screen.
 *
 * @return void
 */
function npmp_render_membership_setup_status() {
	$rows = npmp_get_membership_setup_status();
	if ( empty( $rows ) ) {
		return;
	}

	$icons = array(
		'ok'      => array( 'dashicons-yes-alt', '#00a32a' ),
		'warning' => array( 'dashicons-warning', '#dba617' ),
		'error'   => array( 'dashicons-dismiss', '#d63638' ),
	);

	echo '<div class="npmp-settings-card">';
	echo '<h2>' . esc_html__( 'Setup status', 'nonprofit-manager' ) . '</h2>';
	echo '<table class="npmp-levels-table" style="max-width:760px;">';
	foreach ( $rows as $row ) {
		$icon = isset( $icons[ $row['status'] ] ) ? $icons[ $row['status'] ] : $icons['ok'];
		echo '<tr>';
		echo '<td style="width:28px;vertical-align:top;"><span class="dashicons ' . esc_attr( $icon[0] ) . '" style="color:' . esc_attr( $icon[1] ) . ';"></span></td>';
		echo '<td style="width:150px;vertical-align:top;font-weight:600;">' . esc_html( $row['label'] ) . '</td>';
		echo '<td style="vertical-align:top;">' . esc_html( $row['message'] ) . '</td>';
		echo '</tr>';
	}
	echo '</table>';
	echo '</div>';
}

function npmp_render_membership_forms_page() {
	npmp_verify_admin_access();

	$option_key = 'npmp_membership_form_settings';
	$defaults   = npmp_membership_form_default_settings();
	$settings   = wp_parse_args( get_option( $option_key, array() ), $defaults );
	$message  = '';

	// Get membership levels data
	$membership_levels        = npmp_get_membership_levels_array();
	$default_membership_level = get_option( 'npmp_default_membership_level', '' );

	/* ---------- Handle Membership Level Actions ---------- */
	// Add new level
	if (
		isset( $_POST['npmp_add_level_nonce'] ) &&
		wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_add_level_nonce'] ) ), 'npmp_add_membership_level' ) &&
		! empty( $_POST['npmp_new_level'] )
	) {
		$new_level = sanitize_text_field( wp_unslash( $_POST['npmp_new_level'] ) );
		if ( ! in_array( $new_level, $membership_levels, true ) ) {
			$membership_levels[] = $new_level;
			update_option( 'npmp_membership_levels', $membership_levels );
			$message = __( 'Membership level added.', 'nonprofit-manager' );
		}
	}

	// Remove level
	if (
		isset( $_POST['npmp_remove_level_nonce'] ) &&
		wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_remove_level_nonce'] ) ), 'npmp_remove_membership_level' ) &&
		! empty( $_POST['npmp_level_to_remove'] )
	) {
		$level_to_remove   = sanitize_text_field( wp_unslash( $_POST['npmp_level_to_remove'] ) );
		$membership_levels = array_diff( $membership_levels, array( $level_to_remove ) );
		$membership_levels = array_values( $membership_levels ); // Re-index
		update_option( 'npmp_membership_levels', $membership_levels );
		$message = __( 'Membership level removed.', 'nonprofit-manager' );
	}

	// Save default level
	if (
		isset( $_POST['npmp_default_level_nonce'] ) &&
		wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_default_level_nonce'] ) ), 'npmp_save_default_level' )
	) {
		$default_level = isset( $_POST['npmp_default_membership_level'] ) ? sanitize_text_field( wp_unslash( $_POST['npmp_default_membership_level'] ) ) : '';
		update_option( 'npmp_default_membership_level', $default_level );
		$default_membership_level = $default_level;
		$message                  = __( 'Default membership level saved.', 'nonprofit-manager' );
	}

	/* ---------- Save Form Settings ---------- */
	if (
		isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] &&
		wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ),
			'npmp_membership_form_save'
		)
	) {
		foreach ( array_keys( $settings ) as $k ) {
			if ( ! array_key_exists( $k, $_POST ) ) {
				continue;
			}
			$settings[ $k ] = in_array( $k, array( 'signup_description', 'unsubscribe_description' ), true )
				? sanitize_textarea_field( wp_unslash( $_POST[ $k ] ) )
				: ( in_array( $k, array( 'signup_page_id', 'unsubscribe_page_id' ), true )
					? absint( wp_unslash( $_POST[ $k ] ) )
					: sanitize_text_field( wp_unslash( $_POST[ $k ] ) )
				);
		}
		update_option( $option_key, $settings );
		$settings = npmp_get_membership_form_settings( true );
		$message = __( 'Settings saved.', 'nonprofit-manager' );
	}

	/* ---------- Render ---------- */
	npmp_admin_page_header(
		__( 'Membership Settings', 'nonprofit-manager' ),
		__( 'Configure membership levels and signup/unsubscribe forms.', 'nonprofit-manager' )
	);

	if ( $message ) {
		npmp_admin_notice_success( $message );
	}

	/* ---------- Membership Levels Section ---------- */
	?>
	<style>
		.npmp-settings-card .npmp-levels-table td {
			border-bottom: 1px solid #f0f0f1;
		}
		.npmp-settings-card {
			background: #fff;
			border: 1px solid #c3c4c7;
			box-shadow: 0 1px 1px rgba(0,0,0,.04);
			padding: 20px;
			margin: 20px 0;
		}
		.npmp-settings-card h2 {
			margin-top: 0;
			font-size: 1.3em;
		}
		.npmp-levels-table {
			width: 100%;
			max-width: 600px;
			border-collapse: collapse;
			margin: 20px 0;
		}
		.npmp-levels-table th,
		.npmp-levels-table td {
			padding: 10px;
			text-align: left;
			border-bottom: 1px solid #ddd;
		}
		.npmp-levels-table thead th {
			font-weight: 600;
			background: #f6f7f7;
		}
		.npmp-delete-level {
			color: #b32d2e;
			text-decoration: none;
			cursor: pointer;
		}
		.npmp-delete-level:hover {
			color: #d63638;
		}
		.npmp-add-level-form {
			display: flex;
			gap: 10px;
			align-items: center;
			margin-top: 15px;
		}
		.npmp-add-level-form input[type="text"] {
			flex: 1;
			max-width: 300px;
		}
	</style>

	<?php npmp_render_membership_setup_status(); ?>

	<div class="npmp-settings-card">
		<h2><?php esc_html_e( 'Membership Levels', 'nonprofit-manager' ); ?></h2>
		<p><?php esc_html_e( 'Define membership levels for your organization. These will be available when adding or editing members.', 'nonprofit-manager' ); ?></p>

		<!-- Current Levels Table -->
		<?php if ( ! empty( $membership_levels ) ) : ?>
			<table class="npmp-levels-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Level Name', 'nonprofit-manager' ); ?></th>
						<th style="width: 100px; text-align: center;"><?php esc_html_e( 'Action', 'nonprofit-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $membership_levels as $level ) : ?>
						<tr>
							<td><?php echo esc_html( $level ); ?></td>
							<td style="text-align: center;">
								<a href="#" class="npmp-delete-level" data-level="<?php echo esc_attr( $level ); ?>" title="<?php esc_attr_e( 'Delete this level', 'nonprofit-manager' ); ?>">
									<span class="dashicons dashicons-no-alt"></span>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p><em><?php esc_html_e( 'No membership levels defined yet.', 'nonprofit-manager' ); ?></em></p>
		<?php endif; ?>

		<!-- Add New Level Form -->
		<form method="post" class="npmp-add-level-form">
			<?php wp_nonce_field( 'npmp_add_membership_level', 'npmp_add_level_nonce' ); ?>
			<input
				type="text"
				name="npmp_new_level"
				placeholder="<?php esc_attr_e( 'Enter new membership level name', 'nonprofit-manager' ); ?>"
				required
			>
			<?php submit_button( __( 'Add Level', 'nonprofit-manager' ), 'secondary', 'submit', false ); ?>
		</form>

		<!-- Hidden form for deletion -->
		<form method="post" id="npmp-delete-level-form" style="display: none;">
			<?php wp_nonce_field( 'npmp_remove_membership_level', 'npmp_remove_level_nonce' ); ?>
			<input type="hidden" name="npmp_level_to_remove" id="npmp_level_to_remove">
		</form>

		<hr style="margin: 30px 0; border: none; border-top: 1px solid #ddd;">

		<!-- Default Level Selection -->
		<form method="post">
			<?php wp_nonce_field( 'npmp_save_default_level', 'npmp_default_level_nonce' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="npmp_default_membership_level"><?php esc_html_e( 'Default Level', 'nonprofit-manager' ); ?></label>
					</th>
					<td>
						<select name="npmp_default_membership_level" id="npmp_default_membership_level" class="regular-text">
							<option value=""><?php esc_html_e( '-- Select Default Level --', 'nonprofit-manager' ); ?></option>
							<?php foreach ( $membership_levels as $level ) : ?>
								<option value="<?php echo esc_attr( $level ); ?>" <?php selected( $default_membership_level, $level ); ?>>
									<?php echo esc_html( $level ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'This level will be automatically assigned to new members who sign up via email forms.', 'nonprofit-manager' ); ?>
						</p>
						<?php submit_button( __( 'Save Default Level', 'nonprofit-manager' ), 'primary', 'submit', false ); ?>
					</td>
				</tr>
			</table>
		</form>

		<script>
			document.querySelectorAll('.npmp-delete-level').forEach(function(el) {
				el.addEventListener('click', function(e) {
					e.preventDefault();
					if (confirm('<?php echo esc_js( __( 'Are you sure you want to delete this membership level?', 'nonprofit-manager' ) ); ?>')) {
						document.getElementById('npmp_level_to_remove').value = this.getAttribute('data-level');
						document.getElementById('npmp-delete-level-form').submit();
					}
				});
			});
		</script>
	</div>

	<h2><?php esc_html_e( 'Membership Forms', 'nonprofit-manager' ); ?></h2>

	<?php

	$field = static function ( $id, $label, $type = 'text' ) use ( $settings ) {
		$raw_value = $settings[ $id ];
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		if ( 'textarea' === $type ) {
			echo '<textarea class="large-text" rows="3" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '">' . esc_textarea( $raw_value ) . '</textarea>';
		} else {
			echo '<input class="regular-text" type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" value="' . esc_attr( $raw_value ) . '">';
		}
		echo '</td></tr>';
	};

	echo '<form method="post">';
	wp_nonce_field( 'npmp_membership_form_save' );

	/* Signup section */
	echo '<h2>' . esc_html__( 'Signup Form', 'nonprofit-manager' ) . '</h2><p><code>[npmp_email_signup]</code> - ' . esc_html__( 'Embed anywhere or pick a page below to inject automatically.', 'nonprofit-manager' ) . '</p>';
	echo '<table class="form-table">';
	$field( 'signup_heading', esc_html__( 'Heading', 'nonprofit-manager' ) );
	$field( 'signup_description', esc_html__( 'Description', 'nonprofit-manager' ), 'textarea' );
	echo '<tr><th>' . esc_html__( 'Inject on Page', 'nonprofit-manager' ) . '</th><td>';
	wp_dropdown_pages(
		array(
			'name'             => 'signup_page_id',
			'selected'         => absint( $settings['signup_page_id'] ),
			'show_option_none' => '- ' . esc_html__( 'None', 'nonprofit-manager' ) . ' -',
			'option_none_value'=> 0,
		)
	);
	echo '<p class="description">' . esc_html__( 'The form will be appended to the bottom of the selected page.', 'nonprofit-manager' ) . '</p></td></tr></table>';

	/* Unsubscribe section */
	echo '<h2>' . esc_html__( 'Unsubscribe Form', 'nonprofit-manager' ) . '</h2><p><code>[npmp_email_unsubscribe]</code> - ' . esc_html__( 'Embed anywhere or pick a page below to inject automatically.', 'nonprofit-manager' ) . '</p>';
	echo '<table class="form-table">';
	$field( 'unsubscribe_heading', esc_html__( 'Heading', 'nonprofit-manager' ) );
	$field( 'unsubscribe_description', esc_html__( 'Description', 'nonprofit-manager' ), 'textarea' );
	echo '<tr><th>' . esc_html__( 'Inject on Page', 'nonprofit-manager' ) . '</th><td>';
	wp_dropdown_pages(
		array(
			'name'             => 'unsubscribe_page_id',
			'selected'         => absint( $settings['unsubscribe_page_id'] ),
			'show_option_none' => '- ' . esc_html__( 'None', 'nonprofit-manager' ) . ' -',
			'option_none_value'=> 0,
		)
	);
	echo '<p class="description">' . esc_html__( 'The form will be appended to the bottom of the selected page.', 'nonprofit-manager' ) . '</p></td></tr></table>';

	/* Shared labels */
	echo '<h2>' . esc_html__( 'Shared Labels & Messages', 'nonprofit-manager' ) . '</h2>';
	echo '<table class="form-table">';
	$field( 'name_label',          esc_html__( 'Name Field Label', 'nonprofit-manager' ) );
	$field( 'email_label',         esc_html__( 'Email Field Label', 'nonprofit-manager' ) );
	$field( 'signup_button',       esc_html__( 'Signup Button Text', 'nonprofit-manager' ) );
	$field( 'unsubscribe_button',  esc_html__( 'Unsubscribe Button Text', 'nonprofit-manager' ) );
	$field( 'success_message',     esc_html__( 'Success Message', 'nonprofit-manager' ) );
	$field( 'error_message',       esc_html__( 'Error Message', 'nonprofit-manager' ) );
	echo '</table>';

	submit_button( esc_html__( 'Save Changes', 'nonprofit-manager' ) );
	echo '</form>';
	npmp_admin_page_footer();
}

/**
 * Append a banner query parameter with a nonce.
 *
 * @param string $url Base URL.
 * @param string $key Query key.
 * @param string $value Query value.
 * @return string
 */
function npmp_membership_add_banner_arg( $url, $key, $value ) {
	$url       = add_query_arg( $key, $value, $url );
	$token_key = $key . '_nonce';
	$nonce     = wp_create_nonce( 'npmp_banner_' . $key . '_' . sanitize_key( $value ) );
	return add_query_arg( $token_key, $nonce, $url );
}

/* ====================================================================
 * Shortcodes
 * ==================================================================== */
function npmp_get_banner_html( $type, $settings ) {
	$token_key = $type . '_nonce';

	if ( isset( $_GET[ $type ], $_GET[ $token_key ] ) ) {
		$status = sanitize_text_field( wp_unslash( $_GET[ $type ] ) );
		$nonce  = sanitize_text_field( wp_unslash( $_GET[ $token_key ] ) );

		if ( ! wp_verify_nonce( $nonce, 'npmp_banner_' . $type . '_' . sanitize_key( $status ) ) ) {
			return '';
		}

		if ( 'captcha' === $status ) {
			return '<div class="npmp-form-banner npmp-' . esc_attr( $type ) . ' npmp-status-captcha"><p>' . esc_html__( 'Please complete the spam protection check before submitting.', 'nonprofit-manager' ) . '</p></div>';
		}
		if ( 'confirm' === $status ) {
			return '<div class="npmp-form-banner npmp-' . esc_attr( $type ) . ' npmp-status-success" role="status"><p>' . esc_html__( 'If that address is on our list, a confirmation email is on its way. Click the link in it to finish unsubscribing.', 'nonprofit-manager' ) . '</p></div>';
		}
		$key          = 'success' === $status ? 'success_message' : 'error_message';
		$status_class = 'success' === $status ? 'npmp-status-success' : 'npmp-status-error';
		$message      = isset( $settings[ $key ] ) ? $settings[ $key ] : npmp_membership_form_default_settings()[ $key ];
		return '<div class="npmp-form-banner npmp-' . esc_attr( $type ) . ' ' . esc_attr( $status_class ) . '"><p>' . esc_html( $message ) . '</p></div>';
	}
	return '';
}

/* ------------ Signup shortcode ------------ */
function npmp_email_signup_shortcode() {
	$s    = npmp_get_membership_form_settings();
	$html = npmp_get_banner_html( 'npmp_signup', $s );

	$fields = '
		<p><label>' . esc_html( $s['name_label'] ) . '<br><input type="text" name="npmp_name" required></label></p>
		<p><label>' . esc_html( $s['email_label'] ) . '<br><input type="email" name="npmp_email" required></label></p>';

	/**
	 * Filter the signup form fields to append extra inputs.
	 *
	 * The subscriber notification preferences feature (post/event/digest
	 * checkboxes) renders through this filter. The admin screen promised
	 * those checkboxes "appear on the signup form" but nothing applied the
	 * filter, so they never did.
	 *
	 * @param string $fields Form field HTML so far.
	 */
	$fields = apply_filters( 'npmp_signup_form_after_fields', $fields );

	$html .= '
	<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post" class="npmp-email-signup">
		<h3>' . esc_html( $s['signup_heading'] ) . '</h3>' .
		( $s['signup_description'] ? '<p>' . esc_html( $s['signup_description'] ) . '</p>' : '' ) .
		$fields . '
		' . npmp_captcha_render_widget( 'email_signup' ) . '
		' . wp_nonce_field( 'npmp_email_signup', 'npmp_email_signup_nonce', true, false ) . '
		<input type="hidden" name="npmp_action" value="email_signup">
		<input type="hidden" name="action" value="npmp_handle_form">
		<p><button type="submit">' . esc_html( $s['signup_button'] ) . '</button></p>
	</form>';
	return $html;
}
add_shortcode( 'npmp_email_signup', 'npmp_email_signup_shortcode' );

/* ------------ Unsubscribe shortcode ------------ */
function npmp_email_unsubscribe_shortcode() {
	$s    = npmp_get_membership_form_settings();
	$html = npmp_get_banner_html( 'npmp_unsubscribe', $s );
	$html .= '
	<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post" class="npmp-email-unsubscribe">
		<h3>' . esc_html( $s['unsubscribe_heading'] ) . '</h3>' .
		( $s['unsubscribe_description'] ? '<p>' . esc_html( $s['unsubscribe_description'] ) . '</p>' : '' ) . '
		<p><label>' . esc_html( $s['email_label'] ) . '<br><input type="email" name="npmp_email" required></label></p>
		' . npmp_captcha_render_widget( 'email_unsubscribe' ) . '
		' . wp_nonce_field( 'npmp_email_unsubscribe', 'npmp_email_unsubscribe_nonce', true, false ) . '
		<input type="hidden" name="npmp_action" value="email_unsubscribe">
		<input type="hidden" name="action" value="npmp_handle_form">
		<p><button type="submit">' . esc_html( $s['unsubscribe_button'] ) . '</button></p>
	</form>';
	return $html;
}
add_shortcode( 'npmp_email_unsubscribe', 'npmp_email_unsubscribe_shortcode' );

/* ====================================================================
 * Inject chosen pages
 * ==================================================================== */
add_filter( 'the_content', 'npmp_inject_membership_forms_into_pages', 9 );
function npmp_inject_membership_forms_into_pages( $content ) {
	if ( ! is_singular( 'page' ) ) {
		return $content;
	}
	$s       = npmp_get_membership_form_settings();
	$post_id = get_the_ID();

	$has_signup = ( false !== strpos( $content, '[npmp_email_signup]' ) );
	if ( ! empty( $s['signup_page_id'] ) && absint( $s['signup_page_id'] ) === $post_id && ! $has_signup ) {
		$content .= "\n\n[npmp_email_signup]\n";
	}
	$has_unsubscribe = ( false !== strpos( $content, '[npmp_email_unsubscribe]' ) );
	if ( ! empty( $s['unsubscribe_page_id'] ) && absint( $s['unsubscribe_page_id'] ) === $post_id && ! $has_unsubscribe ) {
		$content .= "\n\n[npmp_email_unsubscribe]\n";
	}
	return $content;
}

/* ====================================================================
 * Handle form submissions
 * ==================================================================== */
add_action( 'admin_post_nopriv_npmp_handle_form', 'npmp_handle_membership_form' );
add_action( 'admin_post_npmp_handle_form',        'npmp_handle_membership_form' );

function npmp_handle_membership_form() {
	if ( empty( $_POST['npmp_action'] ) ) {
		wp_safe_redirect( add_query_arg( 'npmp_form_error', '1', wp_get_referer() ?: home_url() ) );
		exit;
	}

	$action   = sanitize_text_field( wp_unslash( $_POST['npmp_action'] ) );
	$redirect = wp_get_referer() ?: home_url();

	if ( ! class_exists( 'NPMP_Member_Manager' ) ) {
		wp_safe_redirect( add_query_arg( 'npmp_form_error', 'missing_manager', $redirect ) );
		exit;
	}

	$member_manager = NPMP_Member_Manager::get_instance();

	/* ---------------- SIGN-UP ---------------- */
	if (
		'email_signup' === $action &&
		! empty( $_POST['npmp_email_signup_nonce'] ) &&
		wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['npmp_email_signup_nonce'] ) ),
			'npmp_email_signup'
		)
	) {
		if ( npmp_captcha_is_enabled() && ! npmp_captcha_verify( 'email_signup' ) ) {
			wp_safe_redirect( npmp_membership_add_banner_arg( $redirect, 'npmp_signup', 'captcha' ) );
			exit;
		}
		$name  = sanitize_text_field( wp_unslash( $_POST['npmp_name'] ?? '' ) );
		$email = sanitize_email( wp_unslash( $_POST['npmp_email'] ?? '' ) );

		if ( ! is_email( $email ) ) {
			wp_safe_redirect( npmp_membership_add_banner_arg( $redirect, 'npmp_signup', 'error' ) );
			exit;
		}

		$existing   = $member_manager->get_member_by_email( $email );
		$contact_id = 0;
		if ( $existing ) {
			$update = $member_manager->update_member(
				$existing->id,
				array(
					'name'             => $name ?: $existing->name,
					'status'           => 'subscribed',
					'membership_level' => $existing->membership_level ?: 'member',
				)
			);
			$result     = is_wp_error( $update ) ? 'error' : 'success';
			$contact_id = (int) $existing->id;
		} else {
			$added = $member_manager->add_member(
				array(
					'name'             => $name,
					'email'            => $email,
					'status'           => 'subscribed',
					'membership_level' => 'member',
					'source'           => 'form_signup',
				)
			);
			$result     = is_wp_error( $added ) ? 'error' : 'success';
			$contact_id = is_wp_error( $added ) ? 0 : (int) $added;
		}

		if ( 'success' === $result && $contact_id ) {
			/**
			 * Fires after a public signup succeeds.
			 *
			 * The notification-preferences feature saves the subscriber's
			 * chosen checkboxes here. It was hooked but never fired, so
			 * signup preferences were silently discarded.
			 *
			 * @param int   $contact_id Contact post ID.
			 * @param array $post_data  Submitted form data.
			 */
			do_action( 'npmp_after_email_signup', $contact_id, wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Listeners sanitize the fields they read; the array shape matches the documented hook contract.
		}

		wp_safe_redirect( npmp_membership_add_banner_arg( $redirect, 'npmp_signup', $result ) );
		exit;
	}

	/* ---------------- UNSUBSCRIBE ---------------- */
	if (
		'email_unsubscribe' === $action &&
		! empty( $_POST['npmp_email_unsubscribe_nonce'] ) &&
		wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['npmp_email_unsubscribe_nonce'] ) ),
			'npmp_email_unsubscribe'
		)
	) {
		if ( npmp_captcha_is_enabled() && ! npmp_captcha_verify( 'email_unsubscribe' ) ) {
			wp_safe_redirect( npmp_membership_add_banner_arg( $redirect, 'npmp_unsubscribe', 'captcha' ) );
			exit;
		}
		$email = sanitize_email( wp_unslash( $_POST['npmp_email'] ?? '' ) );

		if ( ! is_email( $email ) ) {
			wp_safe_redirect( npmp_membership_add_banner_arg( $redirect, 'npmp_unsubscribe', 'error' ) );
			exit;
		}

		// The form used to unsubscribe any typed-in address on the spot,
		// which let anyone holding the public page nonce silently
		// unsubscribe other people. A confirmation email with the signed
		// one-click link now proves address ownership. The response is
		// identical whether or not the address is on the list, so this form
		// can't be used to check who's subscribed.
		$existing = $member_manager->get_member_by_email( $email );
		if ( $existing && function_exists( 'npmp_get_one_click_unsubscribe_url' ) ) {
			$confirm_url = npmp_get_one_click_unsubscribe_url( $email );
			$subject     = __( 'Confirm your unsubscribe request', 'nonprofit-manager' );
			$body        = sprintf(
				/* translators: 1: site name, 2: confirmation URL. */
				__( "We received a request to unsubscribe this address from %1\$s.\n\nTo confirm, click this link:\n%2\$s\n\nIf you didn't request this, you can ignore this email and nothing will change.", 'nonprofit-manager' ),
				get_bloginfo( 'name' ),
				$confirm_url
			);
			npmp_send_mail( $email, $subject, $body );
		}

		wp_safe_redirect( npmp_membership_add_banner_arg( $redirect, 'npmp_unsubscribe', 'confirm' ) );
		exit;
	}

	/* ---------------- FALLBACK ---------------- */
	wp_safe_redirect( add_query_arg( 'npmp_form_error', '1', $redirect ) );
	exit;
}

/* ====================================================================
 * One-click unsubscribe (RFC 8058 + visible footer links)
 * ==================================================================== */

/**
 * Generate a signed, action-scoped token for one-click unsubscribe links.
 *
 * Scoped to the unsubscribe action so a token minted here can't be replayed
 * against any other token surface (e.g. the manage-preferences page).
 *
 * @param string $email Recipient email address.
 * @return string
 */
function npmp_generate_unsubscribe_token( $email ) {
	return substr( hash_hmac( 'sha256', 'unsubscribe|' . strtolower( (string) $email ), wp_salt( 'auth' ) ), 0, 20 );
}

/**
 * Build a one-click unsubscribe URL carrying the recipient email and token.
 *
 * @param string $email Recipient email address.
 * @return string
 */
function npmp_get_one_click_unsubscribe_url( $email ) {
	return add_query_arg(
		array(
			'action' => 'npmp_one_click_unsubscribe',
			'email'  => rawurlencode( $email ),
			'token'  => npmp_generate_unsubscribe_token( $email ),
		),
		admin_url( 'admin-post.php' )
	);
}

/**
 * Build RFC 8058 List-Unsubscribe headers for a single recipient.
 *
 * Gmail and Yahoo expect bulk senders to offer one-click List-Unsubscribe,
 * and including these headers improves inbox placement for smaller senders too.
 *
 * @param string $email Recipient email address.
 * @return array Array of header strings (empty when the email is invalid).
 */
function npmp_get_list_unsubscribe_headers( $email ) {
	$email = sanitize_email( $email );
	if ( ! $email || ! is_email( $email ) ) {
		return array();
	}

	$one_click   = npmp_get_one_click_unsubscribe_url( $email );
	$admin_email = sanitize_email( (string) get_option( 'admin_email' ) );

	$value = '<' . esc_url_raw( $one_click ) . '>';
	if ( $admin_email ) {
		$value .= ', <mailto:' . $admin_email . '?subject=' . rawurlencode( 'unsubscribe ' . $email ) . '>';
	}

	return array(
		'List-Unsubscribe: ' . $value,
		'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
	);
}

/**
 * Opt an email address out of every channel it can receive mail through.
 *
 * Flips the member record to "unsubscribed" (stops bulk newsletters) and
 * clears post/event/digest notification preferences on the same contact.
 *
 * @param string $email Email address to unsubscribe.
 * @return bool True when a matching record was found and updated.
 */
function npmp_unsubscribe_email_everywhere( $email ) {
	$email = sanitize_email( $email );
	if ( ! $email || ! is_email( $email ) ) {
		return false;
	}

	$done = false;

	if ( class_exists( 'NPMP_Member_Manager' ) ) {
		$manager  = NPMP_Member_Manager::get_instance();
		$existing = $manager->get_member_by_email( $email );

		if ( $existing ) {
			$manager->update_member( $existing->id, array( 'status' => 'unsubscribed' ) );

			// The member record and the notification-preference record are the
			// same npmp_contact post, so clear the notification opt-ins too.
			if ( function_exists( 'npmp_save_subscriber_preferences' ) ) {
				npmp_save_subscriber_preferences(
					$existing->id,
					array(
						'notify_posts'  => false,
						'notify_events' => false,
						'weekly_digest' => false,
					)
				);
			}

			$done = true;
		}
	}

	/**
	 * Fires after a one-click unsubscribe is processed.
	 *
	 * @param string $email Email address that was unsubscribed.
	 * @param bool   $done  Whether a matching record was found.
	 */
	do_action( 'npmp_after_one_click_unsubscribe', $email, $done );

	return $done;
}

add_action( 'admin_post_nopriv_npmp_one_click_unsubscribe', 'npmp_handle_one_click_unsubscribe' );
add_action( 'admin_post_npmp_one_click_unsubscribe', 'npmp_handle_one_click_unsubscribe' );

/**
 * Handle a one-click unsubscribe request.
 *
 * Authenticated by the signed token in the link, not a WordPress nonce:
 * mailbox-provider one-click POSTs (RFC 8058) carry no cookie or nonce.
 *
 * @return void
 */
function npmp_handle_one_click_unsubscribe() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Token-authenticated email link; no cookie/nonce is available for one-click POSTs.
	$email = isset( $_REQUEST['email'] ) ? sanitize_email( wp_unslash( $_REQUEST['email'] ) ) : '';
	$token = isset( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['token'] ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	$valid = $email && is_email( $email ) && hash_equals( npmp_generate_unsubscribe_token( $email ), $token );

	if ( $valid ) {
		npmp_unsubscribe_email_everywhere( $email );
	}

	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

	// RFC 8058 one-click: mailbox provider POSTs and expects a bare 2xx/4xx.
	if ( 'POST' === $method ) {
		status_header( $valid ? 200 : 400 );
		exit;
	}

	// Human clicked the visible link: send them to a friendly confirmation.
	$settings = npmp_get_membership_form_settings();
	$page_id  = absint( $settings['unsubscribe_page_id'] ?? 0 );

	if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
		$target = get_permalink( $page_id );
		if ( $target ) {
			wp_safe_redirect( npmp_membership_add_banner_arg( $target, 'npmp_unsubscribe', $valid ? 'success' : 'error' ) );
			exit;
		}
	}

	// No configured page to land on: render a minimal self-contained message.
	$message = $valid
		? __( 'You have been unsubscribed. You will no longer receive these emails.', 'nonprofit-manager' )
		: __( 'This unsubscribe link is invalid or has expired. Please use the unsubscribe form on our website.', 'nonprofit-manager' );

	wp_die(
		esc_html( $message ),
		esc_html__( 'Unsubscribe', 'nonprofit-manager' ),
		array(
			'response'  => $valid ? 200 : 400,
			'back_link' => true,
		)
	);
}
