<?php
/**
 * Secret model (DTO).
 *
 * @package PostSecret\Admin
 */

namespace PostSecret\Admin\Model;

class Secret {
    public int $id;
    public string $title;
    public string $content;
    public array $topics = [];
    public array $feelings = [];
    public array $meanings = [];

    public function __construct(
        int $id,
        string $title,
        string $content,
        array $topics = [],
        array $feelings = [],
        array $meanings = []
    ) {
        $this->id       = $id;
        $this->title    = $title;
        $this->content  = $content;
        $this->topics   = $topics;
        $this->feelings = $feelings;
        $this->meanings = $meanings;
    }

    /**
     * Get all facets combined.
     *
     * @return array
     */
    public function get_all_facets(): array {
        return array_merge( $this->topics, $this->feelings, $this->meanings );
    }
}
