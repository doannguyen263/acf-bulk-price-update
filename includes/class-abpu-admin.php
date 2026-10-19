<?php
/**
 * Admin menu and page UI.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABPU_Admin {

	/**
	 * Register admin hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade_db' ) );
	}

	/**
	 * Ensure tables exist after plugin update without reactivation.
	 */
	public static function maybe_upgrade_db() {
		$version = get_option( 'abpu_db_version' );
		if ( $version !== ABPU_VERSION ) {
			ABPU_Activator::create_tables();
			update_option( 'abpu_db_version', ABPU_VERSION );
		}
	}

	/**
	 * Top-level admin menu.
	 */
	public static function register_menu() {
		add_menu_page(
			__( 'ACF Bulk Price Update', 'acf-bulk-price-update' ),
			__( 'Bulk Price Update', 'acf-bulk-price-update' ),
			'manage_options',
			'acf-bulk-price-update',
			array( __CLASS__, 'render_page' ),
			'dashicons-tag',
			58
		);
	}

	/**
	 * Enqueue admin assets on plugin page only.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'toplevel_page_acf-bulk-price-update' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'abpu-admin',
			ABPU_PLUGIN_URL . 'assets/admin.css',
			array(),
			ABPU_VERSION
		);

		wp_enqueue_script(
			'abpu-admin',
			ABPU_PLUGIN_URL . 'assets/admin.js',
			array( 'jquery' ),
			ABPU_VERSION,
			true
		);

		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		$types      = array();
		foreach ( $post_types as $pt ) {
			$types[] = array(
				'name'  => $pt->name,
				'label' => $pt->labels->singular_name . ' (' . $pt->name . ')',
			);
		}

		wp_localize_script(
			'abpu-admin',
			'ABPU',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'abpu_admin' ),
				'postTypes'    => $types,
				'defaultType'  => post_type_exists( 'thucdon' ) ? 'thucdon' : ( ! empty( $types[0]['name'] ) ? $types[0]['name'] : 'post' ),
				'defaultField' => 'regular_price',
				'i18n'         => array(
					'confirmRun'      => __( 'Start bulk price update? This cannot be undone without rollback.', 'acf-bulk-price-update' ),
					'confirmRollback' => __( 'Rollback this batch to previous prices?', 'acf-bulk-price-update' ),
					'confirmDelete'   => __( 'Delete this batch history? Prices will not change.', 'acf-bulk-price-update' ),
					'previewFirst'    => __( 'Run Preview first.', 'acf-bulk-price-update' ),
					'running'         => __( 'Running…', 'acf-bulk-price-update' ),
					'done'            => __( 'Done.', 'acf-bulk-price-update' ),
					'error'           => __( 'Error', 'acf-bulk-price-update' ),
				),
			)
		);
	}

	/**
	 * Render admin page shell (JS fills dynamic parts).
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap abpu-wrap">
			<h1><?php echo esc_html__( 'ACF Bulk Price Update', 'acf-bulk-price-update' ); ?></h1>

			<div class="abpu-card">
				<h2><?php echo esc_html__( 'New update', 'acf-bulk-price-update' ); ?></h2>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="abpu-post-type"><?php echo esc_html__( 'Post type', 'acf-bulk-price-update' ); ?></label></th>
						<td><select id="abpu-post-type"></select></td>
					</tr>
					<tr>
						<th scope="row"><label for="abpu-field"><?php echo esc_html__( 'Field', 'acf-bulk-price-update' ); ?></label></th>
						<td>
							<select id="abpu-field"></select>
							<p class="description"><?php echo esc_html__( 'Select from ACF text/number fields or existing meta keys.', 'acf-bulk-price-update' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="abpu-amount"><?php echo esc_html__( 'Amount (+/-)', 'acf-bulk-price-update' ); ?></label></th>
						<td><input type="number" id="abpu-amount" value="5000" step="1" /></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Scope', 'acf-bulk-price-update' ); ?></th>
						<td>
							<label><input type="radio" name="abpu-mode" value="all" checked /> <?php echo esc_html__( 'All published posts of this type', 'acf-bulk-price-update' ); ?></label><br />
							<label><input type="radio" name="abpu-mode" value="selected" /> <?php echo esc_html__( 'Specific post IDs', 'acf-bulk-price-update' ); ?></label>
							<textarea id="abpu-post-ids" class="large-text code" rows="3" placeholder="12, 34, 56" style="display:none;margin-top:8px;"></textarea>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="abpu-chunk"><?php echo esc_html__( 'Chunk size', 'acf-bulk-price-update' ); ?></label></th>
						<td><input type="number" id="abpu-chunk" value="50" min="1" max="200" step="1" /></td>
					</tr>
				</table>

				<p class="abpu-actions">
					<button type="button" class="button" id="abpu-preview-btn"><?php echo esc_html__( 'Preview', 'acf-bulk-price-update' ); ?></button>
					<button type="button" class="button button-primary" id="abpu-run-btn" disabled><?php echo esc_html__( 'Run update', 'acf-bulk-price-update' ); ?></button>
				</p>

				<div id="abpu-preview-result" class="abpu-preview" style="display:none;"></div>

				<div id="abpu-progress" class="abpu-progress" style="display:none;">
					<div class="abpu-progress-bar"><span id="abpu-progress-fill"></span></div>
					<p id="abpu-progress-text"></p>
					<pre id="abpu-log" class="abpu-log"></pre>
				</div>
			</div>

			<div class="abpu-card">
				<h2><?php echo esc_html__( 'Batch history', 'acf-bulk-price-update' ); ?></h2>
				<div id="abpu-history"></div>
				<div id="abpu-history-detail" style="display:none;margin-top:16px;"></div>
			</div>
		</div>
		<?php
	}
}
