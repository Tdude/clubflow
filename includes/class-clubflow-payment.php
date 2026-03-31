<?php
/**
 * Payment handling for ClubCal Lite
 * Supports Swish (primary) and Stripe (prepared)
 *
 * @package ClubFlow
 */

if (!defined('ABSPATH')) {
	exit;
}

final class ClubFlow_Payment {
	public const OPTION_KEY = 'clubflow_payment_settings';
	public const LOG_POST_TYPE = 'club_payment_log';

	private array $settings;

	public function __construct() {
		$this->settings = $this->get_settings();
	}

	public function register(): void {
		add_action('init', [$this, 'register_payment_log_cpt']);
		add_action('admin_menu', [$this, 'register_settings_page']);
		add_action('admin_init', [$this, 'register_settings']);
		
		// Payment log admin columns
		add_filter('manage_' . self::LOG_POST_TYPE . '_posts_columns', [$this, 'payment_log_columns']);
		add_action('manage_' . self::LOG_POST_TYPE . '_posts_custom_column', [$this, 'render_payment_log_column'], 10, 2);
		add_action('admin_notices', [$this, 'payment_log_admin_notice']);

		// Payment log filters and summary
		add_action('restrict_manage_posts', [$this, 'payment_log_filters'], 10, 2);
		add_action('pre_get_posts', [$this, 'payment_log_filter_query']);
		add_action('manage_posts_extra_tablenav', [$this, 'payment_log_summary']);
	}

	/**
	 * Get payment settings with defaults
	 */
	public function get_settings(): array {
		$defaults = [
			'enabled'              => false,
			'payment_method'       => 'swish', // swish, stripe, manual
			'swish_number'         => '',      // Merchant Swish number
			'swish_payee_alias'    => '',      // For API integration
			'swish_cert_path'      => '',      // Path to Swish certificate
			'stripe_publishable'   => '',
			'stripe_secret'        => '',
			'currency'             => 'SEK',
			'require_payment'      => false,   // Require payment before confirming
			'mailchimp_api_key'    => '',
			'mailchimp_list_id'    => '',
			'mailchimp_enabled'    => false,
		];

		$saved = get_option(self::OPTION_KEY, []);
		return wp_parse_args($saved, $defaults);
	}

	/**
	 * Show explanatory notice on payment log page
	 */
	public function payment_log_admin_notice(): void {
		$screen = get_current_screen();
		if (!$screen || $screen->post_type !== self::LOG_POST_TYPE) {
			return;
		}
		?>
		<div class="notice notice-info" style="border-left-color: #7b1fa2;">
			<details style="padding: 8px 0;">
				<summary style="cursor: pointer; font-weight: 600; color: #1d2327;">
					ℹ️ <?php esc_html_e('About Payment Log', 'clubflow'); ?>
				</summary>
				<div style="margin-top: 10px; color: #50575e;">
					<p style="margin: 0 0 8px 0;">
						<?php esc_html_e('This is a complete log of all payment transactions — successful, pending, and failed.', 'clubflow'); ?>
					</p>
					<p style="margin: 0 0 8px 0;">
						<strong><?php esc_html_e('Status meanings:', 'clubflow'); ?></strong>
					</p>
					<ul style="margin: 0 0 0 20px; list-style: disc;">
						<li><span style="color: #2e7d32;">●</span> <strong>Completed</strong> — <?php esc_html_e('Payment received and confirmed', 'clubflow'); ?></li>
						<li><span style="color: #ff9800;">●</span> <strong>Pending</strong> — <?php esc_html_e('Awaiting payment (user may have abandoned checkout)', 'clubflow'); ?></li>
						<li><span style="color: #c62828;">●</span> <strong>Failed</strong> — <?php esc_html_e('Payment was declined or errored', 'clubflow'); ?></li>
					</ul>
					<p style="margin: 10px 0 0 0; font-style: italic;">
						<?php esc_html_e('Tip: Old pending entries can often be safely ignored — they represent abandoned checkouts.', 'clubflow'); ?>
					</p>
				</div>
			</details>
		</div>
		<?php
	}

