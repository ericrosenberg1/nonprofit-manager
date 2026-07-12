<?php
/**
 * Payment Gateway Integrations
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render donation form based on selected gateway
 *
 * @param string $gateway Gateway type.
 * @return string HTML form output.
 */
function npmp_render_gateway_donation_form( $gateway ) {
	switch ( $gateway ) {
		case 'paypal_link':
			return npmp_render_paypal_link_form();
		case 'venmo_link':
			return npmp_render_venmo_link_form();
		case 'paypal_api':
			if ( npmp_is_pro() ) {
				return npmp_render_paypal_api_form();
			}
			break;
		case 'stripe':
			if ( npmp_is_pro() ) {
				return npmp_render_stripe_form();
			}
			break;
	}

	return '<div class="npmp-donation-form npmp-donation-form--inactive"><p>' . esc_html__( 'Payment gateway not configured correctly.', 'nonprofit-manager' ) . '</p></div>';
}

/**
 * Render donation form with multiple payment gateways as buttons
 *
 * @param array $gateways Array of enabled gateway types.
 * @return string HTML form output.
 */
function npmp_render_multi_gateway_donation_form( $gateways ) {
	if ( empty( $gateways ) ) {
		return '<div class="npmp-donation-form npmp-donation-form--inactive"><p>' . esc_html__( 'No payment gateways configured.', 'nonprofit-manager' ) . '</p></div>';
	}

	$opts = npmp_get_donation_form_options();

	ob_start();
	?>
	<div class="npmp-donation-form npmp-multi-gateway" style="max-width:600px;">
		<h3><?php echo esc_html( $opts['title'] ); ?></h3>
		<p><?php echo wp_kses_post( $opts['intro'] ); ?></p>

		<form id="npmp-multi-gateway-form">
			<p><label><?php echo esc_html( $opts['amount_lbl'] ); ?><br>
			<input type="number" step="0.01" min="1" name="amount" id="npmp-donation-amount" required style="width:100%;"></label></p>

			<p><label><?php echo esc_html( $opts['email_lbl'] ); ?><br>
			<input type="email" name="email" id="npmp-donation-email" required style="width:100%;"></label></p>

			<p><strong><?php esc_html_e( 'Choose Payment Method:', 'nonprofit-manager' ); ?></strong></p>

			<div class="npmp-payment-buttons" style="margin-top:20px;">
				<?php foreach ( $gateways as $gateway ) : ?>
					<?php
					switch ( $gateway ) {
						case 'paypal_link':
							$paypal_email = get_option( 'npmp_paypal_email', '' );
							if ( $paypal_email ) :
								?>
								<div style="margin-bottom: 10px;">
									<button type="button" class="npmp-paypal-button" data-gateway="paypal_link" style="background-color: #0070BA; color: white; border: none; padding: 12px 24px; font-size: 16px; border-radius: 4px; cursor: pointer; width: 100%; font-weight: bold;"><svg style="height: 20px; vertical-align: middle; margin-right: 8px; fill: white;" viewBox="0 0 24 24"><path d="M20.905 9.5c.21-1.342.09-2.252-.431-3.216C19.657 5.132 18.278 4.5 16.297 4.5H9.5c-.508 0-.944.368-1.025.866l-3.02 19.14c-.06.378.222.72.606.72h4.42l1.11-7.033-.035.222c.081-.498.515-.866 1.022-.866h2.128c4.182 0 7.456-1.699 8.412-6.614.028-.147.052-.29.073-.43.276-1.765.004-2.963-.812-4.005h-.474z"/></svg>Pay with PayPal</button>
								</div>
							<?php endif; ?>
							<?php break; ?>

						<?php case 'venmo_link': ?>
							<?php
							$venmo_handle = get_option( 'npmp_venmo_handle', '' );
							if ( $venmo_handle ) :
								?>
								<div style="margin-bottom: 10px;">
									<button type="button" class="npmp-venmo-button" data-gateway="venmo_link" style="background-color: #008CFF; color: white; border: none; padding: 12px 24px; font-size: 16px; border-radius: 4px; cursor: pointer; width: 100%; font-weight: bold;">
										<svg style="height: 20px; vertical-align: middle; margin-right: 8px; fill: white;" viewBox="0 0 24 24">
											<path d="M19.83 4.18c1.26 2.01 1.84 4.23 1.84 7.07 0 6.74-5.74 14.6-10.38 14.6H0L3.14.55h6.88l-1.95 15.56c1.51-2.5 4.25-7.23 4.25-10.61 0-1.66-.35-2.87-.92-3.83l7.43-1.49z"/>
										</svg>
										Pay with Venmo
									</button>
								</div>
							<?php endif; ?>
							<?php break; ?>

						<?php case 'paypal_api': ?>
							<?php if ( npmp_is_pro() ) : ?>
								<div id="paypal-button-container-multi" style="margin-bottom: 10px;"></div>
							<?php endif; ?>
							<?php break; ?>

						<?php case 'stripe': ?>
							<?php if ( npmp_is_pro() ) : ?>
								<div style="margin-bottom: 10px;">
									<button type="button" id="npmp-stripe-checkout-button" class="npmp-stripe-button" data-gateway="stripe" style="width:100%; padding: 12px; font-size: 16px; background-color: #635BFF; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">
										<svg style="height: 20px; vertical-align: middle; margin-right: 8px; fill: white;" viewBox="0 0 60 25">
											<path d="M59.64 14.28h-8.06c.19 1.93 1.6 2.55 3.2 2.55 1.64 0 2.96-.37 4.05-.95v3.32a8.33 8.33 0 0 1-4.56 1.1c-4.01 0-6.83-2.5-6.83-7.48 0-4.19 2.39-7.52 6.3-7.52 3.92 0 5.96 3.28 5.96 7.5 0 .4-.04 1.26-.06 1.48zm-5.92-5.62c-1.03 0-2.17.73-2.17 2.58h4.25c0-1.85-1.07-2.58-2.08-2.58zM40.95 20.3c-1.44 0-2.32-.6-2.9-1.04l-.02 4.63-4.12.87V5.57h3.76l.08 1.02a4.7 4.7 0 0 1 3.23-1.29c2.9 0 5.62 2.6 5.62 7.4 0 5.23-2.7 7.6-5.65 7.6zM40 8.95c-.95 0-1.54.34-1.97.81l.02 6.12c.4.44.98.78 1.95.78 1.52 0 2.54-1.65 2.54-3.87 0-2.15-1.04-3.84-2.54-3.84zM28.24 5.57h4.13v14.44h-4.13V5.57zm0-4.7L32.37 0v3.36l-4.13.88V.88zm-4.32 9.35v9.79H19.8V5.57h3.7l.12 1.22c1-1.77 3.07-1.41 3.62-1.22v3.79c-.52-.17-2.29-.43-3.32.86zm-8.55 4.72c0 2.43 2.6 1.68 3.12 1.46v3.36c-.55.3-1.54.54-2.89.54a4.15 4.15 0 0 1-4.27-4.24l.01-13.17 4.02-.86v3.54h3.14V9.1h-3.13v5.85zm-4.91.7c0 2.97-2.31 4.66-5.73 4.66a11.2 11.2 0 0 1-4.46-.93v-3.93c1.38.75 3.1 1.31 4.46 1.31.92 0 1.53-.24 1.53-1C6.26 13.77 0 14.51 0 9.95 0 7.04 2.28 5.3 5.62 5.3c1.36 0 2.72.2 4.09.75v3.88a9.23 9.23 0 0 0-4.1-1.06c-.86 0-1.44.25-1.44.9 0 1.85 6.29.97 6.29 5.88z"/>
										</svg>
										Pay with Stripe
									</button>
								</div>
							<?php endif; ?>
							<?php break; ?>
					<?php } ?>
				<?php endforeach; ?>
			</div>
			<div id="npmp-multi-error" role="alert" style="color: #d63638; margin-top: 10px; display: none;"></div>
		</form>

		<script>
		(function() {
			var amountInput = document.getElementById('npmp-donation-amount');
			var emailInput = document.getElementById('npmp-donation-email');
			var multiError = document.getElementById('npmp-multi-error');

			if (!amountInput || !emailInput) {
				console.error('NPMP: Donation form inputs not found');
				return;
			}

			function npmpShowMultiError(message) {
				if (!multiError) {
					return;
				}
				multiError.textContent = '<?php echo esc_js( __( 'Error:', 'nonprofit-manager' ) ); ?> ' + message;
				multiError.style.display = 'block';
			}

			function npmpClearMultiError() {
				if (multiError) {
					multiError.style.display = 'none';
				}
			}

			// PayPal Link handler
			var paypalButtons = document.querySelectorAll('.npmp-paypal-button[data-gateway="paypal_link"]');
			paypalButtons.forEach(function(button) {
				button.addEventListener('click', function(e) {
					e.preventDefault();
					var amount = parseFloat(amountInput.value);
					var email = emailInput.value;

					if (!amount || amount < 1) {
						npmpShowMultiError('<?php echo esc_js( __( 'Please enter a valid donation amount (minimum $1).', 'nonprofit-manager' ) ); ?>');
						return;
					}

					if (!email) {
						npmpShowMultiError('<?php echo esc_js( __( 'Please enter your email address.', 'nonprofit-manager' ) ); ?>');
						return;
					}

					// Open PayPal with donation amount
					var paypalEmail = '<?php echo esc_js( get_option( 'npmp_paypal_email', '' ) ); ?>';
					if (!paypalEmail) {
						npmpShowMultiError('<?php echo esc_js( __( 'PayPal email is not configured. Please contact the site administrator.', 'nonprofit-manager' ) ); ?>');
						return;
					}
					var paypalUrl = 'https://www.paypal.com/donate/?business=' + encodeURIComponent(paypalEmail) + '&amount=' + amount + '&currency_code=USD&item_name=' + encodeURIComponent('Donation');
					window.open(paypalUrl, '_blank');
				});
			});

			// Venmo handler
			var venmoButtons = document.querySelectorAll('.npmp-venmo-button');
			venmoButtons.forEach(function(button) {
				button.addEventListener('click', function(e) {
					e.preventDefault();
					var amount = parseFloat(amountInput.value);
					var email = emailInput.value;

					if (!amount || amount < 1) {
						npmpShowMultiError('<?php echo esc_js( __( 'Please enter a valid donation amount (minimum $1).', 'nonprofit-manager' ) ); ?>');
						return;
					}

					if (!email) {
						npmpShowMultiError('<?php echo esc_js( __( 'Please enter your email address.', 'nonprofit-manager' ) ); ?>');
						return;
					}

					var venmoHandle = '<?php echo esc_js( get_option( 'npmp_venmo_handle', '' ) ); ?>';
					if (!venmoHandle) {
						npmpShowMultiError('<?php echo esc_js( __( 'Venmo handle is not configured. Please contact the site administrator.', 'nonprofit-manager' ) ); ?>');
						return;
					}
					var note = 'Donation';
					// Clean up handle - remove @ symbol if present
					var cleanHandle = venmoHandle.replace('@', '');
					// Use venmo:// deep link for mobile, fallback to web for desktop
					var venmoUrl = 'venmo://paycharge?txn=pay&recipients=' + encodeURIComponent(cleanHandle) + '&amount=' + amount + '&note=' + encodeURIComponent(note);

					// Try to open Venmo app, fallback to profile page
					var venmoWindow = window.open(venmoUrl, '_blank');

					// After a short delay, if still here, redirect to Venmo profile page
					setTimeout(function() {
						if (!venmoWindow || venmoWindow.closed || typeof venmoWindow.closed === 'undefined') {
							window.open('https://venmo.com/' + encodeURIComponent(cleanHandle), '_blank');
						}
					}, 1000);
				});
			});

			<?php if ( in_array( 'stripe', $gateways, true ) && npmp_is_pro() ) : ?>
				// Stripe handler
				var stripeButton = document.getElementById('npmp-stripe-checkout-button');
				if (stripeButton) {
					stripeButton.addEventListener('click', function() {
						var amount = parseFloat(amountInput.value);
						var email = emailInput.value;

						npmpClearMultiError();

						if (!amount || amount < 1) {
							npmpShowMultiError('<?php echo esc_js( __( 'Please enter a valid donation amount (minimum $1).', 'nonprofit-manager' ) ); ?>');
							return;
						}

						if (!email) {
							npmpShowMultiError('<?php echo esc_js( __( 'Please enter your email address.', 'nonprofit-manager' ) ); ?>');
							return;
						}

						// Prevent a double click from creating two checkout sessions.
						stripeButton.disabled = true;

						// Call Stripe checkout
						var formData = new FormData();
						formData.append('action', 'npmp_create_stripe_session');
						formData.append('nonce', '<?php echo esc_js( wp_create_nonce( 'npmp_stripe_checkout' ) ); ?>');
						formData.append('amount', amount);
						formData.append('email', email);
						formData.append('page_url', window.location.href);

						fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
							method: 'POST',
							body: formData
						})
						.then(function(response) { return response.json(); })
						.then(function(data) {
							if (data.success && data.data.url) {
								window.location.href = data.data.url;
							} else {
								stripeButton.disabled = false;
								npmpShowMultiError(typeof data.data === 'string' ? data.data : '<?php echo esc_js( __( 'An error occurred. Please try again.', 'nonprofit-manager' ) ); ?>');
							}
						})
						.catch(function(error) {
							stripeButton.disabled = false;
							npmpShowMultiError('<?php echo esc_js( __( 'An error occurred. Please try again.', 'nonprofit-manager' ) ); ?>');
						});
					});
				}
			<?php endif; ?>

			<?php if ( in_array( 'paypal_api', $gateways, true ) && npmp_is_pro() ) : ?>
				// PayPal API handler
				<?php
				$mode      = get_option( 'npmp_paypal_mode', 'live' );
				$client_id = 'sandbox' === $mode ? get_option( 'npmp_paypal_sandbox_client_id', '' ) : get_option( 'npmp_paypal_live_client_id', '' );
				if ( $client_id ) :
					$sdk_url = 'https://www.paypal.com/sdk/js?client-id=' . rawurlencode( $client_id ) . '&currency=USD';
					if ( 'sandbox' === $mode ) {
						$sdk_url .= '&debug=true';
					}
					wp_enqueue_script( 'npmp-paypal-sdk-multi', $sdk_url, array(), '1.0.0', true );
					?>

					var npmpInitMultiPayPal = function() {
						if (typeof paypal === 'undefined') {
							npmpShowMultiError('<?php echo esc_js( __( 'PayPal could not be loaded. Please refresh the page.', 'nonprofit-manager' ) ); ?>');
							return;
						}
						paypal.Buttons({
							createOrder: function(data, actions) {
								var amount = parseFloat(amountInput.value);
								var email = emailInput.value;

								if (!amount || amount < 1) {
									npmpShowMultiError('<?php echo esc_js( __( 'Please enter a valid donation amount (minimum $1).', 'nonprofit-manager' ) ); ?>');
									return false;
								}

								if (!email) {
									npmpShowMultiError('<?php echo esc_js( __( 'Please enter your email address.', 'nonprofit-manager' ) ); ?>');
									return false;
								}

								return actions.order.create({
									purchase_units: [{
										amount: { value: amount.toFixed(2) }
									}]
								});
							},
							onApprove: function(data, actions) {
								return actions.order.capture().then(function(details) {
									alert('<?php echo esc_js( __( 'Thank you for your donation!', 'nonprofit-manager' ) ); ?>');
									window.location.reload();
								});
							}
						}).render('#paypal-button-container-multi');
					};
					if ('loading' === document.readyState) {
						// The PayPal SDK loads in the footer, after this inline
						// script runs. Waiting for DOMContentLoaded guarantees the
						// SDK global exists before buttons initialize.
						document.addEventListener('DOMContentLoaded', npmpInitMultiPayPal);
					} else {
						npmpInitMultiPayPal();
					}
				<?php endif; ?>
			<?php endif; ?>
		})();
		</script>
	</div>
	<?php
	return ob_get_clean();
}

