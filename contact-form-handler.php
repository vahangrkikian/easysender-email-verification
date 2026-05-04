<?php
/**
 * Contact Form 7 handler for EasyDMARC Email Verification
 *
 * Moves all CF7-related validation into this file.
 * Uses easysender_do_email_check() to decide whether to accept an email.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'easysender_cf7_is_enabled' ) ) {
    /**
     * Check whether CF7 integration is enabled in plugin settings.
     *
     * @return bool
     */
    function easysender_cf7_is_enabled() {
        $opts = get_option( 'easysender_settings', [] );
        return ( ! empty( $opts['enable_cf7'] ) && $opts['enable_cf7'] === '1' );
    }
}

if ( ! function_exists( 'easysender_cf7_get_posted_email' ) ) {
    /**
     * Read an email value posted for a CF7 tag name.
     *
     * @param object $tag CF7 form tag object.
     * @return string Sanitized email or empty string.
     */
    function easysender_cf7_get_posted_email( $tag ) {
        $name = ( isset( $tag->name ) && is_string( $tag->name ) ) ? $tag->name : '';
        if ( $name === '' ) {
            return '';
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- CF7 handles nonce verification before validation hooks
        if ( ! isset( $_POST[ $name ] ) ) {
            return '';
        }

        // Sanitize before unslashing to satisfy PHPCS
        $raw = isset( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        // Normalize to string (in case of array) and ensure clean input
        $raw = is_array( $raw ) ? '' : (string) $raw;

        // Further sanitize as email
        $email = sanitize_email( $raw );
        $email = strtolower( trim( $email ) );

        return $email;
    }
}

if ( ! function_exists( 'easysender_cf7_validate_email_tag' ) ) {
    /**
     * Validate a CF7 email field using EasyDMARC rules.
     *
     * @param WPCF7_Validation $result Validation result.
     * @param object           $tag    CF7 form tag object.
     * @param string           $log_ctx Logging context label.
     * @return WPCF7_Validation
     */
    function easysender_cf7_validate_email_tag( $result, $tag, $log_ctx ) {
        $email = easysender_cf7_get_posted_email( $tag );

        // If field is empty, CF7 will handle required/not-required itself.
        if ( $email === '' ) {
            return $result;
        }

        // If helper missing, fall back to basic format validation only.
        if ( ! function_exists( 'easysender_do_email_check' ) ) {
            if ( ! is_email( $email ) ) {
                $msg = function_exists( 'wpcf7_get_validation_error_message' )
                    ? wpcf7_get_validation_error_message( 'invalid_email' )
                    : __( 'Invalid email address.', 'easydmarc-email-verification' );

                $result->invalidate( $tag, $msg );
            }
            return $result;
        }

        $check = easysender_do_email_check( $email );

        if ( empty( $check['ok'] ) ) {
            $status = isset( $check['status'] ) ? strtolower( (string) $check['status'] ) : '';
            $reason = ! empty( $check['reason'] ) ? (string) $check['reason'] : '';

            if ( $reason === '' && $status !== '' ) {
                /* translators: %s: Verification status returned by the API (e.g. deliverable, risky, undeliverable, unknown). */
                $template = __( 'This email is marked as %s and is not allowed by the current rules.', 'easydmarc-email-verification' );
                $reason   = sprintf( $template, $status );
            }

            if ( $reason === '' ) {
                $reason = function_exists( 'wpcf7_get_validation_error_message' )
                    ? wpcf7_get_validation_error_message( 'invalid_email' )
                    : __( 'Invalid email address.', 'easydmarc-email-verification' );
            }

            $result->invalidate( $tag, $reason );
        }

        return $result;
    }
}

add_action(
    'init',
    function () {
        // Only run when integration enabled and CF7 exists.
        if ( ! easysender_cf7_is_enabled() ) {
            return;
        }
        if ( ! function_exists( 'wpcf7' ) ) {
            return;
        }

        // Required email fields: [email*]
        add_filter(
            'wpcf7_validate_email*',
            function ( $result, $tag ) {
                try {
                    return easysender_cf7_validate_email_tag( $result, $tag, 'cf7_required' );
                } catch ( Exception $e ) {
                    if ( function_exists( 'easysender_log_api_error' ) ) {
                        easysender_log_api_error( 'cf7_required', 0, $e->getMessage() );
                    }
                    return $result;
                } catch ( Error $e ) { // PHP 7+ safety.
                    if ( function_exists( 'easysender_log_api_error' ) ) {
                        easysender_log_api_error( 'cf7_required', 0, $e->getMessage() );
                    }
                    return $result;
                }
            },
            10,
            2
        );

        // Optional email fields: [email]
        add_filter(
            'wpcf7_validate_email',
            function ( $result, $tag ) {
                try {
                    return easysender_cf7_validate_email_tag( $result, $tag, 'cf7_optional' );
                } catch ( Exception $e ) {
                    if ( function_exists( 'easysender_log_api_error' ) ) {
                        easysender_log_api_error( 'cf7_optional', 0, $e->getMessage() );
                    }
                    return $result;
                } catch ( Error $e ) {
                    if ( function_exists( 'easysender_log_api_error' ) ) {
                        easysender_log_api_error( 'cf7_optional', 0, $e->getMessage() );
                    }
                    return $result;
                }
            },
            10,
            2
        );
    }
);