<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Fetch organisation subscriptions from the EasyDMARC API.
 *
 * @param bool $force_refresh Bypass cached value when true.
 * @return array|WP_Error Array of subscription DTOs or WP_Error on failure.
 */
function easysender_fetch_subscriptions( $force_refresh = false ) {
    $cache_key = 'easysender_subscriptions_cache';

    if ( ! $force_refresh ) {
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }
    }

    if ( ! function_exists( 'easysender_get_access_token' ) ) {
        easysender_log_api_error( 'subscriptions', 0, 'Helper easysender_get_access_token missing' );
        return new WP_Error( 'easysender_missing_helper', 'Auth helper missing.' );
    }

    $token = easysender_get_access_token( false );
    if ( is_wp_error( $token ) ) {
        return $token;
    }

    $url = ( defined( 'EASYSENDER_API_BASE_URL' )
        ? EASYSENDER_API_BASE_URL
        : 'https://sender-api.easydmarc.com' ) . '/api/v0.0/subscriptions';

    $args = [
        'timeout' => 25,
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ],
    ];

    $res  = wp_remote_get( $url, $args );
    $code = (int) wp_remote_retrieve_response_code( $res );
    $body = wp_remote_retrieve_body( $res );
    $json = json_decode( $body, true );

    // Retry once on 401 with a fresh token.
    if ( $code === 401 ) {
        $token = easysender_get_access_token( true );
        if ( ! is_wp_error( $token ) ) {
            $args['headers']['Authorization'] = 'Bearer ' . $token;
            $res  = wp_remote_get( $url, $args );
            $code = (int) wp_remote_retrieve_response_code( $res );
            $body = wp_remote_retrieve_body( $res );
            $json = json_decode( $body, true );
        }
    }

    if ( is_wp_error( $res ) ) {
        easysender_log_api_error( 'subscriptions', 0, $res->get_error_message() );
        return $res;
    }

    if ( $code < 200 || $code >= 300 || ! is_array( $json ) ) {
        $api_msg = ( is_array( $json ) && ! empty( $json['message'] ) )
            ? (string) $json['message']
            : $body;
        easysender_log_api_error( 'subscriptions', $code, $api_msg );
        return new WP_Error(
            'easysender_subscriptions_error',
            $api_msg ?: 'Failed to fetch subscriptions.',
            [ 'status' => $code ?: 400 ]
        );
    }

    // Normalize: the response might be a flat array of subs OR wrapped in a key.
    $subscriptions = $json;
    if ( isset( $json['data'] ) && is_array( $json['data'] ) ) {
        $subscriptions = $json['data'];
    } elseif ( isset( $json['items'] ) && is_array( $json['items'] ) && ! isset( $json['id'] ) ) {
        // If the top-level has 'items' but no 'id', it might be a wrapper — not a single sub.
        $subscriptions = $json['items'];
    }

    // If the response is a single subscription object (has 'id'), wrap it in an array.
    if ( isset( $subscriptions['id'] ) ) {
        $subscriptions = [ $subscriptions ];
    }

    set_transient( $cache_key, $subscriptions, 10 * MINUTE_IN_SECONDS );

    return $subscriptions;
}

/**
 * Determine whether a subscription DTO represents a trial subscription.
 *
 * Checks the product code/name for "trial". Does NOT use price-based fallback
 * to avoid misclassifying paid plans that happen to have no price data in items.
 *
 * @param array $subscription Single subscription DTO from the API.
 * @return bool
 */
