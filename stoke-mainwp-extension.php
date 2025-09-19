<?php
/**
 * Plugin Name:       Stoke MainWP Ops & Reporting
 * Description:       MainWP extension providing uptime, Search Console, and reporting integrations for managed sites.
 * Version:           0.1.0
 * Author:            Stoke Design Co
 * Text Domain:       stoke-mainwp-extension
 * Requires at least: 6.4
 * Requires PHP:      8.1
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Stoke_MainWP_Extension' ) ) {

    /**
     * Main plugin bootstrap.
     */
    final class Stoke_MainWP_Extension {

        /**
         * Option key for global settings.
         *
         * @var string
         */
        private const SETTINGS_OPTION = 'stoked_mainwp_settings';

        /**
         * Option key for per-site metadata.
         *
         * @var string
         */
        private const SITE_META_OPTION = 'stoked_mainwp_site_meta';

        /**
         * Instance of the plugin.
         *
         * @var Stoke_MainWP_Extension|null
         */
        private static ?Stoke_MainWP_Extension $instance = null;

        /**
         * Cached settings for the request lifecycle.
         *
         * @var array<string, mixed>
         */
        private array $settings_cache = array();

        /**
         * Indicates whether inline admin styles have been printed.
         */
        private bool $admin_styles_printed = false;

        /**
         * Retrieves a singleton instance of the plugin.
         */
        public static function instance(): Stoke_MainWP_Extension {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        /**
         * Constructor.
         */
        private function __construct() {
            add_filter( 'mainwp_getextensions', array( $this, 'register_extension' ) );
            add_action( 'admin_init', array( $this, 'handle_settings_save' ) );
            add_action( 'admin_init', array( $this, 'handle_site_meta_save' ) );
            add_action( 'mainwp_manage_sites_edit', array( $this, 'render_site_meta_box' ), 10, 1 );
            add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        }

        /**
         * Plugin activation hook.
         */
        public static function activate(): void {
            $instance = self::instance();
            $instance->ensure_defaults();
        }

        /**
         * Ensures default settings exist.
         */
        private function ensure_defaults(): void {
            $settings = $this->get_settings();

            if ( empty( $settings['connector']['apiToken'] ) ) {
                $settings['connector']['apiToken'] = wp_generate_password( 32, false );
                $this->update_settings( $settings );
            }
        }

        /**
         * Registers the extension with MainWP.
         *
         * @param array<int, array<string, mixed>> $extensions Registered extensions.
         *
         * @return array<int, array<string, mixed>>
         */
        public function register_extension( array $extensions ): array {
            $extensions[] = array(
                'plugin'   => plugin_basename( __FILE__ ),
                'mainwp'   => true,
                'callback' => array( $this, 'render_extension_page' ),
                'name'     => __( 'Ops & Reporting', 'stoke-mainwp-extension' ),
                'slug'     => 'stoke-mainwp-extension',
            );

            return $extensions;
        }

        /**
         * Retrieves plugin settings with defaults applied.
         *
         * @return array<string, mixed>
         */
        public function get_settings(): array {
            if ( ! empty( $this->settings_cache ) ) {
                return $this->settings_cache;
            }

            $defaults = array(
                'uptime'    => array(
                    'baseUrl' => '',
                    'mode'    => 'status-page',
                    'apiKey'  => '',
                ),
                'google'    => array(
                    'clientId'     => '',
                    'clientSecret' => '',
                    'connected'    => false,
                ),
                'connector' => array(
                    'apiToken' => '',
                ),
                'defaults'  => array(
                    'lookerUrl' => '',
                ),
            );

            $settings = get_option( self::SETTINGS_OPTION, array() );
            if ( ! is_array( $settings ) ) {
                $settings = array();
            }

            $settings = wp_parse_args( $settings, $defaults );

            if ( empty( $settings['connector']['apiToken'] ) ) {
                $settings['connector']['apiToken'] = wp_generate_password( 32, false );
                update_option( self::SETTINGS_OPTION, $settings );
            }

            $this->settings_cache = $settings;

            return $settings;
        }

        /**
         * Persists plugin settings and resets caches.
         *
         * @param array<string, mixed> $settings Settings to store.
         */
        private function update_settings( array $settings ): void {
            $this->settings_cache = array();
            update_option( self::SETTINGS_OPTION, $settings );
        }

        /**
         * Handles saving the global settings form.
         */
        public function handle_settings_save(): void {
            if ( ! isset( $_POST['stoked_mainwp_action'] ) ) {
                return;
            }

            if ( 'save_settings' !== sanitize_key( wp_unslash( $_POST['stoked_mainwp_action'] ) ) ) {
                return;
            }

            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            check_admin_referer( 'stoked_mainwp_settings', 'stoked_mainwp_settings_nonce' );

            $settings = $this->get_settings();

            $settings['uptime']['baseUrl'] = isset( $_POST['stoked_uptime_base_url'] ) ? esc_url_raw( wp_unslash( $_POST['stoked_uptime_base_url'] ) ) : '';
            $settings['uptime']['mode']    = isset( $_POST['stoked_uptime_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['stoked_uptime_mode'] ) ) : 'status-page';
            $settings['uptime']['apiKey']  = isset( $_POST['stoked_uptime_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['stoked_uptime_api_key'] ) ) : '';

            $settings['google']['clientId']     = isset( $_POST['stoked_google_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['stoked_google_client_id'] ) ) : '';
            $settings['google']['clientSecret'] = isset( $_POST['stoked_google_client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['stoked_google_client_secret'] ) ) : '';

            $settings['defaults']['lookerUrl'] = isset( $_POST['stoked_default_looker_url'] ) ? esc_url_raw( wp_unslash( $_POST['stoked_default_looker_url'] ) ) : '';

            if ( isset( $_POST['stoked_connector_token'] ) ) {
                $token = sanitize_text_field( wp_unslash( $_POST['stoked_connector_token'] ) );
                if ( '' !== $token ) {
                    $settings['connector']['apiToken'] = $token;
                }
            }

            if ( isset( $_POST['stoked_regenerate_token'] ) ) {
                $settings['connector']['apiToken'] = wp_generate_password( 32, false );
            }

            $this->update_settings( $settings );

            $redirect = wp_get_referer();
            if ( ! $redirect ) {
                $redirect = admin_url( 'admin.php?page=Extensions-Stoke_MainWP_Extension' );
            }

            wp_safe_redirect( add_query_arg( 'stoked-settings-updated', '1', $redirect ) );
            exit;
        }

        /**
         * Handles saving per-site metadata from the MainWP site edit screen.
         */
        public function handle_site_meta_save(): void {
            if ( ! isset( $_POST['stoked_mainwp_site_meta_nonce'] ) ) {
                return;
            }

            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            $nonce = wp_unslash( $_POST['stoked_mainwp_site_meta_nonce'] );

            if ( ! wp_verify_nonce( $nonce, 'stoked_mainwp_site_meta' ) ) {
                return;
            }

            $site_id = isset( $_POST['stoked_mainwp_site_id'] ) ? absint( wp_unslash( $_POST['stoked_mainwp_site_id'] ) ) : 0;
            if ( $site_id <= 0 ) {
                return;
            }

            $looker_url   = isset( $_POST['stoked_looker_url'] ) ? esc_url_raw( wp_unslash( $_POST['stoked_looker_url'] ) ) : '';
            $gsc_property = isset( $_POST['stoked_gsc_property'] ) ? sanitize_text_field( wp_unslash( $_POST['stoked_gsc_property'] ) ) : '';
            $uptime_mode  = isset( $_POST['stoked_uptime_mapping_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['stoked_uptime_mapping_mode'] ) ) : '';

            $status_page_slug = isset( $_POST['stoked_uptime_status_page_slug'] ) ? sanitize_title( wp_unslash( $_POST['stoked_uptime_status_page_slug'] ) ) : '';
            $monitor_ids_raw  = isset( $_POST['stoked_uptime_monitor_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['stoked_uptime_monitor_ids'] ) ) : '';

            $monitor_ids = array();
            if ( '' !== $monitor_ids_raw ) {
                $monitor_ids = array_map( 'sanitize_text_field', array_filter( array_map( 'trim', explode( ',', $monitor_ids_raw ) ) ) );
            }

            $site_meta = $this->get_all_site_meta();

            $site_meta[ (string) $site_id ] = array(
                'lookerUrl'   => $looker_url,
                'gscProperty' => $gsc_property,
                'uptime'      => array(
                    'mode'            => $uptime_mode,
                    'statusPageSlug'  => $status_page_slug,
                    'monitorIds'      => $monitor_ids,
                    'monitorIdsInput' => $monitor_ids_raw,
                ),
            );

            update_option( self::SITE_META_OPTION, $site_meta );
        }

        /**
         * Retrieves all stored site metadata.
         *
         * @return array<string, array<string, mixed>>
         */
        private function get_all_site_meta(): array {
            $meta = get_option( self::SITE_META_OPTION, array() );
            if ( ! is_array( $meta ) ) {
                return array();
            }

            return $meta;
        }

        /**
         * Retrieves metadata for a specific site.
         *
         * @param int $site_id Site ID.
         *
         * @return array<string, mixed>
         */
        private function get_site_meta( int $site_id ): array {
            $meta = $this->get_all_site_meta();
            $key  = (string) $site_id;

            if ( isset( $meta[ $key ] ) && is_array( $meta[ $key ] ) ) {
                return $meta[ $key ];
            }

            $settings = $this->get_settings();

            return array(
                'lookerUrl'   => $settings['defaults']['lookerUrl'] ?? '',
                'gscProperty' => '',
                'uptime'      => array(
                    'mode'            => $settings['uptime']['mode'] ?? 'status-page',
                    'statusPageSlug'  => '',
                    'monitorIds'      => array(),
                    'monitorIdsInput' => '',
                ),
            );
        }

        /**
         * Renders the MainWP extension page.
         */
        public function render_extension_page(): void {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'You do not have permission to access this page.', 'stoke-mainwp-extension' ) );
            }

            $settings    = $this->get_settings();
            $updated     = isset( $_GET['stoked-settings-updated'] );
            $token_value = $settings['connector']['apiToken'];
            ?>
            <div class="wrap">
                <h1><?php esc_html_e( 'Ops & Reporting Settings', 'stoke-mainwp-extension' ); ?></h1>
                <?php if ( $updated ) : ?>
                    <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings updated.', 'stoke-mainwp-extension' ); ?></p></div>
                <?php endif; ?>

                <form method="post">
                    <?php wp_nonce_field( 'stoked_mainwp_settings', 'stoked_mainwp_settings_nonce' ); ?>
                    <input type="hidden" name="stoked_mainwp_action" value="save_settings" />

                    <h2><?php esc_html_e( 'Uptime Kuma', 'stoke-mainwp-extension' ); ?></h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="stoked_uptime_base_url"><?php esc_html_e( 'Base URL', 'stoke-mainwp-extension' ); ?></label></th>
                            <td>
                                <input type="url" class="regular-text" id="stoked_uptime_base_url" name="stoked_uptime_base_url" value="<?php echo esc_attr( $settings['uptime']['baseUrl'] ); ?>" />
                                <p class="description"><?php esc_html_e( 'Example: https://status.example.com', 'stoke-mainwp-extension' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="stoked_uptime_mode"><?php esc_html_e( 'Integration Mode', 'stoke-mainwp-extension' ); ?></label></th>
                            <td>
                                <select id="stoked_uptime_mode" name="stoked_uptime_mode">
                                    <option value="status-page" <?php selected( $settings['uptime']['mode'], 'status-page' ); ?>><?php esc_html_e( 'Status Page', 'stoke-mainwp-extension' ); ?></option>
                                    <option value="badges" <?php selected( $settings['uptime']['mode'], 'badges' ); ?>><?php esc_html_e( 'Badges', 'stoke-mainwp-extension' ); ?></option>
                                    <option value="metrics" <?php selected( $settings['uptime']['mode'], 'metrics' ); ?>><?php esc_html_e( 'Metrics', 'stoke-mainwp-extension' ); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e( 'Choose the preferred integration approach for Uptime Kuma.', 'stoke-mainwp-extension' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="stoked_uptime_api_key"><?php esc_html_e( 'API Key / Credentials', 'stoke-mainwp-extension' ); ?></label></th>
                            <td>
                                <input type="text" class="regular-text" id="stoked_uptime_api_key" name="stoked_uptime_api_key" value="<?php echo esc_attr( $settings['uptime']['apiKey'] ); ?>" />
                                <p class="description"><?php esc_html_e( 'Optional. Required when using Metrics mode with authentication.', 'stoke-mainwp-extension' ); ?></p>
                            </td>
                        </tr>
                    </table>

                    <h2><?php esc_html_e( 'Google Search Console', 'stoke-mainwp-extension' ); ?></h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="stoked_google_client_id"><?php esc_html_e( 'Client ID', 'stoke-mainwp-extension' ); ?></label></th>
                            <td><input type="text" class="regular-text" id="stoked_google_client_id" name="stoked_google_client_id" value="<?php echo esc_attr( $settings['google']['clientId'] ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="stoked_google_client_secret"><?php esc_html_e( 'Client Secret', 'stoke-mainwp-extension' ); ?></label></th>
                            <td><input type="text" class="regular-text" id="stoked_google_client_secret" name="stoked_google_client_secret" value="<?php echo esc_attr( $settings['google']['clientSecret'] ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Connection Status', 'stoke-mainwp-extension' ); ?></th>
                            <td>
                                <?php if ( ! empty( $settings['google']['connected'] ) ) : ?>
                                    <span class="dashicons dashicons-yes" style="color: #46b450;"></span>
                                    <?php esc_html_e( 'Connected', 'stoke-mainwp-extension' ); ?>
                                    <p class="description"><?php esc_html_e( 'OAuth flow implementation pending. Disconnect and reconnect actions will be added in a later release.', 'stoke-mainwp-extension' ); ?></p>
                                <?php else : ?>
                                    <span class="dashicons dashicons-dismiss" style="color: #dc3232;"></span>
                                    <?php esc_html_e( 'Not connected', 'stoke-mainwp-extension' ); ?>
                                    <p class="description"><?php esc_html_e( 'Enter OAuth credentials to prepare for Google Search Console integration.', 'stoke-mainwp-extension' ); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>

                    <h2><?php esc_html_e( 'Defaults & Connector', 'stoke-mainwp-extension' ); ?></h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="stoked_default_looker_url"><?php esc_html_e( 'Default Looker Studio URL', 'stoke-mainwp-extension' ); ?></label></th>
                            <td>
                                <input type="url" class="regular-text" id="stoked_default_looker_url" name="stoked_default_looker_url" value="<?php echo esc_attr( $settings['defaults']['lookerUrl'] ); ?>" />
                                <p class="description"><?php esc_html_e( 'Used when a site does not have a custom Looker Studio report configured.', 'stoke-mainwp-extension' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="stoked_connector_token"><?php esc_html_e( 'Connector API Token', 'stoke-mainwp-extension' ); ?></label></th>
                            <td>
                                <input type="text" readonly class="regular-text code" id="stoked_connector_token" name="stoked_connector_token" value="<?php echo esc_attr( $token_value ); ?>" />
                                <p class="description"><?php esc_html_e( 'Provide this token to the private Looker Studio connector to authenticate requests.', 'stoke-mainwp-extension' ); ?></p>
                                <label><input type="checkbox" name="stoked_regenerate_token" value="1" /> <?php esc_html_e( 'Regenerate token on save', 'stoke-mainwp-extension' ); ?></label>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button( __( 'Save Settings', 'stoke-mainwp-extension' ) ); ?>
                </form>
            </div>
            <?php
        }

        /**
         * Outputs the per-site meta box within the Manage Sites edit screen.
         *
         * @param mixed $site Current site context supplied by MainWP.
         */
        public function render_site_meta_box( $site ): void {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            $site_id = 0;

            if ( is_object( $site ) && isset( $site->id ) ) {
                $site_id = (int) $site->id;
            } elseif ( is_array( $site ) && isset( $site['id'] ) ) {
                $site_id = (int) $site['id'];
            }

            if ( $site_id <= 0 ) {
                return;
            }

            $meta     = $this->get_site_meta( $site_id );
            $uptime   = $this->get_site_uptime_snapshot( $site_id );
            $kpis     = $this->get_cached_search_console_kpis( $site_id );
            $looker   = $meta['lookerUrl'] ?? '';
            $settings = $this->get_settings();

            $status_label = $uptime['status'] ?? 'unknown';
            $uptime24h    = $uptime['uptime24h'] ?? null;
            $uptime7d     = $uptime['uptime7d'] ?? null;

            if ( ! $this->admin_styles_printed ) {
                $this->admin_styles_printed = true;
                ?>
                <style>
                    .stoke-mainwp-ops-reporting .stoke-mainwp-status {
                        margin-bottom: 1em;
                        display: flex;
                        gap: 1em;
                        align-items: center;
                        flex-wrap: wrap;
                    }

                    .stoke-mainwp-ops-reporting .stoke-status {
                        display: inline-flex;
                        align-items: center;
                        padding: 0.25em 0.6em;
                        border-radius: 999px;
                        background: #f1f1f1;
                        text-transform: uppercase;
                        font-weight: 600;
                    }

                    .stoke-mainwp-ops-reporting .stoke-status-up {
                        background: #e6f8ed;
                        color: #22863a;
                    }

                    .stoke-mainwp-ops-reporting .stoke-status-down {
                        background: #fde2e1;
                        color: #d63638;
                    }

                    .stoke-mainwp-ops-reporting .stoke-mainwp-kpis ul {
                        margin: 0 0 1em;
                        padding-left: 1.25em;
                        list-style: disc;
                    }

                    .stoke-mainwp-ops-reporting .stoke-mainwp-kpis li {
                        margin-bottom: 0.25em;
                    }
                </style>
                <?php
            }

            ?>
            <div class="postbox stoke-mainwp-ops-reporting">
                <h2 class="hndle"><span><?php esc_html_e( 'Ops & Reporting', 'stoke-mainwp-extension' ); ?></span></h2>
                <div class="inside">
                    <div class="stoke-mainwp-status">
                        <strong><?php esc_html_e( 'Uptime Status:', 'stoke-mainwp-extension' ); ?></strong>
                        <span class="stoke-status stoke-status-<?php echo esc_attr( strtolower( (string) $status_label ) ); ?>">
                            <?php echo esc_html( strtoupper( (string) $status_label ) ); ?>
                        </span>
                        <?php if ( null !== $uptime24h ) : ?>
                            <span class="stoke-metric"><strong><?php esc_html_e( '24h', 'stoke-mainwp-extension' ); ?>:</strong> <?php echo esc_html( $this->format_percentage( $uptime24h ) ); ?></span>
                        <?php endif; ?>
                        <?php if ( null !== $uptime7d ) : ?>
                            <span class="stoke-metric"><strong><?php esc_html_e( '7d', 'stoke-mainwp-extension' ); ?>:</strong> <?php echo esc_html( $this->format_percentage( $uptime7d ) ); ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="stoke-mainwp-kpis">
                        <h3><?php esc_html_e( 'Search Console (Last 7 days)', 'stoke-mainwp-extension' ); ?></h3>
                        <?php if ( ! empty( $kpis ) && isset( $kpis['clicks'] ) ) : ?>
                            <ul>
                                <li><strong><?php esc_html_e( 'Clicks:', 'stoke-mainwp-extension' ); ?></strong> <?php echo esc_html( number_format_i18n( (float) $kpis['clicks'], 0 ) ); ?></li>
                                <li><strong><?php esc_html_e( 'Impressions:', 'stoke-mainwp-extension' ); ?></strong> <?php echo esc_html( number_format_i18n( (float) $kpis['impressions'], 0 ) ); ?></li>
                                <li><strong><?php esc_html_e( 'CTR:', 'stoke-mainwp-extension' ); ?></strong> <?php echo esc_html( $this->format_percentage( (float) $kpis['ctr'] ) ); ?></li>
                                <li><strong><?php esc_html_e( 'Avg. Position:', 'stoke-mainwp-extension' ); ?></strong> <?php echo esc_html( number_format_i18n( (float) $kpis['position'], 2 ) ); ?></li>
                            </ul>
                        <?php else : ?>
                            <p class="description"><?php esc_html_e( 'KPIs will appear once Google Search Console integration is configured.', 'stoke-mainwp-extension' ); ?></p>
                        <?php endif; ?>
                    </div>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="stoked_looker_url"><?php esc_html_e( 'Looker Studio Report URL', 'stoke-mainwp-extension' ); ?></label></th>
                            <td>
                                <input type="url" class="regular-text" name="stoked_looker_url" id="stoked_looker_url" value="<?php echo esc_attr( $looker ); ?>" />
                                <?php if ( ! empty( $looker ) ) : ?>
                                    <p><a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( $looker ); ?>"><?php esc_html_e( 'Open Report', 'stoke-mainwp-extension' ); ?></a></p>
                                <?php elseif ( ! empty( $settings['defaults']['lookerUrl'] ) ) : ?>
                                    <p class="description"><?php esc_html_e( 'No custom report set. The default Looker Studio report will be used.', 'stoke-mainwp-extension' ); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="stoked_gsc_property"><?php esc_html_e( 'Search Console Property', 'stoke-mainwp-extension' ); ?></label></th>
                            <td>
                                <input type="text" class="regular-text" name="stoked_gsc_property" id="stoked_gsc_property" value="<?php echo esc_attr( $meta['gscProperty'] ?? '' ); ?>" />
                                <p class="description"><?php esc_html_e( 'Example: https://example.com/ or sc-domain:example.com', 'stoke-mainwp-extension' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="stoked_uptime_mapping_mode"><?php esc_html_e( 'Uptime Mode', 'stoke-mainwp-extension' ); ?></label></th>
                            <td>
                                <select name="stoked_uptime_mapping_mode" id="stoked_uptime_mapping_mode">
                                    <option value="" <?php selected( '', $meta['uptime']['mode'] ?? '' ); ?>><?php esc_html_e( 'Use default', 'stoke-mainwp-extension' ); ?></option>
                                    <option value="status-page" <?php selected( 'status-page', $meta['uptime']['mode'] ?? '' ); ?>><?php esc_html_e( 'Status Page', 'stoke-mainwp-extension' ); ?></option>
                                    <option value="badges" <?php selected( 'badges', $meta['uptime']['mode'] ?? '' ); ?>><?php esc_html_e( 'Badges', 'stoke-mainwp-extension' ); ?></option>
                                    <option value="metrics" <?php selected( 'metrics', $meta['uptime']['mode'] ?? '' ); ?>><?php esc_html_e( 'Metrics', 'stoke-mainwp-extension' ); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="stoked_uptime_status_page_slug"><?php esc_html_e( 'Status Page Slug', 'stoke-mainwp-extension' ); ?></label></th>
                            <td>
                                <input type="text" class="regular-text" name="stoked_uptime_status_page_slug" id="stoked_uptime_status_page_slug" value="<?php echo esc_attr( $meta['uptime']['statusPageSlug'] ?? '' ); ?>" />
                                <p class="description"><?php esc_html_e( 'Required for Status Page mode.', 'stoke-mainwp-extension' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="stoked_uptime_monitor_ids"><?php esc_html_e( 'Monitor ID(s)', 'stoke-mainwp-extension' ); ?></label></th>
                            <td>
                                <input type="text" class="regular-text" name="stoked_uptime_monitor_ids" id="stoked_uptime_monitor_ids" value="<?php echo esc_attr( $meta['uptime']['monitorIdsInput'] ?? '' ); ?>" />
                                <p class="description"><?php esc_html_e( 'Comma-separated monitor IDs for Badge or Metrics modes.', 'stoke-mainwp-extension' ); ?></p>
                            </td>
                        </tr>
                    </table>

                    <?php wp_nonce_field( 'stoked_mainwp_site_meta', 'stoked_mainwp_site_meta_nonce' ); ?>
                    <input type="hidden" name="stoked_mainwp_site_id" value="<?php echo esc_attr( (string) $site_id ); ?>" />
                </div>
            </div>
            <?php
        }

        /**
         * Formats a floating point number as a percentage string.
         *
         * @param float $value Percentage in decimal form (0-1 or 0-100).
         */
        private function format_percentage( float $value ): string {
            if ( $value > 1.0 ) {
                $value = $value / 100;
            }

            return sprintf( '%0.2f%%', $value * 100 );
        }

        /**
         * Returns cached uptime data for a site.
         *
         * @param int $site_id Site ID.
         *
         * @return array<string, mixed>
         */
        private function get_site_uptime_snapshot( int $site_id ): array {
            $cache_key = 'stoked_mainwp_uptime_' . $site_id;
            $cached    = get_transient( $cache_key );
            if ( false !== $cached && is_array( $cached ) ) {
                return $cached;
            }

            $data = array(
                'status'    => 'unknown',
                'uptime24h' => null,
                'uptime7d'  => null,
                'pingMs'    => null,
                'lastChange' => null,
            );

            set_transient( $cache_key, $data, MINUTE_IN_SECONDS * 10 );

            return $data;
        }

        /**
         * Retrieves cached Search Console KPIs for a site.
         *
         * @param int $site_id Site ID.
         *
         * @return array<string, mixed>
         */
        private function get_cached_search_console_kpis( int $site_id ): array {
            $cache_key = 'stoked_mainwp_gsc_' . $site_id;
            $cached    = get_transient( $cache_key );
            if ( false !== $cached && is_array( $cached ) ) {
                return $cached;
            }

            return array();
        }

        /**
         * Registers REST API routes for Looker Studio and other consumers.
         */
        public function register_rest_routes(): void {
            register_rest_route(
                'stoked-mainwp/v1',
                '/sites',
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'rest_get_sites' ),
                    'permission_callback' => array( $this, 'rest_permission_check' ),
                )
            );

            register_rest_route(
                'stoked-mainwp/v1',
                '/sites/(?P<id>\d+)/uptime',
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'rest_get_site_uptime' ),
                    'permission_callback' => array( $this, 'rest_permission_check' ),
                    'args'                => array(
                        'id' => array(
                            'type'              => 'integer',
                            'required'          => true,
                            'sanitize_callback' => 'absint',
                        ),
                    ),
                )
            );

            register_rest_route(
                'stoked-mainwp/v1',
                '/sites/(?P<id>\d+)/search-console',
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'rest_get_site_kpis' ),
                    'permission_callback' => array( $this, 'rest_permission_check' ),
                    'args'                => array(
                        'id' => array(
                            'type'              => 'integer',
                            'required'          => true,
                            'sanitize_callback' => 'absint',
                        ),
                        'start' => array(
                            'type'              => 'string',
                            'required'          => false,
                            'sanitize_callback' => array( $this, 'sanitize_date_param' ),
                        ),
                        'end' => array(
                            'type'              => 'string',
                            'required'          => false,
                            'sanitize_callback' => array( $this, 'sanitize_date_param' ),
                        ),
                    ),
                )
            );

            register_rest_route(
                'stoked-mainwp/v1',
                '/rollups/kpis',
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'rest_get_rollup_kpis' ),
                    'permission_callback' => array( $this, 'rest_permission_check' ),
                    'args'                => array(
                        'start' => array(
                            'type'              => 'string',
                            'required'          => false,
                            'sanitize_callback' => array( $this, 'sanitize_date_param' ),
                        ),
                        'end' => array(
                            'type'              => 'string',
                            'required'          => false,
                            'sanitize_callback' => array( $this, 'sanitize_date_param' ),
                        ),
                    ),
                )
            );
        }

        /**
         * Sanitizes REST API date parameters.
         *
         * @param string $value Raw value.
         */
        public function sanitize_date_param( string $value ): string {
            $value = sanitize_text_field( $value );

            if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
                return $value;
            }

            $timestamp = strtotime( $value );

            if ( false === $timestamp ) {
                return gmdate( 'Y-m-d' );
            }

            return gmdate( 'Y-m-d', $timestamp );
        }

        /**
         * REST permission callback.
         *
         * @param WP_REST_Request $request Request.
         */
        public function rest_permission_check( WP_REST_Request $request ): bool {
            if ( current_user_can( 'manage_options' ) ) {
                return true;
            }

            $settings   = $this->get_settings();
            $token      = $settings['connector']['apiToken'] ?? '';
            $header_key = $request->get_header( 'x-stoke-connector-token' );
            $query_key  = $request->get_param( 'token' );

            if ( ! empty( $token ) && ( hash_equals( $token, (string) $header_key ) || hash_equals( $token, (string) $query_key ) ) ) {
                return true;
            }

            return false;
        }

        /**
         * REST endpoint returning site list.
         *
         * @param WP_REST_Request $request Request.
         */
        public function rest_get_sites( WP_REST_Request $request ): WP_REST_Response {
            $sites      = $this->collect_site_payloads();
            $response   = array_values( $sites );
            $rest_reply = rest_ensure_response( $response );

            return $rest_reply;
        }

        /**
         * REST endpoint returning uptime snapshot for a site.
         *
         * @param WP_REST_Request $request Request.
         */
        public function rest_get_site_uptime( WP_REST_Request $request ): WP_REST_Response {
            $site_id = (int) $request->get_param( 'id' );
            $data    = $this->get_site_uptime_snapshot( $site_id );

            return rest_ensure_response( $data );
        }

        /**
         * REST endpoint returning Search Console KPIs for a site.
         *
         * @param WP_REST_Request $request Request.
         */
        public function rest_get_site_kpis( WP_REST_Request $request ): WP_REST_Response {
            $site_id = (int) $request->get_param( 'id' );
            $start   = $request->get_param( 'start' );
            $end     = $request->get_param( 'end' );

            $data = $this->get_cached_search_console_kpis( $site_id );

            $data['start'] = $start ? (string) $start : null;
            $data['end']   = $end ? (string) $end : null;

            return rest_ensure_response( $data );
        }

        /**
         * REST endpoint returning rollup KPIs.
         *
         * @param WP_REST_Request $request Request.
         */
        public function rest_get_rollup_kpis( WP_REST_Request $request ): WP_REST_Response {
            $sites = $this->collect_site_payloads();

            $totals = array(
                'clicks'      => 0.0,
                'impressions' => 0.0,
                'ctr'         => 0.0,
                'position'    => 0.0,
                'sites'       => count( $sites ),
            );

            $with_position = 0;

            foreach ( $sites as $site ) {
                $kpis = $this->get_cached_search_console_kpis( (int) $site['id'] );

                if ( isset( $kpis['clicks'] ) ) {
                    $totals['clicks'] += (float) $kpis['clicks'];
                }

                if ( isset( $kpis['impressions'] ) ) {
                    $totals['impressions'] += (float) $kpis['impressions'];
                }

                if ( isset( $kpis['ctr'] ) ) {
                    $totals['ctr'] += (float) $kpis['ctr'];
                }

                if ( isset( $kpis['position'] ) ) {
                    $totals['position'] += (float) $kpis['position'];
                    ++$with_position;
                }
            }

            if ( $totals['sites'] > 0 ) {
                $totals['ctr'] = $totals['ctr'] / max( 1, $totals['sites'] );
            }

            if ( $with_position > 0 ) {
                $totals['position'] = $totals['position'] / $with_position;
            }

            return rest_ensure_response( $totals );
        }

        /**
         * Builds an array of site payloads for REST responses.
         *
         * @return array<int, array<string, mixed>>
         */
        private function collect_site_payloads(): array {
            $meta  = $this->get_all_site_meta();
            $sites = array();

            foreach ( $meta as $site_id => $site_meta ) {
                $site_details = $this->get_mainwp_site_details( (int) $site_id );

                $sites[] = array(
                    'id'           => (int) $site_id,
                    'name'         => $site_details['name'] ?? '',
                    'domain'       => $site_details['url'] ?? '',
                    'lookerUrl'    => $site_meta['lookerUrl'] ?? '',
                    'gscProperty'  => $site_meta['gscProperty'] ?? '',
                    'uptime'       => $site_meta['uptime'] ?? array(),
                    'defaultUrl'   => $this->get_settings()['defaults']['lookerUrl'] ?? '',
                );
            }

            return $sites;
        }

        /**
         * Attempts to load MainWP site details when available.
         *
         * @param int $site_id Site ID.
         *
         * @return array<string, string>
         */
        private function get_mainwp_site_details( int $site_id ): array {
            if ( ! class_exists( 'MainWP_DB' ) ) {
                return array();
            }

            $db = null;
            if ( method_exists( 'MainWP_DB', 'instance' ) ) {
                $db = MainWP_DB::instance();
            } elseif ( method_exists( 'MainWP_DB', 'Instance' ) ) {
                $db = MainWP_DB::Instance();
            }

            if ( ! $db || ! method_exists( $db, 'getWebsiteById' ) ) {
                return array();
            }

            $website = $db->getWebsiteById( $site_id );
            if ( ! $website ) {
                return array();
            }

            $name = '';
            $url  = '';

            if ( is_object( $website ) ) {
                $name = isset( $website->name ) ? (string) $website->name : '';
                $url  = isset( $website->url ) ? (string) $website->url : '';
            } elseif ( is_array( $website ) ) {
                $name = isset( $website['name'] ) ? (string) $website['name'] : '';
                $url  = isset( $website['url'] ) ? (string) $website['url'] : '';
            }

            return array(
                'name' => $name,
                'url'  => $url,
            );
        }
    }
}

Stoke_MainWP_Extension::instance();

register_activation_hook( __FILE__, array( 'Stoke_MainWP_Extension', 'activate' ) );
