<?php
// includes/npmp-membership-forms.php
defined( 'ABSPATH' ) || exit;

/* ====================================================================
 * Admin settings page
 * ==================================================================== */
function npmp_render_membership_forms_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'nonprofit-manager' ) );
	}

	$option_key = 'npmp_membership_form_settings';
	$defaults   = array(
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
	$settings = wp_parse_args( get_option( $option_key, array() ), $defaults );
	$message  = '';

	/* ---------- Save ---------- */
	if (
		'POST' === $_SERVER['REQUEST_METHOD'] &&
		wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ),
			'npmp_membership_form_save'
		)
	) {
		foreach ( array_keys( $settings ) as $k ) {
			if ( ! isset( $_POST[ $k ] ) ) {
				continue;
			}
			$settings[ $k ] = in_array( $k, array( 'signup_description', 'unsubscribe_description' ), true )
				? sanitize_textarea_field( wp_unslash( $_POST[ $k ] ) )
				: ( in_array( $k, array( 'signup_page_id', 'unsubscribe_page_id' ), true )
					? intval( $_POST[ $k ] )
					: sanitize_text_field( wp_unslash( $_POST[ $k ] ) )
				);
		}
		update_option( $option_key, $settings );
		$message = '<div class="updated notice is-dismissible"><p>' . esc_html__( 'Settings saved.', 'nonprofit-manager' ) . '</p></div>';
	}

	/* ---------- Render ---------- */
	echo '<div class="wrap"><h1>' . esc_html__( 'Membership Signup & Unsubscribe Forms', 'nonprofit-manager' ) . '</h1>';
	if ( $message ) {
		echo wp_kses_post( $message );
	}

	$field = static function ( $id, $label, $type = 'text' ) use ( $settings ) {
		$value = 'textarea' === $type ? esc_textarea( $settings[ $id ] ) : esc_attr( $settings[ $id ] );
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		if ( 'textarea' === $type ) {
			echo '<textarea class="large-text" rows="3" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '">' . $value . '</textarea>';
		} else {
			echo '<input class="regular-text" type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" value="' . $value . '">';
		}
		echo '</td></tr>';
	};

	echo '<form method="post">';
	wp_nonce_field( 'npmp_membership_form_save' );

	/* Signup section */
	echo '<h2>' . esc_html__( 'Signup Form', 'nonprofit-manager' ) . '</h2><p><code>[np_email_signup]</code> — ' . esc_html__( 'Embed anywhere or pick a page below to inject automatically.', 'nonprofit-manager' ) . '</p>';
	echo '<table class="form-table">';
	$field( 'signup_heading', __( 'Heading', 'nonprofit-manager' ) );
	$field( 'signup_description', __( 'Description', 'nonprofit-manager' ), 'textarea' );
	echo '<tr><th>' . esc_html__( 'Inject on Page', 'nonprofit-manager' ) . '</th><td>';
	wp_dropdown_pages(
		array(
			'name'             => 'signup_page_id',
			'selected'         => intval( $settings['signup_page_id'] ),
			'show_option_none' => '— ' . __( 'None', 'nonprofit-manager' ) . ' —',
			'option_none_value'=> 0,
		)
	);
	echo '<p class="description">' . esc_html__( 'The form will be appended to the bottom of the selected page.', 'nonprofit-manager' ) . '</p></td></tr></table>';

	/* Unsubscribe section */
	echo '<h2>' . esc_html__( 'Unsubscribe Form', 'nonprofit-manager' ) . '</h2><p><code>[np_email_unsubscribe]</code> — ' . esc_html__( 'Embed anywhere or pick a page below to inject automatically.', 'nonprofit-manager' ) . '</p>';
	echo '<table class="form-table">';
	$field( 'unsubscribe_heading', __( 'Heading', 'nonprofit-manager' ) );
	$field( 'unsubscribe_description', __( 'Description', 'nonprofit-manager' ), 'textarea' );
	echo '<tr><th>' . esc_html__( 'Inject on Page', 'nonprofit-manager' ) . '</th><td>';
	wp_dropdown_pages(
		array(
			'name'             => 'unsubscribe_page_id',
			'selected'         => intval( $settings['unsubscribe_page_id'] ),
			'show_option_none' => '— ' . __( 'None', 'nonprofit-manager' ) . ' —',
			'option_none_value'=> 0,
		)
	);
	echo '<p class="description">' . esc_html__( 'The form will be appended to the bottom of the selected page.', 'nonprofit-manager' ) . '</p></td></tr></table>';

	/* Shared labels */
	echo '<h2>' . esc_html__( 'Shared Labels & Messages', 'nonprofit-manager' ) . '</h2>';
	echo '<table class="form-table">';
	$field( 'name_label', __( 'Name Field Label', 'nonprofit-manager' ) );
	$field( 'email_label', __( 'Email Field Label', 'nonprofit-manager' ) );
	$field( 'signup_button', __( 'Signup Button Text', 'nonprofit-manager' ) );
	$field( 'unsubscribe_button', __( 'Unsubscribe Button Text', 'nonprofit-manager' ) );
	$field( 'success_message', __( 'Success Message', 'nonprofit-manager' ) );
	$field( 'error_message', __( 'Error Message', 'nonprofit-manager' ) );
	echo '</table>';

	submit_button( __( 'Save Changes', 'nonprofit-manager' ) );
	echo '</form></div>';
}

