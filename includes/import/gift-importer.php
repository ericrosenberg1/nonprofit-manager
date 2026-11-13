<?php
/**
 * File path: includes/import/gift-importer.php
 *
 * The importer screen for donation plugins (GiveWP, Charitable).
 *
 * A gift source registers on the npmp_gift_import_sources filter:
 *
 *     $sources['givewp'] = array(
 *         'name'         => 'GiveWP',
 *         'has_data'     => callable, bool: any gift or donor rows left?
 *         'counts'       => callable, array{gifts:int,donors:int,test:int,not_received:int,active_plans:int}.
 *         'donor_batch'  => callable( int $after, int $limit ): array of donors, each
 *                           array{cursor:int,email:string,name:string,extra:array,gifts:array[]},
 *                           every gift shaped for npmp_past_gift_record() without 'source'.
 *         'plans_where'  => string: where the donor manages a running plan, for the warning.
 *     );
 *
 * The engine walks donors in cursor order, a time budget per request. A donor
 * who isn't a contact needs a free place (past-gifts.php), and a donor with no
 * place left keeps every gift in the old plugin. Running it again skips every
 * gift already here, so you can run it after more gifts come in.
 *
 * The old plugin can be off: every source reads its tables straight from the
 * database and never reads a payment key, a webhook secret, or a licence.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/** Seconds one request spends importing before it hands back. */
if ( ! defined( 'NPMP_GIFT_IMPORT_SECONDS' ) ) {
	define( 'NPMP_GIFT_IMPORT_SECONDS', 20 );
}

/**
 * Registered gift sources.
 *
 * @return array<string,array>
 */
function npmp_gift_import_sources() {
	$sources = (array) apply_filters( 'npmp_gift_import_sources', array() );
	$out     = array();
	foreach ( $sources as $slug => $source ) {
		if ( is_string( $slug ) && preg_match( '/^[a-z0-9_-]{1,40}$/', $slug ) && is_array( $source ) && ! empty( $source['name'] ) && is_callable( $source['donor_batch'] ?? null ) ) {
			$source['slug'] = $slug;
			$out[ $slug ]   = $source;
		}
	}
	return $out;
}

/**
 * Empty results.
 *
 * @return array<string,int>
 */
function npmp_gift_import_empty_results() {
	return array(
		'donors_created'  => 0,
		'donors_existing' => 0,
		'over_limit'      => 0,
		'over_limit_gifts' => 0,
		'invalid_donors'  => 0,
		'gifts_created'   => 0,
		'gifts_exists'    => 0,
		'gifts_invalid'   => 0,
	);
}

/**
 * Saved progress for one source.
 *
 * @param string $slug Source slug.
 * @return array{cursor:int,running:bool,finished_at:int,results:array}
 */
function npmp_gift_import_state( $slug ) {
	$state = get_option( 'npmp_gift_import_' . $slug, array() );
	$state = is_array( $state ) ? $state : array();
	return array(
		'cursor'      => (int) ( $state['cursor'] ?? 0 ),
		'running'     => ! empty( $state['running'] ),
		'finished_at' => (int) ( $state['finished_at'] ?? 0 ),
		'results'     => array_merge( npmp_gift_import_empty_results(), (array) ( $state['results'] ?? array() ) ),
	);
}

/**
 * Save progress.
 *
 * @param string $slug  Source slug.
 * @param array  $state State.
 * @return void
 */
function npmp_gift_import_save_state( $slug, array $state ) {
	update_option( 'npmp_gift_import_' . $slug, $state, false );
}

/**
 * Import one donor and their gifts. Pure apart from the writers it calls.
 *
 * @param string $slug    Source slug.
 * @param array  $donor   From a source's donor_batch.
 * @param array  $results Running totals, changed in place.
 * @return void
 */
