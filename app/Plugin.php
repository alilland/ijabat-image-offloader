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

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use IjabatImageOffloader\WPLogger;
use IjabatImageOffloader\ViewBuilder;
use IjabatImageOffloader\CryptoHelper;

/**
 * Main plugin class for Ijabat Image Offloader.
 *
 * This class handles integration with AWS S3 for media uploads in WordPress.
 * It intercepts various WordPress media functions to upload files and
 * thumbnails to S3, rewrite URLs to point to the S3 bucket (or CloudFront),
 * and clean up local copies.
 *
 * PHP version 8.2.4
 *
 * @category WordPress_Plugin
 * @package  IjabatImageOffloader
 * @author   Ijabat Tech Solutions, LLC <info@ijabat.org>
 * @license  https://www.gnu.org/licenses/gpl-2.0.html GPL2+
 * @link     https://ijabat.org
 * @since    1.0.0
 */
class Plugin
{
    private $_s3;
    private $_bucket;
    private $_region;
    private $_accessKey;
    private $_secretKey;
    private $_localBaseurl;
    private $_localBasedir;
    private $_s3Baseurl;
    private $_logger;
    private $_viewBuilder;

    /**
     * Initializes the class, setting up S3 configurations and adding
     * necessary hooks.
     *
     * @return void
     */
    public function __construct()
    {
        // Initialize the logger for debugging and informational messages
        $this->_logger = new WPLogger(WPLogger::DEBUG);

        // from the options table on the database
        $options = get_option('ijabat_settings', []);

        // set the AWS Bucket
        $this->_bucket = $this->_getBucket($options);

        // set the AWS Region (default us-east-1)
        $this->_region = $this->_getRegion($options);

        // set the AWS Access Key
        $this->_accessKey = $this->_getAccessKey($options);

        // set the AWS Secret Key
        $this->_secretKey = $this->_getSecretKey($options);

        // ...
        $this->_localBaseurl = $this->_getLocalBaseUrl();

        // ...
        $this->_s3Baseurl = $this->_getS3BaseUrl($options);

        // once we have all the variables, we define an S3 object
        $this->_s3 = $this->_defineS3(
            $this->_region,
            $this->_accessKey,
            $this->_secretKey
        );

        // Add WordPress filters and actions for handling S3 functionality
        $this->_setFilters();

        // Initialize the view builder
        $this->_viewBuilder = new ViewBuilder();
        $this->_viewBuilder->registerSettings();

        // called after defining the view builder
        $this->_setActions();

        // Log initialization information
        $this->_logger->debug('*********************************');
        if (str_contains($this->_s3Baseurl, 'cloudfront')) {
            $this->_logger->debug('environment = dotenv');
        } else {
            $this->_logger->debug('environment = database');
        }
        $this->_logger->debug('_bucket = ' . $this->_bucket);
        $this->_logger->debug('_region = ' . $this->_region);
        $this->_logger->debug('_accessKey = ' . $this->_accessKey);
        $this->_logger->debug('_secretKey = ' . $this->_secretKey);
        $this->_logger->debug('_localBaseurl = ' . $this->_localBaseurl);
        $this->_logger->debug('_s3Baseurl = ' . $this->_s3Baseurl);
        $this->_logger->debug('*********************************');
        $this->_compare($options);

        $this->_logger->info('Plugin initialized');
    }

    /**
     * * TEMP METHOD
     *
     * @param $options array
     *
     * @return void
     */
    private function _compare($options)
    {
        $key1 = $_ENV['AWS_ACCESS_KEY_ID'] ?? getenv('AWS_ACCESS_KEY_ID');
        $key2 = CryptoHelper::decrypt($options['AWS_ACCESS_KEY_ID']);

        $this->_logger->debug('AWS_ACCESS_KEY_ID is equal:');
        $this->_logger->debug($key1 == $key2 ? 'true' : 'false');

        $key3 = $_ENV['AWS_SECRET_ACCESS_KEY'] ?? getenv('AWS_SECRET_ACCESS_KEY');
        $key4 = CryptoHelper::decrypt($options['AWS_SECRET_ACCESS_KEY']);

        $this->_logger->debug('AWS_SECRET_ACCESS_KEY is equal:');
        $this->_logger->debug($key3 == $key4 ? 'true' : 'false');
    }


