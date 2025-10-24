<?php
/**
 * Rapper Name Generator Block initialization
 * AJAX action: extrachill_blocks_rapper_name
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Generate rapper name based on user input and preferences
 */
function extrachill_blocks_generate_rapper_name($input, $style = "", $gender = "", $numberOfWords = 2) {
    $firstWordsMale = array( "MC", "Lil", "King", "Sir", "Big", "Duke", "Roc", "Gangsta", "Teflon", "Doc", "Rap", "Hakim", "Trigga", "G", "Scar", "Monsta", "Beast", "Thug", "Gangster", "Killer", "Havoc", "Rogue", "Jae", "Young", "Bruza", "El", "Los", "General", "Fast", "Grand", "Mr.", "Loco", "Ice", "Bold", "Cool", "Raw", "The", "Rebel", "Mischievous", "Mister", "Mista", "Tiny", "Samurai", "Señor", "Gold", "Mac", "Wiz", "Da", "Prince", "Dr.", "Doctor", "Jah", "Quick", "Tough", "Sensei", "De La", "Homie", "Lyrical", "Viceroy", "Czar", "Earl", "Supreme", "Pharoah", "Sultan", "Kaiser", "Shah", "Count", "Heavy", "Action", "LL", "Kid", "Long", "Short", "Wide", "Skinny", "Boogie", "Swift", "Don", "Biz", "Kool", "Schoolboy", "Boss", "Captain", "Comrade" );

    $firstWordsFemale = array( "Queen", "Diva", "Angel", "MC", "Lil", "Lady", "Bad", "Boss", "Baddie", "Diamond", "Empress", "Goddess", "Majesty","Star", "Phoenix", "Mystic", "Radiant", "Glitter", "Glam", "Sparkle", "Miss", "Empyrean", "Ms.", "Starry", "Baby", "Divine", "Radiant", "Fab", "Shimmer", "Gorgeous", "Glimmer", "Radiant", "Vibrant", "Fierce", "Lush", "Pearl", "Candy", "Pretty", "Cardi", "Hot", "Haze", "Rose", "Las", "Feminine", "Witch", "Pink", "Lavender", "Sassy", "Juuls", "Honey", "Coco", "Chica", "Luxe", "Flygirl", "Missy", "Homegirl", "Daisy", "Violet", "Spicy", "Mama", "Angel" );

    $oldSchoolStreetNames = array("MC", "Beatbox", "Breakdance", "Graffiti", "B", "Rhymes", "Rapper", "Rap", "DJ", "Scratch", "Boogie", "Funk", "Soul", "Jazz", "Hip-Hop", "Breakbeat", "Street", "Run", "Public", "Grandmaster", "Blow", "Sugarhill", "Schooly", "Down", "Fat", "Tribe", "Beastie", "Ol'", "Dirty", "Notorious", "Slick", "Phat", "Dawg", "Dogg", "J", "Cypher", "Sly", "Speedy", "Flex", "Enemy", "L", "G", "T", "P", "D", "Ice", "Big", "Smalls", "Breeze", "Bird", "Vibe", "Jays", "Sneakers", "Baller", "Mac", "Method", "Gambino", "Chip", "Juice", "Quest", "Khalifa", "Cream", "Eazy", "Easy", "Game", "Rock", "Steady", "Chill", "Spliff", "Cali", "Green", "Bling", "Style", "Famous", "Paper", "Large", "Fly", "Real", "Diggity", "Flash", "Flava", "Krush", "Shadowbox", "Crooked", "Smoked", "Bone", "Rapscallion", "Recess", "Illest", "Ill", "Dopest", "Limousine", "Rhyme-Sayer", "Nemesis", "Pac", "Fresh", "Immortal", "Technique", "Game", "Slide", "Disco" );

    $trapStreetNames = array("Glock", "Cash", "Diamond", "Trap", "Plug", "Stack", "Grind", "Hustle", "Druglord", "Kingpin", "Sniper", "Gangsta","Trapstar", "Gangster", "Money", "Hustler", "Guns", "Shooter", "Thug", "Dealer", "Pusher", "Grinder", "Hustler", "Blunts", "Trippy", "Post", "Money", "Gas", "Fire", "Marley", "Kush", "Outlaw", "Heat", "Bullet", "Blaze", "Vandal", "Rugged", "Hood", "Street", "Gang", "Lean", "Sipper", "Smoke", "Stacks", "Bands", "Purple", "Outlaw", "Pills", "Molly", "Perscoset", "Xans", "Trapstar", "Traplord", "Diesel", "Supplier", "Provider", "Paper", "Brick", "Gotti", "Rich", "Drip", "Shakedown", "Hash", "Dabs", "Block", "Ransack", "Pistol-Whip", "Planes", "Dollars", "Bills", "Dollabills", "DollaMenu", "Roach", "Dufflebag", "Bag", "Rubberbands", "Bugati", "Lambo", "Glock", "Grills", "Burglar", "Robber", "Buyer" );

    $grimeStreetNames = array("Grit", "Grime", "Ravage", "Hustle", "Riot", "Rise", "Raw", "Shank", "Savage", "Murk", "Gangsta", "Fury", "Rampage", "Rumble", "Thug", "Strive", "Raze", "Ruff", "Ruffian", "Rugged", "Riotous", "Ruthless", "Slime", "Sludge", "Twisted", "Insane", "Wild", "Assassin", "Dread", "Nefarious", "Vicious", "Evil", "Razor", "Hostile", "Untamed", "Death", "Dead", "Deadly", "Havoc", "Savage", "Fierce", "Killer", "Metal", "Corruption", "Sickness", "Nerve", "Darkness", "Villain", "Scoundrel", "Tyrant", "Vicious", "Depraved", "Mayhem", "Fury", "Fangs", "Tremors", "Soot", "Roller", "Crisis", "Wicked", "Darkness", "Sithlord", "Sith", "Lunatic", "Reckless", "Fiend", "Locust", "Devil", "Slime", "Chaotic", "Demon", "Demonic", "Hazard", "Nightmare", "Casket", "Zombie", "Slaughter", "Rotten", "Butcher", "Madhouse", "Loony", "Asylum", "Unhinged", "Eliminator", "Terminator" );

    $consciousStreetNames = array("Wisdom", "Empowered", "Revolution", "Freedom", "Heritage", "Legacy", "Illuminated", "Elevation", "Mystic", "Knowledge", "Power", "Warlord", "Champion", "Victory", "Glory", "Mighty", "Majesty", "Dominion", "Ruler", "Chief", "Sovereign", "Emperor", "Conqueror", "Pinnacle", "Knight", "Strength", "Master", "Sun", "Peace", "Chill", "Flow", "Healer", "Shaman", "God", "Apex", "Blessed", "Energy", "Blesser", "Energy", "Vibration", "Sol", "Messiah", "Luminous", "Conscious", "Pure", "Royalty", "Royal", "Vibration", "Energy", "Ocean", "Creator", "Grateful", "Serene", "Celestial", "Maker", "Holy", "Illustrator", "Cosmic", "Regulator", "Seer", "Infinite", "Chosen", "Dove", "Prophet", "Prodigy", "Blessed", "Voyager", "Professor", "Teacher", "Educator" );

    $randomStreetNames = array("Salad", "Apple", "Cucumber", "Pie", "Candle", "Ketchup", "Mustard", "Seagull", "Mitosis", "Scoliosis", "Fantabulous", "Ashtray", "Dictionary", "Thesaurus", "Begonia", "Hashtag", "Crust", "Pebble", "Nimbus", "Pineapple", "Tea", "Coffee", "Beans", "Chapstick", "Pencil", "Disco", "Lunch", "Yawn", "Drool", "Extra", "Crunch", "Houseplant", "River", "Broomstick", "Zeppelin", "Skateboard", "Termite" );

    $streetNames = array_merge($oldSchoolStreetNames, $trapStreetNames, $grimeStreetNames, $consciousStreetNames, $randomStreetNames);
    $streetName = $streetNames[array_rand($streetNames)];

    $oldSchoolStreetName = $oldSchoolStreetNames[array_rand($oldSchoolStreetNames)];
    $trapStreetName = $trapStreetNames[array_rand($trapStreetNames)];
    $grimeStreetName = $grimeStreetNames[array_rand($grimeStreetNames)];
    $consciousStreetName = $consciousStreetNames[array_rand($consciousStreetNames)];

    $firstWords = array_merge($firstWordsMale, $firstWordsFemale);
    $firstWordNonBinary = $firstWords[array_rand($firstWords)];
    $otherFirstWord = $firstWords[array_rand($firstWords)];
    $firstWord = '';

    switch ($gender) {
        case 'male':
            $firstWord = $firstWordsMale[array_rand($firstWordsMale)];
            $secondWord = $firstWordsMale[array_rand($firstWordsMale)];
            break;
        case 'female':
            $firstWord = $firstWordsFemale[array_rand($firstWordsFemale)];
            $secondWord = $firstWordsFemale[array_rand($firstWordsFemale)];
            break;
        case 'non-binary':
            $firstWord = $firstWordNonBinary;
            $secondWord = $otherFirstWord;
            break;
        default:
            $firstWord = $firstWordNonBinary;
            break;
    }

    switch ($style) {
        case 'old school':
            if ($numberOfWords == 2) {
                return $firstWord . " " . $oldSchoolStreetName . " " . $input;
            } else if ($numberOfWords == 3) {
                return $firstWord . " " . $oldSchoolStreetName . " " . $secondWord . " " . $input;
            }
            break;
        case 'trap':
            if ($numberOfWords == 2) {
                return $firstWord . " " . $input . " " . $trapStreetName;
            } else if ($numberOfWords == 3) {
                return $firstWord . " " . $input . " the " . $trapStreetName;
            }
            break;
        case 'grime':
            if ($numberOfWords == 2) {
                return $input . " " . $firstWord . " " . $grimeStreetName;
            } else if ($numberOfWords == 3) {
                return $secondWord . " " . $input . " " . " the " . " ". $firstWord . " " . $grimeStreetName;
            }
            break;
        case 'conscious':
            if ($numberOfWords == 2) {
                return $input . " " . $firstWord . " " . $consciousStreetName;
            } else if ($numberOfWords == 3) {
                return $firstWord . " " . $secondWord . " " .  $input . " the " . $consciousStreetName;
            }
            break;
        case 'random':
            if ($numberOfWords == 2) {
                return $firstWord . " " . $input . " " . $streetName;
            } else if ($numberOfWords == 3) {
                return $firstWord . " " . $secondWord . " " .  $input . " the " . $streetName;
            }
            break;
        default:
            if ($numberOfWords == 2) {
                return $firstWord . " " . $input . " " . $streetName;
            } else if ($numberOfWords == 3) {
                return $firstWord . " " . $input . " " . $secondWord . " "  . $streetName;
            }
    }
}