/* ==============================================================
 * PayPal Link (Free Tier)
 * ============================================================= */

/**
 * Render PayPal link donation form
 *
 * @return string
 */
function npmp_render_paypal_link_form() {
	$paypal_email = get_option( 'npmp_paypal_email', '' );

	if ( empty( $paypal_email ) ) {
		return '<div class="npmp-donation-form npmp-donation-form--inactive"><p>' . esc_html__( 'PayPal is not configured. Please contact the administrator.', 'nonprofit-manager' ) . '</p></div>';
	}

	$opts = npmp_get_donation_form_options();

	ob_start();
	?>
	<div class="npmp-donation-form" style="max-width:500px;">
		<h3><?php echo esc_html( $opts['title'] ); ?></h3>
		<p><?php echo wp_kses_post( $opts['intro'] ); ?></p>

		<form id="npmp-paypal-link-form" onsubmit="return npmpSubmitPayPalLink(event)">
			<p><label><?php echo esc_html( $opts['amount_lbl'] ); ?><br>
			<input type="number" step="0.01" min="1" name="amount" id="npmp-amount" required style="width:100%;"></label></p>

			<p><label><?php echo esc_html( $opts['email_lbl'] ); ?><br>
			<input type="email" name="email" id="npmp-email" required style="width:100%;"></label></p>

			<?php if ( npmp_has_multiple_frequencies( 'paypal_link' ) ) : ?>
				<p><label><?php esc_html_e( 'Frequency', 'nonprofit-manager' ); ?><br>
				<select name="frequency" id="npmp-frequency" style="width:100%;">
					<?php foreach ( npmp_get_enabled_frequencies( 'paypal_link' ) as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select></label></p>
			<?php endif; ?>

			<input type="hidden" id="npmp-paypal-business" value="<?php echo esc_attr( $paypal_email ); ?>">

			<p style="margin-top:20px;">
				<button type="submit" class="button button-primary" style="width:100%; padding: 10px; font-size: 16px;">
					<img src="https://www.paypalobjects.com/webstatic/icon/pp258.png" alt="PayPal" style="height: 20px; vertical-align: middle; margin-right: 8px;">
					<?php echo esc_html( $opts['btn_lbl'] ); ?>
				</button>
			</p>
		</form>
	</div>

	<script>
	function npmpSubmitPayPalLink(e) {
		e.preventDefault();
		var form = document.getElementById('npmp-paypal-link-form');
		var amount = form.querySelector('#npmp-amount').value;
		var business = form.querySelector('#npmp-paypal-business').value;

		var paypalUrl = 'https://www.paypal.com/donate/?business=' + encodeURIComponent(business) +
			'&amount=' + encodeURIComponent(amount) +
			'&currency_code=USD' +
			'&item_name=' + encodeURIComponent('Donation');

		window.open(paypalUrl, '_blank');
		return false;
	}
	</script>
	<?php
	return ob_get_clean();
}

/* ==============================================================
 * Venmo Link (Free Tier)
 * ============================================================= */

/**
 * Render Venmo link donation form
 *
 * @return string
 */
function npmp_render_venmo_link_form() {
	$venmo_handle = get_option( 'npmp_venmo_handle', '' );

	if ( empty( $venmo_handle ) ) {
		return '<div class="npmp-donation-form npmp-donation-form--inactive"><p>' . esc_html__( 'Venmo is not configured. Please contact the administrator.', 'nonprofit-manager' ) . '</p></div>';
	}

	$opts = npmp_get_donation_form_options();

	ob_start();
	?>
	<div class="npmp-donation-form" style="max-width:500px;">
		<h3><?php echo esc_html( $opts['title'] ); ?></h3>
		<p><?php echo wp_kses_post( $opts['intro'] ); ?></p>

		<form id="npmp-venmo-link-form" onsubmit="return npmpSubmitVenmoLink(event)">
			<p><label><?php echo esc_html( $opts['amount_lbl'] ); ?><br>
			<input type="number" step="0.01" min="1" name="amount" id="npmp-venmo-amount" required style="width:100%;"></label></p>

			<p><label><?php echo esc_html( $opts['email_lbl'] ); ?><br>
			<input type="email" name="email" id="npmp-venmo-email" required style="width:100%;"></label></p>

			<input type="hidden" id="npmp-venmo-handle" value="<?php echo esc_attr( $venmo_handle ); ?>">

			<p style="margin-top:20px;">
				<button type="submit" class="button button-primary" style="width:100%; padding: 10px; font-size: 16px; background-color: #3D95CE;">
					<svg style="height: 20px; vertical-align: middle; margin-right: 8px; fill: white;" viewBox="0 0 24 24">
						<path d="M19.36 2.72c.94 1.37 1.41 3.12 1.41 5.24 0 6.24-4.34 12.77-7.94 18.04H5.14l-3.86-20.8 6.93-.66 1.72 11.91c1.61-2.71 3.62-7.02 3.62-10.31 0-1.18-.15-2.09-.43-2.88l5.24-.54z"/>
					</svg>
					<?php echo esc_html( $opts['btn_lbl'] ); ?>
				</button>
			</p>
		</form>
	</div>

	<script>
	function npmpSubmitVenmoLink(e) {
		e.preventDefault();
		var form = document.getElementById('npmp-venmo-link-form');
		var amount = form.querySelector('#npmp-venmo-amount').value;
		var handle = form.querySelector('#npmp-venmo-handle').value;
		var note = 'Donation';

		// Clean up handle - remove @ symbol if present
		var cleanHandle = handle.replace('@', '');

		// Use venmo:// deep link for mobile devices
		var venmoUrl = 'venmo://paycharge?txn=pay&recipients=' + encodeURIComponent(cleanHandle) + '&amount=' + amount + '&note=' + encodeURIComponent(note);

		// Try to open Venmo app
		var venmoWindow = window.open(venmoUrl, '_blank');

		// Fallback: After a short delay, if we're still on the page, redirect to Venmo profile
		setTimeout(function() {
			if (!venmoWindow || venmoWindow.closed || typeof venmoWindow.closed === 'undefined') {
				window.open('https://venmo.com/' + encodeURIComponent(cleanHandle), '_blank');
			}
		}, 1000);

		return false;
	}
	</script>
	<?php
	return ob_get_clean();
}

/* ==============================================================
 * PayPal API (Pro Tier)
 * ============================================================= */

/**
 * Render PayPal API donation form with Smart Buttons
 *
 * @return string
 */
function npmp_render_paypal_api_form() {
	$mode      = get_option( 'npmp_paypal_mode', 'live' );
	$client_id = 'sandbox' === $mode ? get_option( 'npmp_paypal_sandbox_client_id', '' ) : get_option( 'npmp_paypal_live_client_id', '' );

	if ( empty( $client_id ) ) {
		return '<div class="npmp-donation-form npmp-donation-form--inactive"><p>' . esc_html__( 'PayPal API is not configured. Please contact the administrator.', 'nonprofit-manager' ) . '</p></div>';
	}

	$opts = npmp_get_donation_form_options();

	// Enqueue PayPal SDK
	$sandbox_param = 'sandbox' === $mode ? '&buyer-country=US' : '';
	wp_enqueue_script(
		'paypal-sdk',
		'https://www.paypal.com/sdk/js?client-id=' . $client_id . '&currency=USD' . $sandbox_param,
		array(),
		null,
		true
	);

	ob_start();
	?>
	<div class="npmp-donation-form" style="max-width:500px;">
		<h3><?php echo esc_html( $opts['title'] ); ?></h3>
		<p><?php echo wp_kses_post( $opts['intro'] ); ?></p>

		<form id="npmp-paypal-api-form">
			<p><label><?php echo esc_html( $opts['amount_lbl'] ); ?><br>
			<input type="number" step="0.01" min="1" name="amount" id="npmp-paypal-api-amount" required style="width:100%;"></label></p>

			<p><label><?php echo esc_html( $opts['email_lbl'] ); ?><br>
			<input type="email" name="email" id="npmp-paypal-api-email" required style="width:100%;"></label></p>

			<?php if ( npmp_has_multiple_frequencies( 'paypal_api' ) ) : ?>
				<p><label><?php esc_html_e( 'Frequency', 'nonprofit-manager' ); ?><br>
				<select name="frequency" id="npmp-paypal-api-frequency" style="width:100%;">
					<?php foreach ( npmp_get_enabled_frequencies( 'paypal_api' ) as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select></label></p>
			<?php endif; ?>

			<div id="paypal-button-container" style="margin-top:20px;"></div>
			<div id="npmp-paypal-error" style="color: red; margin-top: 10px; display: none;"></div>
		</form>
	</div>

	<script>
	// The PayPal SDK is enqueued in the footer, so it does not exist yet when
	// this inline script is parsed. Initializing at DOMContentLoaded (after
	// footer scripts execute) is what makes the buttons actually render, the
	// previous parse-time paypal.Buttons() call threw and rendered nothing.
	function npmpInitPayPalApiForm() {
		var errorEl = document.getElementById('npmp-paypal-error');

		function showPayPalError(message) {
			errorEl.textContent = '<?php echo esc_js( __( 'Error:', 'nonprofit-manager' ) ); ?> ' + message;
			errorEl.style.display = 'block';
		}

		if (typeof paypal === 'undefined') {
			showPayPalError('<?php echo esc_js( __( 'PayPal could not be loaded. Please refresh the page.', 'nonprofit-manager' ) ); ?>');
			return;
		}

	paypal.Buttons({
		createOrder: function(data, actions) {
			var amount = document.getElementById('npmp-paypal-api-amount').value;
			var email = document.getElementById('npmp-paypal-api-email').value;

			if (!amount || amount < 1) {
				showPayPalError('<?php echo esc_js( __( 'Please enter a valid donation amount.', 'nonprofit-manager' ) ); ?>');
				return false;
			}

			if (!email) {
				showPayPalError('<?php echo esc_js( __( 'Please enter your email address.', 'nonprofit-manager' ) ); ?>');
				return false;
			}

			errorEl.style.display = 'none';

			return actions.order.create({
				intent: 'CAPTURE',
				purchase_units: [{
					amount: {
						value: amount,
						currency_code: 'USD'
					},
					description: 'Donation'
				}]
			});
		},
		onApprove: function(data, actions) {
			return actions.order.capture().then(function(details) {
				// Log donation via AJAX
				var formData = new FormData();
				formData.append('action', 'npmp_log_donation');
				formData.append('nonce', '<?php echo esc_js( wp_create_nonce( 'npmp_donation' ) ); ?>');
				formData.append('email', document.getElementById('npmp-paypal-api-email').value);
				formData.append('amount', document.getElementById('npmp-paypal-api-amount').value);
				formData.append('frequency', document.getElementById('npmp-paypal-api-frequency') ? document.getElementById('npmp-paypal-api-frequency').value : 'one_time');
				formData.append('gateway', 'paypal_api');
				formData.append('transaction_id', details.id);

				fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
					method: 'POST',
					body: formData
				}).then(function() {
					alert('<?php echo esc_js( __( 'Thank you for your donation!', 'nonprofit-manager' ) ); ?>');
					window.location.reload();
				});
			});
		},
		onError: function(err) {
			console.error('PayPal Error:', err);
			var errorMessage = '<?php echo esc_js( __( 'An error occurred. Please try again.', 'nonprofit-manager' ) ); ?>';
			if (err && err.message) {
				errorMessage += ' (' + err.message + ')';
			}
			showPayPalError(errorMessage);
		}
	}).render('#paypal-button-container');
	}
	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', npmpInitPayPalApiForm);
	} else {
		npmpInitPayPalApiForm();
	}
	</script>
	<?php
	return ob_get_clean();
}