function easysender_is_trial_subscription( $subscription ) {
    $is_trial = false;

    // Check product code for "trial" (top-level, if present).
    if ( ! empty( $subscription['product']['code'] ) ) {
        $is_trial = ( stripos( (string) $subscription['product']['code'], 'trial' ) !== false );
    }

    // Check product name for "trial" (top-level, if present).
    if ( ! $is_trial && ! empty( $subscription['product']['name'] ) ) {
        $is_trial = ( stripos( (string) $subscription['product']['name'], 'trial' ) !== false );
    }

    // Check items-level fields: planType, planName, price.name, price.plan.name.
    // The API nests product info inside items[], not at subscription level.
    if ( ! $is_trial && ! empty( $subscription['items'] ) && is_array( $subscription['items'] ) ) {
        foreach ( $subscription['items'] as $item ) {
            // planType is the most reliable indicator ("plan" vs "trial").
            if ( ! empty( $item['planType'] ) && stripos( (string) $item['planType'], 'trial' ) !== false ) {
                $is_trial = true;
                break;
            }
            // planName e.g. "Trial 100" vs "Verification 1,000".
            if ( ! empty( $item['planName'] ) && stripos( (string) $item['planName'], 'trial' ) !== false ) {
                $is_trial = true;
                break;
            }
            // price.name or price.plan.name.
            if ( ! empty( $item['price']['name'] ) && stripos( (string) $item['price']['name'], 'trial' ) !== false ) {
                $is_trial = true;
                break;
            }
            if ( ! empty( $item['price']['plan']['name'] ) && stripos( (string) $item['price']['plan']['name'], 'trial' ) !== false ) {
                $is_trial = true;
                break;
            }
        }
    }

    /**
     * Filter whether a subscription is considered a trial.
     *
     * @param bool  $is_trial     Whether the subscription is a trial.
     * @param array $subscription The full subscription DTO.
     */
    return (bool) apply_filters( 'easysender_is_trial_subscription', $is_trial, $subscription );
}

/**
 * Calculate days remaining until a date string (UTC).
 *
 * @param string $date_string ISO 8601 date-time string.
 * @return int|null Days remaining, or null if date is invalid/empty.
 */
function easysender_days_until( $date_string ) {
    if ( empty( $date_string ) ) {
        return null;
    }
    try {
        $expire = new DateTime( $date_string, new DateTimeZone( 'UTC' ) );
        $now    = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
        $diff   = $now->diff( $expire );

        // If the date is in the past, return negative days.
        return $diff->invert ? -$diff->days : $diff->days;
    } catch ( Exception $e ) {
        return null;
    }
}

/**
 * Check whether a cancelledAt value indicates actual cancellation.
 *
 * The API schema marks cancelledAt as required, so non-cancelled subscriptions
 * may have null, empty string, or a sentinel value. Only treat as cancelled
 * if the value is a parsable date-time that is in the past or present.
 *
 * @param mixed $cancelled_at The cancelledAt field value.
 * @return bool True if actually cancelled.
 */
function easysender_is_actually_cancelled( $cancelled_at ) {
    // null, empty string, false, 0 — not cancelled.
    if ( empty( $cancelled_at ) ) {
        return false;
    }
    // If it's not a string, ignore it.
    if ( ! is_string( $cancelled_at ) ) {
        return false;
    }
    // Try to parse as a date. If it's a valid date in the past/present, it's cancelled.
    try {
        $dt  = new DateTime( $cancelled_at, new DateTimeZone( 'UTC' ) );
        $now = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
        return $dt <= $now;
    } catch ( Exception $e ) {
        return false;
    }
}

/**
 * Check whether a single subscription is currently active.
 *
 * Active means: not actually cancelled, expireDate is in the future (or today),
 * and either has at least one item with status "active" or has no items array
 * (some APIs return subscriptions without item details).
 *
 * @param array $subscription Single subscription DTO.
 * @return bool
 */
function easysender_is_subscription_active( $subscription ) {
    // Check cancelled — only if actually cancelled (past date).
    if ( easysender_is_actually_cancelled( $subscription['cancelledAt'] ?? null ) ) {
        return false;
    }

    // Check expiry — must be in the future or today.
    $days_left = easysender_days_until( $subscription['expireDate'] ?? '' );
    if ( $days_left !== null && $days_left < 0 ) {
        return false;
    }
    // If expireDate is missing/invalid, don't reject — some subscriptions may not have one.

    // Check items if present.
    if ( ! empty( $subscription['items'] ) && is_array( $subscription['items'] ) ) {
        foreach ( $subscription['items'] as $item ) {
            if ( isset( $item['status'] ) && $item['status'] === 'active' ) {
                return true;
            }
        }
        // All items exist but none are active → not active.
        return false;
    }

    // No items array at all — consider active if not cancelled and not expired.
    return true;
}

