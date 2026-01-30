<?php
header("Content-Type: application/json");

require_once "../core/Database.php";
require_once "../models/Game.php";

// Έλεγχος παραμέτρων
if (!isset($_POST['game_id'], $_POST['player'], $_POST['card'])) {
    echo json_encode(["error" => "Missing parameters (game_id, player, card)"]);
    exit;
}

$game_id = (int) $_POST['game_id'];
$player  = (string) $_POST['player'];   // "1" ή "2"
$card    = strtoupper(trim($_POST['card'])); // π.χ. "4H", "JD"

// Δημιουργία σύνδεσης με τη βάση
$database = new Database();
$db = $database->getConnection();

$game = new Game($db);

// Φόρτωση παιχνιδιού
if (!$game->load($game_id)) {
    echo json_encode(["error" => "Game not found"]);
    exit;
}

// Φόρτωση state
$state = json_decode($game->state_json, true);
if (!is_array($state)) {
    echo json_encode(["error" => "Invalid game state"]);
    exit;
}

// Default τιμές για xeri και xeri_jack, αν λείπουν από το state
if (!isset($state['xeri']) || !is_array($state['xeri'])) {
    $state['xeri'] = ["1" => 0, "2" => 0];
}

if (!isset($state['xeri_jack']) || !is_array($state['xeri_jack'])) {
    $state['xeri_jack'] = ["1" => 0, "2" => 0];
}


// --- Βασικοί έλεγχοι ---

// Έλεγχος player
if ($player !== "1" && $player !== "2") {
    echo json_encode(["error" => "Invalid player (must be '1' or '2')"]);
    exit;
}

// Έλεγχος σειράς
if ((string)$state['turn'] !== $player) {
    echo json_encode([
        "error"      => "Not your turn",
        "currentTurn"=> $state['turn']
    ]);
    exit;
}

// Έλεγχος ότι το φύλλο υπάρχει στο χέρι του παίκτη
if (!isset($state['hands'][$player]) || !is_array($state['hands'][$player])) {
    echo json_encode(["error" => "Player hand not found"]);
    exit;
}

$hand =& $state['hands'][$player]; // reference
$cardIndex = array_search($card, $hand, true);
if ($cardIndex === false) {
    echo json_encode([
        "error" => "Card not found in player's hand",
        "card"  => $card,
        "hand"  => $hand
    ]);
    exit;
}

// Αφαίρεση του φύλλου από το χέρι
unset($hand[$cardIndex]);
$hand = array_values($hand); // reindex

// Βοηθητική: rank κάρτας (τα πάντα εκτός από το τελευταίο char που είναι το suit)
function xeri_get_rank(string $c): string {
    // rank = όλα τα chars εκτός από το τελευταίο (π.χ. "10D" -> "10", "JS" -> "J")
    return substr($c, 0, -1);
}

// Πίνακας τραπεζιού
$table =& $state['table'];
if (!is_array($table)) {
    $table = [];
}

$capture     = false;
$isXeri      = false;
$isXeriJack  = false;
$captured    = [];

// --- Λογική κίνησης ---

if (count($table) === 0) {
    // Άδειο τραπέζι -> απλά αφήνουμε το φύλλο
    $table[] = $card;
} else {
    // Υπάρχουν φύλλα στο τραπέζι
    $topCard  = end($table);               // πάνω φύλλο
    $topRank  = xeri_get_rank($topCard);
    $cardRank = xeri_get_rank($card);

    // Jack (Βαλέ) μαζεύει πάντα όλο το τραπέζι
    if ($cardRank === "J") {
        $capture = true;
        if (count($table) === 1) {
            // Ξερή με Βαλέ
            $isXeriJack = true;
        }
    }
    // Ίδια αξία με το πάνω φύλλο
    elseif ($cardRank === $topRank) {
        $capture = true;
        if (count($table) === 1) {
            // Ξερή (όχι Βαλέ)
            $isXeri = true;
        }
    }

    if ($capture) {
        // Μαζεύουμε όλα τα φύλλα του τραπεζιού + το φύλλο που παίξαμε
        $captured = $table;
        $captured[] = $card;

        if (!isset($state['piles'][$player]) || !is_array($state['piles'][$player])) {
            $state['piles'][$player] = [];
        }

        $state['piles'][$player] = array_merge($state['piles'][$player], $captured);

        // Καθαρίζουμε το τραπέζι
        $table = [];

        // Καταγραφή τελευταίου παίκτη που μάζεψε
        $state['last_capture'] = (int)$player;

        // Καταγραφή Ξερής
        if (!isset($state['xeri'][$player])) {
            $state['xeri'][$player] = 0;
        }
        if (!isset($state['xeri_jack'][$player])) {
            $state['xeri_jack'][$player] = 0;
        }

        if ($isXeriJack) {
            $state['xeri_jack'][$player] += 1;
        } elseif ($isXeri) {
            $state['xeri'][$player] += 1;
        }
    } else {
        // Δεν μαζεύει -> απλά αφήνουμε το φύλλο πάνω στο τραπέζι
        $table[] = $card;
    }
}

// --- Εναλλαγή σειράς παίκτη ---
$state['turn'] = ($player === "1") ? 2 : 1;

// Αποθήκευση του state στη βάση
$game->state_json = json_encode($state);
$game->saveState();

// Απάντηση στον client
echo json_encode([
    "message"      => "Move played",
    "game_id"      => $game->id,
    "player"       => (int)$player,
    "played_card"  => $card,
    "capture"      => $capture,
    "xeri"         => $state['xeri'],
    "xeri_jack"    => $state['xeri_jack'],
    "state"        => $state
], JSON_PRETTY_PRINT);
