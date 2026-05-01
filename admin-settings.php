<?php
if (!defined('ABSPATH')) exit;

// Ensure logger is available without relying on constants during activation.
if (!function_exists('easysender_log_api_error')) {
    require_once plugin_dir_path(__FILE__) . 'includes/logging.php';
}

// Preserve API credentials as entered while stripping control characters only.
if (!function_exists('easysender_clean_credential_input')) {
    function easysender_clean_credential_input($value) {
        $val = is_string($value) ? $value : '';
        if (function_exists('wp_unslash')) {
            $val = wp_unslash($val);
        }
        $val = trim($val);
        return preg_replace('/[\\x00-\\x1F\\x7F]/', '', $val);
    }
}

// Enqueue admin CSS and tab-specific JS
add_action('admin_enqueue_scripts', 'easysender_admin_enqueue_scripts');
function easysender_admin_enqueue_scripts($hook) {
    if (strpos($hook, 'easysender') === false) return;

    wp_enqueue_style('easysender-admin', plugins_url('assets/css/admin.css', __FILE__), [], '1.1.0');

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $tab  = isset($_GET['tab'])  ? sanitize_key(wp_unslash($_GET['tab']))  : 'api';

    if ($page !== 'easysender_settings') return;

    if ($tab === 'api') {
        wp_enqueue_script('easysender-admin-api', plugins_url('assets/js/admin-api.js', __FILE__), [], '1.1.0', true);
        wp_localize_script('easysender-admin-api', 'easysenderApiData', ['nonce' => wp_create_nonce('easysender_verify_api_key')]);
    }
    if ($tab === 'test') {
        wp_enqueue_style('easysender-admin-bulk-css', plugins_url('assets/css/admin-bulk.css', __FILE__), [], '1.1.0');
        wp_enqueue_script('easysender-admin-test', plugins_url('assets/js/admin-test.js', __FILE__), [], '1.1.0', true);
        wp_localize_script('easysender-admin-test', 'easysenderTestData', ['nonce' => wp_create_nonce('easysender_test_email')]);
        wp_enqueue_script('easysender-admin-bulk', plugins_url('assets/js/admin-bulk.js', __FILE__), [], '1.1.0', true);
        wp_localize_script('easysender-admin-bulk', 'easysenderBulkData', ['nonce' => wp_create_nonce('easysender_bulk')]);
    }
    if ($tab === 'usage') {
        wp_enqueue_script('easysender-admin-usage', plugins_url('assets/js/admin-usage.js', __FILE__), [], '1.1.0', true);
        wp_localize_script('easysender-admin-usage', 'easysenderUsageData', ['nonce' => wp_create_nonce('easysender_get_usage')]);
    }
    if ($tab === 'plans') {
        wp_enqueue_style('easysender-admin-bulk-css', plugins_url('assets/css/admin-bulk.css', __FILE__), [], '1.1.0');
        wp_enqueue_script('easysender-admin-plans', plugins_url('assets/js/admin-plans.js', __FILE__), [], '1.1.0', true);
        wp_localize_script('easysender-admin-plans', 'easysenderPlansData', [
            'nonce'       => wp_create_nonce('easysender_plans'),
            'usage_nonce' => wp_create_nonce('easysender_get_usage'),
        ]);
    }
}

// Add top-level admin menu and submenus
add_action('admin_menu', 'easysender_add_admin_menu');
function easysender_add_admin_menu() {
    add_menu_page(
        'EasySender Email Verification',
        'EasySender',
        'manage_options',
        'easysender_welcome',
        'easysender_welcome_page',
        'dashicons-email-alt',
        60
    );

    add_submenu_page(
        'easysender_welcome',
        'Welcome to EasySender',
        'Welcome',
        'manage_options',
        'easysender_welcome',
        'easysender_welcome_page'
    );

    add_submenu_page(
        'easysender_welcome',
        'EasySender Settings',
        'Settings',
        'manage_options',
        'easysender_settings',
        'easysender_settings_page'
    );

}

// Helpers for plugin checks
if ( ! function_exists('easysender_plugin_checks') ) {
    function easysender_plugin_checks() {
        if ( ! function_exists('is_plugin_active') ) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $checks = [];

        // Contact Form 7
        $cf7_slug  = 'contact-form-7/wp-contact-form-7.php';
        $checks['cf7'] = [
            'installed' => file_exists(WP_PLUGIN_DIR . '/' . $cf7_slug),
            'active'    => is_plugin_active($cf7_slug),
            'label'     => 'Contact Form 7',
            'key'       => 'enable_cf7',
            'available' => true,
        ];

        // WPForms
        $wpforms_slug  = 'wpforms-lite/wpforms.php';
        $wpforms_pro   = 'wpforms/wpforms.php';
        $checks['wpforms'] = [
            'installed' => file_exists( WP_PLUGIN_DIR . '/' . $wpforms_slug ) || file_exists( WP_PLUGIN_DIR . '/' . $wpforms_pro ),
            'active'    => is_plugin_active( $wpforms_slug ) || is_plugin_active( $wpforms_pro ),
            'label'     => 'WPForms',
            'key'       => 'enable_wpforms',
            'available' => true,
        ];

        // Elementor Forms (Pro)
        $elementor_slug  = 'elementor-pro/elementor-pro.php';
        $checks['elementor'] = [
            'installed' => file_exists(WP_PLUGIN_DIR . '/' . $elementor_slug),
            'active'    => is_plugin_active($elementor_slug),
            'label'     => 'Elementor Forms',
            'key'       => 'enable_elementor',
            'available' => true,
        ];

        // Ninja Forms
        $nf_slug = 'ninja-forms/ninja-forms.php';
        $checks['ninjaforms'] = [
            'installed' => file_exists( WP_PLUGIN_DIR . '/' . $nf_slug ),
            'active'    => is_plugin_active( $nf_slug ),
            'label'     => 'Ninja Forms',
            'key'       => 'enable_ninjaforms',
            'available' => true,
        ];

        // Fluent Forms (free) / Fluent Forms Pro
        $ff_slug  = 'fluentform/fluentform.php';
        $ff_pro   = 'fluentformpro/fluentformpro.php';
        $checks['fluentforms'] = [
            'installed' => file_exists( WP_PLUGIN_DIR . '/' . $ff_slug ) || file_exists( WP_PLUGIN_DIR . '/' . $ff_pro ),
            'active'    => is_plugin_active( $ff_slug ) || is_plugin_active( $ff_pro ),
            'label'     => 'Fluent Forms',
            'key'       => 'enable_fluentforms',
            'available' => true,
        ];

        // Gravity Forms
        $gf_slug   = 'gravityforms/gravityforms.php';
        $checks['gf'] = [
            'installed' => file_exists(WP_PLUGIN_DIR . '/' . $gf_slug),
            'active'    => is_plugin_active($gf_slug),
            'label'     => 'Gravity Forms',
            'key'       => 'enable_gravityforms',
            'available' => true,
        ];

        // SureForms
        $sf_slug = 'sureforms/sureforms.php';
        $checks['sureforms'] = [
            'installed' => file_exists( WP_PLUGIN_DIR . '/' . $sf_slug ),
            'active'    => is_plugin_active( $sf_slug ),
            'label'     => 'SureForms',
            'key'       => 'enable_sureforms',
            'available' => true,
        ];

        // WooCommerce
        $wc_slug   = 'woocommerce/woocommerce.php';
        $checks['wc'] = [
            'installed' => file_exists( WP_PLUGIN_DIR . '/' . $wc_slug ),
            'active'    => is_plugin_active( $wc_slug ),
            'label'     => 'WooCommerce Checkout',
            'key'       => 'enable_woocommerce',
            'available' => true,
        ];

        // WP Registration (always available — core WordPress)
        $checks['wpreg'] = [
            'installed' => true,
            'active'    => true,
            'label'     => 'WordPress Registration Form',
            'key'       => 'enable_wp_registration',
            'available' => true,
        ];

        return $checks;
    }
}

// (Safe) defaults for ED endpoints if not defined elsewhere
if (!defined('EASYSENDER_API_BASE'))   define('EASYSENDER_API_BASE',   'https://sender-api.easydmarc.com');
if (!defined('EASYSENDER_TOKEN_URL'))  define('EASYSENDER_TOKEN_URL',  EASYSENDER_API_BASE . '/api/v0.0/auth/token');
if (!defined('EASYSENDER_VERIFY_URL')) define('EASYSENDER_VERIFY_URL', EASYSENDER_API_BASE . '/api/v0.0/verify/sync');

/**
 * Usage summary endpoint.
 * NOTE: If backend changes this path, you can override via:
 * add_filter('easysender_usage_url', fn() => 'https://.../new/path');
 */
if (!defined('EASYSENDER_USAGE_URL'))  define('EASYSENDER_USAGE_URL',  EASYSENDER_API_BASE . '/api/v0.0/credit/stats');

