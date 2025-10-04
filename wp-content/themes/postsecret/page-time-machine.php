<?php
/**
 * Template Name: Time Machine
 *
 * Advanced search interface - browse secrets by date, text, and facets.
 *
 * @package PostSecret
 */

// Cache headers
header('Cache-Control: public, max-age=300, s-maxage=600');

// Get facet values for dropdowns
use PostSecret\Admin\Services\SearchService;

$search_service = new SearchService();
$feelings_facets = $search_service->get_facet_values( 'feelings' );
$locations_facets = $search_service->get_facet_values( 'locations' );
$meanings_facets = $search_service->get_facet_values( 'meanings' );
$style_facets = $search_service->get_facet_values( 'style' );
$topics_facets = $search_service->get_facet_values( 'topics' );
$vibe_facets = $search_service->get_facet_values( 'vibe' );

// Get selected values from URL (alphabetical order)
$selected_feelings = isset( $_GET['feelings'] ) ? (array) $_GET['feelings'] : [];
$selected_locations = isset( $_GET['locations'] ) ? (array) $_GET['locations'] : [];
$selected_meanings = isset( $_GET['meanings'] ) ? (array) $_GET['meanings'] : [];
$selected_style = isset( $_GET['style'] ) ? (array) $_GET['style'] : [];
$selected_topics = isset( $_GET['topics'] ) ? (array) $_GET['topics'] : [];
$selected_vibe = isset( $_GET['vibe'] ) ? (array) $_GET['vibe'] : [];

// Get search query and date range
$search_query = isset( $_GET['q'] ) ? sanitize_text_field( $_GET['q'] ) : '';
$start_date = isset( $_GET['start_date'] ) ? sanitize_text_field( $_GET['start_date'] ) : '';
$end_date = isset( $_GET['end_date'] ) ? sanitize_text_field( $_GET['end_date'] ) : '';

// Execute search if we have any filters
$has_filters = ! empty( $search_query ) ||
               ! empty( $start_date ) ||
               ! empty( $end_date ) ||
               ! empty( $selected_feelings ) ||
               ! empty( $selected_locations ) ||
               ! empty( $selected_meanings ) ||
               ! empty( $selected_style ) ||
               ! empty( $selected_topics ) ||
               ! empty( $selected_vibe );

$search_results = null;
if ( $has_filters ) {
	$current_page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;

	$search_results = $search_service->search(
		$search_query,
		[
			'feelings' => $selected_feelings,
			'locations' => $selected_locations,
			'meanings' => $selected_meanings,
			'style' => $selected_style,
			'topics' => $selected_topics,
			'vibe' => $selected_vibe,
		],
		$current_page,
		24, // per_page
		$start_date,
		$end_date
	);
}

// Create nonce for AJAX requests
$facets_nonce = wp_create_nonce( 'ps_facets_nonce' );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Time Machine - <?php bloginfo('name'); ?></title>
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<?php block_template_part('header'); ?>

