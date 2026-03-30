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

    wp_enqueue_style('easysender-admin', plugins_url('assets/css/admin.css', __FILE__), [], '1.0.0');

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $tab  = isset($_GET['tab'])  ? sanitize_key(wp_unslash($_GET['tab']))  : 'api';

    if ($page !== 'easysender_settings') return;

    if ($tab === 'api') {
        wp_enqueue_script('easysender-admin-api', plugins_url('assets/js/admin-api.js', __FILE__), [], '1.0.0', true);
        wp_localize_script('easysender-admin-api', 'easysenderApiData', ['nonce' => wp_create_nonce('easysender_verify_api_key')]);
    }
    if ($tab === 'test') {
        wp_enqueue_script('easysender-admin-test', plugins_url('assets/js/admin-test.js', __FILE__), [], '1.0.0', true);
        wp_localize_script('easysender-admin-test', 'easysenderTestData', ['nonce' => wp_create_nonce('easysender_test_email')]);
    }
    if ($tab === 'usage') {
        wp_enqueue_script('easysender-admin-usage', plugins_url('assets/js/admin-usage.js', __FILE__), [], '1.0.7', true);
        wp_localize_script('easysender-admin-usage', 'easysenderUsageData', ['nonce' => wp_create_nonce('easysender_get_usage')]);
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

    add_submenu_page(
        'easysender_welcome',
        'Domain Scanner',
        'Domain Scanner',
        'manage_options',
        'easysender_domain_scanner',
        'easysender_domain_scanner_page'
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

// Welcome page callback (with tabs)
function easysender_welcome_page() {
    $logo_url = plugins_url('assets/images/EasyDmarc.svg', __FILE__);
    ?>
    <div class="wrap">
        <a href="https://easydmarc.com/" target="_blank" rel="noopener noreferrer">
            <img src="<?php echo esc_url($logo_url); ?>" alt="EasySender Logo" style="height:50px; vertical-align:middle; margin-right:10px;" />
        </a>
        <h1>Welcome to EasySender Email Verification</h1>

        <h2 class="nav-tab-wrapper">
            <a href="?page=easysender_welcome" class="nav-tab nav-tab-active">Welcome</a>
            <a href="?page=easysender_settings&tab=api" class="nav-tab">Settings</a>
        </h2>

        <div style="margin-top: 20px;">
            <p>Thank you for installing EasySender! This plugin helps you verify email addresses directly from your WordPress site using EasyDMARC's API.</p>

            <h2>Getting Started</h2>
            <ol>
                <li><strong><a href="https://app.easydmarc.com/register" target="_blank" rel="noopener noreferrer">Sign Up</a></strong> for an EasyDMARC account.</li>
                <li><strong><a href="https://app.easydmarc.com/login" target="_blank" rel="noopener noreferrer">Sign In</a></strong>  to your account and switch to <strong> EasySender</strong>  (upper-left corner). From the left-hand <strong> Settings</strong>  menu, select <strong> API</strong> , then click <strong> Create API Key.</strong></li>
                <li>Copy your API Client ID and Client Secret (or API Key).</li>
                <li>
                    Go to <strong>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=easysender_settings&tab=api' ) ); ?>">
                            <?php esc_html_e( 'Settings', 'easysender-email-verification' ); ?>
                        </a>
                    </strong>
                    <?php esc_html_e( 'and paste your credentials.', 'easysender-email-verification' ); ?>
                </li>
                <li>Configure other settings and start verifying emails!</li>
            </ol>

            <p>
                <a href="https://app.easydmarc.com/login" target="_blank" rel="noopener noreferrer" class="button button-primary">Sign In</a>
                <a href="https://app.easydmarc.com/register" target="_blank" rel="noopener noreferrer" class="button">Sign Up</a>
            </p>

            <h2>Resources</h2>
            <ul>
                <li><a href="https://easydmarc.com/easysender" target="_blank" rel="noopener noreferrer">Learn More About Email Deliverability</a></li>
                <li><a href="https://sender-api.easydmarc.com/" target="_blank" rel="noopener noreferrer">EasyDMARC API Documentation</a></li>
            </ul>

            <p style="margin-top:30px;">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=easysender_settings&tab=api' ) ); ?>"
                        class="button button-primary button-large">
                    <?php esc_html_e( 'Go to Settings', 'easysender-email-verification' ); ?>
                </a>
            </p>
        </div>
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
    ?>
    <input
            type="text"
            name="easysender_error_messages[<?php echo esc_attr( $id ); ?>]"
            value="<?php echo esc_attr( $value ); ?>"
            class="regular-text"
    />
    <?php
}


function easysender_api_key_actions_render() {
    ?>
    <div style="margin: 8px 0 2px;">
        <button type="button" class="button button-secondary" id="easysender-verify-api-key">Verify API Key</button>
        <a href="https://app.easydmarc.com/login" target="_blank" rel="noopener noreferrer" class="button">Generate Key</a>
        <span id="easysender-verify-status" style="margin-left:8px;"></span>
    </div>
    <?php
}

function easysender_integrations_render() {
    $opts   = get_option( 'easysender_settings', [] );
    $checks = easysender_plugin_checks();

    ?>
    <div class="easysender-integration-grid" style="display:grid; gap:10px; max-width:640px;">
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
                $note = __( ' (not available in this version)', 'easysender-email-verification' );
            } elseif ( ! $installed ) {
                $note = __( ' (not installed)', 'easysender-email-verification' );
            } elseif ( ! $active ) {
                $note = __( ' (installed, not active)', 'easysender-email-verification' );
            }
            ?>
            <label style="display:flex; align-items:center; gap:8px;">
                <input type="hidden" name="easysender_settings[<?php echo esc_attr( $key ); ?>]" value="0" />

                <input
                        type="checkbox"
                        name="easysender_settings[<?php echo esc_attr( $key ); ?>]"
                        value="1"
                    <?php checked( $is_set, true ); ?>
                    <?php disabled( $is_disabled, true ); ?>
                />

                <span <?php echo $is_disabled ? 'style="' . esc_attr( 'opacity:.6;' ) . '"' : ''; ?>>
					<?php echo esc_html( '✅ ' . $label ); ?>
				</span>

                <?php if ( $note ) : ?>
                    <span style="opacity:.6; font-style:italic; margin-left:6px;">
						<?php echo esc_html( $note ); ?>
					</span>
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

    $allowed_tabs = [ 'api', 'usage', 'test', 'messages', 'documentation' ];
    if ( ! in_array( $active_tab, $allowed_tabs, true ) ) {
        $active_tab = 'api';
    }

    $base_url = admin_url( 'admin.php' );
    ?>
    <div class="wrap">
        <h1>EasySender Email Verification Settings</h1>
        <p>Connect Your EasyDMARC Account</p>

        <h2 class="nav-tab-wrapper">
            <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'easysender_settings', 'tab' => 'api' ], $base_url ) ); ?>"
               class="nav-tab <?php echo $active_tab === 'api' ? 'nav-tab-active' : ''; ?>">API Settings</a>

            <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'easysender_settings', 'tab' => 'usage' ], $base_url ) ); ?>"
               class="nav-tab <?php echo $active_tab === 'usage' ? 'nav-tab-active' : ''; ?>">Logging &amp; Usage</a>

            <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'easysender_settings', 'tab' => 'test' ], $base_url ) ); ?>"
               class="nav-tab <?php echo $active_tab === 'test' ? 'nav-tab-active' : ''; ?>">Test Email</a>

            <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'easysender_settings', 'tab' => 'messages' ], $base_url ) ); ?>"
               class="nav-tab <?php echo $active_tab === 'messages' ? 'nav-tab-active' : ''; ?>">Error Messages</a>

            <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'easysender_settings', 'tab' => 'documentation' ], $base_url ) ); ?>"
               class="nav-tab <?php echo $active_tab === 'documentation' ? 'nav-tab-active' : ''; ?>">Documentation</a>
        </h2>

        <div style="margin-top:16px;">
            <?php if ( $active_tab === 'api' ) : ?>
                <form method="post" action="options.php">
                    <?php
                    settings_fields( 'easysender_settings_group' );
                    do_settings_sections( 'easysender_settings' );
                    submit_button();
                    ?>
                </form>

            <?php elseif ( $active_tab === 'messages' ) : ?>
                <form method="post" action="options.php">
                    <?php
                    settings_fields( 'easysender_error_group' );
                    do_settings_sections( 'easysender_error_settings' );
                    submit_button();
                    ?>
                </form>

            <?php elseif ( $active_tab === 'test' ) : ?>
                <?php
                if ( function_exists( 'easysender_render_test_tab' ) ) {
                    easysender_render_test_tab();
                } else {
                    echo '<p style="color:#b91c1c;">Test tab renderer is missing.</p>';
                }
                ?>

            <?php elseif ( $active_tab === 'usage' ) : ?>
                <?php
                if ( function_exists( 'easysender_render_usage_tab' ) ) {
                    easysender_render_usage_tab();
                } else {
                    echo '<p style="color:#b91c1c;">Usage tab renderer is missing.</p>';
                }
                ?>

            <?php elseif ( $active_tab === 'documentation' ) : ?>
                <?php
                if ( function_exists( 'easysender_render_documentation_tab' ) ) {
                    easysender_render_documentation_tab();
                } else {
                    // Simple fallback so tab is never blank
                    ?>
                    <div style="max-width:900px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:16px 20px;">
                        <h2 style="margin-top:0;">Documentation</h2>
                        <p>
                            EasySender verifies email addresses using EasyDMARC’s API.
                            Configure your API credentials in the <strong>API Settings</strong> tab,
                            enable the integrations you want, and use <strong>Test Email</strong> to validate setup.
                        </p>
                        <ul style="margin:0 0 0 18px;">
                            <li><a href="https://sender-api.easydmarc.com/" target="_blank" rel="noopener noreferrer">API Docs</a></li>
                            <li><a href="https://easydmarc.com/privacy-policy/" target="_blank" rel="noopener noreferrer">Privacy Policy</a></li>
                            <li><a href="https://easydmarc.com/terms/" target="_blank" rel="noopener noreferrer">Terms &amp; Conditions</a></li>
                        </ul>
                    </div>
                    <?php
                }
                ?>
            <?php endif; ?>
        </div>
    </div>
    <?php
}