// Welcome page callback
function easysender_welcome_page() {
    $options = get_option( 'easysender_settings', [] );

    // Check if API credentials are saved
    $has_credentials = ! empty( $options['client_id'] ) && ! empty( $options['client_secret'] );

    // Check if any form integrations are enabled
    $form_keys   = [ 'enable_cf7', 'enable_elementor', 'enable_wpforms', 'enable_ninjaforms', 'enable_fluentforms', 'enable_gravityforms', 'enable_sureforms' ];
    $has_forms   = false;
    foreach ( $form_keys as $fk ) {
        if ( ! empty( $options[ $fk ] ) && $options[ $fk ] === '1' ) {
            $has_forms = true;
            break;
        }
    }

    // Determine step statuses
    // Step 2: API key
    if ( $has_credentials ) {
        $step2_status = 'done';
    } else {
        $step2_status = 'in_progress';
    }

    // Step 3: Forms
    if ( $has_forms ) {
        $step3_status = 'done';
    } elseif ( $has_credentials ) {
        $step3_status = 'in_progress';
    } else {
        $step3_status = 'pending';
    }

    // Determine if all setup is complete
    $all_done = $has_credentials && $has_forms;
    ?>
    <div class="wrap es-wrap">
        <h1><?php esc_html_e( 'EasySender', 'easysender-email-verification' ); ?></h1>

        <header class="es-pagehead">
            <div class="es-pagehead__brand">
                <div class="es-pagehead__mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7l9 6 9-6"/><rect x="3" y="5" width="18" height="14" rx="2"/></svg>
                </div>
                <div>
                    <h2 class="es-pagehead__title"><?php esc_html_e( 'Welcome to EasySender', 'easysender-email-verification' ); ?></h2>
                    <p class="es-pagehead__sub"><?php esc_html_e( 'Real-time email verification for your WordPress forms.', 'easysender-email-verification' ); ?></p>
                </div>
            </div>
            <div class="es-pagehead__meta">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=easysender_settings&tab=api' ) ); ?>" class="es-btn es-btn--secondary es-btn--sm"><?php esc_html_e( 'Open settings', 'easysender-email-verification' ); ?> &rarr;</a>
            </div>
        </header>

        <!-- Hero card -->
        <div class="es-card" style="background: linear-gradient(135deg, #FFFFFF 0%, #F8F9FF 100%); border-color: #E0E3FF;">
            <div class="es-card__body" style="display: grid; grid-template-columns: 1fr 200px; gap: 32px; align-items: center;">
                <div>
                    <span class="es-pill es-pill--brand" style="margin-bottom: 12px;"><?php esc_html_e( 'Quick start', 'easysender-email-verification' ); ?></span>
                    <h2 style="margin: 0 0 8px; font-size: 22px; font-weight: 700; letter-spacing: -0.015em;"><?php esc_html_e( 'Stop bad emails before they hit your forms.', 'easysender-email-verification' ); ?></h2>
                    <p style="margin: 0 0 20px; color: var(--es-text-2); font-size: 14px; max-width: 56ch;"><?php esc_html_e( 'EasySender checks every submitted email address in real time — blocking typos, disposable inboxes, and undeliverable addresses across all your form plugins.', 'easysender-email-verification' ); ?></p>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <?php if ( $all_done ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=easysender_settings&tab=test' ) ); ?>" class="es-btn es-btn--primary"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M3 8l3 3 7-7"/></svg><?php esc_html_e( 'Run a test email', 'easysender-email-verification' ); ?></a>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=easysender_settings&tab=usage' ) ); ?>" class="es-btn es-btn--secondary"><?php esc_html_e( 'View usage', 'easysender-email-verification' ); ?></a>
                        <?php else : ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=easysender_settings&tab=api' ) ); ?>" class="es-btn es-btn--primary"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M3 8l3 3 7-7"/></svg><?php esc_html_e( 'Continue setup', 'easysender-email-verification' ); ?></a>
                        <?php endif; ?>
                    </div>
                </div>
                <div style="display: grid; place-items: center;">
                    <svg viewBox="0 0 220 160" width="100%" style="max-width: 200px;">
                        <defs><linearGradient id="env-grad" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#EEF0FF"/><stop offset="100%" stop-color="#E0E3FF"/></linearGradient></defs>
                        <rect x="20" y="36" width="180" height="108" rx="10" fill="url(#env-grad)" stroke="#C7CBFF" stroke-width="1.5"/>
                        <path d="M20 46l90 60 90-60" fill="none" stroke="#A5ABF5" stroke-width="1.5"/>
                        <circle cx="172" cy="44" r="18" fill="#fff" stroke="#10B981" stroke-width="2"/>
                        <path d="M164 44l6 6 10-10" fill="none" stroke="#10B981" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
            </div>
        </div>

        <!-- Setup checklist -->
        <h3 style="margin: 28px 0 12px; font-size: 13px; font-weight: 600; color: var(--es-text-2); text-transform: uppercase; letter-spacing: 0.05em;"><?php esc_html_e( 'Setup checklist', 'easysender-email-verification' ); ?></h3>
        <div class="es-grid es-grid--3">
            <!-- Step 1: Install plugin — always done -->
            <div class="es-card"><div class="es-card__body">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px;">
                    <span style="width: 32px; height: 32px; border-radius: 8px; background: var(--es-success-50); color: var(--es-success); display: grid; place-items: center;"><svg width="18" height="18" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 8l3 3 7-7"/></svg></span>
                    <span class="es-pill es-pill--success"><?php esc_html_e( 'Done', 'easysender-email-verification' ); ?></span>
                </div>
                <h4 style="margin: 0 0 4px; font-size: 14.5px; font-weight: 600;"><?php esc_html_e( '1. Install plugin', 'easysender-email-verification' ); ?></h4>
                <p style="margin: 0; font-size: 13px; color: var(--es-text-2);"><?php esc_html_e( 'EasySender is active and ready to configure.', 'easysender-email-verification' ); ?></p>
            </div></div>

            <!-- Step 2: Connect API key -->
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=easysender_settings&tab=api' ) ); ?>" class="es-card es-quick"><div class="es-card__body">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px;">
                    <?php if ( $has_credentials ) : ?>
                        <span style="width: 32px; height: 32px; border-radius: 8px; background: var(--es-success-50); color: var(--es-success); display: grid; place-items: center;"><svg width="18" height="18" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 8l3 3 7-7"/></svg></span>
                    <?php else : ?>
                        <span style="width: 32px; height: 32px; border-radius: 8px; background: var(--es-primary-50); color: var(--es-primary); display: grid; place-items: center; font-weight: 700;">2</span>
                    <?php endif; ?>
                </div>
                <h4 style="margin: 0 0 4px; font-size: 14.5px; font-weight: 600;"><?php esc_html_e( '2. API Settings', 'easysender-email-verification' ); ?> &rarr;</h4>
                <p style="margin: 0; font-size: 13px; color: var(--es-text-2);"><?php esc_html_e( 'Configure your API credentials and verification rules.', 'easysender-email-verification' ); ?></p>
            </div></a>

            <!-- Step 3: Pick forms -->
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=easysender_settings&tab=api' ) ); ?>" class="es-card es-quick"><div class="es-card__body">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px;">
                    <?php if ( $has_forms ) : ?>
                        <span style="width: 32px; height: 32px; border-radius: 8px; background: var(--es-success-50); color: var(--es-success); display: grid; place-items: center;"><svg width="18" height="18" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 8l3 3 7-7"/></svg></span>
                    <?php else : ?>
                        <span style="width: 32px; height: 32px; border-radius: 8px; background: var(--es-neutral-50); color: var(--es-text-2); display: grid; place-items: center; font-weight: 700;">3</span>
                    <?php endif; ?>
                </div>
                <h4 style="margin: 0 0 4px; font-size: 14.5px; font-weight: 600;"><?php esc_html_e( '3. Form Integrations', 'easysender-email-verification' ); ?> &rarr;</h4>
                <p style="margin: 0; font-size: 13px; color: var(--es-text-2);"><?php esc_html_e( 'Enable verification on Contact Form 7, WPForms, and others.', 'easysender-email-verification' ); ?></p>
            </div></a>
        </div>

        <!-- How to get API keys -->
        <div class="es-card" style="margin-top: 16px;">
            <div class="es-card__head">
                <h2 class="es-card__title"><?php esc_html_e( 'How to get your API credentials', 'easysender-email-verification' ); ?></h2>
                <p class="es-card__sub"><?php esc_html_e( 'Follow these steps to connect EasySender to your site.', 'easysender-email-verification' ); ?></p>
            </div>
            <div class="es-card__body">
                <ol style="margin: 0; padding: 0 0 0 20px; font-size: 13.5px; color: var(--es-text-2); line-height: 1.8;">
                    <li><a href="https://app.easydmarc.com/register" target="_blank" rel="noopener noreferrer" style="color: var(--es-primary); font-weight: 600;"><?php esc_html_e( 'Sign up', 'easysender-email-verification' ); ?></a> <?php esc_html_e( 'for an EasyDMARC account.', 'easysender-email-verification' ); ?></li>
                    <li><a href="https://app.easydmarc.com/login" target="_blank" rel="noopener noreferrer" style="color: var(--es-primary); font-weight: 600;"><?php esc_html_e( 'Sign in', 'easysender-email-verification' ); ?></a> <?php esc_html_e( 'to your account and switch to EasySender (upper-left corner). From the left-hand Settings menu, select API, then click Create API Key.', 'easysender-email-verification' ); ?></li>
                    <li><?php esc_html_e( 'Copy your API Client ID and Client Secret (API Key).', 'easysender-email-verification' ); ?></li>
                    <li><?php
                        printf(
                            /* translators: %s: link to settings page */
                            esc_html__( 'Go to %s and paste your credentials.', 'easysender-email-verification' ),
                            '<a href="' . esc_url( admin_url( 'admin.php?page=easysender_settings&tab=api' ) ) . '" style="color: var(--es-primary); font-weight: 600;">' . esc_html__( 'API Settings', 'easysender-email-verification' ) . '</a>'
                        );
                    ?></li>
                    <li><?php esc_html_e( 'Enable your form integrations and start verifying emails!', 'easysender-email-verification' ); ?></li>
                </ol>
            </div>
        </div>

        <!-- Quick actions -->
        <h3 style="margin: 32px 0 12px; font-size: 13px; font-weight: 600; color: var(--es-text-2); text-transform: uppercase; letter-spacing: 0.05em;"><?php esc_html_e( 'Quick actions', 'easysender-email-verification' ); ?></h3>
        <div class="es-grid es-grid--3">
            <?php if ( $has_credentials ) : ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=easysender_settings&tab=test' ) ); ?>" class="es-card es-quick"><div class="es-card__body">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="color: var(--es-primary); margin-bottom: 10px;"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                <h4 style="margin: 0 0 4px; font-size: 14px; font-weight: 600;"><?php esc_html_e( 'Run a test email', 'easysender-email-verification' ); ?> &rarr;</h4>
                <p style="margin: 0; font-size: 12.5px; color: var(--es-text-2);"><?php esc_html_e( 'Verify a single address or upload a CSV.', 'easysender-email-verification' ); ?></p>
            </div></a>
            <?php else : ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=easysender_settings&tab=api' ) ); ?>" class="es-card es-quick"><div class="es-card__body">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="color: var(--es-primary); margin-bottom: 10px;"><circle cx="6" cy="12" r="2"/><path d="M8 12h12M17 8l5 4-5 4"/></svg>
                <h4 style="margin: 0 0 4px; font-size: 14px; font-weight: 600;"><?php esc_html_e( 'Connect API key', 'easysender-email-verification' ); ?> &rarr;</h4>
                <p style="margin: 0; font-size: 12.5px; color: var(--es-text-2);"><?php esc_html_e( 'Enter your EasySender credentials to get started.', 'easysender-email-verification' ); ?></p>
            </div></a>
            <?php endif; ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=easysender_settings&tab=usage' ) ); ?>" class="es-card es-quick"><div class="es-card__body">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="color: var(--es-primary); margin-bottom: 10px;"><path d="M3 3v18h18"/><path d="M7 14l4-4 3 3 5-5"/></svg>
                <h4 style="margin: 0 0 4px; font-size: 14px; font-weight: 600;"><?php esc_html_e( 'View usage', 'easysender-email-verification' ); ?> &rarr;</h4>
                <p style="margin: 0; font-size: 12.5px; color: var(--es-text-2);"><?php esc_html_e( 'Track verification credits and trends.', 'easysender-email-verification' ); ?></p>
            </div></a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=easysender_settings&tab=documentation' ) ); ?>" class="es-card es-quick"><div class="es-card__body">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="color: var(--es-primary); margin-bottom: 10px;"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                <h4 style="margin: 0 0 4px; font-size: 14px; font-weight: 600;"><?php esc_html_e( 'Read the docs', 'easysender-email-verification' ); ?> &rarr;</h4>
                <p style="margin: 0; font-size: 12.5px; color: var(--es-text-2);"><?php esc_html_e( 'API reference, integration guides, FAQ.', 'easysender-email-verification' ); ?></p>
            </div></a>
        </div>

        <p class="es-footnote"><?php esc_html_e( 'EasySender Email Verification', 'easysender-email-verification' ); ?> &middot; v<?php echo esc_html( defined( 'EASYSENDER_VERSION' ) ? EASYSENDER_VERSION : '1.0.0' ); ?></p>
    </div>
    <?php
}

