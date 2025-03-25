<?php

// was never implemented, was testing an idea

// this code is used to display a Charlston music venue quiz on the site

function enqueue_quiz_ajax_script() {
    if (is_page() || is_single()) {
        global $post;
        if (has_shortcode($post->post_content, 'charleston_music_venue_quiz')) {
            wp_enqueue_script('quiz-ajax', get_template_directory_uri() . '/js/quiz-ajax.js', [], null, true);
            wp_localize_script('quiz-ajax', 'quizAjax', ['ajaxurl' => admin_url('admin-ajax.php')]);
        }
    }
}
add_action('wp_enqueue_scripts', 'enqueue_quiz_ajax_script');

function handle_quiz_submission() {
    $top_venue = process_quiz_submission($_POST);
    $result = prepare_quiz_result($top_venue);

    if ($result) {
        echo json_encode([
            'venue' => $top_venue,
            'image' => $result['image'],
            'description' => $result['description']
        ]);
    } else {
        echo json_encode(['error' => 'Invalid venue']);
    }

    wp_die(); // This is required to terminate immediately and return a proper response.
}
add_action('wp_ajax_handle_quiz_submission', 'handle_quiz_submission');
add_action('wp_ajax_nopriv_handle_quiz_submission', 'handle_quiz_submission');

