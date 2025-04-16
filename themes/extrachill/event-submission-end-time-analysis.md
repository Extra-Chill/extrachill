## Event Submission Form - End Time Analysis

**Question:** Does the event submission form require both an event start and end time? Should we default to 3 hours after the start time, or require an end time?

**Findings:**

*   **Current Form:** The event submission form (`tribe/events/v2/components/event-submission-modal.php`) includes fields for both "Start Time" and "End Time".

*   **Current Logic:** In the processing script (`extrachill-custom/community-integration/extrachill-event-submission.php`), the end time is **not strictly required**. If no end time is provided, the system defaults to setting the event's end time to **1 hour after the start time**.

    ```php
    if ( empty( $end_time ) ) {
        $end_dt = clone $start_dt;
        $end_dt->modify( '+1 hour' );
    }
    ```

*   **Events Calendar Requirement:** The Events Calendar plugin generally requires an end time for events to function correctly within the calendar system.

**Recommendation:**

To improve data accuracy and compatibility with the Events Calendar, it is recommended to **require an end time** in the event submission form.

**Implementation Steps:**

1.  **Modify the HTML form:** In `tribe/events/v2/components/event-submission-modal.php`, add the `required` attribute to the `event-end-time` input field:

    ```html
    <div class="form-field">
      <label for="event-end-time">End Time:</label>
      <input type="time" id="event-end-time" name="event-end-time" required>
    </div>
    ```

2.  **Update JavaScript Validation:**  In `js/event-submission-modal.js`, within the form submission handler, add validation to ensure that the end time field is filled out before form submission.

3.  **(Optional) Adjust Default Time (If Not Requiring):** If you decide against *requiring* the end time but want a longer default duration, change `'+1 hour'` to `'+3 hours'` in `extrachill-custom/community-integration/extrachill-event-submission.php` (line 149).  However, requiring the field is the stronger recommendation.

**Benefits of Requiring End Time:**

*   **Improved Data Quality:** Ensures more complete and accurate event information.
*   **Events Calendar Compatibility:** Aligns with the Events Calendar plugin's expectations for event data.
*   **User Clarity:**  Reduces ambiguity and encourages users to provide complete event details.

By implementing these changes, the event submission form will be more robust and provide a better user experience for event organizers and calendar users.