// Register settings (unchanged + additions)
add_action('admin_init', 'easysender_settings_init');
function easysender_settings_init() {
    // Main API Settings
    register_setting('easysender_settings_group', 'easysender_settings', 'easysender_settings_sanitize');

    add_settings_section('easysender_section', 'API Configuration', null, 'easysender_settings');

    // Existing fields
    $fields = [
        'client_id'     => 'Client ID',
        'client_secret' => 'Client Secret (API Key)',
    ];

    foreach ($fields as $id => $label) {
        add_settings_field($id, $label, 'easysender_field_render', 'easysender_settings', 'easysender_section', ['id' => $id, 'label' => $label]);
    }

    // Button row directly in API section
    add_settings_field('api_key_actions', '', 'easysender_api_key_actions_render', 'easysender_settings', 'easysender_section', []);

    // --- Form Integration Section ---
    add_settings_section(
        'easysender_integrations_section',
        'Form Integration',
        function () {
            echo '<h3 style="margin-top:0;">Choose Forms to Protect</h3>';
            echo '<p>Select the forms where you\'d like to enable real-time email verification.</p>';
        },
        'easysender_settings'
    );

    add_settings_field('easysender_integrations_field', '', 'easysender_integrations_render', 'easysender_settings', 'easysender_integrations_section');

    // Verification Result Filters
    add_settings_section(
        'easysender_result_section',
        'Allowed Verification Result Types',
        function () {
            echo '<p style="margin-bottom:0;"><em>Only the selected email status types will be considered valid. All others will be rejected during verification.</em></p>';
        },
        'easysender_settings'
    );

    $result_types = [
        'allow_deliverable'   => 'Deliverable',
        'allow_risky'         => 'Risky',
        'allow_undeliverable' => 'Undeliverable',
        'allow_unknown'       => 'Unknown'
    ];

    foreach ($result_types as $id => $label) {
        add_settings_field($id, $label, 'easysender_checkbox_field_render', 'easysender_settings', 'easysender_result_section', ['id' => $id, 'label' => $label]);
    }

    add_settings_field(
        'allow_on_api_error',
        'Allow submission if verification service is unavailable',
        'easysender_checkbox_field_render',
        'easysender_settings',
        'easysender_result_section',
        ['id' => 'allow_on_api_error', 'label' => 'Do not block form when the verification API fails (service unavailable/timeouts/api errors).']
    );

    // Error Message Settings
    register_setting('easysender_error_group', 'easysender_error_messages', 'easysender_sanitize_errors');

    add_settings_section('easysender_error_section', 'Custom Error Messages', null, 'easysender_error_settings');

    $error_fields = [
        'msg_invalid'    => 'Invalid Email Message',
        'msg_risky'      => 'Risky Email Message',
        'msg_api_error'  => 'API Error Message'
    ];

    foreach ($error_fields as $id => $label) {
        add_settings_field($id, $label, 'easysender_error_field_render', 'easysender_error_settings', 'easysender_error_section', ['id' => $id, 'label' => $label]);
    }
}

// Render individual field (API fields)
function easysender_field_render($args) {
    $options = get_option('easysender_settings');
    $id = $args['id'];
    $value = isset($options[$id]) ? $options[$id] : '';

    // Decrypt sensitive ones for display
    if (in_array($id, ['client_id', 'client_secret'], true)) {
        $value = $value ? easysender_decrypt($value) : '';
    }

    // Choose input type
    $type = ($id === 'client_secret') ? 'password' : 'text';

    printf(
        "<input type='%s' name='easysender_settings[%s]' value='%s' class='regular-text' autocomplete='off' />",
        esc_attr($type),
        esc_attr($id),
        esc_attr($value)
    );

    if ($id === 'client_secret') {
        echo '<p class="description">You can find or generate this in your EasyDMARC dashboard &gt; <strong>API Settings</strong>.</p>';
    }
}

// Render checkbox fields (result types)
function easysender_checkbox_field_render( $args ) {
    $options = get_option( 'easysender_settings', [] );

    $defaults = [
        'allow_deliverable'   => '1',
        'allow_risky'         => '1',
        'allow_undeliverable' => '0',
        'allow_unknown'       => '0',
        'allow_on_api_error'  => '0',
    ];

    $id       = isset( $args['id'] ) ? (string) $args['id'] : '';
    $label    = isset( $args['label'] ) ? (string) $args['label'] : '';
    $value    = isset( $options[ $id ] ) ? $options[ $id ] : ( $defaults[ $id ] ?? '0' );
    $input_id = 'easysender_' . $id;

    ?>
    <input type="hidden"
           name="easysender_settings[<?php echo esc_attr( $id ); ?>]"
           value="0"
    />

    <label for="<?php echo esc_attr( $input_id ); ?>">
        <input
                id="<?php echo esc_attr( $input_id ); ?>"
                type="checkbox"
                name="easysender_settings[<?php echo esc_attr( $id ); ?>]"
                value="1"
            <?php checked( $value, '1' ); ?>
                aria-label="<?php echo esc_attr( $label ); ?>"
        />
        <span class="description" style="margin-left:6px;">
			<?php echo esc_html( $label ); ?>
		</span>
    </label>
    <?php
}


// Render error message fields
function easysender_error_field_render( $args ) {
    $options = get_option( 'easysender_error_messages', [] );

    $id    = isset( $args['id'] ) ? (string) $args['id'] : '';
    $value = isset( $options[ $id ] ) ? (string) $options[ $id ] : '';

    $defaults = [
        'msg_invalid'   => __( 'Invalid email address.', 'easysender-email-verification' ),
        'msg_risky'     => __( 'Risky email address.', 'easysender-email-verification' ),
        'msg_api_error' => __( 'Verification error. Please try again.', 'easysender-email-verification' ),
    ];
    $placeholder = isset( $defaults[ $id ] ) ? $defaults[ $id ] : '';
    ?>
    <input
        type="text"
        name="easysender_error_messages[<?php echo esc_attr( $id ); ?>]"
        value="<?php echo esc_attr( $value ); ?>"
        class="regular-text"
        placeholder="<?php echo esc_attr( $placeholder ); ?>"
    />
    <p class="description"><?php
        /* translators: %s: default message text */
        printf( esc_html__( 'Default: %s', 'easysender-email-verification' ), '<code>' . esc_html( $placeholder ) . '</code>' );
    ?></p>
    <?php
}


function easysender_api_key_actions_render() {
    ?>
    <div class="es-api-actions">
        <button type="button" id="easysender-verify-api-key"><?php esc_html_e( 'Verify API Key', 'easysender-email-verification' ); ?></button>
        <a href="https://app.easydmarc.com/login" target="_blank" rel="noopener noreferrer" class="button"><?php esc_html_e( 'Generate Key', 'easysender-email-verification' ); ?></a>
        <span id="easysender-verify-status"></span>
    </div>
    <?php
}

function easysender_integrations_render() {
    $opts   = get_option( 'easysender_settings', [] );
    $checks = easysender_plugin_checks();

    ?>
    <div class="es-integrations-grid">
        <?php foreach ( $checks as $code => $info ) :
            $key       = isset( $info['key'] ) ? (string) $info['key'] : '';
            $label     = isset( $info['label'] ) ? (string) $info['label'] : '';
            $is_set    = ( ! empty( $opts[ $key ] ) && $opts[ $key ] === '1' );
            $installed = ! empty( $info['installed'] );
            $active    = ! empty( $info['active'] );
            $available = ! empty( $info['available'] );

            $is_disabled = ( ! $installed || ! $active || ! $available );

            $note = '';
            if ( ! $available ) {
                $note = __( 'Not available yet', 'easysender-email-verification' );
            } elseif ( ! $installed ) {
                $note = __( 'Not installed', 'easysender-email-verification' );
            } elseif ( ! $active ) {
                $note = __( 'Not active', 'easysender-email-verification' );
            }
            ?>
            <label class="es-integration-item">
                <input type="hidden" name="easysender_settings[<?php echo esc_attr( $key ); ?>]" value="0" />
                <input
                    type="checkbox"
                    name="easysender_settings[<?php echo esc_attr( $key ); ?>]"
                    value="1"
                    <?php checked( $is_set, true ); ?>
                    <?php disabled( $is_disabled, true ); ?>
                />
                <span class="es-integration-name"><?php echo esc_html( $label ); ?></span>
                <?php if ( $note ) : ?>
                    <span class="es-integration-note"><?php echo esc_html( $note ); ?></span>
                <?php endif; ?>
            </label>
        <?php endforeach; ?>
    </div>
    <?php
}


function easysender_settings_sanitize($input) {
    $clean = [];

    $whitelist = [
        'client_id', 'client_secret',
        'allow_deliverable', 'allow_risky', 'allow_undeliverable', 'allow_unknown',
        'allow_on_api_error',
        'enable_cf7', 'enable_elementor', 'enable_wpforms', 'enable_ninjaforms', 'enable_fluentforms', 'enable_gravityforms', 'enable_sureforms', 'enable_woocommerce', 'enable_wp_registration',
    ];

    foreach ((array) $input as $key => $value) {
        if (!in_array($key, $whitelist, true)) continue;

        if (in_array($key, ['client_id', 'client_secret'], true)) {
            $val = easysender_clean_credential_input($value);
            if ($val !== '') {
                $clean[$key] = easysender_encrypt($val);
            }
            continue;
        }

        if (in_array($key, ['allow_deliverable','allow_risky','allow_undeliverable','allow_unknown','allow_on_api_error','enable_cf7','enable_elementor','enable_wpforms','enable_ninjaforms','enable_fluentforms','enable_gravityforms','enable_sureforms','enable_woocommerce','enable_wp_registration'], true)) {
            $clean[$key] = ($value === '1') ? '1' : '0';
        }
    }

    $defaults = [
        'enable_cf7'            => '0',
        'enable_elementor'      => '0',
        'enable_wpforms'        => '0',
        'enable_ninjaforms'     => '0',
        'enable_fluentforms'    => '0',
        'enable_gravityforms'   => '0',
        'enable_sureforms'       => '0',
        'enable_woocommerce'     => '0',
        'enable_wp_registration' => '0',
        'allow_on_api_error'    => '0',
    ];
    foreach ($defaults as $k => $v) {
        if (!isset($clean[$k])) $clean[$k] = $v;
    }

    return $clean;
}

// Sanitize error messages
function easysender_sanitize_errors($input) {
    $clean = [];
    foreach ((array) $input as $key => $value) {
        $clean[$key] = sanitize_text_field($value);
    }
    return $clean;
}

