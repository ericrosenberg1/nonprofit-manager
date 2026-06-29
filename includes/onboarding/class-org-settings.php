<?php
/**
 * File path: includes/onboarding/class-org-settings.php
 *
 * Organization identity data model. Captured during the tour and used
 * to auto-fill donation receipts, email signatures, the public
 * "About" widget, and the iCal feed organizer details.
 *
 * Stored under the `npmp_org_settings` option as a flat associative
 * array. Keep the schema small and predictable — third-party themes /
 * helpers can read this directly via get_option().
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

class NPMP_Org_Settings {

	const OPTION_KEY = 'npmp_org_settings';

	/**
	 * Default values. Anything missing from the saved option falls
	 * back to these. Some values are derived from WP core options so
	 * a fresh install has reasonable defaults until the user fills
	 * in the form.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'name'           => (string) get_option( 'blogname', '' ),
			'type'           => '',
			'ein'            => '',
			'address_line1'  => '',
			'address_line2'  => '',
			'city'           => '',
			'state'          => '',
			'postal_code'    => '',
			'country'        => 'US',
			'contact_email'  => (string) get_option( 'admin_email', '' ),
			'website'        => (string) get_option( 'siteurl', '' ),
			'phone'          => '',
		);
	}

	/**
	 * Allowed org types for the dropdown. Used by both the form and the
	 * sanitiser.
	 *
	 * @return array key => label.
	 */
	public static function types() {
		return array(
			'501c3'           => __( '501(c)(3) public charity', 'nonprofit-manager' ),
			'501c4'           => __( '501(c)(4) social welfare org', 'nonprofit-manager' ),
			'religious'       => __( 'Religious congregation', 'nonprofit-manager' ),
			'pto'             => __( 'PTO or school group', 'nonprofit-manager' ),
			'club'            => __( 'Sports or recreational club', 'nonprofit-manager' ),
			'community'       => __( 'HOA or community council', 'nonprofit-manager' ),
			'mutual_aid'      => __( 'Mutual aid network', 'nonprofit-manager' ),
			'other'           => __( 'Other / not yet incorporated', 'nonprofit-manager' ),
		);
	}

	/**
	 * Read the current settings merged with defaults.
	 *
	 * @return array
	 */
	public static function get() {
		$saved = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, self::defaults() );
	}

	/**
	 * Sanitise + persist a posted form.
	 *
	 * @param array $incoming Raw $_POST values (already unslashed).
	 * @return array Cleaned + saved values.
	 */
	public static function save( $incoming ) {
		$types = array_keys( self::types() );

		$clean = array(
			'name'           => sanitize_text_field( $incoming['name'] ?? '' ),
			'type'           => in_array( ( $incoming['type'] ?? '' ), $types, true ) ? $incoming['type'] : '',
			// Strip non-digits and dashes for EIN; allow empty.
			'ein'            => preg_replace( '/[^0-9-]/', '', (string) ( $incoming['ein'] ?? '' ) ),
			'address_line1'  => sanitize_text_field( $incoming['address_line1'] ?? '' ),
			'address_line2'  => sanitize_text_field( $incoming['address_line2'] ?? '' ),
			'city'           => sanitize_text_field( $incoming['city'] ?? '' ),
			'state'          => sanitize_text_field( $incoming['state'] ?? '' ),
			'postal_code'    => sanitize_text_field( $incoming['postal_code'] ?? '' ),
			'country'        => sanitize_text_field( $incoming['country'] ?? 'US' ),
			'contact_email'  => sanitize_email( $incoming['contact_email'] ?? '' ),
			'website'        => esc_url_raw( $incoming['website'] ?? '' ),
			'phone'          => sanitize_text_field( $incoming['phone'] ?? '' ),
		);

		update_option( self::OPTION_KEY, $clean );
		return $clean;
	}

	/**
	 * Render the org-identity form section. Designed to be embedded in
	 * the existing General Settings page (the tour highlights the
	 * `#npmp_org_name` field here).
	 */
	public static function render_form_section() {
		$org   = self::get();
		$types = self::types();
		?>
		<div class="npmp-org-settings" style="background:#fff;border:1px solid #d1d5db;border-radius:8px;padding:24px;margin:24px 0;max-width:760px;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Organization identity', 'nonprofit-manager' ); ?></h2>
			<p class="description" style="margin-top:0;"><?php esc_html_e( 'Used for donation receipts, email signatures, and the public About widget. You can leave optional fields blank.', 'nonprofit-manager' ); ?></p>

			<?php wp_nonce_field( 'npmp_save_org_settings', 'npmp_org_settings_nonce' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="npmp_org_name"><?php esc_html_e( 'Organization name', 'nonprofit-manager' ); ?> <span style="color:#dc2626;">*</span></label></th>
					<td>
						<input type="text" id="npmp_org_name" name="npmp_org_settings[name]" value="<?php echo esc_attr( $org['name'] ); ?>" class="regular-text" required>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="npmp_org_type"><?php esc_html_e( 'Type', 'nonprofit-manager' ); ?></label></th>
					<td>
						<select id="npmp_org_type" name="npmp_org_settings[type]">
							<option value=""><?php esc_html_e( '— Select —', 'nonprofit-manager' ); ?></option>
							<?php foreach ( $types as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $org['type'], $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="npmp_org_ein"><?php esc_html_e( 'EIN (Federal Tax ID)', 'nonprofit-manager' ); ?></label></th>
					<td>
						<input type="text" id="npmp_org_ein" name="npmp_org_settings[ein]" value="<?php echo esc_attr( $org['ein'] ); ?>" class="regular-text" placeholder="XX-XXXXXXX" pattern="[0-9-]*">
						<p class="description"><?php esc_html_e( 'Optional — required on donation receipts if you\'re a 501(c)(3).', 'nonprofit-manager' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="npmp_org_address_line1"><?php esc_html_e( 'Address', 'nonprofit-manager' ); ?></label></th>
					<td>
						<input type="text" id="npmp_org_address_line1" name="npmp_org_settings[address_line1]" value="<?php echo esc_attr( $org['address_line1'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Street address', 'nonprofit-manager' ); ?>">
						<br><br>
						<input type="text" name="npmp_org_settings[address_line2]" value="<?php echo esc_attr( $org['address_line2'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Apt / suite / PO box (optional)', 'nonprofit-manager' ); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'City / State / ZIP', 'nonprofit-manager' ); ?></th>
					<td>
						<input type="text" name="npmp_org_settings[city]" value="<?php echo esc_attr( $org['city'] ); ?>" placeholder="<?php esc_attr_e( 'City', 'nonprofit-manager' ); ?>" style="width:200px;">
						<input type="text" name="npmp_org_settings[state]" value="<?php echo esc_attr( $org['state'] ); ?>" placeholder="<?php esc_attr_e( 'State', 'nonprofit-manager' ); ?>" style="width:80px;">
						<input type="text" name="npmp_org_settings[postal_code]" value="<?php echo esc_attr( $org['postal_code'] ); ?>" placeholder="<?php esc_attr_e( 'ZIP', 'nonprofit-manager' ); ?>" style="width:120px;">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="npmp_org_contact_email"><?php esc_html_e( 'Primary contact email', 'nonprofit-manager' ); ?></label></th>
					<td>
						<input type="email" id="npmp_org_contact_email" name="npmp_org_settings[contact_email]" value="<?php echo esc_attr( $org['contact_email'] ); ?>" class="regular-text">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="npmp_org_phone"><?php esc_html_e( 'Phone', 'nonprofit-manager' ); ?></label></th>
					<td>
						<input type="tel" id="npmp_org_phone" name="npmp_org_settings[phone]" value="<?php echo esc_attr( $org['phone'] ); ?>" class="regular-text">
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Hook the form save into the existing General Settings handler.
	 */
	public static function maybe_save_from_post() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! isset( $_POST['npmp_org_settings_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_org_settings_nonce'] ) ), 'npmp_save_org_settings' ) ) {
			return;
		}
		if ( empty( $_POST['npmp_org_settings'] ) || ! is_array( $_POST['npmp_org_settings'] ) ) {
			return;
		}
		// Per-key sanitisation happens inside save(). Pass through unslashed.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		self::save( wp_unslash( $_POST['npmp_org_settings'] ) );
	}
}

add_action( 'admin_init', array( 'NPMP_Org_Settings', 'maybe_save_from_post' ), 5 );
