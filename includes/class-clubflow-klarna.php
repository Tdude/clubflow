<?php
/**
 * Klarna Payment Integration for ClubCal Lite
 * Handles Klarna Checkout / Payments API
 *
 * @package ClubFlow
 */

if (!defined('ABSPATH')) {
	exit;
}

final class ClubFlow_Klarna {
	public const AJAX_CREATE_ORDER = 'clubflow_klarna_create';
	public const REST_CALLBACK = 'clubflow/v1/klarna-callback';
	public const REST_CONFIRMATION = 'clubflow/v1/klarna-confirmation';

	private array $settings;

	// Klarna API endpoints
	private const API_EU_PROD = 'https://api.klarna.com';
	private const API_EU_TEST = 'https://api.playground.klarna.com';

	public function __construct() {
		$this->settings = $this->get_settings();
	}

	public function register(): void {
		// AJAX endpoints
		add_action('wp_ajax_' . self::AJAX_CREATE_ORDER, [$this, 'ajax_create_order']);
		add_action('wp_ajax_nopriv_' . self::AJAX_CREATE_ORDER, [$this, 'ajax_create_order']);

		// REST API callbacks
		add_action('rest_api_init', [$this, 'register_rest_routes']);
		
		// Confirmation page shortcode
		add_shortcode('clubflow_klarna_confirmation', [$this, 'shortcode_confirmation']);
	}

	/**
	 * Get Klarna settings
	 */
	private function get_settings(): array {
		$payment_settings = get_option(ClubFlow_Payment::OPTION_KEY, []);
		return [
			'enabled'      => !empty($payment_settings['enabled']) && ($payment_settings['payment_method'] ?? '') === 'klarna',
			'merchant_id'  => $payment_settings['klarna_merchant_id'] ?? '',
			'api_secret'   => $payment_settings['klarna_api_secret'] ?? '',
			'test_mode'    => !empty($payment_settings['klarna_test_mode']),
			'region'       => $payment_settings['klarna_region'] ?? 'eu',
		];
	}

	/**
	 * Check if Klarna is properly configured
	 */
	public function is_configured(): bool {
		return !empty($this->settings['merchant_id']) && !empty($this->settings['api_secret']);
	}

	/**
	 * Get API base URL
	 */
	private function get_api_url(): string {
		return $this->settings['test_mode'] ? self::API_EU_TEST : self::API_EU_PROD;
	}

	/**
	 * Make API request to Klarna
	 */
	private function api_request(string $endpoint, string $method = 'GET', array $data = []): array {
		if (!$this->is_configured()) {
			return ['error' => 'Klarna not configured'];
		}

		$url = $this->get_api_url() . $endpoint;
		$auth = base64_encode($this->settings['merchant_id'] . ':' . $this->settings['api_secret']);

		$args = [
			'method'  => $method,
			'headers' => [
				'Authorization' => 'Basic ' . $auth,
				'Content-Type'  => 'application/json',
			],
			'timeout' => 30,
		];

		if (!empty($data) && in_array($method, ['POST', 'PUT', 'PATCH'])) {
			$args['body'] = json_encode($data);
		}

		$response = wp_remote_request($url, $args);

		if (is_wp_error($response)) {
			return ['error' => $response->get_error_message()];
		}

		$body = wp_remote_retrieve_body($response);
		$decoded = json_decode($body, true);

		$code = wp_remote_retrieve_response_code($response);
		if ($code >= 400) {
			return [
				'error'   => $decoded['error_message'] ?? $decoded['error_messages'][0] ?? 'API error',
				'code'    => $code,
				'details' => $decoded,
			];
		}

		return $decoded ?: [];
	}