// Render full settings page with tabs
function easysender_settings_page() {
    $active_tab = 'api';

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( isset( $_GET['tab'] ) ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $active_tab = sanitize_key( wp_unslash( $_GET['tab'] ) );
    }

    $allowed_tabs = [ 'api', 'usage', 'test', 'messages', 'documentation', 'plans' ];
    if ( ! in_array( $active_tab, $allowed_tabs, true ) ) {
        $active_tab = 'api';
    }

    $base_url = admin_url( 'admin.php' );

    // SVG icons for tabs (inline, no external assets)
    $tab_icons = [
        'api'           => '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><circle cx="6" cy="8" r="2"/><path d="M8 8h6M11 6l3 2-3 2"/></svg>',
        'usage'         => '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M2 14h12M3 11l3-3 3 2 4-5"/></svg>',
        'test'          => '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M14 8L9 3v3H2v4h7v3l5-5z"/></svg>',
        'messages'      => '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><circle cx="8" cy="8" r="6"/><path d="M8 5v3M8 11v.01"/></svg>',
        'documentation' => '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M3 13.5A1.5 1.5 0 0 1 4.5 12H13"/><path d="M4.5 2H13v12H4.5A1.5 1.5 0 0 1 3 12.5v-9A1.5 1.5 0 0 1 4.5 2z"/></svg>',
        'plans'         => '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M2 5h12l-1.5 7.5h-9z"/><circle cx="6" cy="14" r="0.8"/><circle cx="11" cy="14" r="0.8"/></svg>',
    ];
    $tab_labels = [
        'api'           => __( 'API Settings', 'easysender-email-verification' ),
        'usage'         => __( 'Logging &amp; Usage', 'easysender-email-verification' ),
        'test'          => __( 'Test Email', 'easysender-email-verification' ),
        'messages'      => __( 'Error Messages', 'easysender-email-verification' ),
        'documentation' => __( 'Documentation', 'easysender-email-verification' ),
        'plans'         => __( 'Buy Credits', 'easysender-email-verification' ),
    ];
    ?>
    <div class="wrap es-wrap">
        <h1><?php esc_html_e( 'EasySender Settings', 'easysender-email-verification' ); ?></h1>

        <header class="es-pagehead">
            <div class="es-pagehead__brand">
                <div class="es-pagehead__mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7l9 6 9-6"/><rect x="3" y="5" width="18" height="14" rx="2"/></svg>
                </div>
                <div>
                    <h2 class="es-pagehead__title"><?php esc_html_e( 'EasySender Settings', 'easysender-email-verification' ); ?></h2>
                    <p class="es-pagehead__sub"><?php esc_html_e( 'Configure how email verification runs on your forms.', 'easysender-email-verification' ); ?></p>
                </div>
            </div>
        </header>

        <nav class="es-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Settings tabs', 'easysender-email-verification' ); ?>">
            <?php foreach ( $allowed_tabs as $tab_key ) : ?>
                <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'easysender_settings', 'tab' => $tab_key ], $base_url ) ); ?>"
                   class="es-tab <?php echo $active_tab === $tab_key ? 'is-active' : ''; ?>"
                   role="tab"
                   <?php echo $active_tab === $tab_key ? 'aria-current="page"' : ''; ?>>
                    <?php echo $tab_icons[ $tab_key ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG ?>
                    <?php echo $tab_labels[ $tab_key ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already translated ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="es-tab-content">
            <?php if ( $active_tab === 'api' ) : ?>
                <?php easysender_render_api_tab(); ?>

            <?php elseif ( $active_tab === 'messages' ) : ?>
                <?php easysender_render_messages_tab(); ?>

            <?php elseif ( $active_tab === 'test' ) : ?>
                <?php
                if ( function_exists( 'easysender_render_test_tab' ) ) {
                    easysender_render_test_tab();
                }
                ?>

            <?php elseif ( $active_tab === 'usage' ) : ?>
                <?php
                if ( function_exists( 'easysender_render_usage_tab' ) ) {
                    easysender_render_usage_tab();
                }
                ?>

            <?php elseif ( $active_tab === 'documentation' ) : ?>
                <?php easysender_render_documentation_tab_default(); ?>

            <?php elseif ( $active_tab === 'plans' ) : ?>
                <?php easysender_render_plans_tab(); ?>

            <?php endif; ?>
        </div>

        <p class="es-footnote"><?php esc_html_e( 'EasySender', 'easysender-email-verification' ); ?> &middot; v<?php echo esc_html( defined( 'EASYSENDER_VERSION' ) ? EASYSENDER_VERSION : '1.0.0' ); ?> &middot; <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'easysender_settings', 'tab' => 'documentation' ], $base_url ) ); ?>"><?php esc_html_e( 'Documentation', 'easysender-email-verification' ); ?></a></p>
    </div>
    <?php
}

/**
 * Render the API Settings tab with design-system cards.
 * Uses settings_fields() for nonce but renders fields manually.
 */
