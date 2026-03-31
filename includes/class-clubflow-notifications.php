<?php
/**
 * Email notifications fallback for ClubFlow
 *
 * Sends booking confirmation emails via wp_mail() when Mailchimp is not
 * configured, and always sends admin notifications for new bookings.
 *
 * @package ClubFlow
 * @since   0.4.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ClubFlow_Notifications {

	/**
	 * Check whether Mailchimp is enabled and has an API key configured.
	 */
	private static function is_mailchimp_active(): bool {
		if ( ! class_exists( 'ClubFlow_Payment' ) ) {
			return false;
		}
		$payment_settings = get_option( ClubFlow_Payment::OPTION_KEY, array() );
		return ! empty( $payment_settings['mailchimp_enabled'] )
			&& ! empty( $payment_settings['mailchimp_api_key'] );
	}

	/**
	 * Gather all booking and event data needed for notification emails.
	 *
	 * @param int $booking_id Booking post ID.
	 * @return array|null Associative array of data, or null on failure.
	 */
	private static function get_booking_data( int $booking_id ): ?array {
		$booking = get_post( $booking_id );
		if ( ! $booking || $booking->post_type !== 'club_booking' ) {
			return null;
		}

		$event_id       = (int) get_post_meta( $booking_id, '_clubflow_booking_event_id', true );
		$event          = get_post( $event_id );
		$event_title    = $event ? $event->post_title : __( 'Okänt event', 'clubflow' );
		$event_start    = get_post_meta( $event_id, '_clubflow_start', true );
		$event_location = get_post_meta( $event_id, '_clubflow_location', true );

		$customer_name  = get_post_meta( $booking_id, '_clubflow_booking_name', true );
		$customer_email = get_post_meta( $booking_id, '_clubflow_booking_email', true );
		$confirmation   = get_post_meta( $booking_id, '_clubflow_booking_confirmation_code', true );
		$status         = get_post_meta( $booking_id, '_clubflow_booking_status', true );

		$date_formatted = '';
		if ( $event_start ) {
			$date_formatted = wp_date( 'l j F Y, H:i', strtotime( $event_start ) );
		}

		return array(
			'event_id'          => $event_id,
			'event_title'       => $event_title,
			'event_start'       => $event_start,
			'event_location'    => $event_location,
			'date_formatted'    => $date_formatted,
			'customer_name'     => $customer_name,
			'customer_email'    => $customer_email,
			'confirmation_code' => $confirmation,
			'status'            => $status,
		);
	}

	/**
	 * Translate a booking status key to a human-readable Swedish label.
	 */
	private static function status_label( string $status ): string {
		$labels = array(
			'confirmed'       => __( 'Bekräftad', 'clubflow' ),
			'pending'         => __( 'Väntar på bekräftelse', 'clubflow' ),
			'pending_payment' => __( 'Väntar på betalning', 'clubflow' ),
			'cancelled'       => __( 'Avbokad', 'clubflow' ),
		);
		return $labels[ $status ] ?? $status;
	}

	// ------------------------------------------------------------------
	// Customer confirmation
	// ------------------------------------------------------------------

	/**
	 * Send a booking confirmation email to the customer.
	 *
	 * If Mailchimp is active, the customer email is skipped because
	 * Mailchimp (or its on_booking_created / on_payment_completed hooks)
	 * already handles it.
	 *
	 * @param int $booking_id Booking post ID.
	 * @return bool True if mail was sent (or intentionally skipped).
	 */
	public static function send_booking_confirmation( int $booking_id ): bool {
		// Mailchimp handles customer emails when it is active.
		if ( self::is_mailchimp_active() ) {
			return true;
		}

		$data = self::get_booking_data( $booking_id );
		if ( ! $data || empty( $data['customer_email'] ) ) {
			return false;
		}

		$subject = sprintf(
			/* translators: %s: event name */
			__( 'Bokningsbekräftelse: %s', 'clubflow' ),
			$data['event_title']
		);

		$body = self::build_customer_html( $data );

		$from_name  = get_bloginfo( 'name' );
		$from_email = get_option( 'admin_email' );
		$headers    = array(
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', $from_name, $from_email ),
		);

		return wp_mail( $data['customer_email'], $subject, $body, $headers );
	}

	/**
	 * Build the HTML body for the customer confirmation email.
	 *
	 * Uses inline CSS so the email renders properly in all major clients.
	 */
	private static function build_customer_html( array $data ): string {
		$site_name = esc_html( get_bloginfo( 'name' ) );

		$status_text = self::status_label( $data['status'] );

		// Build detail rows
		$rows = '';

		$rows .= self::html_row(
			__( 'Event', 'clubflow' ),
			esc_html( $data['event_title'] )
		);

		if ( $data['date_formatted'] ) {
			$rows .= self::html_row(
				__( 'Datum &amp; tid', 'clubflow' ),
				esc_html( $data['date_formatted'] )
			);
		}

		if ( $data['event_location'] ) {
			$rows .= self::html_row(
				__( 'Plats', 'clubflow' ),
				esc_html( $data['event_location'] )
			);
		}

		$rows .= self::html_row(
			__( 'Bekräftelsekod', 'clubflow' ),
			'<strong>' . esc_html( $data['confirmation_code'] ) . '</strong>'
		);

		$rows .= self::html_row(
			__( 'Status', 'clubflow' ),
			esc_html( $status_text )
		);

		$greeting = sprintf(
			/* translators: %s: customer first name */
			__( 'Hej %s,', 'clubflow' ),
			esc_html( $data['customer_name'] )
		);

		$html = <<<HTML
<!DOCTYPE html>
<html lang="sv">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background-color:#f4f4f7;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f4f4f7;">
<tr><td align="center" style="padding:30px 10px;">

<table role="presentation" width="580" cellspacing="0" cellpadding="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;">
  <!-- Header -->
  <tr>
    <td style="background-color:#2c3e50;padding:24px 30px;color:#ffffff;font-size:20px;font-weight:bold;">
      {$site_name}
    </td>
  </tr>
  <!-- Body -->
  <tr>
    <td style="padding:30px;">
      <p style="margin:0 0 16px;font-size:16px;color:#333333;">{$greeting}</p>
      <p style="margin:0 0 24px;font-size:15px;color:#555555;">
        {$this_confirmation_text}
      </p>
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #e0e0e0;border-radius:6px;overflow:hidden;">
        {$rows}
      </table>
      <p style="margin:24px 0 0;font-size:14px;color:#888888;">
        {$this_footer_text}
      </p>
    </td>
  </tr>
  <!-- Footer -->
  <tr>
    <td style="background-color:#f4f4f7;padding:16px 30px;font-size:12px;color:#999999;text-align:center;">
      &copy; {$site_name}
    </td>
  </tr>
</table>

</td></tr>
</table>
</body>
</html>
HTML;

		// Replace placeholder texts (avoids issues with __() inside heredoc)
		$html = str_replace(
			'{$this_confirmation_text}',
			esc_html__( 'Tack för din bokning! Här är dina bokningsuppgifter:', 'clubflow' ),
			$html
		);
		$html = str_replace(
			'{$this_footer_text}',
			esc_html__( 'Spara din bekräftelsekod. Visa den vid incheckning.', 'clubflow' ),
			$html
		);

		return $html;
	}

	/**
	 * Build a single detail row for the HTML email table.
	 */
	private static function html_row( string $label, string $value ): string {
		return sprintf(
			'<tr><td style="padding:10px 14px;font-size:14px;color:#666666;border-bottom:1px solid #eeeeee;width:140px;">%s</td>'
			. '<td style="padding:10px 14px;font-size:14px;color:#333333;border-bottom:1px solid #eeeeee;">%s</td></tr>',
			$label,
			$value
		);
	}

	// ------------------------------------------------------------------
	// Admin notification
	// ------------------------------------------------------------------

	/**
	 * Send a plain-text notification to the site admin about a new booking.
	 *
	 * This is always sent regardless of Mailchimp configuration.
	 *
	 * @param int $booking_id Booking post ID.
	 * @return bool True if mail was sent.
	 */
	public static function send_admin_notification( int $booking_id ): bool {
		$data = self::get_booking_data( $booking_id );
		if ( ! $data ) {
			return false;
		}

		$admin_email = get_option( 'admin_email' );
		if ( empty( $admin_email ) ) {
			return false;
		}

		$site_name   = get_bloginfo( 'name' );
		$status_text = self::status_label( $data['status'] );

		$subject = sprintf(
			/* translators: %s: event name */
			__( 'Ny bokning: %s', 'clubflow' ),
			$data['event_title']
		);

		$lines = array();
		$lines[] = sprintf( __( 'Ny bokning på %s', 'clubflow' ), $site_name );
		$lines[] = str_repeat( '-', 40 );
		$lines[] = '';
		$lines[] = sprintf( __( 'Kund: %s', 'clubflow' ), $data['customer_name'] );
		$lines[] = sprintf( __( 'E-post: %s', 'clubflow' ), $data['customer_email'] );
		$lines[] = sprintf( __( 'Event: %s', 'clubflow' ), $data['event_title'] );

		if ( $data['date_formatted'] ) {
			$lines[] = sprintf( __( 'Datum: %s', 'clubflow' ), $data['date_formatted'] );
		}
		if ( $data['event_location'] ) {
			$lines[] = sprintf( __( 'Plats: %s', 'clubflow' ), $data['event_location'] );
		}

		$lines[] = sprintf( __( 'Bekräftelsekod: %s', 'clubflow' ), $data['confirmation_code'] );
		$lines[] = sprintf( __( 'Status: %s', 'clubflow' ), $status_text );
		$lines[] = '';
		$lines[] = sprintf(
			__( 'Visa bokning: %s', 'clubflow' ),
			admin_url( 'post.php?post=' . $booking_id . '&action=edit' )
		);

		$body = implode( "\n", $lines );

		$headers = array(
			'Content-Type: text/plain; charset=UTF-8',
		);

		return wp_mail( $admin_email, $subject, $body, $headers );
	}
}
