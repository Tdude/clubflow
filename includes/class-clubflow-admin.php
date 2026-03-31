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
		
		// Category color picker
		add_action(ClubFlow::TAX_CATEGORY . '_add_form_fields', [$this, 'render_category_color_field_add']);
		add_action(ClubFlow::TAX_CATEGORY . '_edit_form_fields', [$this, 'render_category_color_field_edit']);
		add_action('created_' . ClubFlow::TAX_CATEGORY, [$this, 'save_category_color']);
		add_action('edited_' . ClubFlow::TAX_CATEGORY, [$this, 'save_category_color']);
		
		// Category color column in list
		add_filter('manage_edit-' . ClubFlow::TAX_CATEGORY . '_columns', [$this, 'taxonomy_color_column']);
		add_filter('manage_' . ClubFlow::TAX_CATEGORY . '_custom_column', [$this, 'render_taxonomy_color_column'], 10, 3);
		
		// Instructor color picker
		add_action(ClubFlow::TAX_TAG . '_add_form_fields', [$this, 'render_category_color_field_add']);
		add_action(ClubFlow::TAX_TAG . '_edit_form_fields', [$this, 'render_category_color_field_edit']);
		add_action('created_' . ClubFlow::TAX_TAG, [$this, 'save_category_color']);
		add_action('edited_' . ClubFlow::TAX_TAG, [$this, 'save_category_color']);
		
		// Instructor color column in list
		add_filter('manage_edit-' . ClubFlow::TAX_TAG . '_columns', [$this, 'taxonomy_color_column']);
		add_filter('manage_' . ClubFlow::TAX_TAG . '_custom_column', [$this, 'render_taxonomy_color_column'], 10, 3);

		// Dashboard widget
		add_action('wp_dashboard_setup', [$this, 'register_dashboard_widget']);
	}
	
	public function taxonomy_color_column(array $columns): array {
		$new = [];
		foreach ($columns as $key => $label) {
			if ($key === 'name') {
				$new['color'] = __('Color', 'clubflow');
			}
			$new[$key] = $label;
		}
		return $new;
	}
	
	public function render_taxonomy_color_column(string $content, string $column, int $term_id): string {
		if ($column === 'color') {
			$color = get_term_meta($term_id, 'clubflow_category_color', true) ?: '#3788d8';
			return '<span style="display:inline-block; width:24px; height:24px; background:' . esc_attr($color) . '; border-radius:4px; border:1px solid rgba(0,0,0,0.1);"></span>';
		}
		return $content;
	}

	public function register_settings_page(): void {
		add_submenu_page(
			'edit.php?post_type=' . ClubFlow::POST_TYPE,
			__( 'User Guide', 'clubflow' ),
			__( 'Guide', 'clubflow' ),
			'edit_posts',
			'clubflow-guide',
			[$this, 'render_settings_page']
		);

		add_submenu_page(
			'edit.php?post_type=' . ClubFlow::POST_TYPE,
			__( 'Bookings Calendar', 'clubflow' ),
			__( 'Bookings Calendar', 'clubflow' ),
			'edit_posts',
			'clubflow-bookings-calendar',
			[$this, 'render_bookings_calendar_page']
		);
	}

	public function render_bookings_calendar_page(): void {
		if (!current_user_can('edit_posts')) {
			return;
		}

		$categories = get_terms([
			'taxonomy' => ClubFlow::TAX_CATEGORY,
			'hide_empty' => false,
		]);
		if (!is_array($categories) || is_wp_error($categories)) {
			$categories = [];
		}

		$instructors = get_terms([
			'taxonomy' => ClubFlow::TAX_TAG,
			'hide_empty' => false,
		]);
		if (!is_array($instructors) || is_wp_error($instructors)) {
			$instructors = [];
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__('ClubFlow Bookings Calendar', 'clubflow'); ?></h1>
			<style>
			#clubflow-admin-calendar{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px;margin-top:14px;}
			#clubflow-admin-calendar-modal{position:fixed;inset:0;z-index:100000;}
			#clubflow-admin-calendar-modal .clubflow-admin-calendar-modal__backdrop{position:absolute;inset:0;background:rgba(0,0,0,.45);}
			#clubflow-admin-calendar-modal .clubflow-admin-calendar-modal__panel{position:relative;max-width:560px;margin:6vh auto;background:#fff;border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.25);padding:16px;}
			#clubflow-admin-calendar-modal .clubflow-admin-calendar-modal__header{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;}
			#clubflow-admin-calendar-modal .clubflow-admin-calendar-modal__title{margin:0;font-size:18px;}
			#clubflow-admin-calendar-modal .clubflow-admin-calendar-modal__actions{display:flex;gap:8px;align-items:center;margin-top:14px;}
			#clubflow-admin-calendar-overlap{margin-top:10px;padding:10px 12px;border-left:4px solid #d63638;background:#fcf0f1;color:#1d2327;border-radius:6px;}
			#clubflow-admin-calendar .fc .fc-timegrid-slots table > tbody > tr:nth-child(odd) > td.fc-timegrid-slot-label,
			#clubflow-admin-calendar .fc .fc-timegrid-slots table > tbody > tr:nth-child(odd) > td.fc-timegrid-slot-lane{background-color:rgba(0,0,0,.02) !important;}
			#clubflow-admin-calendar .fc-timegrid-col-bg tr:nth-child(odd) > td{background-color:rgba(0,0,0,.02) !important;}
			#clubflow-admin-calendar .fc-timegrid-bg-harness:nth-child(odd) .fc-timegrid-bg-frame{background-color:rgba(0,0,0,.02) !important;}
			.clubflow-admin-cal__event{display:flex;justify-content:space-between;gap:8px; padding:6px;}
			.clubflow-admin-cal__event-title{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
			.clubflow-admin-cal__event-counter{font-weight:700;font-size:12px;opacity:.9;}
			.fc-event.clubflow-event--nearly-full{background-color:#e8a020 !important;border-color:#d49318 !important;}
			.fc-event.clubflow-event--full{background-color:#d63638 !important;border-color:#b32d2e !important;}
			.clubflow-admin-cal__event-badge{display:inline-block;font-size:10px;font-weight:700;line-height:1;padding:2px 5px;border-radius:3px;text-transform:uppercase;letter-spacing:.03em;background:rgba(255,255,255,.3);color:#fff;}
			</style>
			<?php $show_blocks_month = get_option('clubflow_show_blocks_month', '1') !== '0'; ?>
			<div id="clubflow-admin-calendar"></div>
			<p style="margin:12px 0 0 0;">
				<label>
					<input type="checkbox" id="clubflow-admin-calendar-toggle-blocks" value="1" <?php checked($show_blocks_month, true); ?> />
					<?php echo esc_html__( 'Show event blocks in month view', 'clubflow' ); ?>
				</label>
			</p>
			<div id="clubflow-admin-calendar-modal" style="display:none;">
				<div class="clubflow-admin-calendar-modal__backdrop"></div>
				<div class="clubflow-admin-calendar-modal__panel" role="dialog" aria-modal="true">
					<div class="clubflow-admin-calendar-modal__header">
						<h2 class="clubflow-admin-calendar-modal__title"></h2>
					</div>
					<div class="clubflow-admin-calendar-modal__body">
						<form id="clubflow-admin-calendar-form">
							<input type="hidden" name="event_id" value="" />
							<p>
								<label><strong><?php echo esc_html__('Title', 'clubflow'); ?></strong></label><br />
								<input type="text" name="title" value="" style="width:100%;" />
							</p>
							<p>
								<label><strong><?php echo esc_html__('Category', 'clubflow'); ?></strong></label><br />
								<select name="category_id" style="width:100%;">
									<option value=""><?php echo esc_html__('Select', 'clubflow'); ?></option>
									<?php foreach ($categories as $term) : ?>
										<option value="<?php echo esc_attr((string) $term->term_id); ?>"><?php echo esc_html($term->name); ?></option>
									<?php endforeach; ?>
								</select>
							</p>
							<p>
								<label><strong><?php echo esc_html__('Instructor', 'clubflow'); ?></strong></label><br />
								<select name="instructor_id" style="width:100%;">
									<option value=""><?php echo esc_html__('Select', 'clubflow'); ?></option>
									<?php foreach ($instructors as $term) : ?>
										<option value="<?php echo esc_attr((string) $term->term_id); ?>"><?php echo esc_html($term->name); ?></option>
									<?php endforeach; ?>
								</select>
							</p>
							<p>
								<label><strong><?php echo esc_html__('Start', 'clubflow'); ?></strong></label><br />
								<input type="datetime-local" name="start" value="" />
							</p>
							<p>
								<label><strong><?php echo esc_html__('End', 'clubflow'); ?></strong></label><br />
								<input type="datetime-local" name="end" value="" />
							</p>
							<p>
								<label><input type="checkbox" name="all_day" value="1" /> <?php echo esc_html__('All day', 'clubflow'); ?></label>
							</p>
							<p>
								<label><strong><?php echo esc_html__('Location', 'clubflow'); ?></strong></label><br />
								<input type="text" name="location" value="" style="width:100%;" />
							</p>
							<p>
								<label><input type="checkbox" name="booking_enabled" value="1" checked /> <?php echo esc_html__('Enable booking', 'clubflow'); ?></label>
							</p>
							<p>
								<label><strong><?php echo esc_html__('Max spots', 'clubflow'); ?></strong></label><br />
								<input type="number" name="max_spots" value="0" min="0" />
							</p>
							<p>
								<label><strong><?php echo esc_html__('Price', 'clubflow'); ?></strong></label><br />
								<input type="text" name="price" value="" placeholder="200 kr" />
							</p>
							<div id="clubflow-admin-calendar-overlap" style="display:none;"></div>
							<div class="clubflow-admin-calendar-modal__actions">
								<button type="button" class="button" data-action="cancel"><?php echo esc_html__('Cancel', 'clubflow'); ?></button>
								<button type="submit" class="button button-primary" data-action="save"><?php echo esc_html__('Save', 'clubflow'); ?></button>
								<button type="button" class="button button-link-delete" data-action="delete" style="display:none;"><?php echo esc_html__('Delete', 'clubflow'); ?></button>
							</div>
						</form>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$user_guide = $this->get_user_guide_content();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'ClubFlow', 'clubflow' ); ?></h1>
			
			<?php if ( $user_guide !== '' ) : ?>
			<div style="max-width: 980px; background: #fff; border: 1px solid #dcdcde; padding: 24px 28px; border-radius: 8px; line-height: 1.7; margin-top: 20px;">
				<?php echo $this->render_markdown_basic( $user_guide ); ?>
			</div>
			<?php else : ?>
			<p><?php echo esc_html__( 'No user guide found.', 'clubflow' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private function get_user_guide_content(): string {
		$plugin_root = dirname( __DIR__ );
		$guide_path = $plugin_root . '/ANVANDARGUIDE.md';
		
		if ( ! file_exists( $guide_path ) ) {
			return '';
		}
		
		$content = file_get_contents( $guide_path );
		return $content !== false ? $content : '';
	}

	/**
	 * Basic markdown to HTML conversion for the user guide
	 */
	private function render_markdown_basic( string $md ): string {
		// Remove the main title (first # line)
		$md = preg_replace( '/^#\s+[^\n]+\n*/m', '', $md, 1 );
		
		// Escape HTML first
		$html = esc_html( $md );
		
		// Headers
		$html = preg_replace( '/^### (.+)$/m', '<h4 style="margin: 1.5em 0 0.5em; color: #1d2327;">$1</h4>', $html );
		$html = preg_replace( '/^## (.+)$/m', '<h3 style="margin: 2em 0 0.75em; color: #1d2327; border-bottom: 1px solid #dcdcde; padding-bottom: 0.5em;">$1</h3>', $html );
		
		// Bold
		$html = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html );
		
		// Inline code
		$html = preg_replace( '/`([^`]+)`/', '<code style="background: #f0f0f1; padding: 2px 6px; border-radius: 3px; font-size: 13px;">$1</code>', $html );
		
		// Code blocks
		$html = preg_replace( '/```[\w]*\n([\s\S]*?)```/', '<pre style="background: #f0f0f1; padding: 12px 16px; border-radius: 4px; overflow-x: auto; font-size: 13px;">$1</pre>', $html );
		
		// Horizontal rules
		$html = preg_replace( '/^---+$/m', '<hr style="border: none; border-top: 1px solid #dcdcde; margin: 2em 0;">', $html );
		
		// Lists (simple)
		$html = preg_replace( '/^- (.+)$/m', '<li style="margin: 0.3em 0;">$1</li>', $html );
		$html = preg_replace( '/^(\d+)\. (.+)$/m', '<li style="margin: 0.3em 0;">$2</li>', $html );
		
		// Wrap consecutive <li> in <ul>
		$html = preg_replace( '/(<li[^>]*>.*?<\/li>\n?)+/s', '<ul style="margin: 0.75em 0 0.75em 1.5em; padding: 0;">$0</ul>', $html );
		
		// Tables (simple)
		$html = preg_replace_callback( '/\|(.+)\|\n\|[-| ]+\|\n((?:\|.+\|\n?)+)/', function( $m ) {
			$headers = array_map( 'trim', explode( '|', trim( $m[1], '| ' ) ) );
			$rows_raw = preg_split( '/\n/', trim( $m[2] ) );
			
			$table = '<table style="border-collapse: collapse; margin: 1em 0; width: 100%;"><thead><tr>';
			foreach ( $headers as $h ) {
				$table .= '<th style="border: 1px solid #dcdcde; padding: 8px 12px; background: #f0f0f1; text-align: left;">' . esc_html( $h ) . '</th>';
			}
			$table .= '</tr></thead><tbody>';
			
			foreach ( $rows_raw as $row ) {
				$cells = array_map( 'trim', explode( '|', trim( $row, '| ' ) ) );
				$table .= '<tr>';
				foreach ( $cells as $c ) {
					$table .= '<td style="border: 1px solid #dcdcde; padding: 8px 12px;">' . esc_html( $c ) . '</td>';
				}
				$table .= '</tr>';
			}
			$table .= '</tbody></table>';
			return $table;
		}, $html );
		
		// Paragraphs - wrap lines that aren't already wrapped
		$html = preg_replace( '/^(?!<[huplo]|<li|<hr|<table|<pre)(.+)$/m', '<p style="margin: 0.75em 0;">$1</p>', $html );
		
		// Italic (after paragraph wrapping)
		$html = preg_replace( '/\*([^*]+)\*/', '<em>$1</em>', $html );
		
		// Clean up empty paragraphs
		$html = preg_replace( '/<p[^>]*>\s*<\/p>/', '', $html );
		
		return $html;
	}

	public function register_meta_boxes(): void {
		// Date & Time - side column
		add_meta_box(
			'clubflow_datetime',
			__('Date & Time', 'clubflow'),
			[$this, 'render_datetime_meta_box'],
			ClubFlow::POST_TYPE,
			'side',
			'high'
		);

		// Event Mode - side column  
		add_meta_box(
			'clubflow_event_mode',
			__('Event Mode', 'clubflow'),
			[$this, 'render_event_mode_meta_box'],
			ClubFlow::POST_TYPE,
			'side',
			'high'
		);

		// Booking Settings - side column
		add_meta_box(
			'clubflow_booking_settings',
			__('Booking', 'clubflow'),
			[$this, 'render_booking_meta_box'],
			ClubFlow::POST_TYPE,
			'side',
			'default'
		);

		// Location - normal column
		add_meta_box(
			'clubflow_location',
			__('Location', 'clubflow'),
			[$this, 'render_location_meta_box'],
			ClubFlow::POST_TYPE,
			'normal',
			'high'
		);

		// Recurrence - normal column (only for non-child events)
		add_meta_box(
			'clubflow_recurrence',
			__('Recurring Event', 'clubflow'),
			[$this, 'render_recurrence_meta_box'],
			ClubFlow::POST_TYPE,
			'normal',
			'default'
		);

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

	/**
	 * Date & Time meta box (side)
	 */
	public function render_datetime_meta_box(\WP_Post $post): void {
		wp_nonce_field('clubflow_save_event_details', 'clubflow_event_details_nonce');

		$start = $this->utils->format_datetime_for_input((string) get_post_meta($post->ID, '_clubflow_start', true));
		$end = $this->utils->format_datetime_for_input((string) get_post_meta($post->ID, '_clubflow_end', true));
		$all_day = get_post_meta($post->ID, '_clubflow_all_day', true);
		$recurrence_enabled = get_post_meta($post->ID, '_clubflow_recurrence_enabled', true) === '1';
		$is_recurrence_child = (bool) get_post_meta($post->ID, '_clubflow_recurrence_parent', true);
		$all_day_checked = (($all_day === '1') || ($all_day === '' && $post->post_status === 'auto-draft')) ? 'checked' : '';

		echo '<p>';
		echo '<label for="clubflow_start"><strong>' . esc_html__('Start', 'clubflow') . '</strong></label><br />';
		echo '<input type="datetime-local" id="clubflow_start" name="clubflow_start" value="' . esc_attr($start) . '" style="width: 100%;" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="clubflow_end"><strong>' . esc_html__('End', 'clubflow') . '</strong> <span style="color: #666; font-weight: normal;">(' . esc_html__('optional', 'clubflow') . ')</span></label><br />';
		echo '<input type="datetime-local" id="clubflow_end" name="clubflow_end" value="' . esc_attr($end) . '" style="width: 100%;" />';
		echo '</p>';

		echo '<p>';
		echo '<label><input type="checkbox" id="clubflow_all_day" name="clubflow_all_day" value="1" ' . esc_attr($all_day_checked) . ' /> ';
		echo esc_html__('All day', 'clubflow') . '</label>';
		echo '</p>';

		if ($recurrence_enabled || $is_recurrence_child) {
			echo '<p style="margin-top: 10px; padding: 8px 10px; background: #f0f6fc; border-left: 4px solid #2271b1; color: #1d2327;">';
			echo esc_html__('For recurring events, this sets the base time and first occurrence date.', 'clubflow');
			echo '</p>';
		}
	}

	/**
	 * Event Mode meta box (side)
	 */
	public function render_event_mode_meta_box(\WP_Post $post): void {
		$event_mode = get_post_meta($post->ID, '_clubflow_event_mode', true) ?: 'calendar';
		$linked_events = get_post_meta($post->ID, '_clubflow_linked_events', true) ?: [];

		echo '<select id="clubflow_event_mode" name="clubflow_event_mode" style="width: 100%;">';
		echo '<option value="calendar" ' . selected($event_mode, 'calendar', false) . '>📅 ' . esc_html__('Calendar', 'clubflow') . '</option>';
		echo '<option value="product" ' . selected($event_mode, 'product', false) . '>🛒 ' . esc_html__('Product', 'clubflow') . '</option>';
		echo '<option value="package" ' . selected($event_mode, 'package', false) . '>📦 ' . esc_html__('Klippkort', 'clubflow') . '</option>';
		echo '</select>';
		echo '<span class="clubflow-info-icon" title="' . esc_attr__('Calendar = shows in calendar. Product/Klippkort = hidden, use shortcode.', 'clubflow') . '" style="display: inline-block; margin-left: 6px; cursor: help; color: #2271b1; font-size: 16px; vertical-align: middle;">ⓘ</span>';

		// Shortcode helper
		echo '<div id="clubflow-shortcode-helper" style="' . ($event_mode !== 'calendar' ? '' : 'display: none;') . 'background: #e7f3ff; padding: 8px; border-radius: 4px; margin-top: 10px; font-size: 12px;">';
		echo '<code id="clubflow-shortcode-code" style="font-size: 11px;">[club_booking id="' . esc_attr($post->ID) . '"]</code>';
		echo '<button type="button" class="button button-small" style="margin-left: 6px; font-size: 11px;" onclick="navigator.clipboard.writeText(document.getElementById(\'clubflow-shortcode-code\').textContent); this.textContent=\'✓\';">' . esc_html__('Copy', 'clubflow') . '</button>';
		echo '</div>';

		// Linked events (package mode only)
		echo '<div id="clubflow-linked-events" style="' . ($event_mode === 'package' ? '' : 'display: none;') . 'margin-top: 10px;">';
		echo '<p><strong>' . esc_html__('Linked Events:', 'clubflow') . '</strong></p>';
		
		$calendar_events = get_posts([
			'post_type' => ClubFlow::POST_TYPE,
			'post_status' => ['publish', 'future'],
			'posts_per_page' => 100,
			'orderby' => 'meta_value',
			'meta_key' => '_clubflow_start',
			'order' => 'ASC',
			'meta_query' => [
				'relation' => 'OR',
				['key' => '_clubflow_event_mode', 'value' => 'calendar'],
				['key' => '_clubflow_event_mode', 'compare' => 'NOT EXISTS'],
			],
			'exclude' => [$post->ID],
		]);

		if (!empty($calendar_events)) {
			echo '<div style="max-height: 150px; overflow-y: auto; border: 1px solid #ddd; border-radius: 4px; padding: 6px; background: #fff; font-size: 12px;">';
			foreach ($calendar_events as $event) {
				$event_start = get_post_meta($event->ID, '_clubflow_start', true);
				$event_date = $event_start ? wp_date('m/d H:i', strtotime($event_start)) : '';
				$checked = in_array($event->ID, (array) $linked_events) ? 'checked' : '';
				echo '<label style="display: block; padding: 4px; cursor: pointer;">';
				echo '<input type="checkbox" name="clubflow_linked_events[]" value="' . esc_attr($event->ID) . '" ' . $checked . ' /> ';
				echo esc_html(wp_trim_words($event->post_title, 4));
				if ($event_date) echo ' <span style="color: #666;">(' . esc_html($event_date) . ')</span>';
				echo '</label>';
			}
			echo '</div>';
		}
		echo '</div>';

		?>
		<script>
		(function() {
			var modeSelect = document.getElementById('clubflow_event_mode');
			var shortcodeHelper = document.getElementById('clubflow-shortcode-helper');
			var linkedEvents = document.getElementById('clubflow-linked-events');
			
			function updateVisibility() {
				var mode = modeSelect.value;
				if (shortcodeHelper) {
					shortcodeHelper.style.display = (mode !== 'calendar') ? 'block' : 'none';
				}
				if (linkedEvents) {
					linkedEvents.style.display = (mode === 'package') ? 'block' : 'none';
				}
			}
			
			if (modeSelect) {
				modeSelect.addEventListener('change', updateVisibility);
				// Run once on load to sync state
				updateVisibility();
			}
		})();
		</script>
		<?php
	}

	/**
	 * Booking meta box (side)
	 */
	public function render_booking_meta_box(\WP_Post $post): void {
		$booking_enabled = get_post_meta($post->ID, '_clubflow_booking_enabled', true);
		$max_spots = get_post_meta($post->ID, '_clubflow_max_spots', true);
		$price = get_post_meta($post->ID, '_clubflow_price', true);
		$member_price = get_post_meta($post->ID, '_clubflow_member_price', true);
		$event_mode = get_post_meta($post->ID, '_clubflow_event_mode', true) ?: 'calendar';
		$klippkort_credits = get_post_meta($post->ID, '_clubflow_klippkort_credits', true);
		
		if ($post->post_status === 'auto-draft' && $booking_enabled === '') {
			$booking_enabled = '1';
		}

		echo '<p>';
		echo '<label><input type="checkbox" id="clubflow_booking_enabled" name="clubflow_booking_enabled" value="1" ' . checked($booking_enabled, '1', false) . ' /> ';
		echo esc_html__('Enable booking', 'clubflow') . '</label>';
		echo '</p>';

		echo '<p>';
		echo '<label for="clubflow_max_spots">' . esc_html__('Max spots', 'clubflow') . ' <span style="color: #666;">(0 = ∞)</span></label><br />';
		echo '<input type="number" id="clubflow_max_spots" name="clubflow_max_spots" value="' . esc_attr((string) $max_spots) . '" min="0" style="width: 80px;" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="clubflow_price">' . esc_html__('Price', 'clubflow') . ' <span style="color: #666;">(' . esc_html__('non-member', 'clubflow') . ')</span></label><br />';
		echo '<input type="text" id="clubflow_price" name="clubflow_price" value="' . esc_attr((string) $price) . '" placeholder="200 kr" style="width: 100px;" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="clubflow_member_price">' . esc_html__('Member price', 'clubflow') . ' <span style="color: #666;">(' . esc_html__('optional', 'clubflow') . ')</span></label><br />';
		echo '<input type="text" id="clubflow_member_price" name="clubflow_member_price" value="' . esc_attr((string) $member_price) . '" placeholder="150 kr" style="width: 100px;" />';
		echo '</p>';

		if ($event_mode === 'package') {
			echo '<p>';
			echo '<label for="clubflow_klippkort_credits">' . esc_html__('Klippkort credits', 'clubflow') . '</label><br />';
			echo '<input type="number" id="clubflow_klippkort_credits" name="clubflow_klippkort_credits" value="' . esc_attr((string) $klippkort_credits) . '" min="0" style="width: 80px;" />';
			echo '</p>';
		}

		// Current bookings
		if (class_exists('ClubFlow_Booking') && $post->post_status !== 'auto-draft') {
			$booking_count = ClubFlow_Booking::get_booking_count($post->ID);
			$spots_remaining = ClubFlow_Booking::get_spots_remaining($post->ID);
			
			echo '<p style="background: #f0f0f1; padding: 8px; border-radius: 4px; margin-top: 10px;">';
			echo '<strong>' . esc_html($booking_count) . '</strong> ' . esc_html__('booked', 'clubflow');
			if ($spots_remaining !== null) {
				echo ' <span style="color: #666;">(' . esc_html($spots_remaining) . ' ' . esc_html__('left', 'clubflow') . ')</span>';
			}
			echo '</p>';
		}
	}

	/**
	 * Location meta box (normal)
	 */
	public function render_location_meta_box(\WP_Post $post): void {
		$location = get_post_meta($post->ID, '_clubflow_location', true);
		echo '<input type="text" id="clubflow_location" name="clubflow_location" value="' . esc_attr((string) $location) . '" style="width: 100%;" placeholder="' . esc_attr__('e.g. Studio A, Main Building', 'clubflow') . '" />';

		// Category reminder
		$terms = get_the_terms($post->ID, ClubFlow::TAX_CATEGORY);
		$has_category = $terms && !is_wp_error($terms) && count($terms) > 0;
		if (!$has_category) {
			echo '<div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 10px 12px; margin-top: 12px; border-radius: 4px;">';
			echo '<strong>⚠️ ' . esc_html__('Remember to set a Category!', 'clubflow') . '</strong>';
			echo '</div>';
		}
	}

	/**
	 * Recurrence meta box (normal)
	 */
	public function render_recurrence_meta_box(\WP_Post $post): void {
		$is_child = get_post_meta($post->ID, '_clubflow_recurrence_parent', true);
		
		if ($is_child) {
			$parent = get_post($is_child);
			echo '<p style="background: #e7f3ff; padding: 10px; border-radius: 4px; border-left: 4px solid #2271b1;">';
			echo '<strong>' . esc_html__('Part of a recurring series', 'clubflow') . '</strong><br>';
			if ($parent) {
				echo '<a href="' . esc_url(get_edit_post_link($is_child)) . '">' . esc_html__('Edit parent event', 'clubflow') . ' →</a>';
			}
			echo '</p>';
			return;
		}

		$recurrence_enabled = get_post_meta($post->ID, '_clubflow_recurrence_enabled', true) === '1';
		$recurrence_type = get_post_meta($post->ID, '_clubflow_recurrence_type', true) ?: 'weekly';
		$recurrence_days = get_post_meta($post->ID, '_clubflow_recurrence_days', true) ?: [];
		$recurrence_until = get_post_meta($post->ID, '_clubflow_recurrence_until', true) ?: date('Y-m-d', strtotime('+3 months'));

		echo '<p>';
		echo '<label><input type="checkbox" id="clubflow_recurrence_enabled" name="clubflow_recurrence_enabled" value="1" ' . checked($recurrence_enabled, true, false) . ' /> ';
		echo esc_html__('This is a recurring event', 'clubflow') . '</label>';
		echo '</p>';

		echo '<div id="clubflow-recurrence-options" style="' . ($recurrence_enabled ? '' : 'display: none;') . 'padding: 15px; background: #f9f9f9; border-radius: 4px;">';

		// Recurrence type
		echo '<p>';
		echo '<label for="clubflow_recurrence_type"><strong>' . esc_html__('Repeat', 'clubflow') . '</strong></label><br>';
		echo '<select id="clubflow_recurrence_type" name="clubflow_recurrence_type" style="width: 100%;">';
		echo '<option value="daily" ' . selected($recurrence_type, 'daily', false) . '>' . esc_html__('Daily', 'clubflow') . '</option>';
		echo '<option value="weekly" ' . selected($recurrence_type, 'weekly', false) . '>' . esc_html__('Weekly', 'clubflow') . '</option>';
		echo '</select>';
		echo '</p>';

		// Days of week
		echo '<div id="clubflow-recurrence-days" style="' . ($recurrence_type === 'weekly' ? '' : 'display: none;') . '">';
		echo '<p><strong>' . esc_html__('On days', 'clubflow') . '</strong></p>';
		$day_labels = [
			'mon' => __('Mon', 'clubflow'), 'tue' => __('Tue', 'clubflow'),
			'wed' => __('Wed', 'clubflow'), 'thu' => __('Thu', 'clubflow'),
			'fri' => __('Fri', 'clubflow'), 'sat' => __('Sat', 'clubflow'),
			'sun' => __('Sun', 'clubflow'),
		];
		echo '<div style="display: flex; gap: 6px; flex-wrap: wrap;">';
		foreach ($day_labels as $day_key => $day_label) {
			$checked = in_array($day_key, (array) $recurrence_days) ? 'checked' : '';
			echo '<label style="padding: 4px 8px; background: #fff; border: 1px solid #ddd; border-radius: 4px; cursor: pointer; font-size: 12px;">';
			echo '<input type="checkbox" name="clubflow_recurrence_days[]" value="' . esc_attr($day_key) . '" ' . $checked . ' /> ' . esc_html($day_label);
			echo '</label>';
		}
		echo '</div></div>';

		// Until date
		echo '<p>';
		echo '<label for="clubflow_recurrence_until"><strong>' . esc_html__('Until', 'clubflow') . '</strong></label><br>';
		echo '<input type="date" id="clubflow_recurrence_until" name="clubflow_recurrence_until" value="' . esc_attr($recurrence_until) . '" style="width: 100%;" />';
		echo '</p>';

		// Generate button
		echo '<p><label>';
		echo '<input type="checkbox" name="clubflow_generate_recurring" value="1" /> ';
		echo esc_html__('Generate events on save', 'clubflow');
		echo '</label></p>';

		// Child count
		if ($post->post_status !== 'auto-draft' && class_exists('ClubFlow_Recurrence')) {
			$child_count = ClubFlow_Recurrence::get_child_count($post->ID);
			if ($child_count > 0) {
				echo '<p style="background: #e7f5e9; padding: 8px; border-radius: 4px;">';
				echo '<strong>' . esc_html($child_count) . '</strong> ' . esc_html__('events generated', 'clubflow');
				echo '</p>';
			}
		}

		echo '</div>';

		?>
		<script>
		(function() {
			var cb = document.getElementById('clubflow_recurrence_enabled');
			var opts = document.getElementById('clubflow-recurrence-options');
			var sel = document.getElementById('clubflow_recurrence_type');
			var days = document.getElementById('clubflow-recurrence-days');
			if (cb && opts) cb.addEventListener('change', function() { opts.style.display = this.checked ? '' : 'none'; });
			if (sel && days) sel.addEventListener('change', function() { days.style.display = this.value === 'weekly' ? '' : 'none'; });
		})();
		</script>
		<?php
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

		$member_price = isset($_POST['clubflow_member_price']) ? sanitize_text_field(wp_unslash($_POST['clubflow_member_price'])) : '';
		if ($member_price !== '') {
			update_post_meta($post_id, '_clubflow_member_price', $member_price);
		} else {
			delete_post_meta($post_id, '_clubflow_member_price');
		}

		$klippkort_credits = isset($_POST['clubflow_klippkort_credits']) ? absint($_POST['clubflow_klippkort_credits']) : 0;
		if ($klippkort_credits > 0) {
			update_post_meta($post_id, '_clubflow_klippkort_credits', $klippkort_credits);
		} else {
			delete_post_meta($post_id, '_clubflow_klippkort_credits');
		}

		// Save event mode
		$event_mode = isset($_POST['clubflow_event_mode']) ? sanitize_text_field(wp_unslash($_POST['clubflow_event_mode'])) : 'calendar';
		if (!in_array($event_mode, ['calendar', 'product', 'package'], true)) {
			$event_mode = 'calendar';
		}
		update_post_meta($post_id, '_clubflow_event_mode', $event_mode);

		// Save linked events (for package mode)
		$linked_events = isset($_POST['clubflow_linked_events']) ? array_map('absint', (array) $_POST['clubflow_linked_events']) : [];
		$linked_events = array_filter($linked_events);
		if (!empty($linked_events)) {
			update_post_meta($post_id, '_clubflow_linked_events', $linked_events);
		} else {
			delete_post_meta($post_id, '_clubflow_linked_events');
		}
	}

	/**
	 * Register the ClubFlow dashboard widget
	 */
	public function register_dashboard_widget(): void {
		if (!current_user_can('edit_posts')) {
			return;
		}

		wp_add_dashboard_widget(
			'clubflow_dashboard_overview',
			'ClubFlow — Oversikt',
			[$this, 'render_dashboard_widget']
		);
	}

	/**
	 * Render the dashboard widget content
	 */
	public function render_dashboard_widget(): void {
		$today     = wp_date('Y-m-d\TH:i:s');
		$next_week = wp_date('Y-m-d\T23:59:59', strtotime('+7 days'));

		// --- Upcoming events this week ---
		$events_query = new \WP_Query([
			'post_type'      => ClubFlow::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 10,
			'meta_key'       => '_clubflow_start',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'meta_query'     => [
				[
					'key'     => '_clubflow_start',
					'value'   => [$today, $next_week],
					'compare' => 'BETWEEN',
					'type'    => 'DATETIME',
				],
			],
		]);

		// --- Pending bookings count ---
		$pending_query = new \WP_Query([
			'post_type'      => ClubFlow_Booking::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'meta_query'     => [
				[
					'key'     => '_clubflow_booking_status',
					'value'   => ['pending', 'pending_payment'],
					'compare' => 'IN',
				],
			],
		]);
		$pending_count = $pending_query->found_posts;

		// --- Revenue this month ---
		$month_start = wp_date('Y-m-01\T00:00:00');
		$month_end   = wp_date('Y-m-t\T23:59:59');

		$payments_query = new \WP_Query([
			'post_type'      => ClubFlow_Payment::LOG_POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => [
				'relation' => 'AND',
				[
					'key'     => '_payment_status',
					'value'   => 'completed',
				],
				[
					'key'     => '_payment_timestamp',
					'value'   => [$month_start, $month_end],
					'compare' => 'BETWEEN',
					'type'    => 'DATETIME',
				],
			],
		]);

		$monthly_revenue = 0;
		if ($payments_query->have_posts()) {
			foreach ($payments_query->posts as $log_id) {
				$amount = (float) get_post_meta($log_id, '_payment_amount', true);
				$monthly_revenue += $amount;
			}
		}

		// Swedish day names for display
		$swedish_days = [
			'Monday'    => 'Mandag',
			'Tuesday'   => 'Tisdag',
			'Wednesday' => 'Onsdag',
			'Thursday'  => 'Torsdag',
			'Friday'    => 'Fredag',
			'Saturday'  => 'Lordag',
			'Sunday'    => 'Sondag',
		];

		$month_name = wp_date('F');
		$swedish_months = [
			'January'   => 'Januari',
			'February'  => 'Februari',
			'March'     => 'Mars',
			'April'     => 'April',
			'May'       => 'Maj',
			'June'      => 'Juni',
			'July'      => 'Juli',
			'August'    => 'Augusti',
			'September' => 'September',
			'October'   => 'Oktober',
			'November'  => 'November',
			'December'  => 'December',
		];
		$current_month_sv = $swedish_months[$month_name] ?? $month_name;

		// Admin URLs
		$bookings_url  = admin_url('edit.php?post_type=' . ClubFlow_Booking::POST_TYPE);
		$payments_url  = admin_url('edit.php?post_type=' . ClubFlow_Payment::LOG_POST_TYPE);
		$new_event_url = admin_url('post-new.php?post_type=' . ClubFlow::POST_TYPE);
		$calendar_url  = admin_url('edit.php?post_type=' . ClubFlow::POST_TYPE . '&page=clubflow-bookings-calendar');
		?>
		<style>
			#clubflow_dashboard_overview .inside { padding: 0 !important; }
			.clubflow-dash { font-size: 13px; line-height: 1.5; }
			.clubflow-dash-section { padding: 12px 16px; border-bottom: 1px solid #e0e0e0; }
			.clubflow-dash-section:last-child { border-bottom: none; }
			.clubflow-dash-heading {
				font-size: 11px;
				font-weight: 600;
				text-transform: uppercase;
				letter-spacing: 0.5px;
				color: #7b1fa2;
				margin: 0 0 8px 0;
			}
			.clubflow-dash-event {
				display: flex;
				justify-content: space-between;
				align-items: baseline;
				padding: 4px 0;
			}
			.clubflow-dash-event-name {
				font-weight: 500;
				color: #1d2327;
				overflow: hidden;
				text-overflow: ellipsis;
				white-space: nowrap;
				max-width: 60%;
			}
			.clubflow-dash-event-meta {
				color: #757575;
				font-size: 12px;
				white-space: nowrap;
			}
			.clubflow-dash-stat {
				display: flex;
				justify-content: space-between;
				align-items: center;
				padding: 6px 0;
			}
			.clubflow-dash-stat-value {
				font-size: 20px;
				font-weight: 700;
				color: #7b1fa2;
			}
			.clubflow-dash-stat-label {
				color: #50575e;
			}
			.clubflow-dash-links {
				display: flex;
				gap: 12px;
				flex-wrap: wrap;
			}
			.clubflow-dash-links a {
				color: #7b1fa2;
				text-decoration: none;
				font-weight: 500;
				font-size: 12px;
			}
			.clubflow-dash-links a:hover {
				text-decoration: underline;
				color: #4a148c;
			}
			.clubflow-dash-badge {
				display: inline-block;
				background: #ff9800;
				color: #fff;
				font-size: 11px;
				font-weight: 600;
				padding: 1px 7px;
				border-radius: 10px;
				margin-left: 6px;
			}
			.clubflow-dash-empty {
				color: #999;
				font-style: italic;
				font-size: 12px;
			}
		</style>
		<div class="clubflow-dash">

			<!-- Upcoming events -->
			<div class="clubflow-dash-section">
				<p class="clubflow-dash-heading">Kommande event (7 dagar)</p>
				<?php if ($events_query->have_posts()) : ?>
					<?php while ($events_query->have_posts()) : $events_query->the_post();
						$event_id  = get_the_ID();
						$start_raw = get_post_meta($event_id, '_clubflow_start', true);
						$booked    = ClubFlow_Booking::get_booking_count($event_id);
						$max_spots = (int) get_post_meta($event_id, '_clubflow_max_spots', true);
						$day_en    = date('l', strtotime($start_raw));
						$day_sv    = $swedish_days[$day_en] ?? $day_en;
						$day_num   = date('j', strtotime($start_raw));
						$mon_en    = date('F', strtotime($start_raw));
						$mon_sv    = strtolower($swedish_months[$mon_en] ?? $mon_en);
						// Abbreviate month to 3 chars
						$mon_short = mb_substr($mon_sv, 0, 3);
						$spots_text = $max_spots > 0
							? $booked . '/' . $max_spots . ' bokade'
							: $booked . ' bokade';
					?>
						<div class="clubflow-dash-event">
							<a href="<?php echo esc_url(get_edit_post_link($event_id)); ?>" class="clubflow-dash-event-name" title="<?php echo esc_attr(get_the_title()); ?>">
								<?php echo esc_html(get_the_title()); ?>
							</a>
							<span class="clubflow-dash-event-meta">
								<?php echo esc_html($spots_text . ', ' . $day_sv . ' ' . $day_num . ' ' . $mon_short); ?>
							</span>
						</div>
					<?php endwhile; wp_reset_postdata(); ?>
				<?php else : ?>
					<p class="clubflow-dash-empty">Inga kommande event de narmaste 7 dagarna.</p>
				<?php endif; ?>
			</div>

			<!-- Stats row -->
			<div class="clubflow-dash-section" style="display: flex; gap: 24px;">
				<div class="clubflow-dash-stat" style="flex: 1;">
					<div>
						<div class="clubflow-dash-stat-value"><?php echo (int) $pending_count; ?></div>
						<div class="clubflow-dash-stat-label">
							Vantande bokningar
							<?php if ($pending_count > 0) : ?>
								<span class="clubflow-dash-badge"><?php echo (int) $pending_count; ?></span>
							<?php endif; ?>
						</div>
					</div>
				</div>
				<div class="clubflow-dash-stat" style="flex: 1;">
					<div>
						<div class="clubflow-dash-stat-value"><?php echo esc_html(number_format($monthly_revenue, 0, ',', ' ')); ?> kr</div>
						<div class="clubflow-dash-stat-label">Intakter <?php echo esc_html(strtolower($current_month_sv)); ?></div>
					</div>
				</div>
			</div>

			<!-- Quick links -->
			<div class="clubflow-dash-section">
				<p class="clubflow-dash-heading">Snabbllankar</p>
				<div class="clubflow-dash-links">
					<a href="<?php echo esc_url($bookings_url); ?>">Alla bokningar</a>
					<a href="<?php echo esc_url($payments_url); ?>">Betalningslogg</a>
					<a href="<?php echo esc_url($new_event_url); ?>">+ Nytt event</a>
					<a href="<?php echo esc_url($calendar_url); ?>">Bokningskalender</a>
				</div>
			</div>
		</div>
		<?php
	}
}