function easysender_render_api_tab() {
    $options = get_option( 'easysender_settings', [] );
    $checks  = easysender_plugin_checks();

    // Decrypt for display
    $client_id     = ! empty( $options['client_id'] ) ? easysender_decrypt( $options['client_id'] ) : '';
    $client_secret = ! empty( $options['client_secret'] ) ? easysender_decrypt( $options['client_secret'] ) : '';

    $defaults = [
        'allow_deliverable'   => '1',
        'allow_risky'         => '1',
        'allow_undeliverable' => '0',
        'allow_unknown'       => '0',
        'allow_on_api_error'  => '0',
    ];

    $result_types = [
        'allow_deliverable'   => [
            'label' => __( 'Deliverable', 'easysender-email-verification' ),
            'desc'  => __( 'Mailbox confirmed — safe to send.', 'easysender-email-verification' ),
            'color' => 'success',
            'icon'  => '<svg width="18" height="18" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 8l3 3 7-7"/></svg>',
        ],
        'allow_risky'         => [
            'label' => __( 'Risky', 'easysender-email-verification' ),
            'desc'  => __( 'Catch-all, role-based, or low-confidence — accept at your discretion.', 'easysender-email-verification' ),
            'color' => 'warning',
            'icon'  => '<svg width="18" height="18" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M8 2l7 12H1z"/><path d="M8 6v4M8 12v.01"/></svg>',
        ],
        'allow_undeliverable' => [
            'label' => __( 'Undeliverable', 'easysender-email-verification' ),
            'desc'  => __( 'Address bounces — strongly recommend rejecting.', 'easysender-email-verification' ),
            'color' => 'danger',
            'icon'  => '<svg width="18" height="18" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 4l8 8M12 4l-8 8"/></svg>',
        ],
        'allow_unknown'       => [
            'label' => __( 'Unknown', 'easysender-email-verification' ),
            'desc'  => __( 'Provider couldn\'t be reached — accept to avoid blocking valid users.', 'easysender-email-verification' ),
            'color' => 'neutral',
            'icon'  => '<svg width="18" height="18" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="8" cy="8" r="6"/><path d="M6 6c0-1 1-2 2-2s2 1 2 2-2 1.5-2 3M8 12v.01"/></svg>',
        ],
    ];
    ?>
    <form method="post" action="options.php">
        <?php settings_fields( 'easysender_settings_group' ); ?>

        <!-- API Credentials -->
        <div class="es-card">
            <div class="es-card__head">
                <h2 class="es-card__title"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><circle cx="6" cy="8" r="2"/><path d="M8 8h6M11 6l3 2-3 2"/></svg><?php esc_html_e( 'API credentials', 'easysender-email-verification' ); ?></h2>
                <p class="es-card__sub"><?php esc_html_e( 'Connect this site to your EasySender account. Generate or rotate keys from your dashboard.', 'easysender-email-verification' ); ?></p>
            </div>
            <div class="es-card__body">
                <div class="es-grid es-grid--2">
                    <div class="es-field">
                        <label class="es-label" for="es-client-id"><?php esc_html_e( 'Client ID', 'easysender-email-verification' ); ?></label>
                        <input id="es-client-id" class="es-input es-input--mono" type="text" name="easysender_settings[client_id]" value="<?php echo esc_attr( $client_id ); ?>" autocomplete="off">
                    </div>
                    <div class="es-field">
                        <label class="es-label" for="es-client-secret"><?php esc_html_e( 'Client secret (API key)', 'easysender-email-verification' ); ?></label>
                        <div class="es-input-affix">
                            <input id="es-client-secret" class="es-input es-input--mono" type="password" name="easysender_settings[client_secret]" value="<?php echo esc_attr( $client_secret ); ?>" autocomplete="off">
                            <button type="button" class="es-input-affix__btn" onclick="var i=document.getElementById('es-client-secret');var s=i.type==='text';i.type=s?'password':'text';this.textContent=s?'Show':'Hide';"><?php esc_html_e( 'Show', 'easysender-email-verification' ); ?></button>
                        </div>
                        <span class="es-help"><?php esc_html_e( 'Kept encrypted in wp_options. Never exposed to the front-end.', 'easysender-email-verification' ); ?></span>
                    </div>
                </div>
                <div style="display: flex; gap: 10px; margin-top: 18px; flex-wrap: wrap; align-items: center;">
                    <button type="button" class="es-btn es-btn--primary" id="easysender-verify-api-key"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M3 8l3 3 7-7"/></svg><?php esc_html_e( 'Verify API key', 'easysender-email-verification' ); ?></button>
                    <a href="https://app.easydmarc.com/login" target="_blank" rel="noopener noreferrer" class="es-btn es-btn--secondary"><?php esc_html_e( 'Generate new key', 'easysender-email-verification' ); ?></a>
                    <span id="easysender-verify-status" style="font-size: 13px;"></span>
                </div>
            </div>
        </div>

        <!-- Form Integrations -->
        <div class="es-card">
            <div class="es-card__head">
                <h2 class="es-card__title"><?php esc_html_e( 'Form integrations', 'easysender-email-verification' ); ?></h2>
                <p class="es-card__sub"><?php esc_html_e( 'Choose which form plugins should run email verification before submission.', 'easysender-email-verification' ); ?></p>
            </div>
            <div class="es-card__body">
                <div class="es-grid es-grid--3">
                    <?php
                    $integration_colors = [
                        'cf7'             => '#3B82F6',
                        'elementor'       => '#92003C',
                        'wpforms'         => '#E1306C',
                        'ninjaforms'      => '#0EA5E9',
                        'fluentforms'     => '#7C3AED',
                        'gravityforms'    => '#F59E0B',
                        'sureforms'       => '#10B981',
                        'woocommerce'     => '#7F54B3',
                        'wp_registration' => '#1E293B',
                    ];
                    $integration_abbrevs = [
                        'cf7'             => 'CF',
                        'elementor'       => 'EL',
                        'wpforms'         => 'WP',
                        'ninjaforms'      => 'NF',
                        'fluentforms'     => 'FF',
                        'gravityforms'    => 'GF',
                        'sureforms'       => 'SF',
                        'woocommerce'     => 'WC',
                        'wp_registration' => 'WP',
                    ];
                    foreach ( $checks as $code => $info ) :
                        $key       = isset( $info['key'] ) ? (string) $info['key'] : '';
                        $label     = isset( $info['label'] ) ? (string) $info['label'] : '';
                        $is_set    = ( ! empty( $options[ $key ] ) && $options[ $key ] === '1' );
                        $installed = ! empty( $info['installed'] );
                        $active    = ! empty( $info['active'] );
                        $available = ! empty( $info['available'] );
                        $is_disabled = ( ! $installed || ! $active || ! $available );

                        $meta = '';
                        if ( ! $available ) {
                            $meta = __( 'Not available yet', 'easysender-email-verification' );
                        } elseif ( ! $installed ) {
                            $meta = __( 'Not installed', 'easysender-email-verification' );
                        } elseif ( ! $active ) {
                            $meta = __( 'Not active', 'easysender-email-verification' );
                        }

                        $color  = $integration_colors[ $code ] ?? '#64748B';
                        $abbrev = $integration_abbrevs[ $code ] ?? strtoupper( substr( $code, 0, 2 ) );
                        $card_class = 'es-int-card';
                        if ( $is_set && ! $is_disabled ) $card_class .= ' is-on';
                        if ( $is_disabled ) $card_class .= ' is-disabled';
                    ?>
                    <label class="<?php echo esc_attr( $card_class ); ?>">
                        <span class="es-int-card__logo" style="background: <?php echo esc_attr( $color ); ?>;"><?php echo esc_html( $abbrev ); ?></span>
                        <div class="es-int-card__body">
                            <div class="es-int-card__name"><?php echo esc_html( $label ); ?></div>
                            <?php if ( $meta ) : ?>
                                <div class="es-int-card__meta"><?php echo esc_html( $meta ); ?></div>
                            <?php endif; ?>
                        </div>
                        <input type="hidden" name="easysender_settings[<?php echo esc_attr( $key ); ?>]" value="0" />
                        <label class="es-toggle">
                            <input type="checkbox" name="easysender_settings[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $is_set, true ); ?> <?php disabled( $is_disabled, true ); ?>>
                            <span class="es-toggle__track"></span>
                        </label>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Allowed Verification Results -->
        <div class="es-card">
            <div class="es-card__head">
                <h2 class="es-card__title"><?php esc_html_e( 'Allowed verification results', 'easysender-email-verification' ); ?></h2>
                <p class="es-card__sub"><?php esc_html_e( 'Email statuses you accept. Anything else is rejected with your custom error message.', 'easysender-email-verification' ); ?></p>
            </div>
            <div class="es-card__body" style="padding: 0;">
                <ul style="list-style: none; margin: 0; padding: 0;">
                    <?php
                    $last_key = array_key_last( $result_types );
                    foreach ( $result_types as $rt_key => $rt ) :
                        $rt_val = isset( $options[ $rt_key ] ) ? $options[ $rt_key ] : ( $defaults[ $rt_key ] ?? '0' );
                        $is_last = ( $rt_key === $last_key );
                    ?>
                    <li class="es-result-row" <?php echo $is_last ? 'style="border-bottom: 0;"' : ''; ?>>
                        <span class="es-result-row__icon" style="background: var(--es-<?php echo esc_attr( $rt['color'] ); ?>-50); color: var(--es-<?php echo esc_attr( $rt['color'] ); ?>);">
                            <?php echo $rt['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG ?>
                        </span>
                        <div class="es-result-row__body">
                            <div class="es-result-row__title"><?php echo esc_html( $rt['label'] ); ?></div>
                            <div class="es-result-row__desc"><?php echo esc_html( $rt['desc'] ); ?></div>
                        </div>
                        <input type="hidden" name="easysender_settings[<?php echo esc_attr( $rt_key ); ?>]" value="0" />
                        <label class="es-toggle">
                            <input type="checkbox" name="easysender_settings[<?php echo esc_attr( $rt_key ); ?>]" value="1" <?php checked( $rt_val, '1' ); ?>>
                            <span class="es-toggle__track"></span>
                        </label>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <!-- Allow on API Error -->
        <?php $aoe_val = isset( $options['allow_on_api_error'] ) ? $options['allow_on_api_error'] : ( $defaults['allow_on_api_error'] ?? '0' ); ?>
        <div class="es-alert es-alert--warning" style="margin-top: 16px; align-items: flex-start;">
            <svg class="es-alert__icon" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M9 2l8 14H1z"/><path d="M9 7v4M9 13v.01"/></svg>
            <div class="es-alert__body" style="display: flex; align-items: center; justify-content: space-between; gap: 16px;">
                <div>
                    <p class="es-alert__title"><?php esc_html_e( 'Allow submission if verification is unavailable', 'easysender-email-verification' ); ?></p>
                    <p class="es-alert__text"><?php esc_html_e( 'If our API times out or returns an error, accept the submission anyway. Recommended to keep forms working during outages.', 'easysender-email-verification' ); ?></p>
                </div>
                <input type="hidden" name="easysender_settings[allow_on_api_error]" value="0" />
                <label class="es-toggle">
                    <input type="checkbox" name="easysender_settings[allow_on_api_error]" value="1" <?php checked( $aoe_val, '1' ); ?>>
                    <span class="es-toggle__track"></span>
                </label>
            </div>
        </div>

        <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 24px;">
            <?php submit_button( __( 'Save changes', 'easysender-email-verification' ), 'primary', 'submit', false ); ?>
        </div>
    </form>
    <?php
}

/**
 * Render the Error Messages tab with design-system cards.
 */
function easysender_render_messages_tab() {
    $options = get_option( 'easysender_error_messages', [] );
    $msgs = [
        'msg_invalid'   => [
            'label'   => __( 'Invalid email message', 'easysender-email-verification' ),
            'desc'    => __( 'Shown when format is malformed or the address bounces.', 'easysender-email-verification' ),
            'default' => __( 'Invalid email address.', 'easysender-email-verification' ),
            'color'   => 'danger',
            'icon'    => '<svg width="18" height="18" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 4l8 8M12 4l-8 8"/></svg>',
        ],
        'msg_risky'     => [
            'label'   => __( 'Risky email message', 'easysender-email-verification' ),
            'desc'    => __( 'Shown when the address is catch-all, role-based, or low-confidence.', 'easysender-email-verification' ),
            'default' => __( 'Risky email address.', 'easysender-email-verification' ),
            'color'   => 'warning',
            'icon'    => '<svg width="18" height="18" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M8 2l7 12H1z"/><path d="M8 6v4M8 12v.01"/></svg>',
        ],
        'msg_api_error' => [
            'label'   => __( 'API error message', 'easysender-email-verification' ),
            'desc'    => __( 'Shown if our service is unreachable. Only seen when "allow on error" is off.', 'easysender-email-verification' ),
            'default' => __( 'Verification error. Please try again.', 'easysender-email-verification' ),
            'color'   => 'neutral',
            'icon'    => '<svg width="18" height="18" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="8" cy="8" r="6"/><path d="M8 5v3M8 11v.01"/></svg>',
        ],
    ];
    ?>
    <form method="post" action="options.php">
        <?php settings_fields( 'easysender_error_group' ); ?>

        <div class="es-card">
            <div class="es-card__head">
                <h2 class="es-card__title"><?php esc_html_e( 'Custom error messages', 'easysender-email-verification' ); ?></h2>
                <p class="es-card__sub"><?php esc_html_e( 'Shown to visitors when their email fails verification. Keep them short and actionable.', 'easysender-email-verification' ); ?></p>
            </div>
            <div class="es-card__body" style="padding: 0;">
                <ul style="list-style: none; margin: 0; padding: 0;">
                    <?php
                    $last_key = array_key_last( $msgs );
                    foreach ( $msgs as $msg_key => $msg ) :
                        $value   = isset( $options[ $msg_key ] ) ? (string) $options[ $msg_key ] : '';
                        $is_last = ( $msg_key === $last_key );
                    ?>
                    <li class="es-msg-row" <?php echo $is_last ? 'style="border-bottom: 0;"' : ''; ?>>
                        <span class="es-msg-row__icon" style="background: var(--es-<?php echo esc_attr( $msg['color'] ); ?>-50); color: var(--es-<?php echo esc_attr( $msg['color'] ); ?>);">
                            <?php echo $msg['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG ?>
                        </span>
                        <div class="es-msg-row__body">
                            <div class="es-msg-row__head">
                                <div>
                                    <div class="es-msg-row__title"><?php echo esc_html( $msg['label'] ); ?></div>
                                    <div class="es-msg-row__desc"><?php echo esc_html( $msg['desc'] ); ?></div>
                                </div>
                            </div>
                            <input class="es-input" type="text" name="easysender_error_messages[<?php echo esc_attr( $msg_key ); ?>]" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $msg['default'] ); ?>">
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 24px;">
            <?php submit_button( __( 'Save changes', 'easysender-email-verification' ), 'primary', 'submit', false ); ?>
        </div>
    </form>
    <?php
}

/**
 * Render the Documentation tab with resource cards.
 */
function easysender_render_documentation_tab_default() {
    if ( function_exists( 'easysender_render_documentation_tab' ) ) {
        easysender_render_documentation_tab();
        return;
    }

    $checks = function_exists( 'easysender_plugin_checks' ) ? easysender_plugin_checks() : [];
    ?>
    <!-- Resource cards -->
    <div class="es-grid es-grid--2">
        <a href="https://sender-api.easydmarc.com/" target="_blank" rel="noopener noreferrer" class="es-card es-doc-card"><div class="es-card__body">
            <span class="es-doc-card__icon" style="background: var(--es-primary-50); color: var(--es-primary);"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 18l6-6-6-6M8 6l-6 6 6 6"/></svg></span>
            <h3 class="es-doc-card__title"><?php esc_html_e( 'API documentation', 'easysender-email-verification' ); ?> <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M6 3h7v7M13 3l-9 9"/></svg></h3>
            <p class="es-doc-card__desc"><?php esc_html_e( 'Endpoints, parameters, response codes, and rate limits for the EasySender verification API.', 'easysender-email-verification' ); ?></p>
        </div></a>
        <a href="https://easydmarc.com/legal" target="_blank" rel="noopener noreferrer" class="es-card es-doc-card"><div class="es-card__body">
            <span class="es-doc-card__icon" style="background: var(--es-success-50); color: var(--es-success);"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l8 4v6c0 5-3.5 9-8 10-4.5-1-8-5-8-10V6z"/><path d="M9 12l2 2 4-4"/></svg></span>
            <h3 class="es-doc-card__title"><?php esc_html_e( 'Legal &amp; privacy', 'easysender-email-verification' ); ?> <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M6 3h7v7M13 3l-9 9"/></svg></h3>
            <p class="es-doc-card__desc"><?php esc_html_e( 'GDPR, data processing addendum, terms of service, and how we handle email data.', 'easysender-email-verification' ); ?></p>
        </div></a>
        <a href="https://easydmarc.com/easysender" target="_blank" rel="noopener noreferrer" class="es-card es-doc-card"><div class="es-card__body">
            <span class="es-doc-card__icon" style="background: var(--es-warning-50); color: var(--es-warning);"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.5 9.5a2.5 2.5 0 0 1 5 0c0 1.5-2.5 2-2.5 3.5M12 17v.01"/></svg></span>
            <h3 class="es-doc-card__title"><?php esc_html_e( 'Support &amp; FAQ', 'easysender-email-verification' ); ?> <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M6 3h7v7M13 3l-9 9"/></svg></h3>
            <p class="es-doc-card__desc"><?php esc_html_e( 'Troubleshooting common issues, integration guides, and contact form for our team.', 'easysender-email-verification' ); ?></p>
        </div></a>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=easysender_settings&tab=api' ) ); ?>" class="es-card es-doc-card"><div class="es-card__body">
            <span class="es-doc-card__icon" style="background: var(--es-neutral-50); color: var(--es-neutral);"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h16M4 18h10"/></svg></span>
            <h3 class="es-doc-card__title"><?php esc_html_e( 'Quick start guide', 'easysender-email-verification' ); ?> <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M6 3h7v7M13 3l-9 9"/></svg></h3>
            <p class="es-doc-card__desc"><?php esc_html_e( 'Configure your API credentials and enable form integrations.', 'easysender-email-verification' ); ?></p>
        </div></a>
    </div>

    <?php if ( ! empty( $checks ) ) : ?>
    <!-- Supported plugins -->
    <div class="es-card" style="margin-top: 16px;">
        <div class="es-card__head">
            <h2 class="es-card__title"><?php esc_html_e( 'Supported form plugins', 'easysender-email-verification' ); ?></h2>
            <p class="es-card__sub"><?php esc_html_e( 'Auto-detected on this site. Enable them in the API Settings tab.', 'easysender-email-verification' ); ?></p>
        </div>
        <div class="es-card__body">
            <div class="es-grid es-grid--3">
                <?php foreach ( $checks as $info ) :
                    $available = ! empty( $info['available'] );
                    $installed = ! empty( $info['installed'] );
                    $pill_class = 'es-pill--neutral';
                    $pill_label = __( 'Not available', 'easysender-email-verification' );
                    if ( $available && $installed ) {
                        $pill_class = 'es-pill--success';
                        $pill_label = __( 'Compatible', 'easysender-email-verification' );
                    } elseif ( $available && ! $installed ) {
                        $pill_class = 'es-pill--neutral';
                        $pill_label = __( 'Not installed', 'easysender-email-verification' );
                    }
                ?>
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 14px; border: 1px solid var(--es-border); border-radius: var(--es-radius-sm); background: #fff;">
                    <div>
                        <div style="font-weight: 600; font-size: 13.5px;"><?php echo esc_html( $info['label'] ); ?></div>
                    </div>
                    <span class="es-pill <?php echo esc_attr( $pill_class ); ?>"><?php echo esc_html( $pill_label ); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- FAQ -->
    <div class="es-card">
        <div class="es-card__head">
            <h2 class="es-card__title"><?php esc_html_e( 'Quick reference', 'easysender-email-verification' ); ?></h2>
            <p class="es-card__sub"><?php esc_html_e( 'Common questions answered without leaving this page.', 'easysender-email-verification' ); ?></p>
        </div>
        <div class="es-card__body" style="padding: 0;">
            <details class="es-faq" open>
                <summary><?php esc_html_e( 'How accurate is verification?', 'easysender-email-verification' ); ?></summary>
                <div><?php esc_html_e( 'Most addresses come back deliverable or undeliverable with high confidence. Catch-all domains and some corporate mailservers return "risky" — accept those at your discretion.', 'easysender-email-verification' ); ?></div>
            </details>
            <details class="es-faq">
                <summary><?php esc_html_e( 'Does verification slow down my forms?', 'easysender-email-verification' ); ?></summary>
                <div><?php esc_html_e( 'Average API latency is under 500 ms. Verification runs server-side during form submission, so users typically don\'t notice.', 'easysender-email-verification' ); ?></div>
            </details>
            <details class="es-faq">
                <summary><?php esc_html_e( 'What happens if my credits run out mid-month?', 'easysender-email-verification' ); ?></summary>
                <div><?php esc_html_e( 'Submissions either pass through or are blocked depending on your "Allow on API error" setting. We recommend enabling that fallback to keep forms working.', 'easysender-email-verification' ); ?></div>
            </details>
            <details class="es-faq">
                <summary><?php esc_html_e( 'Can I use EasySender with a custom form plugin?', 'easysender-email-verification' ); ?></summary>
                <div><?php
                    printf(
                        /* translators: %s: function name */
                        esc_html__( 'Yes — call %s from your form\'s validation hook. It returns a structured result with status, reason, and details.', 'easysender-email-verification' ),
                        '<code style="font-family: var(--es-font-mono); background: var(--es-neutral-50); padding: 1px 6px; border-radius: 4px;">easysender_do_email_check($email)</code>'
                    );
                ?></div>
            </details>
        </div>
    </div>
    <?php
}


function easysender_render_test_tab() {
    ?>
    <!-- Section 1: Single Email Test -->
    <div class="esb-section">
        <h3 class="esb-section-title">
            <?php esc_html_e( 'Test a Single Email Address', 'easysender-email-verification' ); ?>
            <span class="esb-badge esb-badge-credit">1 CREDIT</span>
        </h3>
        <p class="esb-desc"><?php esc_html_e( 'Instantly verify any email address against our real-time API. Results show deliverability, format validity, domain health, and spam risk.', 'easysender-email-verification' ); ?></p>

        <div class="esb-single-row">
            <input type="email" id="esb-single-input" placeholder="e.g. hello@company.com" aria-label="<?php esc_attr_e( 'Email address to verify', 'easysender-email-verification' ); ?>" />
            <button type="button" class="esb-btn esb-btn-primary" id="esb-single-btn"><?php esc_html_e( 'Verify Email', 'easysender-email-verification' ); ?></button>
        </div>

        <div class="esb-result-area" id="esb-single-result" aria-live="polite">
            <span style="color:var(--ed-text-tertiary);font-size:12px;"><?php esc_html_e( 'Result will appear here...', 'easysender-email-verification' ); ?></span>
        </div>
    </div>

    <!-- Divider -->
    <div class="esb-divider">
        <div class="esb-divider-line"></div>
        <span class="esb-divider-text"><?php esc_html_e( 'or verify a list in bulk', 'easysender-email-verification' ); ?></span>
        <div class="esb-divider-line"></div>
    </div>

    <!-- Section 2: Bulk CSV Upload -->
    <div class="esb-section">
        <h3 class="esb-section-title">
            <?php esc_html_e( 'Bulk Verify from CSV', 'easysender-email-verification' ); ?>
            <span class="esb-badge esb-badge-new">NEW</span>
        </h3>
        <p class="esb-desc"><?php esc_html_e( 'Upload a CSV file containing email addresses. We\'ll verify the entire list and let you choose exactly which results to export. Uses 1 credit per email.', 'easysender-email-verification' ); ?></p>

        <!-- Upload Mode Selector -->
        <div class="esb-mode-cards">
            <div class="esb-mode-card active" data-mode="header" tabindex="0" role="button">
                <div class="esb-mode-card-title"><?php esc_html_e( 'CSV with header row', 'easysender-email-verification' ); ?></div>
                <div class="esb-mode-card-desc"><?php esc_html_e( 'File has a header. Choose which column contains email addresses during upload.', 'easysender-email-verification' ); ?></div>
            </div>
            <div class="esb-mode-card" data-mode="plain" tabindex="0" role="button">
                <div class="esb-mode-card-title"><?php esc_html_e( 'Plain email list (CSV)', 'easysender-email-verification' ); ?></div>
                <div class="esb-mode-card-desc"><?php esc_html_e( 'One email address per row, no header row required.', 'easysender-email-verification' ); ?></div>
            </div>
        </div>

        <!-- Drop Zone -->
        <div class="esb-dropzone" id="esb-dropzone" tabindex="0" role="button" aria-label="<?php esc_attr_e( 'Upload CSV file', 'easysender-email-verification' ); ?>">
            <div class="esb-dropzone-icon">&uarr;</div>
            <div class="esb-dropzone-headline"><?php esc_html_e( 'Drop your CSV file here', 'easysender-email-verification' ); ?></div>
            <div class="esb-dropzone-sub">
                <?php
                printf(
                    /* translators: %s: clickable browse link */
                    esc_html__( 'Drag & drop your file, or %s.', 'easysender-email-verification' ),
                    '<span class="esb-dropzone-link">' . esc_html__( 'browse to select a CSV', 'easysender-email-verification' ) . '</span>'
                );
                ?>
            </div>
            <div class="esb-dropzone-sub"><?php esc_html_e( 'Maximum 500,000 email addresses per upload.', 'easysender-email-verification' ); ?></div>
            <span class="esb-dropzone-chip">.csv</span>
            <div class="esb-dropzone-error" id="esb-dropzone-error" style="display:none;"></div>
        </div>
        <input type="file" id="esb-file-input" accept=".csv" style="display:none;" />

        <!-- Preview (injected by JS) -->
        <div id="esb-preview-wrap" style="display:none;"></div>

        <!-- Progress (injected by JS) -->
        <div id="esb-progress-wrap" style="display:none;"></div>

        <!-- Results (injected by JS) -->
        <div id="esb-results-wrap" style="display:none;"></div>
    </div>

    <!-- Format Guide -->
    <div class="esb-format-guide">
        <h4><?php esc_html_e( 'Accepted File Format — CSV Only', 'easysender-email-verification' ); ?></h4>
        <div class="esb-format-grid">
            <div>
                <div class="esb-format-col-title"><?php esc_html_e( 'Single column (no header needed)', 'easysender-email-verification' ); ?></div>
                <pre class="esb-format-pre">alice@acme.com
bob@company.org
carol@startup.io</pre>
            </div>
            <div>
                <div class="esb-format-col-title"><?php esc_html_e( 'Multi-column CSV (with header row)', 'easysender-email-verification' ); ?></div>
                <pre class="esb-format-pre">name,email,company
Alice,alice@acme.com,Acme
Bob,bob@company.org,Corp</pre>
            </div>
        </div>
    </div>
    <?php
}
add_action('wp_ajax_easysender_test_email', 'easysender_test_email');
function easysender_test_email() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized.'], 403);
    }
    check_ajax_referer('easysender_test_email');

    $raw_email = '';
    if ( isset( $_POST['email'] ) ) {
        $raw_email = sanitize_text_field( wp_unslash( $_POST['email'] ) );
    }

    $email = strtolower( trim( sanitize_email( $raw_email ) ) );
    if (!$email || !is_email($email)) {
        wp_send_json_error(['message' => __('Please enter a valid email address.', 'easysender-email-verification')], 400);
    }

    // Prefer centralized helper
    if (function_exists('easysender_do_email_check')) {
        $check = easysender_do_email_check($email);

        if (!empty($check['ok'])) {
            $status  = strtolower($check['status'] ?? 'unknown');
            $verdict = ($status === 'deliverable') ? 'valid' : (($status === 'risky') ? 'risky' : 'invalid');
            wp_send_json_success(['verdict' => $verdict, 'status' => $status, 'details' => $check['details'] ?? []]);
        }

        $status  = strtolower($check['status'] ?? 'unknown');
        $verdict = ($status === 'risky') ? 'risky' : 'invalid';
        $resp    = ['verdict' => $verdict, 'status' => $status, 'details' => $check['details'] ?? []];
        if (!empty($check['reason'])) $resp['message'] = (string) $check['reason'];
        wp_send_json_success($resp);
    }

    // Fallback (direct API call) — mirror Elementor
    if (!function_exists('easysender_get_access_token')) {
        wp_send_json_error(['message' => __('Auth helper missing.', 'easysender-email-verification')], 500);
    }

    // Verify URL
    if (function_exists('easysender_get_verify_url')) {
        $verify_url = easysender_get_verify_url();
    } else {
        $verify_url = (defined('EASYSENDER_VERIFY_URL') && EASYSENDER_VERIFY_URL)
            ? EASYSENDER_VERIFY_URL
            : 'https://sender-api.easydmarc.com/api/v0.0/verify/sync';
    }
    if (empty($verify_url)) {
        wp_send_json_error(['message' => __('Verification URL is not configured.', 'easysender-email-verification')], 500);
    }

    // Token
    $token = easysender_get_access_token(false);
    if (is_wp_error($token)) {
        wp_send_json_error(['message' => $token->get_error_message()], 400);
    }

    // POST
    $payload = ['emailAddresses' => [ $email ]];
    $headers = [
        'Authorization' => 'Bearer ' . $token,
        'Content-Type'  => 'application/json',
        'Accept'        => 'application/json',
    ];
    $res  = wp_remote_post($verify_url, ['timeout' => 25, 'headers' => $headers, 'body' => wp_json_encode($payload)]);
    $code = (int) wp_remote_retrieve_response_code($res);

    // Retry on 401
    if ($code === 401) {
        $token = easysender_get_access_token(true);
        if (!is_wp_error($token)) {
            $headers['Authorization'] = 'Bearer ' . $token;
            $res = wp_remote_post($verify_url, ['timeout' => 25, 'headers' => $headers, 'body' => wp_json_encode($payload)]);
            $code = (int) wp_remote_retrieve_response_code($res);
        }
    }

    if (is_wp_error($res)) {
        easysender_log_api_error('test', 0, $res->get_error_message());
        wp_send_json_error(['message' => $res->get_error_message()], 400);
    }

    $body_raw = wp_remote_retrieve_body($res);
    $body     = json_decode($body_raw, true);

    if ($code === 402) {
        wp_send_json_error(['message' => __('Verification service unavailable: credit limit reached. Please try again later.', 'easysender-email-verification')], 402);
    }
    if ($code === 408 && isset($body['meta']['requestId'])) {
        wp_send_json_error(['message' => __('Verification timed out. Please try again.', 'easysender-email-verification')], 408);
    }

    if ($code < 200 || $code >= 300 || !is_array($body)) {
        $api_msg = (is_array($body) && !empty($body['message'])) ? (string) $body['message'] : $body_raw;
        easysender_log_api_error('test', $code, $api_msg);
        wp_send_json_error(['message' => $api_msg ?: 'Verification request failed.'], $code ?: 400);
    }

    // Parse like Elementor
    $result = '';
    if (isset($body['results']['items'][0]['result'])) {
        $result = $body['results']['items'][0]['result'];
    } elseif (isset($body['items'][0]['status'])) {
        $result = $body['items'][0]['status'];
    } elseif (isset($body['status'])) {
        $result = $body['status'];
    } elseif (isset($body['results'][0]['status'])) {
        $result = $body['results'][0]['status'];
    } elseif (isset($body[0]['status'])) {
        $result = $body[0]['status'];
    }
    $status  = strtolower((string) $result ?: 'unknown');
    $verdict = ($status === 'deliverable') ? 'valid' : (($status === 'risky') ? 'risky' : 'invalid');

    // requestId (for UI)
    $request_id = $body['meta']['requestId'] ?? ($body['results']['items'][0]['meta']['requestId'] ?? ($body['items'][0]['meta']['requestId'] ?? null));
    $details = $body;
    if ($request_id) {
        if (!isset($details['meta'])) $details['meta'] = [];
        $details['meta']['requestId'] = $request_id;
    }

    wp_send_json_success(['verdict' => $verdict, 'status' => $status, 'details' => $details]);
}





