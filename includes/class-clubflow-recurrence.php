<?php
/**
 * ClubFlow Recurrence Handler
 *
 * Generates and manages recurring events.
 *
 * @package ClubFlow
 * @since 0.2.0
 */

if (!defined('ABSPATH')) {
	exit;
}

final class ClubFlow_Recurrence {

	/**
	 * Days of week mapping
	 */
	private const DAYS = [
		'mon' => 1,
		'tue' => 2,
		'wed' => 3,
		'thu' => 4,
		'fri' => 5,
		'sat' => 6,
		'sun' => 7,
	];

	/**
	 * Register hooks
	 */
	public function register(): void {
		add_action('save_post_' . ClubFlow::POST_TYPE, [$this, 'handle_recurrence_save'], 20, 2);
		add_action('before_delete_post', [$this, 'handle_parent_delete']);
		
		// Admin AJAX for manual generation
		add_action('wp_ajax_clubflow_generate_recurring', [$this, 'ajax_generate_recurring']);
		
		// Purge cron
		add_action('clubflow_purge_old_events', [$this, 'purge_old_events']);
		
		// Schedule purge if not already scheduled
		if (!wp_next_scheduled('clubflow_purge_old_events')) {
			wp_schedule_event(time(), 'daily', 'clubflow_purge_old_events');
		}
	}

	/**
	 * Handle save of recurring event
	 */
	public function handle_recurrence_save(int $post_id, \WP_Post $post): void {
		// Prevent multiple executions per request
		static $processed = [];
		if (isset($processed[$post_id])) {
			return;
		}
		$processed[$post_id] = true;
		
		// Skip autosave
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		// Skip AJAX
		if (wp_doing_ajax()) {
			return;
		}

		// Skip revisions
		if (wp_is_post_revision($post_id)) {
			return;
		}

		// Skip if this is a child event
		if (get_post_meta($post_id, '_clubflow_recurrence_parent', true)) {
			return;
		}

		// Check if recurrence is enabled
		$enabled = isset($_POST['clubflow_recurrence_enabled']) && $_POST['clubflow_recurrence_enabled'] === '1';
		update_post_meta($post_id, '_clubflow_recurrence_enabled', $enabled ? '1' : '0');

		if (!$enabled) {
			return;
		}

		// Save recurrence settings
		$type = sanitize_text_field($_POST['clubflow_recurrence_type'] ?? 'weekly');
		$days = isset($_POST['clubflow_recurrence_days']) ? array_map('sanitize_text_field', (array) $_POST['clubflow_recurrence_days']) : [];
		$until = sanitize_text_field($_POST['clubflow_recurrence_until'] ?? '');
		
		update_post_meta($post_id, '_clubflow_recurrence_type', $type);
		update_post_meta($post_id, '_clubflow_recurrence_days', $days);
		update_post_meta($post_id, '_clubflow_recurrence_until', $until);

		// Generate events if requested
		if (isset($_POST['clubflow_generate_recurring']) && $_POST['clubflow_generate_recurring'] === '1') {
			$this->generate_recurring_events($post_id);
		}
	}

