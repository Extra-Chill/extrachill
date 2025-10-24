<?php
/**
 * Band Name Generator Block initialization
 * AJAX action: extrachill_blocks_band_name
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Generate band name based on user input and preferences
 */
function extrachill_blocks_generate_band_name($input, $genre, $numberOfWords, $addTheStart, $addAndTheMiddle) {
    $rockWords = array("Electric", "Guitar", "Power", "Rockin'", "Riff", "Thunder", "Metal", "Sonic", "Blues", "Soul", "Live", "Wire", "Peppers", "Fighters", "Pumpkins", "Third", "Eye", "Blind", "Melon", "Smashing", "Queens", "Heartbreak", "Low", "Deep", "Little", "Rolling", "Stone", "Age", "Kings", "American", "Wildflower", "Keys", "Black", "Eagle", "Steely", "Garage", "Street", "Pearl", "Peppers", "Super", "Twisted", "Sister", "Zeppelin", "Led", "Halen", "Van", "Lightning", "Who", "Roses", "Guns", "Cream", "Experience", "Doors", "Rocket", "Dark", "Axe", "Mother", "Steel", "Heartbreak", "Twin", "Cherry", "Star", "Vinyl", "Kick", "Red", "Radio", "Transistor", "Transmission", "Stereo", "Airwaves", "Breakdown", "Driving", "Jukebox", "Velvet", "Revolver", "New", "New-Age", "Hotel", "Heaven", "Heavenly", "Easy", "Band", "Alive", "Breaking", "Broken", "Speakers", "Busted", "Burst", "Blast", "Blown-Out", "Airplane", "Hits", "Records", "Voltage", "Stone", "Pilots", "Temple", "Operators", "Static", "League", "Revival", "Tangerine", "Dire", "American", "Wolf", "Wings", "Motley", "Angel", "Grand", "Cross", "Thin" );

    $countryWords = array("Bayou", "Tractor", "Mud", "Boots", "Fishing", "Fishermen", "Angler", "Pickup", "Hay", "Rodeo", "Redneck", "Cowboy", "Ranch", "Cattle", "Saddle", "Rodeo", "Deere", "Whitetail", "Lasso", "Yeehaw", "Holler", "Hoot", "Trail", "Ride", "Moonshine", "Whiskey", "Four-Wheel", "Lifted", "Truck", "Wheels", "Corn", "Yonder", "Backwoods", "Alabama", "Virgina", "Georgia", "Southern", "Holler", "South", "Gator", "Turnpike", "Road", "Truckers", "Rodeo", "Ragweed", "Beer", "Bottle", "Carolina", "Hill", "Hillbilly", "Avenue", "Shotgun", "Rifle", "Range", "Prairie", "Swamp", "Rattlers", "Snakeskin", "Texas", "Gentlemen", "Seersucker", "Rollers", "River", "Stream", "Strings", "Boys", "Grit", "Blackberry", "Dusty", "Lady", "Ramblin'", "Ramblers", "Band", "Proud", "Old", "Yonder", "Bo", "Cattle", "Traveler", "Blues", "Smokin'", "Coal", "Mountain", "Wild", "Town", "Ridge", "Oak", "Gritty", "Cardinal", "Magnolia", "Willow", "Cypress", "Cedar", "Thicket", "Thistle", "Sweetgrass", "Situation", "Tobacco", "Fried", "Drifters", "Stomp", "Painters", "Rattle", "Motor", "Oil", "Motel", "Cookout", "Fish-Fry", "Riverboat", "Farmers", "Farm", "Homestead", "Diesel", "Engine", "Motor", "Rusty", "Rowdy", "Barstool", "Country", "Backwoods", "Backcountry", "Tennessee", "Nashville", "Canyon", "Rangers", "Drive-By", "Hollers", "Freedom", "Bobcat", "Hunting", "Tires", "Trailer", "Double-Wide", "Chewing", "Pluckin'", "Roadhouse", "Camp", "Backyard", "Driveway", "Barn", "Mama", "Show", "Crow", "Medicine","Working", "Hard", "Line", "County", "Nowhere", "Gal", "Born", "Crossroads", "Crosstown", "Crosscreek", "Creek", "Steeple", "Gamblers", "Gamblin'", "Lowcountry", "Boiled" );

    $indieWords = array("Dream", "Folk", "Sonic", "Quiet", "Lofi", "Pop", "Haze", "Ghost", "Radiant", "Tree", "City", "Wild", "Morning", "Jacket", "National", "Arctic", "Modest", "Mouse", "House", "My", "Your", "Our", "Twin", "Peaks", "Mountains", "Mouth", "Bag", "Trash", "Sleep", "Sleeping", "Ginger", "Kitchen", "Sink", "Plastic", "Toilet", "Archers", "Radio", "Golden", "Joyful", "Fruit", "Bats", "Blooming", "Bloom", "Shake", "Package", "Mail", "Balloon", "City", "Lost", "Thief", "Big", "Delicate", "Shouting", "Matches", "New", "Band", "Horses", "Lunar", "Vacation", "Real", "Estate", "Market", "Dog", "Babe", "Cry", "Baby", "Walking", "Quiet", "Life", "Basement", "Shiny", "Club", "Pine", "Grove", "Yellow", "Peach", "Pit", "Pear", "Joyous", "Winter", "Forest", "Clement", "Glimpse", "Glare", "Parcel", "Package", "Team", "Artificial", "Fake", "Swimming", "Swimmers", "Sailors", "Climbers", "Sifters", "Cold", "Big", "Small", "Tiny", "Tall", "Pool", "Skyward", "Silver", "Surf", "Season", "Hilly", "Wise", "Fiction", "Honey", "Bubble", "Sneakers", "Shoes", "Basket", "Satchel", "Glow", "Glowing", "Things", "Letters", "Nimble", "Crisp", "Autumn", "Sign", "Seasonal", "Community", "Water", "Copper", "Diameter", "Vineyard", "Vines", "Daisies", "Complex", "Leg", "Wet", "War", "Drugs", "Great", "Taxi", "Delicate", "Forgetful", "Rainbow", "Human", "Bicycle", "Astronomers", "Shiny", "Seldom", "Houses", "Snail", "Mail", "Car", "Built", "Spill", "Seat", "Headrest", "Now", "Orchestra", "Symphony", "Pony", "Dinner", "Lost", "Life", "Suburban", "Bohemian", "Wine", "Peel", "Hippo", "Campus", "Fox", "Tails", "Shampoo", "Art", "Party", "Untitled", "Chairs", "Indoor", "District", "Clean", "Envy", "Coast", "Modern", "Books", "Safety", "Kit", "Silent", "Movies", "Photograph", "Torn", "Numb", "Berries", "Ocean", "Sea", "Blank", "Smog", "Machine", "Machines", "Worn", "Glad", "Sad", "Messenger", "Ferry", "Passenger", "Local", "Natives", "Active", "Salt", "Summer", "Slingshot", "Capsule", "Chaperones", "Vampire", "Weekend" );

    $metalWords = array("Death", "Metal", "Iron", "Black", "Rage", "Night", "Fury", "Chaos", "Demon", "Revenge", "Hate", "Sabbath", "Twisted", "Deranged", "Satanic", "Horror", "Hatred", "Twisted", "Filth", "Sludge", "Oblivion", "Fangs", "Loathe", "Scornful", "Abhorrent", "Resentful", "Abomination", "Ghoul", "Acolyte", "Repugnant", "Mothra", "Gruesome", "Grotesque", "Despicable", "Torture", "Hell", "Madness", "Broth", "Bone", "Marrow", "Legion", "Undead", "Hellish", "Soul", "Coil", "Burning", "Cold", "Evil", "Forgotten", "Shallow", "Shadow", "Dark", "Molten", "Masters", "Masterminds", "Lord", "Horde", "Locust", "Rotten", "Rot", "Mist", "Burns", "Masked", "Mask", "Crown", "Thorns", "Blood", "Brood", "Breed", "Nightmare", "Casket", "Hollow", "Cackle", "Deathly", "Veins", "666", "Satanic", "Onslaught", "Bone", "Seething", "Flaming", "Rat", "Bat", "Winged", "Feeders", "Flesh", "Rotten", "Howl", "Howling", "Barking", "Skulls", "Butcher", "Cleaving" );

    $punkWords = array("Anarchy", "Punk", "Chaos", "Rebel", "Noise", "Disorder", "Riot", "Shout", "Scream", "Anger", "Sour", "Quiet", "Blink", "182", "Anthem", "Venom", "Dirt", "Gaslight", "Lighter", "Spit", "Fire", "Anarchy", "Crush", "Fight", "Hiss", "Fuzz", "Angry", "Wild", "Wailing", "Thrash", "Piss", "Vinegar", "Crank", "Toxic", "Fury", "Pistols", "Clash", "Addicts", "Brains", "Bad", "Stooges", "Misfits", "Outcasts", "Illegal", "Dead", "Spiteful", "Harsh", "Unkind", "Teenage", "Frantic", "Kids", "Brigade", "Social", "Antisocial", "Hybrid", "Mutants", "Aliens", "Reckless", "Underdogs", "Underground", "Sublime", "Savage", "Rapid", "Blaring", "Crude", "Rude", "Cure", "Rehab", "Drunk", "Drunken", "Buzzards", "Lemon", "Push", "Shove", "Punch", "Kick", "Knuckle", "Elbow", "Feet", "Surfers", "Screamers", "Screaming", "Shouting", "Protest", "Anti", "Burners", "Flag", "Distortion", "Buzz", "Public", "Enemy", "Activists", "Third-Rate", "Rabid", "Snarl", "Snarling", "Dumb", "Dogs", "Losers", "Nobodies", "Useless", "Discarded", "Manifesto", "Used", "Pre-Owned", "Bruised", "Bums", "Hitters", "Molotov", "Cocktail", "Maniacs", "Mainline", "Outbreak", "Unlucky", "Cyclone", "State", "Bitter", "Biters", "Confusion", "Asylum" );

    $jamWords = array("Jam", "Funk", "Soul", "Blues", "Groove", "Rhythm", "Swing", "Beat", "Rock", "Dance", "Acid", "Haze", "Fish", "Talking", "Sweet", "Mellow", "Mood", "Vibrations", "Waves", "Sound", "Dead", "Grateful", "Moose", "Caboose", "Train", "Watchtower", "Terrapin", "Station", "Stella", "Blue", "Painted", "Walkin'", "Scarlet", "Mountain", "Band", "Feeling", "Brokedown", "Palace", "Candyman", "High", "Sunflower", "China", "Cat", "Railroad", "Big", "Something", "Shakedown", "Fluff", "Gin", "Brothers", "Wood", "Smooth", "Sweet", "Rain", "Fade", "After", "Midnight", "Time", "Weather", "Report", "Tweezer", "Roses", "Althea", "Chalkdust", "Bathtub", "Feel", "Twist", "Bounce", "Free", "Waste", "Heavy", "Zero", "Stash", "Glide", "Nectar", "Joyful", "Weekapaug", "Makisupa", "Taste", "Greenberg", "Runaway", "Antelope", "Wilson", "Possum", "Reprise", "Melody", "Taste", "Tasty", "Funky", "Jerry", "Garcia", "Company", "Friend", "Talkin'", "Away", "Not", "Begonias", "Street", "Grass", "Bluegrass", "Green", "California", "Moon", "Moonlight", "Riders", "Purple", "Sage", "New", "Hippie", "Cosmic", "Collective", "Experience", "Petunias", "Pancakes", "Bouncing", "Patrol", "Stroll", "Street", "Dancing", "Bears",  "Heady", "Dew", "Toasted", "Old", "Peggy", "Lovin'", "Sky", "Dangle", "Jangle", "Whistle", "Remedy", "Flower", "Marijuana", "Cannabis", "Heads", "Fluid", "Flying", "High", "Endless", "Session", "Floating", "Owsley's", "Steal", "Stealing", "Melt", "Melting", "Marching", "Recipe", "Ingredient", "Truckin'", "Loose", "Easy", "Foolish", "Butter", "Ship", "Melodic", "Harmonic", "Chromatic", "Lump", "Walrus", "Face", "Lucy", "Smiling", "Grinning", "Smile", "Grin", "Sauce", "Balloons", "Simple", "Reba", "Circus", "Antelope", "Jar", "Deal", "Sugar", "Boogie", "Flamingo", "Leftover", "Sunshine", "Daydream", "Bertha", "Spoonful", "Platypus", "Whales", "Chillers", "Shake", "Shakers", "Bus", "Blubber", "Cartoon" );

    $electronicWords = array("House", "Beat", "Lights", "Nectar", "Bass", "Pretty", "Electric", "Deep", "Dance", "Rave","Synth", "Drum", "DJ", "Techno", "Trance", "Ambient", "Glitch", "Mob", "Electro", "Disco", "Funk", "Doctor", "Dirty", "Daft", "Punk", "Rain", "Frenzy", "Soundsystem", "Gees", "Bees", "Fools", "Miracle", "Dreams", "Visions", "Mystic", "Eternal", "Inifinite", "Mystery", "Echoes", "Cheddar", "Neon", "Galactic", "Interstellar", "Orbit", "Orbital", "Spellbound", "Ultra", "Living", "Down", "Upward", "Blissful", "Bliss", "Eternity", "Futuristic", "Future", "Splash", "Gems", "Crystal", "Moon", "Sun", "Stars", "Space", "Exotic", "Chrome", "Jupiter", "Pluto", "Mars", "Castles", "Gang", "Drops", "Mafia", "Fever", "Mind", "Bending", "Universal", "Liquid", "Strange", "Stranger", "Terrestrial", "Lucid", "Whirlwind", "Martians", "Earthlings", "Rolling", "Planet", "Planetary", "Meteor", "Astroid", "Magic", "Magical", "Ethereal", "Voyage", "Voyagers", "Carnival", "Paradise", "Utopia", "Mindful" );

    $randomWords = array("Pineapple", "Raspberry", "Banana", "Bubblegum", "Strawberry", "Pickle", "Cucumber", "Tomato", "Bacon", "Extra", "Chill", "Fantabulous", "Splendid", "Superb", "Brilliant", "Serious", "Drooling", "July", "June", "August", "October", "September", "November", "April", "Termites" );

    switch ($genre) {
        case "country":
            $wordArray = $countryWords;
            break;
        case "rock":
            $wordArray = $rockWords;
            break;
        case "metal":
            $wordArray = $metalWords;
            break;
        case "indie":
            $wordArray = $indieWords;
            break;
        case "punk":
            $wordArray = $punkWords;
            break;
        case "jam":
            $wordArray = $jamWords;
            break;
        case "electronic":
            $wordArray = $electronicWords;
            break;
        case "random":
            $randomArray = array_merge($rockWords, $countryWords, $metalWords, $indieWords, $punkWords, $jamWords, $electronicWords, $randomWords);
            $wordArray = $randomArray;
            break;
        default:
            $wordArray = $rockWords;
            break;
    }

    $bandName = "";
    for ($i = 0; $i < $numberOfWords; $i++) {
        $randIndex = array_rand($wordArray);
        $bandName .= $wordArray[$randIndex] . " ";
    }
    $bandName = trim($bandName);

    if ($numberOfWords == 4 && $addAndTheMiddle) {
        $tempArray = explode(" ", $bandName);
        $tempArray[1] .= " & The";
        $bandName = implode(" ", $tempArray);
        if ($addTheStart) {
            $bandName = "The " . $bandName;
        }
    } else {
        if ($addTheStart && !$addAndTheMiddle) {
            $bandName = "The " . $bandName;
        } else if (!$addTheStart && $addAndTheMiddle) {
            $bandName = preg_replace('/ /', ' & The ', $bandName, 1);
        } else if ($addTheStart && $addAndTheMiddle) {
            $bandName = "The " . preg_replace('/ /', ' & The ', $bandName, 1);
        }
    }

    $bandName = $input . " " . $bandName;

    return $bandName;
}