<main id="primary" class="ps-time-machine" role="main" style="margin-top:0;margin-bottom:0;padding-top:clamp(2rem, 4vw, 4rem);padding-right:clamp(1rem, 3vw, 2rem);padding-bottom:clamp(2rem, 4vw, 4rem);padding-left:clamp(1rem, 3vw, 2rem);max-width:1200px;margin-left:auto;margin-right:auto">

    <article class="ps-time-machine-content">
        <header class="ps-time-machine-header">
            <h1 class="ps-time-machine-title">
                <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>
                Time Machine
            </h1>
            <p class="ps-time-machine-subtitle">Explore PostSecret's archive across time</p>
        </header>

        <!-- Time Machine Form -->
        <form class="ps-time-machine-form" id="timeMachineForm" method="get" action="">

            <!-- Date Range Section -->
            <section class="ps-date-range-section">
                <h2 class="ps-section-label">
                    <i class="fa-solid fa-calendar-range" aria-hidden="true"></i>
                    Select Time Period
                </h2>

                <div class="ps-date-range-form">
                    <div class="ps-date-inputs">
                        <div class="ps-date-field">
                            <label for="start_date" class="ps-label">Start Date</label>
                            <input
                                type="date"
                                id="start_date"
                                name="start_date"
                                class="ps-date-input"
                                aria-describedby="start_date_hint"
                                value="<?php echo esc_attr( $_GET['start_date'] ?? '' ); ?>"
                            />
                            <span id="start_date_hint" class="ps-field-hint">Beginning of your search period</span>
                        </div>

                        <div class="ps-date-separator" aria-hidden="true">
                            <i class="fa-solid fa-arrow-right"></i>
                        </div>

                        <div class="ps-date-field">
                            <label for="end_date" class="ps-label">End Date</label>
                            <input
                                type="date"
                                id="end_date"
                                name="end_date"
                                class="ps-date-input"
                                aria-describedby="end_date_hint"
                                value="<?php echo esc_attr( $_GET['end_date'] ?? '' ); ?>"
                            />
                            <span id="end_date_hint" class="ps-field-hint">End of your search period</span>
                        </div>
                    </div>

                    <!-- Quick Preset Buttons -->
                    <div class="ps-date-presets">
                        <p class="ps-presets-label">Quick Presets:</p>
                        <div class="ps-preset-buttons">
                            <button type="button" class="ps-preset-btn" data-preset="last-month">
                                <i class="fa-solid fa-calendar-day" aria-hidden="true"></i>
                                Last Month
                            </button>
                            <button type="button" class="ps-preset-btn" data-preset="last-year">
                                <i class="fa-solid fa-calendar" aria-hidden="true"></i>
                                Last Year
                            </button>
                            <button type="button" class="ps-preset-btn" data-preset="last-5-years">
                                <i class="fa-solid fa-calendar-week" aria-hidden="true"></i>
                                Last 5 Years
                            </button>
                            <button type="button" class="ps-preset-btn" data-preset="all-time">
                                <i class="fa-solid fa-infinity" aria-hidden="true"></i>
                                All Time
                            </button>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="ps-date-actions">
                        <button type="button" id="clearDates" class="ps-btn">
                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                            Clear Dates
                        </button>
                    </div>

                    <!-- Validation Message -->
                    <div id="dateValidation" class="ps-validation-message" role="alert" aria-live="polite"></div>
                </div>
            </section>

            <!-- Facet Filters Section -->
            <section class="ps-facet-section">
                <h2 class="ps-section-label">
                    <i class="fa-solid fa-filter" aria-hidden="true"></i>
                    Filter by Themes
                </h2>

                <div class="ps-facet-filters" id="facetFilters">

                    <!-- Feelings (Red) -->
                    <div class="ps-facet-group ps-facet-group--feeling">
                        <label class="ps-facet-label">
                            <i class="fa-solid fa-heart" aria-hidden="true"></i>
                            Feelings
                        </label>
                        <div class="ps-facet-scroll-container" data-facet-type="feelings">
                            <div class="ps-facet-pills" id="feelingsPills" role="group" aria-label="Feelings filter">
                                <?php foreach ( array_slice( $feelings_facets, 0, 20 ) as $facet ) : ?>
                                    <button
                                        type="button"
                                        class="ps-facet-pill ps-facet-pill--feeling <?php echo in_array( $facet['value'], $selected_feelings, true ) ? 'selected' : ''; ?>"
                                        data-value="<?php echo esc_attr( $facet['value'] ); ?>"
                                        aria-pressed="<?php echo in_array( $facet['value'], $selected_feelings, true ) ? 'true' : 'false'; ?>">
                                        <?php echo esc_html( $facet['value'] ); ?>
                                        <span class="ps-facet-count">(<?php echo esc_html( $facet['count'] ); ?>)</span>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                            <div class="ps-facet-loader" style="display: none;">
                                <i class="fa-solid fa-spinner fa-spin"></i>
                            </div>
                        </div>
                        <div id="feelingsInputs" class="ps-facet-inputs">
                            <?php foreach ( $selected_feelings as $feeling ) : ?>
                                <input type="hidden" name="feelings[]" value="<?php echo esc_attr( $feeling ); ?>">
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Locations (Orange) -->
                    <div class="ps-facet-group ps-facet-group--location">
                        <label class="ps-facet-label">
                            <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
                            Locations
                        </label>
                        <div class="ps-facet-scroll-container" data-facet-type="locations">
                            <div class="ps-facet-pills" id="locationsPills" role="group" aria-label="Locations filter">
                                <?php foreach ( array_slice( $locations_facets, 0, 20 ) as $facet ) : ?>
                                    <button
                                        type="button"
                                        class="ps-facet-pill ps-facet-pill--location <?php echo in_array( $facet['value'], $selected_locations, true ) ? 'selected' : ''; ?>"
                                        data-value="<?php echo esc_attr( $facet['value'] ); ?>"
                                        aria-pressed="<?php echo in_array( $facet['value'], $selected_locations, true ) ? 'true' : 'false'; ?>">
                                        <?php echo esc_html( $facet['value'] ); ?>
                                        <span class="ps-facet-count">(<?php echo esc_html( $facet['count'] ); ?>)</span>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                            <div class="ps-facet-loader" style="display: none;">
                                <i class="fa-solid fa-spinner fa-spin"></i>
                            </div>
                        </div>
                        <div id="locationsInputs" class="ps-facet-inputs">
                            <?php foreach ( $selected_locations as $location ) : ?>
                                <input type="hidden" name="locations[]" value="<?php echo esc_attr( $location ); ?>">
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Meanings (Yellow) -->
                    <div class="ps-facet-group ps-facet-group--meaning">
                        <label class="ps-facet-label">
                            <i class="fa-solid fa-lightbulb" aria-hidden="true"></i>
                            Meanings
                        </label>
                        <div class="ps-facet-scroll-container" data-facet-type="meanings">
                            <div class="ps-facet-pills" id="meaningsPills" role="group" aria-label="Meanings filter">
                                <?php foreach ( array_slice( $meanings_facets, 0, 20 ) as $facet ) : ?>
                                    <button
                                        type="button"
                                        class="ps-facet-pill ps-facet-pill--meaning <?php echo in_array( $facet['value'], $selected_meanings, true ) ? 'selected' : ''; ?>"
                                        data-value="<?php echo esc_attr( $facet['value'] ); ?>"
                                        aria-pressed="<?php echo in_array( $facet['value'], $selected_meanings, true ) ? 'true' : 'false'; ?>">
                                        <?php echo esc_html( $facet['value'] ); ?>
                                        <span class="ps-facet-count">(<?php echo esc_html( $facet['count'] ); ?>)</span>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                            <div class="ps-facet-loader" style="display: none;">
                                <i class="fa-solid fa-spinner fa-spin"></i>
                            </div>
                        </div>
                        <div id="meaningsInputs" class="ps-facet-inputs">
                            <?php foreach ( $selected_meanings as $meaning ) : ?>
                                <input type="hidden" name="meanings[]" value="<?php echo esc_attr( $meaning ); ?>">
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Style (Green) -->
                    <div class="ps-facet-group ps-facet-group--style">
                        <label class="ps-facet-label">
                            <i class="fa-solid fa-palette" aria-hidden="true"></i>
                            Style
                        </label>
                        <div class="ps-facet-scroll-container" data-facet-type="style">
                            <div class="ps-facet-pills" id="stylePills" role="group" aria-label="Style filter">
                                <?php foreach ( array_slice( $style_facets, 0, 20 ) as $facet ) : ?>
                                    <button
                                        type="button"
                                        class="ps-facet-pill ps-facet-pill--style <?php echo in_array( $facet['value'], $selected_style, true ) ? 'selected' : ''; ?>"
                                        data-value="<?php echo esc_attr( $facet['value'] ); ?>"
                                        aria-pressed="<?php echo in_array( $facet['value'], $selected_style, true ) ? 'true' : 'false'; ?>">
                                        <?php echo esc_html( $facet['value'] ); ?>
                                        <span class="ps-facet-count">(<?php echo esc_html( $facet['count'] ); ?>)</span>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                            <div class="ps-facet-loader" style="display: none;">
                                <i class="fa-solid fa-spinner fa-spin"></i>
                            </div>
                        </div>
                        <div id="styleInputs" class="ps-facet-inputs">
                            <?php foreach ( $selected_style as $style_val ) : ?>
                                <input type="hidden" name="style[]" value="<?php echo esc_attr( $style_val ); ?>">
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Topics (Blue) -->
                    <div class="ps-facet-group ps-facet-group--topic">
                        <label class="ps-facet-label">
                            <i class="fa-solid fa-tag" aria-hidden="true"></i>
                            Topics
                        </label>
                        <div class="ps-facet-scroll-container" data-facet-type="topics">
                            <div class="ps-facet-pills" id="topicsPills" role="group" aria-label="Topics filter">
                                <?php foreach ( array_slice( $topics_facets, 0, 20 ) as $facet ) : ?>
                                    <button
                                        type="button"
                                        class="ps-facet-pill ps-facet-pill--topic <?php echo in_array( $facet['value'], $selected_topics, true ) ? 'selected' : ''; ?>"
                                        data-value="<?php echo esc_attr( $facet['value'] ); ?>"
                                        aria-pressed="<?php echo in_array( $facet['value'], $selected_topics, true ) ? 'true' : 'false'; ?>">
                                        <?php echo esc_html( $facet['value'] ); ?>
                                        <span class="ps-facet-count">(<?php echo esc_html( $facet['count'] ); ?>)</span>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                            <div class="ps-facet-loader" style="display: none;">
                                <i class="fa-solid fa-spinner fa-spin"></i>
                            </div>
                        </div>
                        <div id="topicsInputs" class="ps-facet-inputs">
                            <?php foreach ( $selected_topics as $topic ) : ?>
                                <input type="hidden" name="topics[]" value="<?php echo esc_attr( $topic ); ?>">
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Vibe (Violet) -->
                    <div class="ps-facet-group ps-facet-group--vibe">
                        <label class="ps-facet-label">
                            <i class="fa-solid fa-mountain-sun" aria-hidden="true"></i>
                            Vibe
                        </label>
                        <div class="ps-facet-scroll-container" data-facet-type="vibe">
                            <div class="ps-facet-pills" id="vibePills" role="group" aria-label="Vibe filter">
                                <?php foreach ( array_slice( $vibe_facets, 0, 20 ) as $facet ) : ?>
                                    <button
                                        type="button"
                                        class="ps-facet-pill ps-facet-pill--vibe <?php echo in_array( $facet['value'], $selected_vibe, true ) ? 'selected' : ''; ?>"
                                        data-value="<?php echo esc_attr( $facet['value'] ); ?>"
                                        aria-pressed="<?php echo in_array( $facet['value'], $selected_vibe, true ) ? 'true' : 'false'; ?>">
                                        <?php echo esc_html( $facet['value'] ); ?>
                                        <span class="ps-facet-count">(<?php echo esc_html( $facet['count'] ); ?>)</span>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                            <div class="ps-facet-loader" style="display: none;">
                                <i class="fa-solid fa-spinner fa-spin"></i>
                            </div>
                        </div>
                        <div id="vibeInputs" class="ps-facet-inputs">
                            <?php foreach ( $selected_vibe as $vibe_val ) : ?>
                                <input type="hidden" name="vibe[]" value="<?php echo esc_attr( $vibe_val ); ?>">
                            <?php endforeach; ?>
                        </div>
                    </div>

                </div>
            </section>

            <!-- Search Section -->
            <section class="ps-search-section">
                <h2 class="ps-section-label">Search Text</h2>

                <!-- Search Input -->
                <div class="ps-search-input-group">
                    <input
                        type="search"
                        id="search_query"
                        name="q"
                        class="ps-search-input"
                        placeholder="Search secrets by keywords..."
                        value="<?php echo esc_attr( $_GET['q'] ?? '' ); ?>"
                        aria-describedby="search_hint"
                    />
                    <button type="button" id="clearSearch" class="ps-search-clear" aria-label="Clear search" style="display: none;">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
                <span id="search_hint" class="ps-field-hint">Search through extracted text on postcards</span>
            </section>

            <!-- Submit Section -->
            <section class="ps-submit-section">
                <button type="submit" class="ps-btn ps-btn--solid ps-btn--large">
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                    Search Secrets
                </button>
                <button type="button" id="resetAll" class="ps-btn ps-btn--large">
                    <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                    Reset All Filters
                </button>
            </section>
        </form>

        <!-- Results Section -->
        <?php if ( $search_results ) : ?>
        <section class="ps-results-section" id="resultsSection">
            <header class="ps-results-header">
                <h2 class="ps-section-label">
                    <i class="fa-solid fa-grid-2" aria-hidden="true"></i>
                    Search Results
                </h2>
                <p class="ps-results-meta">
                    Found <strong><?php echo number_format( $search_results['total'] ); ?></strong> secrets
                    <?php if ( $search_results['total'] > $search_results['per_page'] ) : ?>
                        (Page <?php echo $search_results['page']; ?> of <?php echo $search_results['total_pages']; ?>)
                    <?php endif; ?>
                </p>
            </header>

            <?php if ( ! empty( $search_results['posts'] ) ) : ?>
                <div id="resultsContainer" class="ps-results-grid">
                    <?php foreach ( $search_results['posts'] as $post ) : ?>
                        <?php
                        setup_postdata( $post );
                        $attachment_id = $post->ID;
                        $image_url = wp_get_attachment_image_url( $attachment_id, 'medium' );
                        $full_url = wp_get_attachment_url( $attachment_id );

                        // Get facets
                        $topics = get_post_meta( $attachment_id, '_ps_topics', true );
                        $feelings = get_post_meta( $attachment_id, '_ps_feelings', true );
                        $meanings = get_post_meta( $attachment_id, '_ps_meanings', true );
                        $all_facets = array_merge(
                            is_array( $topics ) ? $topics : [],
                            is_array( $feelings ) ? $feelings : [],
                            is_array( $meanings ) ? $meanings : []
                        );

                        // Get text
                        $text = get_post_meta( $attachment_id, '_ps_text', true );
                        $excerpt = ! empty( $text ) ? wp_trim_words( $text, 20, '...' ) : '';

                        // Get dates
                        $submission_date = get_post_meta( $attachment_id, '_ps_submission_date', true );
                        $display_date = ! empty( $submission_date ) ? $submission_date : get_the_date( 'Y-m-d', $post );
                        ?>
                        <article class="ps-result-card">
                            <a href="<?php echo esc_url( get_attachment_link( $attachment_id ) ); ?>" class="ps-result-link">
                                <?php if ( $image_url ) : ?>
                                    <div class="ps-result-image">
                                        <img src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( get_the_title( $post ) ?: 'PostSecret' ); ?>" loading="lazy" />
                                    </div>
                                <?php endif; ?>

                                <div class="ps-result-content">
                                    <?php if ( ! empty( $all_facets ) ) : ?>
                                        <div class="ps-result-facets">
                                            <?php
                                            $display_facets = array_slice( $all_facets, 0, 3 );
                                            $overflow_count = count( $all_facets ) - 3;
                                            ?>
                                            <?php foreach ( $display_facets as $facet ) : ?>
                                                <span class="ps-result-facet-chip"><?php echo esc_html( $facet ); ?></span>
                                            <?php endforeach; ?>
                                            <?php if ( $overflow_count > 0 ) : ?>
                                                <span class="ps-result-facet-chip ps-result-facet-overflow">+<?php echo $overflow_count; ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ( $excerpt ) : ?>
                                        <p class="ps-result-excerpt"><?php echo esc_html( $excerpt ); ?></p>
                                    <?php endif; ?>

                                    <time class="ps-result-date" datetime="<?php echo esc_attr( $display_date ); ?>">
                                        <?php echo esc_html( date( 'F j, Y', strtotime( $display_date ) ) ); ?>
                                    </time>
                                </div>
                            </a>
                        </article>
                    <?php endforeach; ?>
                    <?php wp_reset_postdata(); ?>
                </div>

                <?php if ( $search_results['total_pages'] > 1 ) : ?>
                    <nav class="ps-pagination" aria-label="Search results pagination">
                        <?php
                        // Build pagination URL
                        $base_url = remove_query_arg( 'paged', $_SERVER['REQUEST_URI'] );
                        $page = $search_results['page'];
                        $total_pages = $search_results['total_pages'];
                        ?>

                        <?php if ( $page > 1 ) : ?>
                            <a href="<?php echo esc_url( add_query_arg( 'paged', $page - 1, $base_url ) ); ?>" class="ps-pagination-btn ps-pagination-prev">
                                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                                Previous
                            </a>
                        <?php else : ?>
                            <span class="ps-pagination-btn ps-pagination-prev ps-pagination-disabled" aria-disabled="true">
                                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                                Previous
                            </span>
                        <?php endif; ?>

                        <span class="ps-pagination-info">
                            Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                        </span>

                        <?php if ( $page < $total_pages ) : ?>
                            <a href="<?php echo esc_url( add_query_arg( 'paged', $page + 1, $base_url ) ); ?>" class="ps-pagination-btn ps-pagination-next">
                                Next
                                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                            </a>
                        <?php else : ?>
                            <span class="ps-pagination-btn ps-pagination-next ps-pagination-disabled" aria-disabled="true">
                                Next
                                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                            </span>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>

            <?php else : ?>
                <div class="ps-no-results">
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                    <h3>No secrets found</h3>
                    <p>Try adjusting your filters or date range to see more results.</p>
                </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

    </article>

