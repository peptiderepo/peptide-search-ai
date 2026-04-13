<?php
/**
 * Admin settings page for Peptide Search AI.
 *
 * What: Registers settings page, fields, sanitization, usage dashboard, migration tools.
 * Who calls it: WordPress admin_menu and admin_init hooks via PSA_Admin::init().
 * Dependencies: PSA_Encryption (encrypt API keys), PSA_Cost_Tracker (usage display), PSA_Config, PSA_Post_Type.
 *
 * @package PeptideSearchAI
 * @see     includes/class-psa-encryption.php
 * @see     includes/class-psa-cost-tracker.php
 * @see     includes/class-psa-post-type.php
 */
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PSA_Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_pages' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_admin_actions' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_batch_scripts' ) );
	}

	/**
	 * Add settings page under Settings menu.
	 */
	public static function add_menu_pages(): void {
		add_options_page(
			__( 'Peptide Search AI', 'peptide-search-ai' ),
			__( 'Peptide Search AI', 'peptide-search-ai' ),
			'manage_options',
			'peptide-search-ai',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Register settings fields.
	 */
	public static function register_settings(): void {
		register_setting(
			'psa_settings_group',
			'psa_settings',
			array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
			)
		);

		// OpenRouter Section.
		add_settings_section( 'psa_ai_section', __( 'OpenRouter API Settings', 'peptide-search-ai' ), array( __CLASS__, 'render_section_description' ), 'peptide-search-ai' );
		add_settings_field( 'api_key', __( 'API Key', 'peptide-search-ai' ), array( __CLASS__, 'render_api_key_field' ), 'peptide-search-ai', 'psa_ai_section' );
		add_settings_field( 'ai_model', __( 'Generation Model', 'peptide-search-ai' ), array( __CLASS__, 'render_model_field' ), 'peptide-search-ai', 'psa_ai_section' );
		add_settings_field( 'validation_model', __( 'Validation Model', 'peptide-search-ai' ), array( __CLASS__, 'render_validation_model_field' ), 'peptide-search-ai', 'psa_ai_section' );

		// Behavior Section.
		add_settings_section( 'psa_behavior_section', __( 'Behavior Settings', 'peptide-search-ai' ), null, 'peptide-search-ai' );
		add_settings_field( 'auto_publish', __( 'Auto-Publish', 'peptide-search-ai' ), array( __CLASS__, 'render_publish_field' ), 'peptide-search-ai', 'psa_behavior_section' );
		add_settings_field( 'use_pubchem', __( 'PubChem Enrichment', 'peptide-search-ai' ), array( __CLASS__, 'render_pubchem_field' ), 'peptide-search-ai', 'psa_behavior_section' );
		add_settings_field( 'monthly_budget', __( 'Monthly API Budget (USD)', 'peptide-search-ai' ), array( __CLASS__, 'render_budget_field' ), 'peptide-search-ai', 'psa_behavior_section' );
	}

	/**
	 * Sanitize settings input.
	 *
	 * @param array $input Raw input from the form.
	 * @return array Sanitized settings.
	 */
	public static function sanitize_settings( array $input ): array {
		$api_key_input = $input['api_key'] ?? '';
		$api_key_value = '';

		if ( ! empty( $api_key_input ) && '••••••••' !== $api_key_input ) {
			$sanitized     = sanitize_text_field( $api_key_input );
			$encrypted     = PSA_Encryption::encrypt( $sanitized );
			$api_key_value = ( false !== $encrypted ) ? $encrypted : '';
		} else {
			$existing      = self::get_setting( 'api_key', '' );
			$api_key_value = $existing;
		}

		return array(
			'api_key'            => $api_key_value,
			'ai_model'           => sanitize_text_field( $input['ai_model'] ?? '' ),
			'validation_model'   => sanitize_text_field( $input['validation_model'] ?? '' ),
			'auto_publish'       => sanitize_text_field( $input['auto_publish'] ?? 'draft' ),
			'use_pubchem'        => ! empty( $input['use_pubchem'] ) ? '1' : '0',
			'monthly_budget'     => max( 0, floatval( $input['monthly_budget'] ?? PSA_Config::DEFAULT_MONTHLY_BUDGET ) ),
		);
	}

	/**
	 * Handle admin actions (migration, re-enrich) via GET params with nonce verification.
	 */
	public static function handle_admin_actions(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['psa_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_GET['psa_action'] ) );
		$nonce  = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( 'migrate_categories' === $action && wp_verify_nonce( $nonce, 'psa_migrate_categories' ) ) {
			self::run_category_migration();
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Category migration completed.', 'peptide-search-ai' ) . '</p></div>';
				}
			);
		}

		if ( 'reenrich' === $action && wp_verify_nonce( $nonce, 'psa_reenrich' ) ) {
			$queued = self::queue_reenrichment();
			add_action(
				'admin_notices',
				function () use ( $queued ) {
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
						sprintf(
							/* translators: %d = number of peptides queued */
							__( '%d peptides queued for re-enrichment.', 'peptide-search-ai' ),
							$queued
						)
					) . '</p></div>';
				}
			);
		}
	}

	// -------------------------------------------------------------------------
	// Migration: assign categories to existing peptides
	// -------------------------------------------------------------------------

	/**
	 * One-time migration: assign peptide_category terms to existing peptide posts.
	 *
	 * Reads the free-text psa_category meta from each published peptide and maps
	 * it to a taxonomy term. Safe to run multiple times (idempotent).
	 */
	private static function run_category_migration(): void {
		$posts = get_posts(
			array(
				'post_type'      => 'peptide',
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $posts as $post_id ) {
			// Skip posts that already have a category term assigned.
			$terms = wp_get_object_terms( $post_id, 'peptide_category' );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				continue;
			}

			$category = get_post_meta( $post_id, 'psa_category', true );
			$label    = get_post_meta( $post_id, 'psa_category_label', true );
			$text     = ! empty( $category ) ? $category : $label;

			if ( empty( $text ) ) {
				continue;
			}

			// Use the same mapping logic as the AI generator.
			$slug = self::map_category_to_slug( $text );
			if ( ! empty( $slug ) ) {
				wp_set_object_terms( $post_id, $slug, 'peptide_category' );
			}
		}
	}

	/**
	 * Map free-text category label to taxonomy slug.
	 *
	 * @param string $text Category label from AI or meta.
	 * @return string Taxonomy term slug, or empty if no match.
	 */
	private static function map_category_to_slug( string $text ): string {
		$map = array(
			'gh secretagogues'       => 'growth-hormone',
			'growth hormone'         => 'growth-hormone',
			'healing & repair'       => 'tissue-repair',
			'healing and repair'     => 'tissue-repair',
			'tissue repair'          => 'tissue-repair',
			'tissue healing'         => 'tissue-repair',
			'cytoprotective'         => 'tissue-repair',
			'melanocortin peptides'  => 'dermatological',
			'melanocortin'           => 'dermatological',
			'dermatological'         => 'dermatological',
			'metabolic & anti-aging' => 'metabolic',
			'metabolic'              => 'metabolic',
			'anti-aging'             => 'aging-research',
			'aging research'         => 'aging-research',
			'immunology'             => 'immunology',
			'immune'                 => 'immunology',
			'nootropic'              => 'immunology',
			'neuroprotective'        => 'immunology',
			'lipid metabolism'       => 'lipid-metabolism',
			'endocrine'              => 'endocrine',
		);

		$lower = strtolower( trim( $text ) );
		if ( isset( $map[ $lower ] ) ) {
			return $map[ $lower ];
		}

		// Fuzzy match.
		foreach ( PSA_Post_Type::DEFAULT_CATEGORIES as $slug => $name ) {
			if ( stripos( $text, $name ) !== false || stripos( $text, str_replace( '-', ' ', $slug ) ) !== false ) {
				return $slug;
			}
		}

		return '';
	}

	// -------------------------------------------------------------------------
	// Re-enrichment: queue background regeneration for posts missing new fields
	// -------------------------------------------------------------------------

	/**
	 * Queue re-enrichment for published peptides missing the new extended fields.
	 *
	 * Schedules WP-Cron events for each peptide that needs regeneration.
	 * Respects the daily generation cap to avoid cost overruns.
	 *
	 * @return int Number of peptides queued.
	 */
	private static function queue_reenrichment(): int {
		$posts = get_posts(
			array(
				'post_type'      => 'peptide',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				// Only select posts missing the half_life field (proxy for "needs extended data").
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'     => 'psa_half_life',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => 'psa_half_life',
						'value' => '',
					),
				),
			)
		);

		$daily_key   = 'psa_daily_gen_' . gmdate( 'Y-m-d' );
		$daily_count = (int) get_transient( $daily_key );
		$queued      = 0;

		foreach ( $posts as $post_id ) {
			// Respect daily cap.
			if ( ( $daily_count + $queued ) >= PSA_Config::DAILY_GENERATION_CAP ) {
				break;
			}

			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}

			// Mark as pending so the generator picks it up, then schedule.
			update_post_meta( $post_id, 'psa_source', 'pending' );
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'draft',
				)
			);
			update_post_meta( $post_id, 'psa_generation_started', time() );

			$args = array( $post_id, $post->post_title );
			if ( ! wp_next_scheduled( 'psa_generate_peptide_background', $args ) ) {
				// Stagger jobs 30 seconds apart to avoid rate limit hammering.
				wp_schedule_single_event( time() + ( $queued * 30 ), 'psa_generate_peptide_background', $args );
			}

			$queued++;
		}

		// Bump daily counter.
		set_transient( $daily_key, $daily_count + $queued, DAY_IN_SECONDS );

		// Kick off the cron runner.
		if ( $queued > 0 ) {
			spawn_cron();
		}

		return $queued;
	}

	/**
	 * Enqueue batch enrichment script on the PSA settings page only.
	 *
	 * @param string $hook The admin page hook suffix.
	 */
	public static function enqueue_batch_scripts( string $hook ): void {
		if ( 'settings_page_peptide-search-ai' !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'psa-batch-enrich',
			PSA_PLUGIN_URL . 'assets/js/psa-batch-enrich.js',
			array( 'jquery' ),
			PSA_VERSION,
			true
		);

		wp_localize_script(
			'psa-batch-enrich',
			'psaBatchEnrich',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'psa_batch_enrich' ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Field renderers
	// -------------------------------------------------------------------------

	public static function render_section_description(): void {
		?>
		<p>
			<?php
			printf(
				/* translators: %s = OpenRouter URL */
				__( 'This plugin uses <a href="%s" target="_blank" rel="noopener">OpenRouter</a> to access AI models.', 'peptide-search-ai' ),
				esc_url( 'https://openrouter.ai/' )
			);
			?>
		</p>
		<?php
	}

	public static function render_api_key_field(): void {
		if ( defined( 'PSA_OPENROUTER_KEY' ) && PSA_OPENROUTER_KEY ) {
			?>
			<span class="dashicons dashicons-yes-alt" style="color:#46b450;"></span>
			<strong><?php esc_html_e( 'Configured via wp-config.php', 'peptide-search-ai' ); ?></strong>
			<p class="description"><?php esc_html_e( 'The API key is securely set using the PSA_OPENROUTER_KEY constant.', 'peptide-search-ai' ); ?></p>
			<?php
		} else {
			$value = self::get_setting( 'api_key', '' );
			$display_value = ! empty( $value ) ? '••••••••' : '';
			?>
			<input type="password" name="psa_settings[api_key]" value="<?php echo esc_attr( $display_value ); ?>" class="regular-text" autocomplete="off" />
			<p class="description">
				<?php esc_html_e( 'Your OpenRouter API key.', 'peptide-search-ai' ); ?>
				<a href="https://openrouter.ai/settings/keys" target="_blank" rel="noopener"><?php esc_html_e( 'Get one here', 'peptide-search-ai' ); ?></a>.
			</p>
			<?php
		}
	}

	public static function render_model_field(): void {
		$value = self::get_setting( 'ai_model', '' );
		?>
		<input type="text" name="psa_settings[ai_model]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Leave blank for default', 'peptide-search-ai' ); ?>" />
		<p class="description"><?php esc_html_e( 'Model for full content generation. Default: google/gemini-2.5-flash', 'peptide-search-ai' ); ?></p>
		<?php
	}

	public static function render_validation_model_field(): void {
		$value = self::get_setting( 'validation_model', '' );
		?>
		<input type="text" name="psa_settings[validation_model]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Leave blank for default', 'peptide-search-ai' ); ?>" />
		<p class="description"><?php esc_html_e( 'Lighter model for name validation. Default: google/gemini-2.0-flash-001', 'peptide-search-ai' ); ?></p>
		<?php
	}

	public static function render_publish_field(): void {
		$value = self::get_setting( 'auto_publish', 'draft' );
		?>
		<select name="psa_settings[auto_publish]">
			<option value="draft" <?php selected( $value, 'draft' ); ?>><?php esc_html_e( 'Save as Draft', 'peptide-search-ai' ); ?></option>
			<option value="publish" <?php selected( $value, 'publish' ); ?>><?php esc_html_e( 'Publish Immediately', 'peptide-search-ai' ); ?></option>
		</select>
		<?php
	}

	public static function render_pubchem_field(): void {
		$value = self::get_setting( 'use_pubchem', '1' );
		?>
		<label>
			<input type="checkbox" name="psa_settings[use_pubchem]" value="1" <?php checked( $value, '1' ); ?> />
			<?php esc_html_e( 'Cross-reference with PubChem for verified molecular data', 'peptide-search-ai' ); ?>
		</label>
		<?php
	}

	public static function render_budget_field(): void {
		$value = self::get_setting( 'monthly_budget', PSA_Config::DEFAULT_MONTHLY_BUDGET );
		?>
		<input type="number" name="psa_settings[monthly_budget]" value="<?php echo esc_attr( $value ); ?>" step="0.01" min="0" />
		<p class="description"><?php esc_html_e( 'Set to 0 for unlimited.', 'peptide-search-ai' ); ?></p>
		<?php
	}

	// -------------------------------------------------------------------------
	// Settings page
	// -------------------------------------------------------------------------

	public static function render_settings_page(): void {
		$migrate_url  = wp_nonce_url( admin_url( 'options-general.php?page=peptide-search-ai&psa_action=migrate_categories' ), 'psa_migrate_categories' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Peptide Search AI Settings', 'peptide-search-ai' ); ?></h1>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'psa_settings_group' );
				do_settings_sections( 'peptide-search-ai' );
				submit_button();
				?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'API Usage & Costs', 'peptide-search-ai' ); ?></h2>
			<?php self::render_usage_summary(); ?>

			<hr />
			<h2><?php esc_html_e( 'Data Tools', 'peptide-search-ai' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Assign Categories', 'peptide-search-ai' ); ?></th>
					<td>
						<a href="<?php echo esc_url( $migrate_url ); ?>" class="button">
							<?php esc_html_e( 'Migrate Existing Peptides to Categories', 'peptide-search-ai' ); ?>
						</a>
						<p class="description"><?php esc_html_e( 'Reads AI-generated category data from existing peptides and assigns taxonomy terms. Safe to run multiple times.', 'peptide-search-ai' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Re-enrich Data', 'peptide-search-ai' ); ?></th>
					<td>
						<button type="button" id="psa-batch-enrich-start" class="button button-primary">
							<?php esc_html_e( 'Re-enrich Peptides', 'peptide-search-ai' ); ?>
						</button>
						<span id="psa-batch-enrich-status" style="margin-left:10px;color:#666;"></span>
						<p class="description"><?php esc_html_e( 'Processes one peptide at a time via AI — no WP-Cron dependency. Respects daily generation cap.', 'peptide-search-ai' ); ?></p>

						<div id="psa-batch-enrich-progress" style="display:none;margin-top:10px;max-width:500px;">
							<div style="background:#e0e0e0;border-radius:3px;height:20px;overflow:hidden;">
								<div id="psa-batch-enrich-bar" style="background:#0073aa;height:100%;width:0%;transition:width 0.3s;"></div>
							</div>
							<span id="psa-batch-enrich-pct" style="font-size:12px;color:#666;"></span>
						</div>
						<div id="psa-batch-enrich-log" style="display:none;margin-top:10px;max-height:200px;overflow-y:auto;padding:8px;background:#f9f9f9;border:1px solid #ddd;border-radius:3px;max-width:500px;font-family:monospace;font-size:12px;"></div>
					</td>
				</tr>
			</table>

			<hr />
			<h2><?php esc_html_e( 'Shortcodes', 'peptide-search-ai' ); ?></h2>
			<p><code>[peptide_search]</code> — <?php esc_html_e( 'Search form with auto-generation', 'peptide-search-ai' ); ?></p>
			<p><code>[peptide_directory]</code> — <?php esc_html_e( 'Browsable directory grid with filters and detail modal', 'peptide-search-ai' ); ?></p>

			<hr />
			<h2><?php esc_html_e( 'REST API', 'peptide-search-ai' ); ?></h2>
			<p><code>GET /wp-json/peptides/v1/search?q=BPC-157</code> — <?php esc_html_e( 'Search (published only)', 'peptide-search-ai' ); ?></p>
			<p><code>GET /wp-json/peptide-search-ai/v1/compounds?search=&amp;category=&amp;page=1</code> — <?php esc_html_e( 'Directory listing', 'peptide-search-ai' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render the API usage summary section.
	 */
	private static function render_usage_summary(): void {
		$current_spend  = PSA_Cost_Tracker::get_monthly_spend();
		$current_tokens = PSA_Cost_Tracker::get_monthly_tokens();
		$budget         = floatval( self::get_setting( 'monthly_budget', PSA_Config::DEFAULT_MONTHLY_BUDGET ) );
		$budget_remaining = ( 0.0 === $budget ) ? __( 'Unlimited', 'peptide-search-ai' ) : '$' . number_format( max( 0, $budget - $current_spend ), 2 );
		$recent_logs    = PSA_Cost_Tracker::get_recent_logs( 20 );

		?>
		<div style="background:#f5f5f5;padding:15px;border-radius:4px;margin-bottom:20px;">
			<h3><?php esc_html_e( 'This Month', 'peptide-search-ai' ); ?></h3>
			<p>
				<strong><?php esc_html_e( 'Spend:', 'peptide-search-ai' ); ?></strong> $<?php echo esc_html( number_format( $current_spend, 2 ) ); ?><br />
				<strong><?php esc_html_e( 'Tokens:', 'peptide-search-ai' ); ?></strong> <?php echo esc_html( number_format( $current_tokens ) ); ?><br />
				<strong><?php esc_html_e( 'Budget Remaining:', 'peptide-search-ai' ); ?></strong> <?php echo esc_html( $budget_remaining ); ?>
			</p>
		</div>

		<?php if ( ! empty( $recent_logs ) ) : ?>
			<h3><?php esc_html_e( 'Recent API Calls (Last 20)', 'peptide-search-ai' ); ?></h3>
			<table class="widefat striped" style="margin-bottom:20px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'peptide-search-ai' ); ?></th>
						<th><?php esc_html_e( 'Model', 'peptide-search-ai' ); ?></th>
						<th><?php esc_html_e( 'Tokens', 'peptide-search-ai' ); ?></th>
						<th><?php esc_html_e( 'Cost', 'peptide-search-ai' ); ?></th>
						<th><?php esc_html_e( 'Type', 'peptide-search-ai' ); ?></th>
						<th><?php esc_html_e( 'Peptide', 'peptide-search-ai' ); ?></th>
						<th><?php esc_html_e( 'Status', 'peptide-search-ai' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $recent_logs as $log ) : ?>
						<tr>
							<td><?php echo esc_html( $log->created_at ); ?></td>
							<td><code><?php echo esc_html( $log->model ); ?></code></td>
							<td><?php echo esc_html( number_format( $log->total_tokens ) ); ?></td>
							<td>$<?php echo esc_html( number_format( $log->estimated_cost_usd, 4 ) ); ?></td>
							<td><?php echo esc_html( $log->request_type ); ?></td>
							<td><?php echo esc_html( $log->peptide_name ); ?></td>
							<td><?php echo ( 1 === (int) $log->success ) ? '&#10003;' : '&#10007;'; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * Helper to get a single setting with a default.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	private static function get_setting( string $key, $default = '' ) {
		$options = get_option( 'psa_settings', array() );
		return $options[ $key ] ?? $default;
	}
}
