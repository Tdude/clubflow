<?php

if (!defined('ABSPATH')) {
	exit;
}

final class ClubFlow_Admin {
	private ClubFlow_Utils $utils;

	public function __construct(ClubFlow_Utils $utils) {
		$this->utils = $utils;
	}

	public function register(): void {
		add_action('add_meta_boxes', [$this, 'register_meta_boxes']);
		add_action('save_post_' . ClubFlow::POST_TYPE, [$this, 'save_meta_boxes']);
		add_action('admin_menu', [$this, 'register_settings_page']);
	}

	public function register_settings_page(): void {
		add_options_page(
			__( 'ClubFlow', 'clubflow' ),
			__( 'ClubFlow', 'clubflow' ),
			'manage_options',
			'clubflow',
			[$this, 'render_settings_page']
		);
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$readme_excerpt = $this->get_readme_help_excerpt();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'ClubFlow', 'clubflow' ); ?></h1>
			<h2 style="margin-top: 18px;"><?php echo esc_html__( 'Shortcode usage', 'clubflow' ); ?></h2>
			<div style="max-width: 980px; background: #fff; border: 1px solid #dcdcde; padding: 18px; border-radius: 8px; line-height: 1.6;">
				<?php if ( $readme_excerpt !== '' ) : ?>
					<pre style="white-space: pre-wrap; margin: 0;"><?php echo esc_html( $readme_excerpt ); ?></pre>
				<?php else : ?>
					<p style="margin-top: 0;"><?php echo esc_html__( 'No shortcode usage information found in the README files.', 'clubflow' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private function get_readme_help_excerpt(): string {
		$plugin_root = dirname( __DIR__ );
		$locale = function_exists( 'determine_locale' ) ? (string) determine_locale() : (string) get_locale();
		$locale_lower = strtolower( $locale );
		$is_swedish = ( strpos( $locale_lower, 'sv' ) === 0 );

		$preferred = $is_swedish ? $plugin_root . '/README-SVENSKA.md' : $plugin_root . '/README.md';
		$fallback = $is_swedish ? $plugin_root . '/README.md' : $plugin_root . '/README-SVENSKA.md';

		$preferred_content = file_exists( $preferred ) ? (string) file_get_contents( $preferred ) : '';
		$fallback_content = file_exists( $fallback ) ? (string) file_get_contents( $fallback ) : '';

		$sections = array(
			'## Shortcode options',
			'## Calendar Shortcode Updated',
		);

		$excerpt = $this->build_readme_excerpt_from_content( $preferred_content, $sections );
		if ( $excerpt !== '' ) {
			return $excerpt;
		}

		return $this->build_readme_excerpt_from_content( $fallback_content, $sections );
	}

	private function build_readme_excerpt_from_content( string $content, array $sections ): string {
		if ( $content === '' ) {
			return '';
		}

		$out = array();
		foreach ( $sections as $heading ) {
			$section = $this->extract_readme_section( $content, (string) $heading );
			if ( $section !== '' ) {
				$out[] = trim( $section );
			}
		}

		return trim( implode( "\n\n", $out ) );
	}

	private function extract_readme_section( string $content, string $heading ): string {
		$pos = stripos( $content, $heading );
		if ( $pos === false ) {
			return '';
		}

		$start = $pos;
		$next_pos = strpos( $content, "\n## ", $start + strlen( $heading ) );
		$end = $next_pos === false ? strlen( $content ) : $next_pos + 1;

		return substr( $content, $start, $end - $start );
	}

	public function register_meta_boxes(): void {
		add_meta_box(
			'clubflow_event_details',
			__('Event Details', 'clubflow'),
			[$this, 'render_event_details_meta_box'],
			ClubFlow::POST_TYPE,
			'normal',
			'high'
		);

		add_action(ClubFlow::TAX_CATEGORY . '_add_form_fields', [$this, 'render_category_color_field_add']);
		add_action(ClubFlow::TAX_CATEGORY . '_edit_form_fields', [$this, 'render_category_color_field_edit']);
		add_action('created_' . ClubFlow::TAX_CATEGORY, [$this, 'save_category_color']);
		add_action('edited_' . ClubFlow::TAX_CATEGORY, [$this, 'save_category_color']);
	}

	public function render_category_color_field_add(): void {
		?>
		<div class="form-field">
			<label for="clubflow_category_color"><?php esc_html_e('Color', 'clubflow'); ?></label>
			<input type="color" id="clubflow_category_color" name="clubflow_category_color" value="#3788d8" />
			<p class="description"><?php esc_html_e('Choose a color for events in this category.', 'clubflow'); ?></p>
		</div>
		<?php
	}

	public function render_category_color_field_edit(\WP_Term $term): void {
		$color = get_term_meta($term->term_id, 'clubflow_category_color', true);
		if (empty($color)) {
			$color = '#3788d8';
		}
		?>
		<tr class="form-field">
			<th scope="row">
				<label for="clubflow_category_color"><?php esc_html_e('Color', 'clubflow'); ?></label>
			</th>
			<td>
				<input type="color" id="clubflow_category_color" name="clubflow_category_color" value="<?php echo esc_attr($color); ?>" />
				<p class="description"><?php esc_html_e('Choose a color for events in this category.', 'clubflow'); ?></p>
			</td>
		</tr>
		<?php
	}

	public function save_category_color(int $term_id): void {
		if (!isset($_POST['clubflow_category_color'])) {
			return;
		}

		if (!current_user_can('manage_categories')) {
			return;
		}

		$color = sanitize_hex_color($_POST['clubflow_category_color']);
		if ($color) {
			update_term_meta($term_id, 'clubflow_category_color', $color);
		} else {
			delete_term_meta($term_id, 'clubflow_category_color');
		}
	}

	public function render_event_details_meta_box(\WP_Post $post): void {
		wp_nonce_field('clubflow_save_event_details', 'clubflow_event_details_nonce');

		$start = $this->utils->format_datetime_for_input((string) get_post_meta($post->ID, '_clubflow_start', true));
		$end = $this->utils->format_datetime_for_input((string) get_post_meta($post->ID, '_clubflow_end', true));
		$all_day = get_post_meta($post->ID, '_clubflow_all_day', true);
		$location = get_post_meta($post->ID, '_clubflow_location', true);

		$all_day_checked = (($all_day === '1') || ($all_day === '' && $post->post_status === 'auto-draft')) ? 'checked' : '';

		echo '<p>';
		echo '<label for="clubflow_start"><strong>' . esc_html__('Start date/time', 'clubflow') . '</strong></label><br />';
		echo '<input type="datetime-local" id="clubflow_start" name="clubflow_start" value="' . esc_attr($start) . '" style="width: 100%; max-width: 320px;" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="clubflow_end"><strong>' . esc_html__('End date/time', 'clubflow') . '</strong> <span style="font-weight: normal; color: #666;">(' . esc_html__('optional', 'clubflow') . ')</span></label><br />';
		echo '<input type="datetime-local" id="clubflow_end" name="clubflow_end" value="' . esc_attr($end) . '" style="width: 100%; max-width: 320px;" />';
		echo '<p class="description" style="margin-top: 4px;">' . esc_html__('Leave empty for single-day events.', 'clubflow') . '</p>';
		echo '</p>';

		echo '<p>';
		echo '<label for="clubflow_all_day">';
		echo '<input type="checkbox" id="clubflow_all_day" name="clubflow_all_day" value="1" ' . esc_attr($all_day_checked) . ' /> ';
		echo esc_html__('All day', 'clubflow');
		echo '</label>';
		echo '</p>';

		echo '<p>';
		echo '<label for="clubflow_location"><strong>' . esc_html__('Location', 'clubflow') . '</strong></label><br />';
		echo '<input type="text" id="clubflow_location" name="clubflow_location" value="' . esc_attr((string) $location) . '" style="width: 100%;" />';
		echo '</p>';

		// Booking fields
		$max_spots = get_post_meta($post->ID, '_clubflow_max_spots', true);
		$price = get_post_meta($post->ID, '_clubflow_price', true);
		$booking_enabled = get_post_meta($post->ID, '_clubflow_booking_enabled', true);
		
		// Default booking enabled for new events
		if ($post->post_status === 'auto-draft' && $booking_enabled === '') {
			$booking_enabled = '1';
		}

		echo '<hr style="margin: 20px 0;" />';
		echo '<h4 style="margin-bottom: 10px;">' . esc_html__('Booking Settings', 'clubflow') . '</h4>';

		echo '<p>';
		echo '<label for="clubflow_booking_enabled">';
		echo '<input type="checkbox" id="clubflow_booking_enabled" name="clubflow_booking_enabled" value="1" ' . checked($booking_enabled, '1', false) . ' /> ';
		echo esc_html__('Enable booking for this event', 'clubflow');
		echo '</label>';
		echo '</p>';

		echo '<p>';
		echo '<label for="clubflow_max_spots"><strong>' . esc_html__('Max spots', 'clubflow') . '</strong> <span style="font-weight: normal; color: #666;">(' . esc_html__('0 = unlimited', 'clubflow') . ')</span></label><br />';
		echo '<input type="number" id="clubflow_max_spots" name="clubflow_max_spots" value="' . esc_attr((string) $max_spots) . '" min="0" style="width: 100px;" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="clubflow_price"><strong>' . esc_html__('Price', 'clubflow') . '</strong> <span style="font-weight: normal; color: #666;">(' . esc_html__('e.g. 150 kr', 'clubflow') . ')</span></label><br />';
		echo '<input type="text" id="clubflow_price" name="clubflow_price" value="' . esc_attr((string) $price) . '" style="width: 150px;" />';
		echo '</p>';

		// Show current bookings count
		if (class_exists('ClubFlow_Booking') && $post->post_status !== 'auto-draft') {
			$booking_count = ClubFlow_Booking::get_booking_count($post->ID);
			$spots_remaining = ClubFlow_Booking::get_spots_remaining($post->ID);
			
			echo '<p style="background: #f0f0f1; padding: 10px; border-radius: 4px;">';
			echo '<strong>' . esc_html__('Current bookings:', 'clubflow') . '</strong> ' . esc_html($booking_count);
			if ($spots_remaining !== null) {
				echo ' / ' . esc_html($max_spots) . ' (' . esc_html($spots_remaining) . ' ' . esc_html__('spots left', 'clubflow') . ')';
			}
			echo '</p>';
		}

		// Recurrence section (only for non-child events)
		$is_child = get_post_meta($post->ID, '_clubflow_recurrence_parent', true);
		
		if ($is_child) {
			// This is a child event - show link to parent
			$parent = get_post($is_child);
			echo '<hr style="margin: 20px 0;" />';
			echo '<p style="background: #e7f3ff; padding: 10px; border-radius: 4px; border-left: 4px solid #2271b1;">';
			echo '<strong>' . esc_html__('Recurring event', 'clubflow') . '</strong><br>';
			echo esc_html__('This event is part of a recurring series.', 'clubflow') . ' ';
			if ($parent) {
				echo '<a href="' . esc_url(get_edit_post_link($is_child)) . '">' . esc_html__('Edit parent event', 'clubflow') . ' →</a>';
			}
			echo '</p>';
		} else {
			// This is a parent or standalone event - show recurrence options
			$recurrence_enabled = get_post_meta($post->ID, '_clubflow_recurrence_enabled', true) === '1';
			$recurrence_type = get_post_meta($post->ID, '_clubflow_recurrence_type', true) ?: 'weekly';
			$recurrence_days = get_post_meta($post->ID, '_clubflow_recurrence_days', true) ?: [];
			$recurrence_until = get_post_meta($post->ID, '_clubflow_recurrence_until', true) ?: date('Y-m-d', strtotime('+3 months'));

			echo '<hr style="margin: 20px 0;" />';
			echo '<h4 style="margin-bottom: 10px;">' . esc_html__('Recurring Event', 'clubflow') . '</h4>';

			echo '<p>';
			echo '<label for="clubflow_recurrence_enabled">';
			echo '<input type="checkbox" id="clubflow_recurrence_enabled" name="clubflow_recurrence_enabled" value="1" ' . checked($recurrence_enabled, true, false) . ' /> ';
			echo esc_html__('This is a recurring event', 'clubflow');
			echo '</label>';
			echo '</p>';

			echo '<div id="clubflow-recurrence-options" style="' . ($recurrence_enabled ? '' : 'display: none;') . 'margin-left: 20px; padding: 15px; background: #f9f9f9; border-radius: 4px;">';

			// Recurrence type
			echo '<p>';
			echo '<label for="clubflow_recurrence_type"><strong>' . esc_html__('Repeat', 'clubflow') . '</strong></label><br>';
			echo '<select id="clubflow_recurrence_type" name="clubflow_recurrence_type" style="min-width: 150px;">';
			echo '<option value="daily" ' . selected($recurrence_type, 'daily', false) . '>' . esc_html__('Daily', 'clubflow') . '</option>';
			echo '<option value="weekly" ' . selected($recurrence_type, 'weekly', false) . '>' . esc_html__('Weekly', 'clubflow') . '</option>';
			echo '</select>';
			echo '</p>';

			// Days of week (for weekly)
			echo '<div id="clubflow-recurrence-days" style="' . ($recurrence_type === 'weekly' ? '' : 'display: none;') . '">';
			echo '<p><strong>' . esc_html__('On days', 'clubflow') . '</strong></p>';
			$day_labels = [
				'mon' => __('Mon', 'clubflow'),
				'tue' => __('Tue', 'clubflow'),
				'wed' => __('Wed', 'clubflow'),
				'thu' => __('Thu', 'clubflow'),
				'fri' => __('Fri', 'clubflow'),
				'sat' => __('Sat', 'clubflow'),
				'sun' => __('Sun', 'clubflow'),
			];
			echo '<p style="display: flex; gap: 10px; flex-wrap: wrap;">';
			foreach ($day_labels as $day_key => $day_label) {
				$checked = in_array($day_key, (array) $recurrence_days) ? 'checked' : '';
				echo '<label style="display: inline-flex; align-items: center; gap: 4px; padding: 5px 10px; background: #fff; border: 1px solid #ddd; border-radius: 4px; cursor: pointer;">';
				echo '<input type="checkbox" name="clubflow_recurrence_days[]" value="' . esc_attr($day_key) . '" ' . $checked . ' /> ';
				echo esc_html($day_label);
				echo '</label>';
			}
			echo '</p>';
			echo '</div>';

			// Until date
			echo '<p>';
			echo '<label for="clubflow_recurrence_until"><strong>' . esc_html__('Until', 'clubflow') . '</strong></label><br>';
			echo '<input type="date" id="clubflow_recurrence_until" name="clubflow_recurrence_until" value="' . esc_attr($recurrence_until) . '" style="width: 180px;" />';
			echo '</p>';

			// Generate button
			echo '<p style="margin-top: 15px;">';
			echo '<label style="display: flex; align-items: center; gap: 8px;">';
			echo '<input type="checkbox" name="clubflow_generate_recurring" value="1" /> ';
			echo '<span>' . esc_html__('Generate events on save', 'clubflow') . '</span>';
			echo '</label>';
			echo '</p>';

			// Show child count if any
			if ($post->post_status !== 'auto-draft' && class_exists('ClubFlow_Recurrence')) {
				$child_count = ClubFlow_Recurrence::get_child_count($post->ID);
				if ($child_count > 0) {
					echo '<p style="background: #e7f5e9; padding: 10px; border-radius: 4px; margin-top: 10px;">';
					echo '<strong>' . esc_html($child_count) . '</strong> ' . esc_html__('recurring events generated', 'clubflow');
					echo '</p>';
				}
			}

			echo '</div>'; // #clubflow-recurrence-options

			// JavaScript for toggling
			?>
			<script>
			(function() {
				var enabledCheckbox = document.getElementById('clubflow_recurrence_enabled');
				var optionsDiv = document.getElementById('clubflow-recurrence-options');
				var typeSelect = document.getElementById('clubflow_recurrence_type');
				var daysDiv = document.getElementById('clubflow-recurrence-days');

				if (enabledCheckbox && optionsDiv) {
					enabledCheckbox.addEventListener('change', function() {
						optionsDiv.style.display = this.checked ? '' : 'none';
					});
				}

				if (typeSelect && daysDiv) {
					typeSelect.addEventListener('change', function() {
						daysDiv.style.display = this.value === 'weekly' ? '' : 'none';
					});
				}
			})();
			</script>
			<?php
		}
	}

	public function save_meta_boxes(int $post_id): void {
		if (!isset($_POST['clubflow_event_details_nonce'])) {
			return;
		}

		if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['clubflow_event_details_nonce'])), 'clubflow_save_event_details')) {
			return;
		}

		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		if (!current_user_can('edit_post', $post_id)) {
			return;
		}

		$start_raw = isset($_POST['clubflow_start']) ? sanitize_text_field(wp_unslash($_POST['clubflow_start'])) : '';
		$end_raw = isset($_POST['clubflow_end']) ? sanitize_text_field(wp_unslash($_POST['clubflow_end'])) : '';
		$all_day = isset($_POST['clubflow_all_day']) ? '1' : '0';
		$location = isset($_POST['clubflow_location']) ? sanitize_text_field(wp_unslash($_POST['clubflow_location'])) : '';

		$start = $this->utils->normalize_datetime_for_storage($start_raw);
		$end = $this->utils->normalize_datetime_for_storage($end_raw);

		if ($start !== '') {
			update_post_meta($post_id, '_clubflow_start', $start);
		} else {
			delete_post_meta($post_id, '_clubflow_start');
		}

		if ($end !== '') {
			update_post_meta($post_id, '_clubflow_end', $end);
		} else {
			delete_post_meta($post_id, '_clubflow_end');
		}

		update_post_meta($post_id, '_clubflow_all_day', $all_day);

		if ($location !== '') {
			update_post_meta($post_id, '_clubflow_location', $location);
		} else {
			delete_post_meta($post_id, '_clubflow_location');
		}

		// Save booking fields
		$booking_enabled = isset($_POST['clubflow_booking_enabled']) ? '1' : '0';
		$max_spots = isset($_POST['clubflow_max_spots']) ? absint($_POST['clubflow_max_spots']) : 0;
		$price = isset($_POST['clubflow_price']) ? sanitize_text_field(wp_unslash($_POST['clubflow_price'])) : '';

		update_post_meta($post_id, '_clubflow_booking_enabled', $booking_enabled);
		update_post_meta($post_id, '_clubflow_max_spots', $max_spots);

		if ($price !== '') {
			update_post_meta($post_id, '_clubflow_price', $price);
		} else {
			delete_post_meta($post_id, '_clubflow_price');
		}
	}
}
