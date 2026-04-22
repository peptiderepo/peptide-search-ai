<?php
/**
 * Dependency check for Peptide Repo Core (PR Core).
 *
 * What: Verifies PR Core >= REQUIRED_PR_CORE_VERSION is active so PSA can rely
 * on the `peptide` CPT + `peptide_category` taxonomy being registered. Renders
 * an admin notice when the dependency is unmet. Serves as a boot-path gate:
 * PSA surfaces that operate on the `peptide` CPT (meta boxes, admin columns,
 * directory widget, KB renderer, search widget, template) call
 * is_satisfied() and degrade gracefully — they do not fatal — when PR Core is
 * missing or out of date.
 *
 * Who calls it: Main plugin bootstrap (`psa_init` + `psa_admin_init`) and each
 * CPT-dependent class's `init()` method before hooking into WordPress.
 * Dependencies: PR Core defines the PR_CORE_VERSION constant in its main
 * plugin file; absence of the constant is treated as "not installed."
 *
 * @package PeptideSearchAI
 * @since   4.5.0
 * @see     includes/class-psa-post-type.php  — Gated via init_admin().
 * @see     includes/class-psa-directory.php  — Gated via init().
 * @see     includes/class-psa-template.php   — Gated via init().
 * @see     includes/class-psa-search.php     — Gated via init().
 */
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PSA_Dependency_Check {

	/**
	 * Minimum PR Core version that registers the `peptide` CPT + `peptide_category`
	 * taxonomy in the shape PSA depends on. Coordinated release: see
	 * `convo/peptidesearch/threads/2026-04-peptide-cpt-consolidation/`.
	 */
	const REQUIRED_PR_CORE_VERSION = '0.2.0';

	/** Register the admin notice hook. Safe to call multiple times. */
	public static function init(): void {
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render_notice' ) );
	}

	/**
	 * Whether PR Core is installed, active, and at a version PSA accepts.
	 *
	 * Pure function — no side effects. Safe to call from any boot-path gate.
	 *
	 * @return bool True when PR_CORE_VERSION is defined and >= REQUIRED_PR_CORE_VERSION.
	 */
	public static function is_satisfied(): bool {
		if ( ! defined( 'PR_CORE_VERSION' ) ) {
			return false;
		}
		return version_compare( (string) constant( 'PR_CORE_VERSION' ), self::REQUIRED_PR_CORE_VERSION, '>=' );
	}

	/**
	 * Render the admin notice when the dependency is unmet.
	 *
	 * Hooked to `admin_notices`. Skips rendering when the dependency is
	 * satisfied and when the current user lacks the `activate_plugins`
	 * capability (the only role that can act on the notice).
	 */
	public static function maybe_render_notice(): void {
		if ( self::is_satisfied() ) {
			return;
		}
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$msg = esc_html__(
			'Peptide Search AI requires Peptide Repo Core 0.2.0 or newer for peptide data (custom post type and taxonomy). Please install or update Peptide Repo Core — until then, peptide meta boxes, the directory widget, and the search widget are disabled.',
			'peptide-search-ai'
		);

		printf( '<div class="notice notice-error"><p>%s</p></div>', $msg ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $msg already escaped via esc_html__.
	}
}