	/**
	 * Register REST API routes
	 */
	public function register_rest_routes(): void {
		register_rest_route('clubflow/v1', '/klarna-callback', [
			'methods'             => 'POST',
			'callback'            => [$this, 'handle_callback'],
			'permission_callback' => '__return_true',
		]);

		register_rest_route('clubflow/v1', '/klarna-confirmation/(?P<booking_id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [$this, 'handle_confirmation'],
			'permission_callback' => '__return_true',
		]);
	}

	/**
	 * Create Klarna checkout order
	 */
	public function create_checkout_order(int $booking_id, float $amount, string $description): array {
		if (!$this->is_configured()) {
			return ['error' => __('Klarna not configured.', 'clubflow')];
		}

		// Get booking details
		$event_id = (int) get_post_meta($booking_id, '_clubflow_booking_event_id', true);
		$email = get_post_meta($booking_id, '_clubflow_booking_email', true);
		$name = get_post_meta($booking_id, '_clubflow_booking_name', true);
		$event_title = get_the_title($event_id);

		// Amount in minor units (öre for SEK)
		$amount_minor = (int) round($amount * 100);

		// Build order data
		$order_data = [
			'purchase_country'  => 'SE',
			'purchase_currency' => 'SEK',
			'locale'            => 'sv-SE',
			'order_amount'      => $amount_minor,
			'order_tax_amount'  => 0, // No VAT for simplicity, adjust if needed
			'order_lines'       => [
				[
					'type'             => 'physical', // or 'digital'
					'name'             => mb_substr($event_title, 0, 255),
					'quantity'         => 1,
					'unit_price'       => $amount_minor,
					'tax_rate'         => 0,
					'total_amount'     => $amount_minor,
					'total_tax_amount' => 0,
				],
			],
			'merchant_urls'     => [
				'terms'        => home_url('/villkor/'),
				'checkout'     => home_url('/bokning/?booking=' . $booking_id),
				'confirmation' => rest_url('clubflow/v1/klarna-confirmation/' . $booking_id) . '?klarna_order_id={checkout.order.id}',
				'push'         => rest_url(self::REST_CALLBACK) . '?booking_id=' . $booking_id,
			],
			'merchant_reference1' => (string) $booking_id,
			'merchant_reference2' => 'UPAIF-' . $booking_id,
		];

		// Add billing address if we have email
		if ($email) {
			$order_data['billing_address'] = [
				'email' => $email,
			];

			// Try to split name
			$name_parts = explode(' ', $name, 2);
			if (count($name_parts) === 2) {
				$order_data['billing_address']['given_name'] = $name_parts[0];
				$order_data['billing_address']['family_name'] = $name_parts[1];
			}
		}

		// Create checkout session
		$result = $this->api_request('/checkout/v3/orders', 'POST', $order_data);

		if (!empty($result['error'])) {
			error_log('Klarna create order error: ' . json_encode($result));
			return $result;
		}

		if (empty($result['order_id']) || empty($result['html_snippet'])) {
			return ['error' => __('Invalid Klarna response.', 'clubflow')];
		}

		// Store Klarna order ID
		update_post_meta($booking_id, '_clubflow_klarna_order_id', $result['order_id']);
		update_post_meta($booking_id, '_clubflow_klarna_status', 'checkout_incomplete');

		return [
			'success'      => true,
			'order_id'     => $result['order_id'],
			'html_snippet' => $result['html_snippet'],
		];
	}

	/**
	 * AJAX: Create Klarna order
	 */
	public function ajax_create_order(): void {
		if (!check_ajax_referer('clubflow_klarna_create', '_ajax_nonce', false)) {
			wp_send_json_error(__('Security check failed.', 'clubflow'), 403);
		}

		$booking_id = isset($_POST['booking_id']) ? absint($_POST['booking_id']) : 0;
		if (!$booking_id) {
			wp_send_json_error(__('Invalid booking.', 'clubflow'), 400);
		}

		// Get booking details
		$event_id = (int) get_post_meta($booking_id, '_clubflow_booking_event_id', true);
		$price = get_post_meta($event_id, '_clubflow_price', true);
		$amount = (float) preg_replace('/[^0-9.]/', '', $price);

		if ($amount <= 0) {
			wp_send_json_error(__('Invalid amount.', 'clubflow'), 400);
		}

		$event_title = get_the_title($event_id);
		$result = $this->create_checkout_order($booking_id, $amount, $event_title);

		if (!empty($result['error'])) {
			wp_send_json_error($result['error'], 400);
		}

		// Log payment attempt
		if (class_exists('ClubFlow_Payment')) {
			ClubFlow_Payment::log_payment([
				'booking_id' => $booking_id,
				'event_id'   => $event_id,
				'amount'     => $amount,
				'currency'   => 'SEK',
				'method'     => 'klarna',
				'status'     => 'pending',
				'reference'  => $result['order_id'],
				'email'      => get_post_meta($booking_id, '_clubflow_booking_email', true),
				'name'       => get_post_meta($booking_id, '_clubflow_booking_name', true),
				'notes'      => 'Klarna checkout initiated',
			]);
		}

		wp_send_json_success($result);
	}

	/**
	 * Handle Klarna push callback (order completed)
	 */
	public function handle_callback(\WP_REST_Request $request): \WP_REST_Response {
		$booking_id = $request->get_param('booking_id');
		$body = $request->get_json_params();

		error_log('Klarna callback received for booking ' . $booking_id . ': ' . json_encode($body));

		if (!$booking_id) {
			return new \WP_REST_Response(['error' => 'Missing booking_id'], 400);
		}

		$klarna_order_id = get_post_meta($booking_id, '_clubflow_klarna_order_id', true);
		if (!$klarna_order_id) {
			return new \WP_REST_Response(['error' => 'Order not found'], 404);
		}

		// Fetch order from Klarna to verify status
		$order = $this->api_request('/checkout/v3/orders/' . $klarna_order_id, 'GET');

		if (!empty($order['error'])) {
			error_log('Klarna order fetch error: ' . json_encode($order));
			return new \WP_REST_Response(['error' => 'Could not verify order'], 500);
		}

		$status = $order['status'] ?? '';
		update_post_meta($booking_id, '_clubflow_klarna_status', $status);

		if ($status === 'checkout_complete') {
			// Acknowledge the order
			$this->acknowledge_order($klarna_order_id);

			// Update booking status
			update_post_meta($booking_id, '_clubflow_booking_status', 'confirmed');

			// Update payment log
			if (class_exists('ClubFlow_Payment')) {
				$payment = ClubFlow_Payment::get_booking_payment($booking_id);
				if ($payment) {
					ClubFlow_Payment::update_payment_status(
						$payment['id'],
						'completed',
						'Klarna payment completed. Order ID: ' . $klarna_order_id
					);
				}
			}

			// Fire action for email
			do_action('clubflow_payment_completed', $booking_id, [
				'method'    => 'klarna',
				'order_id'  => $klarna_order_id,
				'amount'    => ($order['order_amount'] ?? 0) / 100,
			]);
		}

		return new \WP_REST_Response(['status' => 'ok'], 200);
	}

	/**
	 * Acknowledge Klarna order
	 */
	private function acknowledge_order(string $order_id): void {
		$this->api_request('/ordermanagement/v1/orders/' . $order_id . '/acknowledge', 'POST');
	}

	/**
	 * Handle confirmation redirect
	 */
	public function handle_confirmation(\WP_REST_Request $request): \WP_REST_Response {
		$booking_id = $request->get_param('booking_id');
		$klarna_order_id = $request->get_param('klarna_order_id');

		// Redirect to a thank you page or back to the booking
		$code = get_post_meta($booking_id, '_clubflow_booking_confirmation_code', true);
		$redirect_url = add_query_arg([
			'booking_confirmed' => '1',
			'booking_id'        => $booking_id,
			'code'              => $code,
		], home_url('/'));

		return new \WP_REST_Response(null, 302, ['Location' => $redirect_url]);
	}

	/**
	 * Shortcode for confirmation page
	 */
	public function shortcode_confirmation(array $atts = []): string {
		if (empty($_GET['booking_confirmed']) || empty($_GET['booking_id'])) {
			return '';
		}

		$booking_id = absint($_GET['booking_id']);
		$confirmation_code = get_post_meta($booking_id, '_clubflow_booking_confirmation_code', true);
		$event_id = (int) get_post_meta($booking_id, '_clubflow_booking_event_id', true);
		$event_title = get_the_title($event_id);
		$event_start = get_post_meta($event_id, '_clubflow_start', true);

		$html = '<div class="clubflow-confirmation">';
		$html .= '<h2>' . esc_html__('Booking Confirmed!', 'clubflow') . ' ✓</h2>';
		$html .= '<p><strong>' . esc_html($event_title) . '</strong></p>';
		if ($event_start) {
			$html .= '<p>' . esc_html(wp_date('l j F Y, H:i', strtotime($event_start))) . '</p>';
		}
		$html .= '<p>' . esc_html__('Confirmation Code:', 'clubflow') . ' <code>' . esc_html($confirmation_code) . '</code></p>';
		$html .= '<p>' . esc_html__('A confirmation email has been sent to you.', 'clubflow') . '</p>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Get Klarna checkout snippet for embedding
	 */
	public static function get_checkout_snippet(int $booking_id): string {
		$klarna = new self();
		
		if (!$klarna->is_configured()) {
			return '<p>' . esc_html__('Payment not available.', 'clubflow') . '</p>';
		}

		// Check if we already have an order
		$existing_order_id = get_post_meta($booking_id, '_clubflow_klarna_order_id', true);
		
		if ($existing_order_id) {
			// Fetch existing order
			$order = $klarna->api_request('/checkout/v3/orders/' . $existing_order_id, 'GET');
			if (!empty($order['html_snippet'])) {
				return $order['html_snippet'];
			}
		}

		// Create new order
		$event_id = (int) get_post_meta($booking_id, '_clubflow_booking_event_id', true);
		$price = get_post_meta($event_id, '_clubflow_price', true);
		$amount = (float) preg_replace('/[^0-9.]/', '', $price);
		$event_title = get_the_title($event_id);

		$result = $klarna->create_checkout_order($booking_id, $amount, $event_title);

		if (!empty($result['html_snippet'])) {
			return $result['html_snippet'];
		}

		return '<p>' . esc_html__('Could not load payment form.', 'clubflow') . '</p>';
	}
}
