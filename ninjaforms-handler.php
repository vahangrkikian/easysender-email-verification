<?php
/**
 * Ninja Forms handler for EasyDMARC Email Verification
 *
 * Validates email fields in Ninja Forms submissions using easysender_do_email_check().
 *
 * Ninja Forms 3.x submission flow (Submission.php):
 *   1. ninja_forms_submit_data filter  → we set $form_data['errors']['fields'][$field_id]
 *   2. Per-field loop: validate_field() → process_field()
 *   3. After process_field(), Submission.php (line 301) checks:
 *        $this->_form_data['errors']['fields'][$field_id]
 *      and calls _respond() immediately to abort with field-level errors.
 *
 * NOTE: The submitted $form_data['fields'] from the client JSON does NOT include
 * the field 'type'. We must load field types from the database via
 * Ninja_Forms()->form($id)->get_fields() to reliably identify email fields.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'easysender_ninjaforms_is_enabled' ) ) {
    /**
     * Check whether Ninja Forms integration is enabled in plugin settings.
     *
     * @return bool
     */
    function easysender_ninjaforms_is_enabled() {
        $opts = get_option( 'easysender_settings', [] );
        return ( ! empty( $opts['enable_ninjaforms'] ) && $opts['enable_ninjaforms'] === '1' );
    }
}

if ( ! function_exists( 'easysender_ninjaforms_validate' ) ) {
    /**
     * Validate all email fields in a Ninja Forms submission.
     *
     * Field types are loaded from the database because the client-submitted
     * $form_data['fields'] JSON does not include a 'type' key per field.
     *
     * Errors are written to $form_data['errors']['fields'][$field_id], the exact
     * path Submission.php (line 301) reads after process_field() to abort submission.
     *
     * @param array $form_data Full Ninja Forms submission data.
     * @return array Modified $form_data with any validation errors set.
     */
    function easysender_ninjaforms_validate( $form_data ) {
        if ( ! easysender_ninjaforms_is_enabled() ) {
            return $form_data;
        }

        if ( empty( $form_data['fields'] ) || ! is_array( $form_data['fields'] ) ) {
            return $form_data;
        }

        // Strip the render-instance suffix from the form ID (e.g. "3_1" → 3).
        $raw_id  = isset( $form_data['id'] ) ? (string) $form_data['id'] : '';
        $form_id = (int) explode( '_', $raw_id )[0];

        if ( ! $form_id ) {
            return $form_data;
        }

        // Load registered field objects from the database — the only reliable
        // source of field types, since the submitted JSON omits them.
        $db_fields = Ninja_Forms()->form( $form_id )->get_fields();
        if ( empty( $db_fields ) ) {
            return $form_data;
        }

        // Customizable error messages from settings.
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

        foreach ( $db_fields as $db_field ) {
            $type = strtolower( (string) $db_field->get_setting( 'type' ) );

            // Only validate email-type fields.
            if ( $type !== 'email' ) {
                continue;
            }

            // The field ID as stored in the database — matches the key used in
            // $this->_form_data['fields'] and $this->_form_data['errors']['fields'].
            $field_id = $db_field->get_id();

            // Get the submitted value for this field.
            if ( ! isset( $form_data['fields'][ $field_id ]['value'] ) ) {
                continue;
            }

            $email = sanitize_email( trim( (string) $form_data['fields'][ $field_id ]['value'] ) );

            // Empty field: Ninja Forms handles required/optional itself.
            if ( $email === '' ) {
                continue;
            }

            // Fallback: if main helper is missing, do basic format check only.
            if ( ! function_exists( 'easysender_do_email_check' ) ) {
                if ( ! is_email( $email ) ) {
                    $form_data['errors']['fields'][ $field_id ] = $msg_invalid;
                }
                continue;
            }

            try {
                $check = easysender_do_email_check( $email );
            } catch ( Exception $e ) {
                if ( function_exists( 'easysender_log_api_error' ) ) {
                    easysender_log_api_error( 'ninjaforms', 0, $e->getMessage() );
                }
                continue;
            } catch ( Error $e ) {
                if ( function_exists( 'easysender_log_api_error' ) ) {
                    easysender_log_api_error( 'ninjaforms', 0, $e->getMessage() );
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
                        $reason = sprintf(
                            // translators: %s: Verification status returned by the API (e.g. deliverable, risky, undeliverable, unknown).
                            __( 'This email is marked as %s and is not allowed by the current rules.', 'easydmarc-email-verification' ),
                            $status
                        );
                    } else {
                        $reason = $msg_api;
                    }
                }

                // Write to the exact path Ninja Forms Submission.php (line 301) checks
                // after process_field() to abort submission with field-level errors.
                $form_data['errors']['fields'][ $field_id ] = $reason;
            }
        }

        return $form_data;
    }
}

add_action(
    'init',
    function () {
        // Only register hooks when integration is enabled and Ninja Forms is present.
        if ( ! easysender_ninjaforms_is_enabled() ) {
            return;
        }
        if ( ! function_exists( 'Ninja_Forms' ) ) {
            return;
        }

        add_filter( 'ninja_forms_submit_data', 'easysender_ninjaforms_validate', 10, 1 );
    }
);
