<?php
/**
 * Backfill route definition.
 *
 * @package PostSecret\Admin
 */

namespace PostSecret\Admin\Routes;

class BackfillRoute {
    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes() {
        register_rest_route(
            'postsecret/v1',
            '/backfill',
            [
                'methods'  => 'POST',
                'callback' => [ $this, 'handle_backfill' ],
                'permission_callback' => function () {
                    return current_user_can( 'manage_options' );
                },
            ]
        );
    }

    public function handle_backfill( \WP_REST_Request $request ) {
        // TODO: Implement backfill via WP-CLI commands or BackfillService.
        return rest_ensure_response(
            [
                'status' => 'queued',
            ]
        );
    }
}