</main>

<style>
/* ========================================
   TIME MACHINE - MOBILE FIRST
   ======================================== */
.ps-time-machine {
    min-height: 60vh;
}

.ps-time-machine-content {
    max-width: 900px;
    margin: 0 auto;
}

.ps-time-machine-form {
    display: flex;
    flex-direction: column;
    gap: clamp(1.5rem, 3vw, 2rem);
}

/* Header */
.ps-time-machine-header {
    text-align: center;
    margin-bottom: clamp(2rem, 4vw, 3rem);
    padding-bottom: clamp(1.5rem, 3vw, 2rem);
    border-bottom: 2px solid var(--wp--preset--color--accent);
}

.ps-time-machine-title {
    font-size: clamp(2rem, 4vw, 3rem);
    font-weight: 700;
    margin: 0 0 0.75rem 0;
    color: var(--wp--preset--color--text);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: clamp(0.75rem, 2vw, 1rem);
}

.ps-time-machine-title i {
    color: var(--wp--preset--color--accent);
}

.ps-time-machine-subtitle {
    font-size: clamp(1rem, 2vw, 1.25rem);
    color: var(--wp--preset--color--muted);
    margin: 0;
}

/* Screen reader only */
.ps-sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border-width: 0;
}

/* Section Labels - Consistent across all sections */
.ps-section-label {
    font-size: clamp(1.25rem, 2.5vw, 1.5rem);
    font-weight: 600;
    margin-bottom: 1rem;
    color: var(--wp--preset--color--text);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.ps-section-label i {
    color: var(--wp--preset--color--accent);
    font-size: 1em;
}

/* Date Range Section */
.ps-date-range-section {
    padding-bottom: clamp(1.5rem, 3vw, 2rem);
    border-bottom: 2px solid var(--wp--preset--color--accent);
}

/* Facet Section */
.ps-facet-section {
    padding-bottom: clamp(1.5rem, 3vw, 2rem);
    border-bottom: 1px solid var(--wp--preset--color--border);
}

/* Search Section */
.ps-search-section {
    padding-bottom: clamp(1.5rem, 3vw, 2rem);
    border-bottom: 1px solid var(--wp--preset--color--border);
}

/* ========================================
   SEARCH INPUT
   ======================================== */
.ps-search-input-group {
    position: relative;
    display: flex;
    align-items: center;
    margin-bottom: 0.5rem;
}

.ps-search-input {
    flex: 1;
    width: 100%;
    padding: 0.875rem 3rem 0.875rem 1rem;
    font-size: 1rem;
    border: 2px solid var(--wp--preset--color--border);
    background: var(--wp--preset--color--bg);
    color: var(--wp--preset--color--text);
    transition: border-color 0.2s ease;
    border-radius: 0;
}

.ps-search-input:focus {
    border-color: var(--wp--preset--color--accent);
    outline: none;
}

.ps-search-clear {
    position: absolute;
    right: 0.5rem;
    background: transparent;
    border: none;
    color: var(--wp--preset--color--muted);
    padding: 0.5rem;
    cursor: pointer;
    font-size: 1.125rem;
    transition: color 0.2s ease;
    min-width: 44px;
    min-height: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.ps-search-clear:hover,
.ps-search-clear:focus {
    color: var(--wp--preset--color--accent);
}

.ps-search-clear:focus-visible {
    outline: 2px solid var(--wp--preset--color--accent);
    outline-offset: 2px;
}

.ps-field-hint {
    font-size: 0.75rem;
    color: var(--wp--preset--color--muted);
    line-height: 1.4;
}

@media (min-width: 768px) {
    .ps-search-input {
        padding: 1rem 3rem 1rem 1.25rem;
        font-size: 1.125rem;
    }

    .ps-search-clear {
        right: 0.75rem;
        font-size: 1.25rem;
    }

    .ps-field-hint {
        font-size: 0.875rem;
    }
}

/* ========================================
   FACET FILTERS
   ======================================== */
.ps-facet-filters {
    padding: clamp(1rem, 2vw, 1.5rem);
    background: var(--wp--preset--color--tint);
    border: 1px solid var(--wp--preset--color--border);
    border-radius: 0;
    display: flex;
    flex-direction: column;
    gap: clamp(1rem, 2vw, 1.5rem);
}

.ps-facet-group {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.ps-facet-label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--wp--preset--color--text);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 0.25rem;
}

.ps-facet-label i {
    color: var(--wp--preset--color--accent);
    font-size: 0.875rem;
}

.ps-facet-inputs {
    display: none;
}

/* Pill Scroll Container */
.ps-facet-scroll-container {
    position: relative;
    overflow-x: auto;
    overflow-y: hidden;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
    scrollbar-color: var(--wp--preset--color--border) transparent;
}

.ps-facet-scroll-container::-webkit-scrollbar {
    height: 6px;
}

.ps-facet-scroll-container::-webkit-scrollbar-track {
    background: transparent;
}

.ps-facet-scroll-container::-webkit-scrollbar-thumb {
    background: var(--wp--preset--color--border);
    border-radius: 3px;
}

.ps-facet-scroll-container::-webkit-scrollbar-thumb:hover {
    background: var(--wp--preset--color--muted);
}

html[data-theme="dark"] .ps-facet-scroll-container::-webkit-scrollbar-thumb {
    background: var(--wp--preset--color--muted);
}

/* Pills Container */
.ps-facet-pills {
    display: flex;
    gap: 0.5rem;
    padding-bottom: 0.5rem;
    min-width: min-content;
}

/* Pill Buttons - Mobile First */
.ps-facet-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.5rem 0.875rem;
    background: var(--wp--preset--color--bg);
    border: 1px solid var(--wp--preset--color--border);
    border-radius: 9999px;
    color: var(--wp--preset--color--text);
    font-size: 0.875rem;
    font-weight: 500;
    white-space: nowrap;
    cursor: pointer;
    transition: all 0.2s ease;
    min-height: 44px;
}

