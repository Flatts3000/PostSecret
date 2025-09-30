<?php
/**
 * Review route definition.
 *
 * @package PostSecret\Admin
 */

namespace PostSecret\Admin\Routes;

class ReviewRoute {
    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes() {
        register_rest_route(
            'postsecret/v1',
            '/review',
            [
                'methods'  => 'POST',
                'callback' => [ $this, 'handle_review' ],
                'permission_callback' => function () {
                    return current_user_can( 'edit_posts' );
                },
            ]
        );
    }

    public function handle_review( \WP_REST_Request $request ) {
        // TODO: Implement review logic via ModerationService.
        return rest_ensure_response(
            [
                'status' => 'success',
            ]
        );
    }
}