function npmp_gift_import_donor( $slug, array $donor, array &$results ) {
	$gifts = (array) ( $donor['gifts'] ?? array() );
	if ( ! $gifts ) {
		return;
	}
	$person = npmp_past_gift_donor( (string) ( $donor['email'] ?? '' ), (string) ( $donor['name'] ?? '' ), $slug, (array) ( $donor['extra'] ?? array() ) );
	if ( 'over_limit' === $person['result'] ) {
		++$results['over_limit'];
		$results['over_limit_gifts'] += count( $gifts );
		return;
	}
	if ( 'invalid' === $person['result'] ) {
		++$results['invalid_donors'];
		$results['gifts_invalid'] += count( $gifts );
		return;
	}
	++$results[ 'created' === $person['result'] ? 'donors_created' : 'donors_existing' ];

	$latest_gateway = '';
	$latest_ts      = 0;
	$wrote          = false;
	foreach ( $gifts as $gift ) {
		$gift['source'] = $slug;
		$gift['email']  = (string) ( $donor['email'] ?? '' );
		if ( ! isset( $gift['name'] ) || '' === $gift['name'] ) {
			$gift['name'] = (string) ( $donor['name'] ?? '' );
		}
		$status = npmp_past_gift_record( $gift );
		if ( 'created' === $status ) {
			++$results['gifts_created'];
			$wrote = true;
		} elseif ( 'exists' === $status ) {
			++$results['gifts_exists'];
		} else {
			++$results['gifts_invalid'];
		}
		$ts = (int) strtotime( (string) ( $gift['date_gmt'] ?? '' ) . ' UTC' );
		if ( $ts >= $latest_ts ) {
			$latest_ts      = $ts;
			$latest_gateway = (string) ( $gift['gateway'] ?? '' );
		}
	}
	if ( $wrote || 'created' === $person['result'] ) {
		npmp_past_gift_refresh_donor( (string) $donor['email'], (string) ( $donor['name'] ?? '' ), $latest_gateway );
	}
}

/**
 * Run batches until the source runs out or the time is up.
 *
 * @param string $slug    Source slug.
 * @param int    $seconds Time budget.
 * @param int    $limit   Donors per batch.
 * @return array State after the run.
 */
function npmp_gift_import_run( $slug, $seconds = NPMP_GIFT_IMPORT_SECONDS, $limit = 25 ) {
	// One run at a time. Two tabs, or a reload while a batch was still
	// running, read the same rows and wrote each new record twice.
	if ( ! npmp_mail_queue_lock( 'import_gift_' . $slug, 120 ) ) {
		return npmp_gift_import_state( $slug );
	}
	try {
		return npmp_gift_import_run_locked( $slug, $seconds, $limit );
	} finally {
		npmp_mail_queue_unlock( 'import_gift_' . $slug );
	}
}

/**
 * The body of npmp_gift_import_run(), run while holding its lock.
 *
 * @see npmp_gift_import_run()
 * @return array State after the run.
 */
function npmp_gift_import_run_locked( $slug, $seconds = NPMP_GIFT_IMPORT_SECONDS, $limit = 25 ) {
	$sources = npmp_gift_import_sources();
	if ( ! isset( $sources[ $slug ] ) ) {
		return npmp_gift_import_state( $slug );
	}
	$source = $sources[ $slug ];
	$state  = npmp_gift_import_state( $slug );
	if ( ! $state['running'] ) {
		// A fresh run starts at the first donor and counts from zero.
		$state = array(
			'cursor'      => 0,
			'running'     => true,
			'finished_at' => $state['finished_at'],
			'results'     => npmp_gift_import_empty_results(),
		);
	}

	$start = microtime( true );
	do {
		$donors = (array) call_user_func( $source['donor_batch'], $state['cursor'], $limit );
		foreach ( $donors as $donor ) {
			npmp_gift_import_donor( $slug, (array) $donor, $state['results'] );
			$state['cursor'] = max( $state['cursor'], (int) ( $donor['cursor'] ?? 0 ) );
		}
		$finished = count( $donors ) < $limit;
		npmp_gift_import_save_state( $slug, $state );
	} while ( ! $finished && ( microtime( true ) - $start ) < $seconds );

	if ( $finished ) {
		$state['running']     = false;
		$state['finished_at'] = time();
		// No npmp_mark_milestone( 'donation' ) here: that milestone means your
		// own form took a gift, and a past gift proves nothing about the form.
		npmp_gift_import_save_state( $slug, $state );
	}
	return $state;
}

/**
 * Gifts you brought over from one source.
 *
 * @param string $slug Source slug.
 * @return array{gifts:int,donors:int}
 */
