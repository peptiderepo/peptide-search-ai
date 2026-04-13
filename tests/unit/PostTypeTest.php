<?php
/**
 * Tests for PSA_Post_Type class.
 *
 * Covers: taxonomy constants, default categories list, meta field constants,
 * extended meta fields, and get_all_meta_keys() helper.
 *
 * @package PeptideSearchAI
 */

declare( strict_types=1 );

// Stub WordPress functions needed by PSA_Post_Type but not by the test bootstrap.
if ( ! function_exists( 'post_type_exists' ) ) {
	function post_type_exists( $post_type ) {
		return false;
	}
}
if ( ! function_exists( 'register_post_type' ) ) {
	function register_post_type( $post_type, $args = array() ) {
		return null;
	}
}
if ( ! function_exists( 'taxonomy_exists' ) ) {
	function taxonomy_exists( $taxonomy ) {
		return false;
	}
}
if ( ! function_exists( 'register_taxonomy' ) ) {
	function register_taxonomy( $taxonomy, $object_type, $args = array() ) {
		return null;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {}
}

require_once dirname( __DIR__ ) . '/bootstrap.php';
require_once ABSPATH . 'includes/class-psa-post-type.php';

class PostTypeTest extends \PHPUnit\Framework\TestCase {

	/**
	 * Verify default categories constant contains exactly 8 entries.
	 */
	public function test_default_categories_count(): void {
		$this->assertCount( 8, PSA_Post_Type::DEFAULT_CATEGORIES );
	}

	/**
	 * Verify required category slugs exist in the constant.
	 */
	public function test_default_categories_slugs(): void {
		$required = array(
			'tissue-repair',
			'lipid-metabolism',
			'aging-research',
			'dermatological',
			'metabolic',
			'growth-hormone',
			'immunology',
			'endocrine',
		);

		foreach ( $required as $slug ) {
			$this->assertArrayHasKey( $slug, PSA_Post_Type::DEFAULT_CATEGORIES, "Missing category slug: {$slug}" );
		}
	}

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
	 * Verify extended meta fields contain the 9 new v4.3.0 fields.
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
}