function charleston_music_venue_quiz() {
    ob_start();
    $is_logged_in = isset($_COOKIE['ecc_user_session_token']);
    ?>
    <div id="quiz-container">
        <h2>Which Charleston Music Venue Are You?</h2>
        <form id="music-venue-quiz" method="POST">
    <input type="hidden" name="action" value="handle_quiz_submission">
    <div class="quiz-question active">
        <p>Wake Up</p>
        <label><input type="radio" name="question1" value="venue1">Coffee</label><br>
        <label><input type="radio" name="question1" value="venue2">Ice Water</label><br>
        <label><input type="radio" name="question1" value="venue3">Meditation</label><br>
        <label><input type="radio" name="question1" value="venue4">Gym</label><br>
        <label><input type="radio" name="question1" value="venue5">Disposable Vape</label><br>
        <label><input type="radio" name="question1" value="venue6">Bong Rip</label><br>
        <button type="button" class="next-btn">Next</button>
    </div>
    <div class="quiz-question">
        <p>Eat Breakfast</p>
        <label><input type="radio" name="question2" value="venue1">Nah</label><br>
        <label><input type="radio" name="question2" value="venue2">Buttered Toast</label><br>
        <label><input type="radio" name="question2" value="venue3">Eggs & Bacon</label><br>
        <label><input type="radio" name="question2" value="venue4">Berries</label><br>
        <label><input type="radio" name="question2" value="venue5">Pancakes</label><br>
        <label><input type="radio" name="question2" value="venue6">Chick-Fil-A</label><br>
        <button type="button" class="prev-btn">Previous</button>
        <button type="button" class="next-btn">Next</button>
    </div>
    <div class="quiz-question">
        <p>Late Morning</p>
        <label><input type="radio" name="question3" value="venue1">Walk the dog</label><br>
        <label><input type="radio" name="question3" value="venue2">Beach</label><br>
        <label><input type="radio" name="question3" value="venue3">Brunch</label><br>
        <label><input type="radio" name="question3" value="venue4">Gardening</label><br>
        <label><input type="radio" name="question3" value="venue5">Video Games</label><br>
        <label><input type="radio" name="question3" value="venue6">Vodka</label><br>
        <button type="button" class="prev-btn">Previous</button>
        <button type="button" class="next-btn">Next</button>
    </div>
    <div class="quiz-question">
        <p>Afternoon</p>
        <label><input type="radio" name="question4" value="venue1">Working all day</label><br>
        <label><input type="radio" name="question4" value="venue2">Golfing</label><br>
        <label><input type="radio" name="question4" value="venue3">Kayaking</label><br>
        <label><input type="radio" name="question4" value="venue4">King street bar hopping</label><br>
        <label><input type="radio" name="question4" value="venue5">Shopping</label><br>
        <label><input type="radio" name="question4" value="venue6">Spliffs</label><br>
        <button type="button" class="prev-btn">Previous</button>
        <button type="button" class="next-btn">Next</button>
    </div>
    <div class="quiz-question">
        <p>Getting Dressed</p>
        <label><input type="radio" name="question5" value="venue1">All black</label><br>
        <label><input type="radio" name="question5" value="venue2">Thrifted outfit</label><br>
        <label><input type="radio" name="question5" value="venue3">Matching with friends</label><br>
        <label><input type="radio" name="question5" value="venue4">Not changing my clothes</label><br>
        <label><input type="radio" name="question5" value="venue5">Dressed to the nines</label><br>
        <label><input type="radio" name="question5" value="venue6">Tie-Dye</label><br>
        <button type="button" class="prev-btn">Previous</button>
        <button type="button" class="next-btn">Next</button>
    </div>
    <div class="quiz-question">
        <p>Pre Show Ritual</p>
        <label><input type="radio" name="question6" value="venue1">Blasting music</label><br>
        <label><input type="radio" name="question6" value="venue2">Meeting up with friends</label><br>
        <label><input type="radio" name="question6" value="venue3">Planning my ride home</label><br>
        <label><input type="radio" name="question6" value="venue4">Nap</label><br>
        <label><input type="radio" name="question6" value="venue5">Pregame drinks</label><br>
        <label><input type="radio" name="question6" value="venue6">Blunt</label><br>
        <button type="button" class="prev-btn">Previous</button>
        <button type="button" class="next-btn">Next</button>
    </div>
    <div class="quiz-question">
        <p>Getting to the Show</p>
        <label><input type="radio" name="question7" value="venue1">Walking</label><br>
        <label><input type="radio" name="question7" value="venue2">Uber</label><br>
        <label><input type="radio" name="question7" value="venue3">Driving</label><br>
        <label><input type="radio" name="question7" value="venue4">Golf Cart</label><br>
        <label><input type="radio" name="question7" value="venue5">Carpooling with friends</label><br>
        <label><input type="radio" name="question7" value="venue6">No idea yet</label><br>
        <button type="button" class="prev-btn">Previous</button>
        <button type="button" class="next-btn">Next</button>
    </div>
    <div class="quiz-question">
        <p>Order A Drink</p>
        <label><input type="radio" name="question8" value="venue1">PBR</label><br>
        <label><input type="radio" name="question8" value="venue2">Tequila shot</label><br>
        <label><input type="radio" name="question8" value="venue3">Glitter Pony IPA</label><br>
        <label><input type="radio" name="question8" value="venue4">Vodka soda</label><br>
        <label><input type="radio" name="question8" value="venue5">Red wine</label><br>
        <label><input type="radio" name="question8" value="venue6">Ice water</label><br>
        <button type="button" class="prev-btn">Previous</button>
        <button type="button" class="next-btn">Next</button>
    </div>
    <div class="quiz-question">
        <p>During the Show</p>
        <label><input type="radio" name="question9" value="venue1">Front row</label><br>
        <label><input type="radio" name="question9" value="venue2">Outside with friends</label><br>
        <label><input type="radio" name="question9" value="venue3">At the bar</label><br>
        <label><input type="radio" name="question9" value="venue4">Enjoying from the back</label><br>
        <label><input type="radio" name="question9" value="venue5">Smoker's section</label><br>
        <label><input type="radio" name="question9" value="venue6">Dancing</label><br>
        <button type="button" class="prev-btn">Previous</button>
        <button type="button" class="next-btn">Next</button>
    </div>
    <div class="quiz-question">
        <p>Late Night</p>
        <label><input type="radio" name="question10" value="venue1">Asleep by midnight</label><br>
        <label><input type="radio" name="question10" value="venue2">Rec Room</label><br>
        <label><input type="radio" name="question10" value="venue3">French fries</label><br>
        <label><input type="radio" name="question10" value="venue4">House party</label><br>
        <label><input type="radio" name="question10" value="venue5">Bong rips</label><br>
        <label><input type="radio" name="question10" value="venue6">Would rather not say</label><br>
        <button type="button" class="prev-btn">Previous</button>
        <button type="button" class="next-btn">Next</button>
    </div>
    <?php if (!$is_logged_in): ?>
    <div class="quiz-question">
        <p>Please enter your email to find out your result:</p>
        <input type="email" name="user_email" required><br>
        <button type="button" class="prev-btn">Previous</button>
        <button type="submit" class="submit-btn">Find Out!</button>
    </div>
    <?php else: ?>
    <div class="quiz-question">
        <button type="submit" class="submit-btn">Find Out!</button>
    </div>
    <?php endif; ?>

    <div class="quiz-question" id="quiz-result-slide">
        <div id="quiz-result"></div>
        <button type="button" class="prev-btn">Previous</button>
    </div>   
</form>
    </div>
    <?php
    return ob_get_clean();
}

