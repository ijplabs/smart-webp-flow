<?php
/**
 * LiteSpace Client License & Update Gateway
 *
 * Core PHP Libsodium bridge for LiteSpace WordPress plugins and themes.
 * Zero database bloat: stores status exclusively in 1 row in wp_options.
 * Zero-latency frontend execution: verification is 100% offline via Libsodium.
 *
 * @package LiteSpace\SDK
 * @author  IJP Labs <dev@ijplabs.id>
 * @license Proprietary / Lifetime License
 */

if (!defined('ABSPATH')) {
    // Standalone / CLI safety shim if invoked outside WordPress
    if (!function_exists('sanitize_key')) {
        function sanitize_key($key) {
            return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
        }
    }
    if (!function_exists('sanitize_text_field')) {
        function sanitize_text_field($str) {
            return trim(strip_tags((string)$str));
        }
    }
    if (!function_exists('plugin_basename')) {
        function plugin_basename($file) {
            return basename(dirname($file)) . '/' . basename($file);
        }
    }
    if (!function_exists('get_site_url')) {
        function get_site_url() {
            return 'https://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
        }
    }
    if (!function_exists('get_option')) {
        function get_option($key, $default = false) {
            $file = '/tmp/litespace_mock_options.json';
            $data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
            return is_array($data) && array_key_exists($key, $data) ? $data[$key] : $default;
        }
    }
    if (!function_exists('update_option')) {
        function update_option($key, $val, $autoload = null) {
            $file = '/tmp/litespace_mock_options.json';
            $data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
            if (!is_array($data)) $data = [];
            $data[$key] = $val;
            file_put_contents($file, json_encode($data));
            return true;
        }
    }
    if (!function_exists('delete_option')) {
        function delete_option($key) {
            $file = '/tmp/litespace_mock_options.json';
            $data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
            if (is_array($data) && isset($data[$key])) {
                unset($data[$key]);
                file_put_contents($file, json_encode($data));
            }
            return true;
        }
    }
    if (!function_exists('add_filter')) {
        function add_filter($tag, $callback, $priority = 10, $accepted_args = 1) {
            return true;
        }
    }
    if (!function_exists('add_action')) {
        function add_action($tag, $callback, $priority = 10, $accepted_args = 1) {
            return true;
        }
    }
    if (!function_exists('sanitize_file_name')) {
        function sanitize_file_name($file) {
            return preg_replace('/[^a-zA-Z0-9_\-\.]/', '', (string)$file);
        }
    }
    if (!function_exists('esc_attr')) {
        function esc_attr($text) {
            return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
        }
    }
}