    // * Private Functions
    /**
     * Sets the filters initialized in the constructor
     *
     * @return void
     */
    private function _setFilters()
    {
        add_filter('wp_handle_upload', [$this, 'uploadToS3']);
        add_filter(
            'wp_generate_attachment_metadata',
            [$this, 'uploadThumbnailsToS3'],
            10, 2
        );
        add_filter('wp_get_attachment_url', [$this, 'replaceWithS3Url']);
        add_filter('image_downsize', [$this, 's3ImageDownsize'], 10, 3);
        add_filter(
            'wp_image_editor_supports',
            [$this, 'disableImageEditing'],
            10, 2
        );
        add_filter(
            'wp_get_attachment_image_src',
            [$this, 'rewriteWpGetAttachmentImageSrc'],
            10, 4
        );
        add_filter(
            'wp_get_attachment_image_srcset',
            [$this, 'rewriteWpGetAttachmentImageSrcset'],
            10, 5
        );
        add_filter(
            'wp_calculate_image_srcset',
            [$this, 'rewriteWpCalculateImageSrcset'],
            10, 5
        );
        add_filter('render_block', [$this, 'rewriteBlockImageUrlsToS3'], 10, 2);
        add_filter('the_content', [$this, 'rewriteImageUrlsToS3']);
    }

    /**
     * Sets the actions initialized in the constructor
     *
     * @return void
     */
    private function _setActions()
    {
        // add the Admin Menu View
        add_action('delete_attachment', [$this, 'deleteFromS3']);
        add_action(
            'admin_menu',
            function () {
                add_menu_page(
                    'Ijabat Image Offloader Settings',
                    'Ijabat Offloader',
                    'manage_options',
                    'ijabat-image-offloader',
                    [$this->_viewBuilder, 'renderSettingsPage']
                );
            }
        );
    }

    /**
     * Extract the bucket from environment or options
     *
     * @param $options array of options from options table
     *
     * @return string
     */
    private function _getBucket($options)
    {
        // try and load the AWS Bucket from Environment
        $bucket = sanitize_text_field($_ENV['AWS_S3_BUCKET'] ?? '');

        // if empty, try and load from options table
        if (!$bucket) {
            $bucket = sanitize_text_field($options['AWS_S3_BUCKET'] ?? '');
        }

        return $bucket;
    }

    /**
     * Extract the region from environment or options
     *
     * @param $options array of options from options table
     *
     * @return string
     */
    private function _getRegion($options)
    {
        // try and load the AWS region from Environment
        $region = sanitize_text_field($_ENV['AWS_DEFAULT_REGION'] ?? '');
        // if empty, try and load from options table
        if (!$region) {
            $region = sanitize_text_field($options['AWS_DEFAULT_REGION'] ?? '');
        }
        // if both are empty set a default of us-east-1 to avoid errors
        return $region !== '' ? $region : 'us-east-1';
    }

    /**
     * Extract the access key from environment or options
     *
     * @param $options array of options from options table
     *
     * @return string
     */
    private function _getAccessKey($options)
    {
        // extract from ENV and return if present
        $key = $_ENV['AWS_ACCESS_KEY_ID'] ?? getenv('AWS_ACCESS_KEY_ID');
        if ($key) {
            return $key;
        }

        // pull from database and decrypt if present
        $encrypted_key = $options['AWS_ACCESS_KEY_ID'];
        if ($encrypted_key) {
            return CryptoHelper::decrypt($encrypted_key);
        }
    }