function npmp_gift_import_imported_counts( $slug ) {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only counts.
	$gifts  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_npmp_import_source' AND pm.meta_value = %s AND p.post_type = 'npmp_donation' AND p.post_status = 'publish'", $slug ) );
	$donors = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT LOWER(e.meta_value)) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = '_npmp_donation_email' WHERE pm.meta_key = '_npmp_import_source' AND pm.meta_value = %s AND p.post_type = 'npmp_donation' AND p.post_status = 'publish'", $slug ) );
	// phpcs:enable
	return array( 'gifts' => $gifts, 'donors' => $donors );
}

/**
 * Has an import of this source finished?
 *
 * @param string $slug Source slug.
 * @return bool
 */
function npmp_gift_import_is_imported( $slug ) {
	return npmp_gift_import_state( $slug )['finished_at'] > 0;
}

/**
 * URL of the importer screen.
 *
 * @param string $slug Source slug.
 * @param array  $args Extra query args.
 * @return string
 */
function npmp_gift_import_url( $slug, array $args = array() ) {
	return add_query_arg( array_merge( array( 'source' => $slug ), $args ), admin_url( 'admin.php?page=npmp_gift_import' ) );
}

/**
 * The hub source both gift plugins share.
 *
 * @param string   $slug      Slug.
 * @param string   $name      Plugin name.
 * @param string[] $folders   Plugin folders.
 * @param callable $installed Installed plugins callable.
 * @param string[] $checklist Verify checklist.
 * @param string   $reason    Why step 5 is unavailable.
 * @param array    $notes     Optional 'deactivate_note' and 'delete_note' callables (see import-hub.php).
 * @return array
 */
function npmp_gift_hub_source( $slug, $name, array $folders, $installed, array $checklist, $reason, array $notes = array() ) {
	return array_merge( array(
		'name'              => $name,
		'covers'            => __( 'Donors and past gifts', 'nonprofit-manager' ),
		'feature'           => __( 'Donations and contacts', 'nonprofit-manager' ),
		'tier'              => 'free',
		'pro_parts'         => array( __( 'New monthly and yearly gifts on your own form', 'nonprofit-manager' ) ),
		'plugin_folders'    => $folders,
		'has_data'          => function () use ( $slug ) {
			$sources = npmp_gift_import_sources();
			return isset( $sources[ $slug ] ) && (bool) call_user_func( $sources[ $slug ]['has_data'] );
		},
		'is_imported'       => function () use ( $slug ) {
			return npmp_gift_import_is_imported( $slug );
		},
		'can_import'        => function () use ( $slug, $name ) {
			$sources = npmp_gift_import_sources();
			return npmp_import_hub_can_import_when( isset( $sources[ $slug ] ) && (bool) call_user_func( $sources[ $slug ]['has_data'] ), $name );
		},
		'import_url'        => function () use ( $slug ) {
			return npmp_gift_import_url( $slug );
		},
		'import_summary'    => function () use ( $slug ) {
			$n = npmp_gift_import_imported_counts( $slug );
			return sprintf(
				/* translators: 1: number of gifts, 2: number of donors. */
				__( 'You have %1$s gifts here from %2$s donors.', 'nonprofit-manager' ),
				number_format_i18n( $n['gifts'] ),
				number_format_i18n( $n['donors'] )
			);
		},
		'counts'            => function () use ( $slug ) {
			$sources = npmp_gift_import_sources();
			$old     = isset( $sources[ $slug ] ) ? (array) call_user_func( $sources[ $slug ]['counts'] ) : array();
			$new     = npmp_gift_import_imported_counts( $slug );
			return array(
				array(
					'label' => __( 'Gifts received', 'nonprofit-manager' ),
					'old'   => (int) ( $old['gifts'] ?? 0 ),
					'new'   => $new['gifts'],
					'note'  => __( 'You leave test, refunded, and failed gifts in the old plugin.', 'nonprofit-manager' ),
				),
				array(
					'label' => __( 'Donors', 'nonprofit-manager' ),
					'old'   => (int) ( $old['donors'] ?? 0 ),
					'new'   => $new['donors'],
					'note'  => __( 'In the free plugin, donors past the import limit stay in the old plugin.', 'nonprofit-manager' ),
				),
				array(
					'label' => __( 'Donors on a running plan', 'nonprofit-manager' ),
					'old'   => (int) ( $old['active_plans'] ?? 0 ),
					'new'   => null,
					'note'  => __( 'A running plan can\'t move. Ask these donors to give again on your form.', 'nonprofit-manager' ),
				),
			);
		},
		'checklist'         => $checklist,
		'installed_plugins' => $installed,
		'data_removal'      => array(
			'reason' => $reason,
		),
	), array_intersect_key( $notes, array_flip( array( 'deactivate_note', 'delete_note' ) ) ) );
}

