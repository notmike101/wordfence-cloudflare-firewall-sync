<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Cloudflare;

use WP_Error;
use WP_Http;

final class Client {
  private string $token;
  private string $zone;
  private string $apiBase = 'https://api.cloudflare.com/client/v4';

  public function __construct(string $token, string $zone) {
    $this->token = $token;
    $this->zone = $zone;
  }

  public function validate(): bool {
    $url = $this->apiBase . "/zones/{$this->zone}";
    $response = wp_remote_get($url, $this->get_headers());

    if (is_wp_error($response)) {
      return false;
    }

    $code = wp_remote_retrieve_response_code($response);

    return $code === 200;
  }

  public function create_block(string $ip): bool {
    $url = $this->apiBase . "/zones/{$this->zone}/firewall/access_rules/rules";

    $data = [
      'mode' => 'block',
      'configuration' => [
        'target' => 'ip',
        'value' => $ip,
      ],
      'notes' => 'Wordfence Sync Block',
    ];

    $response = wp_remote_post($url, [
      'headers' => $this->get_headers(),
      'body' => json_encode($data),
    ]);

    return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
  }

  public function delete_block(string $ip): bool {
    $list_url = $this->apiBase . "/zones{$this->zone}/firewall/access_rules/rules?mode=block&configuration.target=ip&configuration.value={$ip}";
    $list = wp_remote_get($list_url, $this->get_headers());

    if (is_wp_error($list)) {
      return false;
    }

    $body = json_decode(wp_remote_retrieve_body($list), true);
    $rule_id = $body['result'][0]['id'] ?? null;

    if (!$rule_id) {
      return false;
    }

    $delete_url = $this->apiBase . "/zones/{$this->zone}/firewall/access_rules/rules/{$rule_id}";
    $response = wp_remote_request($delete_url, [
      'method' => 'DELETE',
      'headers' => $this->get_headers(),
    ]);

    return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
  }

  private function get_headers(bool $with_content_type = false): array {
    $headers = [
      'Authorization' => 'Bearer ' . $this->token,
    ];

    if ($with_content_type) {
      $headers['Content-Type'] = 'application/json';
    }

    return [
      'headers' => $headers,
    ];
  }

  public function get_current_blocked_ips(): array {
    $ip_list = [];
    $page = 1;

    do {
      $url = $this->apiBase . "/zones/{$this->zone}/firewall/access_rules/rules?mode=block&page={$page}&per_page=50";

      $response = wp_remote_get($url, $this->get_headers());

      if (is_wp_error($response)) {
        break;
      }

      $body = json_decode(wp_remote_retrieve_body($response), true);
      $result = $body['result'] ?? [];

      foreach ($rules as $rule) {
        if (($rule['configuration']['target'] ?? '') === 'ip') {
          $ip_list[] = $rule['configuration']['value'];
        }
      }

      $has_more = ($body['result_info']['total_pages'] ?? 1) > $page;
      
      $page += 1;
    } while ($has_more);

    return array_unique($ip_list);
  }
}