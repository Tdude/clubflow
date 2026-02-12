<?php
/**
 * Plugin Name: ClubFlow
 * Description: Event calendar with bookings and payments for clubs and associations. Lightweight, modern, integrated Stripe payments.
 * Version: 0.2.8
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

final class ClubFlow {
	public const VERSION = '0.2.8';
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