	/**
	 * Register payment log CPT
	 */
	public function register_payment_log_cpt(): void {
		$labels = [
			'name'               => __('Payment Log', 'clubflow'),
			'singular_name'      => __('Payment', 'clubflow'),
			'menu_name'          => __('Payment Log', 'clubflow'),
			'all_items'          => __('Payment Log', 'clubflow'),
			'view_item'          => __('View Payment', 'clubflow'),
			'search_items'       => __('Search Payments', 'clubflow'),
			'not_found'          => __('No payments found.', 'clubflow'),
		];

		$args = [
			'labels'              => $labels,
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => 'edit.php?post_type=' . ClubFlow::POST_TYPE,
			'show_in_rest'        => false,
			'exclude_from_search' => true,
			'publicly_queryable'  => false,
			'capability_type'     => 'post',
			'capabilities'        => [
				'create_posts' => 'do_not_allow', // Disable "Add New"
			],
			'map_meta_cap'        => true,
			'supports'            => ['title'],
			'menu_icon'           => 'dashicons-money-alt',
		];

		register_post_type(self::LOG_POST_TYPE, $args);
	}

	/**
	 * Register settings page under ClubFlow menu
	 */
	public function register_settings_page(): void {
		add_submenu_page(
			'edit.php?post_type=' . ClubFlow::POST_TYPE,
			__('Payment Settings', 'clubflow'),
			__('Payment Settings', 'clubflow'),
			'manage_options',
			'clubflow-payment',
			[$this, 'render_settings_page']
		);
	}

	/**
	 * Register settings
	 */
	public function register_settings(): void {
		register_setting(
			'clubflow_payment',
			self::OPTION_KEY,
			[
				'sanitize_callback' => [$this, 'sanitize_settings'],
			]
		);
	}

	/**
	 * Sanitize settings - preserves existing values when not in form submission
	 */
	public function sanitize_settings(array $input): array {
		// Get existing settings to preserve API keys not in this submission
		$existing = get_option(self::OPTION_KEY, []);
		$sanitized = [];
		
		// General settings
		$sanitized['enabled'] = !empty($input['enabled']);
		$sanitized['payment_method'] = sanitize_text_field($input['payment_method'] ?? $existing['payment_method'] ?? 'stripe');
		$sanitized['require_payment'] = !empty($input['require_payment']);
		$sanitized['currency'] = sanitize_text_field($input['currency'] ?? $existing['currency'] ?? 'SEK');
		
		// Klarna settings - preserve existing if not in input
		$sanitized['klarna_test_mode'] = array_key_exists('klarna_test_mode', $input) ? !empty($input['klarna_test_mode']) : ($existing['klarna_test_mode'] ?? false);
		$sanitized['klarna_merchant_id'] = $this->preserve_or_sanitize($input, $existing, 'klarna_merchant_id');
		$sanitized['klarna_api_secret'] = $this->preserve_or_sanitize($input, $existing, 'klarna_api_secret');
		
		// Swish settings - preserve existing if not in input
		$sanitized['swish_number'] = $this->preserve_or_sanitize($input, $existing, 'swish_number');
		$sanitized['swish_payee_alias'] = $this->preserve_or_sanitize($input, $existing, 'swish_payee_alias');
		$sanitized['swish_cert_path'] = $this->preserve_or_sanitize($input, $existing, 'swish_cert_path');
		$sanitized['swish_cert_pass'] = $this->preserve_or_sanitize($input, $existing, 'swish_cert_pass');
		$sanitized['swish_test_mode'] = array_key_exists('swish_test_mode', $input) ? !empty($input['swish_test_mode']) : ($existing['swish_test_mode'] ?? false);
		
		// Stripe settings - preserve existing if not in input, validate key format
		$sanitized['stripe_publishable'] = $this->preserve_or_sanitize($input, $existing, 'stripe_publishable');
		if ( ! empty( $sanitized['stripe_publishable'] ) && ! preg_match( '/^pk_(test|live)_/', $sanitized['stripe_publishable'] ) ) {
			add_settings_error( self::OPTION_KEY, 'invalid_stripe_pk', __( 'Stripe publishable key must start with pk_test_ or pk_live_.', 'clubflow' ) );
			$sanitized['stripe_publishable'] = $existing['stripe_publishable'] ?? '';
		}
		$sanitized['stripe_secret'] = $this->preserve_or_sanitize($input, $existing, 'stripe_secret');
		if ( ! empty( $sanitized['stripe_secret'] ) && ! preg_match( '/^sk_(test|live)_/', $sanitized['stripe_secret'] ) ) {
			add_settings_error( self::OPTION_KEY, 'invalid_stripe_sk', __( 'Stripe secret key must start with sk_test_ or sk_live_.', 'clubflow' ) );
			$sanitized['stripe_secret'] = $existing['stripe_secret'] ?? '';
		}
		$sanitized['stripe_webhook_secret'] = $this->preserve_or_sanitize($input, $existing, 'stripe_webhook_secret');
		
		// Mailchimp settings - preserve existing if not in input
		$sanitized['mailchimp_api_key'] = $this->preserve_or_sanitize($input, $existing, 'mailchimp_api_key');
		$sanitized['mailchimp_list_id'] = $this->preserve_or_sanitize($input, $existing, 'mailchimp_list_id');
		$sanitized['mailchimp_enabled'] = array_key_exists('mailchimp_enabled', $input) ? !empty($input['mailchimp_enabled']) : ($existing['mailchimp_enabled'] ?? false);

		return $sanitized;
	}

