<?php
/**
 * Search route definition.
 *
 * @package PostSecret\Admin
 */

namespace PostSecret\Admin\Routes;

class SearchRoute {
    /**
     * Constructor.
     */
    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    /**
     * Register REST routes.
     */
    public function register_routes() {
        register_rest_route(
            'postsecret/v1',
            '/search',
            [
                'methods'  => 'GET',
                'callback' => [ $this, 'handle_search' ],
                'permission_callback' => function () {
                    return current_user_can( 'edit_posts' );
                },
                'args'     => [
                    'q'    => [
                        'description' => __( 'Search query', 'postsecret-admin' ),
                        'type'        => 'string',
                    ],
                    'tags' => [
                        'description' => __( 'Tag filters (comma-separated)', 'postsecret-admin' ),
                        'type'        => 'string',
                    ],
                    'page' => [
                        'description' => __( 'Page number', 'postsecret-admin' ),
                        'type'        => 'integer',
                    ],
                ],
            ]
        );
    }

    /**
     * Handle search requests.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function handle_search( \WP_REST_Request $request ) {
        // TODO: Implement search logic via SearchService.
        $query  = sanitize_text_field( $request->get_param( 'q' ) );
        $tags   = array_filter( array_map( 'trim', explode( ',', $request->get_param( 'tags' ) ) ) );
        $page   = intval( $request->get_param( 'page' ) );

        return rest_ensure_response(
            [
                'results' => [],
                'query'   => $query,
                'tags'    => $tags,
                'page'    => $page,
            ]
        );
    }
}
