<?php
/**
 * Admin settings page for Peptide Search AI.
 * Configured for OpenRouter API access.
 *
 * @package PeptideSearchAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PSA_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_pages' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Add settings page under Settings menu.
	 */
	public static function add_menu_pages() {
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
	public static function register_settings() {
		register_setting(
			'psa_settings_group',
			'psa_settings',
			array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
			)
		);

		// OpenRouter Section.
		add_settings_section(
			'psa_ai_section',
			__( 'OpenRouter API Settings', 'peptide-search-ai' ),
			array( __CLASS__, 'render_section_description' ),
			'peptide-search-ai'
		);
		add_settings_field(
			'api_key',
			__( 'API Key', 'peptide-search-ai' ),
			array( __CLASS__, 'render_api_key_field' ),
			'peptide-search-ai',
			'psa_ai_section'
		);
		add_settings_field(
			'ai_model',
			__( 'Generation Model', 'peptide-search-ai' ),
			array( __CLASS__, 'render_model_field' ),
			'peptide-search-ai',
			'psa_ai_section'
		);
		add_settings_field(
			'validation_model',
			__( 'Validation Model', 'peptide-search-ai' ),
			array( __CLASS__, 'render_validation_model_field' ),
			'peptide-search-ai',
			'psa_ai_section'
		);

		// Behavior Section.
		add_settings_section(
			'psa_behavior_section',
			__( 'Behavior Settings', 'peptide-search-ai' ),
			null,
			'peptide-search-ai'
		);
		add_settings_field(
			'auto_publish',
			__( 'Auto-Publish', 'peptide-search-ai' ),
			array( __CLASS__, 'render_publish_field' ),
			'peptide-search-ai',
			'psa_behavior_section'
		);
		add_settings_field(
			'use_pubchem',
			__( 'PubChem Enrichment', 'peptide-search-ai' ),
			array( __CLASS__, 'render_pubchem_field' ),
			'peptide-search-ai',
			'psa_behavior_section'
		);
		add_settings_field(
			'monthly_budget',
			__( 'Monthly API Budget (USD)', 'peptide-search-ai' ),
			array( __CLASS__, 'render_budget_field' ),
			'peptide-search-ai',
			'psa_behavior_section'
		);
	}

	/**
	 * Sanitize settings input.
	 *
	 * @param array $input Raw input from the form.
	 * @return array Sanitized settings.
	 */
	public static function sanitize_settings( $input ) {
		// Handle API key: encrypt new values, preserve existing on empty/mask.
		$api_key_input = $input['api_key'] ?? '';
		$api_key_value = '';

		if ( ! empty( $api_key_input ) && '••••••••' !== $api_key_input ) {
			// New key provided: encrypt it.
			$sanitized = sanitize_text_field( $api_key_input );
			$encrypted = PSA_Encryption::encrypt( $sanitized );
			$api_key_value = ( false !== $encrypted ) ? $encrypted : '';
		} else {
			// Empty or mask string: preserve existing value.
			$existing = self::get_setting( 'api_key', '' );
			$api_key_value = $existing;
		}

		return array(
			'api_key'          => $api_key_value,
			'ai_model'         => sanitize_text_field( $input['ai_model'] ?? '' ),
			'validation_model' => sanitize_text_field( $input['validation_model'] ?? '' ),
			'auto_publish'     => sanitize_text_field( $input['auto_publish'] ?? 'draft' ),
			// Checkbox: if key is absent in POST, it was unchecked → '0'.
			'use_pubchem'      => ! empty( $input['use_pubchem'] ) ? '1' : '0',
			'monthly_budget'   => max( 0, floatval( $input['monthly_budget'] ?? PSA_Config::DEFAULT_MONTHLY_BUDGET ) ),
		);
	}

	// -------------------------------------------------------------------------
	// Field renderers
	// -------------------------------------------------------------------------

	public static function render_section_description() {
		?>
		<p>
			<?php
			printf(
				__( 'This plugin uses <a href="%s" target="_blank" rel="noopener">OpenRouter</a> to access AI models. OpenRouter provides a unified API for hundreds of models including open-source options like Gemini Flash, Llama, Mistral, Qwen, and DeepSeek, as well as commercial models.', 'peptide-search-ai' ),
				esc_url( 'https://openrouter.ai/' )
			);
			?>
		</p>
		<?php
	}

	public static function render_api_key_field() {
		if ( defined( 'PSA_OPENROUTER_KEY' ) && PSA_OPENROUTER_KEY ) {
			?>
			<span class="dashicons dashicons-yes-alt" style="color:#46b450;"></span>
			<strong><?php esc_html_e( 'Configured via wp-config.php', 'peptide-search-ai' ); ?></strong>
			<p class="description"><?php esc_html_e( 'The API key is securely set using the PSA_OPENROUTER_KEY constant in wp-config.php. This is the recommended approach.', 'peptide-search-ai' ); ?></p>
			<?php
		} else {
			$value = self::get_setting( 'api_key', '' );
			// Show mask if key exists, empty if not.
			$display_value = ! empty( $value ) ? '••••••••' : '';
			?>
			<input type="password" name="psa_settings[api_key]" value="<?php echo esc_attr( $display_value ); ?>" class="regular-text" autocomplete="off" />
			<p class="description">
				<?php esc_html_e( 'Your OpenRouter API key. Get one at', 'peptide-search-ai' ); ?>
				<a href="https://openrouter.ai/settings/keys" target="_blank" rel="noopener">openrouter.ai/settings/keys</a>.<br />
				<strong><?php esc_html_e( 'Recommended:', 'peptide-search-ai' ); ?></strong> <?php esc_html_e( 'For better security, add', 'peptide-search-ai' ); ?> <code>define( 'PSA_OPENROUTER_KEY', 'your-key-here' );</code> <?php esc_html_e( 'to your wp-config.php instead.', 'peptide-search-ai' ); ?>
			</p>
			<?php
		}
	}

	public static function render_model_field() {
		$value = self::get_setting( 'ai_model', '' );
		?>
		<input type="text" name="psa_settings[ai_model]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Leave blank for default', 'peptide-search-ai' ); ?>" />
		<p class="description">
			<?php esc_html_e( 'Model for full content generation. Default:', 'peptide-search-ai' ); ?> <code>google/gemini-2.5-flash</code>.<br />
			<?php esc_html_e( 'Browse models at', 'peptide-search-ai' ); ?> <a href="https://openrouter.ai/models" target="_blank" rel="noopener">openrouter.ai/models</a>.
			<?php esc_html_e( 'Examples:', 'peptide-search-ai' ); ?> <code>meta-llama/llama-4-maverick</code>, <code>deepseek/deepseek-chat-v3-0324</code>, <code>qwen/qwen-2.5-72b-instruct</code>.
		</p>
		<?php
	}

	public static function render_validation_model_field() {
		$value = self::get_setting( 'validation_model', '' );
		?>
		<input type="text" name="psa_settings[validation_model]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Leave blank for default (fast/cheap model)', 'peptide-search-ai' ); ?>" />
		<p class="description">
			<?php esc_html_e( 'Lighter model for peptide name validation. Default:', 'peptide-search-ai' ); ?> <code>google/gemini-2.0-flash-001</code>.<br />
			<?php esc_html_e( 'Use a fast, cheap model here to keep validation quick. Examples:', 'peptide-search-ai' ); ?> <code>meta-llama/llama-4-scout</code>, <code>mistralai/mistral-small-3.1-24b-instruct</code>.
		</p>
		<?php
	}

	public static function render_publish_field() {
		$value = self::get_setting( 'auto_publish', 'draft' );
		?>
		<select name="psa_settings[auto_publish]">
			<option value="draft" <?php selected( $value, 'draft' ); ?>><?php esc_html_e( 'Save as Draft (review before publishing)', 'peptide-search-ai' ); ?></option>
			<option value="publish" <?php selected( $value, 'publish' ); ?>><?php esc_html_e( 'Publish Immediately', 'peptide-search-ai' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'Whether AI-generated peptides are published immediately or saved as drafts for review.', 'peptide-search-ai' ); ?></p>
		<?php
	}

	public static function render_pubchem_field() {
		$value = self::get_setting( 'use_pubchem', '1' );
		?>
		<label>
			<input type="checkbox" name="psa_settings[use_pubchem]" value="1" <?php checked( $value, '1' ); ?> />
			<?php esc_html_e( 'Cross-reference with PubChem for verified molecular data', 'peptide-search-ai' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'When enabled, the plugin queries PubChem\'s free API to validate molecular weight, formula, and other properties.', 'peptide-search-ai' ); ?></p>
		<?php
	}

	public static function render_budget_field() {
		$value = self::get_setting( 'monthly_budget', PSA_Config::DEFAULT_MONTHLY_BUDGET );
		?>
		<input type="number" name="psa_settings[monthly_budget]" value="<?php echo esc_attr( $value ); ?>" step="0.01" min="0" />
		<p class="description"><?php esc_html_e( 'Set to 0 for unlimited. Generation will stop when this budget is reached.', 'peptide-search-ai' ); ?></p>
		<?php
	}

	// -------------------------------------------------------------------------
	// Settings page
	// -------------------------------------------------------------------------

	public static function render_settings_page() {
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
			<h2><?php esc_html_e( 'How It Works', 'peptide-search-ai' ); ?></h2>
			<ol style="max-width:600px; line-height:1.8;">
				<li><?php esc_html_e( 'Add the shortcode [peptide_search] to any page.', 'peptide-search-ai' ); ?></li>
				<li><?php esc_html_e( 'Visitors search for a peptide name.', 'peptide-search-ai' ); ?></li>
				<li><?php esc_html_e( 'If found, results appear instantly.', 'peptide-search-ai' ); ?></li>
				<li><?php esc_html_e( 'If not found, the system automatically validates the name, creates a placeholder, and generates content in the background.', 'peptide-search-ai' ); ?></li>
				<li><?php esc_html_e( 'The visitor sees a "being added" message and can check back later.', 'peptide-search-ai' ); ?></li>
			</ol>

			<hr />
			<h2><?php esc_html_e( 'Shortcode', 'peptide-search-ai' ); ?></h2>
			<p><code>[peptide_search]</code></p>
			<p><?php esc_html_e( 'Customize placeholder:', 'peptide-search-ai' ); ?> <code>[peptide_search placeholder="Search peptides..."]</code></p>

			<hr />
			<h2><?php esc_html_e( 'REST API', 'peptide-search-ai' ); ?></h2>
			<p><code>GET /wp-json/peptides/v1/search?q=BPC-157</code></p>
			<p class="description"><?php esc_html_e( 'The REST endpoint returns published peptides only — it does not trigger auto-generation.', 'peptide-search-ai' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render the API usage summary section.
	 */
	private static function render_usage_summary() {
		$current_spend = PSA_Cost_Tracker::get_monthly_spend();
		$current_tokens = PSA_Cost_Tracker::get_monthly_tokens();
		$budget = floatval( self::get_setting( 'monthly_budget', PSA_Config::DEFAULT_MONTHLY_BUDGET ) );
		$budget_remaining = ( 0 === $budget ) ? __( 'Unlimited', 'peptide-search-ai' ) : '$' . number_format( max( 0, $budget - $current_spend ), 2 );
		$recent_logs = PSA_Cost_Tracker::get_recent_logs( 20 );

		?>
		<div style="background: #f5f5f5; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
			<h3><?php esc_html_e( 'This Month', 'peptide-search-ai' ); ?></h3>
			<p>
				<strong><?php esc_html_e( 'Spend:', 'peptide-search-ai' ); ?></strong> $<?php echo esc_html( number_format( $current_spend, 2 ) ); ?><br />
				<strong><?php esc_html_e( 'Tokens:', 'peptide-search-ai' ); ?></strong> <?php echo esc_html( number_format( $current_tokens ) ); ?><br />
				<strong><?php esc_html_e( 'Budget Remaining:', 'peptide-search-ai' ); ?></strong> <?php echo esc_html( $budget_remaining ); ?>
			</p>
		</div>

		<?php if ( ! empty( $recent_logs ) ) : ?>
			<h3><?php esc_html_e( 'Recent API Calls (Last 20)', 'peptide-search-ai' ); ?></h3>
			<table class="widefat striped" style="margin-bottom: 20px;">
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
							<td><?php echo ( 1 === (int) $log->success ) ? '✓' : '✗'; ?></td>
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
	private static function get_setting( $key, $default = '' ) {
		$options = get_option( 'psa_settings', array() );
		return $options[ $key ] ?? $default;
	}
}
