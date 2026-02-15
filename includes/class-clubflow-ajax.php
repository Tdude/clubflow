<?php

if (!defined('ABSPATH')) {
	exit;
}

final class ClubFlow_Ajax {
	private ClubFlow_Utils $utils;

	public function __construct(ClubFlow_Utils $utils) {
		$this->utils = $utils;
	}

	public function register(): void {
		add_action('wp_ajax_' . ClubFlow::AJAX_ACTION_EVENTS, [$this, 'ajax_events']);
		add_action('wp_ajax_nopriv_' . ClubFlow::AJAX_ACTION_EVENTS, [$this, 'ajax_events']);
		add_action('wp_ajax_' . ClubFlow::AJAX_ACTION_EVENT_DETAILS, [$this, 'ajax_event_details']);
		add_action('wp_ajax_nopriv_' . ClubFlow::AJAX_ACTION_EVENT_DETAILS, [$this, 'ajax_event_details']);
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

			$start_iso = $this->utils->format_datetime_for_iso($start_meta);
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
				$end_iso = $this->utils->format_datetime_for_iso($end_meta);
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

		$start_ts = strtotime($start_meta);
		$end_ts = strtotime($end_meta);

		$date_text = '';
		if ($start_ts !== false) {
			$has_end = ($end_meta !== '' && $end_ts !== false && $end_ts > $start_ts);
			$start_text = ($all_day === '1') ? wp_date('Y-m-d', $start_ts) : wp_date('Y-m-d H:i', $start_ts);
			$date_text = $start_text;

			if ($has_end) {
				$end_text = ($all_day === '1') ? wp_date('Y-m-d', $end_ts) : wp_date('Y-m-d H:i', $end_ts);
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
			$html .= '</p>';
		} else {
			if ($badge_name !== '' && $badge_color !== '') {
				$html .= '<p class="clubflow-event__badge-row"><span class="clubflow-event__badge" style="--clubflow-badge-color:' . esc_attr($badge_color) . '">' . esc_html($badge_name) . '</span></p>';
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
			$html .= '<form class="clubflow-booking__form" data-clubflow-booking-form>';
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
			$html .= '<label for="clubflow_book_phone">' . esc_html__('Phone', 'clubflow') . ' <span class="optional">(' . esc_html__('optional', 'clubflow') . ')</span></label>';
			$html .= '<input type="tel" id="clubflow_book_phone" name="phone" />';
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
