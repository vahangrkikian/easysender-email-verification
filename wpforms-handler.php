<?php
/**
 * WPForms handler for EasySender Email Verification
 *
 * Validates email fields in WPForms submissions using easysender_do_email_check().
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'easysender_wpforms_is_enabled' ) ) {
    /**
     * Check whether WPForms integration is enabled in plugin settings.
     *
     * @return bool
     */
    function easysender_wpforms_is_enabled() {
        $opts = get_option( 'easysender_settings', [] );
        return ( ! empty( $opts['enable_wpforms'] ) && $opts['enable_wpforms'] === '1' );
    }
}

if ( ! function_exists( 'easysender_wpforms_validate' ) ) {
    /**
     * Validate all email fields in a WPForms submission.
     *
     * @param array $fields    Processed field data keyed by field ID.
     * @param array $entry     Raw submitted entry data.
     * @param array $form_data Form configuration array.
     */
    function easysender_wpforms_validate( $fields, $entry, $form_data ) {
        if ( ! easysender_wpforms_is_enabled() ) {
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

        $form_id = isset( $form_data['id'] ) ? (int) $form_data['id'] : 0;

        foreach ( $fields as $field_id => $field ) {
            $type = isset( $field['type'] ) ? strtolower( (string) $field['type'] ) : '';

            // Only process email-type fields.
            if ( $type !== 'email' ) {
                continue;
            }

            $email = isset( $field['value'] ) ? sanitize_email( trim( (string) $field['value'] ) ) : '';

            // Empty field: WPForms handles required/optional itself.
            if ( $email === '' ) {
                continue;
            }

            // Fallback: if main helper is missing, do basic format check only.
            if ( ! function_exists( 'easysender_do_email_check' ) ) {
                if ( ! is_email( $email ) ) {
                    wpforms()->process->errors[ $form_id ][ $field_id ] = $msg_invalid;
                }
                continue;
            }

            try {
                $check = easysender_do_email_check( $email );
            } catch ( Exception $e ) {
                if ( function_exists( 'easysender_log_api_error' ) ) {
                    easysender_log_api_error( 'wpforms', 0, $e->getMessage() );
                }
                continue;
            } catch ( Error $e ) {
                if ( function_exists( 'easysender_log_api_error' ) ) {
                    easysender_log_api_error( 'wpforms', 0, $e->getMessage() );
                }
                continue;
            }

            if ( empty( $check['ok'] ) ) {
                $status = isset( $check['status'] ) ? strtolower( (string) $check['status'] ) : '';
                $reason = ! empty( $check['reason'] ) ? (string) $check['reason'] : '';

                if ( $reason === '' ) {
                    if ( $status === 'risky' ) {
                        $reason = $msg_risky;
                    } elseif ( $status !== '' ) {
                        // translators: %s: Verification status returned by the API (e.g. deliverable, risky, undeliverable, unknown).
                        $reason = sprintf(
                            __( 'This email is marked as %s and is not allowed by the current rules.', 'easysender-email-verification' ),
                            $status
                        );
                    } else {
                        $reason = $msg_api;
                    }
                }

                wpforms()->process->errors[ $form_id ][ $field_id ] = $reason;
            }
        }
    }
}

add_action(
    'init',
    function () {
        // Only register hooks when integration is enabled and WPForms is present.
        if ( ! easysender_wpforms_is_enabled() ) {
            return;
        }
        if ( ! function_exists( 'wpforms' ) ) {
            return;
        }

        add_action( 'wpforms_process', 'easysender_wpforms_validate', 10, 3 );
    }
);
