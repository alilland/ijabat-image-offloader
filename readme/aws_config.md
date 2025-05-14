# AWS Configuration Guide for iJabat Image Offloader

This guide will walk you through setting up the necessary AWS services (IAM, S3, and CloudFront) to use the iJabat Image Offloader plugin. No prior AWS knowledge is required.

## Table of Contents
1. [AWS Account Creation](#aws-account-creation)
2. [Creating an S3 Bucket](#creating-an-s3-bucket)
3. [Setting up CloudFront](#setting-up-cloudfront)
4. [Creating IAM User and Permissions](#creating-iam-user)
5. [Plugin Configuration](#plugin-configuration)

## AWS Account Creation
1. Visit [AWS Sign Up](https://aws.amazon.com)
2. Click "Create an AWS Account"
3. Follow the registration process
   - You'll need to provide credit card information
   - Choose the Basic (Free) support plan
   - AWS offers a free tier for 12 months

## Creating an S3 Bucket

1. Log into the [AWS Management Console](https://console.aws.amazon.com)
2. Search for "S3" in the services search bar
3. Click "Create bucket"
4. Configure your bucket:
   - Choose a unique bucket name (e.g., "my-site-images")
   - Select your preferred region (choose the region closest to your WordPress server location, since CloudFront will handle content delivery to end users)
   - Keep "Block all public access" enabled (we'll use CloudFront OAC for secure access)
   - Keep other settings as default
5. Click "Create bucket"

## Setting up CloudFront

1. In the AWS Console, search for "CloudFront"
2. Click "Create Distribution"
3. Configure your distribution:
   - Origin Domain: Select your S3 bucket
   - Origin Path: Leave empty
   - Origin Access: Select "Origin access control settings (recommended)"
   - Create a new Origin Access Control setting (use default settings)
   - Viewer Protocol Policy: Redirect HTTP to HTTPS
   - Cache Policy: Use CachingOptimized
   - Price Class: Choose based on your needs (Price Class 100 for US/Europe is cheapest)
4. Click "Create Distribution"
5. **Important**: After creation, CloudFront will provide a bucket policy. You need to copy this policy and apply it to your S3 bucket
6. Wait for deployment (takes ~15 minutes)
7. Note your CloudFront Domain Name (e.g., `d1234abcd.cloudfront.net`)

### Bucket Policy Setup
1. Click on your newly created bucket
2. Go to the "Permissions" tab
3. Scroll to "Bucket policy" and click "Edit"
4. Copy and paste this policy (replace the values in brackets with your actual values):
```json
{
    "Version": "2008-10-17",
    "Id": "PolicyForCloudFrontPrivateContent",
    "Statement": [
        {
            "Sid": "AllowCloudFrontServicePrincipal",
            "Effect": "Allow",
            "Principal": {
                "Service": "cloudfront.amazonaws.com"
            },
            "Action": "s3:GetObject",
            "Resource": "arn:aws:s3:::[YOUR-BUCKET-NAME]/*",
            "Condition": {
                "StringEquals": {
                    "AWS:SourceArn": "arn:aws:cloudfront::[YOUR-AWS-ACCOUNT-ID]:distribution/[YOUR-DISTRIBUTION-ID]"
                }
            }
        }
    ]
}
```

**Note**: Replace the following values:
- `[YOUR-BUCKET-NAME]`: Your S3 bucket name
- `[YOUR-AWS-ACCOUNT-ID]`: Your 12-digit AWS account ID
- `[YOUR-DISTRIBUTION-ID]`: Your CloudFront distribution ID (e.g., E3R944CIVBD11F)

## Creating IAM User

1. In AWS Console, search for "IAM"
2. Click "Users" → "Create user"
3. Configure the user:
   - Username: `ijabat-image-offloader`
   - Select "Access key - Programmatic access"
4. Click "Next: Permissions"
5. Click "Attach existing policies directly"
6. Create a new policy:
   - Click "Create Policy"
   - Choose JSON editor and paste (replace the values in brackets with your actual values):
```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "AllowS3ActionsOnSpecificBucket",
            "Effect": "Allow",
            "Action": [
                "s3:PutObject",
                "s3:GetObject",
                "s3:DeleteObject"
            ],
            "Resource": "arn:aws:s3:::[YOUR-BUCKET-NAME]/*"
        }
    ]
}
```
7. Name the policy `ijabat-image-offloader-policy`
8. Attach this policy to your user
9. Complete user creation
10. **IMPORTANT**: Save the Access Key ID and Secret Access Key shown. You will not be able to see the Secret Access Key again! See the Security Notes section below for best practices on managing these credentials.

## Plugin Configuration

### Method 1: Host-Level Environment Variables (Recommended)
Set the following environment variables at your host/system level:

```bash
# AWS Credentials
IJABAT_AWS_ACCESS_KEY_ID='your-access-key-id'
IJABAT_AWS_SECRET_ACCESS_KEY='your-secret-access-key'
IJABAT_AWS_REGION='your-region'
IJABAT_S3_BUCKET='your-bucket-name'
IJABAT_CLOUDFRONT_DOMAIN='your-cloudfront-domain'
```

This is the recommended method because:
- Credentials are completely isolated from the application
- Maximum security through proper system-level separation
- No risk of exposure through application files or database
- Follows security best practices for credential management
- Easier to manage across different environments (dev, staging, prod)

Most hosting providers offer ways to set environment variables through their platform:
- AWS Elastic Beanstalk: Environment Properties
- Google App Engine: App Engine settings
- Heroku: Config Vars
- DigitalOcean App Platform: Environment Variables
- Cloudways: Application Settings
- Many other hosts provide similar capabilities

### Method 2: WordPress Admin Panel (Fallback)
If your hosting environment does not support setting environment variables, you can use the plugin settings page as a fallback:

1. In your WordPress admin panel, go to iJabat Image Offloader settings
2. Enter the following details:
   - AWS Access Key ID: (from IAM user creation)
   - AWS Secret Access Key: (from IAM user creation)
   - AWS Region: (the region you chose for your S3 bucket)
   - S3 Bucket Name: (your bucket name)
   - CloudFront Domain: (your CloudFront domain)
3. Click "Save Changes"

**Security Warning**: This method stores credentials in the WordPress database. Only use this if your host does not provide environment variable capabilities.

## Troubleshooting

Common issues and solutions:

1. **403 Forbidden Errors**
   - Check your bucket policy
   - Verify bucket public access settings
   - Confirm IAM user permissions

2. **Images Not Loading**
   - Wait for CloudFront distribution to deploy fully
   - Verify CloudFront origin settings
   - Check if S3 bucket is in the correct region

3. **Upload Failures**
   - Verify AWS credentials are correct
   - Check IAM user has proper permissions
   - Confirm WordPress has write permissions

## Security Notes

### AWS Credentials Management
- Never share your AWS credentials with anyone
- Never commit AWS credentials to version control
- Store credentials securely in your WordPress configuration
- Rotate your AWS access keys every 90 days
  1. Create a new access key in IAM
  2. Update the plugin settings with the new credentials
  3. Verify the plugin works with the new credentials
  4. Delete the old access key from IAM
- Consider using AWS Secrets Manager for enterprise deployments

### Access Control
- Keep "Block all public access" enabled on your S3 bucket
- Regularly audit your IAM user permissions
- Use the principle of least privilege (as shown in the IAM policy)

### Monitoring and Alerts
- Monitor your AWS billing dashboard
- Set up AWS budget alerts to avoid unexpected costs
- Enable AWS CloudTrail for audit logging
- Set up alerts for unusual S3 or CloudFront activity

### WordPress Security
- Keep WordPress core and all plugins updated
- Use HTTPS for your WordPress admin panel
- Restrict access to wp-admin to trusted IPs if possible
- Install a security plugin for active protection:
  - Wordfence (recommended) - provides:
    - Real-time threat defense
    - Malware scanning
    - Brute force protection
    - IP blocking
    - File integrity monitoring
  - Alternative options:
    - Sucuri Security
    - iThemes Security Pro
- Enable and configure security plugin features:
  - Regular malware scans
  - File change detection
  - Login attempt monitoring
  - Two-factor authentication
  - IP-based access rules
- Monitor plugin security logs regularly
- Keep backups of your WordPress installation

## Support

If you encounter any issues:
1. Check the troubleshooting section
2. Review AWS documentation
3. Contact plugin support with specific error messages

---

Remember to replace placeholder values (like `your-bucket-name`) with your actual values when following this guide.