function easysender_render_usage_tab() {
    $base_url = admin_url( 'admin.php' );
    ?>
    <!-- Stat tiles (populated by JS) -->
    <div class="es-grid es-grid--3" id="easysender-stat-tiles">
        <div class="es-stat"><span class="es-stat__label"><?php esc_html_e( 'Allocated this period', 'easysender-email-verification' ); ?></span><span class="es-stat__value"><span class="easysender-skeleton" style="display:inline-block;width:80px;height:28px;"></span></span><span class="es-stat__meta">&nbsp;</span></div>
        <div class="es-stat"><span class="es-stat__label"><?php esc_html_e( 'Used', 'easysender-email-verification' ); ?></span><span class="es-stat__value"><span class="easysender-skeleton" style="display:inline-block;width:50px;height:28px;"></span></span><span class="es-stat__meta">&nbsp;</span></div>
        <div class="es-stat"><span class="es-stat__label"><?php esc_html_e( 'Remaining', 'easysender-email-verification' ); ?></span><span class="es-stat__value"><span class="easysender-skeleton" style="display:inline-block;width:70px;height:28px;"></span></span><span class="es-stat__meta">&nbsp;</span></div>
    </div>

    <!-- Donut chart card (populated by JS) -->
    <div class="es-card" style="margin-top: 16px;">
        <div class="es-card__head">
            <h2 class="es-card__title"><?php esc_html_e( 'Verification activity', 'easysender-email-verification' ); ?></h2>
            <p class="es-card__sub"><?php esc_html_e( 'Live credit usage from EasySender', 'easysender-email-verification' ); ?></p>
        </div>
        <div class="es-card__body" id="easysender-usage-block">
            <!-- skeleton state replaced by JS -->
            <div style="display: grid; grid-template-columns: 220px 1fr; gap: 32px; align-items: center;">
                <div style="display: grid; place-items: center; position: relative;">
                    <svg viewBox="0 0 120 120" width="200" height="200">
                        <circle cx="60" cy="60" r="48" fill="none" stroke="var(--es-neutral-50,#F1F5F9)" stroke-width="14"/>
                        <circle cx="60" cy="60" r="48" fill="none" stroke="var(--es-neutral-100,#E2E8F0)" stroke-width="14" stroke-dasharray="1 301.6" stroke-linecap="round" transform="rotate(-90 60 60)"/>
                    </svg>
                    <div style="position: absolute; text-align: center;">
                        <div class="easysender-skeleton" style="width:70px;height:28px;margin:0 auto 4px;"></div>
                        <div style="font-size: 12px; color: var(--es-text-2);"><?php esc_html_e( 'credits left', 'easysender-email-verification' ); ?></div>
                    </div>
                </div>
                <div>
                    <div style="display: grid; gap: 14px;">
                        <div style="display: flex; gap: 12px; align-items: flex-start;"><span style="width:10px;height:10px;border-radius:999px;background:var(--es-primary);margin-top:6px;flex-shrink:0;"></span><div style="flex:1;"><div style="display:flex;justify-content:space-between;font-weight:600;"><span><?php esc_html_e( 'Available', 'easysender-email-verification' ); ?></span><span class="easysender-skeleton" style="width:50px;height:14px;"></span></div><div style="font-size:12.5px;color:var(--es-text-2);"><?php esc_html_e( 'Use for email verification across all enabled forms.', 'easysender-email-verification' ); ?></div></div></div>
                        <div style="display: flex; gap: 12px; align-items: flex-start;"><span style="width:10px;height:10px;border-radius:999px;background:var(--es-text-3);margin-top:6px;flex-shrink:0;"></span><div style="flex:1;"><div style="display:flex;justify-content:space-between;font-weight:600;"><span><?php esc_html_e( 'Used', 'easysender-email-verification' ); ?></span><span class="easysender-skeleton" style="width:40px;height:14px;"></span></div><div style="font-size:12.5px;color:var(--es-text-2);"><?php esc_html_e( 'Verifications sent in the current billing cycle.', 'easysender-email-verification' ); ?></div></div></div>
                        <div style="display: flex; gap: 12px; align-items: flex-start;"><span style="width:10px;height:10px;border-radius:2px;background:var(--es-neutral-100);margin-top:6px;flex-shrink:0;"></span><div style="flex:1;"><div style="display:flex;justify-content:space-between;font-weight:600;"><span><?php esc_html_e( 'Allocated', 'easysender-email-verification' ); ?></span><span class="easysender-skeleton" style="width:60px;height:14px;"></span></div><div style="font-size:12.5px;color:var(--es-text-2);"><?php esc_html_e( 'Total credits in current cycle.', 'easysender-email-verification' ); ?></div></div></div>
                    </div>
                    <hr class="es-divider" style="margin: 18px 0;">
                    <div style="display:flex;justify-content:space-between;font-size:12.5px;color:var(--es-text-2);margin-bottom:6px;">
                        <span class="easysender-skeleton" style="width:120px;height:12px;"></span>
                        <span class="easysender-skeleton" style="width:30px;height:12px;"></span>
                    </div>
                    <div class="es-progress"><div class="es-progress__bar" style="width: 0%;"></div></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Dark CTA banner -->
    <div class="es-card" style="margin-top: 16px; background: linear-gradient(135deg, #0F172A 0%, #1E1B4B 100%); border: 0; color: #fff;">
        <div class="es-card__body" style="display: grid; grid-template-columns: 1fr auto; gap: 24px; align-items: center;">
            <div>
                <span class="es-pill" style="background: rgba(255,255,255,0.12); color: #C7D2FE; border-color: rgba(255,255,255,0.16);"><?php esc_html_e( 'Need more capacity?', 'easysender-email-verification' ); ?></span>
                <h3 style="margin: 10px 0 4px; font-size: 18px; font-weight: 600;">
                    <?php esc_html_e( 'Larger plans drop to $0.0004 per verification.', 'easysender-email-verification' ); ?>
                </h3>
                <p style="margin: 0; color: rgba(255,255,255,0.7); font-size: 13.5px;">
                    <?php esc_html_e( 'Upgrade anytime — credits and pricing update on next billing cycle.', 'easysender-email-verification' ); ?>
                </p>
            </div>
            <div>
                <a class="es-btn" href="<?php echo esc_url( add_query_arg( [ 'page' => 'easysender_settings', 'tab' => 'plans' ], $base_url ) ); ?>" style="background: #fff; color: var(--es-primary-700, #3730A3);"><?php esc_html_e( 'Upgrade plan', 'easysender-email-verification' ); ?> &rarr;</a>
            </div>
        </div>
    </div>
    <?php
}



