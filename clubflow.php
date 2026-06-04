<?php
/**
 * Plugin Name: ClubFlow
 * Description: Event calendar with bookings and payments for clubs and associations. Lightweight, modern, integrated Stripe payments.
 * Version: 0.4.9
 * Author: Tibor Berki <https://github.com/Tdude>
 * Text Domain: clubflow
 */

if (!defined('ABSPATH')) {
	exit;
}

require_once __DIR__ . '/includes/class-clubflow-utils.php';
require_once __DIR__ . '/includes/class-clubflow-assets.php';
require_once __DIR__ . '/includes/class-clubflow-cpt.php';
require_once __DIR__ . '/includes/class-clubflow-admin.php';
require_once __DIR__ . '/includes/class-clubflow-shortcodes.php';
require_once __DIR__ . '/includes/class-clubflow-ajax.php';
require_once __DIR__ . '/includes/class-clubflow-booking.php';
require_once __DIR__ . '/includes/class-clubflow-payment.php';
require_once __DIR__ . '/includes/class-clubflow-mailchimp.php';
require_once __DIR__ . '/includes/class-clubflow-swish.php';
require_once __DIR__ . '/includes/class-clubflow-klarna.php';
require_once __DIR__ . '/includes/class-clubflow-stripe.php';
require_once __DIR__ . '/includes/class-clubflow-recurrence.php';
require_once __DIR__ . '/includes/class-clubflow-notifications.php';

final class ClubFlow {
	public const VERSION = '0.4.9';
	public const POST_TYPE = 'club_event';
	public const TAX_CATEGORY = 'event_category';
	public const TAX_TAG = 'event_tag';
	public const AJAX_ACTION_EVENTS = 'clubflow_events';
	public const AJAX_ACTION_EVENT_DETAILS = 'clubflow_event_details';
	public const AJAX_ACTION_BOOK = 'clubflow_book';

	private string $plugin_file;
	private ClubFlow_Cpt $cpt;
	private ClubFlow_Assets $assets;
	private ClubFlow_Admin $admin;
	private ClubFlow_Shortcodes $shortcodes;
	private ClubFlow_Ajax $ajax;
	private ClubFlow_Booking $booking;
	private ClubFlow_Payment $payment;
	private ClubFlow_Mailchimp $mailchimp;
	private ClubFlow_Swish $swish;
	private ClubFlow_Klarna $klarna;
	private ClubFlow_Stripe $stripe;
	private ClubFlow_Recurrence $recurrence;

	public function __construct(string $plugin_file) {
		$this->plugin_file = $plugin_file;

		$utils = new ClubFlow_Utils();
		$this->assets = new ClubFlow_Assets($this);
		$this->cpt = new ClubFlow_Cpt();
		$this->admin = new ClubFlow_Admin($utils);
		$this->shortcodes = new ClubFlow_Shortcodes($this->assets, $utils);
		$this->ajax = new ClubFlow_Ajax($utils);
		$this->booking = new ClubFlow_Booking();
		$this->payment = new ClubFlow_Payment();
		$this->mailchimp = new ClubFlow_Mailchimp();
		$this->swish = new ClubFlow_Swish();
		$this->klarna = new ClubFlow_Klarna();
		$this->stripe = new ClubFlow_Stripe();
		$this->recurrence = new ClubFlow_Recurrence();
	}

	public function plugin_file(): string {
		return $this->plugin_file;
	}

	public function init(): void {
		add_action('plugins_loaded', [$this, 'load_textdomain']);
		add_filter('posts_clauses', [$this, 'fix_meta_value_orderby_for_strict_sql'], 10, 2);
		$this->cpt->register();
		$this->admin->register();
		$this->shortcodes->register();
		$this->ajax->register();
		$this->assets->register();
		$this->booking->register();
		$this->payment->register();
		$this->mailchimp->register();
		$this->swish->register();
		$this->klarna->register();
		$this->stripe->register();
		$this->recurrence->register();
	}

	/**
	 * Make `orderby => 'meta_value'` queries compatible with MySQL strict mode.
	 *
	 * WordPress builds meta-value-ordered queries as:
	 *     GROUP BY wp_posts.ID ... ORDER BY wp_postmeta.meta_value
	 * Under ONLY_FULL_GROUP_BY (enabled by default on many hosts, e.g. one.com)
	 * MySQL rejects a non-aggregated joined column in ORDER BY. The query then
	 * errors and WP_Query returns ZERO rows — which silently blanks the public
	 * calendar feed, the events list, and the admin list/calendar.
	 *
	 * Wrapping the meta_value reference in MIN() satisfies the SQL mode without
	 * changing the result order (each post has a single row for the ordered meta
	 * key, so MIN() === that value). Scoped to this plugin's post types and only
	 * applied when a GROUP BY is actually present, so nothing else is affected.
	 *
	 * @param array     $clauses SQL clauses (join/where/groupby/orderby/...).
	 * @param \WP_Query $query   The query being run.
	 * @return array
	 */
	public function fix_meta_value_orderby_for_strict_sql($clauses, $query) {
		// Defensive: this filter can be invoked by other code with unexpected args.
		if (!is_array($clauses) || !($query instanceof \WP_Query)) {
			return $clauses;
		}
		$post_types = (array) $query->get('post_type');
		if (!array_intersect($post_types, [self::POST_TYPE, 'club_booking'])) {
			return $clauses;
		}
		if (empty($clauses['groupby']) || strpos((string) $clauses['orderby'], '.meta_value') === false) {
			return $clauses;
		}
		$clauses['orderby'] = preg_replace('/\b(\w+)\.meta_value\b/', 'MIN($1.meta_value)', $clauses['orderby']);
		return $clauses;
	}

	public function load_textdomain(): void {
		load_plugin_textdomain('clubflow', false, dirname(plugin_basename($this->plugin_file)) . '/languages');
	}

	public function activate(): void {
		$this->cpt->register_cpt_and_taxonomies();
		flush_rewrite_rules();
	}

	public function deactivate(): void {
		flush_rewrite_rules();
	}
}

$clubflow = new ClubFlow(__FILE__);
$clubflow->init();

register_activation_hook(__FILE__, [$clubflow, 'activate']);
register_deactivation_hook(__FILE__, [$clubflow, 'deactivate']);
