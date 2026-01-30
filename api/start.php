<?php
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../models/Game.php';

require_post(['player1','player2']);

$player1 = trim((string)$_POST['player1']);
$player2 = trim((string)$_POST['player2']);

// Ελέγχουμε αν τα ονόματα των παικτών είναι κενά
if ($player1 === '' || $player2 === '') {
    json_out(["error" => "Player names cannot be empty"], 422);
}

$db = (new Database())->getConnection();
$game = new Game($db);

/**
 * Start from a clean base state.
 * If you already have ensure_state_keys() in bootstrap.php, use it.
 */
if (function_exists('ensure_state_keys')) {
    $state = ensure_state_keys([]);
} else {
    $state = [];
    $state['game_over'] = false;
    $state['turn'] = 1;
    $state['round'] = 1;
    $state['deck'] = [];
    $state['table'] = [];
    $state['hands'] = ["1" => [], "2" => []];
    $state['piles'] = ["1" => [], "2" => []];
    $state['xeri'] = ["1" => 0, "2" => 0];
    $state['xeri_jack'] = ["1" => 0, "2" => 0];
    $state['points'] = ["1" => 0, "2" => 0];
    $state['last_capture'] = null;
    $state['moves'] = [];
}

// Δημιουργούμε τα tokens για τους παίκτες
$state['tokens'] = [
    "1" => rand_token(16),
    "2" => rand_token(16)
];

// (Προαιρετικό) Αν τα θες για debugging/local testing, κράτα τα cookies.
// Δεν επηρεάζουν το join flow, που γίνεται με /api/join + token.
setcookie("player1_token", $state['tokens']['1'], time() + (86400 * 30), "/");
setcookie("player2_token", $state['tokens']['2'], time() + (86400 * 30), "/");

// ✅ ΝΕΑ ΛΟΓΙΚΗ: κανένας δεν είναι joined στο start.
// Και οι 2 πρέπει να καλέσουν /api/join με token.
$state['joined'] = ["1" => false, "2" => false];

// Δημιουργούμε το παιχνίδι στην βάση δεδομένων
$game_id = $game->create($player1, $player2, $state);
if (!$game_id) {
    json_out(["error" => "Failed to create game"], 500);
}

// ✅ ΜΗΝ κάνεις auto session εδώ (θα το κάνει το join.php)
// session_set_player((int)$game_id, "1");

// ✅ Flags: στην αρχή δεν μπορεί να γίνει roll/move πριν μπουν και οι 2
if (function_exists('compute_flags')) {
    $flags = compute_flags($state);
} else {
    $flags = [
        "joined_ok"    => false,
        "can_roll"     => false,
        "can_move"     => false,
        "can_end_game" => false
    ];
}

json_out([
    "message"     => "Game started",
    "game_id"     => (int)$game_id,
    "join_tokens" => $state['tokens'],
    "joined"      => $state['joined'],
    "flags"       => $flags,
    "note"        => "Both players must call join once with their token before roll."
], 200);
