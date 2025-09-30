<?php
/**
 * Sanitization utilities.
 *
 * @package PostSecret\Admin
 */

namespace PostSecret\Admin\Util;

class Sanitize {
    /**
     * Sanitize a string.
     *
     * @param string $value Value to sanitize.
     * @return string Sanitized value.
     */
    public static function string( string $value ): string {
        return sanitize_text_field( $value );
    }

    /**
     * Sanitize an array of integers.
     *
     * @param array $values Values to sanitize.
     * @return array Sanitized integers.
     */
    public static function int_array( array $values ): array {
        return array_map( 'intval', $values );
    }
}
