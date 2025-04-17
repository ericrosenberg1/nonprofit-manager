<?php
// includes/npmp-members-settings.php
defined( 'ABSPATH' ) || exit;

/*=====================================================================
 * 1. Membership Dashboard  (top‑level “Membership” page)
 *====================================================================*/
function npmp_render_membership_dashboard() {

	/* Permissions */
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nonprofit-manager' ) );
	}

	$member_manager = NPMP_Member_Manager::get_instance();

	/* ---------- Summary counts ---------- */
	if ( method_exists( $member_manager, 'count_by_level' ) ) {
		$counts = $member_manager->count_by_level();        // preferred
	} else {
		// fallback direct query
		global $wpdb;
		$table  = $wpdb->prefix . 'npmp_members';
		$rows   = $wpdb->get_results( "SELECT membership_level AS lvl, COUNT(*) AS total FROM $table GROUP BY membership_level" );
		$counts = array();
		foreach ( $rows as $r ) {
			$counts[ $r->lvl ] = (int) $r->total;
		}
	}

	/* ---------- Add / remove levels ---------- */
	$levels_option = 'npmp_membership_levels';
	$levels        = get_option( $levels_option, array() );

	if ( ! empty( $_POST['add_level'] ) && ! empty( $_POST['new_level'] ) && check_admin_referer( 'npmp_levels' ) ) {
		$new = sanitize_text_field( wp_unslash( $_POST['new_level'] ) );
		if ( $new && ! in_array( $new, $levels, true ) ) {
			$levels[] = $new;
			update_option( $levels_option, $levels );
			echo '<div class="updated"><p>' . esc_html__( 'Level added.', 'nonprofit-manager' ) . '</p></div>';
		}
	}

	if ( ! empty( $_POST['delete_level'] ) && ! empty( $_POST['level_slug'] ) && check_admin_referer( 'npmp_levels' ) ) {
		$slug   = sanitize_text_field( wp_unslash( $_POST['level_slug'] ) );
		$levels = array_diff( $levels, array( $slug ) );
		update_option( $levels_option, $levels );
		echo '<div class="updated"><p>' . esc_html__( 'Level removed.', 'nonprofit-manager' ) . '</p></div>';
	}

	/* ---------- Output ---------- */
	echo '<div class="wrap">';
	echo '<h1>' . esc_html__( 'Membership Summary', 'nonprofit-manager' ) . '</h1>';

	// summary table
	echo '<h2>' . esc_html__( 'Members by Level', 'nonprofit-manager' ) . '</h2>';
	echo '<table class="widefat fixed striped" style="max-width:400px">';
	echo '<thead><tr><th>' . esc_html__( 'Level', 'nonprofit-manager' ) . '</th><th style="text-align:right">' . esc_html__( 'Count', 'nonprofit-manager' ) . '</th></tr></thead><tbody>';
	foreach ( $counts as $level => $total ) {
		echo '<tr><td>' . esc_html( $level ?: '(none)' ) . '</td><td style="text-align:right">' . esc_html( $total ) . '</td></tr>';
	}
	echo '</tbody></table>';

	// manage levels
	echo '<h2 style="margin-top:2em;">' . esc_html__( 'Manage Membership Levels', 'nonprofit-manager' ) . '</h2>';
	echo '<form method="post" style="margin-bottom:2em;">';
	wp_nonce_field( 'npmp_levels' );
	echo '<input type="text" name="new_level" placeholder="' . esc_attr__( 'New level name', 'nonprofit-manager' ) . '" required>';
	submit_button( __( 'Add Level', 'nonprofit-manager' ), 'secondary', 'add_level', false );
	echo '</form>';

	if ( $levels ) {
		echo '<table class="widefat fixed striped" style="max-width:400px">';
		echo '<thead><tr><th>' . esc_html__( 'Level', 'nonprofit-manager' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $levels as $slug ) {
			echo '<tr><td>' . esc_html( $slug ) . '</td><td style="text-align:right">';
			echo '<form method="post" style="display:inline">';
			wp_nonce_field( 'npmp_levels' );
			echo '<input type="hidden" name="level_slug" value="' . esc_attr( $slug ) . '">';
			submit_button(
				__( 'Remove', 'nonprofit-manager' ),
				'delete',
				'delete_level',
				false,
				array( 'onclick' => 'return confirm("' . esc_js( __( 'Remove this level?', 'nonprofit-manager' ) ) . '")' )
			);
			echo '</form></td></tr>';
		}
		echo '</tbody></table>';
	}

	echo '</div>';
}

