<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Derive sub-flags from an email address and its check result.
 *
 * Centralises flag derivation so swapping in real API field names later
 * is a one-place change.
 *
 * @param string $email        The email address.
 * @param array  $check_result Return value from easysender_do_email_check().
 * @return array
 */
function easysender_csv_derive_flags( $email, $check_result ) {
    $details = isset( $check_result['details'] ) && is_array( $check_result['details'] ) ? $check_result['details'] : [];
    $status  = isset( $check_result['status'] ) ? (string) $check_result['status'] : 'unknown';

    // --- free_account ---
    $is_free = false;
    if ( isset( $details['free'] ) ) {
        $is_free = (bool) $details['free'];
    } elseif ( isset( $details['freemail'] ) ) {
        $is_free = (bool) $details['freemail'];
    } else {
        $free_domains = [
            'gmail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'icloud.com',
            'aol.com', 'proton.me', 'protonmail.com', 'mail.ru', 'yandex.com', 'gmx.com',
        ];
        $domain = substr( strrchr( $email, '@' ), 1 );
        if ( $domain && in_array( strtolower( $domain ), $free_domains, true ) ) {
            $is_free = true;
        }
    }

    // --- role_account ---
    $is_role = false;
    if ( isset( $details['role'] ) ) {
        $is_role = (bool) $details['role'];
    } else {
        $local = strtolower( substr( $email, 0, (int) strpos( $email, '@' ) ) );
        $role_locals = [
            'info', 'support', 'admin', 'noreply', 'no-reply', 'sales', 'contact',
            'hello', 'help', 'abuse', 'postmaster', 'webmaster', 'billing', 'hr',
            'jobs', 'careers',
        ];
        if ( in_array( $local, $role_locals, true ) ) {
            $is_role = true;
        }
    }

    // --- disposable ---
    $is_disposable = false;
    if ( isset( $details['disposable'] ) ) {
        $is_disposable = (bool) $details['disposable'];
    } else {
        $disposable_domains = [
            'mailinator.com', 'guerrillamail.com', '10minutemail.com',
            'tempmail.com', 'throwaway.email', 'trashmail.com',
        ];
        $domain = substr( strrchr( $email, '@' ), 1 );
        if ( $domain && in_array( strtolower( $domain ), $disposable_domains, true ) ) {
            $is_disposable = true;
        }
    }

    // --- full_inbox ---
    $is_full_inbox = false;
    if ( isset( $details['mailbox_full'] ) ) {
        $is_full_inbox = (bool) $details['mailbox_full'];
    } elseif ( isset( $details['inbox_full'] ) ) {
        $is_full_inbox = (bool) $details['inbox_full'];
    } elseif ( isset( $details['reason'] ) && is_string( $details['reason'] ) && stripos( $details['reason'], 'full' ) !== false ) {
        $is_full_inbox = true;
    }

    // --- mx_active ---
    $mx_active = true;
    if ( isset( $details['mx_found'] ) ) {
        $mx_active = (bool) $details['mx_found'];
    } elseif ( isset( $details['has_mx'] ) ) {
        $mx_active = (bool) $details['has_mx'];
    } elseif ( in_array( $status, [ 'undeliverable', 'unknown' ], true ) ) {
        $mx_active = false;
    }

    return [
        'free_account'  => $is_free,
        'role_account'  => $is_role,
        'disposable'    => $is_disposable,
        'full_inbox'    => $is_full_inbox,
        'mx_active'     => $mx_active,
        'format_valid'  => ( $status !== 'invalid_format' ),
    ];
}