    /**
     * Extract the secret key from environment or options
     *
     * @param $options array of options from options table
     *
     * @return string
     */
    private function _getSecretKey($options)
    {
        // extract from ENV and return if present
        $key = $_ENV['AWS_SECRET_ACCESS_KEY'] ?? getenv('AWS_SECRET_ACCESS_KEY');
        if ($key) {
            return $key;
        }

        // pull from database and decrypt if present
        $secret_key = $options['AWS_SECRET_ACCESS_KEY'];
        if ($secret_key) {
            return CryptoHelper::decrypt($secret_key);
        }
    }

    /**
     * Extract the secret key from environment or options
     *
     * @return string
     */
    private function _getLocalBaseUrl()
    {
        $upload_dir = wp_upload_dir();
        $this->_localBaseurl = $upload_dir['baseurl'];
        $this->_localBasedir = wp_normalize_path($upload_dir['basedir']);
        return $this->_localBaseurl;
    }

    /**
     * Set the S3 base URL using CloudFront domain if available,
     * else default S3 URL
     *
     * @param $options options array
     *
     * @return string the S3 Base URL for images
     */
    private function _getS3BaseUrl($options)
    {

        if (!empty($_ENV['AWS_CLOUDFRONT_DOMAIN'])) {
            return rtrim(
                sanitize_text_field($_ENV['AWS_CLOUDFRONT_DOMAIN']), '/'
            );
        }

        if ($options['AWS_CLOUDFRONT_DOMAIN']) {
            return rtrim(
                sanitize_text_field($options['AWS_CLOUDFRONT_DOMAIN']), '/'
            );
        }

        return sprintf(
            'https://%s.s3.%s.amazonaws.com',
            $this->_bucket,
            $this->_region
        );
    }

    /**
     * If we have all the variables properly defined
     * we should be able to declare an S3 object
     *
     * @param $region AWS region
     * @param $key    AWS access key
     * @param $secret AWS secret access key
     *
     * @return S3Client|void
     */
    private function _defineS3($region, $key, $secret)
    {
        if ($key && $secret) {
            return new S3Client(
                [
                    'version' => 'latest',
                    'region'  => $region,
                    'credentials' => compact('key', 'secret'),
                ]
            );
        }
        return null;
    }

    /**
     * Retrieves the S3 key for a given local file path.
     *
     * Converts a local file path to a relative path to be used as an S3 object key
     * by removing the local base directory portion.
     *
     * @param string $file_path The full path of the file on the local server.
     *
     * @return string The relative S3 object key.
     */
    private function _s3Key($file_path)
    {
        $this->_logger->debug('$file_path value = ' . $file_path);
        $file_path = wp_normalize_path($file_path);
        $key = ltrim(str_replace($this->_localBasedir, '', $file_path), '/');
        $this->_logger->debug('_s3Key value = ' . $key);
        return $key;
    }

    /**
     * Converts a given path to a full S3 URL.
     *
     * Takes a relative path and constructs the full URL pointing to the resource
     * on the configured S3 bucket, ensuring the path is properly formatted.
     *
     * @param string $path The relative path of the file.
     *
     * @return string The full S3 URL for the specified path.
     */
    private function _toS3Url($path)
    {
        // Concatenate the S3 base URL with the normalized path
        return trailingslashit($this->_s3Baseurl) . ltrim($path, '/');
    }