.ps-facet-pill:hover {
    border-color: var(--wp--preset--color--accent);
    background: var(--wp--preset--color--tint);
    transform: translateY(-1px);
}

.ps-facet-pill:focus-visible {
    outline: 2px solid var(--wp--preset--color--accent);
    outline-offset: 2px;
}

.ps-facet-pill.selected {
    background: var(--wp--preset--color--accent);
    border-color: var(--wp--preset--color--accent);
    color: #ffffff;
    font-weight: 600;
}

.ps-facet-pill.selected:hover {
    opacity: 0.9;
    transform: translateY(-1px);
}

.ps-facet-count {
    opacity: 0.7;
    font-size: 0.8125rem;
}

.ps-facet-pill.selected .ps-facet-count {
    opacity: 0.9;
}

@media (prefers-reduced-motion: reduce) {
    .ps-facet-pill {
        transition: none;
    }

    .ps-facet-pill:hover {
        transform: none;
    }
}

/* ========================================
   ROYGBV COLOR SCHEME (Alphabetical)
   ======================================== */

/* Feelings - Red */
.ps-facet-pill--feeling {
    border-color: #ef4444;
}

.ps-facet-pill--feeling.selected {
    background: #ef4444;
    border-color: #ef4444;
}

html[data-theme="dark"] .ps-facet-pill--feeling {
    border-color: #f87171;
}

