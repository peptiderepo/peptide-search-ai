<?php
/**
 * PubChem PUG REST API integration for molecular data enrichment.
 *
 * @package PeptideSearchAI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PSA_PubChem {

    const API_BASE = 'https://pubchem.ncbi.nlm.nih.gov/rest/pug';

    /**
     * Look up a peptide/compound by name and return molecular properties.
     * Results are cached in transients to respect PubChem rate limits.
     *
     * @param string $name Peptide or compound name.
     * @return array|WP_Error|null Array of properties, WP_Error on failure, or null if not found.
     */
    public static function lookup( $name ) {
        $options     = get_option( 'psa_settings', array() );
        $use_pubchem = $options['use_pubchem'] ?? '1';

        if ( '1' !== $use_pubchem ) {
            return null;
        }

        // Check cache first (respect PubChem's 5 req/sec limit).
        $cache_key = 'psa_pubchem_' . md5( strtolower( trim( $name ) ) );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            // We store 'not_found' string to differentiate from cache miss.
            if ( 'not_found' === $cached ) {
                return null;
            }
            if ( is_wp_error( $cached ) ) {
                return $cached;
            }
            return $cached;
        }

        // Step 1: Get the CID by compound name.
        $cid = self::get_cid_by_name( $name );
        if ( is_wp_error( $cid ) ) {
            set_transient( $cache_key, $cid, 1 * HOUR_IN_SECONDS );
            return $cid;
        }
        if ( ! $cid ) {
            set_transient( $cache_key, 'not_found', 12 * HOUR_IN_SECONDS );
            return null;
        }

        // Step 2: Get properties by CID.
        $properties = self::get_properties( $cid );
        if ( is_wp_error( $properties ) ) {
            set_transient( $cache_key, $properties, 1 * HOUR_IN_SECONDS );
            return $properties;
        }
        if ( ! $properties ) {
            set_transient( $cache_key, 'not_found', 12 * HOUR_IN_SECONDS );
            return null;
        }

        $properties['cid'] = $cid;

        // Cache successful lookups for 7 days.
        set_transient( $cache_key, $properties, PSA_Config::PUBCHEM_CACHE_TTL ?: 7 * DAY_IN_SECONDS );

        return $properties;
    }

    /**
     * Get PubChem Compound ID (CID) by name.
     *
     * @param string $name Compound name.
     * @return int|WP_Error|null CID, WP_Error on API failure, or null if not found.
     */
    private static function get_cid_by_name( $name ) {
        $url = self::API_BASE . '/compound/name/' . rawurlencode( $name ) . '/cids/JSON';

        $response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'pubchem_api_error', 'PubChem API request failed: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            return new WP_Error( 'pubchem_http_error', 'PubChem returned HTTP ' . $code );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        return isset( $body['IdentifierList']['CID'][0] )
            ? (int) $body['IdentifierList']['CID'][0]
            : null;
    }

    /**
     * Get molecular properties for a given CID.
     *
     * @param int $cid PubChem Compound ID.
     * @return array|WP_Error|null Properties array, WP_Error on failure, or null if not found.
     */
    private static function get_properties( $cid ) {
        $props = 'MolecularFormula,MolecularWeight,IUPACName,CanonicalSMILES,InChI';
        $url   = self::API_BASE . '/compound/cid/' . (int) $cid . '/property/' . $props . '/JSON';

        $response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'pubchem_api_error', 'PubChem API request failed: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            return new WP_Error( 'pubchem_http_error', 'PubChem returned HTTP ' . $code );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! isset( $body['PropertyTable']['Properties'][0] ) ) {
            return null;
        }

        $p = $body['PropertyTable']['Properties'][0];

        return array(
            'molecular_formula' => $p['MolecularFormula'] ?? '',
            'molecular_weight'  => isset( $p['MolecularWeight'] ) ? $p['MolecularWeight'] . ' Da' : '',
            'iupac_name'        => $p['IUPACName'] ?? '',
            'smiles'            => $p['CanonicalSMILES'] ?? '',
            'inchi'             => $p['InChI'] ?? '',
        );
    }
}
