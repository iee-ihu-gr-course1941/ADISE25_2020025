<?php
header("Content-Type: application/json");

require_once "../core/Database.php";
require_once "../models/Game.php";

// Έλεγχος αν υπάρχει game_id στη μέθοδο POST
if (!isset($_POST['game_id'])) {
    echo json_encode(["error" => "Missing game_id"]);
    exit;
}

$game_id = (int) $_POST['game_id'];

// Δημιουργία σύνδεσης με τη βάση
$database = new Database();
$db = $database->getConnection();

$game = new Game($db);

// Φόρτωση παιχνιδιού από τη βάση
if (!$game->load($game_id)) {
    echo json_encode(["error" => "Game not found"]);
    exit;
}

// Φόρτωση του τρέχοντος state του παιχνιδιού
$state = json_decode($game->state_json, true);
if (!is_array($state)) {
    echo json_encode(["error" => "Invalid game state JSON"]);
    exit;
}

// Απλή προστασία: δεν μοιράζουμε νέα φύλλα αν ο γύρος είναι ήδη σε εξέλιξη
if (!empty($state['deck']) ||
    !empty($state['hands']['1']) ||
    !empty($state['hands']['2'])) {

    echo json_encode([
        "error" => "Cannot deal cards: round already in progress",
        "state" => $state
    ], JSON_PRETTY_PRINT);
    exit;
}

/**
 * Δημιουργία πλήρους τράπουλας 52 φύλλων
 * Αναπαράσταση φύλλου: RANK + SUIT (π.χ. "AH" = Άσσος κούπα)
 */
function build_deck(): array
{
    // Σειρά δεν έχει σημασία – θα γίνει shuffle
    $ranks = ['A', 'K', 'Q', 'J', '10', '9', '8', '7', '6', '5', '4', '3', '2'];
    $suits = ['H', 'D', 'C', 'S']; // Hearts, Diamonds, Clubs, Spades

    $deck = [];
    foreach ($suits as $s) {
        foreach ($ranks as $r) {
            $deck[] = $r . $s;
        }
    }
    return $deck;
}

// 1) Δημιουργία & ανακάτεμα τράπουλας
$deck = build_deck();
shuffle($deck);

// 2) 4 φύλλα στο τραπέζι (όπως στις οδηγίες της Ξερής)
$table = [];
for ($i = 0; $i < 4; $i++) {
    $table[] = array_pop($deck);
}

// 3) 6 φύλλα σε κάθε παίκτη
$hands = [
    "1" => [],
    "2" => []
];

for ($i = 0; $i < 6; $i++) {
    $hands["1"][] = array_pop($deck);
    $hands["2"][] = array_pop($deck);
}

// 4) Ενημέρωση state
$state['deck']  = $deck;     // ό,τι απομένει στην τράπουλα
$state['table'] = $table;    // 4 φύλλα στο τραπέζι
$state['hands']['1'] = $hands['1'];
$state['hands']['2'] = $hands['2'];

// (προαιρετικά) βεβαιωνόμαστε ότι οι υπόλοιπες δομές υπάρχουν
$state['piles']   = $state['piles']   ?? ["1" => [], "2" => []];
$state['points']  = $state['points']  ?? ["1" => 0, "2" => 0];
$state['xeri']    = $state['xeri']    ?? ["1" => 0, "2" => 0];
$state['turn']    = $state['turn']    ?? 1;
$state['round']   = $state['round']   ?? 1;
$state['last_capture'] = $state['last_capture'] ?? null;

// 5) Αποθήκευση του ενημερωμένου state στη βάση
$game->state_json = json_encode($state);
if (!$game->saveState()) {
    echo json_encode(["error" => "Failed to save game state"]);
    exit;
}

// 6) Επιστροφή αποτελέσματος
echo json_encode([
    "message" => "Cards dealt",
    "game_id" => $game->id,
    "state"   => $state
], JSON_PRETTY_PRINT);