// ---- AJAX: Upload CSV ----
add_action( 'wp_ajax_easysender_bulk_upload_csv', 'easysender_bulk_upload_csv' );
function easysender_bulk_upload_csv() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
    }
    check_ajax_referer( 'easysender_bulk', '_wpnonce' );

    if ( empty( $_FILES['csv_file'] ) ) {
        wp_send_json_error( [ 'message' => 'No file uploaded.' ], 400 );
    }

    $file = $_FILES['csv_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

    // Validate extension.
    $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
    if ( $ext !== 'csv' ) {
        wp_send_json_error( [ 'message' => 'Only CSV files are supported. Please upload a .csv file.' ], 400 );
    }

    // Validate MIME type.
    $allowed_mimes = [ 'text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/octet-stream' ];
    if ( ! empty( $file['type'] ) && ! in_array( $file['type'], $allowed_mimes, true ) ) {
        wp_send_json_error( [ 'message' => 'Invalid file type. Please upload a valid CSV file.' ], 400 );
    }

    // Validate size (10 MB).
    if ( $file['size'] > 10 * 1024 * 1024 ) {
        wp_send_json_error( [ 'message' => 'File exceeds the 10 MB size limit.' ], 400 );
    }

    // Validate upload error.
    if ( $file['error'] !== UPLOAD_ERR_OK ) {
        wp_send_json_error( [ 'message' => 'File upload failed. Please try again.' ], 400 );
    }

    $mode = 'header';
    if ( isset( $_POST['mode'] ) ) {
        $mode = sanitize_key( wp_unslash( $_POST['mode'] ) );
    }
    if ( ! in_array( $mode, [ 'header', 'plain' ], true ) ) {
        $mode = 'header';
    }

    // Stream-parse the CSV line by line.
    $handle = fopen( $file['tmp_name'], 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
    if ( ! $handle ) {
        wp_send_json_error( [ 'message' => 'Could not read the uploaded file.' ], 400 );
    }

    $emails       = [];
    $seen         = [];
    $duplicates   = 0;
    $line_num     = 0;
    $email_col    = null; // For header mode: index of detected email column.

    while ( ( $row = fgetcsv( $handle ) ) !== false ) {
        $line_num++;

        // Hard cap check.
        if ( count( $emails ) > 500000 ) {
            fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            wp_send_json_error( [ 'message' => 'File exceeds the 500,000 email limit.' ], 400 );
        }

        if ( $mode === 'header' ) {
            // First row: detect email column.
            if ( $line_num === 1 ) {
                // Try header label match first.
                foreach ( $row as $idx => $cell ) {
                    if ( preg_match( '/email|e-mail|mail/i', trim( $cell ) ) ) {
                        $email_col = $idx;
                        break;
                    }
                }
                // Skip header row — don't extract emails from it.
                continue;
            }

            // Second data row: if column not found by header, find first cell with @.
            if ( $line_num === 2 && $email_col === null ) {
                foreach ( $row as $idx => $cell ) {
                    if ( strpos( $cell, '@' ) !== false ) {
                        $email_col = $idx;
                        break;
                    }
                }
            }

            if ( $email_col === null || ! isset( $row[ $email_col ] ) ) {
                continue;
            }

            $candidate = strtolower( trim( sanitize_email( $row[ $email_col ] ) ) );
            if ( $candidate === '' ) continue;

            if ( isset( $seen[ $candidate ] ) ) {
                $duplicates++;
                continue;
            }
            $seen[ $candidate ] = true;
            $emails[] = $candidate;

        } else {
            // Plain mode: first cell matching is_email().
            foreach ( $row as $cell ) {
                $cell = trim( $cell );
                if ( $cell === '' ) continue;
                $candidate = strtolower( trim( sanitize_email( $cell ) ) );
                if ( $candidate !== '' && is_email( $candidate ) ) {
                    if ( isset( $seen[ $candidate ] ) ) {
                        $duplicates++;
                    } else {
                        $seen[ $candidate ] = true;
                        $emails[] = $candidate;
                    }
                    break; // One email per row.
                }
            }
        }
    }
    fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

    if ( empty( $emails ) ) {
        wp_send_json_error( [ 'message' => 'No email addresses found in the uploaded file.' ], 400 );
    }

    if ( count( $emails ) > 500000 ) {
        wp_send_json_error( [ 'message' => 'File exceeds the 500,000 email limit.' ], 400 );
    }

    $job_id = wp_generate_uuid4();

    $job = [
        'created_at'         => time(),
        'filename'           => sanitize_file_name( $file['name'] ),
        'total'              => count( $emails ),
        'duplicates_removed' => $duplicates,
        'emails'             => array_values( $emails ),
        'results'            => [],
        'cursor'             => 0,
        'status'             => 'pending',
    ];

    set_transient( 'easysender_bulk_job_' . $job_id, $job, 2 * HOUR_IN_SECONDS );

    // Build preview (first 5).
    $preview = [];
    $limit   = min( 5, count( $emails ) );
    for ( $i = 0; $i < $limit; $i++ ) {
        $preview[] = [ 'row' => $i + 1, 'email' => $emails[ $i ] ];
    }

    wp_send_json_success( [
        'job_id'             => $job_id,
        'total_emails'       => count( $emails ),
        'duplicates_removed' => $duplicates,
        'preview'            => $preview,
    ] );
}

// ---- AJAX: Verify chunk ----
add_action( 'wp_ajax_easysender_bulk_verify_chunk', 'easysender_bulk_verify_chunk' );
function easysender_bulk_verify_chunk() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
    }
    check_ajax_referer( 'easysender_bulk', '_wpnonce' );

    $job_id = '';
    if ( isset( $_POST['job_id'] ) ) {
        $job_id = sanitize_text_field( wp_unslash( $_POST['job_id'] ) );
    }
    if ( ! $job_id ) {
        wp_send_json_error( [ 'message' => 'Missing job ID.' ], 400 );
    }

    $transient_key = 'easysender_bulk_job_' . sanitize_key( $job_id );
    $job           = get_transient( $transient_key );
    if ( ! is_array( $job ) ) {
        wp_send_json_error( [ 'message' => 'Job expired or not found.' ], 404 );
    }

    if ( $job['status'] === 'complete' ) {
        wp_send_json_success( [
            'cursor'   => $job['total'],
            'total'    => $job['total'],
            'progress' => 1,
            'complete' => true,
            'breakdown' => easysender_bulk_compute_breakdown( $job ),
        ] );
        return;
    }

    $job['status'] = 'processing';
    $chunk_size    = 5;
    $cursor        = (int) $job['cursor'];
    $total         = (int) $job['total'];
    $end           = min( $cursor + $chunk_size, $total );

    for ( $i = $cursor; $i < $end; $i++ ) {
        $email = $job['emails'][ $i ];
        $check = easysender_do_email_check( $email );
        $flags = easysender_csv_derive_flags( $email, $check );

        $job['results'][] = [
            'email'         => $email,
            'status'        => isset( $check['status'] ) ? $check['status'] : 'unknown',
            'reason'        => isset( $check['reason'] ) ? $check['reason'] : '',
            'free_account'  => $flags['free_account'],
            'role_account'  => $flags['role_account'],
            'disposable'    => $flags['disposable'],
            'full_inbox'    => $flags['full_inbox'],
            'mx_active'     => $flags['mx_active'],
            'format_valid'  => $flags['format_valid'],
        ];

        // Abort on quota exhaustion.
        if ( isset( $check['status'] ) && $check['status'] === 'quota' ) {
            $job['status'] = 'error';
            $job['error']  = 'credit_quota';
            $job['cursor'] = $i + 1;
            set_transient( $transient_key, $job, 2 * HOUR_IN_SECONDS );
            wp_send_json_error( [
                'message' => 'Credit quota exhausted. Verification stopped.',
                'error'   => 'credit_quota',
                'cursor'  => $job['cursor'],
                'total'   => $total,
            ] );
            return;
        }
    }

    $job['cursor'] = $end;

    // Check completion.
    if ( $end >= $total ) {
        $job['status'] = 'complete';
        set_transient( $transient_key, $job, 2 * HOUR_IN_SECONDS );

        wp_send_json_success( [
            'cursor'    => $total,
            'total'     => $total,
            'progress'  => 1,
            'complete'  => true,
            'breakdown' => easysender_bulk_compute_breakdown( $job ),
        ] );
        return;
    }

    set_transient( $transient_key, $job, 2 * HOUR_IN_SECONDS );

    wp_send_json_success( [
        'cursor'   => $end,
        'total'    => $total,
        'progress' => round( $end / $total, 4 ),
        'complete' => false,
    ] );
}

