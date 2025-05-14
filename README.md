# Ijabat Image Offloader

[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

> Offload WordPress media uploads and thumbnails to Amazon S3 and serve through CloudFront CDN. Automatically delete local files and serve from the cloud.

## 🚀 Features

- Upload original media files directly to S3
- Upload all generated thumbnail sizes to S3
- Automatically delete local copies after successful upload
- Clean up empty folders in `/wp-content/uploads/` after file deletion
- Serve all media through CloudFront CDN for optimal performance
- Auto-deletes media from S3 when attachment is deleted in WordPress
- Minimal dependency footprint (AWS SDK)
- Follows AWS security best practices with CloudFront Origin Access Control

## 📋 Requirements

- WordPress 6.0 or higher
- PHP 8.0 or higher
- Composer for dependency management
- AWS account with S3 and CloudFront services

## 🔧 Installation

1. Clone the repository into your WordPress plugins directory:
```bash
git clone https://github.com/yourusername/ijabat-image-offloader.git wp-content/plugins/ijabat-image-offloader
```

2. Install Composer dependencies:
```bash
cd wp-content/plugins/ijabat-image-offloader && composer install
```

3. Configure AWS services by following our detailed guide:
   - [AWS Configuration Guide](readme/aws_config.md)
   - Includes setup for S3, CloudFront, and IAM
   - Security best practices
   - Troubleshooting steps

4. Configure the plugin:
   - **Recommended**: Set environment variables at your host level
   - **Alternative**: Use the WordPress admin settings panel

5. Activate the plugin from the WordPress Admin Plugins page

## ❓ FAQ

### How does it work?
- Upload media: Full-size image is uploaded to S3
- Generate thumbnails: Thumbnails are uploaded to S3
- After upload: Local files are deleted
- Delete attachment: Files are deleted from S3
- Serve URLs: Media is served through CloudFront CDN

### Is WordPress Multisite supported?
Not yet — multisite functionality is not currently tested or supported.

### Can I use private S3 buckets?
Yes! The plugin uses CloudFront Origin Access Control (OAC) to serve files securely from private S3 buckets.

## ⚠️ Known Limitations

- WordPress Multisite (Network) is not yet tested
- Requires modern PHP version (8.0+)

## 🗺️ Roadmap

- [ ] Background uploads for very large files
- [ ] Support for WordPress Multisite environments
- [ ] Advanced CloudFront configuration options
- [ ] Custom domain support for CloudFront

## 📝 Changelog

### 1.0.0
- Initial release with S3 and CloudFront support
- Implemented secure CloudFront OAC
- Added environment variable support

## 📜 License

Licensed under the [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html) license.

## 👥 Author

Built and maintained by Ijabat Tech Solutions, LLC.
