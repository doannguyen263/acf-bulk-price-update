<?php
/**
 * Plugin activation: create snapshot tables.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABPU_Activator {

	/**
	 * Create DB tables on activation.
	 */
	public static function activate() {
		self::create_tables();
		update_option( 'abpu_db_version', ABPU_VERSION );
	}

	/**
	 * Create or update plugin tables via dbDelta.
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$batches         = $wpdb->prefix . 'abpu_batches';
		$items           = $wpdb->prefix . 'abpu_batch_items';

		$sql_batches = "CREATE TABLE {$batches} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_type varchar(64) NOT NULL DEFAULT '',
			field_name varchar(191) NOT NULL DEFAULT '',
			amount bigint(20) NOT NULL DEFAULT 0,
			mode varchar(20) NOT NULL DEFAULT 'all',
			status varchar(20) NOT NULL DEFAULT 'running',
			total int(11) unsigned NOT NULL DEFAULT 0,
			processed int(11) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			finished_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset_collate};";

		$sql_items = "CREATE TABLE {$items} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			batch_id bigint(20) unsigned NOT NULL,
			post_id bigint(20) unsigned NOT NULL,
			old_value varchar(64) DEFAULT NULL,
			new_value varchar(64) DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			error_message text,
			PRIMARY KEY  (id),
			KEY batch_id (batch_id),
			KEY batch_status (batch_id, status),
			KEY post_id (post_id)
		) {$charset_collate};";

		dbDelta( $sql_batches );
		dbDelta( $sql_items );
	}
}
