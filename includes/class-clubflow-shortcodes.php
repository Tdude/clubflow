<?php

if (!defined('ABSPATH')) {
	exit;
}

final class ClubFlow_Shortcodes {
	private ClubFlow_Assets $assets;
	private ClubFlow_Utils $utils;
	private bool $modal_markup_rendered = false;

	public function __construct(ClubFlow_Assets $assets, ClubFlow_Utils $utils) {
		$this->assets = $assets;
		$this->utils = $utils;
	}

	public function register(): void {
		add_shortcode('club_calendar', [$this, 'shortcode_club_calendar']);
		add_shortcode('club_events_list', [$this, 'shortcode_club_events_list']);
		add_shortcode('club_booking', [$this, 'shortcode_club_booking']);
	}

	public function shortcode_club_calendar(array $atts = []): string {
		$atts = shortcode_atts(
			[
				'category' => '',
				'view' => 'dayGridMonth',
				'initial_date' => '',
				'list_months' => '3',
			],
			$atts,
			'club_calendar'
		);

		$list_months = max(1, min(12, intval($atts['list_months'])));
		$id = 'clubflow-calendar-' . wp_generate_uuid4();

		$this->assets->maybe_enqueue_frontend_assets();

		$calendar = sprintf(
			'<div id="%s" class="clubflow-calendar" data-category="%s" data-view="%s" data-initial-date="%s" data-list-months="%d"></div>',
			esc_attr($id),
			esc_attr((string) $atts['category']),
			esc_attr((string) $atts['view']),
			esc_attr((string) $atts['initial_date']),
			$list_months
		);

		if ($this->modal_markup_rendered) {
			return $calendar;
		}

		$this->modal_markup_rendered = true;

		$modal = '';
		$modal .= '<div class="clubflow-modal" aria-hidden="true" style="display:none">';
		$modal .= '<div class="clubflow-modal__backdrop" data-clubflow-modal-close></div>';
		$modal .= '<div class="clubflow-modal__dialog-wrapper">';
		$modal .= '<button type="button" class="clubflow-modal__close" data-clubflow-modal-close aria-label="Close">&times;</button>';
		$modal .= '<div class="clubflow-modal__dialog" role="dialog" aria-modal="true" aria-label="Event">';
		$modal .= '<div class="clubflow-modal__content" data-clubflow-modal-content></div>';
		$modal .= '</div>';
		$modal .= '</div>';
		$modal .= '</div>';

		return $calendar . $modal;
	}

