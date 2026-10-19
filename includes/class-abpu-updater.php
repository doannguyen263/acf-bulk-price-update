<?php
/**
 * Price parsing and post meta update helpers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABPU_Updater {

	/**
	 * Parse a raw meta value into a non-negative integer, or null if invalid.
	 *
	 * @param mixed $raw Raw value.
	 * @return int|null
	 */
	public static function parse_price( $raw ) {
		if ( null === $raw || false === $raw ) {
			return null;
		}

		$str = trim( (string) $raw );
		if ( '' === $str ) {
			return null;
		}

		$digits = preg_replace( '/\D+/', '', $str );
		if ( null === $digits || '' === $digits ) {
			return null;
		}

		return (int) $digits;
	}

	/**
	 * Format integer price as canonical string.
	 *
	 * @param int $value Integer price.
	 * @return string
	 */
	public static function format_price( $value ) {
		$value = (int) $value;
		if ( $value < 0 ) {
			$value = 0;
		}
		return (string) $value;
	}

	/**
	 * Apply amount to an old price.
	 *
	 * @param int $old_price Current price.
	 * @param int $amount    Delta (can be negative).
	 * @return int
	 */
	public static function apply_amount( $old_price, $amount ) {
		$new = (int) $old_price + (int) $amount;
		return $new < 0 ? 0 : $new;
	}

	/**
	 * Resolve post IDs for a run.
	 *
	 * @param string $post_type Post type.
	 * @param string $mode      all|selected.
	 * @param int[]  $post_ids  Selected IDs when mode=selected.
	 * @return int[]
	 */
	public static function resolve_post_ids( $post_type, $mode, $post_ids = array() ) {
		$post_type = sanitize_key( $post_type );

		if ( 'selected' === $mode ) {
			$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $post_ids ) ) ) );
			if ( empty( $ids ) ) {
				return array();
			}

			$query = new WP_Query(
				array(
					'post_type'              => $post_type,
					'post__in'               => $ids,
					'posts_per_page'         => count( $ids ),
					'post_status'            => 'any',
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);

			return array_map( 'intval', $query->posts );
		}

		$ids   = array();
		$paged = 1;

		do {
			$query = new WP_Query(
				array(
					'post_type'              => $post_type,
					'post_status'            => 'publish',
					'posts_per_page'         => 500,
					'paged'                  => $paged,
					'fields'                 => 'ids',
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => false,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);

			if ( ! empty( $query->posts ) ) {
				$ids = array_merge( $ids, array_map( 'intval', $query->posts ) );
			}

			$max_pages = (int) $query->max_num_pages;
			$paged++;
		} while ( $paged <= $max_pages );

		return $ids;
	}

	/**
	 * Preview stats for a planned run.
	 *
	 * @param string $post_type  Post type.
	 * @param string $field_name Meta key.
	 * @param string $mode       all|selected.
	 * @param int[]  $post_ids   Selected IDs.
	 * @return array
	 */
	public static function preview( $post_type, $field_name, $mode, $post_ids = array() ) {
		$ids = self::resolve_post_ids( $post_type, $mode, $post_ids );

		$total     = count( $ids );
		$has_meta  = 0;
		$parseable = 0;
		$skip      = 0;

		foreach ( $ids as $post_id ) {
			$raw = get_post_meta( $post_id, $field_name, true );
			if ( '' === $raw || null === $raw || false === $raw ) {
				$skip++;
				continue;
			}
			$has_meta++;
			$parsed = self::parse_price( $raw );
			if ( null === $parsed ) {
				$skip++;
			} else {
				$parseable++;
			}
		}

		return array(
			'total'     => $total,
			'has_meta'  => $has_meta,
			'parseable' => $parseable,
			'skip'      => $skip,
			'post_ids'  => $ids,
		);
	}

	/**
	 * Process one pending item: snapshot + update meta.
	 *
	 * @param object $item       Item row.
	 * @param string $field_name Meta key.
	 * @param int    $amount     Delta.
	 * @return array{ok: bool, status: string, message?: string}
	 */
	public static function process_item( $item, $field_name, $amount ) {
		$post_id = (int) $item->post_id;
		$raw     = get_post_meta( $post_id, $field_name, true );
		$parsed  = self::parse_price( $raw );

		if ( null === $parsed ) {
			ABPU_DB::update_item(
				(int) $item->id,
				array(
					'status'        => 'skipped',
					'error_message' => 'Empty or non-numeric value',
					'old_value'     => is_scalar( $raw ) ? (string) $raw : '',
				)
			);
			return array(
				'ok'     => true,
				'status' => 'skipped',
			);
		}

		$old_value = self::format_price( $parsed );
		$new_value = self::format_price( self::apply_amount( $parsed, $amount ) );

		$updated = update_post_meta( $post_id, $field_name, $new_value );
		// update_post_meta returns false when value unchanged; treat as success if meta matches.
		$current = get_post_meta( $post_id, $field_name, true );
		if ( (string) $current !== (string) $new_value && false === $updated ) {
			ABPU_DB::update_item(
				(int) $item->id,
				array(
					'status'        => 'error',
					'old_value'     => $old_value,
					'new_value'     => $new_value,
					'error_message' => 'Failed to update post meta',
				)
			);
			return array(
				'ok'      => false,
				'status'  => 'error',
				'message' => 'Failed to update post meta',
			);
		}

		ABPU_DB::update_item(
			(int) $item->id,
			array(
				'status'    => 'updated',
				'old_value' => $old_value,
				'new_value' => $new_value,
			)
		);

		return array(
			'ok'     => true,
			'status' => 'updated',
		);
	}

	/**
	 * Rollback one updated item.
	 *
	 * @param object $item       Item row.
	 * @param string $field_name Meta key.
	 * @return array{ok: bool, status: string, message?: string}
	 */
	public static function rollback_item( $item, $field_name ) {
		$post_id   = (int) $item->post_id;
		$old_value = (string) $item->old_value;

		update_post_meta( $post_id, $field_name, $old_value );
		$current = get_post_meta( $post_id, $field_name, true );

		if ( (string) $current !== $old_value ) {
			ABPU_DB::update_item(
				(int) $item->id,
				array(
					'status'        => 'error',
					'error_message' => 'Failed to restore old value',
				)
			);
			return array(
				'ok'      => false,
				'status'  => 'error',
				'message' => 'Failed to restore old value',
			);
		}

		ABPU_DB::update_item(
			(int) $item->id,
			array(
				'status' => 'rolled_back',
			)
		);

		return array(
			'ok'     => true,
			'status' => 'rolled_back',
		);
	}

	/**
	 * Get selectable fields for a post type (ACF first, then meta sample).
	 *
	 * @param string $post_type Post type.
	 * @return array<int, array{name: string, label: string}>
	 */
	public static function get_fields_for_post_type( $post_type ) {
		$post_type = sanitize_key( $post_type );
		$fields    = array();

		if ( function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' ) ) {
			$groups = acf_get_field_groups( array( 'post_type' => $post_type ) );
			foreach ( (array) $groups as $group ) {
				$group_fields = acf_get_fields( $group );
				if ( empty( $group_fields ) ) {
					continue;
				}
				foreach ( $group_fields as $field ) {
					$type = isset( $field['type'] ) ? $field['type'] : '';
					if ( ! in_array( $type, array( 'text', 'number' ), true ) ) {
						continue;
					}
					$name = isset( $field['name'] ) ? $field['name'] : '';
					if ( '' === $name ) {
						continue;
					}
					$fields[ $name ] = array(
						'name'  => $name,
						'label' => isset( $field['label'] ) && $field['label'] ? $field['label'] . ' (' . $name . ')' : $name,
					);
				}
			}
		}

		if ( empty( $fields ) ) {
			$fields = self::get_meta_keys_sample( $post_type );
		}

		return array_values( $fields );
	}

	/**
	 * Sample distinct public meta keys from recent posts.
	 *
	 * @param string $post_type Post type.
	 * @return array<string, array{name: string, label: string}>
	 */
	private static function get_meta_keys_sample( $post_type ) {
		global $wpdb;

		$query = new WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'any',
				'posts_per_page'         => 100,
				'fields'                 => 'ids',
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		if ( empty( $query->posts ) ) {
			return array();
		}

		$ids  = array_map( 'intval', $query->posts );
		$in   = implode( ',', $ids );
		$like = $wpdb->esc_like( '_' ) . '%';
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs are integers.
				"SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE post_id IN ({$in}) AND meta_key NOT LIKE %s ORDER BY meta_key ASC",
				$like
			)
		);

		$fields = array();
		foreach ( (array) $rows as $key ) {
			$key = (string) $key;
			if ( '' === $key ) {
				continue;
			}
			$fields[ $key ] = array(
				'name'  => $key,
				'label' => $key,
			);
		}

		return $fields;
	}
}
