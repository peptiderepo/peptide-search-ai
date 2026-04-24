<?php
/**
 * Browsable peptide directory — shortcode rendering and hook registration.
 *
 * What: Provides the [peptide_directory] shortcode and delegates REST to PSA_Directory_API.
 * Who calls it: WordPress shortcode parser, main plugin file via PSA_Directory::init().
 * Dependencies: PSA_Directory_API (REST endpoint), frontend JS/CSS.
 *
 * @package PeptideSearchAI
 * @since   4.0.0
 * @see     includes/class-psa-directory-api.php — REST endpoint and compound formatting.
 * @see     assets/js/psa-directory.js            — Frontend grid, filter, modal logic.
 * @see     assets/css/psa-directory.css           — Directory-specific styles.
 */
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PSA_Directory {

	/** Cards per page for directory pagination. */
	const PER_PAGE_DEFAULT = 12;
	const PER_PAGE_MAX     = 100;

	/** Register hooks. */
	public static function init(): void {
		add_shortcode( 'peptide_directory', array( __CLASS__, 'render_shortcode' ) );
		add_action( 'rest_api_init', array( 'PSA_Directory_API', 'register_rest_routes' ) );
	}

	/**
	 * Render the [peptide_directory] shortcode.
	 * Outputs a container div populated by frontend JS via REST.
	 *
	 * @param array $atts Shortcode attributes (reserved for future use).
	 * @return string HTML output.
	 */
	public static function render_shortcode( $atts ): string {
		$terms = get_terms(
			array(
				'taxonomy'   => 'peptide_category',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		$categories = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$categories[] = array(
					'slug' => $term->slug,
					'name' => $term->name,
				);
			}
		}

		wp_localize_script(
			'psa-directory',
			'psaDirectory',
			array(
				'restUrl'    => esc_url_raw( rest_url( 'peptide-search-ai/v1/compounds' ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'categories' => $categories,
				'perPage'    => self::PER_PAGE_DEFAULT,
				'i18n'       => array(
					'title'             => __( 'Peptide Directory', 'peptide-search-ai' ),
					'subtitle'          => __( 'Browse reagents with detailed research parameters.', 'peptide-search-ai' ),
					'searchPlaceholder' => __( 'Search peptides...', 'peptide-search-ai' ),
					'showAll'           => __( 'Show All', 'peptide-search-ai' ),
					'loadMore'          => __( 'Load More', 'peptide-search-ai' ),
					'loading'           => __( 'Loading...', 'peptide-search-ai' ),
					'noResults'         => __( 'No peptides found matching your criteria.', 'peptide-search-ai' ),
					'viewDetails'       => __( 'View Research Details', 'peptide-search-ai' ),
					'viewFullPage'      => __( 'View Full Page', 'peptide-search-ai' ),
					'close'             => __( 'Close', 'peptide-search-ai' ),
					'halfLife'          => __( 'Half-Life', 'peptide-search-ai' ),
					'stability'         => __( 'Stability', 'peptide-search-ai' ),
					'solubility'        => __( 'Solubility', 'peptide-search-ai' ),
					'vialSize'          => __( 'Vial Size', 'peptide-search-ai' ),
					'storageLyo'        => __( 'Storage (Lyophilized)', 'peptide-search-ai' ),
					'storageRecon'      => __( 'Storage (Reconstituted)', 'peptide-search-ai' ),
					'molecularWeight'   => __( 'Molecular Weight', 'peptide-search-ai' ),
					'formula'           => __( 'Formula', 'peptide-search-ai' ),
					'typicalDose'       => __( 'Typical Dose', 'peptide-search-ai' ),
					'cycleParams'       => __( 'Cycle Parameters', 'peptide-search-ai' ),
					'sequence'          => __( 'Sequence', 'peptide-search-ai' ),
					'singleReagents'    => __( 'Single Reagents', 'peptide-search-ai' ),
					'protocolModels'    => __( 'Protocol Models', 'peptide-search-ai' ),
					'comingSoon'        => __( 'Coming Soon', 'peptide-search-ai' ),
					'researchParams'    => __( 'Research Parameters', 'peptide-search-ai' ),
					'communityObs'      => __( 'Community Observations', 'peptide-search-ai' ),
					'pubchemLink'       => __( 'View on PubChem', 'peptide-search-ai' ),
					'copySequence'      => __( 'Copy', 'peptide-search-ai' ),
					'copied'            => __( 'Copied!', 'peptide-search-ai' ),
				),
			)
		);

		ob_start();
		?>
		<div id="psa-directory-root" class="psa-dir-wrap" aria-label="<?php esc_attr_e( 'Peptide Directory', 'peptide-search-ai' ); ?>">
			<noscript>
				<p><?php esc_html_e( 'Please enable JavaScript to browse the peptide directory.', 'peptide-search-ai' ); ?></p>
			</noscript>
		</div>
		<?php
		return ob_get_clean();
	}

	// ── Backward-compatible proxies (REST moved to PSA_Directory_API) ───

	/** @see PSA_Directory_API::register_rest_routes() */
	public static function register_rest_routes(): void {
		PSA_Directory_API::register_rest_routes(); }

	/** @see PSA_Directory_API::rest_compounds() */
	public static function rest_compounds( $request ) {
		return PSA_Directory_API::rest_compounds( $request ); }

	/** @see PSA_Directory_API::format_compound() */
	public static function format_compound( $post, string $fields = 'full' ): array {
		return PSA_Directory_API::format_compound( $post, $fields ); }
}
