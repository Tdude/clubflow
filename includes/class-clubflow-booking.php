<?php
/**
 * Booking CPT and logic for ClubCal Lite
 *
 * @package ClubFlow
 */

if (!defined('ABSPATH')) {
	exit;
}

final class ClubFlow_Booking {
	public const POST_TYPE = 'club_booking';
	public const AJAX_ACTION_BOOK = 'clubflow_book';

	public function register(): void {
		add_action('init', [$this, 'register_booking_cpt']);
		add_action('wp_ajax_' . self::AJAX_ACTION_BOOK, [$this, 'ajax_book']);
		add_action('wp_ajax_nopriv_' . self::AJAX_ACTION_BOOK, [$this, 'ajax_book']);
		add_action('wp_footer', [$this, 'maybe_show_confirmation_toast']);
		
		// Admin enhancements
		add_action('add_meta_boxes', [$this, 'register_bookings_meta_box']);
		add_filter('manage_' . ClubFlow::POST_TYPE . '_posts_columns', [$this, 'add_bookings_column']);
		add_action('manage_' . ClubFlow::POST_TYPE . '_posts_custom_column', [$this, 'render_bookings_column'], 10, 2);
		
		// Booking CPT admin columns
		add_filter('manage_' . self::POST_TYPE . '_posts_columns', [$this, 'booking_admin_columns']);
		add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [$this, 'render_booking_admin_column'], 10, 2);
		add_action('add_meta_boxes', [$this, 'register_booking_details_meta_box']);
		