    /**
     * Uploads a file to S3.
     *
     * This method takes a local file path, converts it to an S3 key,
     * and uploads the file to the configured S3 bucket. It logs the
     * success or any errors encountered during the upload process.
     *
     * @param string $path The full path of the file to upload.
     *
     * @return void
     */
    private function _s3Upload($path)
    {
        $this->_logger->info('starting _s3Upload function');
        if (!$this->_s3 || empty($this->_bucket)) {
            $this->_logger->info(
                'Skipping S3 upload — S3 client or bucket not configured.'
            );
            return;
        }

        $key = $this->_s3Key($path);

        try {
            $this->_s3->putObject(
                [
                    'Bucket'      => $this->_bucket,
                    'Key'         => $key,
                    'SourceFile'  => $path,
                    'ContentType' => mime_content_type($path),
                ]
            );
            $this->_logger->info('Uploaded to S3: ' . $key);
        } catch (AwsException $e) {
            $this->_logger->info('S3 Upload Error: ' . $e->getAwsErrorMessage());
        }
    }

    /**
     * Rewrites image source URLs in the srcset to S3 URLs.
     *
     * This method processes an array of image sources, modifying each source's URL
     * to point to the S3 location instead of the local base URL.
     *
     * @param array $sources The array of image sources to be rewritten.
     *
     * @return array The array of rewritten image sources with S3 URLs.
     */
    private function _rewriteSrcset($sources)
    {
        foreach ($sources as &$source) {
            if (!empty($source['url'])) {
                // Replace the local base URL with the S3 base URL in the source URL
                $source['url'] = str_replace(
                    $this->_localBaseurl,
                    $this->_s3Baseurl,
                    $source['url']
                );
            }
        }
        return $sources;
    }

    /**
     * Recursively cleans up empty directories up to the base directory.
     *
     * This method checks the directory hierarchy of a given path and removes
     * any empty directories found, moving upward until the base upload directory
     * is reached or a non-empty directory is found.
     *
     * @param string $path The file or directory path from which to start
     *                     the cleanup.
     *
     * @return void
     */
    private function _maybeCleanupEmptyFolder($path)
    {
        // Start with the directory of the given path
        $dir = dirname($path);

        // Continue until reaching the local base directory
        while ($dir !== $this->_localBasedir) {
            // Check if the directory is valid and empty
            if (is_dir($dir) && count(glob($dir . '/*')) === 0) {
                // Temporarily disable PHPCS for specific rules to allow
                // directory removal
                // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.VIP.FileSystemWritesDisallow.rmdir_rmdir
                rmdir($dir); // Remove the empty directory
                // phpcs:enable
                $dir = dirname($dir); // Move up to the parent directory
            } else {
                break; // Exit if a non-empty directory is found
            }
        }
    }


    // * Public Functions
    /**
     * Handles the uploading of a file to S3 after a WordPress upload.
     *
     * This method checks for the presence of a file in the upload array
     * and uploads it to the configured S3 bucket. The local file is not
     * deleted in this method to ensure redundancy or other operations
     * can be performed if needed.
     *
     * @param array $upload An associative array containing file upload details,
     *                      including the path to the file ('file' key).
     *
     * @return array The original upload array, potentially modified.
     */
    public function uploadToS3($upload)
    {
        // Verify that the file path exists in the upload array
        if (empty($upload['file'])) {
            return $upload;
        };

        // Store the file path for S3 upload
        $file_path = $upload['file'];

        // Upload the file to S3
        $this->_s3Upload($file_path);

        // 🚫 Do not unlink here anymore to retain the local copy of the file
        return $upload;
    }