add_action('wp_ajax_extrachill_blocks_rapper_name', 'extrachill_blocks_rapper_name_handler');
add_action('wp_ajax_nopriv_extrachill_blocks_rapper_name', 'extrachill_blocks_rapper_name_handler');

function extrachill_blocks_rapper_name_handler() {
    check_ajax_referer('extrachill_blocks_rapper_nonce', 'nonce');

    $input = sanitize_text_field(wp_unslash($_POST['input']));
    $gender = sanitize_text_field(wp_unslash($_POST['gender']));
    $style = sanitize_text_field(wp_unslash($_POST['style']));
    $numberOfWords = absint($_POST['number_of_words']);

    if (empty($input)) {
        wp_send_json_error(array('message' => 'Please enter your name'));
    }

    $generatedName = extrachill_blocks_generate_rapper_name($input, $style, $gender, $numberOfWords);

    wp_send_json_success(array('name' => $generatedName));
}

add_action('wp_enqueue_scripts', 'extrachill_blocks_rapper_name_localize_script', 20);

function extrachill_blocks_rapper_name_localize_script() {
    if (has_block('extrachill-blocks/rapper-name-generator')) {
        wp_localize_script(
            'extrachill-blocks-rapper-name-generator-view-script',
            'extraChillRapperNameGenerator',
            array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('extrachill_blocks_rapper_nonce')
            )
        );
    }
}
