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
 * CryptoHelper
 *
 * Provides static methods for encryption and decryption using AES-256-CBC.
 *
 * @category Helpers
 * @package  IjabatImageOffloader
 * @author   Ijabat Tech Solutions, LLC <info@ijabat.org>
 * @license  https://www.gnu.org/licenses/gpl-2.0.html GPL2+
 * @link     https://ijabat.org
 */
class CryptoHelper
{
    /**
     * Encrypt a string using AES-256-CBC with the stored key and IV.
     *
     * This method uses the encryption key and IV loaded from a secure file
     * to encrypt the given plaintext using AES-256-CBC. The result is
     * returned as a base64-encoded ciphertext string.
     *
     * @param string $plaintext The plaintext string to encrypt.
     *
     * @throws \RuntimeException If the encryption key or IV cannot be loaded.
     * @return string Base64-encoded encrypted ciphertext.
     */
    public static function encrypt(string $plaintext): string
    {
        // Load the AES key and IV from the secure JSON file
        $pair = self::loadKeyPair();
        $key  = $pair['key'];
        $iv   = $pair['iv'];

        // Encrypt the plaintext using AES-256-CBC
        $encrypted = openssl_encrypt(
            $plaintext,
            'AES-256-CBC',
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        // Return the result encoded in base64 for safe storage/transmission
        return base64_encode($encrypted);
    }

    /**
     * Decrypt a string using AES-256-CBC with the stored key and IV.
     *
     * This method takes a base64-encoded ciphertext string and decrypts it
     * using AES-256-CBC with the key and IV loaded from a secure file.
     *
     * @param string $ciphertext The base64-encoded encrypted string.
     *
     * @throws \RuntimeException If the encryption key or IV cannot be loaded.
     * @return string|false The decrypted plaintext, or false on failure.
     */
    public static function decrypt(string $ciphertext): string|false
    {
        // Load the AES key and IV from the secure JSON file
        $pair = self::loadKeyPair();
        $key  = $pair['key'];
        $iv   = $pair['iv'];

        // Decode the ciphertext from base64
        $decoded = base64_decode($ciphertext);

        // Decrypt the ciphertext using AES-256-CBC
        return openssl_decrypt(
            $decoded,
            'AES-256-CBC',
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );
    }

    /**
     * Generate and store the encryption key and IV in a private file.
     *
     * @return void
     * @throws \RuntimeException If the secure data directory is not writable.
     */
    public static function generateAndStoreKeys(): void
    {
        // Define secure directory and file
        $dir  = plugin_dir_path(__FILE__) . '../secure-data';
        $file = $dir . '/crypto.json';

        // Create directory if it doesn't exist
        if (!file_exists($dir)) {
            if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new \RuntimeException(
                    'Failed to create secure data directory.'
                );
            }
        }

        // Don't overwrite if the file already exists
        if (!file_exists($file)) {
            $data = [
                'key' => base64_encode(random_bytes(32)), // 256-bit key
                'iv'  => base64_encode(random_bytes(16)), // 128-bit IV
            ];

            if (file_put_contents($file, json_encode($data, JSON_THROW_ON_ERROR)) === false) {
                throw new \RuntimeException(
                    'Failed to write encryption keys to file.'
                );
            }

            chmod($file, 0600); // Restrict file permissions
        }
    }

    /**
     * Load the encryption key and IV from a secure local file.
     *
     * This method reads the base64-encoded AES key and IV stored in a JSON file
     * during plugin activation. It decodes and returns them as raw binary values.
     * If the file is missing or contains invalid data, it throws an exception.
     *
     * @category Security
     * @package  IjabatImageOffloader
     * @author   Ijabat Tech Solutions, LLC <info@ijabat.org>
     * @license  https://www.gnu.org/licenses/gpl-2.0.html GPL2+
     * @link     https://ijabat.org
     *
     * @throws \RuntimeException If the file is missing or the data is invalid.
     * @return array{key: string, iv: string} Associative array with raw binary
     *                    key and IV.
     */
    public static function loadKeyPair(): array
    {
        // Define the path to the encrypted key file
        $file = plugin_dir_path(__FILE__) . '../secure-data/crypto.json';

        // Fail if the file does not exist
        if (!file_exists($file)) {
            throw new \RuntimeException('Crypto key file not found.');
        }

        // Read the entire JSON contents of the file
        $json = file_get_contents($file);

        // Decode the JSON data as an associative array
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        // Ensure both the key and IV are present and not empty
        if (empty($data['key']) || empty($data['iv'])) {
            throw new \RuntimeException('Key or IV is missing from the file.');
        }

        // Return base64-decoded binary values for use in encryption/decryption
        return [
            'key' => base64_decode($data['key']),
            'iv'  => base64_decode($data['iv']),
        ];
    }
}