/* ==============================================================
 * Stripe (Pro Tier)
 * ============================================================= */

/**
 * Render Stripe donation form with Checkout
 *
 * @return string
 */
function npmp_render_stripe_form() {
	$mode            = get_option( 'npmp_stripe_mode', 'live' );
	$publishable_key = 'test' === $mode ? get_option( 'npmp_stripe_test_publishable_key', '' ) : get_option( 'npmp_stripe_live_publishable_key', '' );

	if ( empty( $publishable_key ) ) {
		return '<div class="npmp-donation-form npmp-donation-form--inactive"><p>' . esc_html__( 'Stripe is not configured. Please contact the administrator.', 'nonprofit-manager' ) . '</p></div>';
	}

	$opts = npmp_get_donation_form_options();

	// No Stripe.js needed: the server creates the Checkout Session and the
	// browser follows the session's own URL. redirectToCheckout() is
	// deprecated and required loading Stripe's SDK on the page for nothing.

	ob_start();
	?>
	<div class="npmp-donation-form" style="max-width:500px;">
		<h3><?php echo esc_html( $opts['title'] ); ?></h3>
		<p><?php echo wp_kses_post( $opts['intro'] ); ?></p>

		<form id="npmp-stripe-form">
			<p><label><?php echo esc_html( $opts['amount_lbl'] ); ?><br>
			<input type="number" step="0.01" min="1" name="amount" id="npmp-stripe-amount" required style="width:100%;"></label></p>

			<p><label><?php echo esc_html( $opts['email_lbl'] ); ?><br>
			<input type="email" name="email" id="npmp-stripe-email" required style="width:100%;"></label></p>

			<?php if ( npmp_has_multiple_frequencies( 'stripe' ) ) : ?>
				<p><label><?php esc_html_e( 'Frequency', 'nonprofit-manager' ); ?><br>
				<select name="frequency" id="npmp-stripe-frequency" style="width:100%;">
					<?php foreach ( npmp_get_enabled_frequencies( 'stripe' ) as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select></label></p>
			<?php endif; ?>

			<p style="margin-top:20px;">
				<button type="submit" class="button button-primary" style="width:100%; padding: 10px; font-size: 16px; background-color: #635BFF;">
					<svg style="height: 20px; vertical-align: middle; margin-right: 8px; fill: white;" viewBox="0 0 60 25">
						<path d="M59.64 14.28h-8.06c.19 1.93 1.6 2.55 3.2 2.55 1.64 0 2.96-.37 4.05-.95v3.32a8.33 8.33 0 0 1-4.56 1.1c-4.01 0-6.83-2.5-6.83-7.48 0-4.19 2.39-7.52 6.3-7.52 3.92 0 5.96 3.28 5.96 7.5 0 .4-.04 1.26-.06 1.48zm-5.92-5.62c-1.03 0-2.17.73-2.17 2.58h4.25c0-1.85-1.07-2.58-2.08-2.58zM40.95 20.3c-1.44 0-2.32-.6-2.9-1.04l-.02 4.63-4.12.87V5.57h3.76l.08 1.02a4.7 4.7 0 0 1 3.23-1.29c2.9 0 5.62 2.6 5.62 7.4 0 5.23-2.7 7.6-5.65 7.6zM40 8.95c-.95 0-1.54.34-1.97.81l.02 6.12c.4.44.98.78 1.95.78 1.52 0 2.54-1.65 2.54-3.87 0-2.15-1.04-3.84-2.54-3.84zM28.24 5.57h4.13v14.44h-4.13V5.57zm0-4.7L32.37 0v3.36l-4.13.88V.88zm-4.32 9.35v9.79H19.8V5.57h3.7l.12 1.22c1-1.77 3.07-1.41 3.62-1.22v3.79c-.52-.17-2.29-.43-3.32.86zm-8.55 4.72c0 2.43 2.6 1.68 3.12 1.46v3.36c-.55.3-1.54.54-2.89.54a4.15 4.15 0 0 1-4.27-4.24l.01-13.17 4.02-.86v3.54h3.14V9.1h-3.13v5.85zm-4.91.7c0 2.97-2.31 4.66-5.73 4.66a11.2 11.2 0 0 1-4.46-.93v-3.93c1.38.75 3.1 1.31 4.46 1.31.92 0 1.53-.24 1.53-1C6.26 13.77 0 14.51 0 9.95 0 7.04 2.28 5.3 5.62 5.3c1.36 0 2.72.2 4.09.75v3.88a9.23 9.23 0 0 0-4.1-1.06c-.86 0-1.44.25-1.44.9 0 1.85 6.29.97 6.29 5.88z"/>
					</svg>
					<?php echo esc_html( $opts['btn_lbl'] ); ?>
				</button>
			</p>

			<div id="npmp-stripe-error" role="alert" style="color: #d63638; margin-top: 10px; display: none;"></div>
		</form>
	</div>

	<script>
	document.getElementById('npmp-stripe-form').addEventListener('submit', function(e) {
		e.preventDefault();

		var form = e.target;
		var submitButton = form.querySelector('button[type="submit"]');
		var errorEl = document.getElementById('npmp-stripe-error');
		var amount = document.getElementById('npmp-stripe-amount').value;
		var email = document.getElementById('npmp-stripe-email').value;
		var frequency = document.getElementById('npmp-stripe-frequency') ? document.getElementById('npmp-stripe-frequency').value : 'one_time';

		function showError(message) {
			errorEl.textContent = '<?php echo esc_js( __( 'Error:', 'nonprofit-manager' ) ); ?> ' + message;
			errorEl.style.display = 'block';
			if (submitButton) {
				submitButton.disabled = false;
			}
		}

		if (!amount || amount < 1) {
			showError('<?php echo esc_js( __( 'Please enter a valid donation amount.', 'nonprofit-manager' ) ); ?>');
			return;
		}

		if (!email) {
			showError('<?php echo esc_js( __( 'Please enter your email address.', 'nonprofit-manager' ) ); ?>');
			return;
		}

		errorEl.style.display = 'none';
		if (submitButton) {
			// Prevent a double click from creating two checkout sessions.
			submitButton.disabled = true;
		}

		// Create checkout session via AJAX
		var formData = new FormData();
		formData.append('action', 'npmp_create_stripe_session');
		formData.append('nonce', '<?php echo esc_js( wp_create_nonce( 'npmp_stripe_checkout' ) ); ?>');
		formData.append('amount', amount);
		formData.append('email', email);
		formData.append('frequency', frequency);
		formData.append('page_url', window.location.href);

		fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
			method: 'POST',
			body: formData
		})
		.then(function(response) { return response.json(); })
		.then(function(session) {
			if (session.success && session.data.url) {
				window.location.href = session.data.url;
			} else {
				throw new Error(typeof session.data === 'string' ? session.data : '<?php echo esc_js( __( 'Failed to create checkout session.', 'nonprofit-manager' ) ); ?>');
			}
		})
		.catch(function(error) {
			showError(error && error.message ? error.message : '<?php echo esc_js( __( 'An error occurred. Please try again.', 'nonprofit-manager' ) ); ?>');
		});
	});
	</script>
	<?php
	return ob_get_clean();
}

