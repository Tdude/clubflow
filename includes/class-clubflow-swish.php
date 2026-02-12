<?php
/**
 * Swish Payment Integration for ClubCal Lite
 * Handles Swish Commerce API, QR codes, callbacks
 *
 * @package ClubFlow
 */

if (!defined('ABSPATH')) {
	exit;
}

final class ClubFlow_Swish {
	public const AJAX_CREATE_PAYMENT = 'clubflow_swish_create';
	public const AJAX_CHECK_PAYMENT = 'clubflow_swish_check';
	public const REST_CALLBACK = 'clubflow/v1/swish-callback';

	private array $settings;
	private bool $is_test_mode = false;

	// Swish API endpoints
	private const API_PROD = 'https://cpc.getswish.net/swish-cpcapi/api/v2';
	private const API_TEST = 'https://mss.cpc.getswish.net/swish-cpcapi/api/v2';

	public function __construct() {
		$this->settings = $this->get_settings();
		$this->is_test_mode = !empty($this->settings['swish_test_mode']);
	}

	public function register(): void {
		// AJAX endpoints
		add_action('wp_ajax_' . self::AJAX_CREATE_PAYMENT, [$this, 'ajax_create_payment']);
		add_action('wp_ajax_nopriv_' . self::AJAX_CREATE_PAYMENT, [$this, 'ajax_create_payment']);
		add_action('wp_ajax_' . self::AJAX_CHECK_PAYMENT, [$this, 'ajax_check_payment']);
		add_action('wp_ajax_nopriv_' . self::AJAX_CHECK_PAYMENT, [$this, 'ajax_check_payment']);

		// REST API callback for Swish
		add_action('rest_api_init', [$this, 'register_rest_routes']);
	}

	/**
	 * Get Swish settings
	 */
	private function get_settings(): array {
		$payment_settings = get_option(ClubFlow_Payment::OPTION_KEY, []);
		return [
			'enabled'           => !empty($payment_settings['enabled']) && ($payment_settings['payment_method'] ?? '') === 'swish',
			'swish_number'      => $payment_settings['swish_number'] ?? '',
			'swish_payee_alias' => $payment_settings['swish_payee_alias'] ?? '',
			'swish_cert_path'   => $payment_settings['swish_cert_path'] ?? '',
			'swish_cert_pass'   => $payment_settings['swish_cert_pass'] ?? '',
			'swish_test_mode'   => $payment_settings['swish_test_mode'] ?? false,
		];
	}

	/**
	 * Check if Swish API is properly configured
	 */
	public function is_api_configured(): bool {
		return !empty($this->settings['swish_payee_alias']) && 
		       !empty($this->settings['swish_cert_path']) &&
		       file_exists($this->settings['swish_cert_path']);
	}

	/**
	 * Get API base URL
	 */
	private function get_api_url(): string {
		return $this->is_test_mode ? self::API_TEST : self::API_PROD;
	}

	/**
	 * Register REST API routes for Swish callback
	 */
	public function register_rest_routes(): void {
		register_rest_route('clubflow/v1', '/swish-callback', [
			'methods'             => 'POST',
			'callback'            => [$this, 'handle_callback'],
			'permission_callback' => '__return_true', // Swish needs to access this
		]);
	}

