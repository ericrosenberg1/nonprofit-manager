<?php
/**
 * File path: includes/email-newsletter/imported.php
 *
 * Newsletters you brought over from another plugin (includes/import/
 * past-newsletters.php), and the public archive bulk actions on the
 * newsletter list.
 *
 * An imported newsletter already went out from the old plugin, so it can
 * never send from here. Every path that mails a newsletter checks
 * npmp_newsletter_is_imported(): NPMP_Newsletter_Manager::queue_newsletter()
 * returns 0, the mail queue drops any row for one, Send now and Send later
 * refuse, a Send later row for one gets cancelled, and the editor shows a
 * note in place of the send controls. Duplicate makes a fresh draft that
 * carries no import mark, so you can still send a new copy.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether a newsletter came from another plugin's sent mail.
 *
 * @param int|WP_Post $post Newsletter or its ID.
 * @return bool
 */
function npmp_newsletter_is_imported( $post ) {
	$post = get_post( $post );
	if ( ! $post || 'npmp_newsletter' !== $post->post_type ) {
		return false;
	}
	return '' !== (string) get_post_meta( $post->ID, '_npmp_import_key', true );
}

/**
 * The plugin an imported newsletter came from, for the screen.
 *
 * @param int $post_id Newsletter ID.
 * @return string
 */
function npmp_newsletter_import_source_name( $post_id ) {
	$names  = array(
		'mailpoet'   => 'MailPoet',
		'newsletter' => 'The Newsletter Plugin',
	);
	$source = (string) get_post_meta( $post_id, '_npmp_import_source', true );
	return $names[ $source ] ?? __( 'your old plugin', 'nonprofit-manager' );
}

/**
 * The note that takes the place of the send controls on an imported
 * newsletter.
 *
 * @param WP_Post $post Newsletter.
 * @return bool True when the newsletter is imported and the note printed.
 */
function npmp_newsletter_imported_notice_html( $post ) {
	if ( ! npmp_newsletter_is_imported( $post ) ) {
		return false;
	}
	$sent = (string) get_post_meta( $post->ID, '_npmp_newsletter_queued_at', true );
	echo '<p>' . esc_html(
		'' !== $sent
			? sprintf(
				/* translators: 1: plugin name, such as MailPoet, 2: date it went out. */
				__( 'You sent this newsletter from %1$s on %2$s, and imported it here as a record.', 'nonprofit-manager' ),
				npmp_newsletter_import_source_name( $post->ID ),
				mysql2date( get_option( 'date_format' ), $sent )
			)
			: sprintf(
				/* translators: %s: plugin name, such as MailPoet. */
				__( 'You sent this newsletter from %s, and imported it here as a record.', 'nonprofit-manager' ),
				npmp_newsletter_import_source_name( $post->ID )
			)
	) . '</p>';
	echo '<p>' . esc_html__( 'You can\'t send it again from here. To send it to your list, duplicate it and send the copy.', 'nonprofit-manager' ) . '</p>';
	echo '<p>' . esc_html__( 'We have no open or click numbers for it, since those stayed in the old plugin.', 'nonprofit-manager' ) . '</p>';
	$preview = get_preview_post_link( $post );
	if ( $preview ) {
		echo '<p><a class="button" target="_blank" rel="noopener" href="' . esc_url( $preview ) . '">' . esc_html__( 'Preview in new tab', 'nonprofit-manager' ) . '</a></p>';
	}
	return true;
}

// =====================================================================
// Public archive bulk actions
// =====================================================================

add_action( 'admin_post_npmp_newsletter_public_bulk', 'npmp_newsletter_public_bulk_post' );
add_filter( 'bulk_actions-edit-npmp_newsletter', 'npmp_newsletter_public_bulk_register' );
add_filter( 'handle_bulk_actions-edit-npmp_newsletter', 'npmp_newsletter_public_bulk_from_list', 10, 3 );

/**
 * Bulk action labels.
 *
 * @return array<string,string>
 */
function npmp_newsletter_public_bulk_actions() {
	return array(
		'npmp_public_show' => __( 'Show in the public archive', 'nonprofit-manager' ),
		'npmp_public_hide' => __( 'Hide from the public archive', 'nonprofit-manager' ),
	);
}