html[data-theme="dark"] .ps-facet-pill--feeling.selected {
    background: #f87171;
    border-color: #f87171;
}

/* Locations - Orange */
.ps-facet-pill--location {
    border-color: #f97316;
}

.ps-facet-pill--location.selected {
    background: #f97316;
    border-color: #f97316;
}

html[data-theme="dark"] .ps-facet-pill--location {
    border-color: #fb923c;
}

html[data-theme="dark"] .ps-facet-pill--location.selected {
    background: #fb923c;
    border-color: #fb923c;
}

/* Meanings - Yellow */
.ps-facet-pill--meaning {
    border-color: #eab308;
}

.ps-facet-pill--meaning.selected {
    background: #eab308;
    border-color: #eab308;
}

html[data-theme="dark"] .ps-facet-pill--meaning {
    border-color: #facc15;
}

html[data-theme="dark"] .ps-facet-pill--meaning.selected {
    background: #facc15;
    border-color: #facc15;
}

/* Style - Green */
.ps-facet-pill--style {
    border-color: #22c55e;
}

.ps-facet-pill--style.selected {
    background: #22c55e;
    border-color: #22c55e;
}

html[data-theme="dark"] .ps-facet-pill--style {
    border-color: #4ade80;
}

html[data-theme="dark"] .ps-facet-pill--style.selected {
    background: #4ade80;
    border-color: #4ade80;
}

/* Topics - Blue */
.ps-facet-pill--topic {
    border-color: #3b82f6;
}

.ps-facet-pill--topic.selected {
    background: #3b82f6;
    border-color: #3b82f6;
}

html[data-theme="dark"] .ps-facet-pill--topic {
    border-color: #60a5fa;
}

html[data-theme="dark"] .ps-facet-pill--topic.selected {
    background: #60a5fa;
    border-color: #60a5fa;
}

/* Vibe - Violet */
.ps-facet-pill--vibe {
    border-color: #8b5cf6;
}

.ps-facet-pill--vibe.selected {
    background: #8b5cf6;
    border-color: #8b5cf6;
}

html[data-theme="dark"] .ps-facet-pill--vibe {
    border-color: #a78bfa;
}

html[data-theme="dark"] .ps-facet-pill--vibe.selected {
    background: #a78bfa;
    border-color: #a78bfa;
}

/* Loader */
.ps-facet-loader {
    display: inline-flex;
    align-items: center;
    padding: 0.5rem 1rem;
    color: var(--wp--preset--color--muted);
    font-size: 0.875rem;
}

/* Tablet */
@media (min-width: 768px) {
    .ps-facet-label {
        font-size: 0.875rem;
    }

    .ps-facet-label i {
        font-size: 1rem;
    }

    .ps-facet-pills {
        gap: 0.625rem;
    }

    .ps-facet-pill {
        padding: 0.625rem 1rem;
        font-size: 0.9375rem;
        min-height: 44px;
    }
}

