<?php
/**
 * Settings route definition.
 *
 * @package PostSecret\Admin
 */

namespace PostSecret\Admin\Routes;

class SettingsRoute {
    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes() {
        register_rest_route(
            'postsecret/v1',
            '/settings',
            [
                'methods'  => 'GET',
                'callback' => [ $this, 'get_settings' ],
                'permission_callback' => function () {
                    return current_user_can( 'manage_options' );
                },
            ]
        );
    }

    public function get_settings() {
        // TODO: Retrieve settings via ConfigService.
        return rest_ensure_response(
            [
                'settings' => [],
            ]
        );
    }
}