    /**
     * Uploads image thumbnails and originals to S3 after metadata generation.
     *
     * This method uploads the main image file (and any non-scaled original
     * if necessary) as well as all generated image thumbnails to an S3 bucket.
     * After successful upload, it deletes the local copies of those files to
     * save space. It also checks for and cleans up any empty directories left
     * behind.
     *
     * @param array $metadata      The metadata for the image attachment,
     *                             including size info.
     * @param int   $attachment_id The ID of the attachment whose files are
     *                             being uploaded.
     *
     * @return array The metadata array, unmodified.
     */
    public function uploadThumbnailsToS3($metadata, $attachment_id)
    {
        // Retrieve the main file associated with the attachment
        $file = get_attached_file($attachment_id);
        if (!$file || !$metadata) {
            return $metadata;
        };

        // Start with the main file path, including scaled versions if applicable
        $paths = [$file];

        $dirname = pathinfo($file, PATHINFO_DIRNAME);
        $scaled_filename = pathinfo($file, PATHINFO_BASENAME);

        // Check if a non-scaled version exists and add it for upload/delete
        if (strpos($scaled_filename, '-scaled') !== false) {
            $original_filename = str_replace('-scaled', '', $scaled_filename);
            $original_file_path = $dirname . '/' . $original_filename;

            if (file_exists($original_file_path)) {
                $this->_logger->info(
                    'Found original file to delete: ' .
                    $original_file_path
                );
                $paths[] = $original_file_path;
            }
        }

        // Include all thumbnail sizes for upload
        if (!empty($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size) {
                $paths[] = $dirname . '/' . $size['file'];
            }
        }

        foreach ($paths as $path) {
            if (file_exists($path)) {
                // Upload the file to S3 and then delete the local version
                $this->_s3Upload($path);
                wp_delete_file($path);
                $this->_logger->info('Deleted local: ' . $path);
                $this->_maybeCleanupEmptyFolder($path);
            } else {
                $this->_logger->info(
                    'Warning: File not found for S3 upload: ' .
                    $path
                );
            }
        }

        return $metadata;
    }

    /**
     * Replaces a local attachment URL with its corresponding S3 URL.
     *
     * This method takes a local attachment URL, retrieves the associated
     * attachment ID and metadata, and calculates the S3 URL based on the
     * attachment's file path stored in the metadata.
     *
     * @param string $url The local URL of the attachment.
     *
     * @return string The S3 URL if the attachment exists; otherwise,
     *  returns the original URL.
     */
    public function replaceWithS3Url($url)
    {
        // Retrieve the attachment ID using the URL
        $attachment_id = attachment_url_to_postid($url);
        if (!$attachment_id) {
            return $url;
        };

        // Retrieve the attachment's metadata
        $meta = wp_get_attachment_metadata($attachment_id);
        if (!$meta) {
            return $url;
        };

        // Convert the local file path to an S3 URL and return
        return $this->_toS3Url($meta['file']);
    }

    /**
     * Retrieves downsized image details from S3 URLs.
     *
     * This method attempts to provide the URL and dimensions for a downsized
     * image stored in S3, based on attachment metadata, for a specified size.
     * If the 'full' size is requested, it returns the original image dimensions.
     *
     * @param bool|array   $downsize      Whether to short-circuit the image
     *                                    downsize, or an array of image data.
     * @param int          $attachment_id The ID of the attachment to downsize.
     * @param string|array $size          The size requested, either a
     *                                    registered image size or an array of
     *                                    width and height values.
     *
     * @return array|bool An array of image data (URL, width, height,
     *                    is_intermediate), or false if no matching image
     *                    size is found.
     */
    public function s3ImageDownsize($downsize, $attachment_id, $size)
    {
        // Retrieve the attachment metadata
        $meta = wp_get_attachment_metadata($attachment_id);
        if (!$meta) {
            return false;
        };

        $file = $meta['file'];
        $dirname = pathinfo($file, PATHINFO_DIRNAME);

        // Return the full-size image URL and dimensions
        if ($size === 'full') {
            return [$this->_toS3Url($file), $meta['width'], $meta['height'], true];
        }

        // Return the URL and dimensions for a specific image size if available
        if (!empty($meta['sizes'][$size])) {
            $size_meta = $meta['sizes'][$size];
            return [
                $this->_toS3Url($dirname . '/' . $size_meta['file']),
                $size_meta['width'],
                $size_meta['height'],
                true
            ];
        }

        // Return false if the requested size is not found
        return false;
    }