// =====================================================================
// Screen
// =====================================================================

add_action( 'admin_menu', 'npmp_gift_import_register_menu', 30 );
add_action( 'admin_head', 'npmp_gift_import_hide_menu' );
add_action( 'admin_post_npmp_gift_import', 'npmp_gift_import_post' );
add_action( 'admin_enqueue_scripts', 'npmp_gift_import_enqueue' );

/**
 * Register the screen. It opens from Switch Plugins.
 *
 * @return void
 */
function npmp_gift_import_register_menu() {
	add_submenu_page(
		'npmp_main',
		__( 'Import past gifts', 'nonprofit-manager' ),
		__( 'Import past gifts', 'nonprofit-manager' ),
		'manage_options',
		'npmp_gift_import',
		'npmp_gift_import_render_page'
	);
}

/**
 * Take the screen out of the menu. This runs on admin_head, after WordPress
 * checked access to the screen and before it draws the menu: removing it on
 * admin_menu makes WordPress refuse the screen itself.
 *
 * @return void
 */
function npmp_gift_import_hide_menu() {
	remove_submenu_page( 'npmp_main', 'npmp_gift_import' );
}

/**
 * The hub's styles and its carry-on script.
 *
 * @param string $hook Admin hook suffix.
 * @return void
 */
function npmp_gift_import_enqueue( $hook ) {
	if ( false === strpos( (string) $hook, 'npmp_gift_import' ) ) {
		return;
	}
	$base = plugin_dir_url( dirname( __DIR__ ) );
	$ver  = function_exists( 'npmp_get_asset_version' ) ? npmp_get_asset_version( 'assets/js/npmp-import-hub.js' ) : '1';
	wp_enqueue_style( 'npmp-import-hub', $base . 'assets/css/npmp-import-hub.css', array(), $ver );
	wp_enqueue_script( 'npmp-import-hub', $base . 'assets/js/npmp-import-hub.js', array(), $ver, true );
}

/**
 * Handle the Import button and each carry-on request.
 *
 * @return void
 */
function npmp_gift_import_post() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nonprofit-manager' ), 403 );
	}
	$slug = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
	check_admin_referer( 'npmp_gift_import_' . $slug, 'npmp_gift_nonce' );
	$sources = npmp_gift_import_sources();
	if ( ! isset( $sources[ $slug ] ) ) {
		wp_die( esc_html__( 'We can\'t import gifts from that plugin.', 'nonprofit-manager' ), 400 );
	}
	if ( ! empty( $_POST['restart'] ) ) {
		$state            = npmp_gift_import_state( $slug );
		$state['running'] = false;
		npmp_gift_import_save_state( $slug, $state );
	}
	npmp_gift_import_run( $slug );
	wp_safe_redirect( npmp_gift_import_url( $slug ) );
	exit;
}

/**
 * Hidden fields for the form.
 *
 * @param string $slug Source slug.
 * @return void
 */
function npmp_gift_import_form_fields( $slug ) {
	echo '<input type="hidden" name="action" value="npmp_gift_import">';
	echo '<input type="hidden" name="source" value="' . esc_attr( $slug ) . '">';
	wp_nonce_field( 'npmp_gift_import_' . $slug, 'npmp_gift_nonce' );
}

/**
 * The screen.
 *
 * @return void
 */