	/**
	 * Generate unique payment reference
	 */
	private function generate_reference(): string {
		// Swish requires UUID format for instructionUUID
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			mt_rand(0, 0xffff), mt_rand(0, 0xffff),
			mt_rand(0, 0xffff),
			mt_rand(0, 0x0fff) | 0x4000,
			mt_rand(0, 0x3fff) | 0x8000,
			mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
		);
	}

	/**
	 * Create Swish payment request via API
	 */
	public function create_payment_request(int $booking_id, float $amount, string $message = ''): array {
		$payment_reference = $this->generate_reference();
		$callback_url = rest_url(self::REST_CALLBACK);

		// If API is configured, use it
		if ($this->is_api_configured()) {
			$result = $this->api_create_payment($payment_reference, $amount, $message, $callback_url);
			if (!empty($result['error'])) {
				return $result;
			}
		}

		// Store payment request in database
		$payment_data = [
			'booking_id'        => $booking_id,
			'payment_reference' => $payment_reference,
			'amount'            => $amount,
			'message'           => $message,
			'status'            => 'CREATED',
			'created_at'        => current_time('mysql'),
		];

		// Save to transient for quick lookup (expires in 15 min)
		set_transient('clubflow_swish_' . $payment_reference, $payment_data, 15 * MINUTE_IN_SECONDS);

		// Also save reference to booking meta
		update_post_meta($booking_id, '_clubflow_swish_reference', $payment_reference);
		update_post_meta($booking_id, '_clubflow_swish_status', 'CREATED');

		// Generate QR code data and deep link
		$swish_number = preg_replace('/[^0-9]/', '', $this->settings['swish_number']);
		$payee_alias = $this->settings['swish_payee_alias'] ?: $swish_number;

		// Swish QR format (C-format for static QR)
		$qr_data = $this->generate_qr_payload($payee_alias, $amount, $message, $payment_reference);
		$qr_url = $this->generate_qr_image_url($qr_data);

		// Swish deep link for mobile
		$deep_link = $this->generate_deep_link($payee_alias, $amount, $message, $payment_reference);

		return [
			'success'           => true,
			'payment_reference' => $payment_reference,
			'amount'            => $amount,
			'message'           => $message,
			'swish_number'      => $this->settings['swish_number'],
			'qr_data'           => $qr_data,
			'qr_url'            => $qr_url,
			'deep_link'         => $deep_link,
			'check_url'         => admin_url('admin-ajax.php') . '?' . http_build_query([
				'action'    => self::AJAX_CHECK_PAYMENT,
				'reference' => $payment_reference,
				'_ajax_nonce' => wp_create_nonce('clubflow_swish_check_' . $payment_reference),
			]),
		];
	}

	/**
	 * Generate Swish QR payload (BankID format)
	 */
	private function generate_qr_payload(string $payee, float $amount, string $message, string $reference): string {
		// Swish QR format for payment
		// Format: C{payee};{amount};{message};{editable}
		// editable: 0 = fixed amount, 1 = editable
		$formatted_amount = number_format($amount, 2, '.', '');
		return sprintf('C%s;%s;%s;0', $payee, $formatted_amount, substr($message, 0, 50));
	}

	/**
	 * Generate QR code image URL using external service
	 */
	private function generate_qr_image_url(string $data, int $size = 250): string {
		// Using QR Server API (free, no API key required)
		return 'https://api.qrserver.com/v1/create-qr-code/?' . http_build_query([
			'data'   => $data,
			'size'   => $size . 'x' . $size,
			'format' => 'png',
			'margin' => 10,
		]);
	}

	/**
	 * Generate Swish deep link for mobile
	 */
	private function generate_deep_link(string $payee, float $amount, string $message, string $reference): string {
		$formatted_amount = number_format($amount, 2, '.', '');
		
		// Swish URL scheme
		$params = [
			'data' => json_encode([
				'version' => 1,
				'payee'   => ['value' => $payee],
				'amount'  => ['value' => (float) $formatted_amount],
				'message' => ['value' => substr($message, 0, 50)],
			]),
			'callbackurl' => '', // Return to site after payment
		];

		return 'swish://payment?' . http_build_query($params);
	}

	/**
	 * API: Create payment request (if using Swish Commerce API)
	 */
	private function api_create_payment(string $reference, float $amount, string $message, string $callback_url): array {
		if (!$this->is_api_configured()) {
			return ['error' => 'Swish API not configured'];
		}

		$endpoint = $this->get_api_url() . '/paymentrequests/' . strtoupper($reference);

		$payload = [
			'payeePaymentReference' => $reference,
			'callbackUrl'           => $callback_url,
			'payeeAlias'            => $this->settings['swish_payee_alias'],
			'amount'                => number_format($amount, 2, '.', ''),
			'currency'              => 'SEK',
			'message'               => substr($message, 0, 50),
		];

		$response = wp_remote_request($endpoint, [
			'method'    => 'PUT',
			'headers'   => ['Content-Type' => 'application/json'],
			'body'      => json_encode($payload),
			'sslcertificates' => $this->settings['swish_cert_path'],
			'timeout'   => 30,
		]);

		if (is_wp_error($response)) {
			return ['error' => $response->get_error_message()];
		}

		$code = wp_remote_retrieve_response_code($response);
		if ($code >= 400) {
			$body = json_decode(wp_remote_retrieve_body($response), true);
			return ['error' => $body[0]['errorMessage'] ?? 'API error', 'code' => $code];
		}

		return ['success' => true];
	}

	/**
	 * AJAX: Create payment request
	 */
	public function ajax_create_payment(): void {
		if (!check_ajax_referer('clubflow_swish_create', '_ajax_nonce', false)) {
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
		$message = sprintf(__('Booking %s', 'clubflow'), substr($event_title, 0, 30));

		$result = $this->create_payment_request($booking_id, $amount, $message);

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
				'method'     => 'swish',
				'status'     => 'pending',
				'reference'  => $result['payment_reference'],
				'email'      => get_post_meta($booking_id, '_clubflow_booking_email', true),
				'name'       => get_post_meta($booking_id, '_clubflow_booking_name', true),
				'notes'      => 'Swish payment initiated',
			]);
		}

		wp_send_json_success($result);
	}

	/**
	 * AJAX: Check payment status
	 */
	public function ajax_check_payment(): void {
		$reference = isset($_GET['reference']) ? sanitize_text_field($_GET['reference']) : '';
		
		if (!$reference || !check_ajax_referer('clubflow_swish_check_' . $reference, '_ajax_nonce', false)) {
			wp_send_json_error(__('Invalid request.', 'clubflow'), 400);
		}

		// Check transient first
		$payment_data = get_transient('clubflow_swish_' . $reference);
		
		if (!$payment_data) {
			// Fallback to meta lookup
			$args = [
				'post_type'      => ClubFlow_Booking::POST_TYPE,
				'posts_per_page' => 1,
				'meta_query'     => [
					[
						'key'   => '_clubflow_swish_reference',
						'value' => $reference,
					],
				],
			];
			$query = new WP_Query($args);
			
			if ($query->have_posts()) {
				$booking_id = $query->posts[0]->ID;
				$status = get_post_meta($booking_id, '_clubflow_swish_status', true);
			} else {
				wp_send_json_error(__('Payment not found.', 'clubflow'), 404);
				return;
			}
		} else {
			$status = $payment_data['status'] ?? 'CREATED';
			$booking_id = $payment_data['booking_id'] ?? 0;
		}

		wp_send_json_success([
			'status'     => $status,
			'booking_id' => $booking_id,
			'paid'       => $status === 'PAID',
		]);
	}

	/**
	 * REST API: Handle Swish callback
	 */
	public function handle_callback(\WP_REST_Request $request): \WP_REST_Response {
		$body = $request->get_json_params();

		$reference = $body['payeePaymentReference'] ?? $body['instructionUUID'] ?? '';
		$status = $body['status'] ?? '';
		$amount = $body['amount'] ?? '';

		if (empty($reference)) {
			return new \WP_REST_Response(['error' => 'Missing reference'], 400);
		}

		// Log callback
		error_log('Swish callback received: ' . json_encode($body));

		// Find booking by reference
		$args = [
			'post_type'      => ClubFlow_Booking::POST_TYPE,
			'posts_per_page' => 1,
			'meta_query'     => [
				[
					'key'   => '_clubflow_swish_reference',
					'value' => $reference,
				],
			],
		];
		$query = new WP_Query($args);

		if (!$query->have_posts()) {
			return new \WP_REST_Response(['error' => 'Booking not found'], 404);
		}

		$booking_id = $query->posts[0]->ID;

		// Update status
		update_post_meta($booking_id, '_clubflow_swish_status', $status);

		// Update transient
		$payment_data = get_transient('clubflow_swish_' . $reference);
		if ($payment_data) {
			$payment_data['status'] = $status;
			set_transient('clubflow_swish_' . $reference, $payment_data, 15 * MINUTE_IN_SECONDS);
		}

		if ($status === 'PAID') {
			// Payment successful!
			update_post_meta($booking_id, '_clubflow_booking_status', 'confirmed');

			// Update payment log
			if (class_exists('ClubFlow_Payment')) {
				$payment = ClubFlow_Payment::get_booking_payment($booking_id);
				if ($payment) {
					ClubFlow_Payment::update_payment_status(
						$payment['id'],
						'completed',
						sprintf('Swish payment confirmed. Amount: %s SEK', $amount)
					);
				}
			}

			// Fire action for email
			do_action('clubflow_payment_completed', $booking_id, [
				'method'    => 'swish',
				'amount'    => $amount,
				'reference' => $reference,
			]);
		} elseif (in_array($status, ['DECLINED', 'ERROR', 'CANCELLED'])) {
			// Payment failed
			if (class_exists('ClubFlow_Payment')) {
				$payment = ClubFlow_Payment::get_booking_payment($booking_id);
				if ($payment) {
					ClubFlow_Payment::update_payment_status(
						$payment['id'],
						'failed',
						sprintf('Swish payment %s', strtolower($status))
					);
				}
			}
		}

		return new \WP_REST_Response(['status' => 'ok'], 200);
	}

	/**
	 * Simulate payment completion (for testing without Swish API)
	 */
	public function simulate_payment_complete(string $reference): bool {
		// Find booking
		$args = [
			'post_type'      => ClubFlow_Booking::POST_TYPE,
			'posts_per_page' => 1,
			'meta_query'     => [
				[
					'key'   => '_clubflow_swish_reference',
					'value' => $reference,
				],
			],
		];
		$query = new WP_Query($args);

		if (!$query->have_posts()) {
			return false;
		}

		$booking_id = $query->posts[0]->ID;

		// Update status
		update_post_meta($booking_id, '_clubflow_swish_status', 'PAID');
		update_post_meta($booking_id, '_clubflow_booking_status', 'confirmed');

		// Update transient
		$payment_data = get_transient('clubflow_swish_' . $reference);
		if ($payment_data) {
			$payment_data['status'] = 'PAID';
			set_transient('clubflow_swish_' . $reference, $payment_data, 15 * MINUTE_IN_SECONDS);
		}

		// Update payment log
		if (class_exists('ClubFlow_Payment')) {
			$payment = ClubFlow_Payment::get_booking_payment($booking_id);
			if ($payment) {
				ClubFlow_Payment::update_payment_status(
					$payment['id'],
					'completed',
					'Payment simulated/confirmed manually'
				);
			}
		}

		// Fire action
		do_action('clubflow_payment_completed', $booking_id, [
			'method'    => 'swish',
			'reference' => $reference,
		]);

		return true;
	}
}