    /**
     * Deletes an attachment and its associated images from S3 and local storage.
     *
     * This method uses the attachment metadata to identify and delete the main
     * image, any scaled versions, and all associated thumbnails from the
     * configured S3 bucket. It also attempts to remove the local files and
     * cleans up any empty directories left behind.
     *
     * @param int $attachment_id The ID of the attachment to delete from S3
     *                           and local storage.
     *
     * @return void
     */
    public function deleteFromS3($attachment_id)
    {
        if (!$this->_s3 || empty($this->_bucket)) {
            $this->_logger->info(
                'Skipping S3 delete — S3 client or bucket not configured.'
            );
            return;
        }

        // Retrieve the attachment metadata
        $meta = wp_get_attachment_metadata($attachment_id);

        if (!$meta || empty($meta['file'])) {
            // Log and exit if metadata or file info is unavailable
            $this->_logger->info(
                'deleteFromS3(): No metadata found for attachment ID ' .
                $attachment_id
            );
            return;
        }

        $this->_logger->info(
            'deleteFromS3(): Starting delete for attachment ID ' .
            $attachment_id
        );

        $paths = [];

        // Always delete the "main" file (even if it's scaled)
        $paths[] = $meta['file'];

        // Manually reconstruct the original file path if a scaled version exists
        $scaled_filename = basename($meta['file']);
        $dirname = pathinfo($meta['file'], PATHINFO_DIRNAME);

        if (strpos($scaled_filename, '-scaled') !== false) {
            $original_filename = str_replace('-scaled', '', $scaled_filename);
            $original_relative_path = $dirname . '/' . $original_filename;

            $this->_logger->info(
                'deleteFromS3(): Also preparing original file: ' .
                $original_relative_path
            );

            $paths[] = $original_relative_path;
        }

        // Add all thumbnail paths for deletion
        if (!empty($meta['sizes'])) {
            foreach ($meta['sizes'] as $size) {
                $paths[] = $dirname . '/' . $size['file'];
            }
        }

        foreach ($paths as $relative_path) {
            $key = ltrim($relative_path, '/');

            $this->_logger->info(
                'deleteFromS3(): Preparing to delete S3 Key: ' .
                $key
            );

            try {
                // Attempt to delete the object from S3
                $this->_s3->deleteObject(
                    [
                        'Bucket' => $this->_bucket,
                        'Key'    => $key,
                    ]
                );
                $this->_logger->info(
                    'deleteFromS3(): Successfully deleted from S3: ' .
                    $key
                );
            } catch (AwsException $e) {
                // Log any errors encountered during the S3 delete operation
                $this->_logger->info(
                    'deleteFromS3(): S3 Delete Error: ' .
                    $e->getAwsErrorMessage()
                );
            }

            // Attempt to delete the local file and clean up any empty directories
            $full_local_path = $this->_localBasedir . '/' . $key;

            if (file_exists($full_local_path)) {
                wp_delete_file($full_local_path);
                $this->_logger->info(
                    'deleteFromS3(): Successfully deleted local file: ' .
                    $full_local_path
                );
                $this->_maybeCleanupEmptyFolder($full_local_path);
            } else {
                $this->_logger->info(
                    'deleteFromS3(): Local file not found (already deleted?): ' .
                    $full_local_path
                );
            }
        }
    }

    /**
     * Disables image editing in WordPress.
     *
     * This method prevents further editing of images by always returning false,
     * effectively disabling image editing capabilities for attachments.
     *
     * @param mixed $supports Whether the editor supports a given feature. Ignored.
     * @param array $args     Arguments for supporting the function; not used.
     *
     * @return bool Always returns false to disable image editing capabilities.
     */
    public function disableImageEditing($supports, $args)
    {
        return false;
    }

    /**
     * Rewrites local image URLs in post content to S3 URLs.
     *
     * This method scans the provided content and replaces any instances of
     * local image URLs with their corresponding URLs from the configured S3 bucket.
     *
     * @param string $content The content potentially containing local image URLs.
     *
     * @return string The content with local image URLs replaced by S3 URLs.
     */
    public function rewriteImageUrlsToS3($content)
    {
        if (!$this->_s3) {
            return $content;
        }

        return str_replace($this->_localBaseurl, $this->_s3Baseurl, $content);
    }