add_action('wp_ajax_extrachill_blocks_band_name', 'extrachill_blocks_band_name_handler');
add_action('wp_ajax_nopriv_extrachill_blocks_band_name', 'extrachill_blocks_band_name_handler');

function extrachill_blocks_band_name_handler() {
    check_ajax_referer('extrachill_blocks_band_nonce', 'nonce');

    $input = sanitize_text_field(wp_unslash($_POST['input']));
    $genre = sanitize_text_field(wp_unslash($_POST['genre']));
    $numberOfWords = absint($_POST['number_of_words']);
    $addTheStart = isset($_POST['first_the']) && $_POST['first_the'] === 'true';
    $addAndTheMiddle = isset($_POST['and_the']) && $_POST['and_the'] === 'true';

    if (empty($input)) {
        wp_send_json_error(array('message' => 'Please enter your name or word'));
    }

    $generatedName = extrachill_blocks_generate_band_name($input, $genre, $numberOfWords, $addTheStart, $addAndTheMiddle);

    wp_send_json_success(array('name' => $generatedName));
}

add_action('wp_enqueue_scripts', 'extrachill_blocks_band_name_localize_script', 20);

function extrachill_blocks_band_name_localize_script() {
    if (has_block('extrachill-blocks/band-name-generator')) {
        wp_localize_script(
            'extrachill-blocks-band-name-generator-view-script',
            'extraChillBandNameGenerator',
            array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('extrachill_blocks_band_nonce')
            )
        );
    }
}
