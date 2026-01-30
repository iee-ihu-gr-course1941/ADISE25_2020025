<?php
header("Content-Type: application/json");

require_once "../core/Database.php";
require_once "../models/Game.php";

// Έλεγχος για game_id στο GET
if (!isset($_GET['game_id'])) {
    echo json_encode(["error" => "Missing game_id"]);
    exit;
}

$game_id = (int) $_GET['game_id'];

// Σύνδεση με τη βάση
$database = new Database();
$db = $database->getConnection();

$game = new Game($db);

// Φόρτωση παιχνιδιού
if (!$game->load($game_id)) {
    echo json_encode(["error" => "Game not found"]);
    exit;
}

// Διάβασμα state
$state = json_decode($game->state_json, true);
if (!is_array($state)) {
    echo json_encode(["error" => "Invalid state_json"]);
    exit;
}

// ------------------------------------------------------
// 1. (Προαιρετικός αλλά σωστός έλεγχος) 
//    Μην υπολογίζεις σκορ αν το παιχνίδι δεν έχει τελειώσει
//    Δηλ. πρέπει: deck, table, hands να είναι άδεια
// ------------------------------------------------------
$game_not_finished =
    !empty($state['deck']) ||
    !empty($state['table']) ||
    !empty($state['hands']['1']) ||
    !empty($state['hands']['2']);

if ($game_not_finished) {
    echo json_encode([
        "error"   => "Game not finished yet",
        "message" => "Deck, table or hands are not empty",
        "state"   => $state
    ], JSON_PRETTY_PRINT);
    exit;
}

// ------------------------------------------------------
// 2. Υπολογισμός πόντων
// ------------------------------------------------------

$piles1 = isset($state['piles']['1']) ? $state['piles']['1'] : [];
$piles2 = isset($state['piles']['2']) ? $state['piles']['2'] : [];

$points = [
    "1" => 0,
    "2" => 0,
];

// 2.1. 3 πόντοι σε όποιον έχει τα περισσότερα χαρτιά
$total1 = count($piles1);
$total2 = count($piles2);

if ($total1 > $total2) {
    $points["1"] += 3;
} elseif ($total2 > $total1) {
    $points["2"] += 3;
}
// Αν είναι ίσα, κανείς δεν παίρνει τους 3 πόντους

// Βοηθητική συνάρτηση: ελέγχει αν μια κάρτα υπάρχει σε μια στοίβα
$hasCard = function (array $pile, string $card): bool {
    return in_array($card, $pile, true);
};

// 2.2. 1 πόντος για 2♠ (2S)
if ($hasCard($piles1, "2S")) {
    $points["1"] += 1;
}
if ($hasCard($piles2, "2S")) {
    $points["2"] += 1;
}

// 2.3. 1 πόντος για 10♦ (10D)
if ($hasCard($piles1, "10D")) {
    $points["1"] += 1;
}
if ($hasCard($piles2, "10D")) {
    $points["2"] += 1;
}

// 2.4. 1 πόντος για κάθε Ρήγα, Ντάμα, Βαλέ, 10 (εκτός από το 10♦)
// Κωδικοποίηση: "KD", "QH", "JC", "10S" κλπ.
$faceRanks = ["K", "Q", "J", "10"];

$scoreFaces = function (array $pile) use ($faceRanks): int {
    $sum = 0;
    foreach ($pile as $card) {
        // rank = όλα εκτός από το τελευταίο char (το suit)
        $rank = substr($card, 0, -1);
        $suit = substr($card, -1);

        if (in_array($rank, $faceRanks, true)) {
            // Εξαίρεση: το 10♦ (10D) δεν μετράει εδώ, έχει ήδη δικό του πόντο
            if (!($rank === "10" && $suit === "D")) {
                $sum += 1;
            }
        }
    }
    return $sum;
};

$points["1"] += $scoreFaces($piles1);
$points["2"] += $scoreFaces($piles2);

// 2.5. Ξερές
$xeri1 = isset($state['xeri']['1']) ? (int)$state['xeri']['1'] : 0;
$xeri2 = isset($state['xeri']['2']) ? (int)$state['xeri']['2'] : 0;

$points["1"] += $xeri1 * 10;
$points["2"] += $xeri2 * 10;

// 2.6. Ξερή με Βαλέ (xeri_jack) – 20 πόντοι η καθεμία
$xeriJack1 = isset($state['xeri_jack']['1']) ? (int)$state['xeri_jack']['1'] : 0;
$xeriJack2 = isset($state['xeri_jack']['2']) ? (int)$state['xeri_jack']['2'] : 0;

$points["1"] += $xeriJack1 * 20;
$points["2"] += $xeriJack2 * 20;

// ------------------------------------------------------
// 3. Αποθήκευση αποτελέσματος στο state και στη βάση
// ------------------------------------------------------
$state['points'] = $points;

$game->state_json = json_encode($state);
$game->saveState();

// ------------------------------------------------------
// 4. Απάντηση στον client
// ------------------------------------------------------
echo json_encode([
    "message" => "Final score calculated",
    "game_id" => $game->id,
    "points"  => $points,
    "state"   => $state
], JSON_PRETTY_PRINT);