    /**
     * Rewrites image URLs in specific Gutenberg blocks to S3 URLs.
     *
     * This method checks if the provided block content belongs to specific
     * Gutenberg block types ('core/image', 'core/gallery', 'core/cover')
     * and replaces local image URLs with their corresponding S3 URLs.
     *
     * @param string $block_content The content of the block that may
     *                              contain local image URLs.
     * @param array  $block         Information about the block, including its name.
     *
     * @return string The block content with local image URLs replaced by S3 URLs,
     *                if applicable.
     */
    public function rewriteBlockImageUrlsToS3($block_content, $block)
    {
        if (!empty($block['blockName'])
            && in_array(
                $block['blockName'],
                ['core/image', 'core/gallery', 'core/cover'],
                true
            )
        ) {
            // Replace local image URLs with S3 URLs for specified block types
            $block_content = str_replace(
                $this->_localBaseurl,
                $this->_s3Baseurl,
                $block_content
            );
        }

        return $block_content;
    }

    /**
     * Rewrites the source URL of an attachment image to use the S3 URL.
     *
     * This method modifies the URL of an image attachment source to point
     * to the S3 bucket instead of the local server, if the image is an array
     * and contains a valid URL.
     *
     * @param array|false  $image         The image source data array or false on
     *                                    failure.
     * @param int          $attachment_id The attachment ID.
     * @param string|array $size          Requested size. Image size or an array
     *                                    of width and height values (in that order).
     * @param bool         $icon          Whether the image should be treated as
     *                                    an icon.
     *
     * @return array|false The modified image source array with the S3 URL, or the
     *                     original image data if not modified.
     */
    public function rewriteWpGetAttachmentImageSrc(
        $image,
        $attachment_id,
        $size,
        $icon
    ) {
        if (is_array($image) && !empty($image[0])) {
            // Replace the local URL with the S3 URL in the image source
            $image[0] = str_replace(
                $this->_localBaseurl,
                $this->_s3Baseurl,
                $image[0]
            );
        }
        return $image;
    }

    /**
     * Rewrites the srcset URLs of an attachment image to use S3 URLs.
     *
     * This method processes the srcset data for an image attachment,
     * converting all local image URLs to their corresponding URLs on the S3 bucket.
     *
     * @param array  $sources       An array of srcset source data.
     * @param array  $size_array    Array containing the width and height values
     *                              for the image size the srcset is created for.
     * @param string $image_src     The requested image source URL.
     * @param array  $image_meta    The image attachment meta data.
     * @param int    $attachment_id Attachment ID.
     *
     * @return array The modified srcset source data array with S3 URLs.
     */
    public function rewriteWpGetAttachmentImageSrcset(
        $sources,
        $size_array,
        $image_src,
        $image_meta,
        $attachment_id
    ) {
        return $this->_rewriteSrcset($sources);
    }

    /**
     * Rewrites the calculated srcset URLs for an attachment image to use S3 URLs.
     *
     * This method processes the calculated srcset data, ensuring that all image
     * URLs point to the S3 bucket instead of the local server.
     *
     * @param array  $sources       An array of srcset source data.
     * @param array  $size_array    Array containing the width and height values
     *                              for the image size the srcset is created for.
     * @param string $image_src     The requested image source URL.
     * @param array  $image_meta    The image attachment meta data.
     * @param int    $attachment_id The attachment ID.
     *
     * @return array The modified srcset source data array with S3 URLs.
     */
    public function rewriteWpCalculateImageSrcset(
        $sources,
        $size_array,
        $image_src,
        $image_meta,
        $attachment_id
    ) {
        return $this->_rewriteSrcset($sources);
    }
}