/**
 * Whether the current user may change the public flag. The same capability
 * the editor's "Show in the public archive" box asks for.
 *
 * @return bool
 */
function npmp_newsletter_public_bulk_allowed() {
	$cap = function_exists( 'npmp_newsletter_send_capability' ) ? npmp_newsletter_send_capability() : 'edit_others_posts';
	return current_user_can( $cap );
}

/**
 * Add the actions to the WordPress list of newsletters.
 *
 * @param array $actions Bulk actions.
 * @return array
 */
function npmp_newsletter_public_bulk_register( $actions ) {
	return npmp_newsletter_public_bulk_allowed() ? array_merge( (array) $actions, npmp_newsletter_public_bulk_actions() ) : $actions;
}

/**
 * The newsletters among some IDs the current user may change.
 *
 * @param int[] $ids Post IDs.
 * @return array[] Rows for npmp_newsletter_public_bulk_plan().
 */
function npmp_newsletter_public_bulk_rows( array $ids ) {
	$rows = array();
	foreach ( array_unique( array_filter( array_map( 'absint', $ids ) ) ) as $id ) {
		$post = get_post( $id );
		if ( ! $post || 'npmp_newsletter' !== $post->post_type || 'trash' === $post->post_status || ! current_user_can( 'edit_post', $id ) ) {
			continue;
		}
		$rows[] = array(
			'id'          => $id,
			'send_status' => (string) get_post_meta( $id, '_npmp_newsletter_status', true ),
			'public'      => (string) get_post_meta( $id, NPMP_NEWSLETTER_PUBLIC_META, true ),
		);
	}
	return $rows;
}

/**
 * Apply a plan.
 *
 * @param array  $plan   From npmp_newsletter_public_bulk_plan().
 * @param string $action 'show' or 'hide'.
 * @return int Newsletters changed.
 */
function npmp_newsletter_public_bulk_apply( array $plan, $action ) {
	foreach ( $plan['change'] as $id ) {
		if ( 'show' === $action ) {
			update_post_meta( $id, NPMP_NEWSLETTER_PUBLIC_META, '1' );
		} else {
			delete_post_meta( $id, NPMP_NEWSLETTER_PUBLIC_META );
		}
	}
	return count( $plan['change'] );
}

/**
 * Transient that holds a Show selection waiting for its confirmation.
 *
 * @return string
 */
function npmp_newsletter_public_bulk_key() {
	return 'npmp_nl_public_bulk_' . get_current_user_id();
}

/**
 * Where the newsletter list lives.
 *
 * @param array $args Query arguments.
 * @return string
 */
function npmp_newsletter_public_bulk_back( array $args = array() ) {
	return add_query_arg( $args, admin_url( 'admin.php?page=npmp_newsletter_archive' ) );
}

/**
 * Hand a selection on: Hide right away, Show after a confirmation.
 *
 * @param string $action 'show' or 'hide'.
 * @param int[]  $ids    Post IDs.
 * @return string Redirect URL.
 */
function npmp_newsletter_public_bulk_route( $action, array $ids ) {
	$plan = npmp_newsletter_public_bulk_plan( npmp_newsletter_public_bulk_rows( $ids ), $action );
	if ( 'show' === $action ) {
		set_transient( npmp_newsletter_public_bulk_key(), array_merge( $plan['change'], $plan['unsent'], $plan['already'] ), 15 * MINUTE_IN_SECONDS );
		return npmp_newsletter_public_bulk_back( array( 'npmp_public_confirm' => 1 ) );
	}
	$done = npmp_newsletter_public_bulk_apply( $plan, 'hide' );
	return npmp_newsletter_public_bulk_back(
		array(
			'npmp_public_done' => 'hide',
			'npmp_public_n'    => $done,
		)
	);
}

/**
 * The bulk action from the WordPress list of newsletters.
 *
 * @param string $redirect Redirect URL.
 * @param string $action   Action.
 * @param int[]  $ids      Post IDs.
 * @return string
 */
