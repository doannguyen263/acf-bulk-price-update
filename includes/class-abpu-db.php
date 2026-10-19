<?php
/**
 * Database helpers for batches and items.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABPU_DB {

	/**
	 * Batches table name.
	 *
	 * @return string
	 */
	public static function batches_table() {
		global $wpdb;
		return $wpdb->prefix . 'abpu_batches';
	}

	/**
	 * Batch items table name.
	 *
	 * @return string
	 */
	public static function items_table() {
		global $wpdb;
		return $wpdb->prefix . 'abpu_batch_items';
	}

	/**
	 * Whether a running batch already exists.
	 *
	 * @return bool
	 */
	public static function has_running_batch() {
		global $wpdb;
		$table = self::batches_table();
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'running'" );
		return $count > 0;
	}

	/**
	 * Create a new batch row.
	 *
	 * @param array $data Batch fields.
	 * @return int|false Batch ID or false.
	 */
	public static function create_batch( $data ) {
		global $wpdb;

		$inserted = $wpdb->insert(
			self::batches_table(),
			array(
				'post_type'  => $data['post_type'],
				'field_name' => $data['field_name'],
				'amount'     => (int) $data['amount'],
				'mode'       => $data['mode'],
				'status'     => 'running',
				'total'      => (int) $data['total'],
				'processed'  => 0,
				'user_id'    => (int) $data['user_id'],
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%s' )
		);

		if ( ! $inserted ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Insert pending items for a batch.
	 *
	 * @param int   $batch_id Batch ID.
	 * @param int[] $post_ids Post IDs.
	 * @return int Number of inserted rows.
	 */
	public static function insert_pending_items( $batch_id, $post_ids ) {
		global $wpdb;

		if ( empty( $post_ids ) ) {
			return 0;
		}

		$table   = self::items_table();
		$batch_id = (int) $batch_id;
		$values  = array();

		foreach ( $post_ids as $post_id ) {
			$post_id  = (int) $post_id;
			$values[] = $wpdb->prepare( '(%d, %d, %s)', $batch_id, $post_id, 'pending' );
		}

		// Insert in chunks to avoid oversized queries.
		$inserted = 0;
		$chunks   = array_chunk( $values, 200 );

		foreach ( $chunks as $chunk ) {
			$sql = "INSERT INTO {$table} (batch_id, post_id, status) VALUES " . implode( ',', $chunk );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- values already prepared.
			$result = $wpdb->query( $sql );
			if ( false !== $result ) {
				$inserted += count( $chunk );
			}
		}

		return $inserted;
	}

	/**
	 * Get batch by ID.
	 *
	 * @param int $batch_id Batch ID.
	 * @return object|null
	 */
	public static function get_batch( $batch_id ) {
		global $wpdb;
		$table = self::batches_table();
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $batch_id )
		);
	}

	/**
	 * Update batch fields.
	 *
	 * @param int   $batch_id Batch ID.
	 * @param array $data     Fields to update.
	 * @return bool
	 */
	public static function update_batch( $batch_id, $data ) {
		global $wpdb;
		return false !== $wpdb->update(
			self::batches_table(),
			$data,
			array( 'id' => (int) $batch_id )
		);
	}

	/**
	 * Fetch pending items for processing.
	 *
	 * @param int $batch_id Batch ID.
	 * @param int $limit    Chunk size.
	 * @return array
	 */
	public static function get_pending_items( $batch_id, $limit ) {
		global $wpdb;
		$table = self::items_table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE batch_id = %d AND status = 'pending' ORDER BY id ASC LIMIT %d",
				(int) $batch_id,
				(int) $limit
			)
		);
	}

	/**
	 * Fetch updated items for rollback.
	 *
	 * @param int $batch_id Batch ID.
	 * @param int $limit    Chunk size.
	 * @return array
	 */
	public static function get_updated_items( $batch_id, $limit ) {
		global $wpdb;
		$table = self::items_table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE batch_id = %d AND status = 'updated' ORDER BY id ASC LIMIT %d",
				(int) $batch_id,
				(int) $limit
			)
		);
	}

	/**
	 * Update a batch item.
	 *
	 * @param int   $item_id Item ID.
	 * @param array $data    Fields.
	 * @return bool
	 */
	public static function update_item( $item_id, $data ) {
		global $wpdb;
		return false !== $wpdb->update(
			self::items_table(),
			$data,
			array( 'id' => (int) $item_id )
		);
	}

	/**
	 * Count items by status for a batch.
	 *
	 * @param int    $batch_id Batch ID.
	 * @param string $status   Status.
	 * @return int
	 */
	public static function count_items_by_status( $batch_id, $status ) {
		global $wpdb;
		$table = self::items_table();
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE batch_id = %d AND status = %s",
				(int) $batch_id,
				$status
			)
		);
	}

	/**
	 * List batches paginated.
	 *
	 * @param int $page     Page number.
	 * @param int $per_page Per page.
	 * @return array{items: array, total: int}
	 */
	public static function list_batches( $page = 1, $per_page = 20 ) {
		global $wpdb;
		$table    = self::batches_table();
		$page     = max( 1, (int) $page );
		$per_page = max( 1, (int) $per_page );
		$offset   = ( $page - 1 ) * $per_page;

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);

		return array(
			'items' => $items ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * List items for a batch (paginated).
	 *
	 * @param int $batch_id Batch ID.
	 * @param int $page     Page.
	 * @param int $per_page Per page.
	 * @return array{items: array, total: int}
	 */
	public static function list_batch_items( $batch_id, $page = 1, $per_page = 50 ) {
		global $wpdb;
		$table    = self::items_table();
		$page     = max( 1, (int) $page );
		$per_page = max( 1, (int) $per_page );
		$offset   = ( $page - 1 ) * $per_page;
		$batch_id = (int) $batch_id;

		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE batch_id = %d", $batch_id )
		);
		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE batch_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
				$batch_id,
				$per_page,
				$offset
			)
		);

		return array(
			'items' => $items ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Delete a batch and its items (history only).
	 *
	 * @param int $batch_id Batch ID.
	 * @return bool
	 */
	public static function delete_batch( $batch_id ) {
		global $wpdb;
		$batch_id = (int) $batch_id;
		$wpdb->delete( self::items_table(), array( 'batch_id' => $batch_id ), array( '%d' ) );
		return false !== $wpdb->delete( self::batches_table(), array( 'id' => $batch_id ), array( '%d' ) );
	}
}