/* ====================================================================
 * Shortcodes
 * ==================================================================== */
function npmp_get_banner_html( $type, $settings ) {
	if ( isset( $_GET[ $type ] ) ) {
		$key = 'success' === $_GET[ $type ] ? 'success_message' : 'error_message';
		return '<div class="npmp-form-banner npmp-' . esc_attr( $type ) . '"><p>' . esc_html( $settings[ $key ] ) . '</p></div>';
	}
	return '';
}

function npmp_email_signup_shortcode() {
	$s    = wp_parse_args( get_option( 'npmp_membership_form_settings', array() ), array() );
	$html = npmp_get_banner_html( 'npmp_signup', $s );
	$html .= '
	<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post" class="npmp-email-signup">
		<h3>' . esc_html( $s['signup_heading'] ) . '</h3>' .
		( $s['signup_description'] ? '<p>' . esc_html( $s['signup_description'] ) . '</p>' : '' ) . '
		<p><label>' . esc_html( $s['name_label'] ) . '<br><input type="text" name="npmp_name" required></label></p>
		<p><label>' . esc_html( $s['email_label'] ) . '<br><input type="email" name="npmp_email" required></label></p>
		' . wp_nonce_field( 'npmp_email_signup', 'npmp_email_signup_nonce', true, false ) . '
		<input type="hidden" name="npmp_action" value="email_signup">
		<input type="hidden" name="action" value="npmp_handle_form">
		<p><button type="submit">' . esc_html( $s['signup_button'] ) . '</button></p>
	</form>';
	return $html;
}
add_shortcode( 'np_email_signup', 'npmp_email_signup_shortcode' );

function npmp_email_unsubscribe_shortcode() {
	$s    = wp_parse_args( get_option( 'npmp_membership_form_settings', array() ), array() );
	$html = npmp_get_banner_html( 'npmp_unsubscribe', $s );
	$html .= '
	<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post" class="npmp-email-unsubscribe">
		<h3>' . esc_html( $s['unsubscribe_heading'] ) . '</h3>' .
		( $s['unsubscribe_description'] ? '<p>' . esc_html( $s['unsubscribe_description'] ) . '</p>' : '' ) . '
		<p><label>' . esc_html( $s['email_label'] ) . '<br><input type="email" name="npmp_email" required></label></p>
		' . wp_nonce_field( 'npmp_email_unsubscribe', 'npmp_email_unsubscribe_nonce', true, false ) . '
		<input type="hidden" name="npmp_action" value="email_unsubscribe">
		<input type="hidden" name="action" value="npmp_handle_form">
		<p><button type="submit">' . esc_html( $s['unsubscribe_button'] ) . '</button></p>
	</form>';
	return $html;
}
add_shortcode( 'np_email_unsubscribe', 'npmp_email_unsubscribe_shortcode' );

/* ====================================================================
 * Inject chosen pages
 * ==================================================================== */