/**
 * Compute breakdown counts from completed job results.
 */
function easysender_bulk_compute_breakdown( $job ) {
    $counts = [
        'deliverable'      => 0,
        'risky'            => 0,
        'undeliverable'    => 0,
        'unknown'          => 0,
        'free_account'     => 0,
        'role_account'     => 0,
        'disposable'       => 0,
        'full_inbox'       => 0,
    ];

    $total = count( $job['results'] );
    foreach ( $job['results'] as $r ) {
        $st = isset( $r['status'] ) ? $r['status'] : 'unknown';
        if ( $st === 'deliverable' )                                 $counts['deliverable']++;
        elseif ( $st === 'risky' )                                   $counts['risky']++;
        elseif ( in_array( $st, [ 'undeliverable', 'invalid_format' ], true ) ) $counts['undeliverable']++;
        else                                                          $counts['unknown']++;

        if ( ! empty( $r['free_account'] ) )  $counts['free_account']++;
        if ( ! empty( $r['role_account'] ) )  $counts['role_account']++;
        if ( ! empty( $r['disposable'] ) )    $counts['disposable']++;
        if ( ! empty( $r['full_inbox'] ) )    $counts['full_inbox']++;
    }

    $deliverable_rate = $total > 0 ? round( ( $counts['deliverable'] / $total ) * 100 ) : 0;

    return [
        'total'               => $total,
        'deliverable'         => $counts['deliverable'],
        'risky'               => $counts['risky'],
        'undeliverable'       => $counts['undeliverable'],
        'unknown'             => $counts['unknown'],
        'free_account'        => $counts['free_account'],
        'role_account'        => $counts['role_account'],
        'disposable'          => $counts['disposable'],
        'full_inbox'          => $counts['full_inbox'],
        'deliverable_rate_pct' => $deliverable_rate,
        'duplicates_removed'  => isset( $job['duplicates_removed'] ) ? (int) $job['duplicates_removed'] : 0,
        'filename'            => isset( $job['filename'] ) ? $job['filename'] : '',
        'verified_on'         => isset( $job['created_at'] ) ? gmdate( 'd.m.y H:i', $job['created_at'] ) : '',
    ];
}

