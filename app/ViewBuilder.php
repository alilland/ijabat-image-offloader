<?php
/**
 * PHP version 8.2.4
 *
 * @category WordPress_Plugin
 * @package  IjabatImageOffloader
 * @author   Ijabat Tech Solutions, LLC <info@ijabat.org>
 * @license  https://www.gnu.org/licenses/gpl-2.0.html GPL2+
 * @link     https://ijabat.org
 */

namespace IjabatImageOffloader;

/**
 * View Builder
 *
 * Responsible for rendering plugin views.
 *
 * @category Views
 * @package  IjabatImageOffloader
 * @author   Ijabat Tech Solutions, LLC <info@ijabat.org>
 * @license  https://www.gnu.org/licenses/gpl-2.0.html GPL2+
 * @link     https://ijabat.org
 */
class ViewBuilder
{
    /**
     * Base path to view templates.
     *
     * @var string
     */
    private $_viewsPath;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->_viewsPath = plugin_dir_path(__DIR__) . 'views/';
    }

    /**
     * Render a view file.
     *
     * @param string $viewFile File name (e.g. 'settings-page.php')
     * @param array  $data     Optional associative array of data to
     *                         extract into the view
     *
     * @return void
     */
    public function render(string $viewFile, array $data = []): void
    {
        $fullPath = $this->_viewsPath . $viewFile;

        if (!file_exists($fullPath)) {
            // Optional: log or handle missing views
            echo "<p>View not found: {$viewFile}</p>";
            return;
        }

        extract($data); // makes $data['foo'] available as $foo
        include $fullPath;
    }

    /**
     * Render the plugin's settings page view.
     *
     * This method acts as a shortcut to render the settings page by including
     * the appropriate template file and optionally passing view data.
     *
     * @return void
     */
    public function renderSettingsPage(): void
    {

        add_action(
            'admin_footer',
            function () {
                echo <<<HTML
                    <script>
                        document.addEventListener('DOMContentLoaded', function () {
                          document.querySelectorAll('.copy-key-button')
                            .forEach(function (btn) {
                                btn.addEventListener('click', function () {
                                const key = this.getAttribute('data-copy');
                                navigator.clipboard.writeText(key)
                                  .then(() => {
                                      this.innerText = '✔';
                                      setTimeout(() => {
                                        this.innerText = '📋';
                                      }, 1000);
                                  });
                                });
                            });
                        });
                    </script>
                HTML;
            }
        );

        $this->render(
            'settings-page.php',
            [
                // Optional data you want to pass
                // 'option_value' => get_option('ijabat-image-offloader_option'),
            ]
        );
    }

    /**
     * Check if a value is encrypted.
     *
     * @param string $value The string to test.
     *
     * @return bool True if encrypted, false otherwise.
     */
    public static function isEncrypted(string $value): bool
    {
        if (empty($value)) {
            return false;
        }

        try {
            $decrypted = CryptoHelper::decrypt($value);
            return $decrypted !== false && $decrypted !== $value;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Renders a small copy-to-clipboard button next to a setting label.
     *
     * @param string $key The key name to copy (e.g., 'AWS_ACCESS_KEY_ID').
     *
     * @return string HTML output.
     */
    private function _renderCopyButton(string $key): string
    {
        $id = 'copy-btn-' . $key;

        return sprintf(
            ' <button type="button" class="button button-small copy-key-button" data-copy="%s" id="%s" title="Copy setting key">%s</button>',
            esc_attr($key),
            esc_attr($id),
            esc_html('📋')
        );
    }

    /**
     * Register plugin settings, sections, and fields for the admin settings page.
     *
     * This method hooks into 'admin_init' and registers all AWS-related options.
     * Sensitive fields like access keys are input via password fields for masking.
     *
     * @return void
     */
    public function registerSettings(): void
    {
        add_action(
            'admin_init',
            function () {
                // Register the settings group and option name
                register_setting(
                    'ijabat_settings_group',
                    'ijabat_settings',
                    ['sanitize_callback' => [self::class, 'sanitizeSettings']]
                );

                // Add the main settings section
                add_settings_section(
                    'ijabat_main_section',
                    'AWS Config Keys',
                    null,
                    'ijabat-image-offloader'
                );

                // Define all settings fields with labels and whether to mask them
                $fields = [
                  'AWS_ACCESS_KEY_ID' => [
                      'label' => 'AWS_ACCESS_KEY_ID',
                      'mask' => true
                  ],
                  'AWS_SECRET_ACCESS_KEY' => [
                      'label' => 'AWS_SECRET_ACCESS_KEY',
                      'mask' => true
                  ],
                  'AWS_S3_BUCKET' => [
                      'label' => 'AWS_S3_BUCKET'
                  ],
                  'AWS_DEFAULT_REGION' => [
                      'label' => 'AWS_DEFAULT_REGION'
                  ],
                  'AWS_CLOUDFRONT_DOMAIN' => [
                      'label' => 'AWS_CLOUDFRONT_DOMAIN'
                  ]
                ];

                // Add each field to the section
                foreach ($fields as $key => $props) {
                    add_settings_field(
                        $key,
                        esc_html($props['label']) . $this->_renderCopyButton($key),
                        function () use ($key, $props) {
                            $env_value = $_ENV[$key] ?? getenv($key) ?: null;
                            $options = get_option('ijabat_settings', []);
                            $stored_value = $options[$key] ?? '';
                            $type = !empty($props['mask']) ? 'password' : 'text';

                            if (!empty($env_value)) {
                                // ENV set — read-only display
                                echo "<input type='{$type}' value='" . esc_attr($env_value) . "' class='regular-text' readonly disabled style='background-color:#eee;color:#666;' />";
                                echo "<p class='description'>Defined via environment variable</p>";
                            } else {
                                // DB stored — decrypt if needed
                                if ($type === 'password' && !empty($stored_value) && ViewBuilder::isEncrypted($stored_value)) {
                                    $stored_value = CryptoHelper::decrypt($stored_value);
                                }

                                echo "<input type='{$type}' name='ijabat_settings[{$key}]' value='" . esc_attr($stored_value) . "' class='regular-text' />";
                            }
                        },
                        'ijabat-image-offloader',
                        'ijabat_main_section'
                    );
                }
            }
        );
    }

    /**
     * Intercepts settings save and encrypts sensitive fields before storage.
     *
     * @param array $input The raw submitted values.
     *
     * @return array The sanitized and (conditionally) encrypted values.
     */
    public static function sanitizeSettings(array $input): array
    {
        $sensitive_keys = ['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY'];
        $output = [];

        foreach ($input as $key => $value) {
            $value = trim($value);

            // Decode '+' into space (reversing form encoding issues)
            $value = str_replace(' ', '+', $value); // restores '+' in base64 if form converted it to space

            // Encrypt sensitive fields if not already encrypted
            if (in_array($key, $sensitive_keys, true) && !self::isEncrypted($value)) {
                $value = CryptoHelper::encrypt($value);
            }

            $output[$key] = sanitize_text_field($value);
        }

        return $output;
    }
}
