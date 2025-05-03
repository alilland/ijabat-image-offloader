<?php
/*
Plugin Name: Ijabat Image Offloader
Description: Offloads WordPress media uploads and thumbnails to AWS S3.
Version: 1.0.0
Author: Ijabat Tech Solutions, LLC
License: GPL2+
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class Ijabat_Image_Offloader {

    private $s3;
    private $bucket;
    private $region;
    private $local_baseurl;
    private $local_basedir;
    private $s3_baseurl;

    public function __construct() {
        $this->bucket = $_ENV['AWS_S3_BUCKET'] ?? null;
        $this->region = $_ENV['AWS_DEFAULT_REGION'] ?? null;

        $this->s3 = new S3Client([
            'version' => 'latest',
            'region'  => $this->region,
            'credentials' => [
                'key'    => $_ENV['AWS_ACCESS_KEY_ID'] ?? null,
                'secret' => $_ENV['AWS_SECRET_ACCESS_KEY'] ?? null,
            ],
        ]);

        $upload_dir = wp_upload_dir();
        $this->local_baseurl = $upload_dir['baseurl'];
        $this->local_basedir = wp_normalize_path($upload_dir['basedir']);
        if (!empty($_ENV['AWS_CLOUDFRONT_DOMAIN'])) {
            $this->s3_baseurl = rtrim($_ENV['AWS_CLOUDFRONT_DOMAIN'], '/');
        } else {
            $this->s3_baseurl = sprintf('https://%s.s3.%s.amazonaws.com', $this->bucket, $this->region);
        }

        add_filter('wp_handle_upload', [$this, 'upload_to_s3']);
        add_filter('wp_generate_attachment_metadata', [$this, 'upload_thumbnails_to_s3'], 10, 2);
        add_filter('wp_get_attachment_url', [$this, 'replace_with_s3_url']);
        add_filter('image_downsize', [$this, 's3_image_downsize'], 10, 3);
        add_filter('wp_image_editor_supports', [$this, 'disable_image_editing'], 10, 2);
        add_filter('wp_get_attachment_image_src', [$this, 'rewrite_wp_get_attachment_image_src'], 10, 4);
        add_filter('wp_get_attachment_image_srcset', [$this, 'rewrite_wp_get_attachment_image_srcset'], 10, 5);
        add_filter('wp_calculate_image_srcset', [$this, 'rewrite_wp_calculate_image_srcset'], 10, 5);
        add_filter('render_block', [$this, 'rewrite_block_image_urls_to_s3'], 10, 2);
        add_filter('the_content', [$this, 'rewrite_image_urls_to_s3']);

        add_action('delete_attachment', [$this, 'delete_from_s3']);

        error_log('[CustomS3Offloader] Initialized. Bucket=' . $this->bucket . ', Region=' . $this->region);
    }

    private function s3_key($file_path) {
        $file_path = wp_normalize_path($file_path);
        return ltrim(str_replace($this->local_basedir, '', $file_path), '/');
    }

    private function to_s3_url($path) {
        return trailingslashit($this->s3_baseurl) . ltrim($path, '/');
    }

    public function upload_to_s3($upload) {
        if (empty($upload['file'])) return $upload;

        $file_path = $upload['file'];

        $this->s3_upload($file_path);

        // 🚫 Do not unlink here anymore
        return $upload;
    }

    public function upload_thumbnails_to_s3($metadata, $attachment_id) {
        $file = get_attached_file($attachment_id);
        if (!$file || !$metadata) return $metadata;

        $paths = [$file]; // This will include -scaled if present

        $dirname = pathinfo($file, PATHINFO_DIRNAME);
        $scaled_filename = pathinfo($file, PATHINFO_BASENAME);

        // 👀 Check if there is a *non-scaled* version to delete too
        if (strpos($scaled_filename, '-scaled') !== false) {
            $original_filename = str_replace('-scaled', '', $scaled_filename);
            $original_file_path = $dirname . '/' . $original_filename;

            if (file_exists($original_file_path)) {
                error_log('[CustomS3Offloader] Found original file to delete: ' . $original_file_path);
                $paths[] = $original_file_path; // Add it to be uploaded + deleted
            }
        }

        // Add all generated thumbnails
        if (!empty($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size) {
                $paths[] = $dirname . '/' . $size['file'];
            }
        }

        foreach ($paths as $path) {
            if (file_exists($path)) {
                $this->s3_upload($path); // ✅ Upload to S3
                unlink($path);            // ✅ Then delete local file
                error_log('[CustomS3Offloader] Deleted local: ' . $path);
                $this->maybe_cleanup_empty_folder($path);
            } else {
                error_log('[CustomS3Offloader] Warning: File not found for S3 upload: ' . $path);
            }
        }

        return $metadata;
    }

    private function s3_upload($path) {
        $key = $this->s3_key($path);
        try {
            $this->s3->putObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'SourceFile' => $path,
                'ContentType' => mime_content_type($path),
            ]);
            error_log('[CustomS3Offloader] Uploaded to S3: ' . $key);
        } catch (AwsException $e) {
            error_log('[CustomS3Offloader] S3 Upload Error: ' . $e->getAwsErrorMessage());
        }
    }

    public function replace_with_s3_url($url) {
        $attachment_id = attachment_url_to_postid($url);
        if (!$attachment_id) return $url;

        $meta = wp_get_attachment_metadata($attachment_id);
        if (!$meta) return $url;

        return $this->to_s3_url($meta['file']);
    }

    public function s3_image_downsize($downsize, $attachment_id, $size) {
        $meta = wp_get_attachment_metadata($attachment_id);
        if (!$meta) return false;

        $file = $meta['file'];
        $dirname = pathinfo($file, PATHINFO_DIRNAME);

        if ($size === 'full') {
            return [$this->to_s3_url($file), $meta['width'], $meta['height'], true];
        }

        if (!empty($meta['sizes'][$size])) {
            $size_meta = $meta['sizes'][$size];
            return [$this->to_s3_url($dirname . '/' . $size_meta['file']), $size_meta['width'], $size_meta['height'], true];
        }

        return false;
    }

    public function delete_from_s3($attachment_id) {
        $meta = wp_get_attachment_metadata($attachment_id);

        if (!$meta || empty($meta['file'])) {
            error_log('[CustomS3Offloader] delete_from_s3(): No metadata found for attachment ID ' . $attachment_id);
            return;
        }

        error_log('[CustomS3Offloader] delete_from_s3(): Starting delete for attachment ID ' . $attachment_id);

        $paths = [];

        // Always delete the "main" file (even if it's scaled)
        $paths[] = $meta['file'];

        // 📦 Manually reconstruct the original file (before scaling)
        $scaled_filename = basename($meta['file']);
        $dirname = pathinfo($meta['file'], PATHINFO_DIRNAME);

        if (strpos($scaled_filename, '-scaled') !== false) {
            $original_filename = str_replace('-scaled', '', $scaled_filename);
            $original_relative_path = $dirname . '/' . $original_filename;

            error_log('[CustomS3Offloader] delete_from_s3(): Also preparing original file: ' . $original_relative_path);

            $paths[] = $original_relative_path;
        }

        // Add thumbnails
        if (!empty($meta['sizes'])) {
            foreach ($meta['sizes'] as $size) {
                $paths[] = $dirname . '/' . $size['file'];
            }
        }

        foreach ($paths as $relative_path) {
            $key = ltrim($relative_path, '/');

            error_log('[CustomS3Offloader] delete_from_s3(): Preparing to delete S3 Key: ' . $key);

            try {
                $this->s3->deleteObject([
                    'Bucket' => $this->bucket,
                    'Key'    => $key,
                ]);
                error_log('[CustomS3Offloader] delete_from_s3(): Successfully deleted from S3: ' . $key);
            } catch (AwsException $e) {
                error_log('[CustomS3Offloader] delete_from_s3(): S3 Delete Error: ' . $e->getAwsErrorMessage());
            }

            // 🧹 Now try deleting local file
            $full_local_path = $this->local_basedir . '/' . $key;

            if (file_exists($full_local_path)) {
                unlink($full_local_path);
                error_log('[CustomS3Offloader] delete_from_s3(): Successfully deleted local file: ' . $full_local_path);
                $this->maybe_cleanup_empty_folder($full_local_path);
            } else {
                error_log('[CustomS3Offloader] delete_from_s3(): Local file not found (already deleted?): ' . $full_local_path);
            }
        }
    }

    public function disable_image_editing($supports, $args) {
        return false;
    }

    public function rewrite_image_urls_to_s3($content) {
        return str_replace($this->local_baseurl, $this->s3_baseurl, $content);
    }

    public function rewrite_block_image_urls_to_s3($block_content, $block) {
        if (!empty($block['blockName']) && in_array($block['blockName'], ['core/image', 'core/gallery', 'core/cover'], true)) {
            $block_content = str_replace($this->local_baseurl, $this->s3_baseurl, $block_content);
        }
        return $block_content;
    }

    public function rewrite_wp_get_attachment_image_src($image, $attachment_id, $size, $icon) {
        if (is_array($image) && !empty($image[0])) {
            $image[0] = str_replace($this->local_baseurl, $this->s3_baseurl, $image[0]);
        }
        return $image;
    }

    public function rewrite_wp_get_attachment_image_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
        return $this->rewrite_srcset($sources);
    }

    public function rewrite_wp_calculate_image_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
        return $this->rewrite_srcset($sources);
    }

    private function rewrite_srcset($sources) {
        foreach ($sources as &$source) {
            if (!empty($source['url'])) {
                $source['url'] = str_replace($this->local_baseurl, $this->s3_baseurl, $source['url']);
            }
        }
        return $sources;
    }

    private function maybe_cleanup_empty_folder($path) {
        $dir = dirname($path);
        while ($dir !== $this->local_basedir) {
            if (is_dir($dir) && count(glob($dir . '/*')) === 0) {
                rmdir($dir);
                $dir = dirname($dir);
            } else {
                break;
            }
        }
    }
}

new Ijabat_Image_Offloader();
