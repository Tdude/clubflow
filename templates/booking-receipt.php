<?php

if (!defined('ABSPATH')) {
	exit;
}

$title = (string) ($receipt['title'] ?? '');
$customer_name = (string) ($receipt['customer_name'] ?? '');
$customer_email = (string) ($receipt['customer_email'] ?? '');
$from = (string) ($receipt['from'] ?? '');
$to = (string) ($receipt['to'] ?? '');
$total_paid = (float) ($receipt['total_paid'] ?? 0);
$currency = (string) ($receipt['currency'] ?? 'SEK');
$rows = is_array($receipt['rows'] ?? null) ? $receipt['rows'] : [];

?><!doctype html>
<html>
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php echo esc_html($title); ?></title>
	<style>
		body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; font-size: 13px; color: #111; }
		h1 { font-size: 18px; margin: 0 0 8px 0; }
		.meta { margin: 0 0 14px 0; }
		.meta strong { display: inline-block; min-width: 120px; }
		table { width: 100%; border-collapse: collapse; margin-top: 14px; }
		th, td { border: 1px solid #ddd; padding: 8px; vertical-align: top; }
		th { background: #f6f7f7; text-align: left; font-weight: 600; }
		.small { color: #555; font-size: 12px; }
		.total { margin-top: 14px; font-size: 14px; }
	</style>
</head>
<body>
	<h1><?php echo esc_html($title); ?></h1>

	<div class="meta">
		<?php if ($customer_name !== ''): ?>
			<div><strong><?php echo esc_html__('Customer', 'clubflow'); ?>:</strong> <?php echo esc_html($customer_name); ?></div>
		<?php endif; ?>
		<?php if ($customer_email !== ''): ?>
			<div><strong><?php echo esc_html__('Email', 'clubflow'); ?>:</strong> <?php echo esc_html($customer_email); ?></div>
		<?php endif; ?>
		<?php if ($from !== '' || $to !== ''): ?>
			<div><strong><?php echo esc_html__('Period', 'clubflow'); ?>:</strong> <?php echo esc_html(trim($from . ' – ' . $to, ' –')); ?></div>
		<?php endif; ?>
	</div>

	<div class="total">
		<strong><?php echo esc_html__('Total', 'clubflow'); ?>:</strong>
		<?php echo esc_html(number_format($total_paid, 2, '.', ' ') . ' ' . $currency); ?>
	</div>

	<table>
		<thead>
			<tr>
				<th><?php echo esc_html__('Date purchased', 'clubflow'); ?></th>
				<th><?php echo esc_html__('Date booked', 'clubflow'); ?></th>
				<th><?php echo esc_html__('Summary', 'clubflow'); ?></th>
				<th><?php echo esc_html__('Price', 'clubflow'); ?></th>
				<th><?php echo esc_html__('Method', 'clubflow'); ?></th>
				<th><?php echo esc_html__('Status', 'clubflow'); ?></th>
				<th><?php echo esc_html__('Confirmation Code', 'clubflow'); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if (empty($rows)): ?>
			<tr>
				<td colspan="7" class="small"><?php echo esc_html__('No bookings found.', 'clubflow'); ?></td>
			</tr>
		<?php else: ?>
			<?php foreach ($rows as $row): ?>
				<?php
					$date_purchased = (string) ($row['date_purchased'] ?? '');
					$date_booked = (string) ($row['date_booked'] ?? '');
					$summary = (string) ($row['summary'] ?? '');
					$price = (string) ($row['price'] ?? '');
					$row_currency = (string) ($row['currency'] ?? $currency);
					$method = (string) ($row['payment_method'] ?? '');
					$status = (string) ($row['status'] ?? '');
					$code = (string) ($row['confirmation_code'] ?? '');
				?>
				<tr>
					<td><?php echo esc_html($date_purchased !== '' ? wp_date('Y-m-d H:i', strtotime($date_purchased)) : '—'); ?></td>
					<td><?php echo esc_html($date_booked !== '' ? wp_date('Y-m-d H:i', strtotime($date_booked)) : '—'); ?></td>
					<td><?php echo esc_html($summary !== '' ? $summary : '—'); ?></td>
					<td><?php echo esc_html($price !== '' ? ($price . ' ' . $row_currency) : '—'); ?></td>
					<td><?php echo esc_html($method !== '' ? ucfirst($method) : '—'); ?></td>
					<td><?php echo esc_html($status !== '' ? ucfirst(str_replace('_', ' ', $status)) : '—'); ?></td>
					<td><code><?php echo esc_html($code !== '' ? $code : '—'); ?></code></td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>
</body>
</html>