function npmp_gift_import_render_page() {
	npmp_verify_admin_access( 'manage_options' );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
	$slug    = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : '';
	$sources = npmp_gift_import_sources();
	echo '<div class="wrap npmp-hub-wrap">';
	if ( ! isset( $sources[ $slug ] ) ) {
		echo '<h1>' . esc_html__( 'Import past gifts', 'nonprofit-manager' ) . '</h1>';
		echo '<hr class="wp-header-end">';
		echo '<p>' . esc_html__( 'Pick the plugin you\'re switching from on the Switch Plugins screen.', 'nonprofit-manager' ) . ' <a href="' . esc_url( npmp_import_hub_url() ) . '">' . esc_html__( 'Switch Plugins', 'nonprofit-manager' ) . '</a></p></div>';
		return;
	}
	$source = $sources[ $slug ];
	$state  = npmp_gift_import_state( $slug );
	$counts = array_merge(
		array( 'gifts' => 0, 'donors' => 0, 'test' => 0, 'not_received' => 0, 'active_plans' => 0 ),
		(array) call_user_func( $source['counts'] )
	);

	/* translators: %s: old plugin name, such as GiveWP. */
	echo '<h1>' . esc_html( sprintf( __( 'Import past gifts from %s', 'nonprofit-manager' ), $source['name'] ) ) . '</h1>';
	echo '<hr class="wp-header-end">';
	echo '<p><a href="' . esc_url( npmp_import_hub_url( $slug ) ) . '">&larr; ' . esc_html__( 'Back to Switch Plugins', 'nonprofit-manager' ) . '</a></p>';

	if ( $state['running'] ) {
		npmp_gift_import_render_results( $state['results'] );
		echo '<p>' . esc_html__( 'Importing in batches. Keep this page open.', 'nonprofit-manager' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-npmp-hub-continue="1">';
		npmp_gift_import_form_fields( $slug );
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Keep going', 'nonprofit-manager' ) . '</button></p></form></div>';
		return;
	}

	if ( $state['finished_at'] ) {
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Your import is done.', 'nonprofit-manager' ) . '</p></div>';
		npmp_gift_import_render_results( $state['results'] );
		echo '<p><a class="button button-primary" href="' . esc_url( npmp_import_hub_url( $slug ) ) . '">' . esc_html__( 'Finish on Switch Plugins', 'nonprofit-manager' ) . '</a></p>';
	}

	echo '<h2>' . esc_html__( 'What comes over', 'nonprofit-manager' ) . '</h2><ul class="npmp-hub-checklist">';
	echo '<li>' . esc_html(
		sprintf(
			/* translators: %s: number of gifts. */
			_n( '%s gift, with its date, amount, currency, and payment method.', '%s gifts, each with its date, amount, currency, and payment method.', $counts['gifts'], 'nonprofit-manager' ),
			number_format_i18n( $counts['gifts'] )
		)
	) . ' ' . esc_html(
		sprintf(
			/* translators: %s: number of donors. */
			_n( 'They came from %s donor.', 'They came from %s donors.', $counts['donors'], 'nonprofit-manager' ),
			number_format_i18n( $counts['donors'] )
		)
	) . '</li>';
	echo '<li>' . esc_html__( 'Each donor becomes a contact, or joins the contact you already have with that email. Their giving totals and last gift date come from these gifts.', 'nonprofit-manager' ) . '</li>';
	echo '<li>' . esc_html__( 'No donor gets an email or a receipt.', 'nonprofit-manager' ) . '</li>';
	echo '</ul>';

	echo '<h2>' . esc_html__( 'What you leave in the old plugin', 'nonprofit-manager' ) . '</h2><ul class="npmp-hub-checklist">';
	echo '<li>' . esc_html(
		sprintf(
			/* translators: %s: number of test gifts. */
			_n( '%s test gift.', '%s test gifts.', $counts['test'], 'nonprofit-manager' ),
			number_format_i18n( $counts['test'] )
		)
	) . ' ' . esc_html(
		sprintf(
			/* translators: %s: number of gifts. */
			_n( '%s gift with a refund, a failed charge, or no payment.', '%s gifts with a refund, a failed charge, or no payment.', $counts['not_received'], 'nonprofit-manager' ),
			number_format_i18n( $counts['not_received'] )
		)
	) . '</li>';
	echo '<li>' . esc_html__( 'Payment keys, webhook secrets, and license keys. You connect Stripe on the Payment Settings screen.', 'nonprofit-manager' ) . '</li>';
	echo '<li>' . esc_html__( 'Donation forms, campaigns, and goals. You build a form here once and place it on your page.', 'nonprofit-manager' ) . '</li>';
	echo '</ul>';

	if ( function_exists( 'npmp_is_pro' ) && ! npmp_is_pro() && function_exists( 'npmp_import_free_remaining' ) ) {
		$left = npmp_import_free_remaining();
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: free total of new contacts, 2: places left. */
				__( 'With the free plugin, you can add up to %1$s new contacts through imports, and you have %2$s places left. A donor who\'s already a contact doesn\'t use a place. A donor past the limit stays in the old plugin with every one of their gifts.', 'nonprofit-manager' ),
				number_format_i18n( NPMP_IMPORT_FREE_MAX_ROWS ),
				number_format_i18n( $left )
			)
		) . '</p>';
	}

	if ( $counts['active_plans'] > 0 ) {
		echo '<div class="notice notice-warning inline"><p>' . esc_html(
			sprintf(
				/* translators: 1: number of plans, 2: old plugin name. */
				_n(
					'%1$s donor gives on a repeating plan in %2$s. We can\'t move a running plan. Their payment processor keeps charging each donor. Once %2$s is off, you won\'t see those payments here.',
					'%1$s donors give on a repeating plan in %2$s. We can\'t move a running plan. Their payment processor keeps charging each donor. Once %2$s is off, you won\'t see those payments here.',
					$counts['active_plans'],
					'nonprofit-manager'
				),
				number_format_i18n( $counts['active_plans'] ),
				$source['name']
			)
		) . '</p><p>' . esc_html__( 'Before you turn the old plugin off, ask each of them to start a new monthly gift on your Nonprofit Manager form, then cancel the old plan. Monthly gifts on your own form come with Pro.', 'nonprofit-manager' ) . '</p></div>';
	}

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	npmp_gift_import_form_fields( $slug );
	echo '<input type="hidden" name="restart" value="1">';
	$label = $state['finished_at'] ? __( 'Import again', 'nonprofit-manager' ) : __( 'Import gifts', 'nonprofit-manager' );
	echo '<p><button type="submit" class="button button-primary">' . esc_html( $label ) . '</button></p>';
	if ( $state['finished_at'] ) {
		echo '<p class="description">' . esc_html__( 'Running it again adds only gifts you don\'t have here yet.', 'nonprofit-manager' ) . '</p>';
	}
	echo '</form></div>';
}

