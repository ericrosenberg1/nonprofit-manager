<?php
// includes/npmp-email-settings.php
defined( 'ABSPATH' ) || exit;

/* ========================================================================
 *  Email‑settings admin page
 * ======================================================================== */
function npmp_render_email_settings_page() {

	/* ---------- capability ---------- */
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nonprofit-manager' ) );
	}

	/* ---------- save settings ---------- */
	if (
		isset( $_POST['npmp_email_settings_nonce'] ) &&
		wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_email_settings_nonce'] ) ), 'npmp_email_settings' )
	) {
		$method     = sanitize_text_field( wp_unslash( $_POST['npmp_email_method'] ?? 'wp_mail' ) );
		$from_name  = sanitize_text_field( wp_unslash( $_POST['npmp_email_from_name'] ?? '' ) );
		$from_email = sanitize_email( wp_unslash( $_POST['npmp_email_from_email'] ?? '' ) );

		update_option( 'npmp_email_method', $method );
		update_option( 'npmp_email_from_name', $from_name );
		update_option( 'npmp_email_from_email', $from_email );

		if ( 'smtp' === $method ) {
			update_option( 'npmp_smtp_host',       sanitize_text_field( wp_unslash( $_POST['npmp_smtp_host'] ?? '' ) ) );
			update_option( 'npmp_smtp_port',       intval( $_POST['npmp_smtp_port'] ?? 587 ) );
			update_option( 'npmp_smtp_encryption', sanitize_text_field( wp_unslash( $_POST['npmp_smtp_encryption'] ?? 'tls' ) ) );
			update_option( 'npmp_smtp_auth',       isset( $_POST['npmp_smtp_auth'] ) ? 1 : 0 );
			update_option( 'npmp_smtp_username',   sanitize_text_field( wp_unslash( $_POST['npmp_smtp_username'] ?? '' ) ) );
			update_option( 'npmp_smtp_password',   sanitize_text_field( wp_unslash( $_POST['npmp_smtp_password'] ?? '' ) ) );
		}

		echo '<div class="updated"><p>' . esc_html__( 'Email settings saved.', 'nonprofit-manager' ) . '</p></div>';
	}

	/* ---------- current values ---------- */
	$method     = get_option( 'npmp_email_method', 'wp_mail' );
	$from_name  = get_option( 'npmp_email_from_name', get_bloginfo( 'name' ) );
	$from_email = get_option( 'npmp_email_from_email', get_bloginfo( 'admin_email' ) );

	/* ---------- page markup ---------- */
	echo '<div class="wrap"><h1>' . esc_html__( 'Email Settings', 'nonprofit-manager' ) . '</h1><form method="post">';
	wp_nonce_field( 'npmp_email_settings', 'npmp_email_settings_nonce' );

	echo '<table class="form-table">';
	echo '<tr><th>' . esc_html__( 'From Name', 'nonprofit-manager' ) . '</th><td><input type="text" class="regular-text" name="npmp_email_from_name" value="' . esc_attr( $from_name ) . '"></td></tr>';
	echo '<tr><th>' . esc_html__( 'From Email', 'nonprofit-manager' ) . '</th><td><input type="email" class="regular-text" name="npmp_email_from_email" value="' . esc_attr( $from_email ) . '"></td></tr>';

	echo '<tr><th>' . esc_html__( 'Delivery Method', 'nonprofit-manager' ) . '</th><td>';
	echo '<label><input type="radio" name="npmp_email_method" value="wp_mail" ' . checked( $method, 'wp_mail', false ) . '> ' . esc_html__( 'WordPress Default (wp_mail)', 'nonprofit-manager' ) . '</label><br>';
	echo '<label><input type="radio" name="npmp_email_method" value="smtp" ' . checked( $method, 'smtp', false ) . '> SMTP</label></td></tr>';

	/* ---------- SMTP fields ---------- */
	$smtp_class = 'smtp-settings' . ( 'smtp' === $method ? '' : ' hidden' );
	$enc        = get_option( 'npmp_smtp_encryption', 'tls' );

	echo '<tr class="' . esc_attr( $smtp_class ) . '"><th>' . esc_html__( 'SMTP Host', 'nonprofit-manager' ) . '</th><td><input type="text" class="regular-text" name="npmp_smtp_host" value="' . esc_attr( get_option( 'npmp_smtp_host', '' ) ) . '"></td></tr>';

	echo '<tr class="' . esc_attr( $smtp_class ) . '"><th>' . esc_html__( 'SMTP Port', 'nonprofit-manager' ) . '</th><td><input type="number" class="small-text" name="npmp_smtp_port" value="' . esc_attr( get_option( 'npmp_smtp_port', 587 ) ) . '"></td></tr>';

	echo '<tr class="' . esc_attr( $smtp_class ) . '"><th>' . esc_html__( 'Encryption', 'nonprofit-manager' ) . '</th><td>';
	echo '<label><input type="radio" name="npmp_smtp_encryption" value="none" '   . checked( $enc, 'none', false ) . '> ' . esc_html__( 'None', 'nonprofit-manager' ) . '</label><br>';
	echo '<label><input type="radio" name="npmp_smtp_encryption" value="ssl" '    . checked( $enc, 'ssl',  false ) . '> SSL</label><br>';
	echo '<label><input type="radio" name="npmp_smtp_encryption" value="tls" '    . checked( $enc, 'tls',  false ) . '> TLS</label></td></tr>';

	echo '<tr class="' . esc_attr( $smtp_class ) . '"><th>' . esc_html__( 'Authentication', 'nonprofit-manager' ) . '</th><td><input type="checkbox" name="npmp_smtp_auth" value="1" ' . checked( get_option( 'npmp_smtp_auth', 1 ), 1, false ) . '> ' . esc_html__( 'Use SMTP authentication', 'nonprofit-manager' ) . '</td></tr>';

	echo '<tr class="' . esc_attr( $smtp_class ) . '"><th>' . esc_html__( 'Username', 'nonprofit-manager' ) . '</th><td><input type="text" class="regular-text" name="npmp_smtp_username" value="' . esc_attr( get_option( 'npmp_smtp_username', '' ) ) . '"></td></tr>';
	echo '<tr class="' . esc_attr( $smtp_class ) . '"><th>' . esc_html__( 'Password', 'nonprofit-manager' ) . '</th><td><input type="password" class="regular-text" name="npmp_smtp_password" value="' . esc_attr( get_option( 'npmp_smtp_password', '' ) ) . '"></td></tr>';
	echo '</table>';

	echo '<script>jQuery(function($){$("input[name=npmp_email_method]").on("change",function(){ $(".smtp-settings").toggle($(this).val()==="smtp"); }).trigger("change");});</script>';

	submit_button( __( 'Save Settings', 'nonprofit-manager' ) );
	echo '</form><hr>';

	/* =================================================================
	 * Test‑email form
	 * ================================================================= */
	echo '<h2>' . esc_html__( 'Send Test Email', 'nonprofit-manager' ) . '</h2>';
	echo '<p>' . esc_html__( 'Use this form to test your email configuration.', 'nonprofit-manager' ) . '</p>';

	if (
		isset( $_POST['npmp_test_email_nonce'] ) &&
		wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_test_email_nonce'] ) ), 'npmp_test_email' )
	) {
		$to      = sanitize_email( wp_unslash( $_POST['npmp_test_email'] ?? '' ) );
		$subject = sanitize_text_field( wp_unslash( $_POST['npmp_test_subject'] ?? '' ) );
		$message = sanitize_textarea_field( wp_unslash( $_POST['npmp_test_message'] ?? '' ) );

		if ( $to && $subject && $message ) {
			$headers = array(
				'Content-Type: text/html; charset=UTF-8',
				'From: ' . get_option( 'npmp_email_from_name', get_bloginfo( 'name' ) ) .
				' <'     . get_option( 'npmp_email_from_email', get_bloginfo( 'admin_email' ) ) . '>',
			);

			delete_option( 'npmp_email_last_error' );
			add_action(
				'wp_mail_failed',
				static function ( $wp_error ) {
					update_option( 'npmp_email_last_error', $wp_error );
				}
			);

			$success = wp_mail( $to, $subject, nl2br( $message ), $headers );
			echo $success
				? '<div class="updated"><p>' . esc_html__( '✅ Test email sent successfully!', 'nonprofit-manager' ) . '</p></div>'
				: '<div class="error"><p>' . esc_html__( '❌ Failed to send test email.', 'nonprofit-manager' ) . '</p></div>';

			if ( ! $success && ( $err = get_option( 'npmp_email_last_error' ) ) ) {
				echo '<pre style="background:#fff;border:1px solid #ccc;padding:10px;max-height:300px;overflow:auto;">' .
				     esc_html( is_wp_error( $err ) ? $err->get_error_message() : wp_json_encode( $err ) ) .
				     '</pre>';
			}
		}
	}

	echo '<form method="post">';
	wp_nonce_field( 'npmp_test_email', 'npmp_test_email_nonce' );
	echo '<table class="form-table">';
	echo '<tr><th><label for="npmp_test_email">' . esc_html__( 'Recipient Email', 'nonprofit-manager' ) . '</label></th><td><input type="email" id="npmp_test_email" name="npmp_test_email" class="regular-text" value="' . esc_attr( wp_get_current_user()->user_email ) . '" required></td></tr>';
	echo '<tr><th><label for="npmp_test_subject">' . esc_html__( 'Subject', 'nonprofit-manager' ) . '</label></th><td><input type="text" id="npmp_test_subject" name="npmp_test_subject" class="regular-text" value="' . esc_attr__( 'Test Email from Nonprofit Manager', 'nonprofit-manager' ) . '" required></td></tr>';
	echo '<tr><th><label for="npmp_test_message">' . esc_html__( 'Message', 'nonprofit-manager' ) . '</label></th><td><textarea id="npmp_test_message" name="npmp_test_message" rows="5" class="large-text" required>' . esc_textarea( __( "This is a test email sent from the Nonprofit Manager plugin.\n\nIf you're receiving this, your email settings are working correctly.", 'nonprofit-manager' ) ) . '</textarea></td></tr>';
	echo '</table>';
	submit_button( __( 'Send Test Email', 'nonprofit-manager' ), 'secondary' );
	echo '</form></div>';
}

