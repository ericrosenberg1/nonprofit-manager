<?php
/**
 * File path: includes/npmp-pro-feature-pages.php
 *
 * Menu entries for the Pro screens a free site does not have.
 *
 * Pro adds Automations, Custom Fields and Segments under the Nonprofit
 * Manager menu, and Groups under Membership. On a free site those items were
 * simply missing, so nobody browsing the menu learned they exist. This file
 * adds the entries, marked Pro, each opening a short page that says in one
 * line what the screen does and links to pricing with the screen's own
 * campaign tag.
 *
 * It also prints the greyed Pro controls a free site shows in place: the
 * Groups row on the contact screen, the group filter on the Member List, the
 * Groups picker in a newsletter's audience box, the Group picker in an
 * event's details, and the Print or save as PDF button above admin tables.
 * Each one is disabled markup with one Pro badge that links to pricing, and
 * each hangs off the same hook Pro uses for the working control.
 *
 * Pro loads after the free plugin (alphabetical plugin order), so the hooks
 * below check npmp_is_pro() when they run, not when this file loads. The
 * slugs match Pro's own, so when Pro is installed its real screens take the
 * same places in the menu.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'npmp_pro_feature_pages' ) ) {
	/**
	 * The Pro screens the free menu previews.
	 *
	 * @return array[] Keyed by menu slug: menu (menu label), title (page
	 *                 heading), line (what the screen does), campaign (utm
	 *                 campaign for the upgrade link), and optional parent
	 *                 (menu slug, default npmp_main).
	 */
	function npmp_pro_feature_pages() {
		return array(
			'npmp_automations'   => array(
				'menu'     => __( 'Automations', 'nonprofit-manager' ),
				'title'    => __( 'Email Automations', 'nonprofit-manager' ),
				'line'     => __( 'Send a welcome email on signup, a thank-you on each donation, a membership expiry reminder, a note when a recurring payment fails, and an email when you add a tag, with no one pressing Send. Email automations come with Nonprofit Manager Pro.', 'nonprofit-manager' ),
				'campaign' => 'automations',
			),
			'npmp_custom_fields' => array(
				'menu'     => __( 'Custom Fields', 'nonprofit-manager' ),
				'title'    => __( 'Custom Fields', 'nonprofit-manager' ),
				'line'     => sprintf(
					/* translators: 1: lowercase plural word for members, such as members, 2: lowercase plural word for donations, such as donations or contributions. */
					__( 'Add your own fields to %1$s, %2$s, and events (a phone number, a committee, a T-shirt size) and fill them in from the edit screens and imports. Custom fields come with Nonprofit Manager Pro.', 'nonprofit-manager' ),
					npmp_label( 'members_lc' ),
					npmp_label( 'donations_lc' )
				),
				'campaign' => 'custom_fields',
			),
			'npmp_segments'      => array(
				'menu'     => __( 'Segments', 'nonprofit-manager' ),
				'title'    => __( 'Segments', 'nonprofit-manager' ),
				'line'     => __( 'Save a list built from membership level, status, tags, or last donation date, and send a newsletter to that list alone. Segments come with Nonprofit Manager Pro.', 'nonprofit-manager' ),
				'campaign' => 'segments',
			),
			'npmp_groups'        => array(
				'menu'     => __( 'Groups', 'nonprofit-manager' ),
				'title'    => __( 'Groups', 'nonprofit-manager' ),
				'line'     => sprintf(
					/* translators: %s: lowercase plural word for members, such as members. */
					__( 'Put %s in one or more groups, send each group its own newsletters and event reminders, and give each group its own calendar and signup form. Groups come with Nonprofit Manager Pro.', 'nonprofit-manager' ),
					npmp_label( 'members_lc' )
				),
				'campaign' => 'groups',
				'parent'   => 'npmp_membership',
			),
		);
	}
}

if ( ! function_exists( 'npmp_pro_feature_page_slug' ) ) {
	/**
	 * The page slug WordPress is rendering, or '' off these pages.
	 *
	 * @return string
	 */
	function npmp_pro_feature_page_slug() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page lookup, nothing is changed.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return array_key_exists( $page, npmp_pro_feature_pages() ) ? $page : '';
	}
}