/* ==============================================================
 * AJAX Handlers
 * ============================================================= */

/**
 * Log donation via AJAX (for PayPal API)
 */
add_action( 'wp_ajax_npmp_log_donation', 'npmp_ajax_log_donation' );
add_action( 'wp_ajax_nopriv_npmp_log_donation', 'npmp_ajax_log_donation' );

function npmp_ajax_log_donation() {
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'npmp_donation' ) ) {
		npmp_payment_debug_log( 'donation log rejected: invalid nonce' );
		wp_send_json_error( array( 'message' => __( 'Invalid security token. Please refresh and try again.', 'nonprofit-manager' ) ) );
	}

	$email          = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
	$name           = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
	$amount         = floatval( wp_unslash( $_POST['amount'] ?? 0 ) );
	$frequency      = sanitize_text_field( wp_unslash( $_POST['frequency'] ?? 'one_time' ) );
	$gateway        = sanitize_text_field( wp_unslash( $_POST['gateway'] ?? 'paypal_api' ) );
	$transaction_id = sanitize_text_field( wp_unslash( $_POST['transaction_id'] ?? '' ) );

	if ( ! $email || ! is_email( $email ) ) {
		npmp_payment_debug_log( 'donation log rejected: invalid email' );
		wp_send_json_error( array( 'message' => __( 'Please provide a valid email address.', 'nonprofit-manager' ) ) );
	}

	if ( $amount <= 0 ) {
		npmp_payment_debug_log( 'donation log rejected: invalid amount' );
		wp_send_json_error( array( 'message' => __( 'Please provide a valid donation amount.', 'nonprofit-manager' ) ) );
	}

	// This endpoint is reachable by logged-out visitors holding only the
	// public page nonce, so a client-reported PayPal capture is verified
	// against PayPal's own API before anything is recorded or emailed.
	if ( 'paypal_api' === $gateway ) {
		$verified = npmp_paypal_verify_order( $transaction_id, $amount );
		if ( is_wp_error( $verified ) ) {
			npmp_payment_debug_log( 'donation log rejected: ' . $verified->get_error_code() );
			wp_send_json_error( array( 'message' => __( 'We could not verify this payment with PayPal. If you completed the donation, please contact us.', 'nonprofit-manager' ) ) );
		}
	}

	try {
		// Log donation
		$donation_id = NPMP_Donation_Manager::get_instance()->log_donation(
			array(
				'email'          => $email,
				'name'           => $name,
				'amount'         => $amount,
				'frequency'      => $frequency,
				'gateway'        => $gateway,
				'transaction_id' => $transaction_id,
			)
		);

		if ( is_wp_error( $donation_id ) || false === $donation_id ) {
			npmp_payment_debug_log( 'donation insert failed' );
			wp_send_json_error( array( 'message' => __( 'Failed to record donation. Please contact support.', 'nonprofit-manager' ) ) );
		}

		// Send thank you email (Pro only)
		$email_sent = npmp_send_thank_you_email(
			array(
				'email'     => $email,
				'name'      => $name,
				'amount'    => $amount,
				'frequency' => $frequency,
				'date'      => date_i18n( get_option( 'date_format' ) ),
			)
		);

		if ( ! $email_sent && get_option( 'npmp_enable_thank_you_email', 1 ) ) {
			npmp_payment_debug_log( 'thank-you email failed for donation ' . (int) $donation_id );
		}

		// Add donor to membership if not already a member
		npmp_add_donor_to_membership( $email, $name );

		wp_send_json_success( array( 'donation_id' => $donation_id ) );
	} catch ( Exception $e ) {
		npmp_payment_debug_log( 'donation processing exception: ' . $e->getMessage() );
		wp_send_json_error( array( 'message' => __( 'An unexpected error occurred. Please try again.', 'nonprofit-manager' ) ) );
	}
}

