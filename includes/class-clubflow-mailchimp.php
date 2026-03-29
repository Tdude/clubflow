<?php
/**
 * Mailchimp integration for ClubCal Lite
 * Handles booking confirmation emails and subscriber management
 *
 * @package ClubFlow
 */

if (!defined('ABSPATH')) {
	exit;
}

final class ClubFlow_Mailchimp {
	private array $settings;

	public function __construct() {
		$this->settings = $this->get_settings();
	}

	public function register(): void {
		// Hook into booking creation
		add_action('clubflow_booking_created', [$this, 'on_booking_created'], 10, 2);
		add_action('clubflow_payment_completed', [$this, 'on_payment_completed'], 10, 2);
	}

	/**
	 * Get Mailchimp settings from payment settings
	 */
	private function get_settings(): array {
		$payment_settings = get_option(ClubFlow_Payment::OPTION_KEY, []);
		return [
			'enabled'    => !empty($payment_settings['mailchimp_enabled']),
			'api_key'    => $payment_settings['mailchimp_api_key'] ?? '',
			'list_id'    => $payment_settings['mailchimp_list_id'] ?? '',
		];
	}

	/**
	 * Check if Mailchimp is properly configured
	 */
	public function is_configured(): bool {
		return $this->settings['enabled'] && !empty($this->settings['api_key']);
	}

	/**
	 * Get API base URL from API key
	 */
	private function get_api_base(): string {
		$api_key = $this->settings['api_key'];
		if (empty($api_key) || strpos($api_key, '-') === false) {
			return '';
		}
		
		$dc = substr($api_key, strpos($api_key, '-') + 1);
		return 'https://' . $dc . '.api.mailchimp.com/3.0';
	}

	/**
	 * Make API request to Mailchimp
	 */
	private function api_request(string $endpoint, string $method = 'GET', array $data = []): array {
		if (!$this->is_configured()) {
			return ['error' => 'Mailchimp not configured'];
		}

		$url = $this->get_api_base() . $endpoint;
		
		$args = [
			'method'  => $method,
			'headers' => [
				'Authorization' => 'Basic ' . base64_encode('user:' . $this->settings['api_key']),
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
				'error' => $decoded['detail'] ?? 'API error',
				'code'  => $code,
			];
		}

		return $decoded ?: [];
	}

	/**
	 * Add or update subscriber
	 */
	public function add_subscriber(string $email, array $merge_fields = [], array $tags = []): array {
		if (!$this->is_configured() || empty($this->settings['list_id'])) {
			return ['error' => 'List ID not configured'];
		}

		$subscriber_hash = md5(strtolower($email));
		$endpoint = '/lists/' . $this->settings['list_id'] . '/members/' . $subscriber_hash;

		$data = [
			'email_address' => $email,
			'status_if_new' => 'subscribed',
		];

		if (!empty($merge_fields)) {
			$data['merge_fields'] = $merge_fields;
		}

		if (!empty($tags)) {
			$data['tags'] = $tags;
		}

		return $this->api_request($endpoint, 'PUT', $data);
	}

