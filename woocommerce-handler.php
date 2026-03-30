<?php
/**
 * WooCommerce handler for EasySender Email Verification
 *
 * Validates the billing email field during WooCommerce checkout using
 * easysender_do_email_check().
 *
 * Hook used: woocommerce_after_checkout_validation (action, WC 3.0+)
 * Fires after WooCommerce's own field validation (format, required) but before
 * the order is created. Adding an error to $errors prevents the order and
 * redisplays the checkout page with the message visible.
 *
 * Requires: WooCommerce 3.0+, PHP 7.4+
 * Detected via: class_exists('WooCommerce')
 * Plugin slug:  woocommerce/woocommerce.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'easysender_woocommerce_is_enabled' ) ) {
    /**
     * Check whether WooCommerce integration is enabled in plugin settings.
     *
     * @return bool
     */
    function easysender_woocommerce_is_enabled() {
        $opts = get_option( 'easysender_settings', [] );
        return ( ! empty( $opts['enable_woocommerce'] ) && $opts['enable_woocommerce'] === '1' );
    }
}

if ( ! function_exists( 'easysender_woocommerce_validate_checkout' ) ) {
    /**
     * Validate the billing email field during WooCommerce checkout.
     *
     * Called by the action: woocommerce_after_checkout_validation
     *
     * @param array    $fields All submitted checkout field values (sanitized by WC).
     * @param WP_Error $errors Accumulator — add errors here to block the order.
     *
     * @return void
     */
    function easysender_woocommerce_validate_checkout( $fields, $errors ) {
        $email = isset( $fields['billing_email'] )
            ? strtolower( trim( sanitize_email( (string) $fields['billing_email'] ) ) )
            : '';

        // Skip empty — WooCommerce's own required-field check handles this.
        if ( $email === '' ) {
            return;
        }

        // Customizable error messages from settings.
        $error_opts  = get_option( 'easysender_error_messages', [] );
        $msg_invalid = ! empty( $error_opts['msg_invalid'] )
                       ? $error_opts['msg_invalid']
                       : __( 'Please enter a valid email address.', 'easysender-email-verification' );
        $msg_risky   = ! empty( $error_opts['msg_risky'] )
                       ? $error_opts['msg_risky']
                       : __( 'Risky email address.', 'easysender-email-verification' );
        $msg_api     = ! empty( $error_opts['msg_api_error'] )
                       ? $error_opts['msg_api_error']
                       : __( 'Verification error. Please try again.', 'easysender-email-verification' );

        // Fallback: if main helper is missing, do basic format check only.
        if ( ! function_exists( 'easysender_do_email_check' ) ) {
            if ( ! is_email( $email ) ) {
                $errors->add( 'billing_email', $msg_invalid );
            }
            return;
        }

        try {
            $check = easysender_do_email_check( $email );
        } catch ( Exception $e ) {
            if ( function_exists( 'easysender_log_api_error' ) ) {
                easysender_log_api_error( 'woocommerce', 0, $e->getMessage() );
            }
            return;
        } catch ( Error $e ) {
            if ( function_exists( 'easysender_log_api_error' ) ) {
                easysender_log_api_error( 'woocommerce', 0, $e->getMessage() );
            }
            return;
        }

        if ( empty( $check['ok'] ) ) {
            $status = isset( $check['status'] ) ? strtolower( (string) $check['status'] ) : '';
            $reason = ! empty( $check['reason'] ) ? (string) $check['reason'] : '';

            if ( $reason === '' ) {
                if ( $status === 'risky' ) {
                    $reason = $msg_risky;
                } elseif ( $status !== '' ) {
                    // translators: %s: Verification status returned by the API.
                    $reason = sprintf(
                        __( 'This email is marked as %s and is not allowed by the current rules.', 'easysender-email-verification' ),
                        $status
                    );
                } else {
                    $reason = $msg_api;
                }
            }

            // Using 'billing_email' as the error code places the message next to
            // the billing email field in the WooCommerce checkout form.
            $errors->add( 'billing_email', $reason );
        }
    }
}

add_action(
    'init',
    function () {
        if ( ! easysender_woocommerce_is_enabled() ) {
            return;
        }

        // WooCommerce main class — present in all versions since 2.1.
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        // woocommerce_after_checkout_validation — action, fires per checkout submission.
        // Runs after WC's own field/required validation; before order creation.
        // 2 parameters: $fields (array of sanitized field values), $errors (WP_Error).
        add_action(
            'woocommerce_after_checkout_validation',
            'easysender_woocommerce_validate_checkout',
            10,
            2
        );
    }
);
