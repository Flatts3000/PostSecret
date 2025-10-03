<?php
/**
 * Semantic search results template.
 * Results are loaded via JavaScript from the semantic search API.
 *
 * @package PostSecret
 */

get_header();

$query = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
$is_semantic = isset($_GET['semantic']) && $_GET['semantic'] === '1';
?>

<main id="primary" class="site-main">
    <?php if ($is_semantic && $query): ?>
        <div class="ps-search-header">
            <h1>Searching for: "<?php echo esc_html($query); ?>"</h1>
            <p class="ps-search-loading">
                <i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i>
                Loading results...
            </p>
        </div>
    <?php else: ?>
        <div class="ps-search-header">
            <h1>Search</h1>
            <p>Please enter a search query.</p>
            <a href="/" class="ps-button">Back to Home</a>
        </div>
    <?php endif; ?>
</main>

<?php
get_footer();
