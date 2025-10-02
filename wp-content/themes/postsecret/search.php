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

<!-- Mustache card template (same as used on front-page) -->
<script id="psai-card-tpl" type="x-tmpl-mustache">
    <article class="ps-card {{#hasBack}}ps-card--flippable{{/hasBack}}" data-id="{{id}}" data-orientation="{{orientation}}" data-width="{{width}}" data-height="{{height}}">
      <div class="ps-card__inner">
        <!-- Front side -->
        <div class="ps-card__face ps-card__face--front">
          {{#hasBack}}
          <button class="ps-card__flip-btn" type="button" aria-label="Flip to see back" title="Click to flip">↻</button>
          {{/hasBack}}
          <a class="ps-card__link" href="{{link}}" aria-label="{{altFallback}}">
            <figure class="ps-card__media">
              <img class="ps-card__img" src="{{src}}" alt="{{alt}}">
              {{#advisory}}
              <figcaption class="ps-card__badge" aria-label="Content advisory" title="Content advisory">!</figcaption>
              {{/advisory}}
            </figure>
            <div class="ps-card__meta">
              <div class="ps-card__date">{{dateFmt}}</div>
              {{#excerpt}}<p class="ps-card__excerpt">{{excerpt}}</p>{{/excerpt}}
              {{#displayTags.length}}
              <div class="ps-card__tags">
                {{#displayTags}}<span class="ps-chip">{{.}}</span>{{/displayTags}}
                {{#overflowCount}}<span class="ps-chip ps-chip--more">+{{overflowCount}}</span>{{/overflowCount}}
              </div>
              {{/displayTags.length}}
              {{#similarityPercent}}
              <span class="ps-card__similarity" title="Similarity score">{{similarityPercent}}% match</span>
              {{/similarityPercent}}
            </div>
          </a>
        </div>
        <!-- Back side -->
        {{#hasBack}}
        <div class="ps-card__face ps-card__face--back">
          <button class="ps-card__flip-btn" type="button" aria-label="Flip to see front" title="Click to flip back">↻</button>
          <figure class="ps-card__media">
            <img class="ps-card__img" src="{{back_src}}" alt="{{back_alt}}">
          </figure>
        </div>
        {{/hasBack}}
      </div>
    </article>
</script>

<?php
get_footer();
