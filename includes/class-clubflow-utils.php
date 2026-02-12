<?php

if (!defined('ABSPATH')) {
	exit;
}

final class ClubFlow_Utils {
	private const CATEGORY_FALLBACK_PALETTE = [
		'#2563eb',
		'#16a34a',
		'#dc2626',
		'#7c3aed',
		'#ea580c',
		'#0891b2',
		'#ca8a04',
		'#be185d',
	];

	private function parse_datetime_local(string $value): ?\DateTimeImmutable {
		$value = trim($value);
		if ($value === '') {
			return null;
		}

		$tz = wp_timezone();
		$value = str_replace('T', ' ', $value);

		$formats = [
			'Y-m-d H:i',
			'Y-m-d H:i:s',
			'Y-m-d',
		];

		foreach ($formats as $format) {
			$dt = \DateTimeImmutable::createFromFormat($format, $value, $tz);
			if ($dt instanceof \DateTimeImmutable) {
				$errors = \DateTimeImmutable::getLastErrors();
				if (empty($errors['warning_count']) && empty($errors['error_count'])) {
					if ($format === 'Y-m-d') {
						$dt = $dt->setTime(0, 0, 0);
					}
					return $dt;
				}
			}
		}

		return null;
	}

	private function parse_stored_datetime(string $stored): ?\DateTimeImmutable {
		$stored = trim($stored);
		if ($stored === '') {
			return null;
		}

		$tz = wp_timezone();
		$dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $stored, $tz);
		if (!($dt instanceof \DateTimeImmutable)) {
			return null;
		}

		$errors = \DateTimeImmutable::getLastErrors();
		if (!empty($errors['warning_count']) || !empty($errors['error_count'])) {
			return null;
		}

		return $dt;
	}

	public function normalize_datetime_for_storage(string $value): string {
		$dt = $this->parse_datetime_local($value);
		if (!$dt) {
			return '';
		}

		return $dt->format('Y-m-d H:i:s');
	}

	public function format_datetime_for_input(string $stored): string {
		$dt = $this->parse_stored_datetime($stored);
		if (!$dt) {
			return '';
		}

		return $dt->format('Y-m-d\\TH:i');
	}

	public function format_datetime_for_iso(string $stored): string {
		$dt = $this->parse_stored_datetime($stored);
		if (!$dt) {
			return '';
		}

		return $dt->format('c');
	}

	public function safe_truncate_html(string $html, int $length = 100, string $suffix = '...'): string {
		$plain_text = wp_strip_all_tags($html);
		$plain_text = html_entity_decode($plain_text, ENT_QUOTES, 'UTF-8');
		$plain_text = trim($plain_text);

		if (mb_strlen($plain_text) <= $length) {
			return wp_kses_post($html);
		}

		$truncated = mb_substr($plain_text, 0, $length);

		$last_space = mb_strrpos($truncated, ' ');
		if ($last_space !== false && $last_space > $length * 0.7) {
			$truncated = mb_substr($truncated, 0, $last_space);
		}

		return esc_html($truncated) . $suffix;
	}

	public function get_category_display_data(int $post_id): array {
		$categories = wp_get_post_terms($post_id, ClubFlow::TAX_CATEGORY);
		if (!empty($categories) && !is_wp_error($categories)) {
			$primary_category = $categories[0];
			$color = (string) get_term_meta($primary_category->term_id, 'clubflow_category_color', true);
			if ($color === '') {
				$idx = absint($primary_category->term_id) % count(self::CATEGORY_FALLBACK_PALETTE);
				$color = self::CATEGORY_FALLBACK_PALETTE[$idx];
			}
			return [
				'has_category' => true,
				'name' => $primary_category->name,
				'color' => $color,
			];
		}

		return [
			'has_category' => false,
			'name' => esc_html__('Uncategorized', 'clubflow'),
			'color' => '#111827',
		];
	}
}