	/**
	 * Preserve existing value if key not in input, otherwise sanitize the input
	 * This prevents API keys from being wiped when submitting partial forms
	 */
	private function preserve_or_sanitize(array $input, array $existing, string $key): string {
		if (array_key_exists($key, $input)) {
			return sanitize_text_field($input[$key]);
		}
		return $existing[$key] ?? '';
	}

	/**
	 * Render settings page
	 */
	public function render_settings_page(): void {
		if (!current_user_can('manage_options')) {
			return;
		}

		$settings = $this->get_settings();
		?>
		<style>
			.clubflow-settings-section {
				margin: 20px 0;
				border: 1px solid #ccd0d4;
				border-radius: 4px;
				background: #f9f9f9;
			}
			.clubflow-settings-section[open] {
				background: #fff;
			}
			.clubflow-settings-summary {
				padding: 12px 16px;
				cursor: pointer;
				user-select: none;
			}
			.clubflow-settings-summary:hover {
				background: #f0f0f1;
			}
			.clubflow-settings-section .form-table {
				margin: 0 16px 16px;
			}
			.clubflow-settings-section .description {
				margin-left: 16px;
				margin-right: 16px;
			}
		</style>
		<div class="wrap">
			<h1><?php echo esc_html__('Payment Settings', 'clubflow'); ?></h1>
			
			<form method="post" action="options.php">
				<?php settings_fields('clubflow_payment'); ?>
				
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e('Enable Payments', 'clubflow'); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enabled]" value="1" <?php checked($settings['enabled']); ?> />
								<?php esc_html_e('Enable payment collection for bookings', 'clubflow'); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e('Payment Method', 'clubflow'); ?></th>
						<td>
							<select name="<?php echo esc_attr(self::OPTION_KEY); ?>[payment_method]">
								<option value="klarna" <?php selected($settings['payment_method'], 'klarna'); ?>>Klarna</option>
								<option value="swish" <?php selected($settings['payment_method'], 'swish'); ?>>Swish</option>
								<option value="stripe" <?php selected($settings['payment_method'], 'stripe'); ?>>Stripe</option>
								<option value="manual" <?php selected($settings['payment_method'], 'manual'); ?>><?php esc_html_e('Manual (pay at venue)', 'clubflow'); ?></option>
							</select>
							<p class="description"><?php esc_html_e('Klarna is recommended - easy setup, supports cards and direct bank payment.', 'clubflow'); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e('Require Payment', 'clubflow'); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[require_payment]" value="1" <?php checked($settings['require_payment']); ?> />
								<?php esc_html_e('Require payment confirmation before booking is confirmed', 'clubflow'); ?>
							</label>
							<p class="description"><?php esc_html_e('If unchecked, bookings are confirmed immediately and payment is tracked separately.', 'clubflow'); ?></p>
						</td>
					</tr>
				</table>