		// Admin actions for payment confirmation
		add_action('admin_post_clubflow_confirm_payment', [$this, 'handle_confirm_payment']);
	}

	/**
	 * Register the booking custom post type
	 */
	public function register_booking_cpt(): void {
		$labels = [
			'name'               => __('Bookings', 'clubflow'),
			'singular_name'      => __('Booking', 'clubflow'),
			'menu_name'          => __('Bookings', 'clubflow'),
			'add_new'            => __('Add New', 'clubflow'),
			'add_new_item'       => __('Add New Booking', 'clubflow'),
			'edit_item'          => __('Edit Booking', 'clubflow'),
			'view_item'          => __('View Booking', 'clubflow'),
			'all_items'          => __('All Bookings', 'clubflow'),
			'search_items'       => __('Search Bookings', 'clubflow'),
			'not_found'          => __('No bookings found.', 'clubflow'),
			'not_found_in_trash' => __('No bookings found in Trash.', 'clubflow'),
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
			'supports'            => ['title'],
			'menu_icon'           => 'dashicons-tickets-alt',
		];

		register_post_type(self::POST_TYPE, $args);
	}

	/**
	 * Get the number of confirmed bookings for an event
	 */
	public static function get_booking_count(int $event_id): int {
		$args = [
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'meta_query'     => [
				[
					'key'   => '_clubflow_booking_event_id',
					'value' => $event_id,
					'type'  => 'NUMERIC',
				],
				[
					'key'   => '_clubflow_booking_status',
					'value' => ['confirmed', 'pending'],
					'compare' => 'IN',
				],
			],
		];

		$query = new WP_Query($args);
		return $query->found_posts;
	}

	/**
	 * Get spots remaining for an event
	 */
	public static function get_spots_remaining(int $event_id): ?int {
		$max_spots = (int) get_post_meta($event_id, '_clubflow_max_spots', true);
		if ($max_spots <= 0) {
			return null; // Unlimited
		}

		$booked = self::get_booking_count($event_id);
		return max(0, $max_spots - $booked);
	}

	/**
	 * Check if an event is fully booked
	 */
	public static function is_fully_booked(int $event_id): bool {
		$remaining = self::get_spots_remaining($event_id);
		return $remaining !== null && $remaining <= 0;
	}

	/**
	 * Generate a unique confirmation code
	 */
	private function generate_confirmation_code(): string {
		return strtoupper(substr(md5(uniqid(wp_rand(), true)), 0, 8));
	}

	/**
	 * Create a new booking
	 * 
	 * @param int   $event_id   Event post ID
	 * @param array $data       Booking data (name, email, phone, return_url)
	 * @return array Result with success/error
	 */
	public function create_booking(int $event_id, array $data): array {
		// Validate event exists
		$event = get_post($event_id);
		if (!$event || $event->post_type !== ClubFlow::POST_TYPE) {
			return ['success' => false, 'error' => __('Event not found.', 'clubflow')];
		}

		// Check if fully booked
		if (self::is_fully_booked($event_id)) {
			return ['success' => false, 'error' => __('This event is fully booked.', 'clubflow')];
		}

		// Sanitize input
		$name  = sanitize_text_field($data['name'] ?? '');
		$email = sanitize_email($data['email'] ?? '');
		$phone = sanitize_text_field($data['phone'] ?? '');
		$is_member = !empty($data['is_member']);
		$return_url = esc_url_raw($data['return_url'] ?? '');

		if (empty($name) || empty($email)) {
			return ['success' => false, 'error' => __('Name and email are required.', 'clubflow')];
		}

		if (!is_email($email)) {
			return ['success' => false, 'error' => __('Please enter a valid email address.', 'clubflow')];
		}

		// Check for duplicate booking (same email, same event)
		$existing = get_posts([
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'meta_query'     => [
				[
					'key'   => '_clubflow_booking_event_id',
					'value' => $event_id,
					'type'  => 'NUMERIC',
				],
				[
					'key'   => '_clubflow_booking_email',
					'value' => $email,
				],
			],
		]);

		if (!empty($existing)) {
			return ['success' => false, 'error' => __('You have already booked this event.', 'clubflow')];
		}

		// Generate confirmation code
		$confirmation_code = $this->generate_confirmation_code();

		// Create booking post
		$booking_title = sprintf('%s - %s', $name, get_the_title($event_id));
		$booking_id = wp_insert_post([
			'post_type'   => self::POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => $booking_title,
		]);

		if (is_wp_error($booking_id)) {
			return ['success' => false, 'error' => __('Could not create booking.', 'clubflow')];
		}

		// Save booking meta
		update_post_meta($booking_id, '_clubflow_booking_event_id', $event_id);
		update_post_meta($booking_id, '_clubflow_booking_name', $name);
		update_post_meta($booking_id, '_clubflow_booking_email', $email);
		update_post_meta($booking_id, '_clubflow_booking_phone', $phone);
		update_post_meta($booking_id, '_clubflow_booking_is_member', $is_member ? '1' : '0');
		update_post_meta($booking_id, '_clubflow_booking_confirmation_code', $confirmation_code);
		update_post_meta($booking_id, '_clubflow_booking_created', current_time('mysql'));

		// Get event details for response
		$event_title = get_the_title($event_id);
		$event_start = get_post_meta($event_id, '_clubflow_start', true);
		$event_location = get_post_meta($event_id, '_clubflow_location', true);

		// Determine initial booking status based on payment requirements
		$payment_settings = class_exists('ClubFlow_Payment') ? get_option(ClubFlow_Payment::OPTION_KEY, []) : [];
		$price = get_post_meta($event_id, '_clubflow_price', true);
		$member_price = get_post_meta($event_id, '_clubflow_member_price', true);
		
		// Use member price if applicable
		$applicable_price = ($is_member && $member_price !== '') ? $member_price : $price;
		$amount = $applicable_price ? (float) preg_replace('/[^0-9.]/', '', $applicable_price) : 0;
		
		// Store the price used for this booking
		update_post_meta($booking_id, '_clubflow_booking_price', $applicable_price);
		$payment_enabled = !empty($payment_settings['enabled']);
		$payment_required = $payment_enabled && $amount > 0;
		
		// Set initial status: pending_payment if payment required, confirmed if free
		$initial_status = $payment_required ? 'pending_payment' : 'confirmed';
		update_post_meta($booking_id, '_clubflow_booking_status', $initial_status);

		// Get payment settings and create payment request
		$payment_info = null;
		
		// Debug: log payment settings
		error_log('ClubFlow: payment_required=' . ($payment_required ? 'yes' : 'no') . ', method=' . ($payment_settings['payment_method'] ?? 'none') . ', amount=' . $amount);
		
		if ($payment_required) {
				$payment_method = $payment_settings['payment_method'] ?? 'manual';
				
				if ($payment_method === 'stripe' && $amount > 0 && class_exists('ClubFlow_Stripe')) {
					// Check if Stripe is configured
					if (!ClubFlow_Stripe::is_configured()) {
						// Delete the booking - can't proceed without payment
						wp_delete_post($booking_id, true);
						return ['success' => false, 'error' => __('Payment system not configured. Please contact the administrator.', 'clubflow')];
					}
					
					// Use Stripe Checkout (redirect)
					$stripe = new ClubFlow_Stripe();
					$stripe_result = $stripe->create_checkout_session(
						$booking_id,
						$amount,
						$event_title,
						$return_url
					);
					
					// Debug log
					error_log('ClubFlow Stripe result: ' . print_r($stripe_result, true));
					
					if (!empty($stripe_result['success'])) {
						$payment_info = [
							'method'       => 'stripe',
							'amount'       => $amount,
							'currency'     => 'SEK',
							'session_id'   => $stripe_result['session_id'],
							'checkout_url' => $stripe_result['checkout_url'],
						];

						// Log pending payment
						ClubFlow_Payment::log_payment([
							'booking_id' => $booking_id,
							'event_id'   => $event_id,
							'amount'     => $amount,
							'currency'   => 'SEK',
							'method'     => 'stripe',
							'status'     => 'pending',
							'reference'  => $stripe_result['session_id'],
							'email'      => $email,
							'name'       => $name,
							'notes'      => 'Stripe checkout session created',
						]);
					} else {
						// Stripe session creation failed - delete booking and return error
						wp_delete_post($booking_id, true);
						$error_msg = $stripe_result['error'] ?? __('Could not create payment session.', 'clubflow');
						return ['success' => false, 'error' => $error_msg];
					}
				} elseif ($payment_method === 'klarna' && $amount > 0 && class_exists('ClubFlow_Klarna')) {
					// Use Klarna checkout
					$klarna = new ClubFlow_Klarna();
					$klarna_result = $klarna->create_checkout_order(
						$booking_id,
						$amount,
						$event_title
					);
					
					if (!empty($klarna_result['success'])) {
						$payment_info = [
							'method'       => 'klarna',
							'amount'       => $amount,
							'currency'     => 'SEK',
							'order_id'     => $klarna_result['order_id'],
							'html_snippet' => $klarna_result['html_snippet'],
						];

						// Log pending payment
						ClubFlow_Payment::log_payment([
							'booking_id' => $booking_id,
							'event_id'   => $event_id,
							'amount'     => $amount,
							'currency'   => 'SEK',
							'method'     => 'klarna',
							'status'     => 'pending',
							'reference'  => $klarna_result['order_id'],
							'email'      => $email,
							'name'       => $name,
							'notes'      => 'Klarna checkout initiated',
						]);

						// Update booking status
						update_post_meta($booking_id, '_clubflow_booking_status', 'pending_payment');
					}
				} elseif ($payment_method === 'swish' && $amount > 0 && class_exists('ClubFlow_Swish')) {
					// Use Swish
					$swish = new ClubFlow_Swish();
					$swish_result = $swish->create_payment_request(
						$booking_id,
						$amount,
						sprintf(__('Booking %s', 'clubflow'), substr($event_title, 0, 30))
					);
					
					if (!empty($swish_result['success'])) {
						$payment_info = [
							'method'       => 'swish',
							'amount'       => $amount,
							'currency'     => 'SEK',
							'swish_number' => $swish_result['swish_number'],
							'reference'    => $swish_result['payment_reference'],
							'qr_url'       => $swish_result['qr_url'],
							'deep_link'    => $swish_result['deep_link'],
							'check_url'    => $swish_result['check_url'],
						];

						// Log pending payment
						ClubFlow_Payment::log_payment([
							'booking_id' => $booking_id,
							'event_id'   => $event_id,
							'amount'     => $amount,
							'currency'   => 'SEK',
							'method'     => 'swish',
							'status'     => 'pending',
							'reference'  => $swish_result['payment_reference'],
							'email'      => $email,
							'name'       => $name,
							'notes'      => 'Swish payment initiated',
						]);

						// Update booking status if payment required
						if (!empty($payment_settings['require_payment'])) {
							update_post_meta($booking_id, '_clubflow_booking_status', 'pending_payment');
						}
					}
				} elseif ($payment_method === 'manual') {
					$payment_info = [
						'method'  => 'manual',
						'amount'  => $price,
						'message' => __('Pay at the venue', 'clubflow'),
					];
				}
		}

		$result = [
			'success'           => true,
			'booking_id'        => $booking_id,
			'confirmation_code' => $confirmation_code,
			'event_title'       => $event_title,
			'event_start'       => $event_start,
			'event_location'    => $event_location,
			'name'              => $name,
			'email'             => $email,
		];

		if ($payment_info) {
			$result['payment'] = $payment_info;
		}

		// Fire action for other integrations (e.g., Mailchimp)
		do_action('clubflow_booking_created', $booking_id, $result);

		return $result;
	}

	/**
	 * AJAX handler for booking
	 */
	public function ajax_book(): void {
		// Verify nonce
		if (!check_ajax_referer('clubflow_book', '_ajax_nonce', false)) {
			wp_send_json_error(__('Security check failed.', 'clubflow'), 403);
		}

		$event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
		if ($event_id <= 0) {
			wp_send_json_error(__('Invalid event.', 'clubflow'), 400);
		}

		$result = $this->create_booking($event_id, [
			'name'       => $_POST['name'] ?? '',
			'email'      => $_POST['email'] ?? '',
			'phone'      => $_POST['phone'] ?? '',
			'is_member'  => isset($_POST['is_member']) ? $_POST['is_member'] === '1' : false,
			'return_url' => $_POST['return_url'] ?? '',
		]);

		if ($result['success']) {
			wp_send_json_success($result);
		} else {
			wp_send_json_error($result['error'], 400);
		}
	}

	/**
	 * Get all bookings for an event
	 */
	public static function get_event_bookings(int $event_id): array {
		$args = [
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'ASC',
			'meta_query'     => [
				[
					'key'   => '_clubflow_booking_event_id',
					'value' => $event_id,
					'type'  => 'NUMERIC',
				],
			],
		];

		$query = new WP_Query($args);
		$bookings = [];

		foreach ($query->posts as $post) {
			$bookings[] = [
				'id'                => $post->ID,
				'name'              => get_post_meta($post->ID, '_clubflow_booking_name', true),
				'email'             => get_post_meta($post->ID, '_clubflow_booking_email', true),
				'phone'             => get_post_meta($post->ID, '_clubflow_booking_phone', true),
				'status'            => get_post_meta($post->ID, '_clubflow_booking_status', true),
				'confirmation_code' => get_post_meta($post->ID, '_clubflow_booking_confirmation_code', true),
				'created'           => get_post_meta($post->ID, '_clubflow_booking_created', true),
			];
		}

		return $bookings;
	}

	/**
	 * Register bookings meta box on event edit screen
	 */
	public function register_bookings_meta_box(): void {
		add_meta_box(
			'clubflow_event_bookings',
			__('Bookings', 'clubflow'),
			[$this, 'render_bookings_meta_box'],
			ClubFlow::POST_TYPE,
			'normal',
			'default'
		);
	}

	/**
	 * Render bookings meta box
	 */
	public function render_bookings_meta_box(\WP_Post $post): void {
		$bookings = self::get_event_bookings($post->ID);
		$booking_count = count($bookings);
		$max_spots = (int) get_post_meta($post->ID, '_clubflow_max_spots', true);

		if (empty($bookings)) {
			echo '<p>' . esc_html__('No bookings yet.', 'clubflow') . '</p>';
			return;
		}

		echo '<p><strong>' . sprintf(
			esc_html__('%d booking(s)', 'clubflow'),
			$booking_count
		);
		if ($max_spots > 0) {
			echo ' / ' . esc_html($max_spots) . ' ' . esc_html__('spots', 'clubflow');
		}
		echo '</strong></p>';

		echo '<table class="widefat striped" style="margin-top: 10px;">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__('Name', 'clubflow') . '</th>';
		echo '<th>' . esc_html__('Email', 'clubflow') . '</th>';
		echo '<th>' . esc_html__('Phone', 'clubflow') . '</th>';
		echo '<th>' . esc_html__('Code', 'clubflow') . '</th>';
		echo '<th>' . esc_html__('Status', 'clubflow') . '</th>';
		echo '<th>' . esc_html__('Booked', 'clubflow') . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ($bookings as $booking) {
			echo '<tr>';
			echo '<td>' . esc_html($booking['name']) . '</td>';
			echo '<td><a href="mailto:' . esc_attr($booking['email']) . '">' . esc_html($booking['email']) . '</a></td>';
			echo '<td>' . esc_html($booking['phone'] ?: '—') . '</td>';
			echo '<td><code>' . esc_html($booking['confirmation_code']) . '</code></td>';
			echo '<td>' . esc_html(ucfirst($booking['status'])) . '</td>';
			echo '<td>' . esc_html($booking['created'] ? wp_date('Y-m-d H:i', strtotime($booking['created'])) : '—') . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Add bookings column to events list
	 */
	public function add_bookings_column(array $columns): array {
		$new_columns = [];
		foreach ($columns as $key => $value) {
			$new_columns[$key] = $value;
			if ($key === 'title') {
				$new_columns['bookings'] = __('Bookings', 'clubflow');
			}
		}
		return $new_columns;
	}

	/**
	 * Render bookings column
	 */
	public function render_bookings_column(string $column, int $post_id): void {
		if ($column !== 'bookings') {
			return;
		}

		$booking_enabled = get_post_meta($post_id, '_clubflow_booking_enabled', true) === '1';
		if (!$booking_enabled) {
			echo '<span style="color: #999;">—</span>';
			return;
		}

		$count = self::get_booking_count($post_id);
		$max_spots = (int) get_post_meta($post_id, '_clubflow_max_spots', true);

		if ($max_spots > 0) {
			$remaining = max(0, $max_spots - $count);
			$color = $remaining > 0 ? '#2e7d32' : '#c62828';
			echo '<span style="color: ' . esc_attr($color) . '; font-weight: 500;">' . esc_html($count) . '/' . esc_html($max_spots) . '</span>';
		} else {
			echo '<span>' . esc_html($count) . '</span>';
		}
	}

	/**
	 * Booking CPT admin columns
	 */
	public function booking_admin_columns(array $columns): array {
		return [
			'cb'         => $columns['cb'],
			'title'      => __('Booking', 'clubflow'),
			'event'      => __('Event', 'clubflow'),
			'email'      => __('Email', 'clubflow'),
			'phone'      => __('Phone', 'clubflow'),
			'code'       => __('Code', 'clubflow'),
			'status'     => __('Status', 'clubflow'),
			'date'       => __('Date', 'clubflow'),
		];
	}

	/**
	 * Render booking admin column
	 */
	public function render_booking_admin_column(string $column, int $post_id): void {
		switch ($column) {
			case 'event':
				$event_id = (int) get_post_meta($post_id, '_clubflow_booking_event_id', true);
				if ($event_id > 0) {
					$event = get_post($event_id);
					if ($event) {
						echo '<a href="' . esc_url(get_edit_post_link($event_id)) . '">' . esc_html(get_the_title($event_id)) . '</a>';
					} else {
						echo '<span style="color: #999;">' . esc_html__('Deleted', 'clubflow') . '</span>';
					}
				}
				break;
			case 'email':
				$email = get_post_meta($post_id, '_clubflow_booking_email', true);
				echo '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>';
				break;
			case 'phone':
				$phone = get_post_meta($post_id, '_clubflow_booking_phone', true);
				echo esc_html($phone ?: '—');
				break;
			case 'code':
				$code = get_post_meta($post_id, '_clubflow_booking_confirmation_code', true);
				echo '<code>' . esc_html($code) . '</code>';
				break;
			case 'status':
				$status = get_post_meta($post_id, '_clubflow_booking_status', true);
				$color = $status === 'confirmed' ? '#2e7d32' : '#ff9800';
				echo '<span style="color: ' . esc_attr($color) . ';">' . esc_html(ucfirst($status)) . '</span>';
				break;
		}
	}

	/**
	 * Register booking details meta box
	 */
	public function register_booking_details_meta_box(): void {
		add_meta_box(
			'clubflow_booking_details',
			__('Booking Details', 'clubflow'),
			[$this, 'render_booking_details_meta_box'],
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Render booking details meta box
	 */
	public function render_booking_details_meta_box(\WP_Post $post): void {
		$event_id = (int) get_post_meta($post->ID, '_clubflow_booking_event_id', true);
		$name = get_post_meta($post->ID, '_clubflow_booking_name', true);
		$email = get_post_meta($post->ID, '_clubflow_booking_email', true);
		$phone = get_post_meta($post->ID, '_clubflow_booking_phone', true);
		$status = get_post_meta($post->ID, '_clubflow_booking_status', true);
		$code = get_post_meta($post->ID, '_clubflow_booking_confirmation_code', true);
		$created = get_post_meta($post->ID, '_clubflow_booking_created', true);

		echo '<table class="form-table">';
		
		echo '<tr><th>' . esc_html__('Event', 'clubflow') . '</th><td>';
		if ($event_id > 0) {
			$event = get_post($event_id);
			if ($event) {
				echo '<a href="' . esc_url(get_edit_post_link($event_id)) . '">' . esc_html(get_the_title($event_id)) . '</a>';
				$event_start = get_post_meta($event_id, '_clubflow_start', true);
				if ($event_start) {
					echo '<br><small>' . esc_html(wp_date('Y-m-d H:i', strtotime($event_start))) . '</small>';
				}
			} else {
				echo '<span style="color: #c62828;">' . esc_html__('Event deleted', 'clubflow') . '</span>';
			}
		}
		echo '</td></tr>';

		echo '<tr><th>' . esc_html__('Name', 'clubflow') . '</th><td>' . esc_html($name) . '</td></tr>';
		echo '<tr><th>' . esc_html__('Email', 'clubflow') . '</th><td><a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a></td></tr>';
		echo '<tr><th>' . esc_html__('Phone', 'clubflow') . '</th><td>' . esc_html($phone ?: '—') . '</td></tr>';
		
		$is_member = get_post_meta($post->ID, '_clubflow_booking_is_member', true);
		$booking_price = get_post_meta($post->ID, '_clubflow_booking_price', true);
		if ($is_member !== '') {
			$member_label = $is_member === '1' ? __('Member', 'clubflow') : __('Non-member', 'clubflow');
			echo '<tr><th>' . esc_html__('Type', 'clubflow') . '</th><td>' . esc_html($member_label);
			if ($booking_price) {
				echo ' <span style="color: #666;">(' . esc_html($booking_price) . ')</span>';
			}
			echo '</td></tr>';
		}
		
		echo '<tr><th>' . esc_html__('Confirmation Code', 'clubflow') . '</th><td><code style="font-size: 1.1em;">' . esc_html($code) . '</code></td></tr>';
		echo '<tr><th>' . esc_html__('Status', 'clubflow') . '</th><td><strong>' . esc_html(ucfirst(str_replace('_', ' ', $status))) . '</strong></td></tr>';
		echo '<tr><th>' . esc_html__('Booked at', 'clubflow') . '</th><td>' . esc_html($created ? wp_date('Y-m-d H:i:s', strtotime($created)) : '—') . '</td></tr>';

		// Show payment info if exists
		if (class_exists('ClubFlow_Payment')) {
			$payment = ClubFlow_Payment::get_booking_payment($post->ID);
			if ($payment) {
				$payment_status_colors = [
					'pending'   => '#ff9800',
					'completed' => '#2e7d32',
					'failed'    => '#c62828',
				];
				$payment_color = $payment_status_colors[$payment['status']] ?? '#666';
				
				echo '<tr><th>' . esc_html__('Payment', 'clubflow') . '</th><td>';
				echo '<span style="color: ' . esc_attr($payment_color) . '; font-weight: 500;">' . esc_html(ucfirst($payment['status'])) . '</span>';
				echo ' — ' . esc_html($payment['amount']) . ' ' . esc_html($payment['currency']);
				echo ' (' . esc_html(ucfirst($payment['method'])) . ')';
				
				// Show confirm payment button if pending
				if ($payment['status'] === 'pending') {
					$confirm_url = wp_nonce_url(
						admin_url('admin-post.php?action=clubflow_confirm_payment&booking_id=' . $post->ID),
						'clubflow_confirm_payment_' . $post->ID
					);
					echo '<br><a href="' . esc_url($confirm_url) . '" class="button button-small" style="margin-top: 8px;" onclick="return confirm(\'' . esc_js(__('Confirm this payment as received?', 'clubflow')) . '\');">';
					echo esc_html__('✓ Confirm Payment Received', 'clubflow');
					echo '</a>';
				}
				
				echo '</td></tr>';
			}
		}

		echo '</table>';
	}

	/**
	 * Handle payment confirmation from admin
	 */
	public function handle_confirm_payment(): void {
		$booking_id = isset($_GET['booking_id']) ? absint($_GET['booking_id']) : 0;
		
		if (!$booking_id || !current_user_can('edit_posts')) {
			wp_die(__('Unauthorized', 'clubflow'));
		}

		check_admin_referer('clubflow_confirm_payment_' . $booking_id);

		// Update payment status
		if (class_exists('ClubFlow_Payment')) {
			$payment = ClubFlow_Payment::get_booking_payment($booking_id);
			if ($payment) {
				ClubFlow_Payment::update_payment_status(
					$payment['id'],
					'completed',
					sprintf(__('Payment confirmed manually by %s', 'clubflow'), wp_get_current_user()->display_name)
				);
			}
		}

		// Update booking status
		$current_status = get_post_meta($booking_id, '_clubflow_booking_status', true);
		if ($current_status === 'pending_payment') {
			update_post_meta($booking_id, '_clubflow_booking_status', 'confirmed');
			
			// Fire action for email confirmation
			do_action('clubflow_payment_completed', $booking_id, [
				'status' => 'completed',
			]);
		}

		// Redirect back
		wp_redirect(get_edit_post_link($booking_id, 'raw'));
		exit;
	}

	/**
	 * Show confirmation toast when returning from payment
	 */
	public function maybe_show_confirmation_toast(): void {
		if (is_admin()) {
			return;
		}

		$confirmed = isset($_GET['booking_confirmed']) ? sanitize_text_field($_GET['booking_confirmed']) : '';
		$code = isset($_GET['code']) ? sanitize_text_field($_GET['code']) : '';

		if ($confirmed !== '1' || empty($code)) {
			return;
		}

		$booking_text = esc_html__('Bokning bekräftad!', 'clubflow');
		$code_text = esc_html__('Bekräftelsekod:', 'clubflow');
		$code_escaped = esc_html($code);

		// Styles are in style.css
		?>
		<div class="clubflow-confirmation-toast" id="clubflow-confirmation-toast">
			<div class="clubflow-toast-content">
				<span class="clubflow-toast-icon">✓</span>
				<div class="clubflow-toast-text">
					<strong><?php echo $booking_text; ?></strong><br>
					<?php echo $code_text; ?> <code><?php echo $code_escaped; ?></code>
				</div>
				<button class="clubflow-toast-close" onclick="this.parentNode.parentNode.remove()">×</button>
			</div>
		</div>
		<script>
		(function() {
			// Clean URL
			if (window.history && window.history.replaceState) {
				window.history.replaceState({}, document.title, window.location.pathname);
			}
			// Auto-hide after 10 seconds
			setTimeout(function() {
				var t = document.getElementById('clubflow-confirmation-toast');
				if (t) t.remove();
			}, 10000);
		})();
		</script>
		<?php
	}
}