/* Desktop */
@media (min-width: 1024px) {
    .ps-facet-pills {
        gap: 0.75rem;
    }
}

/* ========================================
   DATE RANGE
   ======================================== */
.ps-date-range-form {
    display: flex;
    flex-direction: column;
    gap: clamp(1.5rem, 3vw, 2rem);
}

/* Date Inputs - Mobile First */
.ps-date-inputs {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1rem;
    align-items: start;
}

.ps-date-field {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.ps-label {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--wp--preset--color--text);
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.ps-date-input {
    width: 100%;
    padding: 0.875rem 1rem;
    font-size: 1rem;
    border: 2px solid var(--wp--preset--color--border);
    border-radius: 0;
    background: var(--wp--preset--color--bg);
    color: var(--wp--preset--color--text);
    transition: border-color 0.2s ease;
}

.ps-date-input:focus {
    border-color: var(--wp--preset--color--accent);
    outline: none;
}

.ps-date-input:focus-visible {
    outline: 2px solid var(--wp--preset--color--accent);
    outline-offset: 2px;
}

.ps-date-separator {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0.5rem 0;
    color: var(--wp--preset--color--accent);
    font-size: 1.25rem;
    transform: rotate(90deg);
}

/* Tablet */
@media (min-width: 768px) {
    .ps-date-inputs {
        grid-template-columns: 1fr auto 1fr;
        gap: 1.5rem;
    }

    .ps-label {
        font-size: 0.875rem;
    }

    .ps-date-separator {
        padding-top: 2rem;
        padding-bottom: 0;
        transform: rotate(0deg);
    }
}

/* Date Presets */
.ps-date-presets {
    border-top: 1px solid var(--wp--preset--color--border);
    padding-top: clamp(1.25rem, 2.5vw, 1.5rem);
}

.ps-presets-label {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--wp--preset--color--muted);
    margin-bottom: 0.75rem;
}

.ps-preset-buttons {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.5rem;
}

.ps-preset-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.875rem 1rem;
    background: transparent;
    border: 1px solid var(--wp--preset--color--border);
    border-radius: 0;
    color: var(--wp--preset--color--text);
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
    text-align: center;
    min-height: 44px;
}

.ps-preset-btn:hover,
.ps-preset-btn:focus {
    background: var(--wp--preset--color--tint);
    border-color: var(--wp--preset--color--accent);
    color: var(--wp--preset--color--accent);
}

.ps-preset-btn:focus-visible {
    outline: 2px solid var(--wp--preset--color--accent);
    outline-offset: 2px;
}

.ps-preset-btn i {
    font-size: 0.875rem;
}

/* Tablet */
@media (min-width: 768px) {
    .ps-presets-label {
        font-size: 0.875rem;
        margin-bottom: 1rem;
    }

    .ps-preset-buttons {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
    }

    .ps-preset-btn {
        padding: 0.75rem 1.25rem;
    }
}

/* ========================================
   BUTTONS & ACTIONS
   ======================================== */

/* Date Actions */
.ps-date-actions {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.ps-date-actions .ps-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    width: 100%;
    min-height: 44px;
}

/* Submit Section */
.ps-submit-section {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    padding-top: clamp(1rem, 2vw, 1.5rem);
    margin-bottom: clamp(2rem, 4vw, 3rem);
}

.ps-btn--large {
    padding: 1rem 1.5rem;
    font-size: 1rem;
    width: 100%;
    min-height: 48px;
    justify-content: center;
}

/* Tablet */
@media (min-width: 768px) {
    .ps-date-actions {
        flex-direction: row;
        gap: 1rem;
    }

    .ps-date-actions .ps-btn {
        width: auto;
    }

    .ps-submit-section {
        flex-direction: row;
        gap: 1rem;
    }

    .ps-btn--large {
        width: auto;
        padding: 1rem 2rem;
    }
}

/* ========================================
   VALIDATION & RESULTS
   ======================================== */

.ps-validation-message {
    padding: 1rem;
    border-radius: 0;
    font-size: 0.875rem;
    display: none;
}

.ps-validation-message.error {
    display: block;
    background: rgba(220, 38, 38, 0.1);
    color: var(--wp--preset--color--accent);
    border: 1px solid var(--wp--preset--color--accent);
}

.ps-validation-message.success {
    display: block;
    background: rgba(34, 197, 94, 0.1);
    color: #16a34a;
    border: 1px solid #16a34a;
}

html[data-theme="dark"] .ps-validation-message.error {
    background: rgba(239, 68, 68, 0.15);
}

html[data-theme="dark"] .ps-validation-message.success {
    background: rgba(34, 197, 94, 0.15);
    color: #4ade80;
    border-color: #4ade80;
}

/* ========================================
   RESULTS SECTION
   ======================================== */
.ps-results-section {
    margin-top: clamp(2rem, 4vw, 3rem);
    padding-top: clamp(2rem, 4vw, 3rem);
    border-top: 2px solid var(--wp--preset--color--accent);
}

.ps-results-header {
    margin-bottom: clamp(1.5rem, 3vw, 2rem);
}

.ps-results-meta {
    font-size: 0.875rem;
    color: var(--wp--preset--color--muted);
    margin-top: 0.5rem;
}

.ps-results-meta strong {
    color: var(--wp--preset--color--accent);
    font-weight: 600;
}

.ps-results-grid {
    display: grid;
    gap: clamp(1.5rem, 3vw, 2rem);
    grid-template-columns: 1fr;
}

