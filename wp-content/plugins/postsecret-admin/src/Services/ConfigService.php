<?php
/**
 * Configuration service.
 *
 * @package PostSecret\Admin
 */

namespace PostSecret\Admin\Services;

class ConfigService {
    /**
     * Get a configuration option.
     *
     * @param string $key Option key.
     * @param mixed  $default Default value.
     * @return mixed Option value.
     */
    public function get_option( string $key, $default = null ) {
        // TODO: Retrieve settings from WordPress options or plugin config.
        return $default;
    }

    /**
     * Set a configuration option.
     *
     * @param string $key   Option key.
     * @param mixed  $value Option value.
     */
    public function set_option( string $key, $value ): void {
        // TODO: Persist settings to WordPress options or plugin config.
    }
}