/**
 * Results of the last run.
 *
 * @param array $r Results.
 * @return void
 */
function npmp_gift_import_render_results( array $r ) {
	$lines = array(
		/* translators: %s: number of gifts. */
		sprintf( __( 'New gifts here: %s', 'nonprofit-manager' ), number_format_i18n( $r['gifts_created'] ) ),
		/* translators: %s: number of gifts. */
		sprintf( __( 'Gifts you already had: %s', 'nonprofit-manager' ), number_format_i18n( $r['gifts_exists'] ) ),
		/* translators: %s: number of contacts. */
		sprintf( __( 'New contacts: %s', 'nonprofit-manager' ), number_format_i18n( $r['donors_created'] ) ),
		/* translators: %s: number of contacts. */
		sprintf( __( 'Donors who were already contacts: %s', 'nonprofit-manager' ), number_format_i18n( $r['donors_existing'] ) ),
	);
	if ( $r['over_limit'] ) {
		$lines[] = sprintf(
			/* translators: 1: number of donors, 2: number of gifts. */
			__( 'Donors past the free limit: %1$s, with %2$s gifts. Pro brings them over.', 'nonprofit-manager' ),
			number_format_i18n( $r['over_limit'] ),
			number_format_i18n( $r['over_limit_gifts'] )
		);
	}
	if ( $r['gifts_invalid'] ) {
		/* translators: %s: number of gifts. */
		$lines[] = sprintf( __( 'Gifts we skipped for a missing email, amount, currency, or date: %s', 'nonprofit-manager' ), number_format_i18n( $r['gifts_invalid'] ) );
	}
	echo '<ul class="npmp-hub-checklist">';
	foreach ( $lines as $line ) {
		echo '<li>' . esc_html( $line ) . '</li>';
	}
	echo '</ul>';
}