if ( ! function_exists( 'npmp_render_pro_feature_page' ) ) {
	/**
	 * One preview page: heading, one line, the upgrade button.
	 */
	function npmp_render_pro_feature_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nonprofit-manager' ), '', array( 'response' => 403 ) );
		}
		$slug = npmp_pro_feature_page_slug();
		if ( '' === $slug ) {
			return;
		}
		$page = npmp_pro_feature_pages()[ $slug ];
		?>
		<div class="wrap npmp-admin-page">
			<?php npmp_admin_page_title( $page['title'], array(), __( 'Pro', 'nonprofit-manager' ) ); ?>
			<div class="card npmp-upsell-card">
				<p class="npmp-upsell-line"><?php echo esc_html( $page['line'] ); ?></p>
				<p class="npmp-flush">
					<a href="<?php echo esc_url( npmp_get_upgrade_url( $page['campaign'] ) ); ?>" class="button button-primary" target="_blank" rel="noopener"><?php esc_html_e( 'Upgrade to Pro', 'nonprofit-manager' ); ?></a>
					<span class="description npmp-upsell-note">
						<?php
						printf(
							/* translators: %s: Pro's starting price, e.g. "$47 a year". */
							esc_html__( 'From %s, 30-day money-back guarantee.', 'nonprofit-manager' ),
							esc_html( npmp_get_pro_starting_price() )
						);
						?>
					</span>
				</p>
			</div>
		</div>
		<?php
	}
}

/*
 * The menu entries, after the plugin's own submenus (priority 50) so they sit
 * at the bottom of the Nonprofit Manager menu.
 */
add_action(
	'admin_menu',
	static function () {
		if ( npmp_is_pro() ) {
			return;
		}
		$badge    = ' <span class="npmp-pro-badge">' . esc_html__( 'Pro', 'nonprofit-manager' ) . '</span>';
		$features = (array) get_option( 'npmp_enabled_features', array() );
		foreach ( npmp_pro_feature_pages() as $slug => $page ) {
			$parent = isset( $page['parent'] ) ? $page['parent'] : 'npmp_main';
			// The Membership menu exists only while the members module is on.
			if ( 'npmp_membership' === $parent && empty( $features['members'] ) ) {
				continue;
			}
			add_submenu_page(
				$parent,
				$page['title'],
				esc_html( $page['menu'] ) . $badge,
				'manage_options',
				$slug,
				'npmp_render_pro_feature_page'
			);
		}
	},
	50
);

/*
 * Grey the preview entries in the menu, and style the badge and the greyed
 * controls.
 */
