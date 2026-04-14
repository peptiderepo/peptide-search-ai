<?php
/**
 * Plugin Name: Peptide Search AI
 * Plugin URI:  https://example.com/peptide-search-ai
 * Description: Searchable peptide database with AI-powered auto-population and browsable directory.
 * Version:     4.4.3
 * Author:      Terence
 * License:     GPL v2 or later
 * Text Domain: peptide-search-ai
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PSA_VERSION', '4.4.3' );
define( 'PSA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PSA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PSA_PLUGIN_FILE', __FILE__ );

require_once PSA_PLUGIN_DIR . 'includes/class-psa-config.php';
require_once PSA_PLUGIN_DIR . 'includes/class-psa-encryption.php';
require_once PSA_PLUGIN_DIR . 'includes/class-psa-post-type.php';
require_once PSA_PLUGIN_DIR . 'includes/class-psa-search.php';
require_once PSA_PLUGIN_DIR . 'includes/class-psa-cost-tracker.php';
require_once PSA_PLUGIN_DIR . 'includes/class-psa-openrouter.php';
require_once PSA_PLUGIN_DIR . 'includes/class-psa-kb-builder.php';
require_once PSA_PLUGIN_DIR . 'includes/class-psa-ai-generator.php';
require_once PSA_PLUGIN_DIR . 'includes/class-psa-pubchem.php';
require_once PSA_PLUGIN_DIR . 'includes/class-psa-admin.php';
require_once PSA_PLUGIN_DIR . 'includes/class-psa-batch-enrichment.php';
require_once PSA_PLUGIN_DIR . 'includes/class-psa-template.php';
require_once PSA_PLUGIN_DIR . 'includes/class-psa-directory.php';
require_once PSA_PLUGIN_DIR . 'includes/class-psa-upgrade.php';

function psa_init() {
	PSA_Post_Type::register_peptide_post_type();
	PSA_Post_Type::register_taxonomy();
	PSA_Search::init();
	PSA_AI_Generator::init();
	PSA_Admin::init();
	PSA_Batch_Enrichment::init();
	PSA_Template::init();
	PSA_Directory::init();
}
add_action( 'init', 'psa_init' );

function psa_admin_init() {
	PSA_Post_Type::init_admin();
	PSA_Upgrade::maybe_run();
}
add_action( 'admin_init', 'psa_admin_init' );

/**
 * Check if current page is the Echo KB main page.
 * Uses is_page() which is reliable even in block templates.
 */
function psa_is_kb_page() {
	$settings = get_option( 'psa_settings', array() );
	$kb_page  = $settings['kb_page_id'] ?? 73;
	return is_page( $kb_page ) || is_page( 'peptide-database' );
}

/**
 * Enqueue assets on pages that need the search:
 * - Homepage (front page) where the hero search lives
 * - KB page (page ID 73)
 * - Any page/post containing the [peptide_search] shortcode
 * - Single peptide pages
 * - Any page/post containing the [peptide_directory] shortcode
 */
function psa_maybe_enqueue_assets() {
	// Homepage: always enqueue (hero section + search overlay use the shortcode).
	if ( is_front_page() ) {
		psa_enqueue_frontend_assets();
		return;
	}

	// KB page: enqueue and return early (no $post dependency).
	if ( psa_is_kb_page() ) {
		psa_enqueue_frontend_assets();
		return;
	}

	global $post;
	if ( ! is_a( $post, 'WP_Post' ) ) {
		return;
	}

	$has_search    = has_shortcode( $post->post_content, 'peptide_search' );
	$has_directory = has_shortcode( $post->post_content, 'peptide_directory' );
	$is_peptide    = is_singular( 'peptide' );

	if ( ! $has_search && ! $has_directory && ! $is_peptide ) {
		return;
	}

	if ( $has_search || $is_peptide ) {
		psa_enqueue_frontend_assets();
	}

	if ( $has_directory ) {
		psa_enqueue_directory_assets();
	}
}
add_action( 'wp_enqueue_scripts', 'psa_maybe_enqueue_assets' );

/**
 * Shared helper to enqueue search CSS, JS, and localize script.
 */
