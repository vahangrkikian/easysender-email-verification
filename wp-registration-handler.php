<?php
/**
 * WordPress Registration Form handler for EasyDMARC Email Verification
 *
 * Validates the email address on the default WordPress user registration form
 * (/wp-login.php?action=register) using easysender_do_email_check().
 *
 * Also supports WordPress Multisite user signup (wp-signup.php) via the
 * wpmu_validate_user_signup filter.
 *
 * Hooks used:
 *   registration_errors       — filter, single-site registration, fires in register_new_user()
 *   wpmu_validate_user_signup — filter, multisite signup, fires in wpmu_validate_user_signup()
 *
 * Requires: WordPress 5.8+, PHP 7.4+
 * No plugin detection needed — these are core WordPress hooks.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'easysender_wp_registration_is_enabled' ) ) {
    /**
     * Check whether WordPress Registration integration is enabled in plugin settings.
     *
     * @return bool
     */
    function easysender_wp_registration_is_enabled() {
        $opts = get_option( 'easysender_settings', [] );
        return ( ! empty( $opts['enable_wp_registration'] ) && $opts['enable_wp_registration'] === '1' );
    }
}

if ( ! function_exists( 'easysender_wp_registration_check_email' ) ) {
    /**
     * Run easysender_do_email_check() and return a reason string on failure,
     * or empty string on pass / API error fallback.
     *
     * @param string $email Sanitized, lowercased email address.
     * @return string  Non-empty string = error message. '' = allow through.
     */
    function easysender_wp_registration_check_email( $email ) {
        $error_opts  = get_option( 'easysender_error_messages', [] );
        $msg_invalid = ! empty( $error_opts['msg_invalid'] )
                       ? $error_opts['msg_invalid']
                       : __( 'Please enter a valid email address.', 'easydmarc-email-verification' );
        $msg_risky   = ! empty( $error_opts['msg_risky'] )
                       ? $error_opts['msg_risky']
                       : __( 'Risky email address.', 'easydmarc-email-verification' );
        $msg_api     = ! empty( $error_opts['msg_api_error'] )
                       ? $error_opts['msg_api_error']
                       : __( 'Verification error. Please try again.', 'easydmarc-email-verification' );

        if ( ! function_exists( 'easysender_do_email_check' ) ) {
            return ( ! is_email( $email ) ) ? $msg_invalid : '';
        }

        try {
            $check = easysender_do_email_check( $email );
        } catch ( Exception $e ) {
            if ( function_exists( 'easysender_log_api_error' ) ) {
                easysender_log_api_error( 'wp_registration', 0, $e->getMessage() );
            }
            return '';
        } catch ( Error $e ) {
            if ( function_exists( 'easysender_log_api_error' ) ) {
                easysender_log_api_error( 'wp_registration', 0, $e->getMessage() );
            }
            return '';
        }

        if ( ! empty( $check['ok'] ) ) {
            return '';
        }

        $status = isset( $check['status'] ) ? strtolower( (string) $check['status'] ) : '';
        $reason = ! empty( $check['reason'] ) ? (string) $check['reason'] : '';

        if ( $reason === '' ) {
            if ( $status === 'risky' ) {
                $reason = $msg_risky;
            } elseif ( $status !== '' ) {
                $reason = sprintf(
                    // translators: %s: Verification status returned by the API.
                    __( 'This email is marked as %s and is not allowed by the current rules.', 'easydmarc-email-verification' ),
                    $status
                );
            } else {
                $reason = $msg_api;
            }
        }

        return $reason;
    }
}

if ( ! function_exists( 'easysender_wp_registration_validate' ) ) {
    /**
     * Validate the email on the default WordPress registration form.
     *
     * Called by the filter: registration_errors
     *
     * @param WP_Error $errors               Existing registration errors.
     * @param string   $sanitized_user_login Sanitized username.
     * @param string   $user_email           Sanitized email address.
     *
     * @return WP_Error
     */
    function easysender_wp_registration_validate( $errors, $sanitized_user_login, $user_email ) {
        // WordPress already checked format; if it's already invalid skip API call.
        if ( $errors->get_error_message( 'invalid_email' ) || $errors->get_error_message( 'email_exists' ) ) {
            return $errors;
        }

        $email  = strtolower( trim( sanitize_email( $user_email ) ) );
        if ( $email === '' ) {
            return $errors;
        }

        $reason = easysender_wp_registration_check_email( $email );
        if ( $reason !== '' ) {
            $errors->add( 'easysender_invalid_email', $reason );
        }

        return $errors;
    }
}

if ( ! function_exists( 'easysender_wpmu_registration_validate' ) ) {
    /**
     * Validate the email on the WordPress Multisite signup form.
     *
     * Called by the filter: wpmu_validate_user_signup
     *
     * @param array $result {
     *     @type string   $user_name  Sanitized username.
     *     @type string   $user_email Email address.
     *     @type WP_Error $errors     Accumulated errors.
     * }
     * @return array
     */
    function easysender_wpmu_registration_validate( $result ) {
        $errors = isset( $result['errors'] ) && ( $result['errors'] instanceof WP_Error )
            ? $result['errors']
            : new WP_Error();

        // Skip if multisite already flagged the email as invalid.
        if ( $errors->get_error_message( 'invalid_email' ) || $errors->get_error_message( 'email_exists' ) ) {
            $result['errors'] = $errors;
            return $result;
        }

        $email = isset( $result['user_email'] )
            ? strtolower( trim( sanitize_email( (string) $result['user_email'] ) ) )
            : '';

        if ( $email !== '' ) {
            $reason = easysender_wp_registration_check_email( $email );
            if ( $reason !== '' ) {
                $errors->add( 'easysender_invalid_email', $reason );
            }
        }

        $result['errors'] = $errors;
        return $result;
    }
}

add_action(
    'init',
    function () {
        if ( ! easysender_wp_registration_is_enabled() ) {
            return;
        }

        // registration_errors — filter, fires during single-site user registration.
        // 3 parameters: $errors (WP_Error), $sanitized_user_login, $user_email.
        // Must return $errors.
        add_filter(
            'registration_errors',
            'easysender_wp_registration_validate',
            10,
            3
        );

        // wpmu_validate_user_signup — filter, fires during multisite user signup.
        // 1 parameter: $result array with keys user_name, user_email, errors (WP_Error).
        // Must return $result.
        if ( is_multisite() ) {
            add_filter(
                'wpmu_validate_user_signup',
                'easysender_wpmu_registration_validate',
                10,
                1
            );
        }
    }
);