// ---- AJAX: Export CSV ----
add_action( 'wp_ajax_easysender_bulk_export_csv', 'easysender_bulk_export_csv' );
function easysender_bulk_export_csv() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
    }
    check_ajax_referer( 'easysender_bulk', '_wpnonce' );

    $job_id = '';
    if ( isset( $_POST['job_id'] ) ) {
        $job_id = sanitize_text_field( wp_unslash( $_POST['job_id'] ) );
    }

    $transient_key = 'easysender_bulk_job_' . sanitize_key( $job_id );
    $job           = get_transient( $transient_key );
    if ( ! is_array( $job ) || $job['status'] !== 'complete' ) {
        wp_send_json_error( [ 'message' => 'Job not found or not complete.' ], 400 );
    }

    $include_raw = '';
    if ( isset( $_POST['include'] ) ) {
        $include_raw = sanitize_text_field( wp_unslash( $_POST['include'] ) );
    }
    $include = json_decode( $include_raw, true );
    if ( ! is_array( $include ) ) {
        $include = [];
    }

    // Whitelist filter keys.
    $filter_whitelist = [
        'deliverable', 'risky', 'risky_free_account', 'risky_role_account',
        'undeliverable', 'unknown', 'unknown_disposable', 'unknown_full_inbox',
    ];
    $filters = [];
    foreach ( $filter_whitelist as $fk ) {
        $filters[ $fk ] = ! empty( $include[ $fk ] );
    }

    // Filter results.
    $rows = [];
    foreach ( $job['results'] as $r ) {
        $st = isset( $r['status'] ) ? $r['status'] : 'unknown';

        if ( $st === 'deliverable' && $filters['deliverable'] ) {
            $rows[] = $r;
        } elseif ( $st === 'risky' && $filters['risky'] ) {
            // Sub-filters narrow: exclude sub-categories that are unchecked.
            if ( ! empty( $r['free_account'] ) && ! $filters['risky_free_account'] ) continue;
            if ( ! empty( $r['role_account'] ) && ! $filters['risky_role_account'] ) continue;
            $rows[] = $r;
        } elseif ( in_array( $st, [ 'undeliverable', 'invalid_format' ], true ) && $filters['undeliverable'] ) {
            $rows[] = $r;
        } elseif ( ! in_array( $st, [ 'deliverable', 'risky', 'undeliverable', 'invalid_format' ], true ) && $filters['unknown'] ) {
            // Unknown bucket — sub-filters narrow.
            if ( ! empty( $r['disposable'] ) && ! $filters['unknown_disposable'] ) continue;
            if ( ! empty( $r['full_inbox'] ) && ! $filters['unknown_full_inbox'] ) continue;
            $rows[] = $r;
        }
    }

    // Stream CSV download.
    $filename = sanitize_file_name( 'easydmarc-verification-' . gmdate( 'Y-m-d' ) . '.csv' );

    // Clean any prior output.
    if ( ob_get_level() ) {
        ob_end_clean();
    }

    nocache_headers();
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    header( 'Pragma: no-cache' );
    header( 'Expires: 0' );

    // Header row.
    echo "email,status,format_valid,mx_active,disposable,free_account,role_account\r\n";

    // Status label map.
    $status_labels = [
        'deliverable'    => 'Deliverable',
        'risky'          => 'Risky',
        'undeliverable'  => 'Undeliverable',
        'invalid_format' => 'Undeliverable',
        'unknown'        => 'Unknown',
    ];

    // Use fputcsv() for proper CSV escaping (quotes fields containing commas,
    // quotes, or newlines). esc_html() must NOT be used here — this is a CSV
    // download, not HTML output.
    $out = fopen( 'php://output', 'w' );
    foreach ( $rows as $r ) {
        $st_label = isset( $status_labels[ $r['status'] ] ) ? $status_labels[ $r['status'] ] : 'Unknown';
        fputcsv( $out, [
            $r['email'],
            $st_label,
            ! empty( $r['format_valid'] ) ? 'true' : 'false',
            ! empty( $r['mx_active'] ) ? 'true' : 'false',
            ! empty( $r['disposable'] ) ? 'true' : 'false',
            ! empty( $r['free_account'] ) ? 'true' : 'false',
            ! empty( $r['role_account'] ) ? 'true' : 'false',
        ] );
    }
    fclose( $out );

    wp_die();
}

// ---- AJAX: Get plans ----
add_action( 'wp_ajax_easysender_get_plans', 'easysender_ajax_get_plans' );
function easysender_ajax_get_plans() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
    }
    check_ajax_referer( 'easysender_plans', '_wpnonce' );

    $catalog = easysender_get_plans_catalog();

    if ( empty( $catalog['plans'] ) ) {
        wp_send_json_error( [ 'message' => 'Unable to load plans. Please try again later.' ] );
        return;
    }

    wp_send_json_success( $catalog );
}