/* ========================================================================
 *  Placeholder Email‑delivery page
 * ======================================================================== */
if ( ! function_exists( 'npmp_render_email_delivery_page' ) ) {
	function npmp_render_email_delivery_page() {}
}

/* ========================================================================
 *  Member‑manager class (shared across admin pages)
 * ======================================================================== */
class NPMP_Member_Manager {

	private static $instance = null;

	public static function get_instance() {
		return self::$instance ?: ( self::$instance = new self() );
	}

	private function table() {
		global $wpdb;
		return esc_sql( $wpdb->prefix . 'npmp_members' );
	}

	private function clear_member_cache( $email ) {
		wp_cache_delete( "npmp_member_email_{$email}", 'npmp_members' );
	}

	private function clear_all_members_cache() {
		wp_cache_delete( 'npmp_members_all', 'npmp_members' );
	}

	/* ---------- CRUD helpers ---------- */
	public function update_member( $id, $data ) {
		global $wpdb;
		$table  = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->update(
			$table,
			array(
				'name'             => $data['name'],
				'email'            => $data['email'],
				'membership_level' => $data['membership_level'],
				'status'           => $data['status'],
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
		$this->clear_member_cache( $data['email'] );
		wp_cache_delete( 'npmp_member_' . $id, 'npmp_members' );
		return $result;
	}

	public function get_member_by_id( $id ) {
		global $wpdb;
		$cache_key = 'npmp_member_' . $id;
		$member    = wp_cache_get( $cache_key, 'npmp_members' );
		if ( false === $member ) {
			$table  = $this->table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$member = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
			if ( $member ) {
				wp_cache_set( $cache_key, $member, 'npmp_members', 300 );
			}
		}
		return $member;
	}

	public function delete_member( $id ) {
		global $wpdb;
		$table  = $this->table();
		$member = $this->get_member_by_id( $id );
		if ( $member && $member->email ) {
			$this->clear_member_cache( $member->email );
		}
		wp_cache_delete( 'npmp_member_' . $id, 'npmp_members' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
	}

	public function delete_members( $ids ) {
		$deleted = 0;
		foreach ( $ids as $id ) {
			$deleted += $this->delete_member( $id );
		}
		$this->clear_all_members_cache();
		return $deleted;
	}

	public function email_exists( $email ) {
		global $wpdb;
		$cache_key = 'npmp_member_email_' . $email;
		$count     = wp_cache_get( $cache_key, 'npmp_members' );
		if ( false === $count ) {
			$table = $this->table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE email = %s", $email ) );
			wp_cache_set( $cache_key, $count, 'npmp_members', 300 );
		}
		return $count;
	}

	public function add_member( $data ) {
		global $wpdb;
		$table  = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$table,
			array(
				'name'             => $data['name'],
				'email'            => $data['email'],
				'membership_level' => $data['membership_level'] ?? '',
				'status'           => $data['status'] ?? 'subscribed',
				'created_at'       => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
		$this->clear_member_cache( $data['email'] );
		$this->clear_all_members_cache();
		return $result;
	}

	public function update_status( $email, $status ) {
		global $wpdb;
		$table  = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->update(
			$table,
			array( 'status' => $status ),
			array( 'email' => $email ),
			array( '%s' ),
			array( '%s' )
		);
		$this->clear_member_cache( $email );
		$this->clear_all_members_cache();
		return $result;
	}

	public function get_all_members() {
		global $wpdb;
		$cache_key = 'npmp_members_all';
		$members   = wp_cache_get( $cache_key, 'npmp_members' );
		if ( false === $members ) {
			$table   = $this->table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching
			$members = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC" );
			wp_cache_set( $cache_key, $members, 'npmp_members', 300 );
		}
		return $members;
	}
}

/* ========================================================================
 *  Members admin page
 * ======================================================================== */
if ( ! function_exists( 'npmp_render_members_page' ) ) {

	function npmp_render_members_page() {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nonprofit-manager' ) );
		}

		$member_manager = NPMP_Member_Manager::get_instance();

		/* ---------- update ---------- */
		if (
			isset( $_POST['npmp_save_member'], $_POST['npmp_members_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_members_nonce'] ) ), 'npmp_manage_members' )
		) {
			$member_manager->update_member(
				intval( $_POST['member_id'] ?? 0 ),
				array(
					'name'             => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
					'email'            => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
					'membership_level' => sanitize_text_field( wp_unslash( $_POST['membership_level'] ?? '' ) ),
					'status'           => sanitize_text_field( wp_unslash( $_POST['status'] ?? '' ) ),
				)
			);
			echo '<div class="updated"><p>' . esc_html__( 'Member updated.', 'nonprofit-manager' ) . '</p></div>';
			return;
		}

		/* ---------- edit form ---------- */
		if ( isset( $_GET['action'], $_GET['id'] ) && 'edit' === sanitize_text_field( wp_unslash( $_GET['action'] ) ) ) {
			$member = $member_manager->get_member_by_id( intval( $_GET['id'] ) );
			if ( ! $member ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Member not found.', 'nonprofit-manager' ) . '</p></div>';
				return;
			}

			echo '<div class="wrap"><h1>' . esc_html__( 'Edit Member', 'nonprofit-manager' ) . '</h1><form method="post">';
			wp_nonce_field( 'npmp_manage_members', 'npmp_members_nonce' );
			echo '<input type="hidden" name="member_id" value="' . esc_attr( $member->id ) . '"><table class="form-table">';
			echo '<tr><th>' . esc_html__( 'Name', 'nonprofit-manager' ) . '</th><td><input type="text" class="regular-text" name="name" value="' . esc_attr( $member->name ) . '" required></td></tr>';
			echo '<tr><th>' . esc_html__( 'Email', 'nonprofit-manager' ) . '</th><td><input type="email" class="regular-text" name="email" value="' . esc_attr( $member->email ) . '" required></td></tr>';
			echo '<tr><th>' . esc_html__( 'Membership Level', 'nonprofit-manager' ) . '</th><td><input type="text" class="regular-text" name="membership_level" value="' . esc_attr( $member->membership_level ) . '"></td></tr>';
			echo '<tr><th>' . esc_html__( 'Status', 'nonprofit-manager' ) . '</th><td><select name="status">';
			foreach ( array( 'subscribed', 'pending', 'unsubscribed' ) as $s ) {
				echo '<option value="' . esc_attr( $s ) . '"' . selected( $member->status, $s, false ) . '>' . esc_html( ucfirst( $s ) ) . '</option>';
			}
			echo '</select></td></tr></table>';
			submit_button( __( 'Save Changes', 'nonprofit-manager' ), 'primary', 'npmp_save_member' );
			echo '</form></div>';
			return;
		}

		/* ---------- deletion ---------- */
		if ( isset( $_GET['action'], $_GET['id'] ) && 'delete' === sanitize_text_field( wp_unslash( $_GET['action'] ) ) && check_admin_referer( 'npmp_manage_members_delete_' . intval( $_GET['id'] ) ) ) {
			$member_manager->delete_member( intval( $_GET['id'] ) );
			echo '<div class="updated"><p>' . esc_html__( 'Member deleted.', 'nonprofit-manager' ) . '</p></div>';
		}

		/* ---------- bulk delete ---------- */
		if (
			isset( $_POST['bulk_delete'], $_POST['npmp_members_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_members_nonce'] ) ), 'npmp_manage_members' )
		) {
			$member_manager->delete_members( array_map( 'intval', (array) wp_unslash( $_POST['user_ids'] ?? array() ) ) );
			echo '<div class="updated"><p>' . esc_html__( 'Selected members deleted.', 'nonprofit-manager' ) . '</p></div>';
		}