function npmp_newsletter_public_bulk_from_list( $redirect, $action, $ids ) {
	if ( ! isset( npmp_newsletter_public_bulk_actions()[ $action ] ) || ! npmp_newsletter_public_bulk_allowed() ) {
		return $redirect;
	}
	return npmp_newsletter_public_bulk_route( 'npmp_public_show' === $action ? 'show' : 'hide', (array) $ids );
}

/**
 * The bulk action from the Newsletter Archive screen, and the confirmation.
 *
 * @return void
 */
function npmp_newsletter_public_bulk_post() {
	if ( ! npmp_newsletter_public_bulk_allowed() ) {
		wp_die( esc_html__( 'You don\'t have permission to change the public archive.', 'nonprofit-manager' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'npmp_newsletter_public_bulk', 'npmp_public_nonce' );

	if ( ! empty( $_POST['npmp_public_confirmed'] ) ) {
		$ids = get_transient( npmp_newsletter_public_bulk_key() );
		delete_transient( npmp_newsletter_public_bulk_key() );
		$plan = npmp_newsletter_public_bulk_plan( npmp_newsletter_public_bulk_rows( is_array( $ids ) ? $ids : array() ), 'show' );
		$done = npmp_newsletter_public_bulk_apply( $plan, 'show' );
		wp_safe_redirect(
			npmp_newsletter_public_bulk_back(
				array(
					'npmp_public_done' => 'show',
					'npmp_public_n'    => $done,
				)
			)
		);
		exit;
	}

	$action = sanitize_key( wp_unslash( $_POST['npmp_public_action'] ?? '' ) );
	$ids    = array_map( 'absint', (array) wp_unslash( $_POST['npmp_public_ids'] ?? array() ) );
	if ( ! in_array( $action, array( 'npmp_public_show', 'npmp_public_hide' ), true ) || ! $ids ) {
		wp_safe_redirect( npmp_newsletter_public_bulk_back( array( 'npmp_public_done' => 'none' ) ) );
		exit;
	}
	wp_safe_redirect( npmp_newsletter_public_bulk_route( 'npmp_public_show' === $action ? 'show' : 'hide', $ids ) );
	exit;
}

/**
 * The confirmation box and the done notice, at the top of the Newsletter
 * Archive screen.
 *
 * @return void
 */
function npmp_newsletter_public_bulk_notices() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only; the change itself checks a nonce.
	$done = isset( $_GET['npmp_public_done'] ) ? sanitize_key( wp_unslash( $_GET['npmp_public_done'] ) ) : '';
	$n    = isset( $_GET['npmp_public_n'] ) ? absint( $_GET['npmp_public_n'] ) : 0;
	$ask  = ! empty( $_GET['npmp_public_confirm'] );
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	if ( 'show' === $done ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
			sprintf(
				/* translators: %s: number of newsletters. */
				_n( 'You put %s newsletter in the public archive.', 'You put %s newsletters in the public archive.', $n, 'nonprofit-manager' ),
				number_format_i18n( $n )
			)
		) . '</p></div>';
	} elseif ( 'hide' === $done ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
			sprintf(
				/* translators: %s: number of newsletters. */
				_n( 'You took %s newsletter out of the public archive.', 'You took %s newsletters out of the public archive.', $n, 'nonprofit-manager' ),
				number_format_i18n( $n )
			)
		) . '</p></div>';
	} elseif ( 'none' === $done ) {
		echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Pick an action and at least one newsletter.', 'nonprofit-manager' ) . '</p></div>';
	}

	if ( ! $ask || ! npmp_newsletter_public_bulk_allowed() ) {
		return;
	}
	$ids = get_transient( npmp_newsletter_public_bulk_key() );
	if ( ! is_array( $ids ) ) {
		return;
	}
	$plan   = npmp_newsletter_public_bulk_plan( npmp_newsletter_public_bulk_rows( $ids ), 'show' );
	$change = count( $plan['change'] );
	echo '<div class="notice notice-info npmp-public-confirm"><p><strong>';
	if ( $change ) {
		echo esc_html(
			sprintf(
				/* translators: %s: number of newsletters. */
				_n( 'Show %s newsletter in the public archive?', 'Show %s newsletters in the public archive?', $change, 'nonprofit-manager' ),
				number_format_i18n( $change )
			)
		);
	} else {
		esc_html_e( 'None of the newsletters you picked can go in the public archive.', 'nonprofit-manager' );
	}
	echo '</strong></p>';
	if ( $change ) {
		echo '<p>' . esc_html__( 'Anyone can read them on their own page, and on any page with the Newsletters block or [npmp_newsletters]. We leave out every reader\'s name, link, and token.', 'nonprofit-manager' ) . '</p>';
	}
	if ( $plan['unsent'] ) {
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %s: number of newsletters. */
				_n( '%s newsletter you picked hasn\'t gone out yet, so it stays hidden.', '%s newsletters you picked haven\'t gone out yet, so they stay hidden.', count( $plan['unsent'] ), 'nonprofit-manager' ),
				number_format_i18n( count( $plan['unsent'] ) )
			)
		) . '</p>';
	}
	if ( $plan['already'] ) {
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %s: number of newsletters. */
				_n( '%s newsletter you picked is already public.', '%s newsletters you picked are already public.', count( $plan['already'] ), 'nonprofit-manager' ),
				number_format_i18n( count( $plan['already'] ) )
			)
		) . '</p>';
	}
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><p>';
	echo '<input type="hidden" name="action" value="npmp_newsletter_public_bulk"><input type="hidden" name="npmp_public_confirmed" value="1">';
	wp_nonce_field( 'npmp_newsletter_public_bulk', 'npmp_public_nonce' );
	if ( $change ) {
		echo '<button type="submit" class="button button-primary">' . esc_html(
			sprintf(
				/* translators: %s: number of newsletters. */
				_n( 'Show %s newsletter', 'Show %s newsletters', $change, 'nonprofit-manager' ),
				number_format_i18n( $change )
			)
		) . '</button> ';
	}
	echo '<a class="button" href="' . esc_url( npmp_newsletter_public_bulk_back() ) . '">' . esc_html__( 'Cancel', 'nonprofit-manager' ) . '</a>';
	echo '</p></form></div>';
}

