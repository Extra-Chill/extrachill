<?php
/**
 * Component: Before Events
 *
 *
 * @version 4.9.11
 *
 * @var string $before_events HTML stored on the Advanced settings to be printed before the Events.
 */

 do_action( 'extrachill_before_events' );


if ( ! empty( $before_events ) ) : ?>
    <div class="tribe-events-before-html">
        <?php echo $before_events; ?>
    </div>
<?php endif; ?>
