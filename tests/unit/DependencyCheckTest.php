<?php
/**
 * Tests for PSA_Dependency_Check.
 *
 * Covers:
 * - is_satisfied() returns false when PR_CORE_VERSION is undefined.
 * - is_satisfied() returns false when PR_CORE_VERSION < REQUIRED_PR_CORE_VERSION.
 * - is_satisfied() returns true when PR_CORE_VERSION == REQUIRED_PR_CORE_VERSION.
 * - is_satisfied() returns true when PR_CORE_VERSION > REQUIRED_PR_CORE_VERSION.
 * - Regression guard: PSA_Post_Type no longer declares
 *   register_peptide_post_type / register_taxonomy / populate_default_categories.
 *
 * PHP constants are defined process-wide and cannot be redefined once set, so
 * each version-value variant runs in its own separate process via
 * @runInSeparateProcess + @preserveGlobalState disabled. That gives each case
 * a clean state to define (or not define) PR_CORE_VERSION.
 *
 * @package PeptideSearchAI
 */

declare( strict_types=1 );

// Stub WordPress admin-notice helpers needed by maybe_render_notice() and the
// class autoloader. Pure stubs; no assertions run against them here — the
// maybe_render_notice() path is exercised only indirectly via is_satisfied().
if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) { return true; }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

require_once dirname( __DIR__ ) . '/bootstrap.php';
require_once ABSPATH . 'includes/class-psa-dependency-check.php';

class DependencyCheckTest extends \PHPUnit\Framework\TestCase {

