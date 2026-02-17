<?php

if (!defined('ABSPATH')) {
	exit;
}

final class ClubFlow_Ajax {
	private ClubFlow_Utils $utils;
	private const FC_LOCAL_ISO_FORMAT = 'Y-m-d\\TH:i:s';

	public function __construct(ClubFlow_Utils $utils) {
		$this->utils = $utils;
	}

	private function format_stored_datetime_for_fullcalendar(string $stored): string {
		$stored = trim($stored);
		if ($stored === '') {
			return '';
		}
		$dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $stored, wp_timezone());
		if (!($dt instanceof \DateTimeImmutable)) {
			return '';
		}
		$errors = \DateTimeImmutable::getLastErrors();
		if (!empty($errors['warning_count']) || !empty($errors['error_count'])) {
			return '';
		}
		return $dt->format(self::FC_LOCAL_ISO_FORMAT);
	}

	public function register(): void {
		add_action('wp_ajax_' . ClubFlow::AJAX_ACTION_EVENTS, [$this, 'ajax_events']);
		add_action('wp_ajax_nopriv_' . ClubFlow::AJAX_ACTION_EVENTS, [$this, 'ajax_events']);
		add_action('wp_ajax_' . ClubFlow::AJAX_ACTION_EVENT_DETAILS, [$this, 'ajax_event_details']);
		add_action('wp_ajax_nopriv_' . ClubFlow::AJAX_ACTION_EVENT_DETAILS, [$this, 'ajax_event_details']);

		add_action('wp_ajax_clubflow_admin_calendar_events', [$this, 'ajax_admin_calendar_events']);
		add_action('wp_ajax_clubflow_admin_calendar_save_event', [$this, 'ajax_admin_calendar_save_event']);
	}

	private function admin_calendar_auth_or_die(): void {
		check_ajax_referer('clubflow_admin_calendar', 'nonce');
		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => __('Unauthorized', 'clubflow')], 403);
		}
	}

	public function ajax_admin_calendar_events(): void {
		$this->admin_calendar_auth_or_die();

		$start = isset($_GET['start']) ? sanitize_text_field(wp_unslash($_GET['start'])) : '';
		$end = isset($_GET['end']) ? sanitize_text_field(wp_unslash($_GET['end'])) : '';
		$category = isset($_GET['category']) ? sanitize_text_field(wp_unslash($_GET['category'])) : '';

		$start_ts = strtotime($start);
		$end_ts = strtotime($end);
		if ($start_ts === false || $end_ts === false) {
			wp_send_json_error(['message' => __('Invalid date range', 'clubflow')], 400);
		}

		$args = [
			'post_type' => ClubFlow::POST_TYPE,
			'post_status' => 'any',
			'posts_per_page' => 800,
			'orderby' => 'meta_value',
			'meta_key' => '_clubflow_start',
			'order' => 'ASC',
			'meta_query' => [
				'relation' => 'AND',
				[
					'key' => '_clubflow_start',
					'compare' => 'EXISTS',
				],
				[
					'relation' => 'OR',
					['key' => '_clubflow_event_mode', 'value' => 'calendar'],
					['key' => '_clubflow_event_mode', 'compare' => 'NOT EXISTS'],
				],
			],
		];

		if ($category !== '') {
			$args['tax_query'] = [
				[
					'taxonomy' => ClubFlow::TAX_CATEGORY,
					'field' => 'slug',
					'terms' => [$category],
				],
			];
		}

		$query = new \WP_Query($args);
		$events = [];
		foreach ($query->posts as $post) {
			$start_meta = trim((string) get_post_meta($post->ID, '_clubflow_start', true));
			if ($start_meta === '') {
				continue;
			}
			$start_meta_ts = strtotime($start_meta);
			if ($start_meta_ts === false) {
				continue;
			}

			$end_meta = trim((string) get_post_meta($post->ID, '_clubflow_end', true));
			$end_meta_ts = ($end_meta !== '') ? strtotime($end_meta) : false;
			$has_end_date = ($end_meta_ts !== false && $end_meta_ts > 0);

			$all_day_meta = (string) get_post_meta($post->ID, '_clubflow_all_day', true);
			$is_all_day = ($all_day_meta === '1');
			$event_end_ts = $has_end_date ? $end_meta_ts : $start_meta_ts;

			if ($start_meta_ts > $end_ts || $event_end_ts < $start_ts) {
				continue;
			}

			$start_iso = $this->format_stored_datetime_for_fullcalendar($start_meta);
			if ($start_iso === '') {
				continue;
			}

			$event = [
				'id' => $post->ID,
				'title' => html_entity_decode(get_the_title($post), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
				'start' => $start_iso,
				'allDay' => $is_all_day,
				'extendedProps' => [
					'postStatus' => (string) $post->post_status,
				],
			];

			if ($has_end_date) {
				$event['display'] = 'block';
			}

			if ($has_end_date) {
				if ($is_all_day) {
					$end_dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $end_meta, wp_timezone());
					if ($end_dt instanceof \DateTimeImmutable && $end_dt->format('H:i:s') === '00:00:00') {
						$end_meta = $end_dt->modify('+1 day')->format('Y-m-d H:i:s');
					}
				}
				$end_iso = $this->format_stored_datetime_for_fullcalendar($end_meta);
				if ($end_iso !== '') {
					$event['end'] = $end_iso;
				}
			}

			$cat = $this->utils->get_category_display_data($post->ID);
			$color = (string) ($cat['color'] ?? '');
			$has_category = (bool) ($cat['has_category'] ?? false);
			if ($has_category) {
				$event['backgroundColor'] = $color;
				$event['borderColor'] = $color;
			} else {
				$event['backgroundColor'] = '#ffffff';
				$event['borderColor'] = $color;
				$event['textColor'] = $color;
			}

			$booking_enabled = get_post_meta($post->ID, '_clubflow_booking_enabled', true) === '1';
			$max_spots = (int) get_post_meta($post->ID, '_clubflow_max_spots', true);
			$price = (string) get_post_meta($post->ID, '_clubflow_price', true);
			$location = (string) get_post_meta($post->ID, '_clubflow_location', true);

			$category_ids = wp_get_object_terms($post->ID, ClubFlow::TAX_CATEGORY, ['fields' => 'ids']);
			$category_id = (!is_wp_error($category_ids) && !empty($category_ids)) ? (int) $category_ids[0] : 0;
			$instructor_ids = wp_get_object_terms($post->ID, ClubFlow::TAX_TAG, ['fields' => 'ids']);
			$instructor_id = (!is_wp_error($instructor_ids) && !empty($instructor_ids)) ? (int) $instructor_ids[0] : 0;

			$booked = 0;
			if ($booking_enabled && class_exists('ClubFlow_Booking')) {
				$booked = ClubFlow_Booking::get_booking_count($post->ID);
			}

			$event['extendedProps']['bookingEnabled'] = $booking_enabled;
			$event['extendedProps']['maxSpots'] = $max_spots;
			$event['extendedProps']['booked'] = $booked;
			$event['extendedProps']['price'] = $price;
			$event['extendedProps']['location'] = $location;
			$event['extendedProps']['categoryId'] = $category_id;
			$event['extendedProps']['instructorId'] = $instructor_id;

			$events[] = $event;
		}

		wp_send_json_success($events);
	}

	private function normalize_admin_calendar_datetime(string $value): string {
		$value = trim($value);
		if ($value === '') {
			return '';
		}
		return $this->utils->normalize_datetime_for_storage($value);
	}

	private function find_overlaps(string $start, string $end, int $exclude_event_id = 0): array {
		$start_dt = $start !== '' ? new \DateTimeImmutable($start, wp_timezone()) : null;
		$end_dt = $end !== '' ? new \DateTimeImmutable($end, wp_timezone()) : null;
		if (!$start_dt) {
			return [];
		}
		if (!$end_dt) {
			$end_dt = $start_dt;
		}

		$args = [
			'post_type' => ClubFlow::POST_TYPE,
			'post_status' => 'any',
			'posts_per_page' => 200,
			'fields' => 'ids',
			'meta_query' => [
				'relation' => 'AND',
				[
					'relation' => 'OR',
					['key' => '_clubflow_event_mode', 'value' => 'calendar'],
					['key' => '_clubflow_event_mode', 'compare' => 'NOT EXISTS'],
				],
				[
					'key' => '_clubflow_start',
					'value' => $end_dt->format('Y-m-d H:i:s'),
					'compare' => '<=',
					'type' => 'DATETIME',
				],
			],
		];
		if ($exclude_event_id > 0) {
			$args['post__not_in'] = [$exclude_event_id];
		}

		$q = new \WP_Query($args);
		$ids = is_array($q->posts) ? array_map('intval', $q->posts) : [];
		$overlaps = [];
		foreach ($ids as $id) {
			$other_start = (string) get_post_meta($id, '_clubflow_start', true);
			if ($other_start === '') {
				continue;
			}
			$other_end = (string) get_post_meta($id, '_clubflow_end', true);
			$other_end = $other_end !== '' ? $other_end : $other_start;

			$os = strtotime($other_start);
			$oe = strtotime($other_end);
			if ($os === false || $oe === false) {
				continue;
			}
			$ns = $start_dt->getTimestamp();
			$ne = $end_dt->getTimestamp();
			if ($ns <= $oe && $ne >= $os) {
				$overlaps[] = [
					'id' => $id,
					'title' => (string) get_the_title($id),
					'start' => $other_start,
					'end' => $other_end,
				];
			}
		}
		return $overlaps;
	}

	public function ajax_admin_calendar_save_event(): void {
		$this->admin_calendar_auth_or_die();

		$event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
		$do_delete = isset($_POST['delete']) && sanitize_text_field(wp_unslash($_POST['delete'])) === '1';
		$title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
		$start_raw = isset($_POST['start']) ? sanitize_text_field(wp_unslash($_POST['start'])) : '';
		$end_raw = isset($_POST['end']) ? sanitize_text_field(wp_unslash($_POST['end'])) : '';
		$all_day = (isset($_POST['all_day']) && sanitize_text_field(wp_unslash($_POST['all_day'])) === '1') ? '1' : '0';
		$location = isset($_POST['location']) ? sanitize_text_field(wp_unslash($_POST['location'])) : '';
		$booking_enabled = isset($_POST['booking_enabled']) ? '1' : '0';
		$max_spots = isset($_POST['max_spots']) ? absint($_POST['max_spots']) : 0;
		$price = isset($_POST['price']) ? sanitize_text_field(wp_unslash($_POST['price'])) : '';
		$category_id = isset($_POST['category_id']) ? absint($_POST['category_id']) : 0;
		$instructor_id = isset($_POST['instructor_id']) ? absint($_POST['instructor_id']) : 0;

		if ($event_id > 0) {
			$post = get_post($event_id);
			if (!$post instanceof \WP_Post || $post->post_type !== ClubFlow::POST_TYPE) {
				wp_send_json_error(['message' => __('Event not found', 'clubflow')], 404);
			}
			if (!current_user_can('edit_post', $event_id)) {
				wp_send_json_error(['message' => __('Unauthorized', 'clubflow')], 403);
			}
		}

		if ($do_delete) {
			if ($event_id <= 0) {
				wp_send_json_error(['message' => __('Invalid event', 'clubflow')], 400);
			}
			wp_trash_post($event_id);
			wp_send_json_success(['eventId' => $event_id]);
		}

		$start = $this->normalize_admin_calendar_datetime($start_raw);
		$end = $this->normalize_admin_calendar_datetime($end_raw);
		if ($start === '') {
			wp_send_json_error(['message' => __('Start is required', 'clubflow')], 400);
		}

		if ($all_day === '1') {
			$start_dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $start, wp_timezone());
			if ($start_dt instanceof \DateTimeImmutable) {
				$start = $start_dt->setTime(0, 0, 0)->format('Y-m-d H:i:s');
			}
			if ($end !== '') {
				$end_dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $end, wp_timezone());
				if ($end_dt instanceof \DateTimeImmutable) {
					$end = $end_dt->setTime(0, 0, 0)->modify('+1 day')->format('Y-m-d H:i:s');
				}
			}
		}
		if ($title === '') {
			$title = __('Booking slot', 'clubflow');
		}

		$postarr = [
			'post_type' => ClubFlow::POST_TYPE,
			'post_title' => $title,
			'post_status' => 'publish',
		];
		if ($event_id > 0) {
			$postarr['ID'] = $event_id;
		}

		$saved_id = wp_insert_post($postarr, true);
		if (is_wp_error($saved_id)) {
			wp_send_json_error(['message' => $saved_id->get_error_message()], 500);
		}
		$saved_id = (int) $saved_id;

		update_post_meta($saved_id, '_clubflow_start', $start);
		if ($end !== '') {
			update_post_meta($saved_id, '_clubflow_end', $end);
		} else {
			delete_post_meta($saved_id, '_clubflow_end');
		}
		update_post_meta($saved_id, '_clubflow_all_day', $all_day);
		update_post_meta($saved_id, '_clubflow_event_mode', 'calendar');
		update_post_meta($saved_id, '_clubflow_booking_enabled', $booking_enabled);
		update_post_meta($saved_id, '_clubflow_max_spots', $max_spots);
		if ($price !== '') {
			update_post_meta($saved_id, '_clubflow_price', $price);
		} else {
			delete_post_meta($saved_id, '_clubflow_price');
		}
		if ($location !== '') {
			update_post_meta($saved_id, '_clubflow_location', $location);
		} else {
			delete_post_meta($saved_id, '_clubflow_location');
		}

		if ($category_id > 0) {
			wp_set_object_terms($saved_id, [$category_id], ClubFlow::TAX_CATEGORY, false);
		} else {
			wp_set_object_terms($saved_id, [], ClubFlow::TAX_CATEGORY, false);
		}

		if ($instructor_id > 0) {
			wp_set_object_terms($saved_id, [$instructor_id], ClubFlow::TAX_TAG, false);
		} else {
			wp_set_object_terms($saved_id, [], ClubFlow::TAX_TAG, false);
		}

		$overlaps = $this->find_overlaps($start, $end !== '' ? $end : $start, $saved_id);

		wp_send_json_success([
			'eventId' => $saved_id,
			'overlaps' => $overlaps,
		]);
	}

	public function ajax_events(): void {
		check_ajax_referer('clubflow_events');

		$start = isset($_GET['start']) ? sanitize_text_field(wp_unslash($_GET['start'])) : '';
		$end = isset($_GET['end']) ? sanitize_text_field(wp_unslash($_GET['end'])) : '';
		$category = isset($_GET['category']) ? sanitize_text_field(wp_unslash($_GET['category'])) : '';

		$start_ts = strtotime($start);
		$end_ts = strtotime($end);

		if ($start_ts === false || $end_ts === false) {
			wp_send_json_error('Invalid date range', 400);
		}

		$post_status = ['publish', 'future'];
		if (is_user_logged_in() && current_user_can('edit_posts')) {
			$post_status = 'any';
		}

		$args = [
			'post_type' => ClubFlow::POST_TYPE,
			'post_status' => $post_status,
			'posts_per_page' => 500,
			'orderby' => 'meta_value',
			'meta_key' => '_clubflow_start',
			'order' => 'ASC',
			'meta_query' => [
				'relation' => 'AND',
				[
					'key' => '_clubflow_start',
					'compare' => 'EXISTS',
				],
				// Only show calendar mode events (exclude products and packages)
				[
					'relation' => 'OR',
					[
						'key' => '_clubflow_event_mode',
						'value' => 'calendar',
					],
					[
						'key' => '_clubflow_event_mode',
						'compare' => 'NOT EXISTS',
					],
				],
			],
		];

		if ($category !== '') {
			$args['tax_query'] = [
				[
					'taxonomy' => ClubFlow::TAX_CATEGORY,
					'field' => 'slug',
					'terms' => [$category],
				],
			];
		}

		$query = new \WP_Query($args);
		$events = [];

		foreach ($query->posts as $post) {
			$start_meta = trim((string) get_post_meta($post->ID, '_clubflow_start', true));
			if ($start_meta === '') {
				continue;
			}

			$start_meta_ts = strtotime($start_meta);
			if ($start_meta_ts === false) {
				continue;
			}

			$end_meta = trim((string) get_post_meta($post->ID, '_clubflow_end', true));
			$end_meta_ts = ($end_meta !== '') ? strtotime($end_meta) : false;
			$has_end_date = ($end_meta_ts !== false && $end_meta_ts > 0);

			$all_day_meta = (string) get_post_meta($post->ID, '_clubflow_all_day', true);
			$is_all_day = ($all_day_meta === '1') || !$has_end_date;

			$event_end_ts = $has_end_date ? $end_meta_ts : strtotime(wp_date('Y-m-d', $start_meta_ts) . ' 23:59:59');

			if ($start_meta_ts > $end_ts || $event_end_ts < $start_ts) {
				continue;
			}

			$location = trim((string) get_post_meta($post->ID, '_clubflow_location', true));

			$start_iso = $this->format_stored_datetime_for_fullcalendar($start_meta);
			if ($start_iso === '') {
				continue;
			}

			$event = [
				'id' => $post->ID,
				'title' => html_entity_decode(get_the_title($post), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
				'start' => $start_iso,
				'url' => get_permalink($post),
				'allDay' => $is_all_day,
			];

			if ($has_end_date) {
				if ($is_all_day) {
					$end_dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $end_meta, wp_timezone());
					if ($end_dt instanceof \DateTimeImmutable && $end_dt->format('H:i:s') === '00:00:00') {
						$end_meta = $end_dt->modify('+1 day')->format('Y-m-d H:i:s');
					}
				}
				$end_iso = $this->format_stored_datetime_for_fullcalendar($end_meta);
				if ($end_iso !== '') {
					$event['end'] = $end_iso;
				}
			}

			$excerpt_plain = trim(html_entity_decode(wp_strip_all_tags((string) $post->post_content), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
			if ($excerpt_plain !== '') {
				$was_truncated = (mb_strlen($excerpt_plain) > 100);
				$excerpt_plain = mb_substr($excerpt_plain, 0, 100);
				if ($was_truncated) {
					$excerpt_plain .= '...';
				}
			}

			$event['extendedProps'] = [];
			if ($location !== '') {
				$event['extendedProps']['location'] = $location;
			}
			if ($excerpt_plain !== '') {
				$event['extendedProps']['excerpt'] = $excerpt_plain;
			}

			$cat = $this->utils->get_category_display_data($post->ID);
			$color = (string) ($cat['color'] ?? '');
			$name = (string) ($cat['name'] ?? '');
			$has_category = (bool) ($cat['has_category'] ?? false);
			$event['extendedProps']['isUncategorized'] = !$has_category;
			if ($has_category) {
				$event['backgroundColor'] = $color;
				$event['borderColor'] = $color;
			} else {
				$event['backgroundColor'] = '#ffffff';
				$event['borderColor'] = $color;
				$event['textColor'] = $color;
			}
			$event['extendedProps']['categoryName'] = $name;
			$event['extendedProps']['dotColor'] = $color;

			// Add booking info
			$booking_enabled = get_post_meta($post->ID, '_clubflow_booking_enabled', true) === '1';
			$event['extendedProps']['bookingEnabled'] = $booking_enabled;

			if ($booking_enabled && class_exists('ClubFlow_Booking')) {
				$spots_remaining = ClubFlow_Booking::get_spots_remaining($post->ID);
				$event['extendedProps']['spotsRemaining'] = $spots_remaining;
				$event['extendedProps']['isFullyBooked'] = ($spots_remaining !== null && $spots_remaining <= 0);
				
				$price = get_post_meta($post->ID, '_clubflow_price', true);
				if ($price) {
					$event['extendedProps']['price'] = $price;
				}
			}

			$events[] = $event;
		}

		wp_send_json_success($events);
	}

	public function ajax_event_details(): void {
		check_ajax_referer('clubflow_event_details');

		$event_id = isset($_GET['event_id']) ? absint($_GET['event_id']) : 0;
		if ($event_id <= 0) {
			wp_send_json_error('Invalid event', 400);
		}

		$post = get_post($event_id);
		if (!$post instanceof \WP_Post) {
			wp_send_json_error('Event not found', 404);
		}

		if ($post->post_type !== ClubFlow::POST_TYPE) {
			wp_send_json_error('Event not available', 404);
		}

		if ($post->post_status !== 'publish') {
			if (!(is_user_logged_in() && current_user_can('edit_posts'))) {
				wp_send_json_error('Event not available', 404);
			}
		}

		$start_meta = (string) get_post_meta($post->ID, '_clubflow_start', true);
		$end_meta = (string) get_post_meta($post->ID, '_clubflow_end', true);
		$all_day = (string) get_post_meta($post->ID, '_clubflow_all_day', true);
		$location = (string) get_post_meta($post->ID, '_clubflow_location', true);

		$tz = wp_timezone();
		$start_dt = $start_meta !== '' ? \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $start_meta, $tz) : null;
		$end_dt = $end_meta !== '' ? \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $end_meta, $tz) : null;

		$date_text = '';
		if ($start_dt instanceof \DateTimeImmutable) {
			$has_end = ($end_dt instanceof \DateTimeImmutable) && ($end_dt->getTimestamp() > $start_dt->getTimestamp());
			$start_text = ($all_day === '1') ? wp_date('Y-m-d', $start_dt->getTimestamp()) : wp_date('Y-m-d H:i', $start_dt->getTimestamp());
			$date_text = $start_text;

			if ($has_end) {
				$display_end_dt = $end_dt;
				if ($all_day === '1' && $end_dt->format('H:i:s') === '00:00:00') {
					// Backwards-compat: many feeds store all-day end as exclusive (00:00 next day)
					$display_end_dt = $end_dt->modify('-1 day');
				}
				$end_text = ($all_day === '1') ? wp_date('Y-m-d', $display_end_dt->getTimestamp()) : wp_date('Y-m-d H:i', $display_end_dt->getTimestamp());
				if ($end_text !== $start_text) {
					$date_text .= ' – ' . $end_text;
				}
			}
		}

		$title = get_the_title($post);
		$permalink = get_permalink($post);
		$content_html = apply_filters('the_content', $post->post_content);
		$thumbnail_url = get_the_post_thumbnail_url($post->ID, 'medium');

		$cat = $this->utils->get_category_display_data($post->ID);
		$badge_name = (string) ($cat['name'] ?? '');
		$badge_color = (string) ($cat['color'] ?? '');

		$html = '';
		$html .= '<div class="clubflow-event">';
		
		// Header with title and optional thumbnail
		$html .= '<div class="clubflow-event__header">';
		$html .= '<div class="clubflow-event__header-content">';
		$html .= '<h3 class="clubflow-event__title">' . esc_html($title) . '</h3>';

		if ($date_text !== '') {
			$html .= '<p class="clubflow-event__datetime">' . esc_html($date_text);
			if ($badge_name !== '' && $badge_color !== '') {
				$html .= '<span class="clubflow-event__badge" style="--clubflow-badge-color:' . esc_attr($badge_color) . '">' . esc_html($badge_name) . '</span>';
			}
			if ($all_day === '1') {
				$html .= '<span class="clubflow-event__badge">' . esc_html__('Heldag', 'clubflow') . '</span>';
			}
			$html .= '</p>';
		} else {
			if ($badge_name !== '' && $badge_color !== '') {
				$html .= '<p class="clubflow-event__badge-row"><span class="clubflow-event__badge" style="--clubflow-badge-color:' . esc_attr($badge_color) . '">' . esc_html($badge_name) . '</span></p>';
			}
			if ($all_day === '1') {
				$html .= '<p class="clubflow-event__badge-row"><span class="clubflow-event__badge">' . esc_html__('Heldag', 'clubflow') . '</span></p>';
			}
		}

		if ($location !== '') {
			$html .= '<p class="clubflow-event__location">' . esc_html($location) . '</p>';
		}
		
		$html .= '</div>'; // .clubflow-event__header-content
		
		if ($thumbnail_url) {
			$html .= '<img class="clubflow-event__thumbnail" src="' . esc_url($thumbnail_url) . '" alt="" loading="lazy" />';
		}
		
		$html .= '</div>'; // .clubflow-event__header

		$html .= '<div class="clubflow-event__content">' . wp_kses_post($content_html) . '</div>';
		$html .= '<p class="clubflow-event__link"><a href="' . esc_url($permalink) . '">'
			. esc_html__('Open event page', 'clubflow')
			. ' <svg class="clubflow-icon clubflow-icon--external" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>'
			. '</a></p>';

		// Add booking form if enabled
		$html .= $this->render_booking_form($post->ID);

		$html .= '</div>';

		wp_send_json_success(['html' => $html]);
	}

	/**
	 * Render booking form HTML for an event
	 */
	private function render_booking_form(int $event_id): string {
		$booking_enabled = get_post_meta($event_id, '_clubflow_booking_enabled', true) === '1';
		if (!$booking_enabled) {
			return '';
		}

		$price = get_post_meta($event_id, '_clubflow_price', true);
		$member_price = get_post_meta($event_id, '_clubflow_member_price', true);
		$max_spots = (int) get_post_meta($event_id, '_clubflow_max_spots', true);
		$has_member_pricing = ($member_price !== '' && $price !== '');
		$price_amount = $price !== '' ? (float) preg_replace('/[^0-9.]/', '', (string) $price) : 0;
		$member_price_amount = $member_price !== '' ? (float) preg_replace('/[^0-9.]/', '', (string) $member_price) : 0;
		
		$spots_remaining = null;
		$is_fully_booked = false;
		
		if (class_exists('ClubFlow_Booking')) {
			$spots_remaining = ClubFlow_Booking::get_spots_remaining($event_id);
			$is_fully_booked = ClubFlow_Booking::is_fully_booked($event_id);
		}

		$html = '<div class="clubflow-booking" data-event-id="' . esc_attr($event_id) . '">';
		$html .= '<hr style="margin: 20px 0; border: none; border-top: 1px solid #ddd;" />';
		$html .= '<h4 class="clubflow-booking__title">' . esc_html__('Book this event', 'clubflow') . '</h4>';

		// Show price and spots
		$html .= '<p class="clubflow-booking__meta">';
		if ($has_member_pricing) {
			$html .= '<span class="clubflow-booking__price">';
			$html .= esc_html__('Member:', 'clubflow') . ' <strong>' . esc_html($member_price) . '</strong>';
			$html .= ' &bull; ';
			$html .= esc_html__('Non-member:', 'clubflow') . ' <strong>' . esc_html($price) . '</strong>';
			$html .= '</span>';
		} elseif ($price) {
			$html .= '<span class="clubflow-booking__price">' . esc_html__('Price:', 'clubflow') . ' <strong>' . esc_html($price) . ' SEK</strong></span>';
		}
		if ($spots_remaining !== null) {
			if ($price || $has_member_pricing) {
				$html .= ' &bull; ';
			}
			$spots_text = $is_fully_booked
				? __('Fully booked', 'clubflow')
				: sprintf(__('%d spots left', 'clubflow'), $spots_remaining);
			$html .= '<span class="clubflow-booking__spots' . ($is_fully_booked ? ' clubflow-booking__spots--full' : '') . '">' . esc_html($spots_text) . '</span>';
		}
		$html .= '</p>';

		if ($is_fully_booked) {
			$html .= '<p class="clubflow-booking__full">' . esc_html__('This event is fully booked. Please check back later or contact us.', 'clubflow') . '</p>';
		} else {
			// Booking form
			$html .= '<form class="clubflow-booking__form" data-clubflow-booking-form '
				. 'data-clubflow-price-amount="' . esc_attr($price_amount) . '" '
				. 'data-clubflow-member-price-amount="' . esc_attr($member_price_amount) . '">' ;
			$html .= '<input type="hidden" name="event_id" value="' . esc_attr($event_id) . '" />';
			
			$html .= '<p class="clubflow-booking__field">';
			$html .= '<label for="clubflow_book_name">' . esc_html__('Name', 'clubflow') . ' <span class="required">*</span></label>';
			$html .= '<input type="text" id="clubflow_book_name" name="name" required />';
			$html .= '</p>';

			$html .= '<p class="clubflow-booking__field">';
			$html .= '<label for="clubflow_book_email">' . esc_html__('Email', 'clubflow') . ' <span class="required">*</span></label>';
			$html .= '<input type="email" id="clubflow_book_email" name="email" required />';
			$html .= '</p>';

			$html .= '<p class="clubflow-booking__field">';
			$html .= '<label for="clubflow_book_phone">' . esc_html__('Phone', 'clubflow') . ' <span class="required">*</span></label>';
			$html .= '<input type="tel" id="clubflow_book_phone" name="phone" required />';
			$html .= '</p>';

			$html .= '<p class="clubflow-booking__field" data-clubflow-free-field style="display:none">';
			$html .= '<label for="clubflow_book_street">' . esc_html__('Street', 'clubflow') . ' <span class="required">*</span></label>';
			$html .= '<input type="text" id="clubflow_book_street" name="street" data-clubflow-free-required />';
			$html .= '</p>';

			$html .= '<p class="clubflow-booking__field" data-clubflow-free-field style="display:none">';
			$html .= '<label for="clubflow_book_postal_code">' . esc_html__('Postal code', 'clubflow') . ' <span class="required">*</span></label>';
			$html .= '<input type="text" id="clubflow_book_postal_code" name="postal_code" data-clubflow-free-required />';
			$html .= '</p>';

			$html .= '<p class="clubflow-booking__field" data-clubflow-free-field style="display:none">';
			$html .= '<label for="clubflow_book_city">' . esc_html__('City', 'clubflow') . ' <span class="required">*</span></label>';
			$html .= '<input type="text" id="clubflow_book_city" name="city" data-clubflow-free-required />';
			$html .= '</p>';

			// Member selection if both prices exist
			if ($has_member_pricing) {
				$html .= '<p class="clubflow-booking__field clubflow-booking__field--member">';
				$html .= '<label>' . esc_html__('I am a', 'clubflow') . '</label>';
				$html .= '<span class="clubflow-booking__radio-group">';
				$html .= '<label class="clubflow-booking__radio"><input type="radio" name="is_member" value="1" /> ' . esc_html__('Member', 'clubflow') . ' <span class="clubflow-booking__radio-price">(' . esc_html($member_price) . ')</span></label>';
				$html .= '<label class="clubflow-booking__radio"><input type="radio" name="is_member" value="0" checked /> ' . esc_html__('Non-member', 'clubflow') . ' <span class="clubflow-booking__radio-price">(' . esc_html($price) . ')</span></label>';
				$html .= '</span>';
				$html .= '</p>';
			}

			$html .= '<p class="clubflow-booking__submit">';
			$html .= '<button type="submit" class="clubflow-booking__button">' . esc_html__('Book now', 'clubflow') . '</button>';
			$html .= '</p>';

			$html .= '<p class="clubflow-booking__message" data-clubflow-booking-message style="display: none;"></p>';
			$html .= '</form>';
		}

		$html .= '</div>';
		return $html;
	}
}
