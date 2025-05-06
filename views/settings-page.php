<?php
/**
 * Plugin settings admin page for My Plugin.
 *
 * This file outputs the HTML form used in the WordPress admin
 * to configure plugin options.
 *
 * PHP version 8.2.4
 *
 * @category WordPress_Plugin
 * @package  IjabatImageOffloader
 * @author   Ijabat Tech Solutions, LLC <info@ijabat.org>
 * @license  https://www.gnu.org/licenses/gpl-2.0.html GPL2+
 * @link     https://ijabat.org
 */
?>

<div class="wrap">
  <style>
    .button.button-small.copy-key-button {
      /* background-color: green; */
      font-size: 0.5rem;
      min-height: 10px;
      width: 20px;
      padding: 0;
      border: 1px solid #e8e8e8;
    }
    td > p.description {
      font-size: 0.7rem;
    }
    .form-table th {
      min-width: 240px;
    }
    h2 {
      margin-top: 3rem;
    }
    .settings-submit-wrapper {
      display: flex;
      flex-wrap: wrap;
    }
    #submit {
      margin-right: 1rem;
    }
  </style>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const clearButton = document.getElementById('ijabat-clear-button');
      const hiddenSubmit = document.getElementById('ijabat-hidden-submit');
      const form = clearButton?.closest('form');

      clearButton?.addEventListener('click', function () {
        // Clear form fields
        form.querySelectorAll('input, select, textarea').forEach((el) => {
          if (el.name && el.type !== 'hidden' && el.type !== 'submit' && el.type !== 'button') {
            if (el.type === 'checkbox' || el.type === 'radio') {
              el.checked = false;
            } else {
              el.value = '';
            }
          }
        });

        // Submit the form
        hiddenSubmit?.click();
      });
    });
  </script>
  <h1><?php esc_html_e('Ijabat Image Offloader', 'ijabat-image-offloader'); ?></h1>
  <div>
    <ul>
      <li>For your safety, we strongly recommend <b><i>not</i></b> storing your AWS Access or Secret Keys in the database unless absolutely necessary.</li>
      <li>Hackers often try to break into websites just to steal database information and sell sensitive data.</li>
      <li>Whenever possible, it's best to store your keys in your hosting environment's settings (like your server Environment Variables, or Docker container Environment Variables).</li>
      <li>We will encrypt and store your credentials using the most secure methods WordPress allows, but it’s still safer to keep them outside of WordPress if you can.</li>
      <li>If your wordpress site sits behind a load balancer, you will need maintain the same copy of `wp-content/plugins/ijabat-image-offloader/secure-data/crypto.json` on all the servers.</li>
    </ul>
  </div>
  <form method="post" action="options.php">
    <?php
      settings_fields('ijabat_settings_group');
      do_settings_sections('ijabat-image-offloader');

      $required_envs = [
        'AWS_ACCESS_KEY_ID',
        'AWS_SECRET_ACCESS_KEY',
        'AWS_S3_BUCKET',
        'AWS_DEFAULT_REGION',
        'AWS_CLOUDFRONT_DOMAIN'
      ];

      $all_envs_set = true;
      foreach ($required_envs as $env_key) {
          if (empty($_ENV[$env_key] ?? getenv($env_key))) {
              $all_envs_set = false;
              break;
          }
      }

      echo '<div class="settings-submit-wrapper">';
      if (!$all_envs_set) {
          submit_button();
          echo '<input type="hidden" name="ijabat_reset" value="1">';
          echo '<button type="submit" id="ijabat-hidden-submit" style="display: none;"></button>';
          echo '<button type="submit" name="ijabat_reset" class="button-link" id="ijabat-clear-button" style="margin-left: 12px; color: #b32d2e;">Clear</button>';
      } else {
          echo '<p><em>All settings are defined via environment variables. No changes can be made here.</em></p>';
      }
      echo '</div>';
        ?>
  </form>
</div>