/*=====================================================================
 * 2. Member List / Edit page  (submenu “Member List”)
 *   -- unchanged except for corrected esc_js
 *====================================================================*/
function npmp_render_members_page() {
	/* ------------- Permission ------------- */
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nonprofit-manager' ) );
	}

	$member_manager = NPMP_Member_Manager::get_instance();

	/* -------- Update member ---------- */
	if (
		! empty( $_POST['npmp_save_member'] ) &&
		wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_members_nonce'] ?? '' ) ), 'npmp_manage_members' )
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

	/* -------- Edit form ---------- */
	if ( isset( $_GET['action'], $_GET['id'] ) && 'edit' === $_GET['action'] && current_user_can( 'manage_options' ) ) {
		$member = $member_manager->get_member_by_id( intval( $_GET['id'] ) );
		if ( ! $member ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Member not found.', 'nonprofit-manager' ) . '</p></div>';
			return;
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Edit Member', 'nonprofit-manager' ) . '</h1>';
		echo '<form method="post">';
		wp_nonce_field( 'npmp_manage_members', 'npmp_members_nonce' );
		echo '<input type="hidden" name="member_id" value="' . esc_attr( $member->id ) . '">';
		echo '<table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Name', 'nonprofit-manager' ) . '</th><td><input class="regular-text" type="text" name="name" value="' . esc_attr( $member->name ) . '" required></td></tr>';
		echo '<tr><th>' . esc_html__( 'Email', 'nonprofit-manager' ) . '</th><td><input class="regular-text" type="email" name="email" value="' . esc_attr( $member->email ) . '" required></td></tr>';
		echo '<tr><th>' . esc_html__( 'Membership Level', 'nonprofit-manager' ) . '</th><td><input class="regular-text" type="text" name="membership_level" value="' . esc_attr( $member->membership_level ) . '"></td></tr>';
		echo '<tr><th>' . esc_html__( 'Status', 'nonprofit-manager' ) . '</th><td><select name="status">';
		foreach ( array( 'subscribed', 'pending', 'unsubscribed' ) as $status_option ) {
			echo '<option value="' . esc_attr( $status_option ) . '"' . selected( $member->status, $status_option, false ) . '>' . esc_html( ucfirst( $status_option ) ) . '</option>';
		}
		echo '</select></td></tr></table>';
		submit_button( __( 'Save Changes', 'nonprofit-manager' ), 'primary', 'npmp_save_member' );
		echo '</form></div>';
		return;
	}

	/* -------- Single / Bulk delete -------- */
	if (
		isset( $_GET['action'], $_GET['id'] ) && 'delete' === $_GET['action'] &&
		check_admin_referer( 'npmp_manage_members_delete_' . intval( $_GET['id'] ) )
	) {
		$member_manager->delete_member( intval( $_GET['id'] ) );
		echo '<div class="updated"><p>' . esc_html__( 'Member deleted.', 'nonprofit-manager' ) . '</p></div>';
	}

	if (
		! empty( $_POST['bulk_delete'] ) &&
		wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_members_nonce'] ?? '' ) ), 'npmp_manage_members' )
	) {
		foreach ( array_map( 'intval', wp_unslash( $_POST['user_ids'] ?? array() ) ) as $id ) {
			$member_manager->delete_member( $id );
		}
		echo '<div class="updated"><p>' . esc_html__( 'Selected members deleted.', 'nonprofit-manager' ) . '</p></div>';
	}

	/* -------- Manual add -------- */
	if (
		! empty( $_POST['manual_add'] ) &&
		wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_members_nonce'] ?? '' ) ), 'npmp_manage_members' )
	) {
		$name  = sanitize_text_field( wp_unslash( $_POST['manual_name'] ?? '' ) );
		$email = sanitize_email( wp_unslash( $_POST['manual_email'] ?? '' ) );

		if ( $name && $email && ! $member_manager->email_exists( $email ) ) {
			$member_manager->add_member(
				array(
					'name'             => $name,
					'email'            => $email,
					'membership_level' => '',
					'status'           => 'subscribed',
				)
			);
			echo '<div class="updated"><p>' . esc_html__( 'Member added.', 'nonprofit-manager' ) . '</p></div>';
		} else {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'This email address is already subscribed.', 'nonprofit-manager' ) . '</p></div>';
		}
	}

	/* -------- List table -------- */
	$members = $member_manager->get_all_members();

	echo '<div class="wrap"><h1>' . esc_html__( 'Member List', 'nonprofit-manager' ) . '</h1>';

	// manual‑add form
	echo '<h2>' . esc_html__( 'Manually Add Member', 'nonprofit-manager' ) . '</h2>';
	echo '<form method="post" style="margin-bottom:20px;">';
	wp_nonce_field( 'npmp_manage_members', 'npmp_members_nonce' );
	echo '<input style="margin-right:10px;" type="text"   name="manual_name"  placeholder="' . esc_attr__( 'Name', 'nonprofit-manager' ) . '"  required>';
	echo '<input style="margin-right:10px;" type="email" name="manual_email" placeholder="' . esc_attr__( 'Email', 'nonprofit-manager' ) . '" required>';
	echo '<input class="button button-primary" type="submit" name="manual_add" value="' . esc_attr__( 'Add Member', 'nonprofit-manager' ) . '">';
	echo '</form>';

	echo '<form method="post">';
	wp_nonce_field( 'npmp_manage_members', 'npmp_members_nonce' );
	echo '<table class="widefat fixed striped">';
	echo '<thead><tr>';
	echo '<td class="check-column"><input type="checkbox" id="bulk-select-all"></td>';
	echo '<th>' . esc_html__( 'Name', 'nonprofit-manager' ) . '</th>';
	echo '<th>' . esc_html__( 'Membership Level', 'nonprofit-manager' ) . '</th>';
	echo '<th>' . esc_html__( 'Email Address', 'nonprofit-manager' ) . '</th>';
	echo '<th>' . esc_html__( 'Status', 'nonprofit-manager' ) . '</th>';
	echo '<th>' . esc_html__( 'Actions', 'nonprofit-manager' ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( $members as $member ) {
		echo '<tr>';
		echo '<th class="check-column"><input type="checkbox" name="user_ids[]" value="' . esc_attr( $member->id ) . '"></th>';
		echo '<td>' . esc_html( $member->name ) . '</td>';
		echo '<td>' . esc_html( $member->membership_level ) . '</td>';
		echo '<td>' . esc_html( $member->email ) . '</td>';
		echo '<td>' . esc_html( $member->status ) . '</td>';
		echo '<td><a href="?page=npmp_members&action=edit&id=' . esc_attr( $member->id ) . '">' . esc_html__( 'Edit', 'nonprofit-manager' ) . '</a> | ';
		echo '<a href="' . esc_url(
			wp_nonce_url(
				'?page=npmp_members&action=delete&id=' . $member->id,
				'npmp_manage_members_delete_' . $member->id
			)
		) . '" onclick="return confirm(\'' . esc_js( __( 'Are you sure?', 'nonprofit-manager' ) ) . '\')">' . esc_html__( 'Delete', 'nonprofit-manager' ) . '</a></td>';
		echo '</tr>';
	}

	echo '</tbody></table>';
	echo '<p><input class="button" type="submit" name="bulk_delete" value="' . esc_attr__( 'Delete Selected', 'nonprofit-manager' ) . '"></p>';
	echo '</form></div>';
}
