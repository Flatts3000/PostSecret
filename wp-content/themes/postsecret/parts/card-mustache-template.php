<?php
/**
 * Shared Mustache template for secret cards
 *
 * This is the SINGLE SOURCE OF TRUTH for the card template.
 * Loaded globally in the footer via functions.php (wp_footer action)
 * Used by both front-page.html and search.php
 *
 * @package PostSecret
 */
?>
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
              <div class="ps-card__footer">
                <time class="ps-card__date">{{dateFmt}}</time>
                {{#similarityPercent}}
                <span class="ps-card__similarity" title="Similarity score">{{similarityPercent}}% match</span>
                {{/similarityPercent}}
              </div>
              <span class="ps-card__cta" aria-hidden="true">Click to view details →</span>
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