		/* ---------- manual add ---------- */
		$name  = sanitize_text_field( wp_unslash( $_POST['manual_name'] ?? ( $_POST['npmp_name'] ?? '' ) ) );
		$email = sanitize_email( wp_unslash( $_POST['manual_email'] ?? ( $_POST['npmp_email'] ?? '' ) ) );
		if (
			$name && $email &&
			isset( $_POST['npmp_members_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_members_nonce'] ) ), 'npmp_manage_members' ) &&
			( isset( $_POST['manual_add'] ) || isset( $_POST['npmp_subscribe'] ) )
		) {
			if ( ! $member_manager->email_exists( $email ) ) {
				$member_manager->add_member( array( 'name' => $name, 'email' => $email ) );
				echo '<div class="updated"><p>' . esc_html__( 'Member added.', 'nonprofit-manager' ) . '</p></div>';
			} elseif ( isset( $_POST['manual_add'] ) ) {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'This email address is already subscribed.', 'nonprofit-manager' ) . '</p></div>';
			}
		}

		/* ---------- unsubscribe (front‑end) ---------- */
		if (
			isset( $_POST['npmp_unsubscribe'], $_POST['npmp_members_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_members_nonce'] ) ), 'npmp_manage_members' )
		) {
			$member_manager->update_status( sanitize_email( wp_unslash( $_POST['npmp_email'] ?? '' ) ), 'unsubscribed' );
			echo '<div class="updated"><p>' . esc_html__( 'You have been unsubscribed.', 'nonprofit-manager' ) . '</p></div>';
		}

		/* ---------- list table ---------- */
		$members = $member_manager->get_all_members();

		echo '<div class="wrap"><h1>' . esc_html__( 'Members & Subscribers', 'nonprofit-manager' ) . '</h1>';

		/* add form */
		echo '<h2>' . esc_html__( 'Manually Add Member', 'nonprofit-manager' ) . '</h2><form method="post" style="margin-bottom:20px;">';
		wp_nonce_field( 'npmp_manage_members', 'npmp_members_nonce' );
		echo '<input type="text" name="manual_name" placeholder="' . esc_attr__( 'Name', 'nonprofit-manager' ) . '" required style="margin-right:10px;">';
		echo '<input type="email" name="manual_email" placeholder="' . esc_attr__( 'Email', 'nonprofit-manager' ) . '" required style="margin-right:10px;">';
		echo '<input type="submit" name="manual_add" class="button button-primary" value="' . esc_attr__( 'Add Member', 'nonprofit-manager' ) . '"></form>';

		/* table */
		echo '<form method="post">';
		wp_nonce_field( 'npmp_manage_members', 'npmp_members_nonce' );
		echo '<table class="widefat fixed striped"><thead><tr>';
		echo '<td class="check-column"><input type="checkbox" id="bulk-select-all"></td>';
		echo '<th>' . esc_html__( 'Name', 'nonprofit-manager' ) . '</th><th>' . esc_html__( 'Membership Level', 'nonprofit-manager' ) . '</th><th>' . esc_html__( 'Email Address', 'nonprofit-manager' ) . '</th><th>' . esc_html__( 'Status', 'nonprofit-manager' ) . '</th><th>' . esc_html__( 'Actions', 'nonprofit-manager' ) . '</th></tr></thead><tbody>';

		foreach ( $members as $m ) {
			echo '<tr><th class="check-column"><input type="checkbox" name="user_ids[]" value="' . esc_attr( $m->id ) . '"></th>';
			echo '<td>' . esc_html( $m->name ) . '</td><td>' . esc_html( $m->membership_level ) . '</td><td>' . esc_html( $m->email ) . '</td><td>' . esc_html( $m->status ) . '</td><td>';
			echo '<a href="' . esc_url( add_query_arg( array( 'page' => 'npmp_members', 'action' => 'edit', 'id' => $m->id ) ) ) . '">' . esc_html__( 'Edit', 'nonprofit-manager' ) . '</a> | ';
			echo '<a href="' . esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'npmp_members', 'action' => 'delete', 'id' => $m->id ) ), 'npmp_manage_members_delete_' . $m->id ) ) . '" onclick="return confirm(\'Are you sure?\')">' . esc_html__( 'Delete', 'nonprofit-manager' ) . '</a></td></tr>';
		}
		echo '</tbody></table><p><input type="submit" name="bulk_delete" class="button" value="' . esc_attr__( 'Delete Selected', 'nonprofit-manager' ) . '"></p></form></div>';
	}
}
