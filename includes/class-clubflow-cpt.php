<?php

if (!defined('ABSPATH')) {
	exit;
}

final class ClubFlow_Cpt {
	public function register(): void {
		add_action('init', [$this, 'register_cpt_and_taxonomies']);
	}

	public function register_cpt_and_taxonomies(): void {
		$labels = [
			'name' => __('Events', 'clubflow'),
			'singular_name' => __('Event', 'clubflow'),
			'menu_name' => __('Calendar', 'clubflow'),
			'name_admin_bar' => __('Event', 'clubflow'),
			'add_new' => __('Add New', 'clubflow'),
			'add_new_item' => __('Add New Event', 'clubflow'),
			'new_item' => __('New Event', 'clubflow'),
			'edit_item' => __('Edit Event', 'clubflow'),
			'view_item' => __('View Event', 'clubflow'),
			'all_items' => __('All Events', 'clubflow'),
			'search_items' => __('Search Events', 'clubflow'),
			'not_found' => __('No events found.', 'clubflow'),
			'not_found_in_trash' => __('No events found in Trash.', 'clubflow'),
		];

		$args = [
			'labels' => $labels,
			'public' => true,
			'show_in_rest' => true,
			'has_archive' => true,
			'menu_icon' => 'dashicons-calendar-alt',
			'supports' => ['title', 'editor', 'thumbnail', 'excerpt', 'custom-fields'],
			'rewrite' => ['slug' => 'events'],
		];

		register_post_type(ClubFlow::POST_TYPE, $args);

		$cat_labels = [
			'name' => __('Event Categories', 'clubflow'),
			'singular_name' => __('Event Category', 'clubflow'),
		];

		register_taxonomy(
			ClubFlow::TAX_CATEGORY,
			[ClubFlow::POST_TYPE],
			[
				'labels' => $cat_labels,
				'public' => true,
				'show_in_rest' => true,
				'hierarchical' => true,
				'rewrite' => ['slug' => 'event-category'],
			]
		);

		$tag_labels = [
			'name' => __('Event Tags', 'clubflow'),
			'singular_name' => __('Event Tag', 'clubflow'),
		];

		register_taxonomy(
			ClubFlow::TAX_TAG,
			[ClubFlow::POST_TYPE],
			[
				'labels' => $tag_labels,
				'public' => true,
				'show_in_rest' => true,
				'hierarchical' => false,
				'rewrite' => ['slug' => 'event-tag'],
			]
		);
	}
}
