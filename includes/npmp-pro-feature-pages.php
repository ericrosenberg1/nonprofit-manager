<?php
/**
 * File path: includes/npmp-pro-feature-pages.php
 *
 * Menu entries for the Pro screens a free site does not have.
 *
 * Pro adds Automations, Custom Fields and Segments under the Nonprofit
 * Manager menu. On a free site those items were simply missing, so nobody
 * browsing the menu learned they exist. This file adds the three entries,
 * marked Pro, each opening a short page that says in one line what the
 * screen does and links to pricing with the screen's own campaign tag.
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
	 *                 campaign for the upgrade link).
	 */
	function npmp_pro_feature_pages() {
		return array(
			'npmp_automations'   => array(
				'menu'     => __( 'Automations', 'nonprofit-manager' ),
				'title'    => __( 'Email Automations', 'nonprofit-manager' ),
				'line'     => __( 'Send a welcome email on signup, a thank-you on each donation, a membership expiry reminder, a note when a recurring payment fails, and an email when a tag is added, with no one pressing Send. Email automations come with Nonprofit Manager Pro.', 'nonprofit-manager' ),
				'campaign' => 'automations',
			),
			'npmp_custom_fields' => array(
				'menu'     => __( 'Custom Fields', 'nonprofit-manager' ),
				'title'    => __( 'Custom Fields', 'nonprofit-manager' ),
				'line'     => __( 'Add your own fields to members, donations and events (a phone number, a committee, a T-shirt size) and fill them in from the edit screens and imports. Custom fields come with Nonprofit Manager Pro.', 'nonprofit-manager' ),
				'campaign' => 'custom_fields',
			),
			'npmp_segments'      => array(
				'menu'     => __( 'Segments', 'nonprofit-manager' ),
				'title'    => __( 'Segments', 'nonprofit-manager' ),
				'line'     => __( 'Save a list built from membership level, status, tags or last donation date, and send a newsletter to just that list. Segments come with Nonprofit Manager Pro.', 'nonprofit-manager' ),
				'campaign' => 'segments',
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
			<h1><?php echo esc_html( $page['title'] ); ?> <span class="npmp-pro-badge"><?php esc_html_e( 'Pro', 'nonprofit-manager' ); ?></span></h1>
			<div class="card npmp-upsell-card" style="max-width:640px;padding:20px 24px;">
				<p style="font-size:14px;margin:0 0 1em;"><?php echo esc_html( $page['line'] ); ?></p>
				<p style="margin:0;">
					<a href="<?php echo esc_url( npmp_get_upgrade_url( $page['campaign'] ) ); ?>" class="button button-primary" target="_blank" rel="noopener"><?php esc_html_e( 'Upgrade to Pro', 'nonprofit-manager' ); ?></a>
					<span class="description" style="margin-left:10px;">
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
		$badge = ' <span class="npmp-pro-badge">' . esc_html__( 'Pro', 'nonprofit-manager' ) . '</span>';
		foreach ( npmp_pro_feature_pages() as $slug => $page ) {
			add_submenu_page(
				'npmp_main',
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
 * Grey the three entries in the menu and style the badge.
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
			#adminmenu .wp-submenu a[href="admin.php?page=npmp_segments"] { opacity: .7; }
			#adminmenu .wp-submenu a[href="admin.php?page=npmp_automations"]:hover,
			#adminmenu .wp-submenu a[href="admin.php?page=npmp_custom_fields"]:hover,
			#adminmenu .wp-submenu a[href="admin.php?page=npmp_segments"]:hover,
			#adminmenu .wp-submenu .current a[href="admin.php?page=npmp_automations"],
			#adminmenu .wp-submenu .current a[href="admin.php?page=npmp_custom_fields"],
			#adminmenu .wp-submenu .current a[href="admin.php?page=npmp_segments"] { opacity: 1; }
			.npmp-pro-badge { display:inline-block; font-size:10px; line-height:1; font-weight:600; letter-spacing:.02em; text-transform:uppercase; padding:3px 5px; border-radius:3px; background:#2271b1; color:#fff; vertical-align:middle; }
			#adminmenu .npmp-pro-badge { background:rgba(240,246,252,.22); color:#f0f6fc; }
			#adminmenu .wp-has-current-submenu .npmp-pro-badge { background:rgba(255,255,255,.25); }
		</style>
		<?php
	}
);
