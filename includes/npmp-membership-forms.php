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
		// Email each new subscriber a link to confirm (double opt-in). On for
		// every site from 2026.11.3, new and existing.
		'double_optin'            => 1,
	);
}

/**
 * Whether a public signup waits for the subscriber to confirm by email.
 *
 * @return bool
 */
function npmp_double_optin_enabled() {
	$settings = npmp_get_membership_form_settings();
	/**
	 * Filter whether public signups need email confirmation.
	 *
	 * @param bool $enabled The "Confirm new subscribers" setting.
	 */
	return (bool) apply_filters( 'npmp_double_optin_enabled', ! empty( $settings['double_optin'] ) );
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

	/* Unsubscribe page: the failure that silently breaks CAN-SPAM links. */
	$unsub_id = absint( $settings['unsubscribe_page_id'] ?? 0 );
	if ( ! $unsub_id ) {
		$rows[] = array(
			'status'  => 'warning',
			'label'   => __( 'Unsubscribe page', 'nonprofit-manager' ),
			'message' => __( 'No unsubscribe page is set, so the footer\'s unsubscribe link points to /unsubscribe, which may not exist. You can pick a page in the Unsubscribe Form section below.', 'nonprofit-manager' ),
		);
	} elseif ( 'publish' !== get_post_status( $unsub_id ) ) {
		$rows[] = array(
			'status'  => 'error',
			'label'   => __( 'Unsubscribe page', 'nonprofit-manager' ),
			'message' => __( 'The unsubscribe page you picked is missing or unpublished, so the unsubscribe links in your emails go nowhere. Pick a published page below.', 'nonprofit-manager' ),
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

	/* CAN-SPAM postal address. Only newsletters carry the footer. */
	if ( function_exists( 'npmp_can_spam_address_text' ) ) {
		if ( '' === npmp_can_spam_address_text() ) {
			$rows[] = array(
				'status'  => 'warning',
				'label'   => __( 'Mailing address', 'nonprofit-manager' ),
				'message' => __( 'No mailing address is set. CAN-SPAM requires a postal address in every newsletter footer, and until you add one the footer shows your admin email instead. You can add your organization address on General Settings.', 'nonprofit-manager' ),
				'link'    => admin_url( 'admin.php?page=npmp_general_settings' ),
				'link_text' => __( 'Open General Settings', 'nonprofit-manager' ),
			);
		} else {
			$rows[] = array(
				'status'  => 'ok',
				'label'   => __( 'Mailing address', 'nonprofit-manager' ),
				'message' => __( 'A postal mailing address is set for the CAN-SPAM footer.', 'nonprofit-manager' ),
			);
		}
	}

	/* Signup page (informational). */
	$signup_id = absint( $settings['signup_page_id'] ?? 0 );
	if ( $signup_id && 'publish' !== get_post_status( $signup_id ) ) {
		$rows[] = array(
			'status'  => 'error',
			'label'   => __( 'Signup page', 'nonprofit-manager' ),
			'message' => __( 'The signup page you picked is missing or unpublished. Pick a published page below.', 'nonprofit-manager' ),
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
		'ok'      => array( 'dashicons-yes-alt', 'npmp-status-icon--ok', __( 'Done', 'nonprofit-manager' ) ),
		'warning' => array( 'dashicons-warning', 'npmp-status-icon--warning', __( 'Needs setup', 'nonprofit-manager' ) ),
		'error'   => array( 'dashicons-dismiss', 'npmp-status-icon--error', __( 'Problem', 'nonprofit-manager' ) ),
	);

	echo '<div class="npmp-settings-card">';
	echo '<h2>' . esc_html__( 'Setup status', 'nonprofit-manager' ) . '</h2>';
	echo '<table class="widefat striped npmp-status-table">';
	echo '<thead><tr><th scope="col">' . esc_html__( 'Status', 'nonprofit-manager' ) . '</th><th scope="col">' . esc_html__( 'Item', 'nonprofit-manager' ) . '</th><th scope="col">' . esc_html__( 'Details', 'nonprofit-manager' ) . '</th></tr></thead><tbody>';
	foreach ( $rows as $row ) {
		$icon = isset( $icons[ $row['status'] ] ) ? $icons[ $row['status'] ] : $icons['ok'];
		echo '<tr>';
		echo '<td class="npmp-status-table__state"><span class="dashicons ' . esc_attr( $icon[0] . ' ' . $icon[1] ) . '" aria-hidden="true"></span> ' . esc_html( $icon[2] ) . '</td>';
		echo '<th scope="row">' . esc_html( $row['label'] ) . '</th>';
		echo '<td>' . esc_html( $row['message'] );
		if ( ! empty( $row['link'] ) ) {
			echo ' <a href="' . esc_url( $row['link'] ) . '">' . esc_html( $row['link_text'] ) . '</a>';
		}
		echo '</td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
	echo '</div>';
}

/**
 * Seed starter levels the first time anyone opens Membership Settings.
 *
 * General Settings used to do this every time it found the list empty, so
 * deleting your last level and then opening General Settings brought all
 * four back. This runs only when the option has never been saved.
 *
 * @return void
 */
function npmp_maybe_seed_membership_levels() {
	if ( false === get_option( 'npmp_membership_levels', false ) ) {
		update_option( 'npmp_membership_levels', array( 'Subscriber', 'Member', 'Donor', 'Director' ) );
	}
}

/**
 * Handle the membership level forms on Membership Settings.
 *
 * Levels are managed on this screen only. The handler acts on a POST to
 * admin.php?page=npmp_membership_forms, then redirects back with a notice
 * flag so a refresh can't post twice. General Settings used to handle the
 * same nonce names first and send people to General Settings.
 * tests/test-settings-handlers.php pins this.
 *
 * @return void
 */
function npmp_membership_levels_handle_post() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing only. Each action below checks its own nonce.
	if ( ! isset( $_GET['page'] ) || 'npmp_membership_forms' !== $_GET['page'] ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$levels = npmp_get_membership_levels_array();
	$notice = '';

	if (
		isset( $_POST['npmp_add_level_nonce'] ) &&
		wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_add_level_nonce'] ) ), 'npmp_add_membership_level' )
	) {
		$new_level = isset( $_POST['npmp_new_level'] ) ? sanitize_text_field( wp_unslash( $_POST['npmp_new_level'] ) ) : '';
		if ( '' !== $new_level && ! in_array( $new_level, $levels, true ) ) {
			$levels[] = $new_level;
			update_option( 'npmp_membership_levels', $levels );
		}
		$notice = 'added';
	} elseif (
		isset( $_POST['npmp_remove_level_nonce'] ) &&
		wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_remove_level_nonce'] ) ), 'npmp_remove_membership_level' )
	) {
		$level_to_remove = isset( $_POST['npmp_level_to_remove'] ) ? sanitize_text_field( wp_unslash( $_POST['npmp_level_to_remove'] ) ) : '';
		if ( '' !== $level_to_remove ) {
			update_option( 'npmp_membership_levels', array_values( array_diff( $levels, array( $level_to_remove ) ) ) );
			// A removed level can't stay the default, or new signups keep
			// getting a level that no longer shows anywhere.
			if ( get_option( 'npmp_default_membership_level', '' ) === $level_to_remove ) {
				update_option( 'npmp_default_membership_level', '' );
			}
		}
		$notice = 'removed';
	} elseif (
		isset( $_POST['npmp_default_level_nonce'] ) &&
		wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_default_level_nonce'] ) ), 'npmp_save_default_level' )
	) {
		$default_level = isset( $_POST['npmp_default_membership_level'] ) ? sanitize_text_field( wp_unslash( $_POST['npmp_default_membership_level'] ) ) : '';
		update_option( 'npmp_default_membership_level', $default_level );
		$notice = 'default';
	}

	if ( '' === $notice ) {
		return;
	}
	wp_safe_redirect( add_query_arg( 'npmp_levels', $notice, admin_url( 'admin.php?page=npmp_membership_forms' ) ) . '#npmp-levels' );
	exit;
}
add_action( 'admin_init', 'npmp_membership_levels_handle_post' );

function npmp_render_membership_forms_page() {
	npmp_verify_admin_access();

	$option_key = 'npmp_membership_form_settings';
	$defaults   = npmp_membership_form_default_settings();
	$settings   = wp_parse_args( get_option( $option_key, array() ), $defaults );
	$message  = '';
	$accent_note = '';

	npmp_maybe_seed_membership_levels();
	$membership_levels        = npmp_get_membership_levels_array();
	$default_membership_level = get_option( 'npmp_default_membership_level', '' );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice flag set by npmp_membership_levels_handle_post().
	$levels_notice = isset( $_GET['npmp_levels'] ) ? sanitize_key( wp_unslash( $_GET['npmp_levels'] ) ) : '';
	if ( 'added' === $levels_notice ) {
		$message = sprintf( /* translators: %s: singular word for a membership level, such as Membership Level or Dues Level. */ __( '%s added.', 'nonprofit-manager' ), npmp_label( 'level' ) );
	} elseif ( 'removed' === $levels_notice ) {
		$message = sprintf( /* translators: %s: singular word for a membership level, such as Membership Level or Dues Level. */ __( '%s removed.', 'nonprofit-manager' ), npmp_label( 'level' ) );
	} elseif ( 'default' === $levels_notice ) {
		$message = sprintf( /* translators: %s: lowercase singular word for a membership level, such as membership level or dues level. */ __( 'Default %s saved.', 'nonprofit-manager' ), npmp_label( 'level_lc' ) );
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
		// A checkbox posts nothing when it's off, so the loop above can't
		// turn it off.
		$settings['double_optin'] = empty( $_POST['double_optin'] ) ? 0 : 1;
		update_option( $option_key, $settings );
		$settings = npmp_get_membership_form_settings( true );
		$message = __( 'Settings saved.', 'nonprofit-manager' );

		// The forms' button color. Anything that isn't a hex color saves the
		// brand default, which prints no extra CSS.
		if ( isset( $_POST['npmp_forms_accent'] ) && function_exists( 'npmp_forms_accent_normalize' ) ) {
			$accent = npmp_forms_accent_normalize( sanitize_text_field( wp_unslash( $_POST['npmp_forms_accent'] ) ) );
			update_option( 'npmp_forms_accent', '' !== $accent ? $accent : npmp_forms_accent_default() );
			$accent_note = npmp_forms_accent_notice( npmp_forms_accent() );
		}
	}

	/* ---------- Render ---------- */
	npmp_admin_page_header(
		__( 'Membership Settings', 'nonprofit-manager' ),
		sprintf( /* translators: %s: lowercase plural word for membership levels, such as membership levels or dues levels. */ __( 'Your %s and your signup and unsubscribe forms.', 'nonprofit-manager' ), npmp_label( 'levels_lc' ) )
	);

	if ( $message ) {
		npmp_admin_notice_success( $message );
	}
	if ( ! empty( $accent_note ) ) {
		echo '<div class="notice notice-warning"><p>' . esc_html( $accent_note ) . '</p></div>';
	}

	/* ---------- Membership Levels Section ---------- */
	npmp_render_membership_setup_status();
	?>

	<div class="npmp-settings-card" id="npmp-levels">
		<h2><?php echo esc_html( npmp_label( 'levels' ) ); ?></h2>
		<p><?php echo esc_html( sprintf( /* translators: 1: lowercase plural word for membership levels, such as membership levels or dues levels, 2: lowercase word for the group, such as nonprofit or club, 3: lowercase plural word for members, such as members. */ __( 'Set up %1$s for your %2$s. You can pick one when you add or edit %3$s.', 'nonprofit-manager' ), npmp_label( 'levels_lc' ), npmp_label( 'org_lc' ), npmp_label( 'members_lc' ) ) ); ?></p>

		<?php if ( ! empty( $membership_levels ) ) : ?>
			<table class="widefat striped npmp-levels-table">
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html( npmp_label( 'level' ) ); ?></th>
						<th scope="col" class="npmp-levels-table__action"><?php esc_html_e( 'Delete', 'nonprofit-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $membership_levels as $level ) : ?>
						<tr>
							<td><?php echo esc_html( $level ); ?></td>
							<td class="npmp-levels-table__action">
								<button type="button" class="button-link npmp-icon-button npmp-delete-level" data-level="<?php echo esc_attr( $level ); ?>">
									<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
									<span class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: %s: name of a membership level. */ __( 'Delete level %s', 'nonprofit-manager' ), $level ) ); ?></span>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p><em><?php echo esc_html( sprintf( /* translators: %s: lowercase plural word for membership levels, such as membership levels or dues levels. */ __( 'No %s yet.', 'nonprofit-manager' ), npmp_label( 'levels_lc' ) ) ); ?></em></p>
		<?php endif; ?>

		<?php if ( ! npmp_is_pro() ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: URL to upgrade page */
					wp_kses_post( __( 'Want to charge dues for a level and bill them automatically through Stripe? <a href="%s" target="_blank">Upgrade to Nonprofit Manager Pro</a>.', 'nonprofit-manager' ) ),
					esc_url( npmp_get_upgrade_url( 'membership_dues' ) )
				);
				?>
			</p>
		<?php endif; ?>

		<form method="post" class="npmp-add-level-form">
			<?php wp_nonce_field( 'npmp_add_membership_level', 'npmp_add_level_nonce' ); ?>
			<label for="npmp_new_level" class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: %s: lowercase singular word for a membership level, such as membership level or dues level. */ __( 'New %s name', 'nonprofit-manager' ), npmp_label( 'level_lc' ) ) ); ?></label>
			<input
				type="text"
				id="npmp_new_level"
				name="npmp_new_level"
				placeholder="<?php echo esc_attr( sprintf( /* translators: %s: lowercase singular word for a membership level, such as membership level or dues level. */ __( 'Enter a new %s name', 'nonprofit-manager' ), npmp_label( 'level_lc' ) ) ); ?>"
				required
			>
			<?php submit_button( __( 'Add level', 'nonprofit-manager' ), 'secondary', 'submit', false ); ?>
		</form>

		<form method="post" id="npmp-delete-level-form" hidden>
			<?php wp_nonce_field( 'npmp_remove_membership_level', 'npmp_remove_level_nonce' ); ?>
			<input type="hidden" name="npmp_level_to_remove" id="npmp_level_to_remove">
		</form>

		<hr class="npmp-card-divider">

		<form method="post">
			<?php wp_nonce_field( 'npmp_save_default_level', 'npmp_default_level_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="npmp_default_membership_level"><?php esc_html_e( 'Default level', 'nonprofit-manager' ); ?></label>
					</th>
					<td>
						<select name="npmp_default_membership_level" id="npmp_default_membership_level" class="regular-text" aria-describedby="npmp_default_membership_level_help">
							<option value=""><?php echo esc_html( sprintf( /* translators: %s: the level a signup gets when no default is set. */ __( 'None (new signups get "%s")', 'nonprofit-manager' ), 'member' ) ); ?></option>
							<?php foreach ( $membership_levels as $level ) : ?>
								<option value="<?php echo esc_attr( $level ); ?>" <?php selected( $default_membership_level, $level ); ?>>
									<?php echo esc_html( $level ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description" id="npmp_default_membership_level_help">
							<?php esc_html_e( 'People who join through a signup form get this level.', 'nonprofit-manager' ); ?>
						</p>
						<?php submit_button( __( 'Save default level', 'nonprofit-manager' ), 'primary', 'submit', false ); ?>
					</td>
				</tr>
			</table>
		</form>

		<script>
			document.querySelectorAll('.npmp-delete-level').forEach(function(el) {
				el.addEventListener('click', function() {
					if (window.confirm('<?php echo esc_js( sprintf( /* translators: %s: lowercase singular word for a membership level, such as membership level or dues level. */ __( 'Delete this %s? People who have it keep it on their record, but you can\'t pick it for anyone new.', 'nonprofit-manager' ), npmp_label( 'level_lc' ) ) ); ?>')) {
						document.getElementById('npmp_level_to_remove').value = this.getAttribute('data-level');
						document.getElementById('npmp-delete-level-form').submit();
					}
				});
			});
		</script>
	</div>

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
	echo '<h2>' . esc_html__( 'Signup form', 'nonprofit-manager' ) . '</h2><p><code>[npmp_email_signup]</code> ' . esc_html__( 'You can paste this shortcode on any page, or pick a page below and the form shows up at the bottom of it.', 'nonprofit-manager' ) . '</p>';
	echo '<table class="form-table" role="presentation">';
	$field( 'signup_heading', esc_html__( 'Heading', 'nonprofit-manager' ) );
	$field( 'signup_description', esc_html__( 'Description', 'nonprofit-manager' ), 'textarea' );
	echo '<tr><th scope="row">' . esc_html__( 'Confirm new subscribers', 'nonprofit-manager' ) . '</th><td><label><input type="checkbox" name="double_optin" value="1"' . checked( ! empty( $settings['double_optin'] ), true, false ) . '> ' . esc_html__( 'Email each new subscriber a link to confirm before they get any email', 'nonprofit-manager' ) . '</label>';
	echo '<p class="description">' . esc_html__( 'Stops people from signing up someone else and catches typos. Until they click the link, they show as Pending on the Contacts screen.', 'nonprofit-manager' ) . '</p></td></tr>';
	echo '<tr><th scope="row"><label for="signup_page_id">' . esc_html__( 'Add to page', 'nonprofit-manager' ) . '</label></th><td>';
	wp_dropdown_pages(
		array(
			'name'             => 'signup_page_id',
			'id'               => 'signup_page_id',
			'selected'         => absint( $settings['signup_page_id'] ),
			'show_option_none' => '- ' . esc_html__( 'None', 'nonprofit-manager' ) . ' -',
			'option_none_value'=> 0,
		)
	);
	echo '<p class="description">' . esc_html__( 'Nonprofit Manager adds the form below the page\'s own content.', 'nonprofit-manager' ) . '</p></td></tr></table>';

	/* Unsubscribe section */
	echo '<h2>' . esc_html__( 'Unsubscribe form', 'nonprofit-manager' ) . '</h2><p><code>[npmp_email_unsubscribe]</code> ' . esc_html__( 'You can paste this shortcode on any page, or pick a page below and the form shows up at the bottom of it.', 'nonprofit-manager' ) . '</p>';
	echo '<table class="form-table" role="presentation">';
	$field( 'unsubscribe_heading', esc_html__( 'Heading', 'nonprofit-manager' ) );
	$field( 'unsubscribe_description', esc_html__( 'Description', 'nonprofit-manager' ), 'textarea' );
	echo '<tr><th scope="row"><label for="unsubscribe_page_id">' . esc_html__( 'Add to page', 'nonprofit-manager' ) . '</label></th><td>';
	wp_dropdown_pages(
		array(
			'name'             => 'unsubscribe_page_id',
			'id'               => 'unsubscribe_page_id',
			'selected'         => absint( $settings['unsubscribe_page_id'] ),
			'show_option_none' => '- ' . esc_html__( 'None', 'nonprofit-manager' ) . ' -',
			'option_none_value'=> 0,
		)
	);
	echo '<p class="description">' . esc_html__( 'Nonprofit Manager adds the form below the page\'s own content.', 'nonprofit-manager' ) . '</p></td></tr></table>';

	/* Form color */
	if ( function_exists( 'npmp_forms_accent' ) ) {
		$accent       = npmp_forms_accent();
		$accent_check = npmp_forms_accent_notice( $accent );
		echo '<h2>' . esc_html__( 'Form color', 'nonprofit-manager' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="npmp_forms_accent">' . esc_html__( 'Button color', 'nonprofit-manager' ) . '</label></th><td>';
		echo '<input type="color" name="npmp_forms_accent" id="npmp_forms_accent" value="' . esc_attr( $accent ) . '" aria-describedby="npmp_forms_accent_help">';
		echo '<p class="description" id="npmp_forms_accent_help">' . esc_html(
			sprintf(
				/* translators: %s: the default color as a hex code. */
				__( 'You can set the color of the buttons and focus outlines on your signup, unsubscribe, preferences, contact, and membership forms. The default is %s.', 'nonprofit-manager' ),
				npmp_forms_accent_default()
			)
		) . '</p>';
		if ( '' !== $accent_check ) {
			echo '<p class="description npmp-forms-accent-warning">' . esc_html( $accent_check ) . '</p>';
		}
		echo '</td></tr></table>';
	}

	/* Shared labels */
	echo '<h2>' . esc_html__( 'Shared labels and messages', 'nonprofit-manager' ) . '</h2>';
	echo '<table class="form-table" role="presentation">';
	$field( 'name_label',          esc_html__( 'Name field label', 'nonprofit-manager' ) );
	$field( 'email_label',         esc_html__( 'Email field label', 'nonprofit-manager' ) );
	$field( 'signup_button',       esc_html__( 'Signup button text', 'nonprofit-manager' ) );
	$field( 'unsubscribe_button',  esc_html__( 'Unsubscribe button text', 'nonprofit-manager' ) );
	$field( 'success_message',     esc_html__( 'Success message', 'nonprofit-manager' ) );
	$field( 'error_message',       esc_html__( 'Error message', 'nonprofit-manager' ) );
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
		if ( 'busy' === $status ) {
			return '<div class="npmp-form-banner npmp-' . esc_attr( $type ) . ' npmp-status-error" role="alert"><p>' . esc_html__( 'We got too many requests from here in a short time. Please try again in an hour.', 'nonprofit-manager' ) . '</p></div>';
		}
		if ( 'unsubscribed' === $status ) {
			// The one-click link landed here. The shared success message
			// ("Your request has been processed") didn't say what happened.
			return '<div class="npmp-form-banner npmp-' . esc_attr( $type ) . ' npmp-status-success" role="status"><p>' . esc_html__( 'You\'re unsubscribed. We won\'t send you any more of these emails.', 'nonprofit-manager' ) . '</p></div>';
		}
		if ( 'check_email' === $status ) {
			return '<div class="npmp-form-banner npmp-' . esc_attr( $type ) . ' npmp-status-success" role="status"><p>' . esc_html__( 'Thanks! Check your inbox for an email from us and click the link in it to confirm your subscription.', 'nonprofit-manager' ) . '</p></div>';
		}
		if ( 'confirmed' === $status ) {
			return '<div class="npmp-form-banner npmp-' . esc_attr( $type ) . ' npmp-status-success" role="status"><p>' . esc_html__( 'You\'re subscribed. Thanks for confirming.', 'nonprofit-manager' ) . '</p></div>';
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

/**
 * Default [npmp_email_signup] attributes, extended by add-ons.
 *
 * @return array
 */
function npmp_signup_form_default_atts() {
	/**
	 * Filter the attributes [npmp_email_signup] and the Email Signup block
	 * accept (free has none). Pro adds `'groups' => ''`, so
	 * `[npmp_email_signup groups="art,history"]` puts each new member in
	 * those groups. The form signs the non-empty values into a hidden field,
	 * so a visitor can't change them, and `npmp_after_email_signup` gets them
	 * back as `$atts`.
	 *
	 * @param array $defaults Attribute => default value.
	 */
	$defaults = apply_filters( 'npmp_signup_form_default_atts', array() );
	return is_array( $defaults ) ? $defaults : array();
}

/**
 * Sign a signup form's attributes for its hidden field.
 *
 * @param array $atts Attributes (only non-empty scalars are kept).
 * @return string payload.signature, or '' when there's nothing to carry.
 */
function npmp_signup_atts_token( $atts ) {
	$keep = array();
	foreach ( (array) $atts as $key => $value ) {
		if ( is_scalar( $value ) && '' !== (string) $value ) {
			$keep[ sanitize_key( (string) $key ) ] = (string) $value;
		}
	}
	if ( ! $keep ) {
		return '';
	}
	ksort( $keep );
	$payload = rtrim( strtr( base64_encode( (string) wp_json_encode( $keep ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe transport of signed form attributes, not obfuscation.
	return $payload . '.' . hash_hmac( 'sha256', $payload, wp_salt( 'nonce' ) );
}

/**
 * Read back a signup form's signed attributes. A missing or tampered field
 * gives the defaults.
 *
 * @param string $token Value of the hidden npmp_signup_atts field.
 * @return array
 */
function npmp_signup_atts_from_token( $token ) {
	$defaults = npmp_signup_form_default_atts();
	$parts    = explode( '.', (string) $token );
	if ( 2 !== count( $parts ) || ! hash_equals( hash_hmac( 'sha256', $parts[0], wp_salt( 'nonce' ) ), $parts[1] ) ) {
		return $defaults;
	}
	$json    = base64_decode( strtr( $parts[0], '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Reverses npmp_signup_atts_token().
	$decoded = is_string( $json ) ? json_decode( $json, true ) : null;
	if ( ! is_array( $decoded ) ) {
		return $defaults;
	}
	// Only attributes an add-on still accepts come back.
	return array_merge( $defaults, array_intersect_key( $decoded, $defaults ) );
}

/**
 * A note for administrators naming [npmp_email_signup] attributes that no
 * active plugin on the site accepts.
 *
 * Add-ons register their attributes through npmp_signup_form_default_atts,
 * and shortcode_atts() drops the rest without a word. So Pro's
 * groups="" choose="yes" pasted onto a site without Pro showed a plain form
 * and read as a bug (Pete at U3A, wordpress.org thread "Some minors in
 * subscribing / unsubscribing"). Visitors see nothing.
 *
 * @param array $unknown Attribute names the form ignored.
 * @return string HTML, or '' for a visitor or when every attribute is known.
 */
function npmp_signup_form_unknown_atts_note( $unknown ) {
	$names = array();
	foreach ( (array) $unknown as $name ) {
		if ( is_string( $name ) && '' !== sanitize_key( $name ) ) {
			$names[] = sanitize_key( $name );
		}
	}
	if ( ! $names || ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
		return '';
	}
	$names = array_values( array_unique( $names ) );
	$text  = sprintf(
		/* translators: %s: comma-separated shortcode attribute names, for example "groups, choose". */
		_n(
			'Only administrators see this note. Visitors get this form without the attribute %s. To use it, you need Nonprofit Manager Pro with an active license, or check the spelling.',
			'Only administrators see this note. Visitors get this form without the attributes %s. To use them, you need Nonprofit Manager Pro with an active license, or check the spelling.',
			count( $names ),
			'nonprofit-manager'
		),
		implode( ', ', $names )
	);
	return '<p class="npmp-form-admin-note"><em>' . esc_html( $text ) . '</em></p>';
}

/**
 * Render the email signup form.
 *
 * @param array|string $atts Shortcode attributes.
 * @return string
 */
function npmp_email_signup_shortcode( $atts = array() ) {
	$raw      = is_array( $atts ) ? $atts : array();
	$defaults = npmp_signup_form_default_atts();
	$atts     = shortcode_atts( $defaults, $raw, 'npmp_email_signup' );
	$s        = npmp_get_membership_form_settings();
	$html     = npmp_signup_form_unknown_atts_note( array_keys( array_diff_key( $raw, $defaults ) ) );
	$html    .= npmp_get_banner_html( 'npmp_signup', $s );

	$fields = '
		<p><label>' . esc_html( $s['name_label'] ) . '<br><input type="text" name="npmp_name" required></label></p>
		<p><label>' . esc_html( $s['email_label'] ) . '<br><input type="email" name="npmp_email" required></label></p>';

	/**
	 * Filter the signup form fields to append extra inputs.
	 *
	 * The subscriber notification preferences feature (post/event/digest
	 * checkboxes) renders through this filter. The admin screen promised
	 * those checkboxes "appear on the signup form" but nothing applied the
	 * filter, so they never did. Pro adds a group's checkboxes here when the
	 * form names more than one group.
	 *
	 * @param string $fields Form field HTML so far.
	 * @param array  $atts   The form's attributes, after defaults.
	 */
	$fields = apply_filters( 'npmp_signup_form_after_fields', $fields, $atts );

	$atts_token = npmp_signup_atts_token( $atts );
	$atts_field = '' !== $atts_token ? '<input type="hidden" name="npmp_signup_atts" value="' . esc_attr( $atts_token ) . '">' : '';

	$html .= '
	<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post" class="npmp-email-signup">
		<h3>' . esc_html( $s['signup_heading'] ) . '</h3>' .
		( $s['signup_description'] ? '<p>' . esc_html( $s['signup_description'] ) . '</p>' : '' ) .
		$fields . '
		<p class="npmp-hp" aria-hidden="true" style="position:absolute;left:-9999px;"><label>' . esc_html__( 'Leave this field empty', 'nonprofit-manager' ) . '<input type="text" name="npmp_signup_website" tabindex="-1" autocomplete="off"></label></p>
		' . npmp_captcha_render_widget( 'email_signup' ) . '
		' . wp_nonce_field( 'npmp_email_signup', 'npmp_email_signup_nonce', true, false ) . '
		<input type="hidden" name="npmp_action" value="email_signup">
		<input type="hidden" name="action" value="npmp_handle_form">
		' . $atts_field . '
		<p><button type="submit">' . esc_html( $s['signup_button'] ) . '</button></p>
	</form>';
	return $html;
}
add_shortcode( 'npmp_email_signup', 'npmp_email_signup_shortcode' );

/* ------------ Unsubscribe shortcode ------------ */

/**
 * The address a newsletter's unsubscribe link signed, when the link is valid.
 *
 * @return string Email address, or '' for a bare visit or a bad token.
 */
function npmp_unsubscribe_link_email() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Token-authenticated email link.
	if ( empty( $_GET['npmp_email'] ) || empty( $_GET['npmp_token'] ) ) {
		return '';
	}
	$email = sanitize_email( wp_unslash( $_GET['npmp_email'] ) );
	$token = sanitize_text_field( wp_unslash( $_GET['npmp_token'] ) );
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	if ( ! $email || ! is_email( $email ) || ! hash_equals( npmp_generate_unsubscribe_token( $email ), $token ) ) {
		return '';
	}
	return $email;
}

function npmp_email_unsubscribe_shortcode() {
	$s    = npmp_get_membership_form_settings();
	$html = npmp_get_banner_html( 'npmp_unsubscribe', $s );

	// Arrived from the link in a newsletter: the address is already known and
	// signed, so ask for one button press. A GET changes nothing, so a mail
	// scanner that opens the link doesn't unsubscribe anyone. The button posts
	// to the one-click handler, which lands back here with "You're unsubscribed".
	$link_email = npmp_unsubscribe_link_email();
	if ( '' !== $link_email ) {
		$bare_url = remove_query_arg( array( 'npmp_email', 'npmp_token' ) );
		$html    .= '
	<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post" class="npmp-email-unsubscribe npmp-email-unsubscribe-link">
		<h3>' . esc_html( $s['unsubscribe_heading'] ) . '</h3>
		<p>' . sprintf(
			/* translators: %s: the subscriber's email address. */
			esc_html__( 'Stop sending emails to %s?', 'nonprofit-manager' ),
			'<strong>' . esc_html( $link_email ) . '</strong>'
		) . '</p>
		<input type="hidden" name="action" value="npmp_one_click_unsubscribe">
		<input type="hidden" name="email" value="' . esc_attr( $link_email ) . '">
		<input type="hidden" name="token" value="' . esc_attr( npmp_generate_unsubscribe_token( $link_email ) ) . '">
		<input type="hidden" name="npmp_confirm" value="1">
		<p><button type="submit">' . esc_html( $s['unsubscribe_button'] ) . '</button></p>
		<p class="npmp-unsubscribe-other"><a href="' . esc_url( $bare_url ) . '">' . esc_html__( 'Unsubscribe a different address', 'nonprofit-manager' ) . '</a></p>
	</form>';
		return $html;
	}

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
		// Honeypot: a bot filled the hidden field. Answer as if it worked.
		if ( '' !== trim( (string) wp_unslash( $_POST['npmp_signup_website'] ?? '' ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only tested for emptiness.
			wp_safe_redirect( npmp_membership_add_banner_arg( $redirect, 'npmp_signup', 'success' ) );
			exit;
		}

		$name  = sanitize_text_field( wp_unslash( $_POST['npmp_name'] ?? '' ) );
		$email = sanitize_email( wp_unslash( $_POST['npmp_email'] ?? '' ) );

		if ( ! is_email( $email ) ) {
			wp_safe_redirect( npmp_membership_add_banner_arg( $redirect, 'npmp_signup', 'error' ) );
			exit;
		}

		// Every signup can email the address it names (the confirmation link,
		// or Pro's welcome automation for a new contact), and the form is
		// public. Cap it per visitor, and site-wide, so it can't be used to
		// send a site's mail to a list of strangers. Both are filterable
		// through npmp_rate_limit_max ('signup' and 'signup_site').
		if ( ! npmp_rate_limit_allows( 'signup', 10, HOUR_IN_SECONDS ) || ! npmp_rate_limit_allows( 'signup_site', 200, HOUR_IN_SECONDS, 'site' ) ) {
			wp_safe_redirect( npmp_membership_add_banner_arg( $redirect, 'npmp_signup', 'busy' ) );
			exit;
		}

		$existing   = $member_manager->get_member_by_email( $email );
		$contact_id = 0;
		$atts_token = sanitize_text_field( wp_unslash( $_POST['npmp_signup_atts'] ?? '' ) );
		if ( ( ! $existing || 'unsubscribed' !== ( $existing->status ?? '' ) ) && npmp_double_optin_enabled() ) {
			// Double opt-in: nothing a subscriber would notice happens until
			// they click the link in their own inbox. A new address is saved as
			// Pending, which no newsletter, digest or notification reaches. The
			// visitor sees the same answer whether or not the address was known.
			$is_new = ! $existing || ( 'pending' === ( $existing->status ?? '' ) && npmp_signup_pending_is_new( (int) $existing->id ) );
			if ( $existing ) {
				$contact_id = (int) $existing->id;
			} else {
				$default_level = sanitize_text_field( get_option( 'npmp_default_membership_level', '' ) );
				$added         = $member_manager->add_member(
					array(
						'name'             => $name,
						'email'            => $email,
						'status'           => 'pending',
						'membership_level' => $default_level ? $default_level : 'member',
						'source'           => 'form_signup',
					)
				);
				if ( is_wp_error( $added ) ) {
					wp_safe_redirect( npmp_membership_add_banner_arg( $redirect, 'npmp_signup', 'error' ) );
					exit;
				}
				$contact_id = (int) $added;
			}
			npmp_send_signup_confirmation( $contact_id, $email, $name, wp_unslash( $_POST ), $atts_token, $is_new ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Stored for npmp_after_email_signup, whose listeners sanitize what they read.
			wp_safe_redirect( npmp_membership_add_banner_arg( $redirect, 'npmp_signup', 'check_email' ) );
			exit;
		} elseif ( $existing && 'unsubscribed' === ( $existing->status ?? '' ) ) {
			// Someone who unsubscribed is only put back on the list once they
			// confirm from their own inbox. The form used to flip them straight
			// back to subscribed, so anyone could re-subscribe a stranger. The
			// visitor sees the same answer either way.
			npmp_send_resubscribe_confirmation( $email );
			wp_safe_redirect( npmp_membership_add_banner_arg( $redirect, 'npmp_signup', 'success' ) );
			exit;
		} elseif ( $existing ) {
			// Existing contact: subscribe them, but never overwrite a name or
			// level someone else typed. A public form is not the place to
			// rename a contact.
			$default_level = sanitize_text_field( get_option( 'npmp_default_membership_level', '' ) );
			$update        = $member_manager->update_member(
				$existing->id,
				array(
					'name'             => ( '' !== (string) $existing->name ) ? $existing->name : $name,
					'status'           => 'subscribed',
					// Same fallback a new contact gets below.
					'membership_level' => $existing->membership_level ?: ( $default_level ? $default_level : 'member' ),
				)
			);
			$result     = is_wp_error( $update ) ? 'error' : 'success';
			$contact_id = (int) $existing->id;
		} else {
			// New contacts get the admin-configured "Default Level" (Membership
			// Settings / General Settings) when one is set, matching the
			// description shown next to that setting. Fall back to the
			// generic 'member' label when no default has been configured.
			$default_level = sanitize_text_field( get_option( 'npmp_default_membership_level', '' ) );
			$added         = $member_manager->add_member(
				array(
					'name'             => $name,
					'email'            => $email,
					'status'           => 'subscribed',
					'membership_level' => $default_level ? $default_level : 'member',
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
			 * Pro adds the new or returning member to the form's groups
			 * from `$atts['groups']` (and any group checkboxes it printed).
			 *
			 * @param int   $contact_id Contact post ID.
			 * @param array $post_data  Submitted form data.
			 * @param array $atts       The form's attributes, read back from its
			 *                          signed hidden field (defaults when missing).
			 * @param bool  $is_new     True when this signup created the contact.
			 */
			$signup_atts = npmp_signup_atts_from_token( $atts_token );
			do_action( 'npmp_after_email_signup', $contact_id, wp_unslash( $_POST ), $signup_atts, ! $existing ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Listeners sanitize the fields they read. The array shape matches the documented hook contract.
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

		// Each request emails the address it names, so cap it the way signup
		// is capped. Without this, anyone could flood a member's inbox with
		// confirmation emails from this site.
		if ( ! npmp_rate_limit_allows( 'unsubscribe', 10, HOUR_IN_SECONDS ) || ! npmp_rate_limit_allows( 'unsubscribe_site', 200, HOUR_IN_SECONDS, 'site' ) ) {
			wp_safe_redirect( npmp_membership_add_banner_arg( $redirect, 'npmp_unsubscribe', 'busy' ) );
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
 * Signed token for the "put me back on the list" link. Scoped to its own
 * action so an unsubscribe token can't be replayed here, or the reverse.
 *
 * @param string $email Recipient email address.
 * @return string
 */
function npmp_generate_resubscribe_token( $email ) {
	return substr( hash_hmac( 'sha256', 'resubscribe|' . strtolower( (string) $email ), wp_salt( 'auth' ) ), 0, 20 );
}

/**
 * Email an unsubscribed contact a signed link that puts them back on the list.
 *
 * @param string $email Recipient email address.
 * @return void
 */
function npmp_send_resubscribe_confirmation( $email ) {
	$url = add_query_arg(
		array(
			'action' => 'npmp_confirm_resubscribe',
			'email'  => rawurlencode( $email ),
			'token'  => npmp_generate_resubscribe_token( $email ),
		),
		admin_url( 'admin-post.php' )
	);
	$subject = __( 'Confirm you want to subscribe again', 'nonprofit-manager' );
	$body    = sprintf(
		/* translators: 1: site name, 2: confirmation URL. */
		__( "We received a request to add this address back to the %1\$s email list.

To confirm, open this link and press the button:
%2\$s

If you didn't ask for this, ignore this email and you'll stay unsubscribed.", 'nonprofit-manager' ),
		get_bloginfo( 'name' ),
		$url
	);
	if ( function_exists( 'npmp_send_mail' ) ) {
		npmp_send_mail( $email, $subject, $body );
	}
}

add_action( 'admin_post_nopriv_npmp_confirm_resubscribe', 'npmp_handle_confirm_resubscribe' );
add_action( 'admin_post_npmp_confirm_resubscribe', 'npmp_handle_confirm_resubscribe' );

/**
 * Confirm a re-subscribe request from the signed email link. A GET shows a
 * button and only the POST changes anything, so a mail scanner that opens the
 * link doesn't subscribe anyone.
 *
 * @return void
 */
function npmp_handle_confirm_resubscribe() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- Token-authenticated email link.
	$email = isset( $_REQUEST['email'] ) ? sanitize_email( wp_unslash( $_REQUEST['email'] ) ) : '';
	$token = isset( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['token'] ) ) : '';
	// phpcs:enable
	$valid  = $email && is_email( $email ) && hash_equals( npmp_generate_resubscribe_token( $email ), $token );
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

	if ( ! $valid ) {
		wp_die( esc_html__( 'This link is invalid. Please use the signup form on our website.', 'nonprofit-manager' ), esc_html__( 'Subscribe', 'nonprofit-manager' ), array( 'response' => 400, 'back_link' => true ) );
	}

	if ( 'POST' !== $method ) {
		npmp_render_email_link_confirm_page(
			__( 'Subscribe again', 'nonprofit-manager' ),
			__( 'Press the button to add this address back to our email list.', 'nonprofit-manager' ),
			__( 'Subscribe', 'nonprofit-manager' ),
			'npmp_confirm_resubscribe',
			$email,
			$token
		);
	}

	if ( class_exists( 'NPMP_Member_Manager' ) ) {
		$manager  = NPMP_Member_Manager::get_instance();
		$existing = $manager->get_member_by_email( $email );
		if ( $existing ) {
			$manager->update_member( $existing->id, array( 'status' => 'subscribed' ) );
		}
	}
	wp_die( esc_html__( "You're subscribed again. Thanks for coming back.", 'nonprofit-manager' ), esc_html__( 'Subscribe', 'nonprofit-manager' ), array( 'response' => 200, 'back_link' => true ) );
}

/* ====================================================================
 * Double opt-in: confirm a new signup from the subscriber's own inbox
 * ==================================================================== */

/**
 * Whether a Pending contact's unconfirmed signup created them.
 *
 * @param int $contact_id Contact ID.
 * @return bool
 */
function npmp_signup_pending_is_new( $contact_id ) {
	$pending = get_post_meta( (int) $contact_id, '_npmp_signup_confirm', true );
	return is_array( $pending ) && ! empty( $pending['is_new'] );
}

/**
 * The submitted signup fields worth keeping until the subscriber confirms:
 * the form's own npmp_ fields (notification and group checkboxes), without
 * the nonce, honeypot or captcha.
 *
 * @param array $post Unslashed $_POST.
 * @return array
 */
function npmp_signup_fields_to_keep( $post ) {
	$skip = array( 'npmp_email_signup_nonce', 'npmp_signup_website', 'npmp_action', 'npmp_signup_atts' );
	$keep = array();
	foreach ( (array) $post as $key => $value ) {
		$key = (string) $key;
		if ( 0 !== strpos( $key, 'npmp_' ) || in_array( $key, $skip, true ) ) {
			continue;
		}
		if ( is_scalar( $value ) ) {
			$keep[ $key ] = substr( (string) $value, 0, 500 );
		} elseif ( is_array( $value ) ) {
			$keep[ $key ] = array_slice( array_map( 'strval', array_filter( $value, 'is_scalar' ) ), 0, 100 );
		}
	}
	return $keep;
}

/**
 * Save a pending signup and email the subscriber a link to confirm it.
 *
 * Each email gets a fresh single-use key, so an old link stops working once
 * a newer one is sent or the signup is confirmed, and a link can't put back
 * someone who confirmed and later unsubscribed.
 *
 * @param int    $contact_id Contact ID.
 * @param string $email      Subscriber address.
 * @param string $name       Name they typed.
 * @param array  $post       Unslashed $_POST.
 * @param string $atts_token The form's signed attributes.
 * @param bool   $is_new     True when this signup created the contact.
 * @return void
 */
function npmp_send_signup_confirmation( $contact_id, $email, $name, $post, $atts_token, $is_new ) {
	$key = wp_generate_password( 32, false );
	update_post_meta(
		(int) $contact_id,
		'_npmp_signup_confirm',
		array(
			'key'    => $key,
			'name'   => (string) $name,
			'post'   => npmp_signup_fields_to_keep( $post ),
			'atts'   => (string) $atts_token,
			'is_new' => (bool) $is_new,
			'time'   => time(),
		)
	);

	$url = add_query_arg(
		array(
			'action' => 'npmp_confirm_signup',
			'email'  => rawurlencode( $email ),
			'token'  => $key,
		),
		admin_url( 'admin-post.php' )
	);
	$subject = sprintf(
		/* translators: %s: site name. */
		__( 'Please confirm your subscription to %s', 'nonprofit-manager' ),
		wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
	);
	$body = sprintf(
		/* translators: 1: site name, 2: confirmation URL. */
		__( "Thanks for signing up for email from %1\$s.

To confirm, open this link and press the button:
%2\$s

If you didn't sign up, ignore this email and you won't hear from us.", 'nonprofit-manager' ),
		wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		$url
	);

	/**
	 * Filter extra lines for the "please confirm" email, as plain text. Each
	 * line becomes its own paragraph after the thank-you line. Pro adds the
	 * groups the signup asked to join. Free adds nothing, so its email is
	 * unchanged.
	 *
	 * @param string[] $lines      Plain-text lines ('' in free).
	 * @param int      $contact_id Contact ID.
	 * @param array    $fields     The signup's kept form fields (npmp_signup_fields_to_keep()).
	 * @param array    $atts       The form's attributes, read back from its signed field.
	 */
	$lines = apply_filters( 'npmp_signup_confirmation_lines', array(), (int) $contact_id, npmp_signup_fields_to_keep( $post ), npmp_signup_atts_from_token( (string) $atts_token ) );
	$lines = array_values( array_filter( array_map( 'wp_strip_all_tags', array_filter( (array) $lines, 'is_scalar' ) ), 'strlen' ) );
	if ( $lines ) {
		$parts = explode( "\n\n", $body, 2 );
		$body  = $parts[0] . "\n\n" . implode( "\n\n", $lines ) . ( isset( $parts[1] ) ? "\n\n" . $parts[1] : '' );
	}
	if ( function_exists( 'npmp_send_mail' ) ) {
		npmp_send_mail( $email, $subject, $body );
	}
}

add_action( 'admin_post_nopriv_npmp_confirm_signup', 'npmp_handle_confirm_signup' );
add_action( 'admin_post_npmp_confirm_signup', 'npmp_handle_confirm_signup' );

/**
 * Confirm a signup from the emailed link. A GET shows a button and only the
 * POST subscribes, so a mail scanner that opens the link confirms no one.
 *
 * @return void
 */
function npmp_handle_confirm_signup() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- Key-authenticated email link.
	$email = isset( $_REQUEST['email'] ) ? sanitize_email( wp_unslash( $_REQUEST['email'] ) ) : '';
	$token = isset( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['token'] ) ) : '';
	// phpcs:enable
	$method  = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	$title   = __( 'Confirm your subscription', 'nonprofit-manager' );
	$manager = class_exists( 'NPMP_Member_Manager' ) ? NPMP_Member_Manager::get_instance() : null;
	$contact = ( $manager && $email && is_email( $email ) ) ? $manager->get_member_by_email( $email ) : null;
	$pending = $contact ? get_post_meta( (int) $contact->id, '_npmp_signup_confirm', true ) : null;
	$valid   = is_array( $pending ) && '' !== $token && hash_equals( (string) ( $pending['key'] ?? '' ), $token );

	if ( ! $valid ) {
		$already = $contact && 'subscribed' === ( $contact->status ?? '' ) && ! is_array( $pending );
		wp_die(
			esc_html(
				$already
					? __( 'You\'re already subscribed. Thanks!', 'nonprofit-manager' )
					: __( 'This link has expired or was already used. Please sign up again on our website.', 'nonprofit-manager' )
			),
			esc_html( $title ),
			array( 'response' => $already ? 200 : 400, 'back_link' => true )
		);
	}

	if ( 'POST' !== $method ) {
		npmp_render_email_link_confirm_page(
			$title,
			__( 'Press the button to start getting our emails at this address.', 'nonprofit-manager' ),
			__( 'Confirm', 'nonprofit-manager' ),
			'npmp_confirm_signup',
			$email,
			$token
		);
	}

	$contact_id = (int) $contact->id;
	$update     = array( 'status' => 'subscribed' );
	if ( '' === trim( (string) $contact->name ) || $contact->name === $contact->email ) {
		$update['name'] = (string) ( $pending['name'] ?? '' );
	}
	if ( '' === (string) ( $contact->membership_level ?? '' ) ) {
		$default_level              = sanitize_text_field( get_option( 'npmp_default_membership_level', '' ) );
		$update['membership_level'] = $default_level ? $default_level : 'member';
	}
	$manager->update_member( $contact_id, $update );
	delete_post_meta( $contact_id, '_npmp_signup_confirm' );

	$is_new = ! empty( $pending['is_new'] );
	/** This action is documented in includes/npmp-membership-forms.php */
	do_action( 'npmp_after_email_signup', $contact_id, (array) ( $pending['post'] ?? array() ), npmp_signup_atts_from_token( (string) ( $pending['atts'] ?? '' ) ), $is_new );

	/**
	 * Fires when a subscriber confirms a signup from the emailed link. Pro
	 * runs its "member created" automation here for a contact the signup
	 * created, instead of when the Pending contact was saved.
	 *
	 * @param int  $contact_id Contact ID.
	 * @param bool $is_new     True when the confirmed signup created the contact.
	 */
	do_action( 'npmp_signup_confirmed', $contact_id, $is_new );

	$settings = npmp_get_membership_form_settings();
	$page_id  = absint( $settings['signup_page_id'] ?? 0 );
	if ( $page_id && 'publish' === get_post_status( $page_id ) && get_permalink( $page_id ) ) {
		wp_safe_redirect( npmp_membership_add_banner_arg( get_permalink( $page_id ), 'npmp_signup', 'confirmed' ) );
		exit;
	}
	wp_die( esc_html__( 'You\'re subscribed. Thanks for confirming.', 'nonprofit-manager' ), esc_html( $title ), array( 'response' => 200, 'back_link' => true ) );
}

/**
 * Render a one-button confirmation page for a signed email link and stop.
 *
 * Link scanners (Microsoft Safe Links, Mimecast and others) open every link in
 * an email. Acting on the GET let them unsubscribe people who never clicked,
 * so the GET shows this page and the button's POST does the work.
 *
 * @param string $title   Page title.
 * @param string $message Line above the button.
 * @param string $button  Button label.
 * @param string $action  admin-post action.
 * @param string $email   Email from the link.
 * @param string $token   Token from the link.
 * @return void
 */
function npmp_render_email_link_confirm_page( $title, $message, $button, $action, $email, $token ) {
	$html  = '<p>' . esc_html( $message ) . '</p>';
	$html .= '<p><strong>' . esc_html( $email ) . '</strong></p>';
	$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	$html .= '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
	$html .= '<input type="hidden" name="email" value="' . esc_attr( $email ) . '">';
	$html .= '<input type="hidden" name="token" value="' . esc_attr( $token ) . '">';
	$html .= '<input type="hidden" name="npmp_confirm" value="1">';
	$html .= '<p><button type="submit" class="button button-primary">' . esc_html( $button ) . '</button></p>';
	$html .= '</form>';
	wp_die( wp_kses( $html, array(
		'p'      => array(),
		'strong' => array(),
		'form'   => array( 'method' => true, 'action' => true ),
		'input'  => array( 'type' => true, 'name' => true, 'value' => true ),
		'button' => array( 'type' => true, 'class' => true ),
	) ), esc_html( $title ), array( 'response' => 200 ) );
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
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Token-authenticated email link. No cookie/nonce is available for one-click POSTs.
	$email = isset( $_REQUEST['email'] ) ? sanitize_email( wp_unslash( $_REQUEST['email'] ) ) : '';
	$token = isset( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['token'] ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	$valid = $email && is_email( $email ) && hash_equals( npmp_generate_unsubscribe_token( $email ), $token );

	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Token-authenticated email link.
	$from_button = isset( $_POST['npmp_confirm'] );

	// A GET is a person clicking the link, or a mail scanner opening it. Ask
	// for a button press so scanners don't unsubscribe people.
	if ( $valid && 'POST' !== $method ) {
		npmp_render_email_link_confirm_page(
			__( 'Unsubscribe', 'nonprofit-manager' ),
			__( 'Press the button to stop receiving these emails.', 'nonprofit-manager' ),
			__( 'Unsubscribe', 'nonprofit-manager' ),
			'npmp_one_click_unsubscribe',
			$email,
			$token
		);
	}

	if ( $valid && 'POST' === $method ) {
		npmp_unsubscribe_email_everywhere( $email );
	}

	// RFC 8058 one-click: mailbox provider POSTs and expects a bare 2xx/4xx.
	if ( 'POST' === $method && ! $from_button ) {
		status_header( $valid ? 200 : 400 );
		exit;
	}

	// Human clicked the visible link: send them to a friendly confirmation.
	$settings = npmp_get_membership_form_settings();
	$page_id  = absint( $settings['unsubscribe_page_id'] ?? 0 );

	if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
		$target = get_permalink( $page_id );
		if ( $target ) {
			wp_safe_redirect( npmp_membership_add_banner_arg( $target, 'npmp_unsubscribe', $valid ? 'unsubscribed' : 'error' ) );
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
