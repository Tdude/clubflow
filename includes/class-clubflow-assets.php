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

		$inline_css = ".clubflow-calendar,.clubflow-modal,.clubflow-booking-widget{--clubflow-bg:{$bg};--clubflow-surface:rgba(255,255,255,.90);--clubflow-text:{$text};--clubflow-muted:rgba(45,30,18,.65);--clubflow-border:rgba(45,30,18,.12);--clubflow-accent:{$accent};--clubflow-backdrop:rgba(0,0,0,.55);--accent-red:{$accent};--text-dark:{$text};--text-light:rgba(45,30,18,.65);--bg-primary:{$bg};} .clubflow-event__link a{color:var(--clubflow-accent);}";
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
				'nonceEvents' => wp_create_nonce('clubflow_events'),
				'nonceDetails' => wp_create_nonce('clubflow_event_details'),
				'nonceBook' => wp_create_nonce('clubflow_book'),
				'i18n' => [
					'bookNow' => __('Book now', 'clubflow'),
					'booking' => __('Booking...', 'clubflow'),
					'booked' => __('Booked!', 'clubflow'),
					'bookingSuccess' => __('Your booking is confirmed! Confirmation code:', 'clubflow'),
					'bookingError' => __('Booking failed. Please try again.', 'clubflow'),
					'payWithSwish' => __('Pay with Swish:', 'clubflow'),
					'swishMessage' => __('Message:', 'clubflow'),
					'amount' => __('Amount:', 'clubflow'),
					'openSwish' => __('Open Swish', 'clubflow'),
					'scanQR' => __('Scan the QR code with the Swish app:', 'clubflow'),
					'orPayManually' => __('Or pay manually to:', 'clubflow'),
					'payToNumber' => __('Pay to:', 'clubflow'),
					'awaitingPayment' => __('Awaiting payment...', 'clubflow'),
					'paymentConfirmed' => __('Payment confirmed!', 'clubflow'),
					'paymentFailed' => __('Payment failed', 'clubflow'),
					'paymentTimeout' => __('Payment could not be confirmed. Contact us if you have paid.', 'clubflow'),
					'redirectingToPayment' => __('Redirecting to payment...', 'clubflow'),
					'proceedToPayment' => __('Proceed to Payment', 'clubflow'),
				],
			]
		);
	}

	public function enqueue_admin_assets(string $hook_suffix): void {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (!$screen) {
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