				<div data-clubflow-provider="klarna">
				<details class="clubflow-settings-section">
				<summary class="clubflow-settings-summary"><h2 class="title" style="display:inline; cursor:pointer;"><?php esc_html_e('Klarna Settings', 'clubflow'); ?> <span style="font-size:12px;color:#666;">▼</span></h2></summary>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e('Test Mode', 'clubflow'); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[klarna_test_mode]" value="1" <?php checked($settings['klarna_test_mode'] ?? false); ?> />
								<?php esc_html_e('Use Klarna Playground (test environment)', 'clubflow'); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Merchant ID (UID)', 'clubflow'); ?></th>
						<td>
							<input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[klarna_merchant_id]" value="<?php echo esc_attr($settings['klarna_merchant_id'] ?? ''); ?>" class="regular-text" placeholder="K12345_abcdef" />
							<p class="description"><?php esc_html_e('Your Klarna API username/UID from the Merchant Portal.', 'clubflow'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('API Secret', 'clubflow'); ?></th>
						<td>
							<input type="password" name="<?php echo esc_attr(self::OPTION_KEY); ?>[klarna_api_secret]" value="<?php echo esc_attr($settings['klarna_api_secret'] ?? ''); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e('Your Klarna API password/secret.', 'clubflow'); ?></p>
						</td>
					</tr>
				</table>
				
				<p class="description" style="background: #e8f5e9; padding: 12px; border-left: 4px solid #4caf50; margin: 16px 0;">
					<strong><?php esc_html_e('Get Klarna credentials:', 'clubflow'); ?></strong><br>
					1. <?php esc_html_e('Go to', 'clubflow'); ?> <a href="https://portal.klarna.com" target="_blank">portal.klarna.com</a><br>
					2. <?php esc_html_e('Create account or sign in', 'clubflow'); ?><br>
					3. <?php esc_html_e('Go to Settings → API Credentials', 'clubflow'); ?><br>
					4. <?php esc_html_e('Generate new API credentials', 'clubflow'); ?>
				</p>
				</details>
				</div>

				<div data-clubflow-provider="swish">
				<details class="clubflow-settings-section">
				<summary class="clubflow-settings-summary"><h2 class="title" style="display:inline; cursor:pointer;"><?php esc_html_e('Swish Settings', 'clubflow'); ?> <span style="font-size:12px;color:#666;">▼</span></h2></summary>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e('Swish Number', 'clubflow'); ?></th>
						<td>
							<input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[swish_number]" value="<?php echo esc_attr($settings['swish_number']); ?>" class="regular-text" placeholder="123 456 78 90" />
							<p class="description"><?php esc_html_e('Your Swish number for receiving payments. Displayed to customers.', 'clubflow'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Test Mode', 'clubflow'); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[swish_test_mode]" value="1" <?php checked($settings['swish_test_mode'] ?? false); ?> />
								<?php esc_html_e('Use Swish test/sandbox environment', 'clubflow'); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Swish Payee Alias', 'clubflow'); ?></th>
						<td>
							<input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[swish_payee_alias]" value="<?php echo esc_attr($settings['swish_payee_alias']); ?>" class="regular-text" placeholder="1234567890" />
							<p class="description"><?php esc_html_e('Merchant Swish number (10 digits). Required for API integration.', 'clubflow'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Certificate Path', 'clubflow'); ?></th>
						<td>
							<input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[swish_cert_path]" value="<?php echo esc_attr($settings['swish_cert_path'] ?? ''); ?>" class="large-text" placeholder="/path/to/swish-certificate.p12" />
							<p class="description"><?php esc_html_e('Full server path to your Swish merchant certificate (.p12 file).', 'clubflow'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Certificate Password', 'clubflow'); ?></th>
						<td>
							<input type="password" name="<?php echo esc_attr(self::OPTION_KEY); ?>[swish_cert_pass]" value="<?php echo esc_attr($settings['swish_cert_pass'] ?? ''); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e('Password for the certificate file.', 'clubflow'); ?></p>
						</td>
					</tr>
				</table>
				
				<p class="description" style="background: #f0f6fc; padding: 12px; border-left: 4px solid #2271b1; margin: 16px 0;">
					<strong><?php esc_html_e('Note:', 'clubflow'); ?></strong> 
					<?php esc_html_e('Without API credentials, the plugin will show QR code and Swish number for manual payment. You can confirm payments manually in the admin.', 'clubflow'); ?>
					<br><br>
					<?php esc_html_e('For automatic payment confirmation, you need a Swish for Merchants agreement with your bank and the merchant certificate.', 'clubflow'); ?>
				</p>
				</details>
				</div>

				<div data-clubflow-provider="stripe">
				<h2 class="title"><?php esc_html_e('Stripe Settings', 'clubflow'); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e('Publishable Key', 'clubflow'); ?></th>
						<td>
							<input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[stripe_publishable]" value="<?php echo esc_attr($settings['stripe_publishable'] ?? ''); ?>" class="large-text" placeholder="pk_test_..." />
							<p class="description"><?php esc_html_e('Starts with pk_test_ (test) or pk_live_ (production)', 'clubflow'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Secret Key', 'clubflow'); ?></th>
						<td>
							<input type="password" name="<?php echo esc_attr(self::OPTION_KEY); ?>[stripe_secret]" value="<?php echo esc_attr($settings['stripe_secret'] ?? ''); ?>" class="large-text" placeholder="sk_test_..." />
							<p class="description"><?php esc_html_e('Starts with sk_test_ (test) or sk_live_ (production)', 'clubflow'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Webhook Secret', 'clubflow'); ?></th>
						<td>
							<input type="password" name="<?php echo esc_attr(self::OPTION_KEY); ?>[stripe_webhook_secret]" value="<?php echo esc_attr($settings['stripe_webhook_secret'] ?? ''); ?>" class="large-text" placeholder="whsec_..." />
							<p class="description">
								<?php esc_html_e('From Stripe Dashboard → Developers → Webhooks', 'clubflow'); ?><br>
								<?php esc_html_e('Webhook URL:', 'clubflow'); ?> <code><?php echo esc_html(rest_url('clubflow/v1/stripe-webhook')); ?></code>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Test Connection', 'clubflow'); ?></th>
						<td>
							<button type="button" id="clubflow-test-stripe" class="button button-secondary">
								<?php esc_html_e('Test Stripe Connection', 'clubflow'); ?>
							</button>
							<span id="clubflow-stripe-result" style="margin-left: 10px;"></span>
							<script>
							document.getElementById('clubflow-test-stripe').addEventListener('click', function() {
								var btn = this;
								var result = document.getElementById('clubflow-stripe-result');
								btn.disabled = true;
								result.innerHTML = '<?php esc_html_e('Testing...', 'clubflow'); ?>';
								result.style.color = '#666';
								
								fetch(ajaxurl, {
									method: 'POST',
									headers: {'Content-Type': 'application/x-www-form-urlencoded'},
									body: 'action=clubflow_test_stripe&nonce=<?php echo wp_create_nonce('clubflow_test_stripe'); ?>'
								})
								.then(r => r.json())
								.then(data => {
									btn.disabled = false;
									if (data.success) {
										result.innerHTML = '✅ ' + data.data.message + ' (' + data.data.mode + ' mode)';
										result.style.color = '#2e7d32';
									} else {
										result.innerHTML = '❌ ' + (data.data?.message || 'Error');
										result.style.color = '#c62828';
									}
								})
								.catch(e => {
									btn.disabled = false;
									result.innerHTML = '❌ Connection error';
									result.style.color = '#c62828';
								});
							});
							</script>
						</td>
					</tr>
				</table>
				
				<p class="description" style="background: #f3e5f5; padding: 12px; border-left: 4px solid #7b1fa2; margin: 16px 0;">
					<strong><?php esc_html_e('Setup steps:', 'clubflow'); ?></strong><br>
					1. <?php esc_html_e('Get API keys from', 'clubflow'); ?> <a href="https://dashboard.stripe.com/apikeys" target="_blank">Stripe Dashboard → Developers → API keys</a><br>
					2. <?php esc_html_e('Create webhook at', 'clubflow'); ?> <a href="https://dashboard.stripe.com/webhooks" target="_blank">Stripe Dashboard → Developers → Webhooks</a><br>
					3. <?php esc_html_e('Add endpoint:', 'clubflow'); ?> <code><?php echo esc_html(rest_url('clubflow/v1/stripe-webhook')); ?></code><br>
					4. <?php esc_html_e('Select event:', 'clubflow'); ?> <code>checkout.session.completed</code>
				</p>

				</div>

				<h2 class="title"><?php esc_html_e('Mailchimp Settings', 'clubflow'); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e('Enable Mailchimp', 'clubflow'); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[mailchimp_enabled]" value="1" <?php checked($settings['mailchimp_enabled']); ?> />
								<?php esc_html_e('Send booking confirmations via Mailchimp', 'clubflow'); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('API Key', 'clubflow'); ?></th>
						<td>
							<input type="password" name="<?php echo esc_attr(self::OPTION_KEY); ?>[mailchimp_api_key]" value="<?php echo esc_attr($settings['mailchimp_api_key']); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e('Find this in Mailchimp → Account → Extras → API keys', 'clubflow'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Audience/List ID', 'clubflow'); ?></th>
						<td>
							<input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[mailchimp_list_id]" value="<?php echo esc_attr($settings['mailchimp_list_id']); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e('The audience to add subscribers to (optional).', 'clubflow'); ?></p>
						</td>
					</tr>
				</table>


				<script>
				(function() {
					var select = document.querySelector('select[name="clubflow_payment_settings[payment_method]"]');
					var providers = document.querySelectorAll('[data-clubflow-provider]');
					function toggleProviders() {
						var selected = select.value;
						providers.forEach(function(el) {
							el.style.display = (el.getAttribute('data-clubflow-provider') === selected) ? '' : 'none';
						});
					}
					select.addEventListener('change', toggleProviders);
					toggleProviders();
				})();
				</script>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Log a payment
	 */
	public static function log_payment(array $data): int {
		$title = sprintf(
			'%s - %s - %s',
			$data['booking_id'] ?? 'N/A',
			$data['amount'] ?? '0',
			$data['method'] ?? 'unknown'
		);

		$log_id = wp_insert_post([
			'post_type'   => self::LOG_POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => $title,
		]);

		if (is_wp_error($log_id)) {
			return 0;
		}

		// Save all data as meta
		update_post_meta($log_id, '_payment_booking_id', absint($data['booking_id'] ?? 0));
		update_post_meta($log_id, '_payment_event_id', absint($data['event_id'] ?? 0));
		update_post_meta($log_id, '_payment_amount', sanitize_text_field($data['amount'] ?? ''));
		update_post_meta($log_id, '_payment_currency', sanitize_text_field($data['currency'] ?? 'SEK'));
		update_post_meta($log_id, '_payment_method', sanitize_text_field($data['method'] ?? ''));
		update_post_meta($log_id, '_payment_status', sanitize_text_field($data['status'] ?? 'pending'));
		update_post_meta($log_id, '_payment_reference', sanitize_text_field($data['reference'] ?? ''));
		update_post_meta($log_id, '_payment_customer_email', sanitize_email($data['email'] ?? ''));
		update_post_meta($log_id, '_payment_customer_name', sanitize_text_field($data['name'] ?? ''));
		update_post_meta($log_id, '_payment_timestamp', current_time('mysql'));
		update_post_meta($log_id, '_payment_notes', sanitize_textarea_field($data['notes'] ?? ''));

		return $log_id;
	}

	/**
	 * Update payment status
	 */
	public static function update_payment_status(int $log_id, string $status, string $notes = ''): bool {
		if (get_post_type($log_id) !== self::LOG_POST_TYPE) {
			return false;
		}

		update_post_meta($log_id, '_payment_status', sanitize_text_field($status));
		
		if ($notes) {
			$existing_notes = get_post_meta($log_id, '_payment_notes', true);
			$new_notes = $existing_notes ? $existing_notes . "\n" . $notes : $notes;
			update_post_meta($log_id, '_payment_notes', sanitize_textarea_field($new_notes));
		}

		update_post_meta($log_id, '_payment_updated', current_time('mysql'));

		return true;
	}

	/**
	 * Get payment for a booking
	 */
	public static function get_booking_payment(int $booking_id): ?array {
		$args = [
			'post_type'      => self::LOG_POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'meta_query'     => [
				'relation' => 'AND',
				[
					'key'   => '_payment_booking_id',
					'value' => $booking_id,
					'type'  => 'NUMERIC',
				],
				// Exclude non-payment bookkeeping entries
				[
					'key'     => '_payment_method',
					'value'   => 'email',
					'compare' => '!=',
				],
				// Exclude klippkort ledger rows which reuse the payment log CPT
				[
					'relation' => 'OR',
					[
						'key'     => '_clubflow_klippkort_action',
						'compare' => 'NOT EXISTS',
					],
					[
						'key'     => '_clubflow_klippkort_action',
						'value'   => '',
						'compare' => '=',
					],
				],
			],
		];

		$query = new WP_Query($args);
		if (!$query->have_posts()) {
			return null;
		}

		$post = $query->posts[0];
		return [
			'id'        => $post->ID,
			'booking_id'=> (int) get_post_meta($post->ID, '_payment_booking_id', true),
			'event_id'  => (int) get_post_meta($post->ID, '_payment_event_id', true),
			'amount'    => get_post_meta($post->ID, '_payment_amount', true),
			'currency'  => get_post_meta($post->ID, '_payment_currency', true),
			'method'    => get_post_meta($post->ID, '_payment_method', true),
			'status'    => get_post_meta($post->ID, '_payment_status', true),
			'reference' => get_post_meta($post->ID, '_payment_reference', true),
			'email'     => get_post_meta($post->ID, '_payment_customer_email', true),
			'name'      => get_post_meta($post->ID, '_payment_customer_name', true),
			'timestamp' => get_post_meta($post->ID, '_payment_timestamp', true),
			'notes'     => get_post_meta($post->ID, '_payment_notes', true),
		];
	}

	/**
	 * Payment log admin columns
	 */
	public function payment_log_columns(array $columns): array {
		return [
			'cb'        => $columns['cb'],
			'title'     => __('Payment', 'clubflow'),
			'klippkort' => __('Klippkort', 'clubflow'),
			'amount'    => __('Amount', 'clubflow'),
			'method'    => __('Method', 'clubflow'),
			'status'    => __('Status', 'clubflow'),
			'customer'  => __('Customer', 'clubflow'),
			'event'     => __('Event', 'clubflow'),
			'logged'    => __('Logged', 'clubflow'),
		];
	}

	/**
	 * Render payment log column
	 */
	public function render_payment_log_column(string $column, int $post_id): void {
		$klippkort_action = (string) get_post_meta($post_id, '_clubflow_klippkort_action', true);
		$klippkort_code = (string) get_post_meta($post_id, '_clubflow_klippkort_code', true);
		$is_klippkort_ledger = $klippkort_action !== '';

		switch ($column) {
			case 'klippkort':
				if (!$is_klippkort_ledger) {
					echo '—';
					break;
				}
				$label = $klippkort_action === 'issued'
					? __('Issued', 'clubflow')
					: ($klippkort_action === 'redeemed' ? __('Redeemed', 'clubflow') : ucfirst($klippkort_action));
				$icon = $klippkort_action === 'issued' ? '➕' : '✂️';
				$tooltip = trim('Klippkort: ' . $label . ($klippkort_code !== '' ? ' (' . $klippkort_code . ')' : ''));
				echo '<span title="' . esc_attr($tooltip) . '" style="font-size: 16px; line-height: 1; cursor: help;">' . esc_html($icon) . '</span>';
				break;

			case 'amount':
				$amount = get_post_meta($post_id, '_payment_amount', true);
				$currency = get_post_meta($post_id, '_payment_currency', true) ?: 'SEK';
				if ($is_klippkort_ledger && $klippkort_code !== '') {
					echo esc_html($amount ? $amount . ' ' . $currency : '0 ' . $currency);
					echo '<br><small><code>' . esc_html($klippkort_code) . '</code></small>';
				} else {
					echo esc_html($amount ? $amount . ' ' . $currency : '—');
				}
				break;

			case 'method':
				$method = get_post_meta($post_id, '_payment_method', true);
				$methods = [
					'swish'  => 'Swish',
					'stripe' => 'Stripe',
					'manual' => __('Manual', 'clubflow'),
					'klippkort' => __('Klippkort', 'clubflow'),
				];
				if ($is_klippkort_ledger) {
					echo esc_html($methods['klippkort']);
				} else {
					echo esc_html($methods[$method] ?? ucfirst($method));
				}
				break;

			case 'status':
				$status = get_post_meta($post_id, '_payment_status', true);
				$colors = [
					'pending'   => '#ff9800',
					'completed' => '#2e7d32',
					'failed'    => '#c62828',
					'refunded'  => '#7b1fa2',
				];
				$color = $colors[$status] ?? '#666';
				echo '<span style="color: ' . esc_attr($color) . '; font-weight: 500;">' . esc_html(ucfirst($status)) . '</span>';
				break;

			case 'customer':
				$name = get_post_meta($post_id, '_payment_customer_name', true);
				$email = get_post_meta($post_id, '_payment_customer_email', true);
				echo esc_html($name);
				if ($email) {
					echo '<br><small><a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a></small>';
				}
				break;

			case 'event':
				$event_id = (int) get_post_meta($post_id, '_payment_event_id', true);
				if ($event_id > 0) {
					$event = get_post($event_id);
					if ($event) {
						echo '<a href="' . esc_url(get_edit_post_link($event_id)) . '">' . esc_html(get_the_title($event_id)) . '</a>';
					} else {
						echo '<span style="color: #999;">' . esc_html__('Deleted', 'clubflow') . '</span>';
					}
				}
				break;

			case 'logged':
				$post = get_post($post_id);
				if ($post) {
					echo esc_html(wp_date('Y-m-d H:i', strtotime($post->post_date)));
				}
				break;
		}
	}

	/**
	 * Generate Swish payment data for QR/deep link
	 */
	public static function generate_swish_payment(int $booking_id, string $amount, string $message = ''): array {
		$settings = (new self())->get_settings();
		$swish_number = preg_replace('/[^0-9]/', '', $settings['swish_number']);

		if (empty($swish_number)) {
			return ['error' => __('Swish number not configured.', 'clubflow')];
		}

		// Generate reference from booking ID
		$reference = 'UPAIF-' . $booking_id;

		// Swish deep link format
		$swish_data = [
			'payee'   => $swish_number,
			'amount'  => $amount,
			'message' => $message ?: $reference,
		];

		// Swish app deep link
		$deep_link = 'swish://payment?' . http_build_query([
			'data' => json_encode([
				'version' => 1,
				'payee'   => ['value' => $swish_number],
				'amount'  => ['value' => (float) $amount],
				'message' => ['value' => $message ?: $reference],
			]),
		]);

		return [
			'swish_number' => $swish_number,
			'amount'       => $amount,
			'reference'    => $reference,
			'message'      => $message ?: $reference,
			'deep_link'    => $deep_link,
			'qr_data'      => 'C' . $swish_number . ';' . $amount . ';' . ($message ?: $reference) . ';0',
		];
	}

	/**
	 * Add dropdown filters for payment method and status on the payment log list
	 */
	public function payment_log_filters(string $post_type, string $which): void {
		if ($post_type !== self::LOG_POST_TYPE || $which !== 'top') {
			return;
		}

		$current_method = sanitize_text_field($_GET['_filter_method'] ?? '');
		$current_status = sanitize_text_field($_GET['_filter_status'] ?? '');

		$methods = [
			''       => __('All methods', 'clubflow'),
			'stripe' => 'Stripe',
			'swish'  => 'Swish',
			'klarna' => 'Klarna',
			'manual' => __('Manual', 'clubflow'),
		];

		$statuses = [
			''          => __('All statuses', 'clubflow'),
			'completed' => __('Completed', 'clubflow'),
			'pending'   => __('Pending', 'clubflow'),
			'failed'    => __('Failed', 'clubflow'),
		];

		echo '<select name="_filter_method">';
		foreach ($methods as $value => $label) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr($value),
				selected($current_method, $value, false),
				esc_html($label)
			);
		}
		echo '</select>';

		echo '<select name="_filter_status">';
		foreach ($statuses as $value => $label) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr($value),
				selected($current_status, $value, false),
				esc_html($label)
			);
		}
		echo '</select>';
	}

	/**
	 * Apply payment log filters to the admin query
	 */
	public function payment_log_filter_query(\WP_Query $query): void {
		if (!is_admin() || !$query->is_main_query()) {
			return;
		}

		$screen = get_current_screen();
		if (!$screen || $screen->post_type !== self::LOG_POST_TYPE) {
			return;
		}

		$meta_query = $query->get('meta_query') ?: [];

		$filter_method = sanitize_text_field($_GET['_filter_method'] ?? '');
		if ($filter_method !== '') {
			$meta_query[] = [
				'key'   => '_payment_method',
				'value' => $filter_method,
			];
		}

		$filter_status = sanitize_text_field($_GET['_filter_status'] ?? '');
		if ($filter_status !== '') {
			$meta_query[] = [
				'key'   => '_payment_status',
				'value' => $filter_status,
			];
		}

		if (!empty($meta_query)) {
			$query->set('meta_query', $meta_query);
		}
	}

	/**
	 * Show a summary line above the payment log table (top tablenav only)
	 */
	public function payment_log_summary(string $which): void {
		if ($which !== 'top') {
			return;
		}

		$screen = get_current_screen();
		if (!$screen || $screen->post_type !== self::LOG_POST_TYPE) {
			return;
		}

		$completed_total = 0.0;
		$completed_count = 0;
		$pending_count   = 0;

		// Query all completed payments
		$completed_posts = get_posts([
			'post_type'      => self::LOG_POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => [
				[
					'key'   => '_payment_status',
					'value' => 'completed',
				],
			],
		]);

		foreach ($completed_posts as $pid) {
			$amount = (float) get_post_meta($pid, '_payment_amount', true);
			$completed_total += $amount;
			$completed_count++;
		}

		// Count pending payments
		$pending_posts = get_posts([
			'post_type'      => self::LOG_POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => [
				[
					'key'   => '_payment_status',
					'value' => 'pending',
				],
			],
		]);
		$pending_count = count($pending_posts);

		printf(
			'<div class="alignleft" style="margin: 6px 0 0 8px; font-size: 13px; color: #50575e;">'
			. '<span style="color: #2e7d32; font-weight: 600;">%s</span> &nbsp;|&nbsp; '
			. '<span style="color: #ff9800;">%s</span>'
			. '</div>',
			/* translators: 1: total kr, 2: number of payments */
			esc_html(sprintf(__('Total completed: %s kr (%s betalningar)', 'clubflow'),
				number_format_i18n($completed_total, 0),
				$completed_count
			)),
			esc_html(sprintf(__('Pending: %s betalningar', 'clubflow'),
				$pending_count
			))
		);
	}
}