/**
 * Create Stripe checkout session
 */
add_action( 'wp_ajax_npmp_create_stripe_session', 'npmp_ajax_create_stripe_session' );
add_action( 'wp_ajax_nopriv_npmp_create_stripe_session', 'npmp_ajax_create_stripe_session' );

function npmp_ajax_create_stripe_session() {
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'npmp_stripe_checkout' ) ) {
		npmp_payment_debug_log( 'stripe session rejected: invalid nonce' );
		wp_send_json_error( __( 'Invalid security token. Please refresh and try again.', 'nonprofit-manager' ) );
	}

	$amount    = floatval( wp_unslash( $_POST['amount'] ?? 0 ) );
	$email     = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
	$frequency = sanitize_text_field( wp_unslash( $_POST['frequency'] ?? 'one_time' ) );
	$page_url  = isset( $_POST['page_url'] ) ? wp_unslash( $_POST['page_url'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in npmp_payment_return_base().

	// Recurring donations require Pro.
	if ( 'one_time' !== $frequency && ! npmp_is_pro() ) {
		wp_send_json_error( __( 'Recurring donations require Nonprofit Manager Pro.', 'nonprofit-manager' ) );
	}

	if ( ! $email || ! is_email( $email ) ) {
		npmp_payment_debug_log( 'stripe session rejected: invalid email' );
		wp_send_json_error( __( 'Please provide a valid email address.', 'nonprofit-manager' ) );
	}

	if ( $amount < 1 ) {
		npmp_payment_debug_log( 'stripe session rejected: invalid amount' );
		wp_send_json_error( __( 'Please provide a valid donation amount (minimum $1).', 'nonprofit-manager' ) );
	}

	$secret_key = npmp_stripe_secret_key();

	if ( empty( $secret_key ) ) {
		npmp_payment_debug_log( 'stripe session rejected: no API key configured' );
		wp_send_json_error( __( 'Stripe is not configured. Please contact the site administrator.', 'nonprofit-manager' ) );
	}

	// Send the donor back to the page they donated from, with a status flag
	// the form renderer turns into a visible confirmation banner. The
	// {CHECKOUT_SESSION_ID} placeholder must reach Stripe unencoded, so the
	// success URL is assembled by hand instead of add_query_arg().
	$return_base = npmp_payment_return_base( $page_url );
	$joiner      = ( false === strpos( $return_base, '?' ) ) ? '?' : '&';
	$success_url = $return_base . $joiner . 'npmp_donation=success&npmp_session_id={CHECKOUT_SESSION_ID}';
	$cancel_url  = add_query_arg( 'npmp_donation', 'cancelled', $return_base );

	$endpoint     = 'https://api.stripe.com/v1/checkout/sessions';
	$amount_cents = intval( $amount * 100 ); // Convert to cents

	$body = array(
		'payment_method_types[]' => 'card',
		'customer_email'         => $email,
		'line_items[0][price_data][currency]' => 'usd',
		'line_items[0][price_data][unit_amount]' => $amount_cents,
		'line_items[0][quantity]' => 1,
		'success_url'            => $success_url,
		'cancel_url'             => $cancel_url,
		'metadata[frequency]'    => $frequency,
		'metadata[gateway]'      => 'stripe',
	);

	// A donor who picks a recurring frequency gets a real Stripe subscription.
	// Before this, the session was always a one-time payment and the chosen
	// frequency only ever reached Stripe as metadata, so "monthly" donors were
	// silently charged once.
	$intervals = array(
		'weekly'    => array( 'week', 1 ),
		'monthly'   => array( 'month', 1 ),
		'quarterly' => array( 'month', 3 ),
		'annual'    => array( 'year', 1 ),
	);

	if ( isset( $intervals[ $frequency ] ) ) {
		$body['mode'] = 'subscription';
		$body['line_items[0][price_data][recurring][interval]']       = $intervals[ $frequency ][0];
		$body['line_items[0][price_data][recurring][interval_count]'] = $intervals[ $frequency ][1];
		$body['line_items[0][price_data][product_data][name]']        = __( 'Recurring donation', 'nonprofit-manager' );
	} else {
		$body['mode'] = 'payment';
		$body['line_items[0][price_data][product_data][name]'] = __( 'Donation', 'nonprofit-manager' );
	}

	$response = wp_remote_post(
		$endpoint,
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $secret_key,
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
			'body'    => http_build_query( $body ),
			'timeout' => 15,
		)
	);

	if ( is_wp_error( $response ) ) {
		npmp_payment_debug_log( 'stripe session connection error: ' . $response->get_error_code() );
		wp_send_json_error( __( 'Could not reach the payment provider. Please try again.', 'nonprofit-manager' ) );
	}

	$response_code = wp_remote_retrieve_response_code( $response );
	$body          = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( 200 !== $response_code && 201 !== $response_code ) {
		$error_message = $body['error']['message'] ?? __( 'Unknown error', 'nonprofit-manager' );
		npmp_payment_debug_log( 'stripe API error HTTP ' . (int) $response_code );
		/* translators: %s: error message from Stripe. */
		wp_send_json_error( sprintf( __( 'Stripe error: %s', 'nonprofit-manager' ), $error_message ) );
	}

	if ( isset( $body['id'] ) ) {
		// Nothing is recorded yet. The donation is logged only after Stripe
		// confirms payment, when the donor returns to the success URL (see
		// npmp_maybe_finalize_stripe_donation). Logging here booked every
		// abandoned checkout as a completed gift.
		wp_send_json_success( $body );
	} else {
		npmp_payment_debug_log( 'stripe session creation failed: malformed response' );
		wp_send_json_error( $body['error']['message'] ?? __( 'Failed to create checkout session.', 'nonprofit-manager' ) );
	}
}

