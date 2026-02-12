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
		$modal .= '<div class="clubflow-modal__dialog" role="dialog" aria-modal="true" aria-label="Event">';
		$modal .= '<button type="button" class="clubflow-modal__close" data-clubflow-modal-close aria-label="Close">&times;</button>';
		$modal .= '<div class="clubflow-modal__content" data-clubflow-modal-content></div>';
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
				[
					'key'     => '_clubflow_start',
					'compare' => 'EXISTS',
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
			return '<p class="clubflow-list__empty">' . esc_html__('No upcoming events.', 'clubflow') . '</p>';
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
			$html .= '<p class="clubflow-list__link"><a href="' . esc_url(get_permalink()) . '">' . esc_html__('Open event page', 'clubflow') . '</a></p>';
			$html .= '</div>';
			$html .= '</article>';
		}

		wp_reset_postdata();
		$html .= '</div>';
		return $html;
	}
}