	/**
	 * Send transactional email (requires Mandrill/Transactional add-on)
	 * For now, this is a placeholder - basic Mailchimp doesn't support transactional emails
	 * We'll use wp_mail as fallback
	 */
	public function send_booking_confirmation(int $booking_id, array $booking_data): bool {
		// Get event details
		$event_id = (int) get_post_meta($booking_id, '_clubflow_booking_event_id', true);
		$event_title = get_the_title($event_id);
		$event_start = get_post_meta($event_id, '_clubflow_start', true);
		$event_location = get_post_meta($event_id, '_clubflow_location', true);

		$to = $booking_data['email'];
		$name = $booking_data['name'];
		$confirmation_code = $booking_data['confirmation_code'] ?? get_post_meta($booking_id, '_clubflow_booking_confirmation_code', true);
		$discount_code = (string) ($booking_data['discount_code'] ?? get_post_meta($booking_id, '_clubflow_booking_discount_code', true));
		$discount_percent = (string) ($booking_data['discount_percent'] ?? get_post_meta($booking_id, '_clubflow_booking_discount_percent', true));

		// Format date
		$date_formatted = $event_start ? wp_date('l j F Y, H:i', strtotime($event_start)) : '';

		$subject = sprintf(__('Booking Confirmed: %s', 'clubflow'), $event_title);

		// Build email body
		$body = sprintf(__('Hi %s,', 'clubflow'), $name) . "\n\n";
		$body .= __('Your booking has been confirmed!', 'clubflow') . "\n\n";
		$body .= "---\n\n";
		$body .= sprintf(__('Event: %s', 'clubflow'), $event_title) . "\n";
		if ($date_formatted) {
			$body .= sprintf(__('Date: %s', 'clubflow'), $date_formatted) . "\n";
		}
		if ($event_location) {
			$body .= sprintf(__('Location: %s', 'clubflow'), $event_location) . "\n";
		}
		$body .= sprintf(__('Confirmation Code: %s', 'clubflow'), $confirmation_code) . "\n\n";
		if ($discount_code !== '' || $discount_percent !== '') {
			$disc = '';
			if ($discount_percent !== '') {
				$disc .= $discount_percent . '%';
			}
			if ($discount_code !== '') {
				$disc .= ($disc !== '' ? ' ' : '') . $discount_code;
			}
			$body .= sprintf(__('Discount: %s', 'clubflow'), $disc) . "\n\n";
		}
		$body .= "---\n\n";
		$body .= __('See you there!', 'clubflow') . "\n";
		$body .= get_bloginfo('name');

		// Add subscriber to list if configured
		if ($this->is_configured() && !empty($this->settings['list_id'])) {
			$this->add_subscriber($to, [
				'FNAME' => $name,
			], ['booking-' . $event_id]);
		}

		// Send via wp_mail (Mailchimp transactional requires Mandrill)
		$headers = ['Content-Type: text/plain; charset=UTF-8'];
		
		$from_name = get_bloginfo('name');
		$from_email = get_option('admin_email');
		$headers[] = 'From: ' . $from_name . ' <' . $from_email . '>';

		$sent = wp_mail($to, $subject, $body, $headers);

		// Log the email attempt
		if (class_exists('ClubFlow_Payment')) {
			ClubFlow_Payment::log_payment([
				'booking_id' => $booking_id,
				'event_id'   => $event_id,
				'amount'     => '0',
				'method'     => 'email',
				'status'     => $sent ? 'completed' : 'failed',
				'email'      => $to,
				'name'       => $name,
				'notes'      => $sent ? 'Confirmation email sent' : 'Failed to send confirmation email',
			]);
		}

		return $sent;
	}

	/**
	 * Hook: On booking created
	 */
	public function on_booking_created(int $booking_id, array $booking_data): void {
		// Check if we should send immediately or wait for payment
		$payment_settings = get_option(ClubFlow_Payment::OPTION_KEY, []);
		$require_payment = !empty($payment_settings['require_payment']) && !empty($payment_settings['enabled']);

		if (!$require_payment) {
			// Send confirmation immediately
			$this->send_booking_confirmation($booking_id, $booking_data);
		}
	}

	/**
	 * Hook: On payment completed
	 */
	public function on_payment_completed(int $booking_id, array $payment_data): void {
		// Send confirmation after payment is complete
		$booking_data = [
			'email' => get_post_meta($booking_id, '_clubflow_booking_email', true),
			'name'  => get_post_meta($booking_id, '_clubflow_booking_name', true),
		];

		$this->send_booking_confirmation($booking_id, $booking_data);
	}

	/**
	 * Test API connection
	 */
	public function test_connection(): array {
		if (!$this->is_configured()) {
			return ['error' => 'Mailchimp not configured'];
		}

		return $this->api_request('/ping');
	}
}