/**
 * Get a comprehensive subscription status summary.
 *
 * @return array {
 *     @type bool       $has_credentials  Whether API credentials are configured.
 *     @type bool       $has_active_trial Whether an active trial subscription exists.
 *     @type bool       $has_active_plan  Whether an active paid subscription exists.
 *     @type array|null $trial            The trial subscription DTO, or null.
 *     @type array|null $plan             The paid subscription DTO, or null.
 *     @type int|null   $trial_days_left  Days until trial expires.
 *     @type int|null   $plan_days_left   Days until paid plan expires.
 *     @type bool       $should_block     Whether the block screen should be shown.
 *     @type string|null $block_reason    Reason for blocking: trial_expired, trial_cancelled, no_subscription.
 * }
 */
function easysender_get_subscription_status() {
    static $cached_result = null;
    if ( $cached_result !== null ) {
        return $cached_result;
    }

    $default = [
        'has_credentials'  => false,
        'has_active_trial' => false,
        'has_active_plan'  => false,
        'trial'            => null,
        'plan'             => null,
        'trial_days_left'  => null,
        'plan_days_left'   => null,
        'should_block'     => false,
        'block_reason'     => null,
    ];

    // Short-circuit: no credentials = no blocking (let the setup flow handle it).
    $options = get_option( 'easysender_settings', [] );
    if ( empty( $options['client_id'] ) || empty( $options['client_secret'] ) ) {
        $cached_result = $default;
        return $cached_result;
    }
    $default['has_credentials'] = true;

    $subscriptions = easysender_fetch_subscriptions();
    if ( is_wp_error( $subscriptions ) || ! is_array( $subscriptions ) ) {
        // On API failure, don't block — allow graceful degradation.
        $cached_result = $default;
        return $cached_result;
    }

    // If the array is empty, we have no subscriptions at all.
    if ( empty( $subscriptions ) ) {
        $default['should_block']  = true;
        $default['block_reason']  = 'no_subscription';
        $cached_result = $default;
        return $cached_result;
    }

    $trial_sub = null;
    $paid_sub  = null;
    $expired_trial    = null;
    $cancelled_trial  = null;

    foreach ( $subscriptions as $sub ) {
        if ( ! is_array( $sub ) ) {
            continue;
        }

        $is_trial = easysender_is_trial_subscription( $sub );
        $is_active = easysender_is_subscription_active( $sub );

        if ( $is_trial ) {
            if ( $is_active ) {
                $trial_sub = $sub;
            } else {
                if ( easysender_is_actually_cancelled( $sub['cancelledAt'] ?? null ) ) {
                    $cancelled_trial = $sub;
                } else {
                    $expired_trial = $sub;
                }
            }
        } else {
            if ( $is_active ) {
                $paid_sub = $sub;
            }
        }
    }

    $result = $default;

    if ( $trial_sub ) {
        $result['has_active_trial'] = true;
        $result['trial']            = $trial_sub;
        $result['trial_days_left']  = easysender_days_until( $trial_sub['expireDate'] ?? '' );
    }

    if ( $paid_sub ) {
        $result['has_active_plan'] = true;
        $result['plan']            = $paid_sub;
        $result['plan_days_left']  = easysender_days_until( $paid_sub['expireDate'] ?? '' );
    }

    // Determine if we should block.
    if ( ! $result['has_active_trial'] && ! $result['has_active_plan'] ) {
        $result['should_block'] = true;

        if ( $cancelled_trial ) {
            $result['block_reason'] = 'trial_cancelled';
            $result['trial']        = $cancelled_trial;
        } elseif ( $expired_trial ) {
            $result['block_reason'] = 'trial_expired';
            $result['trial']        = $expired_trial;
        } else {
            $result['block_reason'] = 'no_subscription';
        }
    }

    $cached_result = $result;
    return $cached_result;
}

