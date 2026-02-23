<?php
/**
 * GenD GitHub Updater
 * 
 * Simple class to enable GitHub-based updates for WordPress plugins.
 */

if (!class_exists('GenD_GitHub_Updater')) {

    class GenD_GitHub_Updater
    {
        private $plugin_file;
        private $github_repo;
        private $slug;
        private $plugin_data;

        public function __construct($plugin_file, $github_repo)
        {
            $this->plugin_file = $plugin_file;
            $this->github_repo = $github_repo; // e.g., 'gend-me/Society-Core'
            $this->slug = plugin_basename($plugin_file);

            add_filter('site_transient_update_plugins', [$this, 'check_update']);
            add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
        }

        public function check_update($transient)
        {
            if (empty($transient->checked)) {
                return $transient;
            }

            $remote_data = $this->get_remote_plugin_data();
            if (!$remote_data) {
                return $transient;
            }

            $local_version = $this->get_local_version();

            if (version_compare($local_version, $remote_data['Version'], '<')) {
                $obj = new stdClass();
                $obj->slug = $this->slug;
                $obj->new_version = $remote_data['Version'];
                $obj->url = "https://github.com/" . $this->github_repo;
                $package_url = "https://github.com/" . $this->github_repo . "/archive/refs/heads/main.zip";
                if (defined('GEND_GITHUB_TOKEN') && GEND_GITHUB_TOKEN) {
                    $package_url = add_query_arg('access_token', GEND_GITHUB_TOKEN, $package_url);
                }
                $obj->package = $package_url;
                $obj->plugin = $this->slug;

                $transient->response[$this->slug] = $obj;
            }

            return $transient;
        }

        public function plugin_info($false, $action, $arg)
        {
            if (isset($arg->slug) && $arg->slug === $this->slug) {
                $remote_data = $this->get_remote_plugin_data();
                if (!$remote_data)
                    return $false;

                $obj = new stdClass();
                $obj->slug = $this->slug;
                $obj->plugin_name = $remote_data['Name'];
                $obj->new_version = $remote_data['Version'];
                $obj->requires = $remote_data['RequiresWP'];
                $obj->tested = $remote_data['TestedUpTo'];
                $download_link = "https://github.com/" . $this->github_repo . "/archive/refs/heads/main.zip";
                if (defined('GEND_GITHUB_TOKEN') && GEND_GITHUB_TOKEN) {
                    $download_link = add_query_arg('access_token', GEND_GITHUB_TOKEN, $download_link);
                }
                $obj->download_link = $download_link;
                $obj->sections = [
                    'description' => $remote_data['Description'],
                ];
                return $obj;
            }
            return $false;
        }

        private function get_local_version()
        {
            if (!$this->plugin_data) {
                $this->plugin_data = get_plugin_data($this->plugin_file);
            }
            return $this->plugin_data['Version'];
        }

        private function get_remote_plugin_data()
        {
            $cache_key = 'gend_remote_data_' . md5($this->github_repo);

            // Allow bypassing cache for testing
            if (!defined('GEND_UPDATE_BYPASS_CACHE') || !GEND_UPDATE_BYPASS_CACHE) {
                $remote_data = get_transient($cache_key);
                if ($remote_data !== false) {
                    return $remote_data;
                }
            }

            $url = "https://raw.githubusercontent.com/" . $this->github_repo . "/main/" . basename($this->plugin_file);

            $args = [
                'timeout' => 10,
                'headers' => [
                    'Accept' => 'application/vnd.github.v3.raw',
                ]
            ];

            // Add Authorization header if token is defined
            if (defined('GEND_GITHUB_TOKEN') && GEND_GITHUB_TOKEN) {
                $args['headers']['Authorization'] = 'token ' . GEND_GITHUB_TOKEN;
            }

            $response = wp_remote_get($url, $args);

            if (is_wp_error($response)) {
                return false;
            }

            $content = wp_remote_retrieve_body($response);
            if (empty($content)) {
                return false;
            }

            // Simple header parsing
            $remote_data = [];
            if (preg_match('/Version:\s*(.*)$/mi', $content, $matches)) {
                $remote_data['Version'] = trim($matches[1]);
            }
            if (preg_match('/Plugin Name:\s*(.*)$/mi', $content, $matches)) {
                $remote_data['Name'] = trim($matches[1]);
            }
            if (preg_match('/Description:\s*(.*)$/mi', $content, $matches)) {
                $remote_data['Description'] = trim($matches[1]);
            }
            if (preg_match('/Requires at least:\s*(.*)$/mi', $content, $matches)) {
                $remote_data['RequiresWP'] = trim($matches[1]);
            } else {
                $remote_data['RequiresWP'] = '6.0';
            }
            if (preg_match('/Tested up to:\s*(.*)$/mi', $content, $matches)) {
                $remote_data['TestedUpTo'] = trim($matches[1]);
            } else {
                $remote_data['TestedUpTo'] = '6.4';
            }

            if (empty($remote_data['Version'])) {
                return false;
            }

            set_transient($cache_key, $remote_data, HOUR_IN_SECONDS);
            return $remote_data;
        }
    }
}