add_shortcode('charleston_music_venue_quiz', 'charleston_music_venue_quiz');

function process_quiz_submission($post_data) {
    $venue_scores = [
        'The Royal American' => 0,
        'Music Farm' => 0,
        'The Refinery' => 0,
        'Firefly Distillery' => 0,
        'Charleston Pour House' => 0,
        'The Windjammer' => 0,
        'Tin Roof' => 0,
        'Lo-Fi Brewing' => 0,
        'The Purple Buffalo' => 0,
        'Charleston Music Hall' => 0,
        'The Commodore' => 0,
        'Rebel Taqueria' => 0,
    ];

    $question_mapping = [
        'question1' => [
            'venue1' => ['The Refinery', 'Charleston Music Hall', 'Music Farm'],
            'venue2' => ['The Windjammer', 'Music Farm', 'Charleston Music Hall'],
            'venue3' => ['Charleston Pour House', 'Lo-Fi Brewing', 'The Royal American'],
            'venue4' => ['The Commodore', 'Firefly Distillery', 'The Windjammer', 'The Refinery'],
            'venue5' => ['Rebel Taqueria', 'The Purple Buffalo', 'The Royal American', 'Tin Roof', 'The Commodore'],
            'venue6' => ['The Royal American', 'Tin Roof', 'Charleston Pour House', 'Rebel Taqueria', 'Lo-Fi Brewing']
        ],
        'question2' => [
            'venue1' => ['Tin Roof', 'Lo-Fi Brewing'],
            'venue2' => ['The Commodore', 'The Refinery'],
            'venue3' => ['The Windjammer', 'Music Farm'],
            'venue4' => ['Charleston Pour House', 'The Purple Buffalo'],
            'venue5' => ['The Royal American', 'Charleston Music Hall'],
            'venue6' => ['Firefly Distillery', 'Rebel Taqueria']
        ],
        'question3' => [
            'venue1' => ['The Refinery', 'Charleston Music Hall', 'Charleston Pour House'],
            'venue2' => ['The Windjammer', 'Music Farm', 'Charleston Pour House', 'The Royal American', 'The Commodore'],
            'venue3' => ['The Commodore', 'Firefly Distillery', 'Charleston Music Hall', 'The Refinery'],
            'venue4' => ['Lo-Fi Brewing', 'Charleston Pour House', 'Firefly Distillery'],
            'venue5' => ['The Purple Buffalo', 'Rebel Taqueria', 'Tin Roof'],
            'venue6' => ['The Royal American', 'Tin Roof', 'The Windjammer', 'The Commodore']
        ],
        'question4' => [
            'venue1' => ['The Royal American', 'Tin Roof'],
            'venue2' => ['The Refinery', 'The Windjammer', 'Charleston Music Hall'],
            'venue3' => ['Charleston Pour House', 'Lo-Fi Brewing', 'The Royal American'],
            'venue4' => ['Music Farm', 'The Commodore'],
            'venue5' => ['Firefly Distillery', 'Charleston Music Hall', 'The Windjammer'],
            'venue6' => ['Rebel Taqueria', 'The Purple Buffalo', 'The Windjammer', 'Charleston Pour House', 'Lo-Fi Brewing']
        ],
        'question5' => [
            'venue1' => ['Rebel Taqueria', 'Music Farm', 'The Royal American', 'The Royal American'],
            'venue2' => ['The Royal American', 'Lo-Fi Brewing', 'The Royal American', 'Tin Roof', 'Charleston Pour House'],
            'venue3' => ['Firefly Distillery', 'The Refinery', 'Music Farm'],
            'venue4' => ['Tin Roof', 'The Windjammer', 'Charleston Pour House'],
            'venue5' => ['The Commodore', 'Charleston Music Hall'],
            'venue6' => ['Charleston Pour House', 'The Purple Buffalo', 'Lo-Fi Brewing', 'Firefly Distillery', 'The Windjammer']
        ],
        'question6' => [
            'venue1' => ['The Purple Buffalo', 'Music Farm', 'Charleston Pour House'],
            'venue2' => ['The Commodore', 'Charleston Music Hall', 'The Royal American'],
            'venue3' => ['Firefly Distillery', 'The Windjammer'],
            'venue4' => ['The Royal American', 'Charleston Pour House'],
            'venue5' => ['The Refinery', 'Tin Roof', 'The Royal American', 'The Commodore', 'Music Farm'],
            'venue6' => ['Rebel Taqueria', 'Lo-Fi Brewing', 'Charleston Pour House', 'The Purple Buffalo']
        ],
        'question7' => [
            'venue1' => ['Music Farm', 'Charleston Music Hall', 'The Commodore'],
            'venue2' => ['Lo-Fi Brewing', 'Firefly Distillery', 'Charleston Pour House'],
            'venue3' => ['Tin Roof', 'Rebel Taqueria', 'The Royal American', 'The Purple Buffalo'],
            'venue4' => ['The Refinery', 'The Windjammer'],
            'venue5' => ['Charleston Pour House', 'The Royal American'],
            'venue6' => ['The Purple Buffalo', 'The Commodore']
        ],
        'question8' => [
            'venue1' => ['Tin Roof', 'The Purple Buffalo', 'Charleston Pour House', 'Music Farm'],
            'venue2' => ['Rebel Taqueria', 'The Royal American', 'Music Farm'],
            'venue3' => ['Lo-Fi Brewing', 'Charleston Pour House', 'Lo-Fi Brewing'],
            'venue4' => ['Music Farm', 'The Commodore', 'Charleston Pour House', 'The Royal American', 'Tin Roof'],
            'venue5' => ['Charleston Music Hall', 'The Refinery'],
            'venue6' => ['Firefly Distillery', 'The Windjammer']
        ],
        'question9' => [
            'venue1' => ['Charleston Pour House', 'Music Farm', 'The Royal American'],
            'venue2' => ['The Royal American', 'The Windjammer', 'Charleston Pour House', 'Lo-Fi Brewing', 'Firefly Distillery', 'The Refinery'],
            'venue3' => ['Tin Roof', 'Rebel Taqueria', 'The Royal American'],
            'venue4' => ['The Refinery', 'Charleston Music Hall', 'Charleston Pour House'],
            'venue5' => ['The Purple Buffalo', 'Lo-Fi Brewing'],
            'venue6' => ['Firefly Distillery', 'The Commodore', 'Charleston Pour House', 'Music Farm']
        ],
        'question10' => [
            'venue1' => ['Firefly Distillery', 'Charleston Music Hall', 'The Refinery'],
            'venue2' => ['Music Farm', 'Tin Roof'],
            'venue3' => ['The Royal American', 'The Refinery'],
            'venue4' => ['The Windjammer', 'Charleston Pour House', 'The Royal American'],
            'venue5' => ['Lo-Fi Brewing', 'Rebel Taqueria', 'Charleston Pour House', 'Tin Roof', 'The Royal American', 'The Purple Buffalo'],
            'venue6' => ['The Purple Buffalo', 'The Commodore', 'The Royal American']
        ]
    ];

    $negative_mapping = [
        'question1' => [
        //    'venue1' => [''],
            'venue2' => ['Rebel Taqueria'],
            'venue3' => ['Tin Roof', 'The Commodore'],
            'venue4' => ['The Purple Buffalo'],
            'venue5' => ['Charleston Music Hall'],
            'venue6' => ['Firefly Distillery']
        ],
        'question2' => [
        //   'venue1' => ['The Refinery'],
         //   'venue2' => ['The Royal American'],
         //   'venue3' => ['The Purple Buffalo'],
        //    'venue4' => ['Lo-Fi Brewing'],
         //   'venue5' => ['Firefly Distillery'],
        //    'venue6' => ['Music Farm']
        ],
        'question3' => [
          //  'venue1' => [''],
            'venue2' => ['Tin Roof', 'The Purple Buffalo', 'Rebel Taqueria'],
            'venue3' => ['The Purple Buffalo'],
            'venue4' => ['The Commodore', 'Music Farm'],
            'venue5' => ['The Refinery', 'Charleston Pour House'],
            'venue6' => ['Charleston Music Hall']
        ],
        'question4' => [
            'venue1' => ['The Refinery', 'Charleston Music Hall', 'The Windjammer'],
            'venue2' => ['Tin Roof', 'The Purple Buffalo', 'Rebel Taqueria'],
            'venue3' => ['Firefly Distillery', 'The Commodore'],
            'venue4' => ['The Windjammer', 'The Purple Buffalo', 'Rebel Taqueria'],
            'venue5' => ['The Purple Buffalo'],
            'venue6' => ['Charleston Music Hall']
        ],
        'question5' => [
            'venue1' => ['The Refinery', 'Charleston Pour House', 'The Windjammer'],
            'venue2' => ['The Windjammer', 'Charleston Music Hall'],
            'venue3' => ['The Royal American', 'Lo-Fi Brewing', 'The Purple Buffalo', 'Rebel Taqueria'],
            'venue4' => ['Charleston Music Hall', 'Firefly Distillery', 'The Refinery', 'The Commodore'],
            'venue5' => ['Charleston Pour House', 'Music Farm', 'The Purple Buffalo', 'The Windjammer', 'Lo-Fi Brewing', 'Tin Roof'],
            'venue6' => ['Music Farm', 'Charleston Music Hall', 'The Commmodore', 'Tin Roof']
        ],
        'question6' => [
          //  'venue1' => [''],
        //    'venue2' => ['The Royal American'],
            'venue3' => ['Music Farm', 'Charleston Music Hall', 'The Commodore'],
         //   'venue4' => ['Lo-Fi Brewing'],
       //     'venue5' => ['Firefly Distillery'],
            'venue6' => ['Charleston Music Hall', 'Firefly Distillery']
        ],
        'question7' => [
            'venue1' => ['Tin Roof', 'The Windjammer', 'Charleston Pour House', 'Rebel Taqueria', 'The Purple Buffalo', 'The Refinery', 'Firefly Distillery', 'Lo-Fi Brewing', 'The Royal American'],
          //  'venue2' => ['The Royal American'],
            'venue3' => ['Music Farm', 'Charleston Music Hall', 'The Commodore'],
            'venue4' => ['The Purple Buffalo', 'Rebel Taqueria', 'Lo-Fi Brewing', 'The Royal American', 'Tin Roof'],
        //    'venue5' => ['Firefly Distillery'],
          'venue6' => ['Charleston Music Hall', 'Music Farm', 'The Windjammer']
        ],
        'question8' => [
            'venue1' => ['The Royal American', 'Charleston Music Hall', 'Lo-Fi Brewing'],
            'venue2' => ['Lo-Fi Brewing'],
            'venue3' => ['The Purple Buffalo', 'The Commodore'],
            'venue4' => ['Lo-Fi Brewing'],
            'venue5' => ['Tin Roof', 'The Purple Buffalo', 'Rebel Taqueria', 'Lo-Fi Brewing', 'The Windjammer'],
            'venue6' => ['The Purple Buffalo', 'Tin Roof', 'The Royal American']
        ],
        'question9' => [
            'venue1' => ['Firefly Distillery', 'Charleston Music Hall'],
            'venue2' => ['Charleston Music Hall'],
            'venue3' => ['Charleston Music Hall', 'The Refinery'],
          //  'venue4' => [''],
            'venue5' => ['Firefly Distillery', 'Charleston Music Hall', 'The Commmodore'],
            'venue6' => ['The Purple Buffalo', 'Tin Roof', 'Charleston Music Hall']
        ],
        'question10' => [
            'venue1' => ['The Royal American', 'Tin Roof', 'The Purple Buffalo', 'Rebel Taqueria', 'The Commodore', 'Charleston Pour House'],
            'venue2' => ['The Purple Buffalo', 'Rebel Taqueria', 'Charleston Pour House', 'The Windjammer'],
            'venue3' => ['The Purple Buffalo', 'Charleston Pour House', 'The Windjammer'],
            'venue4' => [''],
            'venue5' => ['Firefly Distillery', 'Charleston Music Hall'],
          //  'venue6' => ['Music Farm']
        ]
    ];

    foreach ($post_data as $question => $answer) {
        if (array_key_exists($question, $question_mapping)) {
            foreach ($question_mapping[$question][$answer] as $venue) {
                $venue_scores[$venue]++;
            }
        }

        if (array_key_exists($question, $negative_mapping) && array_key_exists($answer, $negative_mapping[$question])) {
            foreach ($negative_mapping[$question][$answer] as $venue) {
                $venue_scores[$venue]--;
            }
        }
    }

    arsort($venue_scores);
    $top_venue = key($venue_scores);

    return $top_venue;
}

