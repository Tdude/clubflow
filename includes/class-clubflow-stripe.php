<?php
/**
 * ClubCal Lite Stripe Integration
 *
 * Handles Stripe Checkout for card payments.
 *
 * @package ClubFlow
 * @since 1.5
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ClubFlow_Stripe {

    /**
     * Stripe API base URL
     */
    const API_BASE = 'https://api.stripe.com/v1';

    /**
     * Register Stripe integration
     */
    public function register(): void {
        // AJAX endpoints
        add_action( 'wp_ajax_clubflow_create_stripe_session', array( $this, 'ajax_create_checkout_session' ) );
        add_action( 'wp_ajax_nopriv_clubflow_create_stripe_session', array( $this, 'ajax_create_checkout_session' ) );
        
        add_action( 'wp_ajax_clubflow_check_stripe_payment', array( $this, 'ajax_check_payment_status' ) );
        add_action( 'wp_ajax_nopriv_clubflow_check_stripe_payment', array( $this, 'ajax_check_payment_status' ) );
        
        // Test connection (admin only)
        add_action( 'wp_ajax_clubflow_test_stripe', array( $this, 'ajax_test_connection' ) );

        // REST API webhook endpoint
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
    }

    /**
     * AJAX: Test Stripe connection
     */
    public function ajax_test_connection(): void {
        check_ajax_referer( 'clubflow_test_stripe', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        if ( ! self::is_configured() ) {
            wp_send_json_error( array( 'message' => __( 'Stripe keys not configured', 'clubflow' ) ) );
        }

        // Test by fetching account balance
        $result = self::api_request( '/balance', array(), 'GET' );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 
            'message' => __( 'Connection successful!', 'clubflow' ),
            'mode' => isset( $result['livemode'] ) && $result['livemode'] ? 'live' : 'test'
        ) );
    }

    /**
     * Register REST API routes for Stripe webhooks
     */
    public function register_rest_routes(): void {
        register_rest_route( 'clubflow/v1', '/stripe-webhook', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_webhook' ),
            'permission_callback' => '__return_true',
        ) );
    }

    /**
     * Get Stripe settings from unified payment options
     */
    public static function get_settings(): array {
        $payment_settings = get_option( ClubFlow_Payment::OPTION_KEY, array() );
        return array(
            'secret_key'      => $payment_settings['stripe_secret'] ?? '',
            'publishable_key' => $payment_settings['stripe_publishable'] ?? '',
            'webhook_secret'  => $payment_settings['stripe_webhook_secret'] ?? '',
        );
    }

    /**
     * Check if Stripe is configured
     */
    public static function is_configured(): bool {
        $settings = self::get_settings();
        return ! empty( $settings['secret_key'] ) && ! empty( $settings['publishable_key'] );
    }

    /**
     * Make API request to Stripe
     */
    private static function api_request( string $endpoint, array $data = array(), string $method = 'POST' ) {
        $settings = self::get_settings();
        
        if ( empty( $settings['secret_key'] ) ) {
            return new WP_Error( 'no_api_key', __( 'Stripe API key not configured', 'clubflow' ) );
        }

        // Validate key format — must start with sk_test_ or sk_live_
        if ( ! preg_match( '/^sk_(test|live)_/', $settings['secret_key'] ) ) {
            return new WP_Error( 'invalid_api_key', __( 'Invalid Stripe secret key format. It must start with sk_test_ or sk_live_.', 'clubflow' ) );
        }

        $args = array(
            'method'  => $method,
            'headers' => array(
                'Authorization' => 'Bearer ' . $settings['secret_key'],
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ),
            'timeout' => 10,
        );

        if ( ! empty( $data ) && $method === 'POST' ) {
            $args['body'] = http_build_query( $data );
        }

        $response = wp_remote_request( self::API_BASE . $endpoint, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $code = wp_remote_retrieve_response_code( $response );

        if ( $code >= 400 ) {
            $error_message = isset( $body['error']['message'] ) ? $body['error']['message'] : __( 'Stripe API error', 'clubflow' );
            return new WP_Error( 'stripe_error', $error_message, $body );
        }

        return $body;
    }

    /**
     * Create Stripe Checkout Session
     * 
     * @param int    $booking_id  Booking post ID
     * @param float  $amount      Payment amount in SEK (optional, reads from event meta if not provided)
     * @param string $description Product description (optional, uses event title if not provided)
     * @param string $return_url  URL to return to after payment (optional, uses home_url if not provided)
     * @return array Success array with session_id and checkout_url, or error array
     */
    public function create_checkout_session( int $booking_id, float $amount = 0, string $description = '', string $return_url = '' ): array {
        $booking = get_post( $booking_id );
        if ( ! $booking || $booking->post_type !== 'club_booking' ) {
            return array( 'success' => false, 'error' => __( 'Invalid booking', 'clubflow' ) );
        }

        // Get booking metadata (using ClubCal Lite meta keys)
        $event_id = get_post_meta( $booking_id, '_clubflow_booking_event_id', true );
        $event    = get_post( $event_id );
        $customer_name  = get_post_meta( $booking_id, '_clubflow_booking_name', true );
        $customer_email = get_post_meta( $booking_id, '_clubflow_booking_email', true );
        $confirmation_code = get_post_meta( $booking_id, '_clubflow_booking_confirmation_code', true );

        // Use provided amount or get from event meta
        if ( $amount <= 0 ) {
            $price_str = get_post_meta( $event_id, '_clubflow_price', true );
            $amount = (float) preg_replace( '/[^0-9.]/', '', $price_str );
        }

        if ( $amount <= 0 ) {
            return array( 'success' => false, 'error' => __( 'No payment amount specified', 'clubflow' ) );
        }

        // Use provided description or event title
        $product_name = ! empty( $description ) ? $description : ( $event ? $event->post_title : __( 'Event Booking', 'clubflow' ) );

        // Stripe expects amount in smallest currency unit (öre for SEK)
        $amount_in_ore = (int) ( $amount * 100 );

        // Build return URLs - use provided URL or fall back to home
        $base_url = ! empty( $return_url ) ? $return_url : home_url();
        // Strip any existing query params for clean URLs
        $base_url = strtok( $base_url, '?' );
        
        $success_url = add_query_arg( array(
            'booking_confirmed' => '1',
            'code'              => $confirmation_code,
        ), $base_url );
        
        $cancel_url = add_query_arg( array(
            'booking_cancelled' => '1',
            'booking_id'        => $booking_id,
        ), $base_url );

        $session_data = array(
            'payment_method_types[]'              => 'card',
            'mode'                                => 'payment',
            'success_url'                         => $success_url,
            'cancel_url'                          => $cancel_url,
            'client_reference_id'                 => $booking_id,
            'customer_email'                      => $customer_email,
            'line_items[0][price_data][currency]' => 'sek',
            'line_items[0][price_data][unit_amount]' => $amount_in_ore,
            'line_items[0][price_data][product_data][name]' => $product_name,
            'line_items[0][quantity]'             => 1,
            'metadata[booking_id]'                => $booking_id,
            'metadata[event_id]'                  => $event_id,
            'metadata[customer_name]'             => $customer_name,
            'locale'                              => 'sv',
        );

        $response = self::api_request( '/checkout/sessions', $session_data );

        if ( is_wp_error( $response ) ) {
            return array( 'success' => false, 'error' => $response->get_error_message() );
        }

        // Store session ID on booking
        update_post_meta( $booking_id, '_stripe_session_id', $response['id'] );

        return array(
            'success'      => true,
            'session_id'   => $response['id'],
            'checkout_url' => $response['url'],
            'amount'       => $amount,
        );
    }

    /**
     * AJAX: Create Checkout Session
     */
    public function ajax_create_checkout_session(): void {
        check_ajax_referer( 'clubflow_booking', 'nonce' );

        $booking_id = isset( $_POST['booking_id'] ) ? intval( $_POST['booking_id'] ) : 0;
        $return_url = isset( $_POST['return_url'] ) ? esc_url_raw( $_POST['return_url'] ) : '';

        if ( ! $booking_id ) {
            wp_send_json_error( array( 'message' => __( 'Missing booking ID', 'clubflow' ) ) );
        }

        $result = $this->create_checkout_session( $booking_id );

        if ( empty( $result['success'] ) ) {
            wp_send_json_error( array( 'message' => $result['error'] ?? __( 'Failed to create checkout session', 'clubflow' ) ) );
        }

        $settings = self::get_settings();

        wp_send_json_success( array(
            'session_id'      => $result['session_id'],
            'checkout_url'    => $result['checkout_url'],
            'publishable_key' => $settings['publishable_key'],
        ) );
    }

    /**
     * AJAX: Check payment status
     */
    public function ajax_check_payment_status(): void {
        check_ajax_referer( 'clubflow_booking', 'nonce' );

        $booking_id = isset( $_POST['booking_id'] ) ? intval( $_POST['booking_id'] ) : 0;

        if ( ! $booking_id ) {
            wp_send_json_error( array( 'message' => __( 'Missing booking ID', 'clubflow' ) ) );
        }

        $session_id = get_post_meta( $booking_id, '_stripe_session_id', true );

        if ( empty( $session_id ) ) {
            wp_send_json_error( array( 'message' => __( 'No Stripe session found', 'clubflow' ) ) );
        }

        $session = self::api_request( '/checkout/sessions/' . $session_id, array(), 'GET' );

        if ( is_wp_error( $session ) ) {
            wp_send_json_error( array( 'message' => $session->get_error_message() ) );
        }

        $payment_status = $session['payment_status'];
        $booking_status = get_post_meta( $booking_id, '_clubflow_booking_status', true );

        // If paid but booking not yet confirmed, confirm it
        if ( $payment_status === 'paid' && $booking_status !== 'confirmed' ) {
            self::confirm_booking_payment( $booking_id, $session );
            $booking_status = 'confirmed';
        }

        wp_send_json_success( array(
            'payment_status' => $payment_status,
            'booking_status' => $booking_status,
        ) );
    }

    /**
     * Handle Stripe webhook
     */
    public static function handle_webhook( WP_REST_Request $request ) {
        $payload   = $request->get_body();
        $sig_header = $request->get_header( 'stripe-signature' );
        $settings   = self::get_settings();

        // Webhook must be verified. Without a configured secret we cannot trust incoming events.
        if ( empty( $settings['webhook_secret'] ) ) {
            return new WP_REST_Response( array( 'error' => 'Webhook secret not configured' ), 400 );
        }

        if ( empty( $sig_header ) ) {
            return new WP_REST_Response( array( 'error' => 'Missing signature header' ), 400 );
        }

        $verified = self::verify_webhook_signature( $payload, $sig_header, $settings['webhook_secret'] );
        if ( ! $verified ) {
            return new WP_REST_Response( array( 'error' => 'Invalid signature' ), 400 );
        }

        $event = json_decode( $payload, true );

        if ( ! isset( $event['type'] ) ) {
            return new WP_REST_Response( array( 'error' => 'Invalid event' ), 400 );
        }

        // Handle checkout.session.completed
        if ( $event['type'] === 'checkout.session.completed' ) {
            $session_from_event = $event['data']['object'] ?? array();
            $session_id = $session_from_event['id'] ?? '';
            if ( empty( $session_id ) ) {
                return new WP_REST_Response( array( 'error' => 'Missing session id' ), 400 );
            }

            // Verify the session directly with Stripe API to avoid trusting any forged payload.
            $session = self::api_request( '/checkout/sessions/' . $session_id, array(), 'GET' );
            if ( is_wp_error( $session ) ) {
                return new WP_REST_Response( array( 'error' => $session->get_error_message() ), 400 );
            }

            $booking_id = isset( $session['metadata']['booking_id'] ) ? intval( $session['metadata']['booking_id'] ) : 0;
            if ( $booking_id && ( $session['payment_status'] ?? '' ) === 'paid' ) {
                self::confirm_booking_payment( $booking_id, $session );
            }
        }

        return new WP_REST_Response( array( 'received' => true ), 200 );
    }

    /**
     * Verify Stripe webhook signature
     */
    private static function verify_webhook_signature( $payload, $sig_header, $secret ) {
        $elements = explode( ',', $sig_header );
        $timestamp = null;
        $signature = null;

        foreach ( $elements as $element ) {
            $parts = explode( '=', $element, 2 );
            if ( count( $parts ) === 2 ) {
                if ( $parts[0] === 't' ) {
                    $timestamp = $parts[1];
                } elseif ( $parts[0] === 'v1' ) {
                    $signature = $parts[1];
                }
            }
        }

        if ( ! $timestamp || ! $signature ) {
            return false;
        }

        // Check timestamp (allow 5 minutes tolerance)
        if ( abs( time() - intval( $timestamp ) ) > 300 ) {
            return false;
        }

        $signed_payload   = $timestamp . '.' . $payload;
        $expected_signature = hash_hmac( 'sha256', $signed_payload, $secret );

        return hash_equals( $expected_signature, $signature );
    }

    /**
     * Confirm booking after successful payment
     */
    private static function confirm_booking_payment( int $booking_id, array $session ): void {
        // Idempotency guard: prevent duplicate payment logs and emails from webhook+polling race
        $current_status = get_post_meta( $booking_id, '_clubflow_booking_status', true );
        if ( $current_status === 'confirmed' ) {
            return;
        }

        $event_id = get_post_meta( $booking_id, '_clubflow_booking_event_id', true );
        $customer_email = get_post_meta( $booking_id, '_clubflow_booking_email', true );
        $customer_name = get_post_meta( $booking_id, '_clubflow_booking_name', true );

        // Update booking status (using ClubCal Lite meta keys)
        update_post_meta( $booking_id, '_clubflow_booking_status', 'confirmed' );
        update_post_meta( $booking_id, '_stripe_payment_intent', $session['payment_intent'] ?? '' );
        update_post_meta( $booking_id, '_payment_completed_at', current_time( 'mysql' ) );

        // Log the successful payment
        if ( class_exists( 'ClubFlow_Payment' ) ) {
            ClubFlow_Payment::log_payment( array(
                'booking_id' => $booking_id,
                'event_id'   => $event_id,
                'method'     => 'stripe',
                'status'     => 'completed',
                'amount'     => ( $session['amount_total'] ?? 0 ) / 100,
                'currency'   => 'SEK',
                'reference'  => $session['payment_intent'] ?? $session['id'],
                'email'      => $customer_email,
                'name'       => $customer_name,
                'notes'      => 'Stripe Checkout completed',
            ) );
        }

        // Send confirmation email
        if ( class_exists( 'ClubFlow_Mailchimp' ) ) {
            ClubFlow_Mailchimp::send_booking_confirmation( $booking_id );
        }

        // Fallback customer confirmation (skips automatically when Mailchimp is active)
        if ( class_exists( 'ClubFlow_Notifications' ) ) {
            ClubFlow_Notifications::send_booking_confirmation( $booking_id );
            ClubFlow_Notifications::send_admin_notification( $booking_id );
        }

        // Fire action for extensibility
        do_action( 'clubflow_booking_payment_confirmed', $booking_id, 'stripe', $session );
    }
}
