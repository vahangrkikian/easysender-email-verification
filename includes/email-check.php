<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'easysender_get_verify_url' ) ) {
    /**
     * Resolve the Verify URL from constant, legacy option, or hardcoded default.
     */
    function easysender_get_verify_url(): string {
        if ( defined( 'EASYSENDER_VERIFY_URL' ) && EASYSENDER_VERIFY_URL ) {
            return EASYSENDER_VERIFY_URL;
        }
        $settings = get_option( 'easysender_settings', [] );
        if ( ! empty( $settings['verify_url'] ) ) {
            return (string) $settings['verify_url'];
        }
        return 'https://sender-api.easydmarc.com/api/v0.0/verify/sync';
    }
}

if ( ! function_exists( 'easysender_do_email_check' ) ) {
    /**
     * Core email verification function shared by all form integrations.
     *
     * @param string $raw_email Raw email value from form submission.
     * @return array { ok: bool, status: string, reason?: string, details?: array }
     */
    function easysender_do_email_check( $raw_email ) {
        $settings           = get_option( 'easysender_settings', [] );
        $allow_on_api_error = ! empty( $settings['allow_on_api_error'] ) && $settings['allow_on_api_error'] === '1';

        // 0) Local format check — no API credit consumed.
        $email = strtolower( trim( sanitize_email( $raw_email ) ) );
        if ( ! $email || ! is_email( $email ) ) {
            $errs = get_option( 'easysender_error_messages', [] );
            $msg  = ! empty( $errs['msg_invalid'] ) ? $errs['msg_invalid'] : __( 'Invalid email address.', 'easydmarc-email-verification' );
            return [ 'ok' => false, 'status' => 'invalid_format', 'reason' => $msg ];
        }

        // 1) Dupe guard — 8-second transient to prevent re-calling on rapid resubmits.
        $dupe_key = 'easysender_last_checked_' . md5( $email );
        $recent   = get_transient( $dupe_key );
        if ( is_array( $recent ) ) return $recent;

        // 2) Auth token.
        if ( ! function_exists( 'easysender_get_access_token' ) ) {
            easysender_log_api_error( 'bootstrap', 0, 'Helper easysender_get_access_token missing' );
            $out = [ 'ok' => false, 'status' => 'auth_error', 'reason' => __( 'Auth helper missing.', 'easydmarc-email-verification' ) ];
            return $allow_on_api_error ? [ 'ok' => true, 'status' => 'allowed_on_error', 'details' => $out ] : $out;
        }

        $token = easysender_get_access_token( false );
        if ( is_wp_error( $token ) ) {
            easysender_log_api_error( 'token', 0, $token->get_error_message() );
            $errs = get_option( 'easysender_error_messages', [] );
            $msg  = ! empty( $errs['msg_api_error'] ) ? $errs['msg_api_error'] : __( 'Verification error. Please try again.', 'easydmarc-email-verification' );
            $out  = [ 'ok' => false, 'status' => 'auth_error', 'reason' => $msg ];
            set_transient( $dupe_key, $out, 8 );
            return $allow_on_api_error ? [ 'ok' => true, 'status' => 'allowed_on_error', 'details' => $out ] : $out;
        }

        // 3) Verify URL.
        $verify_url = easysender_get_verify_url();
        if ( empty( $verify_url ) ) {
            $out = [ 'ok' => false, 'status' => 'config_error', 'reason' => __( 'Verification URL is not configured.', 'easydmarc-email-verification' ) ];
            set_transient( $dupe_key, $out, 8 );
            return $allow_on_api_error ? [ 'ok' => true, 'status' => 'allowed_on_error', 'details' => $out ] : $out;
        }

        // 4) POST to verify endpoint.
        $payload = [ 'emailAddresses' => [ $email ] ];
        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];
        $response = wp_remote_post( $verify_url, [
            'headers' => $headers,
            'body'    => wp_json_encode( $payload ),
            'timeout' => 25,
        ] );
        $code = (int) wp_remote_retrieve_response_code( $response );

        // Retry once on 401 (expired token).
        if ( $code === 401 ) {
            $token = easysender_get_access_token( true );
            if ( ! is_wp_error( $token ) ) {
                $headers['Authorization'] = 'Bearer ' . $token;
                $response = wp_remote_post( $verify_url, [
                    'headers' => $headers,
                    'body'    => wp_json_encode( $payload ),
                    'timeout' => 25,
                ] );
                $code = (int) wp_remote_retrieve_response_code( $response );
            }
        }

        if ( is_wp_error( $response ) ) {
            easysender_log_api_error( 'transport', 0, $response->get_error_message() );
            $errs = get_option( 'easysender_error_messages', [] );
            $msg  = ! empty( $errs['msg_api_error'] ) ? $errs['msg_api_error'] : __( 'Verification error. Please try again.', 'easydmarc-email-verification' );
            $out  = [ 'ok' => false, 'status' => 'api_error', 'reason' => $msg ];
            set_transient( $dupe_key, $out, 8 );
            return $allow_on_api_error ? [ 'ok' => true, 'status' => 'allowed_on_error', 'details' => $out ] : $out;
        }

        $body_raw = wp_remote_retrieve_body( $response );
        $body     = json_decode( $body_raw, true );

        // Credit limit.
        if ( $code === 402 ) {
            $out = [ 'ok' => false, 'status' => 'quota', 'reason' => __( 'Verification service unavailable: credit limit reached. Please try again later.', 'easydmarc-email-verification' ) ];
            set_transient( $dupe_key, $out, 8 );
            return $allow_on_api_error ? [ 'ok' => true, 'status' => 'allowed_on_error', 'details' => $out ] : $out;
        }

        // Timeout with requestId.
        if ( $code === 408 && isset( $body['meta']['requestId'] ) ) {
            $out = [ 'ok' => false, 'status' => 'timeout', 'reason' => __( 'Verification timed out. Please try again.', 'easydmarc-email-verification' ) ];
            set_transient( $dupe_key, $out, 8 );
            return $allow_on_api_error ? [ 'ok' => true, 'status' => 'allowed_on_error', 'details' => $out ] : $out;
        }

        // Non-2xx or bad JSON — log raw API message, show generic message to users.
        if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
            $api_msg = '';
            if ( is_array( $body ) ) {
                if ( ! empty( $body['message'] ) ) {
                    $api_msg = (string) $body['message'];
                } elseif ( ! empty( $body['error'] ) ) {
                    $api_msg = is_string( $body['error'] ) ? $body['error'] : wp_json_encode( $body['error'] );
                } elseif ( ! empty( $body['errors'] ) && is_array( $body['errors'] ) ) {
                    $first = reset( $body['errors'] );
                    if ( is_array( $first ) && ! empty( $first['message'] ) ) {
                        $api_msg = (string) $first['message'];
                    }
                }
            }
            if ( ! $api_msg ) $api_msg = $body_raw;

            easysender_log_api_error( 'verify', $code, $api_msg ?: 'HTTP error' );

            // Use custom/generic message for users — raw API message stays in logs only.
            $errs   = get_option( 'easysender_error_messages', [] );
            $reason = ! empty( $errs['msg_api_error'] ) ? $errs['msg_api_error'] : __( 'Verification request failed.', 'easydmarc-email-verification' );

            $out = [ 'ok' => false, 'status' => 'api_error', 'reason' => $reason, 'details' => [ 'http_code' => $code, 'api_msg' => $api_msg ] ];
            set_transient( $dupe_key, $out, 8 );
            return $allow_on_api_error ? [ 'ok' => true, 'status' => 'allowed_on_error', 'details' => $out ] : $out;
        }

        // 5) Interpret result (support multiple API response shapes).
        $result = '';
        if ( isset( $body['results']['items'][0]['result'] ) ) {
            $result = $body['results']['items'][0]['result'];
        } elseif ( isset( $body['items'][0]['status'] ) ) {
            $result = $body['items'][0]['status'];
        } elseif ( isset( $body['status'] ) ) {
            $result = $body['status'];
        } elseif ( isset( $body['results'][0]['status'] ) ) {
            $result = $body['results'][0]['status'];
        } elseif ( isset( $body[0]['status'] ) ) {
            $result = $body[0]['status'];
        }
        $status = strtolower( (string) ( $result ?: 'unknown' ) );

        // Allowed statuses from settings; default to deliverable only if none configured.
        $settings = get_option( 'easysender_settings', [] );
        $allowed  = [];
        if ( ! empty( $settings['allow_deliverable'] ) )   $allowed[] = 'deliverable';
        if ( ! empty( $settings['allow_risky'] ) )         $allowed[] = 'risky';
        if ( ! empty( $settings['allow_undeliverable'] ) ) $allowed[] = 'undeliverable';
        if ( ! empty( $settings['allow_unknown'] ) )       $allowed[] = 'unknown';
        if ( empty( $allowed ) )                           $allowed   = [ 'deliverable' ];

        // Enrich details with requestId if present.
        $details    = $body;
        $request_id = $body['meta']['requestId'] ?? ( $body['results']['items'][0]['meta']['requestId'] ?? ( $body['items'][0]['meta']['requestId'] ?? null ) );
        if ( $request_id ) {
            if ( ! isset( $details['meta'] ) ) $details['meta'] = [];
            $details['meta']['requestId'] = $request_id;
        }

        if ( in_array( $status, $allowed, true ) ) {
            $out = [ 'ok' => true, 'status' => $status, 'details' => $details ];
            set_transient( $dupe_key, $out, 8 );
            return $out;
        }

        $errs        = get_option( 'easysender_error_messages', [] );
        $msg_invalid = ! empty( $errs['msg_invalid'] ) ? $errs['msg_invalid'] : __( 'Invalid email address.', 'easydmarc-email-verification' );
        $msg_risky   = ! empty( $errs['msg_risky'] )   ? $errs['msg_risky']   : __( 'Risky email address.', 'easydmarc-email-verification' );
        $reason      = ( $status === 'risky' ) ? $msg_risky : $msg_invalid;

        $out = [ 'ok' => false, 'status' => $status, 'reason' => $reason, 'details' => $details ];
        set_transient( $dupe_key, $out, 8 );
        return $out;
    }
}
