<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Admin;

use WPCF\FirewallSync\Cloudflare\Client;
use \WPCF\FirewallSync\Services\SyncScheduler;

final class Fields {
  public static function register(): void {
    add_action('admin_init', [self::class, 'register_settings']);
    add_action('admin_post_firewall_sync_validate_cf_credentials', [self::class, 'handle_validate']);
    add_action('admin_post_firewall_sync_test_block', [self::class, 'handle_test_block']);
    add_action('update_option_firewall_sync_options', [self::class, 'maybe_handle_manual_block'], 10, 2);
    add_action('admin_post_firewall_sync_now', [self::class, 'handle_sync_now']);
    add_action('admin_post_firewall_sync_cleanup_now', [self::class, 'handle_cleanup_now']);
  }

  public static function register_settings(): void {
    register_settings('firewall_sync_options_group', 'firwall_sync_options', [
      'type' => 'array',
      'sanitize_callback' => [self::class, 'sanitize'],
      'default' => []
    ]);

    add_settings_section(
      'firwall_sync_main_section',
      'Cloudflare Configuration',
      null,
      'firewall-sync-settings',
    );

    self::add_text_field('cloudflare_api_token', 'Cloudflare API Token');
    self::add_text_field('cloudflare_zone_id', 'Cloudflare Zone ID');
    self::add_text_field('sync_interval', 'Sync Interval (minutes)', 'e.g., 15, 30, 60');
    self::add_text_field('manual_block_ip', 'Manually Block IP');
    self::add_button_field('validate_cf_credentials', 'Validate Cloudflare Credentials');
    self::add_button_field('test_block', 'Run Test Block');
  }

  private static function add_text_field(string $name, string $label, string $placeholder = ''): void {
    add_settings_field(
      $name,
      $label,
      function () use ($name, $placeholder): void {
        $options = get_option('firewall_sync_options');
        $value = $options[$name] ?? '';

        printf(
          '<input type="text" id="%1$s" name="firewall_sync_options[%1$s]" value="%2$s" placeholder="%3$s" class="regular-text">',
          esc_attr($name),
          esc_attr($value),
          esc_attr($placeholder)
        );
      },
      'firewall-sync-settings',
      'firewall_sync_main_section'
    );
  }

  public static function handle_sync_now(): void {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    check_admin_referer('firewall_sync_now', 'firewall_sync_now_nonce');

    $success = SyncScheduler::run_now();

    add_settings_error(
        'firewall_sync_messages',
        'sync_now',
        $success ? 'Sync completed successfully.' : 'Sync failed.',
        $success ? 'updated' : 'error'
    );

    wp_redirect(admin_url('admin.php?page=firewall-sync-settings'));
    exit;
  }

  public static function handle_cleanup_now(): void {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    check_admin_referer('firewall_sync_cleanup_now', 'firewall_sync_cleanup_now_nonce');

    $options = get_option('firewall_sync_options');
    $client = new Client(
        $options['cloudflare_api_token'] ?? '',
        $options['cloudflare_zone_id'] ?? ''
    );

    SyncScheduler::cleanup_expired($client);

    add_settings_error('firewall_sync_messages', 'cleanup_now', 'Cleanup completed successfully.', 'updated');
    wp_redirect(admin_url('admin.php?page=firewall-sync-settings'));

    exit;
  }

  private static function add_button_field(string $name, string $label): void {
    add_settings_field(
      $name,
      $label,
      function () use ($name): void {
        submit_button($label, 'secondary', 'firewall_sync_action_' . $name, false);
      },
      'firewall-sync-settings',
      'firewall_sync_main_section'
    );
  }

  public static function sanitize(array $input): array {
    return array_map('sanitize_text_field', $input);
  }

  public static function maybe_handle_manual_block(array $old_value, array $new_value): void {
    if (!empty($sanitized['manual_block_ip']) && filter_var($sanitized['manual_block_ip'], FILTER_VALIDATE_IP)) {
      $client = new Client(
        $sanitized['cloudflare_api_token'] ?? '',
        $sanitized['cloudflare_zone_id'] ?? ''
      );

      $success = $client->create_block($sanitized['manual_block_ip']);

      add_settings_error(
        'firewall_sync_messages',
        'manual_block',
        $success
          ? "Successfully blocked IP: {$sanitized['manual_block_ip']}"
          : "Failed to block IP: {$sanitized['manual_block_ip']}",
        $success ? 'updated' : 'error'
      );

      $new_value['manual_block_ip'] = '';
      update_option('firewall_sync_options', $new_value);
    }

  }

  public static function handle_validate(): void {
    if (!current_user_can('manage_options')) {
      wp_die('You do not have sufficient permissions to access this page.');
    }

    check_admin_referer('firewall_sync_validate_cf_credentials_nonce');

    $options = get_option('firewall_sync_options');

    $client = new Client($options['cloudflare_api_token'] ?? '', $options['cloudflare_zone_id'] ?? '');

    $result = $client->validate();
    $msg = $result ? 'Cloudflare credentials validated successfully' : 'Failed to validate Cloudflare credentials';

    add_settings_error('firewall_sync_messages', 'validate', $msg, $result ? 'updated' : 'error');
    wp_redirect(admin_url('admin.php?page=firewall-sync-settings'));
    exit;
  }

  public static function handle_test_block(): void {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    check_admin_referer('firewall_sync_test_block_nonce');

    $options = get_option('firewall_sync_options');
    $client = new Client($options['cloudflare_api_token'] ?? '', $options['cloudflare_zone_id'] ?? '');
    $ip = sanitize_text_field($_POST['firewall_sync_options']['manual_block_ip'] ?? '');

    $create = $client->create_block($ip);
    $delete = $create ? $client->delete_block($ip) : false;

    $msg = ($create && $delete)
      ? 'Test block created and deleted successfully'
      : 'Failed to create or delete test block';

    add_settings_error('firewall_sync_messages', 'test', $msg, $create && $delete ? 'updated' : 'error');
    wp_redirect(admin_url('admin.php?page=firewall-sync-settings'));
    exit;
  }
}