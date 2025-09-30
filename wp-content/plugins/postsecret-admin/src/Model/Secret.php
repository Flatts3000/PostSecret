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
    public array $tags = [];

    public function __construct( int $id, string $title, string $content, array $tags = [] ) {
        $this->id      = $id;
        $this->title   = $title;
        $this->content = $content;
        $this->tags    = $tags;
    }
}
