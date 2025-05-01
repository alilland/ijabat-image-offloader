=== Ijabat Image Offloader ===
Contributors: ijabattech
Tags: s3, media offload, amazon s3, image optimization, cloud storage
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Ijabat Image Offloader is a lightweight WordPress plugin that offloads all media uploads — including thumbnails — to an Amazon S3 bucket and serves them from S3.

== Description ==

Ijabat Image Offloader is a lightweight WordPress plugin that automatically offloads all media uploads — including original files and generated thumbnails — to an Amazon S3 bucket.

It is designed for clean, cloud-native WordPress setups where media is served from S3, keeping the local server lightweight and efficient.

=== Features ===

* Upload original media files directly to S3
* Upload all generated thumbnail sizes to S3
* Automatically delete local copies after successful upload
* Clean up empty folders in `/wp-content/uploads/` after file deletion
* Serve all media URLs directly from your S3 bucket
* Auto-deletes media from S3 when attachment is deleted in WordPress
* Minimal dependency footprint (AWS SDK + Dotenv)
* Follows best security practices (no unnecessary public ACLs)

== Installation ==

1. Clone the repository into your WordPress plugins directory:

`git clone https://github.com/yourusername/custom-s3-offloader.git wp-content/plugins/custom-s3-offloader`

2. Install Composer dependencies:

`cd wp-content/plugins/custom-s3-offloader && composer install`

3. Create a `.env.local` file in your WordPress root (`/htdocs/your-site/.env.local`) with:

```
AWS_ACCESS_KEY_ID=your-access-key
AWS_SECRET_ACCESS_KEY=your-secret-key
AWS_S3_BUCKET=your-bucket-name
AWS_DEFAULT_REGION=your-region (e.g., us-west-1)
```

4. Add the following to `wp-config.php` to load the environment variables:

```php
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
    Dotenv\Dotenv::createImmutable(__DIR__, '.env.local')->safeLoad();
}
```

5. Activate the plugin from the WordPress Admin Plugins page.

== Frequently Asked Questions ==

= How does it work? =

* Upload media: Full-size image is uploaded to S3
* Generate thumbnails: Thumbnails are uploaded to S3
* After upload: Local files are deleted
* Delete attachment: Files are deleted from S3
* Serve URLs: Media is served directly from S3

= Is WordPress Multisite supported? =

Not yet — multisite functionality is not currently tested or supported.

= Can I use private S3 buckets or signed URLs? =

Not at this time. All uploads are assumed to be public.

== Screenshots ==

None yet.

== Changelog ==

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.0 =
Initial public release of Ijabat Image Offloader.

== Known Limitations ==

* All uploads are assumed to be public.
* Private/signed URLs are not supported yet.
* WordPress Multisite (Network) is not yet tested.

== Roadmap ==

* CloudFront CDN integration
* Optional signed S3 URLs for protected media
* Background uploads for very large files
* Support for WordPress Multisite environments

== License ==

Licensed under the GPL-2.0-or-later license.

== Author ==

Built and maintained by Ijabat Tech Solutions, LLC.