	/**
	 * Generate recurring events from a parent event
	 */
	public function generate_recurring_events(int $parent_id, bool $delete_existing = false): array {
		// Prevent double-execution
		static $generating = [];
		if (isset($generating[$parent_id])) {
			return ['success' => false, 'error' => 'Already generating'];
		}
		$generating[$parent_id] = true;

		$parent = get_post($parent_id);
		if (!$parent || $parent->post_type !== ClubFlow::POST_TYPE) {
			unset($generating[$parent_id]);
			return ['success' => false, 'error' => 'Invalid parent event'];
		}

		// Get recurrence settings
		$type = get_post_meta($parent_id, '_clubflow_recurrence_type', true) ?: 'weekly';
		$days = get_post_meta($parent_id, '_clubflow_recurrence_days', true) ?: [];
		$until = get_post_meta($parent_id, '_clubflow_recurrence_until', true);

		// Safety: weekly requires at least one day selected
		if ($type === 'weekly' && empty($days)) {
			unset($generating[$parent_id]);
			return ['success' => false, 'error' => 'No days selected for weekly recurrence'];
		}

		if (empty($until)) {
			// Default: 3 months ahead
			$until = date('Y-m-d', strtotime('+3 months'));
		}

		// Safety: max 6 months ahead
		$max_until = date('Y-m-d', strtotime('+6 months'));
		if ($until > $max_until) {
			$until = $max_until;
		}

		// Get parent event time
		$parent_start = get_post_meta($parent_id, '_clubflow_start', true);
		$parent_end = get_post_meta($parent_id, '_clubflow_end', true);
		
		if (empty($parent_start)) {
			return ['success' => false, 'error' => 'Parent event has no start date'];
		}

		$start_time = date('H:i:s', strtotime($parent_start));
		$end_time = $parent_end ? date('H:i:s', strtotime($parent_end)) : null;
		$duration = $parent_end ? strtotime($parent_end) - strtotime($parent_start) : 3600; // Default 1 hour

		// Delete existing children if requested
		if ($delete_existing) {
			$this->delete_child_events($parent_id);
		}

		// Get existing child dates to avoid duplicates
		$existing_dates = $this->get_existing_child_dates($parent_id);
		
		// Safety: check total children - hard cap at 200
		$existing_count = count($existing_dates);
		if ($existing_count >= 200) {
			unset($generating[$parent_id]);
			return ['success' => false, 'error' => 'Maximum recurring events reached (200)'];
		}
		$max_new = 200 - $existing_count;

		// Generate dates
		$dates = $this->generate_dates($type, $days, $parent_start, $until);
		
		$created = 0;
		$skipped = 0;

		foreach ($dates as $date) {
			// Safety: respect max new events
			if ($created >= $max_new) {
				break;
			}
			
			$date_key = $date->format('Y-m-d');
			
			// Skip if already exists
			if (in_array($date_key, $existing_dates)) {
				$skipped++;
				continue;
			}

			// Skip the parent's own date
			if ($date_key === date('Y-m-d', strtotime($parent_start))) {
				continue;
			}

			// Create child event
			$child_start = $date->format('Y-m-d') . ' ' . $start_time;
			$child_end = $end_time ? $date->format('Y-m-d') . ' ' . $end_time : date('Y-m-d H:i:s', strtotime($child_start) + $duration);

			$child_id = $this->create_child_event($parent_id, $child_start, $child_end);
			
			if ($child_id) {
				$created++;
			}
		}

		unset($generating[$parent_id]);
		
		return [
			'success' => true,
			'created' => $created,
			'skipped' => $skipped,
		];
	}

	/**
	 * Generate dates based on recurrence type
	 */
	private function generate_dates(string $type, array $days, string $start_date, string $until): array {
		$dates = [];
		$current = new DateTime($start_date);
		$end = new DateTime($until);

		// Safety limits
		$max_events = 100; // Hard cap at 100 events per generation
		$count = 0;

		while ($current <= $end && $count < $max_events) {
			if ($type === 'daily') {
				$dates[] = clone $current;
				$current->modify('+1 day');
			} elseif ($type === 'weekly') {
				$day_of_week = strtolower($current->format('D'));
				$day_key = substr($day_of_week, 0, 3);
				
				if (in_array($day_key, $days)) {
					$dates[] = clone $current;
				}
				$current->modify('+1 day');
			}
			$count++;
		}

		return $dates;
	}

	/**
	 * Create a child event
	 */
	private function create_child_event(int $parent_id, string $start, string $end): ?int {
		$parent = get_post($parent_id);

		// Copy parent data
		$child_data = [
			'post_type'    => ClubFlow::POST_TYPE,
			'post_status'  => $parent->post_status,
			'post_title'   => $parent->post_title,
			'post_content' => $parent->post_content,
			'post_excerpt' => $parent->post_excerpt,
		];

		$child_id = wp_insert_post($child_data);

		if (is_wp_error($child_id) || !$child_id) {
			return null;
		}

		// Copy meta fields
		$meta_keys = [
			'_clubflow_location',
			'_clubflow_all_day',
			'_clubflow_max_spots',
			'_clubflow_price',
			'_clubflow_booking_enabled',
		];

		foreach ($meta_keys as $key) {
			$value = get_post_meta($parent_id, $key, true);
			if ($value !== '') {
				update_post_meta($child_id, $key, $value);
			}
		}

		// Set dates
		update_post_meta($child_id, '_clubflow_start', $start);
		update_post_meta($child_id, '_clubflow_end', $end);

		// Link to parent
		update_post_meta($child_id, '_clubflow_recurrence_parent', $parent_id);

		// Copy categories
		$categories = wp_get_object_terms($parent_id, ClubFlow::TAX_CATEGORY, ['fields' => 'ids']);
		if (!is_wp_error($categories) && !empty($categories)) {
			wp_set_object_terms($child_id, $categories, ClubFlow::TAX_CATEGORY);
		}

		return $child_id;
	}

