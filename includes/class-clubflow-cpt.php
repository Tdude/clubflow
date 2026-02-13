<?php

if (!defined('ABSPATH')) {
	exit;
}

final class ClubFlow_Cpt {
	public function register(): void {
		add_action('init', [$this, 'register_cpt_and_taxonomies']);
		
		// Admin columns
		add_filter('manage_' . ClubFlow::POST_TYPE . '_posts_columns', [$this, 'add_admin_columns']);
		add_action('manage_' . ClubFlow::POST_TYPE . '_posts_custom_column', [$this, 'render_admin_columns'], 10, 2);
		add_filter('manage_edit-' . ClubFlow::POST_TYPE . '_sortable_columns', [$this, 'sortable_columns']);
		add_action('pre_get_posts', [$this, 'sort_by_event_date']);
	}

	/**
	 * Add custom columns to admin list
	 */
	public function add_admin_columns(array $columns): array {
		$new_columns = [];
		foreach ($columns as $key => $value) {
			if ($key === 'date') {
				// Insert our columns before WP date column
				$new_columns['event_mode'] = __('Mode', 'clubflow');
				$new_columns['event_date'] = __('Event Date', 'clubflow');
				$new_columns['event_time'] = __('Time', 'clubflow');
				$new_columns['event_category'] = __('Category', 'clubflow');
				$new_columns['event_price'] = __('Price', 'clubflow');
			}
			$new_columns[$key] = $value;
		}
		// Remove the WP "Date" column (confusing - shows publish date)
		unset($new_columns['date']);
		return $new_columns;
	}

	/**
	 * Render custom column content
	 */
	public function render_admin_columns(string $column, int $post_id): void {
		switch ($column) {
			case 'event_mode':
				$mode = get_post_meta($post_id, '_clubflow_event_mode', true) ?: 'calendar';
				$mode_labels = [
					'calendar' => ['📅', __('Calendar', 'clubflow')],
					'product'  => ['🛒', __('Product', 'clubflow')],
					'package'  => ['📦', __('Package', 'clubflow')],
				];
				$label = $mode_labels[$mode] ?? $mode_labels['calendar'];
				echo '<span title="' . esc_attr($label[1]) . '">' . esc_html($label[0]) . '</span>';
				
				// Show linked events count for packages
				if ($mode === 'package') {
					$linked = get_post_meta($post_id, '_clubflow_linked_events', true) ?: [];
					if (!empty($linked)) {
						echo ' <small style="color:#666;">(' . count($linked) . ')</small>';
					}
				}
				break;

			case 'event_date':
				$start = get_post_meta($post_id, '_clubflow_start', true);
				if ($start) {
					$date = date_i18n('Y-m-d', strtotime($start));
					$is_past = strtotime($start) < time();
					$style = $is_past ? 'color: #999;' : 'font-weight: 500;';
					echo '<span style="' . esc_attr($style) . '">' . esc_html($date) . '</span>';
					
					// Show recurring indicator
					$parent = get_post_meta($post_id, '_clubflow_recurrence_parent', true);
					$is_recurring = get_post_meta($post_id, '_clubflow_recurrence_enabled', true) === '1';
					if ($parent) {
						echo ' <span title="' . esc_attr__('Part of recurring series', 'clubflow') . '">🔁</span>';
					} elseif ($is_recurring) {
						echo ' <span title="' . esc_attr__('Recurring event (parent)', 'clubflow') . '">📅</span>';
					}
				} else {
					echo '<span style="color: #999;">—</span>';
				}
				break;
				
			case 'event_time':
				$start = get_post_meta($post_id, '_clubflow_start', true);
				$all_day = get_post_meta($post_id, '_clubflow_all_day', true);
				if ($all_day === '1') {
					echo '<span style="color: #666;">' . esc_html__('All day', 'clubflow') . '</span>';
				} elseif ($start) {
					echo esc_html(date_i18n('H:i', strtotime($start)));
				} else {
					echo '<span style="color: #999;">—</span>';
				}
				break;
				
			case 'event_category':
				$terms = get_the_terms($post_id, ClubFlow::TAX_CATEGORY);
				if ($terms && !is_wp_error($terms)) {
					$cats = [];
					foreach ($terms as $term) {
						$color = get_term_meta($term->term_id, 'clubflow_category_color', true) ?: '#3788d8';
						$cats[] = '<span style="display:inline-block; padding:2px 8px; background:' . esc_attr($color) . '22; border-left:3px solid ' . esc_attr($color) . '; border-radius:2px;">' . esc_html($term->name) . '</span>';
					}
					echo implode(' ', $cats);
				} else {
					echo '<span style="color: #999;">—</span>';
				}
				break;
				
			case 'event_price':
				$price = get_post_meta($post_id, '_clubflow_price', true);
				if ($price) {
					echo '<strong>' . esc_html($price) . '</strong>';
				} else {
					echo '<span style="color: #999;">—</span>';
				}
				break;
		}
	}