	public function shortcode_club_events_list(array $atts = []): string {
		$atts = shortcode_atts(
			[
				'category'     => '',
				'limit'        => 10,
				'order'        => 'ASC',
				'show_past'    => 'no',
			],
			$atts,
			'club_events_list'
		);

		$this->assets->maybe_enqueue_list_assets();

		$post_status = ['publish', 'future'];
		if (is_user_logged_in() && current_user_can('edit_posts')) {
			$post_status = 'any';
		}

		$args = [
			'post_type'      => ClubFlow::POST_TYPE,
			'post_status'    => $post_status,
			'posts_per_page' => intval($atts['limit']),
			'orderby'        => 'meta_value',
			'meta_key'       => '_clubflow_start',
			'order'          => strtoupper($atts['order']) === 'DESC' ? 'DESC' : 'ASC',
			'meta_query'     => [
				'relation' => 'AND',
				[
					'key'     => '_clubflow_start',
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

		if ($atts['category'] !== '') {
			$args['tax_query'] = [
				[
					'taxonomy' => ClubFlow::TAX_CATEGORY,
					'field'    => 'slug',
					'terms'    => array_map('trim', explode(',', (string) $atts['category'])),
				],
			];
		}

		if ($atts['show_past'] !== 'yes') {
			$args['meta_query'][] = [
				'key'     => '_clubflow_start',
				'value'   => current_time('Y-m-d H:i:s'),
				'compare' => '>=',
				'type'    => 'DATETIME',
			];
		}

		$query = new \WP_Query($args);

		if (!$query->have_posts()) {
			return '<p class="clubflow-list__empty">' . esc_html__('Inga kommande events.', 'clubflow') . '</p>';
		}

		$html = '<div class="clubflow-list">';

		while ($query->have_posts()) {
			$query->the_post();
			$post_id = get_the_ID();

			$start_meta = (string) get_post_meta($post_id, '_clubflow_start', true);
			$end_meta   = (string) get_post_meta($post_id, '_clubflow_end', true);
			$all_day    = (string) get_post_meta($post_id, '_clubflow_all_day', true);
			$location   = (string) get_post_meta($post_id, '_clubflow_location', true);

			$start_ts = strtotime($start_meta);
			$end_ts = strtotime($end_meta);
			$date_text = '';
			if ($start_ts !== false) {
				$has_end = ($end_meta !== '' && $end_ts !== false && $end_ts > $start_ts);

				if ($all_day === '1') {
					$start_text = wp_date(__('F j, Y', 'clubflow'), $start_ts);
					$date_text = $start_text;
					if ($has_end) {
						$end_text = wp_date(__('F j, Y', 'clubflow'), $end_ts);
						if ($end_text !== $start_text) {
							$date_text .= ' – ' . $end_text;
						}
					}
				} else {
					$start_text = wp_date(__('F j, Y \\a\\t H:i', 'clubflow'), $start_ts);
					$date_text = $start_text;
					if ($has_end) {
						$end_text = wp_date(__('F j, Y \\a\\t H:i', 'clubflow'), $end_ts);
						if ($end_text !== $start_text) {
							$date_text .= ' – ' . $end_text;
						}
					}
				}
			}

			$excerpt = get_the_excerpt();
			if (empty($excerpt)) {
				$content = get_the_content();
				$excerpt = $this->utils->safe_truncate_html($content, 100);
			}

			$full_content = apply_filters('the_content', get_the_content());

			$accent_color = '#3788d8';
			$categories = wp_get_post_terms($post_id, ClubFlow::TAX_CATEGORY);
			if (!empty($categories) && !is_wp_error($categories)) {
				$cat_color = get_term_meta($categories[0]->term_id, 'clubflow_category_color', true);
				if (!empty($cat_color)) {
					$accent_color = $cat_color;
				}
			}

			$html .= '<article class="clubflow-list__item" data-clubflow-list-item>';
			$html .= '<div class="clubflow-list__header" data-clubflow-list-toggle style="border-left-color: ' . esc_attr($accent_color) . ';">';
			$html .= '<div class="clubflow-list__info">';
			$html .= '<h3 class="clubflow-list__title">' . esc_html(get_the_title()) . '</h3>';
			$html .= '<div class="clubflow-list__meta">';
			if ($location !== '') {
				$html .= '<span class="clubflow-list__location">' . esc_html($location) . '</span>';
			}
			$html .= '</div>';
			$html .= '<div class="clubflow-list__excerpt">';
			if ($date_text !== '') {
				$html .= '<span class="clubflow-list__date-inline">' . esc_html($date_text) . '</span> ';
			}
			$html .= wp_kses_post($excerpt);
			$html .= '</div>';
			$html .= '</div>';
			$html .= '<div class="clubflow-list__chevron" aria-hidden="true">';
			$html .= '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>';
			$html .= '</div>';
			$html .= '</div>';
			$html .= '<div class="clubflow-list__content" data-clubflow-list-content aria-hidden="true">';
			$html .= '<div class="clubflow-list__content-inner">' . wp_kses_post($full_content) . '</div>';
			$html .= '<p class="clubflow-list__link"><a href="' . esc_url(get_permalink()) . '">' . esc_html__('Öppna eventsida', 'clubflow') . '</a></p>';
			$html .= '</div>';
			$html .= '</article>';
		}

		wp_reset_postdata();
		$html .= '</div>';
		return $html;
	}

	/**
	 * Shortcode: [club_booking id="123"]
	 * Renders a booking form for any event (calendar, product, or package)
	 * 
	 * Attributes:
	 *   popup="true" - Show as button that opens booking form in modal
	 *   label="..." - Custom label for popup button (defaults to event title)
	 */
	public function shortcode_club_booking(array $atts = []): string {
		$atts = shortcode_atts(
			[
				'id'           => 0,
				'show_price'   => 'true',
				'show_spots'   => 'true',
				'show_includes'=> 'true',
				'button_text'  => '',
				'popup'        => 'true',
				'label'        => '',
			],
			$atts,
			'club_booking'
		);

		$event_id = absint($atts['id']);
		if ($event_id <= 0) {
			return '<p class="clubflow-booking-error">' . esc_html__('Ogiltigt event-ID.', 'clubflow') . '</p>';
		}

		$post = get_post($event_id);
		if (!$post || $post->post_type !== ClubFlow::POST_TYPE) {
			return '<p class="clubflow-booking-error">' . esc_html__('Eventet hittades inte.', 'clubflow') . '</p>';
		}

		// Enqueue assets (force because shortcode may be on non-singular pages)
		$this->assets->force_enqueue_frontend_assets();

		$is_popup = filter_var($atts['popup'], FILTER_VALIDATE_BOOLEAN);

		// Popup mode: render a trigger button that opens the booking form in a modal
		if ($is_popup) {
			return $this->render_booking_popup_trigger($event_id, $atts);
		}

		$event_mode = get_post_meta($event_id, '_clubflow_event_mode', true) ?: 'calendar';
		$price = get_post_meta($event_id, '_clubflow_price', true);
		$max_spots = (int) get_post_meta($event_id, '_clubflow_max_spots', true);
		$linked_events = get_post_meta($event_id, '_clubflow_linked_events', true) ?: [];
		$price_amount = $price !== '' ? (float) preg_replace('/[^0-9.]/', '', (string) $price) : 0;

		$spots_remaining = null;
		$is_fully_booked = false;

		if (class_exists('ClubFlow_Booking')) {
			$spots_remaining = ClubFlow_Booking::get_spots_remaining($event_id);
			$is_fully_booked = ClubFlow_Booking::is_fully_booked($event_id);
		}

		$show_price = filter_var($atts['show_price'], FILTER_VALIDATE_BOOLEAN);
		$show_spots = filter_var($atts['show_spots'], FILTER_VALIDATE_BOOLEAN);
		$show_includes = filter_var($atts['show_includes'], FILTER_VALIDATE_BOOLEAN);
		$button_text = $atts['button_text'] ?: __('Boka nu', 'clubflow');

		$html = '<div class="clubflow-booking-widget" data-event-id="' . esc_attr($event_id) . '">';

		// Header
		$html .= '<div class="clubflow-booking-widget__header">';
		$html .= '<h3 class="clubflow-booking-widget__title">' . esc_html(get_the_title($event_id)) . '</h3>';

		// Meta line (price + spots)
		if (($show_price && $price) || ($show_spots && $spots_remaining !== null)) {
			$html .= '<p class="clubflow-booking-widget__meta">';
			if ($show_price && $price) {
				$html .= '<span class="clubflow-booking-widget__price">' . esc_html($price) . '</span>';
			}
			if ($show_spots && $spots_remaining !== null) {
				if ($show_price && $price) {
					$html .= ' <span class="clubflow-booking-widget__sep">•</span> ';
				}
				$spots_text = $is_fully_booked
					? __('Fullbokat', 'clubflow')
					: sprintf(__('%d platser kvar', 'clubflow'), $spots_remaining);
				$spots_class = $is_fully_booked ? ' clubflow-booking-widget__spots--full' : '';
				$html .= '<span class="clubflow-booking-widget__spots' . $spots_class . '">' . esc_html($spots_text) . '</span>';
			}
			$html .= '</p>';
		}
		$html .= '</div>';

		// For packages: show included events
		if ($event_mode === 'package' && $show_includes && !empty($linked_events)) {
			$html .= '<div class="clubflow-booking-widget__includes">';
			$html .= '<p class="clubflow-booking-widget__includes-label"><strong>' . esc_html__('Inkluderar:', 'clubflow') . '</strong></p>';
			$html .= '<ul class="clubflow-booking-widget__includes-list">';
			foreach ($linked_events as $linked_id) {
				$linked_post = get_post($linked_id);
				if (!$linked_post) continue;
				$linked_start = get_post_meta($linked_id, '_clubflow_start', true);
				$linked_date = $linked_start ? wp_date('D j M H:i', strtotime($linked_start)) : '';
				$html .= '<li>' . esc_html($linked_post->post_title);
				if ($linked_date) {
					$html .= ' <span class="clubflow-booking-widget__includes-date">— ' . esc_html($linked_date) . '</span>';
				}
				$html .= '</li>';
			}
			$html .= '</ul>';
			$html .= '</div>';
		}

		// Fully booked message
		if ($is_fully_booked) {
			$html .= '<div class="clubflow-booking-widget__full">';
			$html .= '<p>' . esc_html__('Detta event är fullbokat. Vänligen försök igen senare eller kontakta oss.', 'clubflow') . '</p>';
			$html .= '</div>';
		} else {
			// Booking form
			$html .= '<form class="clubflow-booking-widget__form" data-clubflow-booking-form '
				. 'data-clubflow-price-amount="' . esc_attr($price_amount) . '" '
				. 'data-clubflow-member-price-amount="0">';
			$html .= '<input type="hidden" name="event_id" value="' . esc_attr($event_id) . '" />';
			$html .= '<input type="hidden" name="return_url" value="' . esc_attr(home_url($_SERVER['REQUEST_URI'] ?? '')) . '" />';

			// Section 1: Personal details (always visible)
			$html .= '<fieldset class="clubflow-form-section clubflow-form-section--details">';
			$html .= '<legend class="clubflow-form-section__legend">' . esc_html__('Dina uppgifter', 'clubflow') . '</legend>';

			$html .= '<div class="clubflow-booking-widget__field">';
			$html .= '<label for="clubflow_widget_name_' . $event_id . '">' . esc_html__('Namn', 'clubflow') . ' <span class="required">*</span></label>';
			$html .= '<input type="text" id="clubflow_widget_name_' . $event_id . '" name="name" required />';
			$html .= '</div>';

			$html .= '<div class="clubflow-booking-widget__field">';
			$html .= '<label for="clubflow_widget_email_' . $event_id . '">' . esc_html__('E-post', 'clubflow') . ' <span class="required">*</span></label>';
			$html .= '<input type="email" id="clubflow_widget_email_' . $event_id . '" name="email" required />';
			$html .= '</div>';

			$html .= '<div class="clubflow-booking-widget__field">';
			$html .= '<label for="clubflow_widget_phone_' . $event_id . '">' . esc_html__('Telefon', 'clubflow') . ' <span class="required">*</span></label>';
			$html .= '<input type="tel" id="clubflow_widget_phone_' . $event_id . '" name="phone" required />';
			$html .= '</div>';

			$html .= '<div class="clubflow-booking-widget__field" data-clubflow-free-field style="display:none">';
			$html .= '<label for="clubflow_widget_street_' . $event_id . '">' . esc_html__('Gatuadress', 'clubflow') . ' <span class="required">*</span></label>';
			$html .= '<input type="text" id="clubflow_widget_street_' . $event_id . '" name="street" data-clubflow-free-required />';
			$html .= '</div>';

			$html .= '<div class="clubflow-booking-widget__field" data-clubflow-free-field style="display:none">';
			$html .= '<label for="clubflow_widget_postal_code_' . $event_id . '">' . esc_html__('Postnummer', 'clubflow') . ' <span class="required">*</span></label>';
			$html .= '<input type="text" id="clubflow_widget_postal_code_' . $event_id . '" name="postal_code" data-clubflow-free-required />';
			$html .= '</div>';

			$html .= '<div class="clubflow-booking-widget__field" data-clubflow-free-field style="display:none">';
			$html .= '<label for="clubflow_widget_city_' . $event_id . '">' . esc_html__('Ort', 'clubflow') . ' <span class="required">*</span></label>';
			$html .= '<input type="text" id="clubflow_widget_city_' . $event_id . '" name="city" data-clubflow-free-required />';
			$html .= '</div>';

			$html .= '</fieldset>'; // end Section 1

			// Section 2: Payment options (visually distinct)
			if ($event_mode !== 'package') {
				$html .= '<fieldset class="clubflow-form-section clubflow-form-section--options">';
				$html .= '<legend class="clubflow-form-section__legend">' . esc_html__('Betalningsalternativ', 'clubflow') . '</legend>';

				$html .= '<div class="clubflow-booking-widget__field clubflow-booking-widget__field--klippkort">';
				$html .= '<label class="clubflow-checkline"><input type="checkbox" name="use_klippkort" value="1" data-clubflow-klippkort-toggle /> ' . esc_html__('Använd klippkort', 'clubflow') . '</label>';
				$html .= '</div>';
				$html .= '<div class="clubflow-booking-widget__field clubflow-booking-widget__field--klippkort" data-clubflow-klippkort-code style="display:none; margin-top: -8px;">';
				$html .= '<label for="clubflow_widget_klippkort_code_' . $event_id . '">' . esc_html__('Klippkort kod (valfritt)', 'clubflow') . '</label>';
				$html .= '<input type="text" id="clubflow_widget_klippkort_code_' . $event_id . '" name="klippkort_code" placeholder="KLIPPKORT-ABC123" />';
				$html .= '</div>';
				$html .= '<div class="clubflow-booking-widget__field clubflow-booking-widget__field--klippkort">';
				$html .= '<label class="clubflow-checkline"><input type="checkbox" name="pay_later" value="1" /> ' . esc_html__('Faktura/Epassi', 'clubflow') . '</label>';
				$html .= '</div>';
				$html .= '<div class="clubflow-booking-widget__field clubflow-booking-widget__field--klippkort">';
				$html .= '<label class="clubflow-checkline"><input type="checkbox" name="instructor_student" value="1" /> ' . esc_html__('Instruktör/Student (10%)', 'clubflow') . '</label>';
				$html .= '</div>';

				$html .= '<div class="clubflow-booking-widget__field">';
				$html .= '<label for="clubflow_widget_notes_' . $event_id . '">' . esc_html__('Kommentar / önskemål', 'clubflow') . '</label>';
				$html .= '<textarea id="clubflow_widget_notes_' . $event_id . '" name="order_notes" rows="3" maxlength="1000" placeholder="' . esc_attr__('Valfri kommentar till din bokning...', 'clubflow') . '"></textarea>';
				$html .= '</div>';

				$html .= '</fieldset>'; // end Section 2
			} else {
				// Package mode: no payment options, just the notes field
				$html .= '<div class="clubflow-booking-widget__field">';
				$html .= '<label for="clubflow_widget_notes_' . $event_id . '">' . esc_html__('Kommentar / önskemål', 'clubflow') . '</label>';
				$html .= '<textarea id="clubflow_widget_notes_' . $event_id . '" name="order_notes" rows="3" maxlength="1000" placeholder="' . esc_attr__('Valfri kommentar till din bokning...', 'clubflow') . '"></textarea>';
				$html .= '</div>';
			}

			$html .= '<div class="clubflow-booking-widget__submit">';
			$html .= '<button type="submit" class="clubflow-booking-widget__button">' . esc_html($button_text) . '</button>';
			$html .= '</div>';

			$html .= '<div class="clubflow-booking__message clubflow-booking-widget__message" data-clubflow-booking-message style="display: none;"></div>';
			$html .= '</form>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Render a popup trigger button for booking
	 * Clicking opens the full booking form in a modal
	 */
	private function render_booking_popup_trigger(int $event_id, array $atts): string {
		$price = get_post_meta($event_id, '_clubflow_price', true);
		$member_price = get_post_meta($event_id, '_clubflow_member_price', true);
		$event_mode = get_post_meta($event_id, '_clubflow_event_mode', true) ?: 'calendar';
		$linked_events = get_post_meta($event_id, '_clubflow_linked_events', true) ?: [];

		$spots_remaining = null;
		$is_fully_booked = false;
		if (class_exists('ClubFlow_Booking')) {
			$spots_remaining = ClubFlow_Booking::get_spots_remaining($event_id);
			$is_fully_booked = ClubFlow_Booking::is_fully_booked($event_id);
		}

		$show_price = filter_var($atts['show_price'], FILTER_VALIDATE_BOOLEAN);
		$show_spots = filter_var($atts['show_spots'], FILTER_VALIDATE_BOOLEAN);

		// Custom label or fall back to event title
		$label = $atts['label'] ?: get_the_title($event_id);
		$button_text = $atts['button_text'] ?: __('Boka nu', 'clubflow');

		// For packages, count included events
		$includes_count = ($event_mode === 'package' && !empty($linked_events)) ? count($linked_events) : 0;

		$html = '<div class="clubflow-booking-popup" data-event-id="' . esc_attr($event_id) . '">';
		
		// Main clickable card
		$disabled_class = $is_fully_booked ? ' clubflow-booking-popup--disabled' : '';
		$html .= '<button type="button" class="clubflow-booking-popup__trigger' . $disabled_class . '" ';
		$html .= 'data-clubflow-booking-popup="' . esc_attr($event_id) . '" ';
		if ($is_fully_booked) {
			$html .= 'disabled ';
		}
		$html .= '>';

		// Content wrapper
		$html .= '<span class="clubflow-booking-popup__content">';
		$html .= '<span class="clubflow-booking-popup__label">' . esc_html($label) . '</span>';

		// Meta info (price, spots, includes count)
		$meta_parts = [];
		if ($show_price && $price) {
			$price_text = esc_html($price);
			if ($member_price) {
				$price_text .= ' / ' . esc_html($member_price) . ' ' . __('(medlem)', 'clubflow');
			}
			$meta_parts[] = '<span class="clubflow-booking-popup__price">' . $price_text . '</span>';
		}
		if ($includes_count > 0) {
			$meta_parts[] = '<span class="clubflow-booking-popup__includes">' . 
				sprintf(_n('%d klass', '%d klasser', $includes_count, 'clubflow'), $includes_count) . 
				'</span>';
		}
		if ($show_spots && $spots_remaining !== null) {
			if ($is_fully_booked) {
				$meta_parts[] = '<span class="clubflow-booking-popup__spots clubflow-booking-popup__spots--full">' . 
					esc_html__('Fullbokat', 'clubflow') . '</span>';
			} else {
				$meta_parts[] = '<span class="clubflow-booking-popup__spots">' . 
					sprintf(__('%d platser', 'clubflow'), $spots_remaining) . '</span>';
			}
		}

		if (!empty($meta_parts)) {
			$html .= '<span class="clubflow-booking-popup__meta">' . implode(' • ', $meta_parts) . '</span>';
		}

		$html .= '</span>'; // /__content

		// Button/arrow
		if (!$is_fully_booked) {
			$html .= '<span class="clubflow-booking-popup__action">' . esc_html($button_text) . '</span>';
		}

		$html .= '</button>';
		$html .= '</div>';

		// Ensure modal markup exists on the page
		if (!$this->modal_markup_rendered) {
			$this->modal_markup_rendered = true;
			$html .= '<div class="clubflow-modal" aria-hidden="true" style="display:none">';
			$html .= '<div class="clubflow-modal__backdrop" data-clubflow-modal-close></div>';
			$html .= '<div class="clubflow-modal__dialog-wrapper">';
			$html .= '<button type="button" class="clubflow-modal__close" data-clubflow-modal-close aria-label="Close">&times;</button>';
			$html .= '<div class="clubflow-modal__dialog" role="dialog" aria-modal="true" aria-label="Event">';
			$html .= '<div class="clubflow-modal__content" data-clubflow-modal-content></div>';
			$html .= '</div>';
			$html .= '</div>';
			$html .= '</div>';
		}

		return $html;
	}
}