	/**
	 * Get existing child event dates
	 */
	private function get_existing_child_dates(int $parent_id): array {
		$children = get_posts([
			'post_type'      => ClubFlow::POST_TYPE,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'meta_query'     => [
				[
					'key'   => '_clubflow_recurrence_parent',
					'value' => $parent_id,
				],
			],
			'fields' => 'ids',
		]);

		$dates = [];
		foreach ($children as $child_id) {
			$start = get_post_meta($child_id, '_clubflow_start', true);
			if ($start) {
				$dates[] = date('Y-m-d', strtotime($start));
			}
		}

		return $dates;
	}

	/**
	 * Delete child events of a parent
	 */
	public function delete_child_events(int $parent_id, bool $only_future = false): int {
		$args = [
			'post_type'      => ClubFlow::POST_TYPE,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'meta_query'     => [
				[
					'key'   => '_clubflow_recurrence_parent',
					'value' => $parent_id,
				],
			],
			'fields' => 'ids',
		];

		if ($only_future) {
			$args['meta_query'][] = [
				'key'     => '_clubflow_start',
				'value'   => current_time('mysql'),
				'compare' => '>=',
				'type'    => 'DATETIME',
			];
		}

		$children = get_posts($args);
		$deleted = 0;

		foreach ($children as $child_id) {
			// Check if has bookings
			$bookings = ClubFlow_Booking::get_booking_count($child_id);
			if ($bookings > 0) {
				continue; // Don't delete events with bookings
			}

			wp_delete_post($child_id, true);
			$deleted++;
		}

		return $deleted;
	}

	/**
	 * Handle parent deletion - unlink children
	 */
	public function handle_parent_delete(int $post_id): void {
		$post = get_post($post_id);
		if (!$post || $post->post_type !== ClubFlow::POST_TYPE) {
			return;
		}

		// Unlink children (don't delete - they might have bookings)
		$children = get_posts([
			'post_type'      => ClubFlow::POST_TYPE,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'meta_query'     => [
				[
					'key'   => '_clubflow_recurrence_parent',
					'value' => $post_id,
				],
			],
			'fields' => 'ids',
		]);

		foreach ($children as $child_id) {
			delete_post_meta($child_id, '_clubflow_recurrence_parent');
		}
	}

	/**
	 * Purge old events (daily cron)
	 * 
	 * Deletes events that are:
	 * - More than X days in the past
	 * - Have no bookings
	 */
	public function purge_old_events(): void {
		$days_to_keep = apply_filters('clubflow_purge_days', 30); // Keep 30 days by default
		$cutoff = date('Y-m-d H:i:s', strtotime("-{$days_to_keep} days"));

		$old_events = get_posts([
			'post_type'      => ClubFlow::POST_TYPE,
			'post_status'    => 'any',
			'posts_per_page' => 100, // Batch limit
			'meta_query'     => [
				[
					'key'     => '_clubflow_start',
					'value'   => $cutoff,
					'compare' => '<',
					'type'    => 'DATETIME',
				],
			],
			'fields' => 'ids',
		]);

		$deleted = 0;
		$kept = 0;

		foreach ($old_events as $event_id) {
			// Never delete events with bookings
			$bookings = ClubFlow_Booking::get_booking_count($event_id);
			if ($bookings > 0) {
				$kept++;
				continue;
			}

			// Never delete parent recurring events
			$is_parent = get_post_meta($event_id, '_clubflow_recurrence_enabled', true) === '1';
			if ($is_parent) {
				$kept++;
				continue;
			}

			wp_delete_post($event_id, true);
			$deleted++;
		}

		if ($deleted > 0 || $kept > 0) {
			error_log("ClubFlow purge: deleted {$deleted} old events, kept {$kept} (have bookings or are recurring parents)");
		}
	}

	/**
	 * AJAX handler for manual generation
	 */
	public function ajax_generate_recurring(): void {
		check_ajax_referer('clubflow_admin', '_ajax_nonce');

		if (!current_user_can('edit_posts')) {
			wp_send_json_error('Unauthorized');
		}

		$parent_id = isset($_POST['parent_id']) ? absint($_POST['parent_id']) : 0;
		$delete_existing = isset($_POST['delete_existing']) && $_POST['delete_existing'] === '1';

		if (!$parent_id) {
			wp_send_json_error('Missing parent ID');
		}

		$result = $this->generate_recurring_events($parent_id, $delete_existing);

		if ($result['success']) {
			wp_send_json_success($result);
		} else {
			wp_send_json_error($result['error']);
		}
	}

	/**
	 * Get count of child events
	 */
	public static function get_child_count(int $parent_id): int {
		return count(get_posts([
			'post_type'      => ClubFlow::POST_TYPE,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'meta_query'     => [
				[
					'key'   => '_clubflow_recurrence_parent',
					'value' => $parent_id,
				],
			],
			'fields' => 'ids',
		]));
	}
}