function easysender_render_test_tab() {
    ?>
    <div class="easysender-test-card">
        <div class="easysender-test-header">🔬 Test a Sample Email</div>
        <p class="easysender-subtle" style="margin:6px 0 0;">
            Enter an email address below and click <strong>Verify Email Now</strong> to check it using EasyDMARC.
        </p>

        <div class="easysender-row">
            <input type="email" id="easysender-test-email" class="regular-text" placeholder="name@example.com" />
            <button type="button" class="button button-primary" id="easysender-test-run">🔍 Verify Email Now</button>
        </div>

        <div id="easysender-test-result" class="easysender-result" aria-live="polite">
            <span class="easysender-subtle">Result will appear here…</span>
        </div>

        <p class="easysender-subtle" style="margin-top:8px;">
            (Optional tooltip: <span class="easysender-tooltip" title="Powered by EasyDMARC’s real-time verification engine">Powered by EasyDMARC’s real-time verification engine</span>)
        </p>
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
    ?>
    <div class="easysender-usage-card">
        <div class="easysender-usage-header">📊 Verification Statistics &amp; Limits</div>
        <p class="easysender-usage-subtle">Live credit stats from EasyDMARC.</p>

        <div id="easysender-usage-block">
            <!-- skeleton state -->
            <div class="es-usage-visual">
                <div class="es-donut-wrap">
                    <svg class="es-donut" viewBox="0 0 100 100">
                        <circle cx="50" cy="50" r="44" fill="none" stroke="#f3f4f6" stroke-width="13"/>
                        <circle cx="50" cy="50" r="44" fill="none" stroke="#e5e7eb" stroke-width="13" stroke-dasharray="276 276"/>
                    </svg>
                    <div class="es-donut-inner">
                        <div class="easysender-skeleton" style="height:18px;width:54px;margin-bottom:4px;"></div>
                        <div class="es-donut-sub">credits left</div>
                    </div>
                </div>
                <div class="es-legend">
                    <div class="es-legend-row"><div class="es-legend-dot" style="background:#2563eb"></div><div><div class="easysender-skeleton" style="height:14px;width:70px;"></div><div class="es-legend-lbl">Credits available</div></div></div>
                    <div class="es-legend-row"><div class="es-legend-dot" style="background:#e5e7eb;border:1px solid #d1d5db"></div><div><div class="easysender-skeleton" style="height:14px;width:40px;"></div><div class="es-legend-lbl">Credits used</div></div></div>
                    <div class="es-legend-row"><div class="es-legend-dot" style="background:#bfdbfe"></div><div><div class="easysender-skeleton" style="height:14px;width:60px;"></div><div class="es-legend-lbl">Allocated this period</div></div></div>
                </div>
            </div>
        </div>

        <div class="easysender-usage-cta">
            <a href="https://easydmarc.com/pricing/easysender/email-verification" target="_blank" rel="noopener noreferrer" class="button button-primary">Upgrade Plan for More Verifications</a>
            <a href="https://sender.easydmarc.com/email-verification/api-single" target="_blank" rel="noopener noreferrer" class="button">View Detailed Logs in Dashboard</a>
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



function easysender_domain_scanner_page() {
    ?>
    <div class="wrap">
        <h1>Domain Scanner</h1>
        <p>Launch the EasyDMARC Domain Scanner in a new tab to audit your domain.</p>
        <p>
            <a class="button button-primary" href="https://easydmarc.com/tools/domain-scanner" target="_blank" rel="noopener noreferrer">Open Domain Scanner</a>
        </p>
        <p class="description">For security, external scripts are not embedded directly inside wp-admin.</p>
    </div>
    <?php
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



