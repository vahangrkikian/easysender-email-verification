<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Fetch email verification plans from EasyDMARC API.
 *
 * @return array { plans: array, currency: string, currency_sign: string }
 */
function easysender_get_plans_catalog() {
    $cache_key = 'easysender_plans_cache';
    $cached    = get_transient( $cache_key );
    if ( is_array( $cached ) ) {
        return $cached;
    }

    $org_id = easysender_get_organisation_id();
    if ( empty( $org_id ) ) {
        return [ 'plans' => [], 'currency' => 'USD', 'currency_sign' => '$' ];
    }

    $url = add_query_arg( 'organisation_id', $org_id, 'https://api2.easydmarc.com/v1/email-verification-plans' );

    $response = wp_remote_get( $url, [
        'timeout' => 15,
        'headers' => [ 'Accept' => 'application/json' ],
    ] );

    if ( is_wp_error( $response ) ) {
        easysender_log_api_error( 'plans', 0, $response->get_error_message() );
        return [ 'plans' => [], 'currency' => 'USD', 'currency_sign' => '$' ];
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code < 200 || $code >= 300 || ! is_array( $body ) || empty( $body['data'] ) ) {
        easysender_log_api_error( 'plans', $code, 'Empty or invalid plans response' );
        return [ 'plans' => [], 'currency' => 'USD', 'currency_sign' => '$' ];
    }

    $currency      = ! empty( $body['currency'] ) ? $body['currency'] : 'USD';
    $currency_sign = '$';
    if ( $currency === 'EUR' ) $currency_sign = '€';
    if ( $currency === 'GBP' ) $currency_sign = '£';

    $plans    = [];
    $all_data = $body['data'];

    // Sort by verification_count ascending.
    usort( $all_data, function ( $a, $b ) {
        $a_count = isset( $a['features']['verification_count'] ) ? (int) $a['features']['verification_count'] : 0;
        $b_count = isset( $b['features']['verification_count'] ) ? (int) $b['features']['verification_count'] : 0;
        return $a_count - $b_count;
    } );

    // Find the max verification count to mark the middle-high plan as popular.
    $counts = array_map( function ( $p ) {
        return isset( $p['features']['verification_count'] ) ? (int) $p['features']['verification_count'] : 0;
    }, $all_data );
    $popular_target = 50000; // Default popular tier.

    foreach ( $all_data as $plan ) {
        $verif_count = isset( $plan['features']['verification_count'] ) ? (int) $plan['features']['verification_count'] : 0;
        if ( $verif_count <= 0 ) continue;

        // Extract first monthly and first yearly price.
        $monthly_price = null;
        $yearly_price  = null;

        if ( ! empty( $plan['prices'] ) && is_array( $plan['prices'] ) ) {
            foreach ( $plan['prices'] as $price ) {
                $freq = isset( $price['frequency'] ) ? strtolower( $price['frequency'] ) : '';
                if ( ! $monthly_price && ( $freq === 'm' || $freq === 'month' ) ) {
                    $monthly_price = $price;
                }
                if ( ! $yearly_price && ( $freq === 'y' || $freq === 'year' ) ) {
                    $yearly_price = $price;
                }
            }
        }

        // Use monthly price as the primary display price, fall back to yearly / 12.
        $display_amount = 0;
        $price_id       = '';
        if ( $monthly_price ) {
            $display_amount = (float) $monthly_price['amount'];
            $price_id       = $monthly_price['objectId'];
        } elseif ( $yearly_price ) {
            $display_amount = round( (float) $yearly_price['amount'] / 12, 2 );
            $price_id       = $yearly_price['objectId'];
        }

        $cost_per = $verif_count > 0 ? round( $display_amount / $verif_count, 6 ) : 0;

        $plans[] = [
            'id'                      => $plan['objectId'],
            'plan_id'                 => $plan['objectId'],
            'label'                   => number_format( $verif_count ),
            'verifications_per_month' => $verif_count,
            'price'                   => $display_amount,
            'cost_per_verification'   => $cost_per,
            'popular'                 => ( $verif_count === $popular_target ),
            'currency'                => $currency,
            'currency_sign'           => ! empty( $plan['currency_sign'] ) ? $plan['currency_sign'] : $currency_sign,
            'monthly_price_id'        => $monthly_price ? $monthly_price['objectId'] : '',
            'monthly_amount'          => $monthly_price ? (float) $monthly_price['amount'] : 0,
            'yearly_price_id'         => $yearly_price ? $yearly_price['objectId'] : '',
            'yearly_amount'           => $yearly_price ? (float) $yearly_price['amount'] : 0,
        ];
    }

    // Calculate savings_pct relative to the most expensive per-verification cost.
    if ( ! empty( $plans ) ) {
        $max_cpv = 0;
        foreach ( $plans as $p ) {
            if ( $p['cost_per_verification'] > $max_cpv ) {
                $max_cpv = $p['cost_per_verification'];
            }
        }
        if ( $max_cpv > 0 ) {
            foreach ( $plans as &$p ) {
                $p['savings_pct'] = (int) round( ( 1 - $p['cost_per_verification'] / $max_cpv ) * 100 );
            }
            unset( $p );
        }
    }

    $catalog = [
        'plans'         => $plans,
        'currency'      => $currency,
        'currency_sign' => $currency_sign,
    ];

    set_transient( $cache_key, $catalog, 15 * MINUTE_IN_SECONDS );

    return apply_filters( 'easysender_plans_catalog', $catalog );
}

/**
 * Get the organisation ID (ownerId) from the EasySender auth/me endpoint.
 *
 * Calls GET /api/v0.0/auth/me with the current bearer token and caches
 * the ownerId in a transient scoped to the client_id hash.
 *
 * @return string Organisation ID or empty string on failure.
 */
function easysender_get_organisation_id() {
    // Allow override via settings.
    $settings = get_option( 'easysender_settings', [] );
    if ( ! empty( $settings['organisation_id'] ) ) {
        return sanitize_text_field( $settings['organisation_id'] );
    }

    // Check transient cache first.
    $client_id_raw = ! empty( $settings['client_id'] ) ? easysender_decrypt( $settings['client_id'] ) : '';
    $cache_key     = 'easysender_org_id_' . substr( md5( $client_id_raw ), 0, 10 );
    $cached        = get_transient( $cache_key );
    if ( ! empty( $cached ) && is_string( $cached ) ) {
        return $cached;
    }

    // Get bearer token.
    $token = easysender_get_access_token( false );
    if ( is_wp_error( $token ) || empty( $token ) ) {
        return '';
    }

    // Call /api/v0.0/auth/me.
    $me_url  = EASYSENDER_API_BASE_URL . '/api/v0.0/auth/me';
    $response = wp_remote_get( $me_url, [
        'timeout' => 15,
        'headers' => [
            'Accept'        => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ],
    ] );

    if ( is_wp_error( $response ) ) {
        easysender_log_api_error( 'auth/me', 0, $response->get_error_message() );
        return '';
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code < 200 || $code >= 300 || empty( $body['ownerId'] ) ) {
        easysender_log_api_error( 'auth/me', $code, 'Missing ownerId in response' );
        return '';
    }

    $org_id = sanitize_text_field( $body['ownerId'] );

    // Cache for 1 hour — ownerId doesn't change often.
    set_transient( $cache_key, $org_id, HOUR_IN_SECONDS );

    return $org_id;
}
