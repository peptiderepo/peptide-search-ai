<?php
/**
 * Tests for PSA_Post_Type class.
 *
 * As of v4.5.0 this class no longer registers the `peptide` CPT or
 * `peptide_category` taxonomy — PR Core >= 0.2.0 owns those. PSA_Post_Type's
 * remaining public surface is: META_FIELDS, EXTENDED_META_FIELDS,
 * get_all_meta_keys(), init_admin() (meta boxes + admin columns), and the
 * add_admin_columns / render_admin_column helpers.
 *
 * These tests cover the public meta-key contract and the admin-column
 * helpers. Registration-path coverage lives in DependencyCheckTest (which
 * also contains the regression guard against those methods coming back).
 *
 * @package PeptideSearchAI
 */

declare( strict_types=1 );

// Stub WordPress functions needed by PSA_Post_Type at class-load time.
if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

require_once dirname( __DIR__ ) . '/bootstrap.php';
require_once ABSPATH . 'includes/class-psa-post-type.php';

class PostTypeTest extends \PHPUnit\Framework\TestCase {

	/**
	 * Verify core META_FIELDS constant has expected keys.
	 */
	public function test_core_meta_fields_keys(): void {
		$expected = array(
			'psa_sequence',
			'psa_molecular_weight',
			'psa_molecular_formula',
			'psa_aliases',
			'psa_mechanism',
			'psa_research_apps',
			'psa_safety_profile',
			'psa_dosage_info',
			'psa_references',
			'psa_source',
			'psa_pubchem_cid',
		);

		foreach ( $expected as $key ) {
			$this->assertArrayHasKey( $key, PSA_Post_Type::META_FIELDS, "Missing core meta field: {$key}" );
		}
	}

	/**
	 * Verify extended meta fields contain the 9 v4.3.0 fields.
	 */
	public function test_extended_meta_fields_count(): void {
		$this->assertCount( 9, PSA_Post_Type::EXTENDED_META_FIELDS );
	}

	/**
	 * Verify specific extended field keys.
	 */
	public function test_extended_meta_field_keys(): void {
		$expected = array(
			'psa_half_life',
			'psa_stability',
			'psa_solubility',
			'psa_vial_size_mg',
			'psa_storage_lyophilized',
			'psa_storage_reconstituted',
			'psa_typical_dose_mcg',
			'psa_cycle_parameters',
			'psa_amino_acid_count',
		);

		foreach ( $expected as $key ) {
			$this->assertArrayHasKey( $key, PSA_Post_Type::EXTENDED_META_FIELDS, "Missing extended meta field: {$key}" );
		}
	}

	/**
	 * Verify get_all_meta_keys() returns combined core + extended keys.
	 */
	public function test_get_all_meta_keys_merges_both(): void {
		$all_keys = PSA_Post_Type::get_all_meta_keys();

		// Should include both core and extended.
		$this->assertContains( 'psa_sequence', $all_keys );
		$this->assertContains( 'psa_half_life', $all_keys );
		$this->assertContains( 'psa_amino_acid_count', $all_keys );

		// Total should be core + extended.
		$expected_count = count( PSA_Post_Type::META_FIELDS ) + count( PSA_Post_Type::EXTENDED_META_FIELDS );
		$this->assertCount( $expected_count, $all_keys );
	}

	/**
	 * add_admin_columns() inserts the Source column right after Title.
	 * This is the CPT-independent part of the admin surface and runs even
	 * before PSA_Post_Type_Meta is loaded by PR Core's registration.
	 */
	public function test_add_admin_columns_inserts_source_after_title(): void {
		$columns = array(
			'cb'     => '<input />',
			'title'  => 'Title',
			'date'   => 'Date',
		);
		$result = PSA_Post_Type::add_admin_columns( $columns );

		$keys = array_keys( $result );
		$this->assertSame( array( 'cb', 'title', 'psa_source', 'date' ), $keys );
		$this->assertSame( 'Source', $result['psa_source'] );
	}

	/**
	 * add_admin_columns() is a no-op when 'title' is not in the columns
	 * array (defensive — WP themes/plugins ca