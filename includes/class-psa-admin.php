<?php
/**
 * Admin settings registration, sanitization, and data tools for Peptide Search AI.
 *
 * What: Settings registration, sanitization, admin action handlers, migration, re-enrichment.
 * Who calls it: WordPress admin_menu and admin_init hooks via PSA_Admin::init().
 * Dependencies: PSA_Encryption, PSA_Config, PSA_Post_Type, PSA_Admin_Page (rendering).
 *
 * @package PeptideSearchAI
 * @since   2.6.0
 * @see     includes/class-psa-admin-page.php — Settings page rendering and field renderers.
 * @see     includes/class-psa-encryption.php
 * @see     includes/class-psa-cost-tracker.php
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
		add_action( 'admin_enqueue_scripts', array( 'PSA_Admin_Page', 'enqueue_batch_scripts' ) );
	}

	/** Add settings page under Settings menu. */
	public static function add_menu_pages(): void {
		add_options_page(
			__( 'Peptide Search AI', 'peptide-search-ai' ),
			__( 'Peptide Search AI', 'peptide-search-ai' ),
			'manage_options',
			'peptide-search-ai',
			array( 'PSA_Admin_Page', 'render_settings_page' )
		);
	}

	/** Register settings fields — rendering delegated to PSA_Admin_Page. */
	public static function register_settings(): void {
		register_setting( 'psa_settings_group', 'psa_settings', array(
			'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
		) );

		add_settings_section( 'psa_ai_section', __( 'OpenRouter API Settings', 'peptide-search-ai' ), array( 'PSA_Admin_Page', 'render_section_description' ), 'peptide-search-ai' );
		add_settings_field( 'api_key', __( 'API Key', 'peptide-search-ai' ), array( 'PSA_Admin_Page', 'render_api_key_field' ), 'peptide-search-ai', 'psa_ai_section' );
		add_settings_field( 'ai_model', __( 'Generation Model', 'peptide-search-ai' ), array( 'PSA_Admin_Page', 'render_model_field' ), 'peptide-search-ai', 'psa_ai_section' );
		add_settings_field( 'validation_model', __( 'Validation Model', 'peptide-search-ai' ), array( 'PSA_Admin_Page', 'render_validation_model_field' ), 'peptide-search-ai', 'psa_ai_section' );

		add_settings_section( 'psa_behavior_section', __( 'Behavior Settings', 'peptide-search-ai' ), null, 'peptide-search-ai' );
		add_settings_field( 'auto_publish', __( 'Auto-Publish', 'peptide-search-ai' ), array( 'PSA_Admin_Page', 'render_publish_field' ), 'peptide-search-ai', 'psa_behavior_section' );
		add_settings_field( 'use_pubchem', __( 'PubChem Enrichment', 'peptide-search-ai' ), array( 'PSA_Admin_Page', 'render_pubchem_field' ), 'peptide-search-ai', 'psa_behavior_section' );
		add_settings_field( 'monthly_budget', __( 'Monthly API Budget (USD)', 'peptide-search-ai' ), array( 'PSA_Admin_Page', 'render_budget_field' ), 'peptide-search-ai', 'psa_behavior_section' );
	}

	/**
	 * Sanitize settings input.
	 *
	 * @param array $input Raw form input.
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
			$api_key_value = self::get_setting( 'api_key', '' );
		}

		return array(
			'api_key'          => $api_key_value,
			'ai_model'         => sanitize_text_field( $input['ai_model'] ?? '' ),
			'validation_model' => sanitize_text_field( $input['validation_model'] ?? '' ),
			'auto_publish'     => sanitize_text_field( $input['auto_publish'] ?? 'draft' ),
			'use_pubchem'      => ! empty( $input['use_pubchem'] ) ? '1' : '0',
			'monthly_budget'   => max( 0, floatval( $input['monthly_budget'] ?? PSA_Config::DEFAULT_MONTHLY_BUDGET ) ),
		);
	}

	/** Handle admin actions (migration, re-enrich) via GET params with nonce verification. */
	public static function handle_admin_actions(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['psa_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_GET['psa_action'] ) );
		$nonce  = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( 'migrate_categories' === $action && wp_verify_nonce( $nonce, 'psa_migrate_categories' ) ) {
			self::run_category_migration();
			add_action( 'admin_notices', function () {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Category migration completed.', 'peptide-search-ai' ) . '</p></div>';
			} );
		}

		if ( 'reenrich' === $action && wp_verify_nonce( $nonce, 'psa_reenrich' ) ) {
			$queued = self::queue_reenrichment();
			add_action( 'admin_notices', function () use ( $queued ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf(
					__( '%d peptides queued for re-enrichment.', 'peptide-search-ai' ), $queued
				) ) . '</p></div>';
			} );
		}
	}

	// ── Category migration ──────────────────────────────────────────────

	/**
	 * Assign peptide_category terms to existing posts from free-text meta.
	 * Idempotent — safe to run multiple times.
	 */
	private static function run_category_migration(): void {
		$posts = get_posts( array(
			'post_type'      => 'peptide',
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );

		foreach ( $posts as $post_id ) {
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

		// Fuzzy match against registered default categories.
		foreach ( PSA_Post_Type::DEFAULT_CATEGORIES as $slug => $name ) {
			if ( stripos( $text, $name ) !== false || stripos( $text, str_replace( '-', ' ', $slug ) ) !== false ) {
				return $slug;
			}
		}

		return '';
	}

	// ── Re-enrichment ───────────────────────────────────────────────────

	/**
	 * Queue re-enrichment for published peptides missing extended fields.
	 * Respects the daily generation cap to avoid cost overruns.
	 *
	 * @return int Number of peptides queued.
	 */
	private static function queue_reenrichment(): int {
		$posts = get_posts( array(
			'post_type'      => 'peptide',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
				'relation' => 'OR',
				array( 'key' => 'psa_half_life', 'compare' => 'NOT EXISTS' ),
				array( 'key' => 'psa_half_life', 'value' => '' ),
			),
		) );

		$daily_key   = 'psa_daily_gen_' . gmdate( 'Y-m-d' );
		$daily_count = (int) get_transient( $daily_key );
		$queued      = 0;

		foreach ( $posts as $post_id ) {
			if ( ( $daily_count + $queued ) >= PSA_Config::DAILY_GENERATION_CAP ) {
				break;
			}

			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}

			update_post_meta( $post_id, 'psa_source', 'pending' );
			wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
			update_post_meta( $post_id, 'psa_generation_started', time() );

			$args = array( $post_id, $post->post_title );
			if ( ! wp_next_scheduled( 'psa_generate_peptide_background', $args ) ) {
				// Stagger 30s apart to avoid rate-limit hammering.
				wp_schedule_single_event( time() + ( $queued * 30 ), 'psa_generate_peptide_background', $args );
			}
			$queued++;
		}

		set_transient( $daily_key, $daily_count + $queued, DAY_IN_SECONDS );
		if ( $queued > 0 ) {
			spawn_cron();
		}

		return $queued;
	}

	// ── Settings accessor ───────────────────────────────────────────────

	/**
	 * Get a single setting with a default.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public static function get_setting( string $key, $default = '' ) {
		$options = get_option( 'psa_settings', array() );
		return $options[ $key ] ?? $default;
	}

	// ── Backward-compatible proxies (rendering moved to PSA_Admin_Page) ─

	/** @see PSA_Admin_Page::enqueue_batch_scripts() */
	public static function enqueue_batch_scripts( string $hook ): void { PSA_Admin_Page::enqueue_batch_scripts( $hook ); }

	/** @see PSA_Admin_Page::render_section_description() */
	public static function render_section_description(): void { PSA_Admin_Page::render_section_description(); }

	/** @see PSA_Admin_Page::render_api_key_field() */
	public static function render_api_key_field(): void { PSA_Admin_Page::render_api_key_field(); }

	/** @see PSA_Admin_Page::render_model_field() */
	public static function render_model_field(): void { PSA_Admin_Page::render_model_field(); }

	/** @see PSA_Admin_Page::render_validation_model_field() */
	public static function render_validation_model_field(): void { PSA_Admin_Page::render_validation_model_field(); }

	/** @see PSA_Admin_Page::render_publish_field() */
	public static function render_publish_field(): void { PSA_Admin_Page::render_publish_field(); }

	/** @see PSA_Admin_Page::render_pubchem_field() */
	public static function render_pubchem_field(): void { PSA_Admin_Page::render_pubchem_field(); }

	/** @see PSA_Admin_Page::render_budget_field() */
	public static function render_budget_field(): void { PSA_Admin_Page::render_budget_field(); }

	/** @see PSA_Admin_Page::render_settings_page() */
	public static function render_settings_page(): void { PSA_Admin_Page::render_settings_page(); }
}
