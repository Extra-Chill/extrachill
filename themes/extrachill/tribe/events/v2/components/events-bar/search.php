<?php
/**
 * View: Events Bar Search
 *
 * @version 5.2.0
 */
?>
<div
    class="tribe-events-c-events-bar__search"
    id="tribe-events-events-bar-search"
    data-js="tribe-events-events-bar-search"
>
    <form
        id="tribe-bar-form"
        class="tribe-events-c-search tribe-events-c-events-bar__search-form"
        method="get"
        data-js="tribe-events-view-form"
        role="search"
    >
        <input type="hidden" name="tribe-events-views[url]" value="<?php echo esc_url( $this->get( 'url' ) ); ?>" />

        <div class="tribe-events-c-search__input-group">
            <?php $this->template( 'components/events-bar/search/keyword' ); ?>
        </div>

        <?php $this->template( 'components/events-bar/search/submit' ); ?>

        <!-- Location filter after the submit button -->
        <div class="tribe-events-c-search__input-group">
            <?php $this->template( 'components/events-bar/location-filter' ); ?>
        </div>
    </form>
</div>
