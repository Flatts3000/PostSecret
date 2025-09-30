<?php
/**
 * Taxonomy route definition.
 *
 * @package PostSecret\Admin
 */

namespace PostSecret\Admin\Routes;

class TaxonomyRoute {
    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes() {
        register_rest_route(
            'postsecret/v1',
            '/taxonomy',
            [
                'methods'  => 'POST',
                'callback' => [ $this, 'handle_taxonomy' ],
                'permission_callback' => function () {
                    return current_user_can( 'manage_categories' );
                },
            ]
        );
    }

    public function handle_taxonomy( \WP_REST_Request $request ) {
        // TODO: Implement taxonomy management via TaxonomyService.
        return rest_ensure_response(
            [
                'status' => 'success',
            ]
        );
    }
}