function psa_enqueue_frontend_assets() {
	wp_enqueue_style(
		'psa-styles',
		PSA_PLUGIN_URL . 'assets/css/peptide-search.css',
		array(),
		PSA_VERSION
	);

	wp_enqueue_script(
		'psa-search',
		PSA_PLUGIN_URL . 'assets/js/peptide-search.js',
		array( 'jquery' ),
		PSA_VERSION,
		true
	);

	wp_localize_script(
		'psa-search',
		'psaAjax',
		array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'psa_search_nonce' ),
			'i18n'    => array(
				'error'              => __( 'Error:', 'peptide-search-ai' ),
				'searchFailed'       => __( 'Search failed.', 'peptide-search-ai' ),
				'unexpected'         => __( 'Unexpected response.', 'peptide-search-ai' ),
				'rateLimited'        => __( 'Too many requests. Please try again later.', 'peptide-search-ai' ),
				'networkError'       => __( 'Could not connect to the server. Please try again.', 'peptide-search-ai' ),
				'timeout'            => __( 'Request timed out. The server took too long to respond. Please try again.', 'peptide-search-ai' ),
				'connectionError'    => __( 'Network error. Please check your connection and try again.', 'peptide-search-ai' ),
				'serverError'        => __( 'Server error. Please try again later.', 'peptide-search-ai' ),
				'unavailable'        => __( 'Server is temporarily unavailable. Please try again later.', 'peptide-search-ai' ),
				'peptidesFound'      => __( 'peptide(s) found', 'peptide-search-ai' ),
				'pendingMsg'         => __( 'is currently being added to our database. Please check back again later.', 'peptide-search-ai' ),
				'invalidMsg'         => __( 'does not appear to be a recognized peptide. Please check the spelling or try a different search term.', 'peptide-search-ai' ),
				'verified'           => __( 'Verified', 'peptide-search-ai' ),
				'curated'            => __( 'Curated', 'peptide-search-ai' ),
			),
		)
	);
}

/**
 * Enqueue directory-specific CSS and JS for the [peptide_directory] shortcode.
 * Includes the search CSS as a base dependency (spinner styles, badge styles).
 */
function psa_enqueue_directory_assets() {
	// Directory depends on search CSS for shared styles (spinner, badges).
	wp_enqueue_style(
		'psa-styles',
		PSA_PLUGIN_URL . 'assets/css/peptide-search.css',
		array(),
		PSA_VERSION
	);

	wp_enqueue_style(
		'psa-directory-styles',
		PSA_PLUGIN_URL . 'assets/css/psa-directory.css',
		array( 'psa-styles' ),
		PSA_VERSION
	);

	wp_enqueue_script(
		'psa-directory',
		PSA_PLUGIN_URL . 'assets/js/psa-directory.js',
		array(),
		PSA_VERSION,
		true
	);

	// psaDirectory localization is handled in PSA_Directory::render_shortcode()
	// because it needs to run after get_terms() to populate category data.
}

/**
 * On the KB page, hide Echo KB search and inject ours via wp_footer.
 */
function psa_replace_kb_search() {
	if ( ! psa_is_kb_page() ) {
		return;
	}

	$search_html = PSA_Search::render_search_form(
		array(
			'placeholder' => 'Search for a peptide (e.g., BPC-157, Thymosin Beta-4)...',
		)
	);
	$encoded     = wp_json_encode( $search_html );

	echo '<style>
		#epkb-ml__module-search { display: none !important; }
		.psa-kb-search-wrap { background: #4a8c3f; padding: 30px 20px; text-align: center; }
		.psa-kb-search-wrap h2 { color: #fff; font-size: 26px; margin: 0 0 15px 0; font-weight: 600; }
		.psa-kb-search-wrap .psa-search-wrap { max-width: 700px; margin: 0 auto; }
		.psa-kb-search-wrap .psa-search-results,
		.psa-kb-search-wrap .psa-checking,
		.psa-kb-search-wrap .psa-pending,
		.psa-kb-search-wrap .psa-invalid,
		.psa-kb-search-wrap .psa-error {
			text-align: left; background: #fff; border-radius: 6px; margin-top: 10px; padding: 15px;
		}
	</style>';

	echo '<script>
		document.addEventListener("DOMContentLoaded", function() {
			var kbRow = document.getElementById("epkb-ml__row-1");
			if (!kbRow) return;
			var wrap = document.createElement("div");
			wrap.className = "psa-kb-search-wrap";
			wrap.innerHTML = "<h2>Search Our Peptide Database</h2>" + ' . $encoded . ';
			kbRow.parentNode.insertBefore(wrap, kbRow);
		});
	</script>';
}
add_action( 'wp_footer', 'psa_replace_kb_search' );

function psa_activate() {
	PSA_Post_Type::register_peptide_post_type();
	PSA_Post_Type::register_taxonomy();
	PSA_Post_Type::populate_default_categories();
	PSA_Cost_Tracker::create_table();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'psa_activate' );

function psa_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'psa_deactivate' );

function psa_get_client_ip() {
	$headers = array(
		'HTTP_CF_CONNECTING_IP',
		'REMOTE_ADDR',
	);
	foreach ( $headers as $header ) {
		if ( ! empty( $_SERVER[ $header ] ) ) {
			$ip = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
			$ip = trim( $ip[0] );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
	}
	return '0.0.0.0';
}

function psa_get_admin_user_id() {
	$admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	);
	return ! empty( $admins ) ? (int) $admins[0] : 0;
}