/**
 * The bulk action picker above the Newsletter Archive table. The table's
 * checkboxes belong to the form id it opens.
 *
 * @return bool Whether the user may use it, so the table prints checkboxes.
 */
function npmp_newsletter_public_bulk_controls() {
	if ( ! npmp_newsletter_public_bulk_allowed() ) {
		return false;
	}
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="npmp-public-bulk" class="tablenav top npmp-public-bulk">';
	echo '<input type="hidden" name="action" value="npmp_newsletter_public_bulk">';
	wp_nonce_field( 'npmp_newsletter_public_bulk', 'npmp_public_nonce' );
	echo '<div class="alignleft actions bulkactions">';
	echo '<label class="screen-reader-text" for="npmp-public-action">' . esc_html__( 'Bulk actions', 'nonprofit-manager' ) . '</label>';
	echo '<select name="npmp_public_action" id="npmp-public-action"><option value="">' . esc_html__( 'Bulk actions', 'nonprofit-manager' ) . '</option>';
	foreach ( npmp_newsletter_public_bulk_actions() as $value => $label ) {
		echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
	}
	echo '</select> <button type="submit" class="button action">' . esc_html__( 'Apply', 'nonprofit-manager' ) . '</button></div></form>';
	return true;
}

/**
 * One row's checkbox for the bulk form.
 *
 * @param int    $post_id Newsletter ID.
 * @param string $title   Title, for the label.
 * @return string HTML.
 */
function npmp_newsletter_public_bulk_checkbox( $post_id, $title ) {
	return '<th scope="row" class="check-column"><input type="checkbox" form="npmp-public-bulk" name="npmp_public_ids[]" value="' . esc_attr( (string) (int) $post_id ) . '" aria-label="' . esc_attr(
		/* translators: %s: newsletter title. */
		sprintf( __( 'Select %s', 'nonprofit-manager' ), $title )
	) . '"></th>';
}

/**
 * The header cell with a select-all box. WordPress's common.js ticks every
 * row box under a thead check-column box.
 *
 * @return string HTML.
 */
function npmp_newsletter_public_bulk_header() {
	return '<td class="manage-column column-cb check-column"><input type="checkbox" form="npmp-public-bulk" aria-label="' . esc_attr__( 'Select all', 'nonprofit-manager' ) . '"></td>';
}