add_action(
	'admin_head',
	static function () {
		if ( npmp_is_pro() ) {
			return;
		}
		?>
		<style>
			#adminmenu .wp-submenu a[href="admin.php?page=npmp_automations"],
			#adminmenu .wp-submenu a[href="admin.php?page=npmp_custom_fields"],
			#adminmenu .wp-submenu a[href="admin.php?page=npmp_segments"],
			#adminmenu .wp-submenu a[href="admin.php?page=npmp_groups"] { opacity: .7; }
			#adminmenu .wp-submenu a[href="admin.php?page=npmp_automations"]:hover,
			#adminmenu .wp-submenu a[href="admin.php?page=npmp_custom_fields"]:hover,
			#adminmenu .wp-submenu a[href="admin.php?page=npmp_segments"]:hover,
			#adminmenu .wp-submenu a[href="admin.php?page=npmp_groups"]:hover,
			#adminmenu .wp-submenu .current a[href="admin.php?page=npmp_automations"],
			#adminmenu .wp-submenu .current a[href="admin.php?page=npmp_custom_fields"],
			#adminmenu .wp-submenu .current a[href="admin.php?page=npmp_segments"],
			#adminmenu .wp-submenu .current a[href="admin.php?page=npmp_groups"] { opacity: 1; }
			.npmp-pro-badge { display:inline-block; font-size:10px; line-height:1; font-weight:600; letter-spacing:.02em; text-transform:uppercase; padding:3px 5px; border-radius:3px; background:#0f766e; color:#fff; vertical-align:middle; }
			#adminmenu .npmp-pro-badge { background:rgba(240,246,252,.22); color:#f0f6fc; }
			#adminmenu .wp-has-current-submenu .npmp-pro-badge { background:rgba(255,255,255,.25); }
			/* Greyed Pro controls: disabled markup plus one badge link. */
			.npmp-pro-locked { display:inline-flex; align-items:center; flex-wrap:wrap; gap:6px; max-width:100%; }
			.npmp-pro-locked select:disabled, .npmp-pro-locked input:disabled, .npmp-pro-locked button:disabled { opacity:.6; cursor:not-allowed; }
			.npmp-pro-locked .description { margin:0; color:#50575e; }
			a.npmp-pro-badge { text-decoration:none; }
			a.npmp-pro-badge:hover, a.npmp-pro-badge:focus { background:#115e59; color:#fff; }
			a.npmp-pro-badge:focus { outline:none; box-shadow:0 0 0 2px #fff, 0 0 0 4px #0f766e; }
			.npmp-pro-locked-block { display:block; margin:8px 0; }
			.npmp-admin-print-locked { margin:0 0 12px; }
			.tablenav .npmp-admin-print-locked { margin:0; }
		</style>
		<?php
	}
);

if ( ! function_exists( 'npmp_pro_lock_badge' ) ) {
	/**
	 * The Pro badge as the one upgrade link of a greyed control.
	 *
	 * @param string $url   Campaign-tagged upgrade URL from npmp_get_upgrade_url( campaign ).
	 * @param string $label What the badge says to a screen reader.
	 * @return string
	 */
	function npmp_pro_lock_badge( $url, $label ) {
		return '<a class="npmp-pro-badge" href="' . esc_url( $url ) . '" target="_blank" rel="noopener" title="' . esc_attr( $label ) . '" aria-label="' . esc_attr( $label ) . '">' . esc_html__( 'Pro', 'nonprofit-manager' ) . '</a>';
	}
}

if ( ! function_exists( 'npmp_pro_teaser_member_groups_row' ) ) {
	/**
	 * A greyed Groups row on the contact edit screen.
	 *
	 * @return void
	 */
	function npmp_pro_teaser_member_groups_row() {
		if ( npmp_is_pro() ) {
			return;
		}
		echo '<tr class="npmp-pro-locked-row"><th scope="row"><label for="npmp-member-groups-locked">' . esc_html__( 'Groups', 'nonprofit-manager' ) . '</label></th><td>';
		echo '<span class="npmp-pro-locked"><select id="npmp-member-groups-locked" disabled><option>' . esc_html__( 'No groups', 'nonprofit-manager' ) . '</option></select> ';
		echo npmp_pro_lock_badge( npmp_get_upgrade_url( 'groups' ), __( 'Groups come with Nonprofit Manager Pro', 'nonprofit-manager' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in npmp_pro_lock_badge().
		echo ' <span class="description">' . esc_html__( 'You can put each member in one or more groups with Pro.', 'nonprofit-manager' ) . '</span></span>';
		echo '</td></tr>';
	}
}
add_action( 'npmp_member_edit_rows', 'npmp_pro_teaser_member_groups_row' );

if ( ! function_exists( 'npmp_pro_teaser_members_group_filter' ) ) {
	/**
	 * A greyed Group menu in the Member List filter bar.
	 *
	 * @return void
	 */
	function npmp_pro_teaser_members_group_filter() {
		if ( npmp_is_pro() ) {
			return;
		}
		echo '<span class="npmp-pro-locked" style="margin-left:10px;"><label for="npmp-member-group-locked">' . esc_html__( 'Group', 'nonprofit-manager' ) . '</label> ';
		echo '<select id="npmp-member-group-locked" disabled><option>' . esc_html__( 'All groups', 'nonprofit-manager' ) . '</option></select> ';
		echo npmp_pro_lock_badge( npmp_get_upgrade_url( 'groups' ), __( 'Group filters come with Nonprofit Manager Pro', 'nonprofit-manager' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in npmp_pro_lock_badge().
		echo '</span> ';
	}
}
add_action( 'npmp_members_filter_bar', 'npmp_pro_teaser_members_group_filter' );

if ( ! function_exists( 'npmp_pro_teaser_newsletter_groups' ) ) {
	/**
	 * A greyed Groups picker in a newsletter's audience box.
	 *
	 * @return void
	 */
	function npmp_pro_teaser_newsletter_groups() {
		if ( npmp_is_pro() ) {
			return;
		}
		echo '<span class="npmp-pro-locked npmp-pro-locked-block"><label><input type="checkbox" disabled> ' . esc_html__( 'Send to one or more groups', 'nonprofit-manager' ) . '</label> ';
		echo npmp_pro_lock_badge( npmp_get_upgrade_url( 'groups' ), __( 'Group newsletters come with Nonprofit Manager Pro', 'nonprofit-manager' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in npmp_pro_lock_badge().
		echo '</span>';
	}
}
add_action( 'npmp_newsletter_audience_fields', 'npmp_pro_teaser_newsletter_groups' );

if ( ! function_exists( 'npmp_pro_teaser_event_group' ) ) {
	/**
	 * A greyed Group picker in an event's details box.
	 *
	 * @return void
	 */
	function npmp_pro_teaser_event_group() {
		if ( npmp_is_pro() ) {
			return;
		}
		echo '<p><label for="npmp-event-group-locked"><strong>' . esc_html__( 'Group', 'nonprofit-manager' ) . '</strong></label><br>';
		echo '<span class="npmp-pro-locked"><select id="npmp-event-group-locked" disabled><option>' . esc_html__( 'Everyone', 'nonprofit-manager' ) . '</option></select> ';
		echo npmp_pro_lock_badge( npmp_get_upgrade_url( 'groups' ), __( 'Group events come with Nonprofit Manager Pro', 'nonprofit-manager' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in npmp_pro_lock_badge().
		echo ' <span class="description">' . esc_html__( 'You can put an event on a group\'s own calendar with Pro.', 'nonprofit-manager' ) . '</span></span></p>';
	}
}
add_action( 'npmp_event_details_fields', 'npmp_pro_teaser_event_group' );

if ( ! function_exists( 'npmp_pro_teaser_admin_print' ) ) {
	/**
	 * Fires above an admin table or report, once per screen.
	 *
	 * Pro hooks it to print its working "Print or save as PDF" button, which
	 * opens a clean print page of the whole filtered list. Its print sources
	 * come from `apply_filters( 'npmp_print_sources', array() )`, keyed by
	 * the same source key. On a free site, free hooks it itself to show the
	 * button greyed with a Pro badge.
	 *
	 * Sources: 'members', 'donations', 'events' (view 'overview' or 'all'),
	 * 'newsletter_reports', 'overview', and 'scheduled_emails'.
	 *
	 * do_action( 'npmp_admin_table_toolbar', string $source_key, array $context )
	 *
	 * `$context` carries what the screen filtered by, so Pro can rebuild the
	 * same list: members { query_args, request }, donations { year, month },
	 * events { view, request? }, newsletter_reports { limit }, overview
	 * { features }, scheduled_emails {}. Give your callback's second
	 * parameter a default of array().
	 *
	 * @param string $source_key Which table.
	 * @return void
	 */
	function npmp_pro_teaser_admin_print( $source_key = '' ) {
		if ( npmp_is_pro() ) {
			return;
		}
		echo '<p class="npmp-pro-locked npmp-admin-print-locked" data-source="' . esc_attr( (string) $source_key ) . '"><button type="button" class="button" disabled>' . esc_html__( 'Print or save as PDF', 'nonprofit-manager' ) . '</button> ';
		echo npmp_pro_lock_badge( npmp_get_upgrade_url( 'admin_print' ), __( 'Printing admin lists comes with Nonprofit Manager Pro', 'nonprofit-manager' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in npmp_pro_lock_badge().
		echo '</p>';
	}
}
add_action( 'npmp_admin_table_toolbar', 'npmp_pro_teaser_admin_print' );
