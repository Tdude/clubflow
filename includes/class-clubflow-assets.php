<?php

if (!defined('ABSPATH')) {
	exit;
}

final class ClubFlow_Assets {
	private ClubFlow $plugin;
	private bool $frontend_assets_enqueued = false;

	public function __construct(ClubFlow $plugin) {
		$this->plugin = $plugin;
	}

	public function register(): void {
		add_action('wp_enqueue_scripts', [$this, 'maybe_enqueue_frontend_assets']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
	}

	public function maybe_enqueue_list_assets(): void {
		wp_enqueue_style(
			'clubflow',
			plugins_url('style.css', $this->plugin->plugin_file()),
			[],
			ClubFlow::VERSION
		);

		$accent = get_theme_mod( 'upaif_color_accent_red', '#9b2d30' );
		$text = get_theme_mod( 'upaif_color_text_dark', '#2d1e12' );
		$bg = get_theme_mod( 'upaif_color_bg_primary', '#f5f0e6' );
		$accent = sanitize_hex_color( $accent ) ?: '#9b2d30';
		$text = sanitize_hex_color( $text ) ?: '#2d1e12';
		$bg = sanitize_hex_color( $bg ) ?: '#f5f0e6';

		$inline_css = ".clubflow-list{--clubflow-list-bg:rgba(255,255,255,.88);--clubflow-list-text:{$text};--clubflow-list-muted:rgba(45,30,18,.65);--clubflow-list-border:rgba(45,30,18,.10);} .clubflow-list__link a{color:{$accent};}";
		wp_add_inline_style( 'clubflow', $inline_css );

		wp_enqueue_script(
			'clubflow-list',
			plugins_url('assets/clubflow-list.js', $this->plugin->plugin_file()),
			[],
			ClubFlow::VERSION,
			true
		);
	}

	/**
	 * Force enqueue frontend assets (called from shortcodes)
	 */
	public function force_enqueue_frontend_assets(): void {
		if ($this->frontend_assets_enqueued) {
			return;
		}
		$this->frontend_assets_enqueued = true;
		$this->enqueue_frontend_assets_internal();
	}

	public function maybe_enqueue_frontend_assets(): void {
		if ($this->frontend_assets_enqueued) {
			return;
		}

		$should_enqueue = false;
		if (is_singular()) {
			$post = get_post();
			if ($post instanceof \WP_Post) {
				$should_enqueue = has_shortcode($post->post_content, 'club_calendar') 
					|| has_shortcode($post->post_content, 'club_booking');
			}
		}

		if (!$should_enqueue && !is_admin()) {
			return;
		}

		$this->frontend_assets_enqueued = true;
		$this->enqueue_frontend_assets_internal();
	}

	private function enqueue_frontend_assets_internal(): void {
		$show_blocks_month = get_option('clubflow_show_blocks_month', '1') !== '0';

		wp_enqueue_style(
			'clubflow',
			plugins_url('style.css', $this->plugin->plugin_file()),
			[],
			ClubFlow::VERSION
		);

		$accent = get_theme_mod( 'upaif_color_accent_red', '#9b2d30' );
		$text = get_theme_mod( 'upaif_color_text_dark', '#2d1e12' );
		$bg = get_theme_mod( 'upaif_color_bg_primary', '#f5f0e6' );
		$accent = sanitize_hex_color( $accent ) ?: '#9b2d30';
		$text = sanitize_hex_color( $text ) ?: '#2d1e12';
		$bg = sanitize_hex_color( $bg ) ?: '#f5f0e6';

		$inline_css = ".clubflow-calendar,.clubflow-modal,.clubflow-booking-widget{--clubflow-bg:{$bg};--clubflow-surface:rgba(255,255,255,.97);--clubflow-text:{$text};--clubflow-muted:rgba(45,30,18,.65);--clubflow-border:rgba(45,30,18,.12);--clubflow-accent:{$accent};--clubflow-backdrop:rgba(0,0,0,.55);--accent-red:{$accent};--text-dark:{$text};--text-light:rgba(45,30,18,.65);--bg-primary:{$bg};} .clubflow-event__link a{color:var(--clubflow-accent);}";
		wp_add_inline_style( 'clubflow', $inline_css );

		wp_enqueue_style(
			'fullcalendar',
			'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css',
			[],
			'6.1.15'
		);

		wp_enqueue_script(
			'fullcalendar',
			'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js',
			[],
			'6.1.15',
			true
		);

		wp_enqueue_script(
			'clubflow-calendar',
			plugins_url('assets/clubflow-calendar.js', $this->plugin->plugin_file()),
			['fullcalendar'],
			ClubFlow::VERSION,
			true
		);

		wp_localize_script(
			'clubflow-calendar',
			'ClubFlow',
			[
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'actionEvents' => ClubFlow::AJAX_ACTION_EVENTS,
				'actionDetails' => ClubFlow::AJAX_ACTION_EVENT_DETAILS,
				'actionBook' => ClubFlow::AJAX_ACTION_BOOK,
				'actionKlippkortRemaining' => ClubFlow_Booking::AJAX_ACTION_KLIPPKORT_REMAINING,
				'nonceEvents' => wp_create_nonce('clubflow_events'),
				'nonceDetails' => wp_create_nonce('clubflow_event_details'),
				'nonceBook' => wp_create_nonce('clubflow_book'),
				'nonceKlippkortRemaining' => wp_create_nonce('clubflow_klippkort_remaining'),
				'showBlocksMonth' => $show_blocks_month,
				'timeZone' => wp_timezone_string() ?: 'local',
				'i18n' => [
					'bookNow' => __('Boka nu', 'clubflow'),
					'booking' => __('Bokar...', 'clubflow'),
					'booked' => __('Bokad!', 'clubflow'),
					'bookingSuccess' => __('Din bokning är bekräftad! Bekräftelsekod:', 'clubflow'),
					'bookingError' => __('Bokningen misslyckades. Försök igen.', 'clubflow'),
					'payWithSwish' => __('Betala med Swish:', 'clubflow'),
					'swishMessage' => __('Meddelande:', 'clubflow'),
					'amount' => __('Belopp:', 'clubflow'),
					'openSwish' => __('Öppna Swish', 'clubflow'),
					'scanQR' => __('Skanna QR-koden med Swish-appen:', 'clubflow'),
					'orPayManually' => __('Eller betala manuellt till:', 'clubflow'),
					'payToNumber' => __('Betala till:', 'clubflow'),
					'awaitingPayment' => __('Inväntar betalning...', 'clubflow'),
					'paymentConfirmed' => __('Betalning bekräftad!', 'clubflow'),
					'paymentFailed' => __('Betalningen misslyckades', 'clubflow'),
					'paymentTimeout' => __('Betalningen kunde inte bekräftas. Kontakta oss om du har betalat.', 'clubflow'),
					'redirectingToPayment' => __('Skickar dig till betalning...', 'clubflow'),
					'proceedToPayment' => __('Gå till betalning', 'clubflow'),
					'nameRequired' => __('Namn krävs', 'clubflow'),
					'invalidEmail' => __('Ogiltig e-postadress', 'clubflow'),
					'phoneRequired' => __('Telefonnummer krävs', 'clubflow'),
					'fieldRequired' => __('krävs', 'clubflow'),
					'fieldFallback' => __('Fält', 'clubflow'),
					'copy' => __('Kopiera', 'clubflow'),
					'copied' => __('Kopierad!', 'clubflow'),
					'klippkortCode' => __('Klippkortkod:', 'clubflow'),
					'discount' => __('Rabatt:', 'clubflow'),
					'klippkortHint' => __('Du verkar ha %d klipp kvar.', 'clubflow'),
				],
			]
		);
	}

	public function enqueue_admin_assets(string $hook_suffix): void {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (!$screen) {
			return;
		}

		wp_register_style('clubflow-admin', false, [], ClubFlow::VERSION);
		wp_enqueue_style('clubflow-admin');
		// Make the calendar icon behave in admin rightbar
		wp_add_inline_style('clubflow-admin', '.wp-core-ui select{padding-right:0;}');

		if (($screen->id ?? '') === 'club_event_page_clubflow-bookings-calendar') {
			wp_enqueue_style(
				'fullcalendar',
				'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css',
				[],
				'6.1.15'
			);

			wp_enqueue_script(
				'fullcalendar',
				'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js',
				[],
				'6.1.15',
				true
			);

			wp_enqueue_script(
				'clubflow-admin',
				plugins_url('assets/clubflow-admin.js', $this->plugin->plugin_file()),
				['fullcalendar'],
				ClubFlow::VERSION,
				true
			);

			wp_localize_script(
				'clubflow-admin',
				'ClubFlowAdmin',
				[
					'ajaxUrl' => admin_url('admin-ajax.php'),
					'nonce' => wp_create_nonce('clubflow_admin_calendar'),
					'showBlocksMonth' => get_option('clubflow_show_blocks_month', '1') !== '0',
					'timeZone' => wp_timezone_string() ?: 'local',
					'actions' => [
						'events' => 'clubflow_admin_calendar_events',
						'save' => 'clubflow_admin_calendar_save_event',
						'savePref' => 'clubflow_admin_calendar_save_pref',
					],
					'i18n' => [
						'newEvent' => __('New booking slot', 'clubflow'),
						'editEvent' => __('Edit booking slot', 'clubflow'),
						'save' => __('Save', 'clubflow'),
						'cancel' => __('Cancel', 'clubflow'),
						'delete' => __('Delete', 'clubflow'),
						'overlapWarning' => __('Warning: overlaps with another slot.', 'clubflow'),
						'saving' => __('Saving...', 'clubflow'),
						'loadError' => __('Could not load calendar data.', 'clubflow'),
						'saveError' => __('Could not save. Please try again.', 'clubflow'),
					],
				]
			);
			return;
		}

		if ($screen->base !== 'post' || $screen->post_type !== ClubFlow::POST_TYPE) {
			return;
		}

		wp_enqueue_script(
			'clubflow-admin',
			plugins_url('assets/clubflow-admin.js', $this->plugin->plugin_file()),
			[],
			ClubFlow::VERSION,
			true
		);
	}
}