/**
 * Finalize a Stripe donation when the donor lands back on the site.
 *
 * The success URL carries the Checkout Session id. We fetch that session
 * from Stripe's API and only record the donation when Stripe itself says it
 * was paid, which keeps abandoned and cancelled checkouts out of the
 * donation reports. Subscription-mode sessions are not logged here: the Pro
 * webhook records each invoice payment, and double-logging the first cycle
 * would inflate totals.
 *
 * @return void
 */
function npmp_maybe_finalize_stripe_donation() {
	if ( is_admin() || empty( $_GET['npmp_donation'] ) || 'success' !== $_GET['npmp_donation'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status flag; the session id is verified against Stripe's API below.
		return;
	}

	$session_id = isset( $_GET['npmp_session_id'] ) ? sanitize_text_field( wp_unslash( $_GET['npmp_session_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! preg_match( '/^cs_[A-Za-z0-9_]+$/', $session_id ) ) {
		return;
	}

	// Cheap re-entry guard for refreshes. The durable dedupe is the
	// transaction-id check inside log_donation().
	$lock_key = 'npmp_stripe_fin_' . md5( $session_id );
	if ( get_transient( $lock_key ) ) {
		return;
	}
	set_transient( $lock_key, 1, 15 * MINUTE_IN_SECONDS );

	$secret_key = npmp_stripe_secret_key();
	if ( empty( $secret_key ) ) {
		return;
	}

	$response = wp_remote_get(
		'https://api.stripe.com/v1/checkout/sessions/' . rawurlencode( $session_id ),
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $secret_key,
				'Accept'        => 'application/json',
			),
			'timeout' => 15,
		)
	);

	if ( is_wp_error( $response ) ) {
		delete_transient( $lock_key ); // Let a refresh retry after a transient network failure.
		return;
	}

	$session = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $session ) || empty( $session['payment_status'] ) || 'paid' !== $session['payment_status'] ) {
		return;
	}

	$email = '';
	if ( ! empty( $session['customer_details']['email'] ) ) {
		$email = sanitize_email( $session['customer_details']['email'] );
	} elseif ( ! empty( $session['customer_email'] ) ) {
		$email = sanitize_email( $session['customer_email'] );
	}

	if ( ! $email || ! is_email( $email ) ) {
		return;
	}

	$amount    = isset( $session['amount_total'] ) && is_numeric( $session['amount_total'] ) ? floatval( $session['amount_total'] ) / 100 : 0;
	$frequency = isset( $session['metadata']['frequency'] ) ? sanitize_text_field( $session['metadata']['frequency'] ) : 'one_time';
	$name      = isset( $session['customer_details']['name'] ) ? sanitize_text_field( $session['customer_details']['name'] ) : '';

	if ( 'subscription' !== ( $session['mode'] ?? '' ) && $amount > 0 && class_exists( 'NPMP_Donation_Manager' ) ) {
		NPMP_Donation_Manager::get_instance()->log_donation(
			array(
				'email'          => $email,
				'name'           => $name,
				'amount'         => $amount,
				'frequency'      => $frequency,
				'gateway'        => 'stripe',
				'transaction_id' => $session_id,
			)
		);
	}

	npmp_send_thank_you_email(
		array(
			'email'     => $email,
			'name'      => $name,
			'amount'    => $amount,
			'frequency' => $frequency,
			'date'      => date_i18n( get_option( 'date_format' ) ),
		)
	);

	npmp_add_donor_to_membership( $email, $name );
}
add_action( 'template_redirect', 'npmp_maybe_finalize_stripe_donation' );