	/**
	 * REQUIRED_PR_CORE_VERSION is the contract — changing it is a breaking
	 * coordination event with PR Core. Assert it here so an accidental bump
	 * trips CI.
	 */
	public function test_required_pr_core_version_constant(): void {
		$this->assertSame(
			'0.2.0',
			PSA_Dependency_Check::REQUIRED_PR_CORE_VERSION,
			'REQUIRED_PR_CORE_VERSION is a contract with PR Core; changing it must be a coordinated PR across both plugins.'
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_is_satisfied_false_when_pr_core_version_undefined(): void {
		require_once dirname( __DIR__, 2 ) . '/includes/class-psa-dependency-check.php';

		$this->assertFalse(
			defined( 'PR_CORE_VERSION' ),
			'Precondition: PR_CORE_VERSION must not be defined in this process.'
		);
		$this->assertFalse( PSA_Dependency_Check::is_satisfied() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_is_satisfied_false_when_pr_core_version_below_required(): void {
		define( 'PR_CORE_VERSION', '0.1.1' );
		require_once dirname( __DIR__, 2 ) . '/includes/class-psa-dependency-check.php';

		$this->assertFalse( PSA_Dependency_Check::is_satisfied() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_is_satisfied_true_when_pr_core_version_exactly_required(): void {
		define( 'PR_CORE_VERSION', '0.2.0' );
		require_once dirname( __DIR__, 2 ) . '/includes/class-psa-dependency-check.php';

		$this->assertTrue( PSA_Dependency_Check::is_satisfied() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_is_satisfied_true_when_pr_core_version_above_required(): void {
		define( 'PR_CORE_VERSION', '0.3.0' );
		require_once dirname( __DIR__, 2 ) . '/includes/class-psa-dependency-check.php';

		$this->assertTrue( PSA_Dependency_Check::is_satisfied() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_is_satisfied_false_for_pre_release_below_required(): void {
		// Defensive: PHP's version_compare treats "0.2.0-alpha" as < "0.2.0",
		// which is the desired behaviour here. Regression guard against the
		// constant silently accepting pre-release PR Core builds.
		define( 'PR_CORE_VERSION', '0.2.0-alpha' );
		require_once dirname( __DIR__, 2 ) . '/includes/class-psa-dependency-check.php';

		$this->assertFalse( PSA_Dependency_Check::is_satisfied() );
	}

	/**
	 * Regression guard for the v4.5.0 cleanup: PSA_Post_Type must no longer
	 * expose the three removed registration methods. A future refactor that
	 * re-introduces any of them would re-create the CPT collision with PR
	 * Core and break the production site.
	 */
	public function test_psa_post_type_registration_methods_removed(): void {
		// Stub the minimum WP surface PSA_Post_Type touches at class-load
		// time so we can reflect on it here without pulling the full bootstrap.
		if ( ! function_exists( 'post_type_exists' ) ) {
			function post_type_exists( $post_type ) { return false; }
		}
		if ( ! function_exists( 'register_post_type' ) ) {
			function register_post_type( $post_type, $args = array() ) { return null; }
		}
		if ( ! function_exists( 'taxonomy_exists' ) ) {
			function taxonomy_exists( $taxonomy ) { return false; }
		}
		if ( ! function_exists( 'register_taxonomy' ) ) {
			function register_taxonomy( $taxonomy, $object_type, $args = array() ) { return null; }
		}
		if ( ! function_exists( 'add_filter' ) ) {
			function add_filter() {}
		}
		if ( ! function_exists( '__' ) ) {
			function __( $text, $domain = 'default' ) { return $text; }
		}

		require_once ABSPATH . 'includes/class-psa-post-type.php';

		$this->assertFalse(
			method_exists( 'PSA_Post_Type', 'register_peptide_post_type' ),
			'PSA_Post_Type::register_peptide_post_type() was removed in v4.5.0 — do not reintroduce it; PR Core owns the peptide CPT.'
		);
		$this->assertFalse(
			method_exists( 'PSA_Post_Type', 'register_taxonomy' ),
			'PSA_Post_Type::register_taxonomy() was removed in v4.5.0 — do not reintroduce it; PR Core owns the peptide_category taxonomy.'
		);
		$this->assertFalse(
			method_exists( 'PSA_Post_Type', 'populate_default_categories' ),
			'PSA_Post_Type::populate_default_categories() was removed in v4.5.0 — PR Core owns taxonomy term seeding.'
		);

		// DEFAULT_CATEGORIES constant is intentionally retained: PSA consumes
		// it as a slug→name reference list for fuzzy-matching in
		// PSA_AI_Content::assign_category_term() and the admin migration
		// button. It is no longer a seed list. See CHANGELOG [4.5.0] and
		// the class docblock.
		$this->assertTrue(
			defined( 'PSA_Post_Type::DEFAULT_CATEGORIES' ),
			'PSA_Post_Type::DEFAULT_CATEGORIES is retained as a consumer lookup — PSA_AI_Content and the admin migration button read it.'
		);
		$this->assertCount(
			8,
			PSA_Post_Type::DEFAULT_CATEGORIES,
			'DEFAULT_CATEGORIES should still contain exactly 8 entries matching the terms seeded on prod.'
		);
	}

	/**
	 * Meta-box contract survives the CPT handoff — the `psa_*` meta key
	 * namespace on the `peptide` CPT is still owned by PSA, so the class
	 * constants that drive meta-box rendering must remain intact.
	 */
	public function test_psa_post_type_meta_keys_unchanged(): void {
		$this->assertArrayHasKey( 'psa_sequence', PSA_Post_Type::META_FIELDS );
		$this->assertArrayHasKey( 'psa_molecular_weight', PSA_Post_Type::META_FIELDS );
		$this->assertArrayHasKey( 'psa_source', PSA_Post_Type::META_FIELDS );
		$this->assertArrayHasKey( 'psa_pubchem_cid', PSA_Post_Type::META_FIELDS );

		$this->assertArrayHasKey( 'psa_half_life', PSA_Post_Type::EXTENDED_META_FIELDS );
		$this->assertArrayHasKey( 'psa_stability', PSA_Post_Type::EXTENDED_META_FIELDS );
		$this->assertArrayHasKey( 'psa_solubility', PSA_Post_Type::EXTENDED_META_FIELDS );
	}

	/**
	 * PSA_Post_Type::init_admin() still hooks meta boxes + admin columns
	 * against the `peptide` CPT. The method has to survive the CPT-handoff
	 * refactor — it's the primary consumer-side attachment point.
	 */
	public function test_psa_post_type_init_admin_preserved(): void {
		$this->assertTrue( method_exists( 'PSA_Post_Type', 'init_admin' ) );
	}
}