add_action('wp_ajax_easysender_get_usage', 'easysender_get_usage');
function easysender_get_usage() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized.'], 403);
    }
    check_ajax_referer('easysender_get_usage');

    $usage = easysender_fetch_usage_data(true); // force refresh for this explicit request
    if (is_wp_error($usage)) {
        wp_send_json_error(['message' => $usage->get_error_message()], 400);
    }

    wp_send_json_success($usage);
}

/**
 * Fetch usage/credit stats with short caching to avoid hammering the API.
 *
 * @param bool $force_refresh Bypass cached value when true.
 * @return array|\WP_Error
 */
function easysender_fetch_usage_data($force_refresh = false) {
    $cache_key = 'easysender_usage_cache';
    if (!$force_refresh) {
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }
    }

    if (!function_exists('easysender_get_access_token')) {
        easysender_log_api_error('usage', 0, 'Helper easysender_get_access_token missing');
        return new WP_Error('easysender_missing_helper', 'Auth helper missing.');
    }

    $token = easysender_get_access_token(false);
    if (is_wp_error($token)) {
        return $token;
    }

    $url = defined('EASYSENDER_USAGE_URL')
        ? EASYSENDER_USAGE_URL
        : (defined('EASYSENDER_API_BASE') ? EASYSENDER_API_BASE : 'https://sender-api.easydmarc.com') . '/api/v0.0/credit/stats';

    $args = [
        'timeout' => 25,
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ],
    ];

    $res  = wp_remote_get($url, $args);
    $code = (int) wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);
    $json = json_decode($body, true);

    if ($code === 401) {
        $token = easysender_get_access_token(true);
        if (!is_wp_error($token)) {
            $args['headers']['Authorization'] = 'Bearer ' . $token;
            $res  = wp_remote_get($url, $args);
            $code = (int) wp_remote_retrieve_response_code($res);
            $body = wp_remote_retrieve_body($res);
            $json = json_decode($body, true);
        }
    }

    if (is_wp_error($res)) {
        easysender_log_api_error('usage', 0, $res->get_error_message());
        return $res;
    }
    if ($code < 200 || $code >= 300 || !is_array($json)) {
        $api_msg = (is_array($json) && !empty($json['message'])) ? (string) $json['message'] : $body;
        easysender_log_api_error('usage', $code, $api_msg);
        return new WP_Error('easysender_usage_error', $api_msg ?: 'Failed to fetch usage.', ['status' => $code ?: 400]);
    }

    $allocated = (int) ($json['allocated'] ?? 0);
    $balance   = (int) ($json['balance']   ?? 0);
    $spent     = (int) ($json['spent']     ?? 0);

    if ($spent    < 0) $spent    = 0;
    if ($balance  < 0) $balance  = 0;
    if ($allocated <= 0) $allocated = max($balance + $spent, 1);

    $total_for_pct = max($allocated, $balance + $spent);
    $pct_spent     = ($total_for_pct > 0) ? max(0, min(100, round(($spent / $total_for_pct) * 100))) : 0;

    $data = [
        'allocated' => $allocated,
        'balance'   => $balance,
        'spent'     => $spent,
        'total'     => $total_for_pct,
        'pct'       => $pct_spent,
        'quota'     => ($balance <= 0),
    ];

    set_transient($cache_key, $data, 5 * MINUTE_IN_SECONDS);

    return $data;
}

// Warn admins in wp-admin when credits are low (<50 by default)
add_action('admin_notices', 'easysender_low_balance_notice');
function easysender_low_balance_notice() {
    if (!current_user_can('manage_options')) return;
    if (!is_admin()) return;

    $usage = easysender_fetch_usage_data(false);
    if (is_wp_error($usage)) return;

    // Temporary testing threshold bumped to 195 to surface notice for current sandbox account.
    $threshold = (int) apply_filters('easysender_low_balance_threshold', 50);
    if ($usage['balance'] > $threshold) return;

    $balance     = number_format_i18n($usage['balance']);
    $upgrade_url = 'https://easydmarc.com/pricing/easysender/email-verification';
    $message     = sprintf('Only %s credits remain. Please top up to avoid any interruptions.', $balance);

    echo '<div class="notice notice-warning" style="display:flex;justify-content:space-between;align-items:center;"><p><strong>' . esc_html__('EasySender:', 'easysender-email-verification') . '</strong> ' . esc_html($message) . '</p><p><a class="button button-primary" href="' . esc_url($upgrade_url) . '" target="_blank" rel="noopener noreferrer">Upgrade Plan for More Verifications</a></p></div>';
}