@media (min-width: 640px) {
    .ps-results-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (min-width: 1024px) {
    .ps-results-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

/* Result Card */
.ps-result-card {
    background: var(--wp--preset--color--bg);
    border: 1px solid var(--wp--preset--color--border);
    border-radius: 0;
    overflow: hidden;
    transition: all 0.2s ease;
}

.ps-result-card:hover {
    border-color: var(--wp--preset--color--accent);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

html[data-theme="dark"] .ps-result-card:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
}

@media (prefers-reduced-motion: reduce) {
    .ps-result-card {
        transition: none;
    }

    .ps-result-card:hover {
        transform: none;
    }
}

.ps-result-link {
    display: block;
    text-decoration: none;
    color: inherit;
}

.ps-result-link:focus-visible {
    outline: 2px solid var(--wp--preset--color--accent);
    outline-offset: 2px;
}

.ps-result-image {
    width: 100%;
    aspect-ratio: 3 / 2;
    overflow: hidden;
    background: var(--wp--preset--color--tint);
}

.ps-result-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.ps-result-content {
    padding: 1rem;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.ps-result-facets {
    display: flex;
    flex-wrap: wrap;
    gap: 0.375rem;
}

.ps-result-facet-chip {
    display: inline-block;
    padding: 0.25rem 0.625rem;
    background: var(--wp--preset--color--accent);
    color: #ffffff;
    font-size: 0.6875rem;
    font-weight: 600;
    border-radius: 9999px;
    text-transform: uppercase;
    letter-spacing: 0.025em;
}

.ps-result-facet-overflow {
    background: var(--wp--preset--color--muted);
}

.ps-result-excerpt {
    font-size: 0.875rem;
    line-height: 1.5;
    color: var(--wp--preset--color--text);
    margin: 0;
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
}

.ps-result-date {
    font-size: 0.75rem;
    color: var(--wp--preset--color--muted);
    font-weight: 500;
}

/* No Results */
.ps-no-results {
    text-align: center;
    padding: clamp(3rem, 6vw, 5rem) clamp(1rem, 2vw, 2rem);
    color: var(--wp--preset--color--muted);
}

.ps-no-results i {
    font-size: clamp(3rem, 6vw, 4rem);
    color: var(--wp--preset--color--border);
    margin-bottom: 1.5rem;
}

.ps-no-results h3 {
    font-size: clamp(1.25rem, 2.5vw, 1.5rem);
    font-weight: 600;
    color: var(--wp--preset--color--text);
    margin: 0 0 0.75rem 0;
}

.ps-no-results p {
    font-size: 1rem;
    margin: 0;
}

/* ========================================
   PAGINATION
   ======================================== */
.ps-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin-top: clamp(2rem, 4vw, 3rem);
    padding-top: clamp(1.5rem, 3vw, 2rem);
    border-top: 1px solid var(--wp--preset--color--border);
}

.ps-pagination-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1.25rem;
    background: transparent;
    border: 1px solid var(--wp--preset--color--border);
    border-radius: 0;
    color: var(--wp--preset--color--text);
    font-size: 0.875rem;
    font-weight: 500;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.2s ease;
    min-height: 44px;
}

.ps-pagination-btn:hover,
.ps-pagination-btn:focus {
    background: var(--wp--preset--color--tint);
    border-color: var(--wp--preset--color--accent);
    color: var(--wp--preset--color--accent);
}

.ps-pagination-btn:focus-visible {
    outline: 2px solid var(--wp--preset--color--accent);
    outline-offset: 2px;
}

.ps-pagination-btn.ps-pagination-disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.ps-pagination-btn.ps-pagination-disabled:hover {
    background: transparent;
    border-color: var(--wp--preset--color--border);
    color: var(--wp--preset--color--text);
}

.ps-pagination-info {
    font-size: 0.875rem;
    color: var(--wp--preset--color--muted);
    font-weight: 500;
}

@media (min-width: 768px) {
    .ps-pagination {
        justify-content: center;
    }

    .ps-pagination-btn {
        padding: 0.875rem 1.5rem;
        font-size: 0.9375rem;
    }

    .ps-pagination-info {
        font-size: 1rem;
        min-width: 140px;
        text-align: center;
    }
}

/* Desktop */
@media (min-width: 1200px) {
    .ps-time-machine-content {
        max-width: 1100px;
    }
}
</style>

<script>
(function() {
    'use strict';

    // Facet state management (alphabetical order)
    const facetState = {
        feelings: { offset: 20, hasMore: true, loading: false },
        locations: { offset: 20, hasMore: true, loading: false },
        meanings: { offset: 20, hasMore: true, loading: false },
        style: { offset: 20, hasMore: true, loading: false },
        topics: { offset: 20, hasMore: true, loading: false },
        vibe: { offset: 20, hasMore: true, loading: false }
    };

    const ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
    const facetsNonce = '<?php echo esc_js( $facets_nonce ); ?>';

    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('timeMachineForm');
        const searchInput = document.getElementById('search_query');
        const clearSearchBtn = document.getElementById('clearSearch');
        const startDateInput = document.getElementById('start_date');
        const endDateInput = document.getElementById('end_date');
        const clearDatesBtn = document.getElementById('clearDates');
        const resetAllBtn = document.getElementById('resetAll');
        const validationMsg = document.getElementById('dateValidation');
        const presetButtons = document.querySelectorAll('.ps-preset-btn');

        // Initialize facet pill handlers
        initFacetPills();
        initFacetScrollLoading();

        // Search input clear button
        if (searchInput && clearSearchBtn) {
            searchInput.addEventListener('input', function() {
                clearSearchBtn.style.display = this.value ? 'block' : 'none';
            });

            clearSearchBtn.addEventListener('click', function() {
                searchInput.value = '';
                this.style.display = 'none';
                searchInput.focus();
            });

            // Show clear button on page load if search has value
            if (searchInput.value) {
                clearSearchBtn.style.display = 'block';
            }
        }

        // Clear dates
        if (clearDatesBtn) {
            clearDatesBtn.addEventListener('click', function() {
                startDateInput.value = '';
                endDateInput.value = '';
                hideValidation();
            });
        }

        // Reset all filters
        if (resetAllBtn) {
            resetAllBtn.addEventListener('click', function() {
                // Clear search
                if (searchInput) searchInput.value = '';
                if (clearSearchBtn) clearSearchBtn.style.display = 'none';

                // Clear facet pills
                document.querySelectorAll('.ps-facet-pill.selected').forEach(function(pill) {
                    pill.classList.remove('selected');
                    pill.setAttribute('aria-pressed', 'false');
                });

                // Clear hidden inputs
                document.querySelectorAll('.ps-facet-inputs').forEach(function(container) {
                    container.innerHTML = '';
                });

                // Clear dates
                if (startDateInput) startDateInput.value = '';
                if (endDateInput) endDateInput.value = '';

                hideValidation();
            });
        }

        // Preset button handlers
        presetButtons.forEach(function(btn) {
            btn.addEventListener('click', function() {
                const preset = this.getAttribute('data-preset');
                applyPreset(preset);
            });
        });

        // Form validation
        if (form) {
            form.addEventListener('submit', function(e) {
                if (!validateDates()) {
                    e.preventDefault();
                }
            });
        }

        // Real-time validation on date change
        if (startDateInput) startDateInput.addEventListener('change', validateDates);
        if (endDateInput) endDateInput.addEventListener('change', validateDates);

        function applyPreset(preset) {
            const today = new Date();
            let startDate = new Date();
            let endDate = new Date();

            switch(preset) {
                case 'last-month':
                    startDate.setMonth(today.getMonth() - 1);
                    break;
                case 'last-year':
                    startDate.setFullYear(today.getFullYear() - 1);
                    break;
                case 'last-5-years':
                    startDate.setFullYear(today.getFullYear() - 5);
                    break;
                case 'all-time':
                    startDate = new Date('2005-01-01'); // PostSecret started in 2005
                    break;
            }

            startDateInput.value = formatDate(startDate);
            endDateInput.value = formatDate(endDate);
            validateDates();
        }

        function formatDate(date) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return year + '-' + month + '-' + day;
        }

        function validateDates() {
            const startVal = startDateInput.value;
            const endVal = endDateInput.value;

            // If both are empty, no validation needed
            if (!startVal && !endVal) {
                hideValidation();
                return true;
            }

            // If only one is filled, require both
            if ((startVal && !endVal) || (!startVal && endVal)) {
                showValidation('Please select both start and end dates.', 'error');
                return false;
            }

            // Check if start is after end
            const startDate = new Date(startVal);
            const endDate = new Date(endVal);

            if (startDate > endDate) {
                showValidation('Start date must be before or equal to end date.', 'error');
                return false;
            }

            // Check if dates are in the future
            const today = new Date();
            today.setHours(0, 0, 0, 0);

            if (startDate > today || endDate > today) {
                showValidation('Dates cannot be in the future.', 'error');
                return false;
            }

            hideValidation();
            return true;
        }

        function showValidation(message, type) {
            validationMsg.textContent = message;
            validationMsg.className = 'ps-validation-message ' + type;
        }

        function hideValidation() {
            validationMsg.textContent = '';
            validationMsg.className = 'ps-validation-message';
        }

        // Facet pill selection handler
        function initFacetPills() {
            document.querySelectorAll('.ps-facet-pill').forEach(function(pill) {
                pill.addEventListener('click', function() {
                    toggleFacetPill(this);
                });
            });
        }

        function toggleFacetPill(pill) {
            const value = pill.getAttribute('data-value');
            const container = pill.closest('.ps-facet-scroll-container');
            const facetType = container.getAttribute('data-facet-type');
            const inputsContainer = document.getElementById(facetType + 'Inputs');

            // Toggle selected state
            pill.classList.toggle('selected');
            const isSelected = pill.classList.contains('selected');
            pill.setAttribute('aria-pressed', isSelected ? 'true' : 'false');

            // Update hidden inputs
            if (isSelected) {
                // Add hidden input
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = facetType + '[]';
                input.value = value;
                input.setAttribute('data-value', value);
                inputsContainer.appendChild(input);
            } else {
                // Remove hidden input
                const input = inputsContainer.querySelector('input[data-value="' + value + '"]');
                if (input) {
                    input.remove();
                }
            }
        }

        // Infinite scroll for facet pills
        function initFacetScrollLoading() {
            document.querySelectorAll('.ps-facet-scroll-container').forEach(function(container) {
                container.addEventListener('scroll', function() {
                    handleFacetScroll(this);
                });
            });
        }

        function handleFacetScroll(container) {
            const facetType = container.getAttribute('data-facet-type');
            const state = facetState[facetType];

            if (!state.hasMore || state.loading) {
                return;
            }

            // Check if scrolled near the end (within 100px)
            const scrollLeft = container.scrollLeft;
            const scrollWidth = container.scrollWidth;
            const clientWidth = container.clientWidth;

            if (scrollLeft + clientWidth >= scrollWidth - 100) {
                loadMoreFacets(facetType);
            }
        }

        function loadMoreFacets(facetType) {
            const state = facetState[facetType];

            if (state.loading || !state.hasMore) {
                return;
            }

            state.loading = true;

            const container = document.querySelector('[data-facet-type="' + facetType + '"]');
            const pillsContainer = container.querySelector('.ps-facet-pills');
            const loader = container.querySelector('.ps-facet-loader');

            // Show loader
            loader.style.display = 'inline-flex';

            // Make AJAX request
            const formData = new FormData();
            formData.append('action', 'ps_load_more_facets');
            formData.append('nonce', facetsNonce);
            formData.append('facet_type', facetType);
            formData.append('offset', state.offset);

            fetch(ajaxUrl, {
                method: 'POST',
                body: formData
            })
            .then(function(response) {
                return response.json();
            })
            .then(function(data) {
                if (data.success && data.data.facets) {
                    // Append new pills
                    data.data.facets.forEach(function(facet) {
                        const pill = createFacetPill(facet.value, facet.count, facetType);
                        pillsContainer.appendChild(pill);
                    });

                    // Update state
                    state.offset += data.data.facets.length;
                    state.hasMore = data.data.has_more;
                }
            })
            .catch(function(error) {
                console.error('Error loading facets:', error);
            })
            .finally(function() {
                state.loading = false;
                loader.style.display = 'none';
            });
        }

        function createFacetPill(value, count, facetType) {
            const pill = document.createElement('button');
            pill.type = 'button';

            // Add appropriate class based on facet type for ROYGBV colors
            const typeClass = facetType === 'feelings' ? 'ps-facet-pill--feeling' :
                            facetType === 'locations' ? 'ps-facet-pill--location' :
                            facetType === 'meanings' ? 'ps-facet-pill--meaning' :
                            facetType === 'style' ? 'ps-facet-pill--style' :
                            facetType === 'topics' ? 'ps-facet-pill--topic' :
                            facetType === 'vibe' ? 'ps-facet-pill--vibe' : '';

            pill.className = 'ps-facet-pill ' + typeClass;
            pill.setAttribute('data-value', value);
            pill.setAttribute('aria-pressed', 'false');

            pill.innerHTML = value + ' <span class="ps-facet-count">(' + count + ')</span>';

            pill.addEventListener('click', function() {
                toggleFacetPill(this);
            });

            return pill;
        }
    });
})();
</script>

<?php block_template_part('footer'); ?>

<?php wp_footer(); ?>
</body>
</html>