/* ==============================================================
 * Helper Functions
 * ============================================================= */

/**
 * Resolve the configured Stripe secret key for the current mode.
 *
 * Single source of truth for the free plugin (the AJAX session creator and
 * the success-return verifier both use it) instead of each call site reading
 * the two options itself.
 *
 * @return string Empty string when unconfigured.
 */
function npmp_stripe_secret_key() {
	$mode = get_option( 'npmp_stripe_mode', 'live' );
	return 'test' === $mode
		? get_option( 'npmp_stripe_test_secret_key', '' )
		: get_option( 'npmp_stripe_live_secret_key', '' );
}

/**
 * Debug-gated logger for the payment paths.
 *
 * These handlers are reachable by logged-out visitors, so unconditional
 * error_log() calls let anyone grow the server log and seed it with chosen
 * strings, and donor emails were being written to plaintext logs. Diagnostics
 * now only run when WP_DEBUG is on, and callers no longer pass PII.
 *
 * @param string $message Log message (no emails, names, or amounts).
 * @return void
 */
function npmp_payment_debug_log( $message ) {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( 'NPMP payments: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}

/**
 * Verify a PayPal order server-side before trusting a client-reported capture.
 *
 * The PayPal Smart Buttons flow captures in the browser and then reports the
 * result to our AJAX logger. Without this check, anyone holding the public
 * page nonce could report fake captures, creating donation records and
 * triggering thank-you emails with no payment. When API credentials are
 * configured we confirm the order is COMPLETED and covers the claimed amount.
 *
 * @param string $order_id PayPal order id from the client.
 * @param float  $amount   Claimed donation amount.
 * @return true|WP_Error True when verified. WP_Error when PayPal refuses or the order doesn't match.
 */
function npmp_paypal_verify_order( $order_id, $amount ) {
	$mode      = get_option( 'npmp_paypal_mode', 'live' );
	$client_id = 'sandbox' === $mode ? get_option( 'npmp_paypal_sandbox_client_id', '' ) : get_option( 'npmp_paypal_live_client_id', '' );
	$secret    = 'sandbox' === $mode ? get_option( 'npmp_paypal_sandbox_secret', '' ) : get_option( 'npmp_paypal_live_secret', '' );

	if ( ! $client_id || ! $secret ) {
		// No API secret on file: verification is impossible, keep legacy
		// behavior rather than breaking existing installs. The settings page
		// encourages adding the secret.
		return true;
	}

	$order_id = preg_replace( '/[^A-Za-z0-9\-_]/', '', (string) $order_id );
	if ( '' === $order_id ) {
		return new WP_Error( 'npmp_paypal_no_order', __( 'Missing PayPal order reference.', 'nonprofit-manager' ) );
	}

	$base = 'sandbox' === $mode ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';

	$token_response = wp_remote_post(
		$base . '/v1/oauth2/token',
		array(
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $secret ),
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
			'body'    => 'grant_type=client_credentials',
			'timeout' => 15,
		)
	);

	if ( is_wp_error( $token_response ) ) {
		return $token_response;
	}

	$token_body = json_decode( wp_remote_retrieve_body( $token_response ), true );
	if ( empty( $token_body['access_token'] ) ) {
		return new WP_Error( 'npmp_paypal_auth', __( 'Could not authenticate with PayPal to verify the donation.', 'nonprofit-manager' ) );
	}

	$order_response = wp_remote_get(
		$base . '/v2/checkout/orders/' . rawurlencode( $order_id ),
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token_body['access_token'],
				'Accept'        => 'application/json',
			),
			'timeout' => 15,
		)
	);

	if ( is_wp_error( $order_response ) ) {
		return $order_response;
	}

	$order = json_decode( wp_remote_retrieve_body( $order_response ), true );

	if ( empty( $order['status'] ) || 'COMPLETED' !== $order['status'] ) {
		return new WP_Error( 'npmp_paypal_not_completed', __( 'PayPal reports this donation as not completed.', 'nonprofit-manager' ) );
	}

	$paid = isset( $order['purchase_units'][0]['amount']['value'] ) ? (float) $order['purchase_units'][0]['amount']['value'] : 0;
	if ( $paid + 0.001 < (float) $amount ) {
		return new WP_Error( 'npmp_paypal_amount_mismatch', __( 'The PayPal payment does not match the reported amount.', 'nonprofit-manager' ) );
	}

	return true;
}