// Add logo overlay markup & styles on plugin admin pages
add_action('admin_footer', 'easysender_add_logo_overlay');
function easysender_add_logo_overlay() {
    if (!function_exists('get_current_screen')) return;
    $screen = get_current_screen();

    $allowed_pages = [
        'settings_page_easysender_settings',
        'toplevel_page_easysender_welcome',
    ];

    if ($screen && in_array($screen->id, $allowed_pages, true)) {
        $logo_url = plugins_url('assets/images/EasyDmarc.svg', __FILE__);
        ?>
        <div id="easysender-logo-overlay">
            <a href="https://easydmarc.com/" target="_blank" rel="noopener noreferrer">
                <img src="<?php echo esc_url($logo_url); ?>" alt="EasySender Logo" style="max-width: 100%; max-height: 100%;" />
            </a>
        </div>
        <?php
    }
}

add_action('wp_ajax_easysender_verify_api_key', 'easysender_verify_api_key');
function easysender_verify_api_key() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'easysender-email-verification' ) ), 403 );
    }

    check_ajax_referer( 'easysender_verify_api_key' );

    $client_id     = '';
    $client_secret = '';

    // Correct order: sanitize_text_field( wp_unslash( $_POST[...] ) )
    if ( isset( $_POST['client_id'] ) && is_string( $_POST['client_id'] ) ) {
        $raw_client_id = sanitize_text_field( wp_unslash( $_POST['client_id'] ) );
        $client_id = easysender_clean_credential_input( $raw_client_id );
    }

    if ( isset( $_POST['client_secret'] ) && is_string( $_POST['client_secret'] ) ) {
        $raw_client_secret = sanitize_text_field( wp_unslash( $_POST['client_secret'] ) );
        $client_secret = easysender_clean_credential_input( $raw_client_secret );
    }

    if ( '' === $client_id || '' === $client_secret ) {
        wp_send_json_error(
            array( 'message' => __( 'Please enter Client ID and Client Secret.', 'easysender-email-verification' ) ),
            400
        );
    }

    $body = array(
        'grant_type' => 'password',
        'client_id'  => 'customer-api-console',
        'username'   => $client_id,
        'password'   => $client_secret,
    );

    $res = wp_remote_post(
        EASYSENDER_TOKEN_URL,
        array(
            'timeout' => 25,
            'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
            'body'    => $body,
        )
    );

    if ( is_wp_error( $res ) ) {
        wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
    }

    $code     = (int) wp_remote_retrieve_response_code( $res );
    $body_raw = (string) wp_remote_retrieve_body( $res );
    $json     = json_decode( $body_raw, true );

    if ( $code >= 200 && $code < 300 && is_array( $json ) && ! empty( $json['access_token'] ) ) {
        wp_send_json_success( array( 'message' => __( 'Credentials are valid.', 'easysender-email-verification' ) ) );
    }

    $msg = '';
    if ( is_array( $json ) ) {
        if ( ! empty( $json['message'] ) ) {
            $msg = (string) $json['message'];
        } elseif ( ! empty( $json['error_description'] ) ) {
            $msg = (string) $json['error_description'];
        } elseif ( ! empty( $json['error'] ) ) {
            $msg = is_string( $json['error'] ) ? $json['error'] : wp_json_encode( $json['error'] );
        }
    }

    if ( '' === $msg && '' !== $body_raw ) {
        $msg = $body_raw;
    }
    if ( '' === $msg ) {
        $msg = __( 'API returned an error.', 'easysender-email-verification' );
    }

    wp_send_json_error( array( 'message' => $msg ), $code ? $code : 400 );
}


/**
 * Render the Buy Credits tab.
 */
function easysender_render_plans_tab() {
    ?>
    <!-- Balance Strip -->
    <div class="esb-balance-strip">
        <div class="esb-balance-icon">&circledcirc;</div>
        <div class="esb-balance-info">
            <div class="esb-balance-label"><?php esc_html_e( 'CURRENT BALANCE', 'easysender-email-verification' ); ?></div>
            <div id="esb-balance-area">
                <span class="esb-skeleton" style="display:inline-block;width:80px;height:22px;"></span>
                <span class="esb-balance-sub"><?php esc_html_e( 'credits remaining this month', 'easysender-email-verification' ); ?></span>
            </div>
        </div>
        <div id="esb-balance-pill-area">
            <span class="esb-skeleton" style="display:inline-block;width:120px;height:24px;border-radius:999px;"></span>
        </div>
    </div>

    <!-- Section Heading -->
    <div class="esb-plans-heading">
        <h2><?php esc_html_e( 'Choose Your Monthly Verification Plan', 'easysender-email-verification' ); ?></h2>
        <p><?php esc_html_e( 'Each plan gives you a monthly credit allowance — credits refresh automatically on your billing date. Upgrade, downgrade, or cancel anytime from your EasyDMARC account.', 'easysender-email-verification' ); ?></p>
    </div>

    <!-- Trust Badges -->
    <div class="esb-trust-badges">
        <span class="esb-trust-badge"><span class="esb-trust-check">&check;</span> <?php esc_html_e( 'Credits refresh monthly', 'easysender-email-verification' ); ?></span>
        <span class="esb-trust-badge"><span class="esb-trust-check">&check;</span> <?php esc_html_e( 'Cancel anytime', 'easysender-email-verification' ); ?></span>
        <span class="esb-trust-badge"><span class="esb-trust-check">&check;</span> <?php esc_html_e( 'Upgrade or downgrade anytime', 'easysender-email-verification' ); ?></span>
        <span class="esb-trust-badge"><span class="esb-trust-check">&check;</span> <?php esc_html_e( 'Billed securely on EasyDMARC.com', 'easysender-email-verification' ); ?></span>
    </div>

    <!-- Subscription Notice Banner -->
    <div class="esb-sub-notice">
        <span class="esb-sub-notice-icon">&oplus;</span>
        <div>
            <strong><?php esc_html_e( 'These are monthly subscription plans, not one-time credits.', 'easysender-email-verification' ); ?></strong>
            <?php esc_html_e( 'Your selected number of verifications is included every month, automatically. Subscription and billing are managed securely on', 'easysender-email-verification' ); ?>
            <strong>EasyDMARC.com</strong> —
            <?php
            printf(
                /* translators: %s: "Subscribe" button label reference */
                esc_html__( 'clicking "%s" below will open your EasyDMARC account to complete checkout.', 'easysender-email-verification' ),
                esc_html__( 'Subscribe', 'easysender-email-verification' )
            );
            ?>
        </div>
    </div>

    <!-- Two-column layout -->
    <div class="esb-plans-layout">
        <!-- Left column -->
        <div>
            <!-- Package Grid (populated by JS) -->
            <div class="esb-package-grid" id="esb-plans-grid">
                <?php for ( $i = 0; $i < 7; $i++ ) : ?>
                    <div class="esb-skeleton esb-skeleton-card"></div>
                <?php endfor; ?>
            </div>

            <!-- Enterprise Row -->
            <div class="esb-enterprise-row">
                <div>
                    <div class="esb-enterprise-heading"><?php esc_html_e( 'Need over 1,000,000 verifications / month?', 'easysender-email-verification' ); ?></div>
                    <div class="esb-enterprise-sub"><?php esc_html_e( 'Custom volume pricing — typically 40–60% off. Talk to the team.', 'easysender-email-verification' ); ?></div>
                </div>
                <a href="https://easydmarc.com/contact-sales" target="_blank" rel="noopener noreferrer" class="esb-btn esb-btn-secondary" id="esb-contact-sales">
                    <?php esc_html_e( 'Contact Sales', 'easysender-email-verification' ); ?> &rarr;
                </a>
            </div>

            <!-- FAQ Strip -->
            <div class="esb-faq-strip">
                <div class="esb-faq-card">
                    <div class="esb-faq-title"><?php esc_html_e( 'Do unused credits roll over?', 'easysender-email-verification' ); ?></div>
                    <div class="esb-faq-body"><?php esc_html_e( "No — credits refresh each billing cycle. Unused credits from the previous month don't carry forward.", 'easysender-email-verification' ); ?></div>
                </div>
                <div class="esb-faq-card">
                    <div class="esb-faq-title"><?php esc_html_e( 'Can I change plans?', 'easysender-email-verification' ); ?></div>
                    <div class="esb-faq-body"><?php esc_html_e( 'Yes, upgrade or downgrade anytime from your EasyDMARC account. Changes take effect at the next billing cycle.', 'easysender-email-verification' ); ?></div>
                </div>
                <div class="esb-faq-card">
                    <div class="esb-faq-title"><?php esc_html_e( 'Where do I manage billing?', 'easysender-email-verification' ); ?></div>
                    <div class="esb-faq-body"><?php esc_html_e( 'All billing, invoices, and subscription settings live in your EasyDMARC account at easydmarc.com.', 'easysender-email-verification' ); ?></div>
                </div>
            </div>
        </div>

        <!-- Right panel -->
        <div class="esb-right-panel">
            <div class="esb-selected-plan-card">
                <div class="esb-selected-header"><?php esc_html_e( 'Selected Plan', 'easysender-email-verification' ); ?></div>
                <div class="esb-selected-body" id="esb-right-panel-body">
                    <div class="esb-skeleton esb-skeleton-line" style="width:60%;height:28px;"></div>
                    <div class="esb-skeleton esb-skeleton-line" style="width:80%;"></div>
                    <div class="esb-skeleton esb-skeleton-line" style="width:70%;"></div>
                    <div class="esb-skeleton esb-skeleton-line" style="width:90%;"></div>
                </div>
            </div>

            <!-- Redirect Notice -->
            <div class="esb-redirect-notice">
                <span class="esb-redirect-lock">&lock;</span>
                <div>
                    <?php
                    printf(
                        /* translators: %1$s: "Subscribe", %2$s: "EasyDMARC.com" (bold) */
                        esc_html__( 'Clicking "%1$s" opens %2$s where you\'ll complete your subscription securely. Your plan will activate and sync back to this plugin instantly.', 'easysender-email-verification' ),
                        esc_html__( 'Subscribe', 'easysender-email-verification' ),
                        '<strong>EasyDMARC.com</strong>'
                    );
                    ?>
                </div>
            </div>

            <!-- Subscribe Button -->
            <button type="button" class="esb-subscribe-btn" id="esb-subscribe-btn" aria-label="<?php esc_attr_e( 'Subscribe — opens EasyDMARC.com', 'easysender-email-verification' ); ?>">
                <?php esc_html_e( 'Subscribe', 'easysender-email-verification' ); ?> &nearrow;
            </button>

            <div class="esb-popup-fallback" id="esb-popup-fallback"></div>

            <!-- Footnote -->
            <div class="esb-footnote">
                <?php
                printf(
                    /* translators: %1$s: easydmarc.com link, %2$s: Terms of Service link */
                    esc_html__( "You'll be taken to %1\$s to complete checkout. By subscribing you agree to EasyDMARC's %2\$s.", 'easysender-email-verification' ),
                    '<a href="https://easydmarc.com/" target="_blank" rel="noopener noreferrer">easydmarc.com</a>',
                    '<a href="https://easydmarc.com/legal" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Terms of Service', 'easysender-email-verification' ) . '</a>'
                );
                ?>
            </div>
        </div>
    </div>
    <?php
}

