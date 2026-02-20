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
	private const META_KLIPPKORT_CODE = '_clubflow_klippkort_code';
	private const META_KLIPPKORT_CREDITS_TOTAL = '_clubflow_klippkort_credits_total';
	private const META_KLIPPKORT_CREDITS_REMAINING = '_clubflow_klippkort_credits_remaining';
	private const META_KLIPPKORT_ISSUED = '_clubflow_klippkort_issued';
	private const META_USED_KLIPPKORT_CODE = '_clubflow_klippkort_used_code';
	private const META_USED_KLIPPKORT_PURCHASE_BOOKING_ID = '_clubflow_klippkort_purchase_booking_id';

	public function register(): void {
		add_action('init', [$this, 'register_booking_cpt']);
		add_action('wp_ajax_' . self::AJAX_ACTION_BOOK, [$this, 'ajax_book']);
		add_action('wp_ajax_nopriv_' . self::AJAX_ACTION_BOOK, [$this, 'ajax_book']);
		add_action('add_meta_boxes', [$this, 'register_bookings_meta_box']);
		add_filter('manage_' . ClubFlow::POST_TYPE . '_posts_columns', [$this, 'add_bookings_column']);
		add_action('manage_' . ClubFlow::POST_TYPE . '_posts_custom_column', [$this, 'render_bookings_column'], 10, 2);
		add_filter('manage_' . self::POST_TYPE . '_posts_columns', [$this, 'booking_admin_columns']);
		add_filter('manage_edit-' . self::POST_TYPE . '_sortable_columns', [$this, 'booking_admin_sortable_columns']);
		add_action('pre_get_posts', [$this, 'maybe_apply_booking_admin_sorting']);
		add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [$this, 'render_booking_admin_column'], 10, 2);
		add_action('add_meta_boxes_' . self::POST_TYPE, [$this, 'register_booking_details_meta_box']);
		add_action('admin_post_clubflow_confirm_payment', [$this, 'handle_confirm_payment']);
		add_action('admin_post_clubflow_view_receipt', [$this, 'handle_view_receipt']);
		add_action('manage_posts_extra_tablenav', [$this, 'render_receipt_controls']);
		add_action('wp_footer', [$this, 'maybe_show_confirmation_toast']);
		add_action('clubflow_payment_completed', [$this, 'maybe_issue_klippkort_from_booking'], 10, 2);
		add_action('clubflow_booking_payment_confirmed', [$this, 'maybe_issue_klippkort_from_stripe_booking'], 10, 3);
	}

	private static function normalize_code(string $code): string {
		$code = strtoupper(trim($code));
		$code = preg_replace('/[^A-Z0-9\-]/', '', $code) ?: '';
		return $code;
	}

	private static function generate_klippkort_code(): string {
		$rand = strtoupper(wp_generate_password(6, false, false));
		return 'KLIPPKORT-' . $rand;
	}

	private static function ensure_unique_klippkort_code(string $preferred = ''): string {
		$preferred = self::normalize_code($preferred);
		if ($preferred !== '') {
			$existing = get_posts([
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => [
					[
						'key'   => self::META_KLIPPKORT_CODE,
						'value' => $preferred,
					],
				],
			]);
			if (empty($existing)) {
				return $preferred;
			}
		}

		for ($i = 0; $i < 20; $i++) {
			$candidate = self::generate_klippkort_code();
			$existing = get_posts([
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => [
					[
						'key'   => self::META_KLIPPKORT_CODE,
						'value' => $candidate,
					],
				],
			]);
			if (empty($existing)) {
				return $candidate;
			}
		}

		return self::generate_klippkort_code();
	}

	private static function get_event_mode(int $event_id): string {
		$mode = get_post_meta($event_id, '_clubflow_event_mode', true) ?: 'calendar';
		return in_array($mode, ['calendar', 'product', 'package'], true) ? $mode : 'calendar';
	}

	public function maybe_issue_klippkort_from_stripe_booking(int $booking_id, string $method, array $session): void {
		$this->maybe_issue_klippkort_from_booking($booking_id, [
			'method' => $method,
			'session' => $session,
		]);
	}

	public function maybe_issue_klippkort_from_booking(int $booking_id, array $context = []): void {
		$booking_id = absint($booking_id);
		if ($booking_id <= 0 || get_post_type($booking_id) !== self::POST_TYPE) {
			return;
		}

		$issued = get_post_meta($booking_id, self::META_KLIPPKORT_ISSUED, true);
		if ($issued === '1') {
			return;
		}

		$booking_status = (string) get_post_meta($booking_id, '_clubflow_booking_status', true);
		if ($booking_status !== 'confirmed') {
			return;
		}

		$event_id = (int) get_post_meta($booking_id, '_clubflow_booking_event_id', true);
		if ($event_id <= 0) {
			return;
		}

		if (self::get_event_mode($event_id) !== 'package') {
			return;
		}

		$credits_total = (int) get_post_meta($event_id, '_clubflow_klippkort_credits', true);
		if ($credits_total <= 0) {
			return;
		}

		$existing_code = (string) get_post_meta($booking_id, self::META_KLIPPKORT_CODE, true);
		$existing_code = self::normalize_code($existing_code);
		if ($existing_code === '') {
			$existing_code = self::ensure_unique_klippkort_code();
			update_post_meta($booking_id, self::META_KLIPPKORT_CODE, $existing_code);
		}

		update_post_meta($booking_id, self::META_KLIPPKORT_CREDITS_TOTAL, (string) $credits_total);
		$remaining = get_post_meta($booking_id, self::META_KLIPPKORT_CREDITS_REMAINING, true);
		if ($remaining === '') {
			update_post_meta($booking_id, self::META_KLIPPKORT_CREDITS_REMAINING, (string) $credits_total);
		}
		update_post_meta($booking_id, self::META_KLIPPKORT_ISSUED, '1');

		if (class_exists('ClubFlow_Payment')) {
			$customer_email = (string) get_post_meta($booking_id, '_clubflow_booking_email', true);
			$customer_name = (string) get_post_meta($booking_id, '_clubflow_booking_name', true);
			$booking_price = (string) get_post_meta($booking_id, '_clubflow_booking_price', true);
			$amount = $booking_price !== '' ? (float) preg_replace('/[^0-9.]/', '', $booking_price) : 0;
			$method = isset($context['method']) ? sanitize_text_field((string) $context['method']) : '';
			if ($method === '') {
				$payment = ClubFlow_Payment::get_booking_payment($booking_id);
				$method = is_array($payment) ? (string) ($payment['method'] ?? '') : '';
			}
			$log_id = ClubFlow_Payment::log_payment([
				'booking_id' => $booking_id,
				'event_id'   => $event_id,
				'amount'     => $amount,
				'currency'   => 'SEK',
				'method'     => $method !== '' ? $method : 'manual',
				'status'     => 'completed',
				'reference'  => $existing_code,
				'email'      => $customer_email,
				'name'       => $customer_name,
				'notes'      => 'KLIPPKORT ISSUED: ' . $existing_code . ', credits=' . $credits_total,
			]);
			if ($log_id > 0) {
				update_post_meta($log_id, '_clubflow_klippkort_action', 'issued');
				update_post_meta($log_id, '_clubflow_klippkort_code', $existing_code);
				update_post_meta($log_id, '_clubflow_klippkort_purchase_booking_id', (string) $booking_id);
			}
		}
	}

	private static function find_klippkort_purchase_booking_id(string $email, string $code = ''): int {
		$email = sanitize_email($email);
		$code = self::normalize_code($code);
		if ($email === '') {
			return 0;
		}

		$meta_query = [
			'relation' => 'AND',
			[
				'key'   => '_clubflow_booking_status',
				'value' => ['confirmed', 'pending_payment'],
				'compare' => 'IN',
			],
			// Accept both issued klippkort and legacy/partially-initialized purchases
			[
				'relation' => 'OR',
				[
					'key'   => self::META_KLIPPKORT_ISSUED,
					'value' => '1',
				],
				[
					'key'     => self::META_KLIPPKORT_ISSUED,
					'compare' => 'NOT EXISTS',
				],
			],
			[
				'key'   => '_clubflow_booking_email',
				'value' => $email,
			],
			// Require at least some credits (remaining preferred, but total covers partially initialized purchases)
			[
				'relation' => 'OR',
				[
					'key'     => self::META_KLIPPKORT_CREDITS_REMAINING,
					'value'   => 1,
					'compare' => '>=',
					'type'    => 'NUMERIC',
				],
				[
					'key'     => self::META_KLIPPKORT_CREDITS_TOTAL,
					'value'   => 1,
					'compare' => '>=',
					'type'    => 'NUMERIC',
				],
			],
		];
		if ($code !== '') {
			$meta_query[] = [
				'key'   => self::META_KLIPPKORT_CODE,
				'value' => $code,
			];
		}

		$purchase_booking_ids = get_posts([
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'meta_query'     => $meta_query,
		]);

		if (empty($purchase_booking_ids)) {
			return 0;
		}

		return (int) $purchase_booking_ids[0];
	}

	private static function redeem_one_klippkort_credit(int $purchase_booking_id, int $redeem_booking_id = 0): bool {
		$purchase_booking_id = absint($purchase_booking_id);
		if ($purchase_booking_id <= 0) {
			return false;
		}

		$remaining = (int) get_post_meta($purchase_booking_id, self::META_KLIPPKORT_CREDITS_REMAINING, true);
		if ($remaining <= 0) {
			return false;
		}

		$next_remaining = (string) max(0, $remaining - 1);
		update_post_meta($purchase_booking_id, self::META_KLIPPKORT_CREDITS_REMAINING, $next_remaining);

		if ($redeem_booking_id > 0 && class_exists('ClubFlow_Payment')) {
			$redeem_booking_id = absint($redeem_booking_id);
			$event_id = (int) get_post_meta($redeem_booking_id, '_clubflow_booking_event_id', true);
			$customer_email = (string) get_post_meta($redeem_booking_id, '_clubflow_booking_email', true);
			$customer_name = (string) get_post_meta($redeem_booking_id, '_clubflow_booking_name', true);
			$code = (string) get_post_meta($purchase_booking_id, self::META_KLIPPKORT_CODE, true);
			$code = self::normalize_code($code);
			$log_id = ClubFlow_Payment::log_payment([
				'booking_id' => $redeem_booking_id,
				'event_id'   => $event_id,
				'amount'     => 0,
				'currency'   => 'SEK',
				'method'     => 'klippkort',
				'status'     => 'completed',
				'reference'  => $code,
				'email'      => $customer_email,
				'name'       => $customer_name,
				'notes'      => 'KLIPPKORT REDEEMED: ' . $code . ', purchase_booking_id=' . $purchase_booking_id . ', remaining=' . $next_remaining,
			]);
			if ($log_id > 0) {
				update_post_meta($log_id, '_clubflow_klippkort_action', 'redeemed');
				if ($code !== '') {
					update_post_meta($log_id, '_clubflow_klippkort_code', $code);
				}
				update_post_meta($log_id, '_clubflow_klippkort_purchase_booking_id', (string) $purchase_booking_id);
			}
		}
		return true;
	}

	public function render_receipt_controls(string $which): void {
		if (!is_admin() || $which !== 'top') {
			return;
		}

		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (!$screen || ($screen->base ?? '') !== 'edit' || ($screen->post_type ?? '') !== self::POST_TYPE) {
			return;
		}

		if (!current_user_can('edit_posts')) {
			return;
		}

		$email = isset($_GET['clubflow_receipt_email']) ? sanitize_email((string) $_GET['clubflow_receipt_email']) : '';
		$from = isset($_GET['clubflow_receipt_from']) ? sanitize_text_field((string) $_GET['clubflow_receipt_from']) : '';
		$to = isset($_GET['clubflow_receipt_to']) ? sanitize_text_field((string) $_GET['clubflow_receipt_to']) : '';
		$nonce = wp_create_nonce('clubflow_view_receipt');

		echo '<div class="alignleft actions" style="margin-left: 8px;">';
		echo '<input type="email" id="clubflow_receipt_email" placeholder="' . esc_attr__('Email', 'clubflow') . '" value="' . esc_attr($email) . '" style="min-width: 220px;" />';
		echo '<input type="date" id="clubflow_receipt_from" value="' . esc_attr($from) . '" />';
		echo '<input type="date" id="clubflow_receipt_to" value="' . esc_attr($to) . '" />';
		echo '<a href="#" class="button" id="clubflow_view_receipt_btn">' . esc_html__('View Receipt', 'clubflow') . '</a>';
		echo '<script>(function(){var btn=document.getElementById("clubflow_view_receipt_btn");if(!btn)return;btn.addEventListener("click",function(e){e.preventDefault();var email=(document.getElementById("clubflow_receipt_email")||{}).value||"";var from=(document.getElementById("clubflow_receipt_from")||{}).value||"";var to=(document.getElementById("clubflow_receipt_to")||{}).value||"";var url=' . json_encode(admin_url('admin-post.php?action=clubflow_view_receipt&_wpnonce=' . $nonce)) . ';url += "&clubflow_receipt_email="+encodeURIComponent(email);url += "&clubflow_receipt_from="+encodeURIComponent(from);url += "&clubflow_receipt_to="+encodeURIComponent(to);window.open(url, "_blank");});})();</script>';
		echo '</div>';
	}

	public function handle_view_receipt(): void {
		if (!current_user_can('edit_posts')) {
			wp_die(__('Unauthorized', 'clubflow'));
		}

		check_admin_referer('clubflow_view_receipt');

		$email = isset($_GET['clubflow_receipt_email']) ? sanitize_email((string) $_GET['clubflow_receipt_email']) : '';
		$from = isset($_GET['clubflow_receipt_from']) ? sanitize_text_field((string) $_GET['clubflow_receipt_from']) : '';
		$to = isset($_GET['clubflow_receipt_to']) ? sanitize_text_field((string) $_GET['clubflow_receipt_to']) : '';

		$meta_query = [];
		if ($email !== '') {
			$meta_query[] = [
				'key'   => '_clubflow_booking_email',
				'value' => $email,
			];
		}

		$from_dt = $from !== '' ? $from . ' 00:00:00' : '';
		$to_dt = $to !== '' ? $to . ' 23:59:59' : '';
		if ($from_dt !== '' && $to_dt !== '') {
			$meta_query[] = [
				'key'     => '_clubflow_booking_created',
				'value'   => [$from_dt, $to_dt],
				'compare' => 'BETWEEN',
				'type'    => 'DATETIME',
			];
		} elseif ($from_dt !== '') {
			$meta_query[] = [
				'key'     => '_clubflow_booking_created',
				'value'   => $from_dt,
				'compare' => '>=',
				'type'    => 'DATETIME',
			];
		} elseif ($to_dt !== '') {
			$meta_query[] = [
				'key'     => '_clubflow_booking_created',
				'value'   => $to_dt,
				'compare' => '<=',
				'type'    => 'DATETIME',
			];
		}

		$args = [
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'ASC',
			'fields'         => 'ids',
		];
		if (!empty($meta_query)) {
			$args['meta_query'] = $meta_query;
		}

		$query = new \WP_Query($args);
		$booking_ids = is_array($query->posts) ? array_map('intval', $query->posts) : [];

		$model = self::build_receipt_model($booking_ids, [
			'from' => $from,
			'to' => $to,
			'customer_email' => $email,
		]);

		$html = self::render_receipt_html($model);
		if ($html === '') {
			wp_die(__('Could not generate receipt.', 'clubflow'));
		}

		nocache_headers();
		header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
		echo $html;
		exit;
	}

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
			return ['success' => false, 'error' => __('Eventet hittades inte.', 'clubflow')];
		}

		$event_mode = self::get_event_mode($event_id);

		// Check if fully booked
		if (self::is_fully_booked($event_id)) {
			return ['success' => false, 'error' => __('Detta event är fullbokat.', 'clubflow')];
		}

		// Sanitize input
		$name  = sanitize_text_field($data['name'] ?? '');
		$email = sanitize_email($data['email'] ?? '');
		$phone = sanitize_text_field($data['phone'] ?? '');
		$street = sanitize_text_field($data['street'] ?? '');
		$postal_code = sanitize_text_field($data['postal_code'] ?? '');
		$city = sanitize_text_field($data['city'] ?? '');
		$address = sanitize_text_field($data['address'] ?? '');
		$is_member = !empty($data['is_member']);
		$use_klippkort = !empty($data['use_klippkort']);
		$klippkort_code = self::normalize_code((string) ($data['klippkort_code'] ?? ''));
		$return_url = esc_url_raw($data['return_url'] ?? '');

		if (empty($name) || empty($email) || empty($phone)) {
			return ['success' => false, 'error' => __('Namn, e-post och telefon är obligatoriskt.', 'clubflow')];
		}

		if (!is_email($email)) {
			return ['success' => false, 'error' => __('Ange en giltig e-postadress.', 'clubflow')];
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
			return ['success' => false, 'error' => __('Du har redan bokat detta event.', 'clubflow')];
		}

		$payment_settings = class_exists('ClubFlow_Payment') ? get_option(ClubFlow_Payment::OPTION_KEY, []) : [];
		$price = get_post_meta($event_id, '_clubflow_price', true);
		$member_price = get_post_meta($event_id, '_clubflow_member_price', true);

		$applicable_price = ($is_member && $member_price !== '') ? $member_price : $price;
		$amount = $applicable_price ? (float) preg_replace('/[^0-9.]/', '', $applicable_price) : 0;
		$redeemed_purchase_booking_id = 0;
		$redeemed_code = '';
		if ($use_klippkort && $event_mode !== 'package') {
			$redeemed_purchase_booking_id = self::find_klippkort_purchase_booking_id($email, $klippkort_code);
			if ($redeemed_purchase_booking_id <= 0) {
				return ['success' => false, 'error' => __('Inget giltigt klippkort hittades för denna e-post. Om ni delar e-post i hushållet, ange din klippkortskod.', 'clubflow')];
			}
			$remaining = (int) get_post_meta($redeemed_purchase_booking_id, self::META_KLIPPKORT_CREDITS_REMAINING, true);
			if ($remaining <= 0) {
				return ['success' => false, 'error' => __('Klippkortet har inga klipp kvar.', 'clubflow')];
			}
			$redeemed_code = (string) get_post_meta($redeemed_purchase_booking_id, self::META_KLIPPKORT_CODE, true);
			$amount = 0;
			$applicable_price = '0';
		}

		if ($amount <= 0 && !$use_klippkort) {
			if (empty($street) || empty($postal_code) || empty($city)) {
				return ['success' => false, 'error' => __('Gatuadress, postnummer och ort är obligatoriskt för gratisbokningar.', 'clubflow')];
			}
		}

		$combined_address = trim(implode(' ', array_filter([
			$street,
			trim($postal_code . ' ' . $city),
		])));
		if ($combined_address !== '') {
			$address = $combined_address;
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
			return ['success' => false, 'error' => __('Kunde inte skapa bokning.', 'clubflow')];
		}

		if ($redeemed_purchase_booking_id > 0) {
			if (!self::redeem_one_klippkort_credit($redeemed_purchase_booking_id, (int) $booking_id)) {
				wp_delete_post($booking_id, true);
				return ['success' => false, 'error' => __('Klippkortet har inga klipp kvar.', 'clubflow')];
			}
		}

		// Save booking meta
		update_post_meta($booking_id, '_clubflow_booking_event_id', $event_id);
		update_post_meta($booking_id, '_clubflow_booking_name', $name);
		update_post_meta($booking_id, '_clubflow_booking_email', $email);
		update_post_meta($booking_id, '_clubflow_booking_phone', $phone);
		update_post_meta($booking_id, '_clubflow_booking_street', $street);
		update_post_meta($booking_id, '_clubflow_booking_postal_code', $postal_code);
		update_post_meta($booking_id, '_clubflow_booking_city', $city);
		update_post_meta($booking_id, '_clubflow_booking_address', $address);
		update_post_meta($booking_id, '_clubflow_booking_is_member', $is_member ? '1' : '0');
		update_post_meta($booking_id, '_clubflow_booking_confirmation_code', $confirmation_code);
		update_post_meta($booking_id, '_clubflow_booking_created', current_time('mysql'));
		if ($redeemed_purchase_booking_id > 0) {
			update_post_meta($booking_id, self::META_USED_KLIPPKORT_PURCHASE_BOOKING_ID, (string) $redeemed_purchase_booking_id);
			update_post_meta($booking_id, self::META_USED_KLIPPKORT_CODE, (string) $redeemed_code);
		}
		$issued_klippkort_code = '';
		if ($event_mode === 'package') {
			$issued_klippkort_code = (string) get_post_meta($booking_id, self::META_KLIPPKORT_CODE, true);
			$issued_klippkort_code = self::normalize_code($issued_klippkort_code);
			if ($issued_klippkort_code === '') {
				$issued_klippkort_code = self::ensure_unique_klippkort_code();
				update_post_meta($booking_id, self::META_KLIPPKORT_CODE, $issued_klippkort_code);
			}
		}

		// Get event details for response
		$event_title = get_the_title($event_id);
		$event_start = get_post_meta($event_id, '_clubflow_start', true);
		$event_location = get_post_meta($event_id, '_clubflow_location', true);

		// Determine initial booking status based on payment requirements
		// Store the price used for this booking
		update_post_meta($booking_id, '_clubflow_booking_price', $applicable_price);
		$payment_enabled = !empty($payment_settings['enabled']);
		$payment_required = $payment_enabled && $amount > 0;
		
		// Set initial status: pending_payment if payment required, otherwise pending (manual confirmation)
		$initial_status = $payment_required ? 'pending_payment' : 'pending';
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
						return ['success' => false, 'error' => __('Betalsystemet är inte konfigurerat. Kontakta administratören.', 'clubflow')];
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
						$error_msg = $stripe_result['error'] ?? __('Kunde inte skapa en betalningssession.', 'clubflow');
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
		if ($issued_klippkort_code !== '') {
			$result['klippkort_code'] = $issued_klippkort_code;
		}

		$base_url = $return_url !== '' ? $return_url : home_url();
		$base_url = strtok($base_url, '?');
		$result['redirect_url'] = add_query_arg([
			'booking_pending' => '1',
			'code'            => $confirmation_code,
		], $base_url);

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
			wp_send_json_error(__('Säkerhetskontrollen misslyckades.', 'clubflow'), 403);
		}

		$event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
		if ($event_id <= 0) {
			wp_send_json_error(__('Ogiltigt event.', 'clubflow'), 400);
		}

		$result = $this->create_booking($event_id, [
			'name'          => $_POST['name'] ?? '',
			'email'         => $_POST['email'] ?? '',
			'phone'         => $_POST['phone'] ?? '',
			'street'        => $_POST['street'] ?? '',
			'postal_code'   => $_POST['postal_code'] ?? '',
			'city'          => $_POST['city'] ?? '',
			'is_member'     => isset($_POST['is_member']) ? $_POST['is_member'] === '1' : false,
			'use_klippkort' => isset($_POST['use_klippkort']) ? $_POST['use_klippkort'] === '1' : false,
			'klippkort_code'=> $_POST['klippkort_code'] ?? '',
			'return_url'    => $_POST['return_url'] ?? '',
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
				'street'            => get_post_meta($post->ID, '_clubflow_booking_street', true),
				'postal_code'       => get_post_meta($post->ID, '_clubflow_booking_postal_code', true),
				'city'              => get_post_meta($post->ID, '_clubflow_booking_city', true),
				'address'           => get_post_meta($post->ID, '_clubflow_booking_address', true),
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
		echo '<th>' . esc_html__('Street', 'clubflow') . '</th>';
		echo '<th>' . esc_html__('Post address', 'clubflow') . '</th>';
		echo '<th>' . esc_html__('Code', 'clubflow') . '</th>';
		echo '<th>' . esc_html__('Status', 'clubflow') . '</th>';
		echo '<th>' . esc_html__('Booked', 'clubflow') . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ($bookings as $booking) {
			$street = trim((string) ($booking['street'] ?? ''));
			$postal_code = trim((string) ($booking['postal_code'] ?? ''));
			$city = trim((string) ($booking['city'] ?? ''));
			$legacy_address = trim((string) ($booking['address'] ?? ''));
			$post_address = trim($postal_code . ' ' . $city);

			$street_display = $street;
			$post_display = $post_address;
			if ($street_display === '' && $post_display === '' && $legacy_address !== '') {
				$street_display = $legacy_address;
			}

			echo '<tr>';
			echo '<td>' . esc_html($booking['name']) . '</td>';
			echo '<td><a href="mailto:' . esc_attr($booking['email']) . '">' . esc_html($booking['email']) . '</a></td>';
			echo '<td>' . esc_html($booking['phone'] ?: '—') . '</td>';
			echo '<td>' . esc_html($street_display ?: '—') . '</td>';
			echo '<td>' . esc_html($post_display ?: '—') . '</td>';
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

	public function booking_admin_sortable_columns(array $columns): array {
		$columns['event'] = 'clubflow_event';
		$columns['email'] = 'clubflow_email';
		$columns['phone'] = 'clubflow_phone';
		$columns['status'] = 'clubflow_status';
		return $columns;
	}

	public function maybe_apply_booking_admin_sorting(\WP_Query $query): void {
		if (!is_admin() || !$query->is_main_query()) {
			return;
		}

		$post_type = $query->get('post_type');
		if ($post_type !== self::POST_TYPE) {
			return;
		}

		$orderby = (string) $query->get('orderby');
		switch ($orderby) {
			case 'clubflow_event':
				$query->set('meta_key', '_clubflow_booking_event_id');
				$query->set('orderby', 'meta_value_num');
				break;
			case 'clubflow_email':
				$query->set('meta_key', '_clubflow_booking_email');
				$query->set('orderby', 'meta_value');
				break;
			case 'clubflow_phone':
				$query->set('meta_key', '_clubflow_booking_phone');
				$query->set('orderby', 'meta_value');
				break;
			case 'clubflow_status':
				$query->set('meta_key', '_clubflow_booking_status');
				$query->set('orderby', 'meta_value');
				break;
		}
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
				if (in_array((string) $status, ['pending', 'pending_payment'], true)) {
					$confirm_url = wp_nonce_url(
						admin_url('admin-post.php?action=clubflow_confirm_payment&booking_id=' . $post_id),
						'clubflow_confirm_payment_' . $post_id
					);
					echo '<br><a href="' . esc_url($confirm_url) . '" class="button button-small" style="margin-top: 6px;">' . esc_html__('Confirm payment received', 'clubflow') . '</a>';
				}
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
		$street = get_post_meta($post->ID, '_clubflow_booking_street', true);
		$postal_code = get_post_meta($post->ID, '_clubflow_booking_postal_code', true);
		$city = get_post_meta($post->ID, '_clubflow_booking_city', true);
		$address = get_post_meta($post->ID, '_clubflow_booking_address', true);
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
		$street = trim((string) $street);
		$postal_code = trim((string) $postal_code);
		$city = trim((string) $city);
		$legacy_address = trim((string) $address);
		$post_address = trim($postal_code . ' ' . $city);
		if ($street === '' && $post_address === '' && $legacy_address !== '') {
			echo '<tr><th>' . esc_html__('Address', 'clubflow') . '</th><td>' . esc_html($legacy_address) . '</td></tr>';
		} else {
			echo '<tr><th>' . esc_html__('Street', 'clubflow') . '</th><td>' . esc_html($street ?: '—') . '</td></tr>';
			echo '<tr><th>' . esc_html__('Post address', 'clubflow') . '</th><td>' . esc_html($post_address ?: '—') . '</td></tr>';
		}
		
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

		// Klippkort details (only relevant for package-mode events)
		if ($event_id > 0 && self::get_event_mode($event_id) === 'package') {
			$klippkort_code = (string) get_post_meta($post->ID, self::META_KLIPPKORT_CODE, true);
			$klippkort_code = self::normalize_code($klippkort_code);
			$klippkort_issued = (string) get_post_meta($post->ID, self::META_KLIPPKORT_ISSUED, true);
			$credits_total = (string) get_post_meta($post->ID, self::META_KLIPPKORT_CREDITS_TOTAL, true);
			$credits_remaining = (string) get_post_meta($post->ID, self::META_KLIPPKORT_CREDITS_REMAINING, true);
			$event_credits = (string) get_post_meta($event_id, '_clubflow_klippkort_credits', true);

			echo '<tr><th>' . esc_html__('Klippkort code', 'clubflow') . '</th><td>';
			echo $klippkort_code !== '' ? '<code style="font-size: 1.1em;">' . esc_html($klippkort_code) . '</code>' : '—';
			echo '</td></tr>';
			echo '<tr><th>' . esc_html__('Klippkort issued', 'clubflow') . '</th><td>' . esc_html($klippkort_issued !== '' ? $klippkort_issued : '—') . '</td></tr>';
			echo '<tr><th>' . esc_html__('Klippkort credits (event)', 'clubflow') . '</th><td>' . esc_html($event_credits !== '' ? $event_credits : '—') . '</td></tr>';
			echo '<tr><th>' . esc_html__('Klippkort credits total', 'clubflow') . '</th><td>' . esc_html($credits_total !== '' ? $credits_total : '—') . '</td></tr>';
			echo '<tr><th>' . esc_html__('Klippkort credits remaining', 'clubflow') . '</th><td>' . esc_html($credits_remaining !== '' ? $credits_remaining : '—') . '</td></tr>';

			if ($klippkort_issued !== '1') {
				$confirm_url = wp_nonce_url(
					admin_url('admin-post.php?action=clubflow_confirm_payment&booking_id=' . $post->ID),
					'clubflow_confirm_payment_' . $post->ID
				);
				echo '<tr><th>' . esc_html__('Klippkort issuance', 'clubflow') . '</th><td>';
				echo '<a href="' . esc_url($confirm_url) . '" class="button button-small">' . esc_html__('↻ Issue Klippkort Credits', 'clubflow') . '</a>';
				echo '<br><small style="color:#666;">' . esc_html__('Use after setting “Klippkort credits” on the event.', 'clubflow') . '</small>';
				echo '</td></tr>';
			}
		}

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
					echo '<br><a href="' . esc_url($confirm_url) . '" class="button button-small" style="margin-top: 8px;">';
					echo esc_html__('Confirm payment received', 'clubflow');
					echo '</a>';
				}
				
				echo '</td></tr>';
			}
		}

		// Show manual confirm button even if there is no payment record (e.g. free/manual flows)
		$booking_status = (string) get_post_meta($post->ID, '_clubflow_booking_status', true);
		$event_mode = $event_id > 0 ? self::get_event_mode($event_id) : '';
		if ($event_mode !== 'package' && in_array($booking_status, ['pending', 'pending_payment'], true)) {
			$confirm_url = wp_nonce_url(
				admin_url('admin-post.php?action=clubflow_confirm_payment&booking_id=' . $post->ID),
				'clubflow_confirm_payment_' . $post->ID
			);
			echo '<tr><th>' . esc_html__('Confirmation', 'clubflow') . '</th><td>';
			echo '<a href="' . esc_url($confirm_url) . '" class="button button-small">';
			echo esc_html__('Confirm payment received', 'clubflow');
			echo '</a>';
			echo '</td></tr>';
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
		$current_status = (string) get_post_meta($booking_id, '_clubflow_booking_status', true);
		if (in_array($current_status, ['pending', 'pending_payment'], true)) {
			update_post_meta($booking_id, '_clubflow_booking_status', 'confirmed');
			
			// Fire action for email confirmation / downstream actions
			do_action('clubflow_payment_completed', $booking_id, [
				'status' => 'completed',
				'source' => 'manual_admin_confirmation',
			]);
		}

		// Ensure klippkort issuance happens even if the booking was already confirmed
		// (e.g. klippkort credits were configured on the event after the initial confirmation)
		$this->maybe_issue_klippkort_from_booking($booking_id, [
			'source' => 'manual_admin_confirmation',
		]);

		// Redirect back
		wp_redirect(get_edit_post_link($booking_id, 'raw'));
		exit;
	}

	public static function build_receipt_model(array $booking_ids, array $args = []): array {
		$from = isset($args['from']) ? sanitize_text_field((string) $args['from']) : '';
		$to = isset($args['to']) ? sanitize_text_field((string) $args['to']) : '';
		$customer_email_filter = isset($args['customer_email']) ? sanitize_email((string) $args['customer_email']) : '';

		$rows = [];
		$total_paid = 0.0;
		$currency = '';

		foreach ($booking_ids as $booking_id) {
			$booking_id = absint($booking_id);
			if ($booking_id <= 0 || get_post_type($booking_id) !== self::POST_TYPE) {
				continue;
			}

			$customer_name = (string) get_post_meta($booking_id, '_clubflow_booking_name', true);
			$customer_email = (string) get_post_meta($booking_id, '_clubflow_booking_email', true);
			if ($customer_email_filter !== '' && strtolower($customer_email_filter) !== strtolower($customer_email)) {
				continue;
			}

			$event_id = (int) get_post_meta($booking_id, '_clubflow_booking_event_id', true);
			$event_title = $event_id > 0 ? (string) get_the_title($event_id) : '';
			$event_start = $event_id > 0 ? (string) get_post_meta($event_id, '_clubflow_start', true) : '';
			$event_location = $event_id > 0 ? (string) get_post_meta($event_id, '_clubflow_location', true) : '';

			$date_booked = (string) get_post_meta($booking_id, '_clubflow_booking_created', true);
			$status = (string) get_post_meta($booking_id, '_clubflow_booking_status', true);
			$confirmation_code = (string) get_post_meta($booking_id, '_clubflow_booking_confirmation_code', true);
			$booking_price = (string) get_post_meta($booking_id, '_clubflow_booking_price', true);

			$payment = null;
			if (class_exists('ClubFlow_Payment')) {
				$payment = ClubFlow_Payment::get_booking_payment($booking_id);
			}

			$date_purchased = '';
			$price = $booking_price;
			$payment_method = '';
			$payment_status = '';
			if (is_array($payment)) {
				$date_purchased = (string) ($payment['timestamp'] ?? '');
				$price = (string) ($payment['amount'] ?? $price);
				$currency = (string) ($payment['currency'] ?? $currency);
				$payment_method = (string) ($payment['method'] ?? '');
				$payment_status = (string) ($payment['status'] ?? '');
				if ($payment_status === 'completed') {
					$total_paid += (float) str_replace(',', '.', (string) $price);
				}
			}

			$summary_parts = [];
			if ($event_title !== '') {
				$summary_parts[] = $event_title;
			}
			if ($event_start !== '') {
				$summary_parts[] = wp_date('Y-m-d H:i', strtotime($event_start));
			}
			if ($event_location !== '') {
				$summary_parts[] = $event_location;
			}
			$summary = implode(' — ', $summary_parts);

			$rows[] = [
				'date_purchased'    => $date_purchased,
				'date_booked'       => $date_booked,
				'price'             => $price,
				'currency'          => $currency !== '' ? $currency : 'SEK',
				'payment_method'    => $payment_method,
				'status'            => $status !== '' ? $status : $payment_status,
				'summary'           => $summary,
				'customer_name'     => $customer_name,
				'customer_email'    => $customer_email,
				'confirmation_code' => $confirmation_code,
			];
		}

		$customer_name = isset($args['customer_name']) ? sanitize_text_field((string) $args['customer_name']) : '';
		$customer_email = $customer_email_filter;
		if ($customer_name === '' && !empty($rows)) {
			$customer_name = (string) ($rows[0]['customer_name'] ?? '');
		}
		if ($customer_email === '' && !empty($rows)) {
			$customer_email = (string) ($rows[0]['customer_email'] ?? '');
		}

		return [
			'title'         => __('Receipt / Payment summary', 'clubflow'),
			'customer_name' => $customer_name,
			'customer_email'=> $customer_email,
			'from'          => $from,
			'to'            => $to,
			'total_paid'    => $total_paid,
			'currency'      => $currency !== '' ? $currency : 'SEK',
			'rows'          => $rows,
		];
	}

	public static function render_receipt_html(array $receipt_model): string {
		$template_path = dirname(__DIR__) . '/templates/booking-receipt.php';
		if (!file_exists($template_path)) {
			return '';
		}

		$receipt = $receipt_model;
		ob_start();
		include $template_path;
		return (string) ob_get_clean();
	}

	/**
	 * Show confirmation toast when returning from payment
	 */
	public function maybe_show_confirmation_toast(): void {
		if (is_admin()) {
			return;
		}

		$confirmed = isset($_GET['booking_confirmed']) ? sanitize_text_field($_GET['booking_confirmed']) : '';
		$pending = isset($_GET['booking_pending']) ? sanitize_text_field($_GET['booking_pending']) : '';
		$code = isset($_GET['code']) ? sanitize_text_field($_GET['code']) : '';

		if (($confirmed !== '1' && $pending !== '1') || empty($code)) {
			return;
		}

		$booking_text = $confirmed === '1'
			? esc_html__('Bokning bekräftad!', 'clubflow')
			: esc_html__('Bokning mottagen!', 'clubflow');
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
