<?php
/**
 * Archive template for secrets.
 * Redirects to front page as all rendering is done via JavaScript
 *
 * @package PostSecret
 */

wp_redirect( home_url( '/' ) );
exit;