function prepare_quiz_result($top_venue) {
    $venue_details = [
        'The Royal American' => [
            'image' => 'path/to/royal_american.jpg',
            'description' => 'The Royal American is a great place to enjoy live music with a casual vibe.',
        ],
        'Music Farm' => [
            'image' => 'path/to/music_farm.jpg',
            'description' => 'Music Farm is a popular venue for big name artists and a vibrant crowd.',
        ],
        'The Refinery' => [
            'image' => 'path/to/the_refinery.jpg',
            'description' => 'The Refinery is known for its industrial chic atmosphere and eclectic performances.',
        ],
        'Firefly Distillery' => [
            'image' => 'path/to/firefly_distillery.jpg',
            'description' => 'Firefly Distillery offers a unique experience with its blend of distillery tours and live music.',
        ],
        'Charleston Pour House' => [
            'image' => 'path/to/charleston_pour_house.jpg',
            'description' => 'Charleston Pour House is a beloved venue with a laid-back atmosphere and a diverse lineup of bands.',
        ],
        'The Windjammer' => [
            'image' => 'path/to/the_windjammer.jpg',
            'description' => 'The Windjammer is a beachfront venue that hosts both local and national acts.',
        ],
        'Tin Roof' => [
            'image' => 'path/to/tin_roof.jpg',
            'description' => 'Tin Roof is a quirky spot known for its casual vibe and live music nights.',
        ],
        'Lo-Fi Brewing' => [
            'image' => 'path/to/lofi_brewing.jpg',
            'description' => 'Lo-Fi Brewing combines great craft beer with an intimate music experience.',
        ],
        'The Purple Buffalo' => [
            'image' => 'path/to/the_purple_buffalo.jpg',
            'description' => 'The Purple Buffalo is a hidden gem with an underground feel and a loyal following.',
        ],
        'Charleston Music Hall' => [
            'image' => 'path/to/charleston_music_hall.jpg',
            'description' => 'Charleston Music Hall is a historic venue that offers a sophisticated setting for live performances.',
        ],
        'The Commodore' => [
            'image' => 'path/to/the_commodore.jpg',
            'description' => 'The Commodore is a stylish venue that features live jazz and funk in a cozy, retro environment.',
        ],
        'Rebel Taqueria' => [
            'image' => 'path/to/rebel_taqueria.jpg',
            'description' => 'Rebel Taqueria serves up delicious tacos and hosts vibrant live music events.',
        ],
    ];

    return $venue_details[$top_venue] ?? null;
}
