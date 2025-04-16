<!-- Event Submission Modal -->
   <!-- Add Event Modal Button -->
   <button id="add-event-btn" class="tribe-common-c-btn tribe-common-c-btn--primary">
        Add Event
    </button>
<div id="event-submission-modal" class="event-submission-modal" style="display: none;">
  <span id="close-modal" class="close-modal">&times;</span>
  <!-- Logged-in User: Event Submission Form (initially hidden) -->
  <?php
    if ( isset($_COOKIE['ecc_user_session_token']) ) :
  ?>
    <div id="event-submission-form-container">
      <h2>
        <?php
          $userDetails = preload_user_details(); // Use preload_user_details to get user details
          if ($userDetails && isset($userDetails['username'])) {
            echo 'Sup, <span id="modal-username">' . esc_html($userDetails['username']) . '</span>?';
          } else {
            echo esc_html__('Add New Event', 'colormag-pro');
          }
        ?>
      </h2>
      <p><?php esc_html_e('Tell us about your event.', 'colormag-pro'); ?></p>
      <form id="event-submission-form">
        <div class="form-field">
          <label for="event-title">Event Title:</label>
          <input type="text" id="event-title" name="event-title" required>
        </div>
        <div class="form-field">
          <label for="event-description">Description:</label>
          <textarea id="event-description" name="event-description"></textarea>
        </div>
        <div class="form-field">
            <label for="ticket-link">Ticket Link (optional):</label>
            <input type="url" id="ticket-link" name="ticket-link">
        </div>
        <div class="form-field">
          <label for="event-date">Date:</label>
          <input type="date" id="event-date" name="event-date" required>
        </div>
        <div class="form-field">
          <label for="event-time">Start Time:</label>
          <input type="time" id="event-time" name="event-time">
        </div>
        <div class="form-field">
          <label for="event-end-time">End Time:</label>
          <input type="time" id="event-end-time" name="event-end-time">
        </div>
        <div class="form-field">
          <label for="event-location">Location (City or State):</label>
          <input type="text" id="event-location" name="event-location" placeholder="City or State">
          <ul id="location-suggestions" class="suggestions"></ul>
        </div>
        <div class="form-field">
          <label for="event-venue">Venue:</label>
          <input type="text" id="event-venue" name="event-venue">
          <!-- Venue suggestions will be inserted dynamically -->
          <ul id="venue-suggestions" class="suggestions"></ul>
        </div>
        <!-- Hidden field to store the venue ID if one is selected -->
        <input type="hidden" id="event-venue-id" name="event-venue-id" value="">
        <!-- Extra venue fields: initially hidden, revealed only if needed -->
        <div id="new-venue-fields" style="display: none;">
          <div class="form-field">
            <label for="venue-address">Venue Address:</label>
            <input type="text" id="venue-address" name="venue-address">
          </div>
        </div>
        <!-- Hidden fields for AJAX -->
        <input type="hidden" name="action" value="submit_event">
        <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('event_submission_nonce'); ?>">
        <button type="submit" class="submit-event-btn">Submit Event</button>
        <button type="button" id="cancel-modal" class="cancel-event-btn">Cancel</button>
        <div id="event-submission-processing-message" style="display:none;">Processing Event Submission...</div>
      </form>
      <div id="event-submission-success-message" style="display:none; color: green;"></div>
      <div id="event-submission-error-message" style="display:none; color: red;"></div>
    </div>
  <?php
    else :
  ?>
    <div id="event-login-prompt-container">
      <p>Community feature: <a href="https://community.extrachill.com/login">Log in to add your event</a></p>
    </div>
  <?php
    endif;
  ?>
</div>
