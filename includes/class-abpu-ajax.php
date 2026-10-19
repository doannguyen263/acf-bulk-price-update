<?php
/**
 * AJAX endpoints for preview, batch, rollback, fields.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABPU_Ajax {

	/**
	 * Register AJAX actions.
	 */
	public static function init() {
		$actions = array(
			'abpu_get_fields',
			'abpu_preview',
			'abpu_start_batch',
			'abpu_process_chunk',
			'abpu_rollback_chunk',
			'abpu_list_batches',
			'abpu_list_batch_items',
			'abpu_delete_batch',
		);

		foreach ( $actions as $action ) {
			add_action( 'wp_ajax_' . $action, array( __CLASS__, str_replace( 'abpu_', 'handle_', $action ) ) );
		}
	}

	/**
	 * Capability + nonce check.
	 */
	private static function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}
		check_ajax_referer( 'abpu_admin', 'nonce' );
	}

	/**
	 * Parse common run args from request.
	 *
	 * @return array
	 */
	private static function parse_run_args() {
		$post_type  = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
		$field_name = isset( $_POST['field_name'] ) ? sanitize_text_field( wp_unslash( $_POST['field_name'] ) ) : '';
		$mode       = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'all';
		$amount     = isset( $_POST['amount'] ) ? (int) $_POST['amount'] : 0;
		$chunk_size = isset( $_POST['chunk_size'] ) ? (int) $_POST['chunk_size'] : 50;
		$post_ids   = array();

		if ( isset( $_POST['post_ids'] ) ) {
			$raw = wp_unslash( $_POST['post_ids'] );
			if ( is_array( $raw ) ) {
				$post_ids = array_map( 'absint', $raw );
			} else {
				$parts    = preg_split( '/[\s,;]+/', (string) $raw );
				$post_ids = array_map( 'absint', (array) $parts );
			}
		}

		if ( ! in_array( $mode, array( 'all', 'selected' ), true ) ) {
			$mode = 'all';
		}

		$chunk_size = max( 1, min( 200, $chunk_size ) );

		return array(
			'post_type'  => $post_type,
			'field_name' => $field_name,
			'mode'       => $mode,
			'amount'     => $amount,
			'chunk_size' => $chunk_size,
			'post_ids'   => array_values( array_filter( $post_ids ) ),
		);
	}

	/**
	 * AJAX: fields for post type.
	 */
	public static function handle_get_fields() {
		self::guard();
		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
		if ( '' === $post_type ) {
			wp_send_json_error( array( 'message' => 'Missing post type' ) );
		}
		$fields = ABPU_Updater::get_fields_for_post_type( $post_type );
		wp_send_json_success( array( 'fields' => $fields ) );
	}

	/**
	 * AJAX: preview counts.
	 */
	public static function handle_preview() {
		self::guard();
		$args = self::parse_run_args();

		if ( '' === $args['post_type'] || '' === $args['field_name'] ) {
			wp_send_json_error( array( 'message' => 'Post type and field are required' ) );
		}

		if ( 'selected' === $args['mode'] && empty( $args['post_ids'] ) ) {
			wp_send_json_error( array( 'message' => 'Enter at least one post ID' ) );
		}

		$preview = ABPU_Updater::preview(
			$args['post_type'],
			$args['field_name'],
			$args['mode'],
			$args['post_ids']
		);

		// Do not return full ID list to client on large sets.
		unset( $preview['post_ids'] );

		wp_send_json_success( $preview );
	}

	/**
	 * AJAX: start batch.
	 */
	public static function handle_start_batch() {
		self::guard();
		$args = self::parse_run_args();

		if ( '' === $args['post_type'] || '' === $args['field_name'] ) {
			wp_send_json_error( array( 'message' => 'Post type and field are required' ) );
		}

		if ( 0 === $args['amount'] ) {
			wp_send_json_error( array( 'message' => 'Amount cannot be zero' ) );
		}

		if ( 'selected' === $args['mode'] && empty( $args['post_ids'] ) ) {
			wp_send_json_error( array( 'message' => 'Enter at least one post ID' ) );
		}

		if ( ABPU_DB::has_running_batch() ) {
			wp_send_json_error( array( 'message' => 'Another batch is already running' ) );
		}

		$preview = ABPU_Updater::preview(
			$args['post_type'],
			$args['field_name'],
			$args['mode'],
			$args['post_ids']
		);

		if ( $preview['parseable'] < 1 ) {
			wp_send_json_error( array( 'message' => 'No posts with parseable numeric values' ) );
		}

		$post_ids = $preview['post_ids'];
		$batch_id = ABPU_DB::create_batch(
			array(
				'post_type'  => $args['post_type'],
				'field_name' => $args['field_name'],
				'amount'     => $args['amount'],
				'mode'       => $args['mode'],
				'total'      => count( $post_ids ),
				'user_id'    => get_current_user_id(),
			)
		);

		if ( ! $batch_id ) {
			wp_send_json_error( array( 'message' => 'Failed to create batch' ) );
		}

		ABPU_DB::insert_pending_items( $batch_id, $post_ids );

		wp_send_json_success(
			array(
				'batch_id'   => $batch_id,
				'total'      => count( $post_ids ),
				'chunk_size' => $args['chunk_size'],
			)
		);
	}

	/**
	 * AJAX: process one chunk.
	 */
	public static function handle_process_chunk() {
		self::guard();

		$batch_id   = isset( $_POST['batch_id'] ) ? absint( $_POST['batch_id'] ) : 0;
		$chunk_size = isset( $_POST['chunk_size'] ) ? max( 1, min( 200, (int) $_POST['chunk_size'] ) ) : 50;

		$batch = ABPU_DB::get_batch( $batch_id );
		if ( ! $batch ) {
			wp_send_json_error( array( 'message' => 'Batch not found' ) );
		}
		if ( 'running' !== $batch->status ) {
			wp_send_json_error( array( 'message' => 'Batch is not running' ) );
		}

		$items = ABPU_DB::get_pending_items( $batch_id, $chunk_size );
		foreach ( $items as $item ) {
			ABPU_Updater::process_item( $item, $batch->field_name, (int) $batch->amount );
		}

		$pending = ABPU_DB::count_items_by_status( $batch_id, 'pending' );
		$done    = (int) $batch->total - $pending;

		$data = array(
			'processed' => $done,
			'total'     => (int) $batch->total,
			'done'      => $pending < 1,
		);

		ABPU_DB::update_batch(
			$batch_id,
			array(
				'processed' => $done,
			)
		);

		if ( $pending < 1 ) {
			ABPU_DB::update_batch(
				$batch_id,
				array(
					'status'      => 'completed',
					'processed'   => (int) $batch->total,
					'finished_at' => current_time( 'mysql' ),
				)
			);
			$data['status'] = 'completed';
		}

		wp_send_json_success( $data );
	}

	/**
	 * AJAX: rollback chunk.
	 */
	public static function handle_rollback_chunk() {
		self::guard();

		$batch_id   = isset( $_POST['batch_id'] ) ? absint( $_POST['batch_id'] ) : 0;
		$chunk_size = isset( $_POST['chunk_size'] ) ? max( 1, min( 200, (int) $_POST['chunk_size'] ) ) : 50;
		$is_start   = ! empty( $_POST['start'] );

		$batch = ABPU_DB::get_batch( $batch_id );
		if ( ! $batch ) {
			wp_send_json_error( array( 'message' => 'Batch not found' ) );
		}

		if ( $is_start ) {
			if ( 'completed' !== $batch->status ) {
				wp_send_json_error( array( 'message' => 'Only completed batches can be rolled back' ) );
			}
			if ( ABPU_DB::has_running_batch() ) {
				wp_send_json_error( array( 'message' => 'Another batch is already running' ) );
			}
			ABPU_DB::update_batch( $batch_id, array( 'status' => 'running' ) );
			$batch = ABPU_DB::get_batch( $batch_id );
		}

		if ( 'running' !== $batch->status && 'completed' !== $batch->status ) {
			wp_send_json_error( array( 'message' => 'Batch cannot be rolled back' ) );
		}

		$items = ABPU_DB::get_updated_items( $batch_id, $chunk_size );
		foreach ( $items as $item ) {
			ABPU_Updater::rollback_item( $item, $batch->field_name );
		}

		$remaining = ABPU_DB::count_items_by_status( $batch_id, 'updated' );
		$rolled    = ABPU_DB::count_items_by_status( $batch_id, 'rolled_back' );

		$data = array(
			'processed' => $rolled,
			'total'     => max( $rolled + $remaining, 1 ),
			'done'      => $remaining < 1,
		);

		if ( $remaining < 1 ) {
			ABPU_DB::update_batch(
				$batch_id,
				array(
					'status'      => 'rolled_back',
					'finished_at' => current_time( 'mysql' ),
				)
			);
			$data['status'] = 'rolled_back';
		}

		wp_send_json_success( $data );
	}

	/**
	 * AJAX: list batches.
	 */
	public static function handle_list_batches() {
		self::guard();
		$page = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
		$result = ABPU_DB::list_batches( $page, 20 );
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: list batch items.
	 */
	public static function handle_list_batch_items() {
		self::guard();
		$batch_id = isset( $_POST['batch_id'] ) ? absint( $_POST['batch_id'] ) : 0;
		$page     = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
		$result   = ABPU_DB::list_batch_items( $batch_id, $page, 50 );
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: delete batch history.
	 */
	public static function handle_delete_batch() {
		self::guard();
		$batch_id = isset( $_POST['batch_id'] ) ? absint( $_POST['batch_id'] ) : 0;
		$batch    = ABPU_DB::get_batch( $batch_id );

		if ( ! $batch ) {
			wp_send_json_error( array( 'message' => 'Batch not found' ) );
		}
		if ( 'running' === $batch->status ) {
			wp_send_json_error( array( 'message' => 'Cannot delete a running batch' ) );
		}

		ABPU_DB::delete_batch( $batch_id );
		wp_send_json_success( array( 'deleted' => true ) );
	}
}