/**
 * Resolve the page a donation form was submitted from, so Stripe can send
 * the donor back there instead of the homepage. Only same-host URLs are
 * accepted. Never trusts an off-site value.
 *
 * @param string $raw_url Client-supplied page URL.
 * @return string Safe base URL for success/cancel redirects.
 */
function npmp_payment_return_base( $raw_url ) {
	$fallback = home_url( '/' );
	$raw_url  = esc_url_raw( (string) $raw_url );

	if ( ! $raw_url ) {
		return $fallback;
	}

	$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
	$url_host  = wp_parse_url( $raw_url, PHP_URL_HOST );

	if ( ! $url_host || strtolower( (string) $url_host ) !== strtolower( (string) $home_host ) ) {
		return $fallback;
	}

	// Strip any stale status args from a previous round trip.
	return remove_query_arg( array( 'npmp_donation', 'npmp_session_id' ), $raw_url );
}

/**
 * Get donation form options
 *
 * @return array
 */
function npmp_get_donation_form_options() {
	return array(
		'title'      => get_option( 'npmp_donation_form_title', 'Support Our Mission' ),
		'intro'      => get_option( 'npmp_donation_form_intro', 'Your gift supports our work.' ),
		'amount_lbl' => get_option( 'npmp_donation_amount_label', 'Donation Amount' ),
		'email_lbl'  => get_option( 'npmp_donation_email_label', 'Your Email' ),
		'btn_lbl'    => get_option( 'npmp_donation_button_label', 'Donate Now' ),
	);
}

/**
 * Get enabled donation frequencies for a specific gateway
 *
 * @param string $gateway Gateway type (paypal_api, stripe, etc.).
 * @return array
 */
function npmp_get_enabled_frequencies( $gateway = '' ) {
	$frequencies = array();

	// One-time is always enabled
	$frequencies['one_time'] = __( 'One-Time', 'nonprofit-manager' );

	// Pro-only recurring frequencies - gateway-specific
	if ( npmp_is_pro() && ! empty( $gateway ) ) {
		$prefix = '';

		// Determine the option prefix based on gateway
		if ( 'paypal_api' === $gateway ) {
			$prefix = 'npmp_paypal_enable_';
		} elseif ( 'stripe' === $gateway ) {
			$prefix = 'npmp_stripe_enable_';
		}

		// If we have a valid gateway with frequency settings
		if ( ! empty( $prefix ) ) {
			if ( get_option( $prefix . 'weekly', 0 ) ) {
				$frequencies['weekly'] = __( 'Weekly', 'nonprofit-manager' );
			}
			if ( get_option( $prefix . 'monthly', 0 ) ) {
				$frequencies['monthly'] = __( 'Monthly', 'nonprofit-manager' );
			}
			if ( get_option( $prefix . 'quarterly', 0 ) ) {
				$frequencies['quarterly'] = __( 'Quarterly', 'nonprofit-manager' );
			}
			if ( get_option( $prefix . 'annual', 0 ) ) {
				$frequencies['annual'] = __( 'Annual', 'nonprofit-manager' );
			}
		}
	}

	return $frequencies;
}

/**
 * Check if multiple frequencies are enabled for a specific gateway
 *
 * @param string $gateway Gateway type (paypal_api, stripe, etc.).
 * @return bool
 */
function npmp_has_multiple_frequencies( $gateway = '' ) {
	return count( npmp_get_enabled_frequencies( $gateway ) ) > 1;
}

/**
 * Send thank you email to donor
 *
 * @param array $donation_data Donation information.
 * @return bool
 */
function npmp_send_thank_you_email( $donation_data ) {
	if ( ! npmp_is_pro() ) {
		return false;
	}

	if ( ! get_option( 'npmp_enable_thank_you_email', 1 ) ) {
		return false;
	}

	$donor_email = $donation_data['email'] ?? '';
	$donor_name  = $donation_data['name'] ?? '';
	$amount      = $donation_data['amount'] ?? 0;
	$frequency   = $donation_data['frequency'] ?? 'one_time';
	$date        = $donation_data['date'] ?? date_i18n( get_option( 'date_format' ) );

	if ( empty( $donor_email ) ) {
		return false;
	}

	// Get email template
	$subject = get_option( 'npmp_thank_you_subject', 'Thank You for Your Donation!' );
	$message = get_option( 'npmp_thank_you_message', "Dear {donor_name},\n\nThank you for your generous donation of {donation_amount} on {donation_date}.\n\nYour support keeps our work going.\n\nBest regards,\n{site_name}" );

	// Replace shortcodes
	$frequency_labels = array(
		'one_time'  => __( 'One-Time', 'nonprofit-manager' ),
		'weekly'    => __( 'Weekly', 'nonprofit-manager' ),
		'monthly'   => __( 'Monthly', 'nonprofit-manager' ),
		'quarterly' => __( 'Quarterly', 'nonprofit-manager' ),
		'annual'    => __( 'Annual', 'nonprofit-manager' ),
	);

	$replacements = array(
		'{donor_name}'         => $donor_name ?: $donor_email,
		'{donor_email}'        => $donor_email,
		'{donation_amount}'    => '$' . number_format( $amount, 2 ),
		'{donation_date}'      => $date,
		'{donation_frequency}' => $frequency_labels[ $frequency ] ?? $frequency,
		'{site_name}'          => get_bloginfo( 'name' ),
	);

	$subject = str_replace( array_keys( $replacements ), array_values( $replacements ), $subject );
	$message = str_replace( array_keys( $replacements ), array_values( $replacements ), $message );

	// Send email
	$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
	return wp_mail( $donor_email, $subject, $message, $headers );
}

/**
 * Add donor to membership list if not already a member
 *
 * @param string $email Donor email.
 * @param string $name  Donor name.
 * @return void
 */
function npmp_add_donor_to_membership( $email, $name = '' ) {
	// Check if Member Manager class exists
	if ( ! class_exists( 'NPMP_Member_Manager' ) ) {
		return;
	}

	$member_manager = NPMP_Member_Manager::get_instance();

	// Check if member already exists
	$existing_member = $member_manager->get_member_by_email( $email );

	if ( $existing_member ) {
		// Member exists, do nothing
		return;
	}

	// Get default membership level
	$default_level = get_option( 'npmp_default_membership_level', 'Subscriber' );

	// Add as new member
	$member_manager->add_member(
		array(
			'email'            => $email,
			'name'             => $name,
			'membership_level' => $default_level,
			'source'           => 'donation',
		)
	);
}