	/**
	 * Make event_date column sortable
	 */
	public function sortable_columns(array $columns): array {
		$columns['event_date'] = 'event_date';
		return $columns;
	}

	/**
	 * Handle sorting by event date
	 */
	public function sort_by_event_date(\WP_Query $query): void {
		if (!is_admin() || !$query->is_main_query()) {
			return;
		}

		if ($query->get('post_type') !== ClubFlow::POST_TYPE) {
			return;
		}

		$orderby = $query->get('orderby');

		// Default sort: by event date ascending (upcoming first)
		if (empty($orderby)) {
			$query->set('meta_key', '_clubflow_start');
			$query->set('orderby', 'meta_value');
			$query->set('order', 'ASC');
		} elseif ($orderby === 'event_date') {
			$query->set('meta_key', '_clubflow_start');
			$query->set('orderby', 'meta_value');
		}
	}

	public function register_cpt_and_taxonomies(): void {
		$labels = [
			'name' => __('Events', 'clubflow'),
			'singular_name' => __('Event', 'clubflow'),
			'menu_name' => 'ClubFlow',
			'name_admin_bar' => __('Event', 'clubflow'),
			'add_new' => __('Add New', 'clubflow'),
			'add_new_item' => __('Add New Event', 'clubflow'),
			'new_item' => __('New Event', 'clubflow'),
			'edit_item' => __('Edit Event', 'clubflow'),
			'view_item' => __('View Event', 'clubflow'),
			'all_items' => __('All Events', 'clubflow'),
			'search_items' => __('Search Events', 'clubflow'),
			'not_found' => __('No events found.', 'clubflow'),
			'not_found_in_trash' => __('No events found in Trash.', 'clubflow'),
		];

		$args = [
			'labels' => $labels,
			'public' => true,
			'show_in_rest' => true,
			'has_archive' => true,
			'menu_icon' => 'dashicons-calendar-alt',
			'supports' => ['title', 'editor', 'thumbnail', 'excerpt', 'custom-fields'],
			'rewrite' => ['slug' => 'events'],
		];

		register_post_type(ClubFlow::POST_TYPE, $args);

		$cat_labels = [
			'name' => __('Event Categories', 'clubflow'),
			'singular_name' => __('Event Category', 'clubflow'),
		];

		register_taxonomy(
			ClubFlow::TAX_CATEGORY,
			[ClubFlow::POST_TYPE],
			[
				'labels' => $cat_labels,
				'public' => true,
				'show_in_rest' => true,
				'hierarchical' => true,
				'rewrite' => ['slug' => 'event-category'],
			]
		);

		$tag_labels = [
			'name' => __('Event Tags', 'clubflow'),
			'singular_name' => __('Event Tag', 'clubflow'),
		];

		register_taxonomy(
			ClubFlow::TAX_TAG,
			[ClubFlow::POST_TYPE],
			[
				'labels' => $tag_labels,
				'public' => true,
				'show_in_rest' => true,
				'hierarchical' => false,
				'rewrite' => ['slug' => 'event-tag'],
			]
		);
	}
}
