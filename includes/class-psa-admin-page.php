<?php
/**
 * Admin page rendering for Peptide Search AI settings.
 *
 * What: Settings page HTML, field renderers, usage summary, batch enrich scripts.
 * Who calls it: PSA_Admin::add_menu_pages() registers render_settings_page as page callback.
 * Dependencies: PSA_Config, PSA_Cost_Tracker, PSA_Encryption.
 *
 * @package PeptideSearchAI
 * @since   2.6.0
 * @see     includes/class-psa-admin.php — Registration, sanitization, migration tools.
 */
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PSA_Admin_Page {

	/**
	 * Enqueue batch enrichment script on the PSA settings page only.
	 *
	 * @param string $hook Admin page hook suffix.
	 */
	public static function enqueue_batch_scripts( string $hook ): void {
		if ( 'settings_page_peptide-search-ai' !== $hook ) {
			return;
		}

		wp_enqueue_script( 'psa-batch-enrich', PSA_PLUGIN_URL . 'assets/js/psa-batch-enrich.js', array( 'jquery' ), PSA_VERSION, true );
		wp_localize_script(
			'psa-batch-enrich',
			'psaBatchEnrich',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'psa_batch_enrich' ),
			)
		);
	}

	// ── Field renderers ─────────────────────────────────────────────────

	public static function render_section_description(): void {
		printf(
			'<p>' . __( 'This plugin uses <a href="%s" target="_blank" rel="noopener">OpenRouter</a> to access AI models.', 'peptide-search-ai' ) . '</p>',
			esc_url( 'https://openrouter.ai/' )
		);
	}

	public static function render_api_key_field(): void {
		if ( defined( 'PSA_OPENROUTER_KEY' ) && PSA_OPENROUTER_KEY ) {
			echo '<span class="dashicons dashicons-yes-alt" style="color:#46b450;"></span> ';
			echo '<strong>' . esc_html__( 'Configured via wp-config.php', 'peptide-search-ai' ) . '</strong>';
			echo '<p class="description">' . esc_html__( 'The API key is securely set using the PSA_OPENROUTER_KEY constant.', 'peptide-search-ai' ) . '</p>';
		} else {
			$value         = PSA_Admin::get_setting( 'api_key', '' );
			$display_value = ! empty( $value ) ? '••••••••' : '';
			printf(
				'<input type="password" name="psa_settings[api_key]" value="%s" class="regular-text" autocomplete="off" />',
				esc_attr( $display_value )
			);
			printf(
				'<p class="description">%s <a href="https://openrouter.ai/settings/keys" target="_blank" rel="noopener">%s</a>.</p>',
				esc_html__( 'Your OpenRouter API key.', 'peptide-search-ai' ),
				esc_html__( 'Get one here', 'peptide-search-ai' )
			);
		}
	}

	public static function render_model_field(): void {
		$value = PSA_Admin::get_setting( 'ai_model', '' );
		printf(
			'<input type="text" name="psa_settings[ai_model]" value="%s" class="regular-text" placeholder="%s" />',
			esc_attr( $value ),
			esc_attr__( 'Leave blank for default', 'peptide-search-ai' )
		);
		echo '<p class="description">' . esc_html__( 'Model for full content generation. Default: google/gemini-2.5-flash', 'peptide-search-ai' ) . '</p>';
	}

	public static function render_validation_model_field(): void {
		$value = PSA_Admin::get_setting( 'validation_model', '' );
		printf(
			'<input type="text" name="psa_settings[validation_model]" value="%s" class="regular-text" placeholder="%s" />',
			esc_attr( $value ),
			esc_attr__( 'Leave blank for default', 'peptide-search-ai' )
		);
		echo '<p class="description">' . esc_html__( 'Lighter model for name validation. Default: google/gemini-2.0-flash-001', 'peptide-search-ai' ) . '</p>';
	}

	public static function render_publish_field(): void {
		$value = PSA_Admin::get_setting( 'auto_publish', 'draft' );
		echo '<select name="psa_settings[auto_publish]">';
		printf( '<option value="draft" %s>%s</option>', selected( $value, 'draft', false ), esc_html__( 'Save as Draft', 'peptide-search-ai' ) );
		printf( '<option value="publish" %s>%s</option>', selected( $value, 'publish', false ), esc_html__( 'Publish Immediately', 'peptide-search-ai' ) );
		echo '</select>';
	}

	public static function render_pubchem_field(): void {
		$value = PSA_Admin::get_setting( 'use_pubchem', '1' );
		printf(
			'<label><input type="checkbox" name="psa_settings[use_pubchem]" value="1" %s /> %s</label>',
			checked( $value, '1', false ),
			esc_html__( 'Cross-reference with PubChem for verified molecular data', 'peptide-search-ai' )
		);
	}

	public static function render_budget_field(): void {
		$value = PSA_Admin::get_setting( 'monthly_budget', PSA_Config::DEFAULT_MONTHLY_BUDGET );
		printf(
			'<input type="number" name="psa_settings[monthly_budget]" value="%s" step="0.01" min="0" />',
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'Set to 0 for unlimited.', 'peptide-search-ai' ) . '</p>';
	}

	// ── Settings page ───────────────────────────────────────────────────

	public static function render_settings_page(): void {
		$migrate_url = wp_nonce_url( admin_url( 'options-general.php?page=peptide-search-ai&psa_action=migrate_categories' ), 'psa_migrate_categories' );
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
						<a href="<?php echo esc_url( $migrate_url ); ?>" class="button"><?php esc_html_e( 'Migrate Existing Peptides to Categories', 'peptide-search-ai' ); ?></a>
						<p class="description"><?php esc_html_e( 'Reads AI-generated category data from existing peptides and assigns taxonomy terms. Safe to run multiple times.', 'peptide-search-ai' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Re-enrich Data', 'peptide-search-ai' ); ?></th>
					<td>
						<button type="button" id="psa-batch-enrich-start" class="button button-primary"><?php esc_html_e( 'Re-enrich Peptides', 'peptide-search-ai' ); ?></button>
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

	/** Render the API usage summary section with recent call log. */
	private static function render_usage_summary(): void {
		$current_spend    = PSA_Cost_Tracker::get_monthly_spend();
		$current_tokens   = PSA_Cost_Tracker::get_monthly_tokens();
		$estimated_count  = PSA_Cost_Tracker::get_monthly_estimated_count();
		$budget           = floatval( PSA_Admin::get_setting( 'monthly_budget', PSA_Config::DEFAULT_MONTHLY_BUDGET ) );
		$budget_remaining = ( 0.0 === $budget ) ? __( 'Unlimited', 'peptide-search-ai' ) : '$' . number_format( max( 0, $budget - $current_spend ), 2 );
		$recent_logs      = PSA_Cost_Tracker::get_recent_logs( 20 );
		?>
		<div style="background:#f5f5f5;padding:15px;border-radius:4px;margin-bottom:20px;">
			<h3><?php esc_html_e( 'This Month', 'peptide-search-ai' ); ?></h3>
			<p>
				<strong><?php esc_html_e( 'Spend:', 'peptide-search-ai' ); ?></strong> $<?php echo esc_html( number_format( $current_spend, 2 ) ); ?>
				<?php if ( $estimated_count > 0 ) : ?>
					<span style="color:#b45309;font-size:12px;" title="<?php echo esc_attr( $estimated_count ); ?> API calls used character-based token estimates">&#9888; includes estimates</span>
				<?php endif; ?>
				<br />
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
						<?php $is_estimated = ( isset( $log->token_source ) && 'estimated' === $log->token_source ); ?>
						<tr<?php echo $is_estimated ? ' style="color:#92400e;"' : ''; ?>>
							<td><?php echo esc_html( $log->created_at ); ?></td>
							<td><code><?php echo esc_html( $log->model ); ?></code></td>
							<td><?php echo $is_estimated ? '<span title="Estimated">~' : ''; ?><?php echo esc_html( number_format( $log->total_tokens ) ); ?><?php echo $is_estimated ? '</span>' : ''; ?></td>
							<td><?php echo $is_estimated ? '<span title="Estimated">~$' : '$'; ?><?php echo esc_html( number_format( $log->estimated_cost_usd, 4 ) ); ?><?php echo $is_estimated ? '</span>' : ''; ?></td>
							<td><?php echo esc_html( $log->request_type ); ?></td>
							<td><?php echo esc_html( $log->peptide_name ); ?></td>
							<td><?php echo ( 1 === (int) $log->success ) ? '&#10003;' : '&#10007;'; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		endif;
	}
}
