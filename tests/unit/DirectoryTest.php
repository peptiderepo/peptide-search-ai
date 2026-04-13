<?php
/**
 * Tests for PSA_Directory class.
 *
 * Covers: constants, format_compound() output structure.
 *
 * @package PeptideSearchAI
 */

declare( strict_types=1 );

// Stub WordPress functions needed by PSA_Directory.
if ( ! function_exists( 'add_shortcode' ) ) {
	function add_shortcode() {}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}
if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route() {}
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $id ) {
		return 'https://example.com/peptides/test-peptide/';
	}
}
if ( ! function_exists( 'wp_get_object_terms' ) ) {
	function wp_get_object_terms( $id, $taxonomy, $args = array() ) {
		return array();
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key = '', $single = false ) {
		$meta = array(
			'psa_half_life'             => '4-6 hours',
			'psa_stability'             => '28 days at 2-8°C',
			'psa_source'                => 'ai-generated',
			'psa_sequence'              => 'Gly-Glu-Pro',
			'psa_molecular_weight'      => '1419.53 Da',
			'psa_molecular_formula'     => 'C62H98N16O22',
			'psa_aliases'               => 'BPC-157, Pentadecapeptide',
			'psa_mechanism'             => 'Test mechanism',
			'psa_research_apps'         => 'Test research',
			'psa_safety_profile'        => 'Test safety',
			'psa_dosage_info'           => 'Test dosage',
			'psa_references'            => 'Test refs',
			'psa_pubchem_cid'           => '12345',
			'psa_solubility'            => 'Bacteriostatic Water',
			'psa_vial_size_mg'          => '5',
			'psa_storage_lyophilized'   => '-20°C',
			'psa_storage_reconstituted' => '2-8°C',
			'psa_typical_dose_mcg'      => '200-300 mcg',
			'psa_cycle_parameters'      => '4-6 weeks',
			'psa_amino_acid_count'      => '15',
		);
		return $meta[ $key ] ?? '';
	}
}
if ( ! function_exists( 'wp_trim_words' ) ) {
	function wp_trim_words( $text, $num_words = 55 ) {
		$words = explode( ' ', $text );
		return implode( ' ', array_slice( $words, 0, $num_words ) );
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

require_once dirname( __DIR__ ) . '/bootstrap.php';
require_once ABSPATH . 'includes/class-psa-post-type.php';
require_once ABSPATH . 'includes/class-psa-directory.php';

class DirectoryTest extends \PHPUnit\Framework\TestCase {

	/**
	 * Verify directory pagination constants.
	 */
	public function test_per_page_constants(): void {
		$this->assertSame( 12, PSA_Directory::PER_PAGE_DEFAULT );
		$this->assertSame( 100, PSA_Directory::PER_PAGE_MAX );
	}

	/**
	 * Verify format_compound() returns expected keys for full field set.
	 */
	public function test_format_compound_full_fields(): void {
		$mock_post = new \stdClass();
		$mock_post->ID            = 42;
		$mock_post->post_title    = 'BPC-157';
		$mock_post->post_name     = 'bpc-157';
		$mock_post->post_content  = 'A synthetic peptide for research purposes.';

		$result = PSA_Directory::format_compound( $mock_post, 'full' );

		// Check required top-level keys.
		$required_keys = array(
			'id', 'name', 'slug', 'url', 'categories', 'half_life', 'stability',
			'source', 'extras', 'excerpt', 'description', 'sequence',
			'molecular_weight', 'molecular_formula', 'aliases', 'mechanism',
			'pubchem_cid', 'solubility', 'vial_size_mg', 'storage_lyophilized',
			'storage_reconstituted', 'typical_dose_mcg', 'cycle_parameters',
			'amino_acid_count',
		);

		foreach ( $required_keys as $key ) {
			$this->assertArrayHasKey( $key, $result, "Missing key in full compound: {$key}" );
		}

		$this->assertSame( 42, $result['id'] );
		$this->assertSame( 'BPC-157', $result['name'] );
		$this->assertSame( 'bpc-157', $result['slug'] );
	}

	/**
	 * Verify format_compound() with 'basic' fields returns lightweight data.
	 */
	public function test_format_compound_basic_fields(): void {
		$mock_post = new \stdClass();
		$mock_post->ID            = 42;
		$mock_post->post_title    = 'BPC-157';
		$mock_post->post_name     = 'bpc-157';
		$mock_post->post_content  = 'A synthetic peptide.';

		$result = PSA_Directory::format_compound( $mock_post, 'basic' );

		// Basic should NOT include detail fields.
		$this->assertArrayHasKey( 'id', $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'vial_size_mg', $result );
		$this->assertArrayNotHasKey( 'sequence', $result );
		$this->assertArrayNotHasKey( 'mechanism', $result );
		$this->assertArrayNotHasKey( 'description', $result );
	}
}