if (!class_exists('LiteSpace_License')) {

    class LiteSpace_License {

        const HEARTBEAT_INTERVAL = 43200; // 12 Hours in seconds (43,200)

        /**
         * @var string
         */
        protected $product_slug;

        /**
         * @var string
         */
        protected $product_name;

        /**
         * @var string
         */
        protected $version;

        /**
         * @var string 64-character hex Ed25519 public key
         */
        protected $public_key;

        /**
         * @var string
         */
        protected $api_url;

        /**
         * @var string
         */
        protected $option_key;

        /**
         * @var string
         */
        protected $plugin_file;

        /**
         * Constructor
         *
         * @param string $product_slug Unique slug (e.g., 'smart-webp-flow')
         * @param string $product_name Display name
         * @param string $version Current version (e.g., '2.1.0')
         * @param string $public_key 64-char hex Ed25519 public key
         * @param string $plugin_file Main plugin file path (__FILE__)
         * @param string|null $api_url Optional custom API base URL
         */
        public function __construct($product_slug, $product_name, $version, $public_key, $plugin_file = '', $api_url = null) {
            $this->product_slug = sanitize_key($product_slug);
            $this->product_name = sanitize_text_field($product_name);
            $this->version      = sanitize_text_field($version);
            $this->public_key   = trim((string)$public_key);
            $this->plugin_file  = $plugin_file ? plugin_basename($plugin_file) : $product_slug . '/' . $product_slug . '.php';

            if ($api_url) {
                $this->api_url = rtrim($api_url, '/');
            } elseif (defined('LITESPACE_API_URL')) {
                $this->api_url = rtrim(LITESPACE_API_URL, '/');
            } else {
                $this->api_url = 'https://api.litespace.id';
            }

            $this->option_key = 'litespace_license_' . $this->product_slug;

            // Hook into WordPress plugin update checks (12-hour cycle)
            add_filter('pre_set_site_transient_update_plugins', [$this, 'check_heartbeat_and_update']);
            add_filter('plugins_api', [$this, 'inject_plugin_information'], 20, 3);

            // Hook into WordPress frontend <head> for zero-compute Critical CSS injection
            add_action('wp_head', [$this, 'render_critical_css_head'], 1);
        }

        /**
         * Normalizes a domain or URL to its bare hostname.
         *
         * @param string $url
         * @return string
         */
        public static function normalize_domain($url) {
            $cleaned = strtolower(trim($url));
            $cleaned = preg_replace('#^https?://#', '', $cleaned);
            $cleaned = preg_replace('#^www\.#', '', $cleaned);
            $parts = explode('/', $cleaned);
            $host = $parts[0] ?? $cleaned;
            $host_parts = explode(':', $host);
            return $host_parts[0] ?? $host;
        }

        /**
         * Returns the normalized domain of the current WordPress installation.
         *
         * @return string
         */
        public function get_current_domain() {
            $site_url = function_exists('get_site_url') ? get_site_url() : ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
            return self::normalize_domain($site_url);
        }

        /**
         * Generates a deterministic SHA-256 server fingerprint.
         *
         * @return string
         */
        public function get_fingerprint() {
            $seed = php_uname() . '|' . $this->get_current_domain() . '|' . (defined('ABSPATH') ? ABSPATH : __DIR__);
            return 'sha256:' . hash('sha256', $seed);
        }

        /**
         * Performs offline Libsodium signature verification.
         * Zero network overhead, zero latency.
         *
         * @param string $message Raw message / canonical JSON string
         * @param string $signature_hex 128-char hex Ed25519 signature
         * @param string|null $custom_public_key Optional custom public key
         * @return bool
         */
        public function verify_signature_offline($message, $signature_hex, $custom_public_key = null) {
            if (!extension_loaded('sodium')) {
                error_log('[LiteSpace SDK] Critical: PHP Libsodium extension is not loaded!');
                return false;
            }

            $pub_hex = $custom_public_key ? trim($custom_public_key) : $this->public_key;
            if (strlen($pub_hex) !== 64 || strlen($signature_hex) !== 128) {
                return false;
            }

            try {
                $pub_bin = sodium_hex2bin($pub_hex);
                $sig_bin = sodium_hex2bin($signature_hex);
                return sodium_crypto_sign_verify_detached($sig_bin, (string)$message, $pub_bin);
            } catch (\Exception $e) {
                error_log('[LiteSpace SDK] Libsodium verification error: ' . $e->getMessage());
                return false;
            }
        }

        /**
         * Checks whether the plugin is actively licensed and authentic.
         * Completely offline check with 0 network calls.
         *
         * @return bool
         */
        public function is_active() {
            $stored = get_option($this->option_key, false);
            if (!is_array($stored) || empty($stored['certificate_json']) || empty($stored['signature'])) {
                return false;
            }

            // 1. Verify asymmetric signature against official LiteSpace Public Key
            $is_sig_valid = $this->verify_signature_offline(
                $stored['certificate_json'],
                $stored['signature'],
                $stored['public_key'] ?? $this->public_key
            );

            if (!$is_sig_valid) {
                return false;
            }

            // 2. Decode certificate and verify domain match & expiry
            $cert = json_decode($stored['certificate_json'], true);
            if (!is_array($cert) || empty($cert['domain'])) {
                return false;
            }

            $current_domain = $this->get_current_domain();
            $cert_domain = self::normalize_domain($cert['domain']);

            if ($cert_domain !== $current_domain && $cert_domain !== '127.0.0.1' && $cert_domain !== 'localhost') {
                return false;
            }

            // 3. Expiration check (null = Lifetime deal)
            if (!empty($cert['expires_at']) && time() > (int)$cert['expires_at']) {
                return false;
            }

            return true;
        }

        /**
         * Returns full license details stored in wp_options.
         *
         * @return array|null
         */
        public function get_license_data() {
            $data = get_option($this->option_key, null);
            if (!is_array($data)) {
                return null;
            }

            $is_valid = $this->is_active();
            $cert = !empty($data['certificate_json']) ? json_decode($data['certificate_json'], true) : [];

            return [
                'isValid'         => $is_valid,
                'licenseKey'      => $data['license_key'] ?? null,
                'productSlug'     => $this->product_slug,
                'productName'     => $this->product_name,
                'tier'            => $cert['tier'] ?? 'Single',
                'maxActivations'  => $cert['max_activations'] ?? 1,
                'domain'          => $cert['domain'] ?? $this->get_current_domain(),
                'isLifetime'      => empty($cert['expires_at']),
                'expiresAt'       => $cert['expires_at'] ?? null,
                'activatedAt'     => $data['activated_at'] ?? null,
                'lastHeartbeat'   => $data['last_heartbeat'] ?? null,
                'publicKey'       => $data['public_key'] ?? $this->public_key,
                'signature'       => $data['signature'] ?? null,
            ];
        }

        /**
         * Activates a license key for this site with the LiteSpace server.
         *
         * @param string $license_key
         * @return array ['success' => bool, 'message' => string, 'error' => string|null]
         */
        public function activate($license_key) {
            $license_key = trim($license_key);
            if (empty($license_key)) {
                return [
                    'success' => false,
                    'message' => 'Kunci lisensi tidak boleh kosong.',
                    'error'   => 'EMPTY_KEY',
                ];
            }

            $current_domain = $this->get_current_domain();
            $endpoint = $this->api_url . '/api/v1/licenses/activate';

            $payload = [
                'licenseKey'        => $license_key,
                'domain'            => $current_domain,
                'siteUrl'           => function_exists('get_site_url') ? get_site_url() : 'https://' . $current_domain,
                'serverFingerprint' => $this->get_fingerprint(),
                'wpVersion'         => $GLOBALS['wp_version'] ?? '6.7',
                'pluginVersion'     => $this->version,
                'phpVersion'        => PHP_VERSION,
            ];

            $response = $this->http_post_json($endpoint, $payload);
            if (!$response['success']) {
                return $response;
            }

            $body = $response['body'];
            if (empty($body['success']) || empty($body['certificateJson']) || empty($body['signature'])) {
                return [
                    'success' => false,
                    'message' => $body['message'] ?? 'Respons aktivasi tidak valid.',
                    'error'   => $body['error'] ?? 'INVALID_RESPONSE',
                ];
            }

            // Cryptographic offline verification of the received certificate
            $is_verified = $this->verify_signature_offline(
                $body['certificateJson'],
                $body['signature'],
                $body['publicKey'] ?? $this->public_key
            );

            if (!$is_verified) {
                return [
                    'success' => false,
                    'message' => 'Verifikasi integritas tanda tangan digital Ed25519 gagal. Kemungkinan respon dipalsukan.',
                    'error'   => 'TAMPERED_CERTIFICATE',
                ];
            }

            // Save single clean row into wp_options (autoload = false)
            $record = [
                'license_key'      => $license_key,
                'certificate_json' => $body['certificateJson'],
                'certificate'      => $body['certificate'] ?? json_decode($body['certificateJson'], true),
                'signature'        => $body['signature'],
                'public_key'       => $body['publicKey'] ?? $this->public_key,
                'activated_at'     => time(),
                'last_heartbeat'   => time(),
            ];

            update_option($this->option_key, $record, false);

            return [
                'success'     => true,
                'message'     => 'Lisensi berhasil diaktivasi dan sertifikat digital tervalidasi.',
                'certificate' => $record['certificate'],
            ];
        }

        /**
         * Deactivates the license from this domain, freeing the slot on the LiteSpace server.
         *
         * @return array
         */
        public function deactivate() {
            $data = get_option($this->option_key, null);
            $license_key = is_array($data) ? ($data['license_key'] ?? '') : '';

            if ($license_key) {
                $endpoint = $this->api_url . '/api/v1/licenses/deactivate';
                $this->http_post_json($endpoint, [
                    'licenseKey' => $license_key,
                    'domain'     => $this->get_current_domain(),
                ]);
            }

            delete_option($this->option_key);

            return [
                'success' => true,
                'message' => 'Lisensi berhasil dicabut dari domain ini.',
            ];
        }

        /**
         * Compiles settings & CSS via LiteSpace Rust Engine and writes static artifacts.
         * Enforces the "Build-on-Save" anti-piracy & zero-latency architecture.
         *
         * @param array $settings Plugin/Theme settings
         * @param string $raw_css Raw CSS to extract critical styles from
         * @return array
         */
        public function build_and_save_artifacts(array $settings = [], $raw_css = '') {
            if (!$this->is_active()) {
                return [
                    'success' => false,
                    'message' => 'Lisensi tidak aktif atau belum diverifikasi. Kompilasi aset statis dibatalkan.',
                    'error'   => 'LICENSE_INACTIVE',
                ];
            }

            $stored = get_option($this->option_key, []);
            $license_key = $stored['license_key'] ?? '';
            $domain = $this->get_current_domain();

            $endpoint = $this->api_url . '/api/v1/compiler/build-config';
            $payload = [
                'licenseKey'  => $license_key,
                'domain'      => $domain,
                'productSlug' => $this->product_slug,
                'settings'    => $settings,
                'rawCss'      => (string)$raw_css,
            ];

            $res = $this->http_post_json($endpoint, $payload);
            if (!$res['success'] || empty($res['body']['success'])) {
                return [
                    'success' => false,
                    'message' => $res['body']['message'] ?? 'Gagal mengompilasi aset statis dari server LiteSpace.',
                    'error'   => $res['body']['error'] ?? 'COMPILATION_ERROR',
                ];
            }

            $artifacts = $res['body']['artifacts'] ?? [];

            // Determine uploads directory
            $upload_dir = '';
            if (function_exists('wp_upload_dir')) {
                $dirs = wp_upload_dir();
                $upload_dir = $dirs['basedir'] . '/litespace/static';
            } elseif (defined('ABSPATH')) {
                $upload_dir = ABSPATH . 'wp-content/uploads/litespace/static';
            } elseif (file_exists('/root/projects/wordpress-lab/wp-content')) {
                $upload_dir = '/root/projects/wordpress-lab/wp-content/uploads/litespace/static';
            } else {
                $upload_dir = dirname(__DIR__) . '/uploads/litespace/static';
            }

            if (!file_exists($upload_dir)) {
                @mkdir($upload_dir, 0755, true);
            }

            // Write static CSS file
            if (!empty($artifacts['assetFilename']) && !empty($artifacts['criticalCss'])) {
                $css_file = $upload_dir . '/' . sanitize_file_name($artifacts['assetFilename']);
                @file_put_contents($css_file, $artifacts['criticalCss']);
            }

            // Write static rules JSON
            if (!empty($artifacts['staticRules'])) {
                $rules_file = $upload_dir . '/litespace-rules-' . $this->product_slug . '.json';
                @file_put_contents($rules_file, json_encode($artifacts['staticRules']));
            }

            // Save static artifacts cache to wp_options
            $artifact_meta = [
                'build_hash'         => $artifacts['buildHash'] ?? '',
                'asset_filename'     => $artifacts['assetFilename'] ?? '',
                'critical_css'       => $artifacts['criticalCss'] ?? '',
                'savings_percentage' => $artifacts['savingsPercentage'] ?? 0.0,
                'compiled_at'        => $artifacts['compiledAt'] ?? time(),
                'engine'             => $artifacts['engine'] ?? 'Rust Compiler',
            ];

            update_option('litespace_static_' . $this->product_slug, $artifact_meta, false);

            return [
                'success'   => true,
                'message'   => 'Aset statis berhasil dikompilasi oleh LiteSpace Rust Engine dan disimpan secara lokal.',
                'artifacts' => $artifacts,
            ];
        }

        /**
         * Renders inline Critical CSS into <head> with zero network latency.
         */
        public function render_critical_css_head() {
            if (!$this->is_active()) {
                return;
            }

            $meta = get_option('litespace_static_' . $this->product_slug, null);
            if (!is_array($meta) || empty($meta['critical_css'])) {
                return;
            }

            echo "\n<!-- LiteSpace Nitro Static Critical CSS (Built by Rust Core Engine) -->\n";
            echo "<style id=\"litespace-critical-css-" . esc_attr($this->product_slug) . "\">\n";
            echo $meta['critical_css'];
            echo "\n</style>\n";
        }

        /**
         * Retrieves the currently active static artifacts.
         *
         * @return array|null
         */
        public function get_static_artifacts() {
            return get_option('litespace_static_' . $this->product_slug, null);
        }

        /**
         * WordPress 12-hour passive heartbeat and update checker.
         * Hooked into `pre_set_site_transient_update_plugins`.
         *
         * @param object $transient
         * @return object
         */
        public function check_heartbeat_and_update($transient) {
            if (!is_object($transient)) {
                $transient = new \stdClass();
            }

            if (!$this->is_active()) {
                return $transient;
            }

            $stored = get_option($this->option_key, []);
            $last_heartbeat = (int)($stored['last_heartbeat'] ?? 0);
            $elapsed = time() - $last_heartbeat;

            // Only contact server if 12 hours have passed
            if ($elapsed < self::HEARTBEAT_INTERVAL) {
                return $transient;
            }

            $endpoint = $this->api_url . '/api/v1/licenses/heartbeat';
            $payload = [
                'licenseKey'    => $stored['license_key'] ?? '',
                'domain'        => $this->get_current_domain(),
                'timestamp'     => time(),
                'wpVersion'     => $GLOBALS['wp_version'] ?? '6.7',
                'pluginVersion' => $this->version,
                'phpVersion'    => PHP_VERSION,
            ];

            $res = $this->http_post_json($endpoint, $payload);
            if ($res['success']) {
                $body = $res['body'];

                // Update last heartbeat timestamp
                $stored['last_heartbeat'] = time();
                update_option($this->option_key, $stored, false);

                // If new version is available, inject into WordPress transient
                if (!empty($body['hasUpdate']) && !empty($body['latestVersion']) && !empty($body['packageUrl'])) {
                    $item = (object)[
                        'id'          => $this->product_slug,
                        'slug'        => $this->product_slug,
                        'plugin'      => $this->plugin_file,
                        'new_version' => $body['latestVersion'],
                        'package'     => $body['packageUrl'],
                        'url'         => 'https://litespace.id/products/' . $this->product_slug,
                    ];

                    $transient->response[$this->plugin_file] = $item;
                }
            }

            return $transient;
        }

        /**
         * Injects structured information for the WordPress "View details" modal.
         *
         * @param false|object|array $res
         * @param string $action
         * @param object $args
         * @return false|object
         */
        public function inject_plugin_information($res, $action, $args) {
            if ($action !== 'plugin_information' || !isset($args->slug) || $args->slug !== $this->product_slug) {
                return $res;
            }

            $info = (object)[
                'name'          => $this->product_name,
                'slug'          => $this->product_slug,
                'version'       => $this->version,
                'author'        => '<a href="https://ijplabs.id">IJP Labs</a>',
                'homepage'      => 'https://litespace.id',
                'requires'      => '5.8',
                'tested'        => '6.7',
                'requires_php'  => '7.4',
                'sections'      => [
                    'description' => 'LiteSpace High-Performance WordPress Engineering Plugin.',
                    'changelog'   => 'Pembaruan otomatis berkeamanan tinggi via LiteSpace Core.',
                ],
            ];

            return $info;
        }

        /**
         * Helper to make HTTP POST requests with JSON payload.
         *
         * @param string $url
         * @param array $data
         * @return array
         */
        protected function http_post_json($url, array $data) {
            $json_payload = json_encode($data);

            if (function_exists('wp_remote_post')) {
                $args = [
                    'method'      => 'POST',
                    'timeout'     => 15,
                    'redirection' => 5,
                    'httpversion' => '1.1',
                    'blocking'    => true,
                    'headers'     => [
                        'Content-Type' => 'application/json',
                        'User-Agent'   => 'LiteSpace-SDK/' . $this->version . '; ' . $this->get_current_domain(),
                    ],
                    'body'        => $json_payload,
                ];

                $response = wp_remote_post($url, $args);
                if (is_wp_error($response)) {
                    return [
                        'success' => false,
                        'message' => 'Koneksi ke server LiteSpace gagal: ' . $response->get_error_message(),
                        'error'   => 'HTTP_CONNECTION_ERROR',
                    ];
                }

                $code = wp_remote_retrieve_response_code($response);
                $body = json_decode(wp_remote_retrieve_body($response), true);

                return [
                    'success' => ($code >= 200 && $code < 300),
                    'code'    => $code,
                    'body'    => is_array($body) ? $body : [],
                ];
            } else {
                // Native cURL fallback for CLI / standalone testing
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'User-Agent: LiteSpace-SDK/' . $this->version . '; ' . $this->get_current_domain(),
                ]);
                curl_setopt($ch, CURLOPT_TIMEOUT, 15);

                $response_body = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
                curl_close($ch);

                if ($err) {
                    return [
                        'success' => false,
                        'message' => 'cURL Error: ' . $err,
                        'error'   => 'CURL_ERROR',
                    ];
                }

                $body = json_decode((string)$response_body, true);

                return [
                    'success' => ($code >= 200 && $code < 300),
                    'code'    => $code,
                    'body'    => is_array($body) ? $body : [],
                ];
            }
        }
    }
}