add_filter( 'the_content', 'npmp_inject_membership_forms_into_pages', 9 );
function npmp_inject_membership_forms_into_pages( $content ) {
	if ( ! is_singular( 'page' ) ) {
		return $content;
	}
	$s       = get_option( 'npmp_membership_form_settings', array() );
	$post_id = get_the_ID();

	if ( ! empty( $s['signup_page_id'] ) && intval( $s['signup_page_id'] ) === $post_id && false === strpos( $content, '[np_email_signup]' ) ) {
		$content .= "\n\n[np_email_signup]\n";
	}
	if ( ! empty( $s['unsubscribe_page_id'] ) && intval( $s['unsubscribe_page_id'] ) === $post_id && false === strpos( $content, '[np_email_unsubscribe]' ) ) {
		$content .= "\n\n[np_email_unsubscribe]\n";
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

	global $wpdb;
	$table = $wpdb->prefix . 'npmp_members'; // *** FIXED: matches members page ***

	/* ------------------------------------------------------------------
	 * Retrieve actual column names
	 * ------------------------------------------------------------------ */
	$columns = $wpdb->get_col( "DESC `$table`", 0 ); // Field names
	if ( empty( $columns ) ) {
		wp_safe_redirect( add_query_arg( 'npmp_form_error', 'dbcols', $redirect ) );
		exit;
	}

	/* ------------------------------------------------------------------
	 * SIGN‑UP
	 * ------------------------------------------------------------------ */
	if (
		'email_signup' === $action &&
		! empty( $_POST['npmp_email_signup_nonce'] ) &&
		wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['npmp_email_signup_nonce'] ) ),
			'npmp_email_signup'
		)
	) {
		$name  = sanitize_text_field( wp_unslash( $_POST['npmp_name'] ?? '' ) );
		$email = sanitize_email( wp_unslash( $_POST['npmp_email'] ?? '' ) );

		if ( ! is_email( $email ) ) {
			wp_safe_redirect( add_query_arg( 'npmp_signup', 'error', $redirect ) );
			exit;
		}

		/* Already subscribed = success */
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `$table` WHERE email = %s LIMIT 1", $email ) ) ) {
			wp_safe_redirect( add_query_arg( 'npmp_signup', 'success', $redirect ) );
			exit;
		}

		$data   = $format = array();

		if ( in_array( 'name', $columns, true ) ) {
			$data['name'] = $name;
			$format[]     = '%s';
		}
		if ( in_array( 'email', $columns, true ) ) {
			$data['email'] = $email;
			$format[]      = '%s';
		}
		if ( in_array( 'membership_level', $columns, true ) ) {
			$data['membership_level'] = 'member';
			$format[]                 = '%s';
		}
		if ( in_array( 'status', $columns, true ) ) {
			$data['status'] = 'subscribed';
			$format[]       = '%s';
		}
		if ( in_array( 'created_at', $columns, true ) ) {
			$data['created_at'] = current_time( 'mysql' );
			$format[]           = '%s';
		}

		$inserted = $wpdb->insert( $table, $data, $format );
		$result   = ( $inserted && empty( $wpdb->last_error ) ) ? 'success' : 'error';

		wp_safe_redirect( add_query_arg( 'npmp_signup', $result, $redirect ) );
		exit;
	}

	/* ------------------------------------------------------------------
	 * UNSUBSCRIBE
	 * ------------------------------------------------------------------ */
	if (
		'email_unsubscribe' === $action &&
		! empty( $_POST['npmp_email_unsubscribe_nonce'] ) &&
		wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['npmp_email_unsubscribe_nonce'] ) ),
			'npmp_email_unsubscribe'
		)
	) {
		$email = sanitize_email( wp_unslash( $_POST['npmp_email'] ?? '' ) );

		if ( ! is_email( $email ) ) {
			wp_safe_redirect( add_query_arg( 'npmp_unsubscribe', 'error', $redirect ) );
			exit;
		}

		$deleted = $wpdb->delete( $table, array( 'email' => $email ), array( '%s' ) );
		$result  = ( $deleted && empty( $wpdb->last_error ) ) ? 'success' : 'error';

		wp_safe_redirect( add_query_arg( 'npmp_unsubscribe', $result, $redirect ) );
		exit;
	}

	/* ------------------------------------------------------------------
	 * FALLBACK
	 * ------------------------------------------------------------------ */
	wp_safe_redirect( add_query_arg( 'npmp_form_error', '1', $redirect ) );
	exit;
}
