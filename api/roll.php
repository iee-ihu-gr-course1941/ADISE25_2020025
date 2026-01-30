<?php
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../models/Game.php';

require_post(['game_id']);
$game_id = (int)$_POST['game_id'];

$db = (new Database())->getConnection();
$game = new Game($db);

if (!$game->load($game_id)) {
    json_out(["error" => "Game not found"], 404);
}

$state = json_decode($game->state_json, true);
if (!is_array($state)) {
    json_out(["error" => "Invalid game state JSON"], 500);
}

$state = ensure_state_keys($state);

// Session auth
$player = (string)session_get_player($game_id); // "1" or "2"
if ($player !== "1" && $player !== "2") {
    json_out(["error" => "Not joined (no session)"], 401);
}

// game_over check
if (!empty($state['game_over'])) {
    json_out([
        "error"       => "Game is over",
        "winner"      => $state['winner'] ?? 0,
        "final_score" => $state['final_score'] ?? ["1"=>0,"2"=>0],
        "flags"       => compute_flags($state, $player)
    ], 409);
}

// Both players must have joined
if (!both_players_joined($state)) {
    json_out([
        "error"  => "Both players must join before roll",
        "joined" => $state['joined'],
        "flags"  => compute_flags($state, $player)
    ], 409);
}

// Do not deal again if round already in progress (hands not empty)
if (!empty($state['hands']['1']) || !empty($state['hands']['2'])) {
    json_out([
        "error" => "Cannot deal cards: round already in progress",
        "flags" => compute_flags($state, $player)
    ], 409);
}

/**
 * INITIALIZE GAME (only once)
 * - started: ξεκίνησε το παιχνίδι (έχουν γίνει οι αρχικές ρυθμίσεις)
 * - deck_initialized: έχει δημιουργηθεί/ανακατευτεί τράπουλα + έχουν μπει 4 στο τραπέζι
 */
$isFirstStart = empty($state['started']);

if ($isFirstStart) {
    $state['started'] = true;

    // build + shuffle + 4 to table
    $state['deck'] = build_deck();
    shuffle($state['deck']);

    $state['table'] = [];
    for ($i = 0; $i < 4; $i++) {
        // αν για κάποιο λόγο δεν υπάρχουν αρκετές κάρτες (δεν θα συμβεί σε πλήρη deck)
        if (empty($state['deck'])) break;
        $state['table'][] = array_pop($state['deck']);
    }

    $state['deck_initialized'] = true;

    // αρχικός γύρος
    $state['round'] = 1;

    // Player 1 starts by default
    $state['turn'] = 1;
} else {
    /**
     * NEW ROUND
     * Start player = last_capture if valid, otherwise keep current turn as is.
     * + Αύξηση round
     */
    $state['round'] = (int)($state['round'] ?? 1) + 1;

    if ($state['last_capture'] === 1 || $state['last_capture'] === "1") {
        $state['turn'] = 1;
    } elseif ($state['last_capture'] === 2 || $state['last_capture'] === "2") {
        $state['turn'] = 2;
    } else {
        // αν δεν υπάρχει last_capture, κράτα το υπάρχον ή γύρνα στο 1
        $state['turn'] = (int)($state['turn'] ?? 1);
        if ($state['turn'] !== 1 && $state['turn'] !== 2) $state['turn'] = 1;
    }
}

// roll only by current turn player (ΜΕΤΑ το init/new round που μπορεί να αλλάξει turn)
if ((string)$state['turn'] !== (string)$player) {
    json_out([
        "error" => "Not your turn",
        "turn"  => (int)$state['turn'],
        "you"   => (int)$player,
        "flags" => compute_flags($state, $player)
    ], 403);
}

// Compute how many we can deal (6 each or less)
$dealCount    = 6;
$maxPerPlayer = intdiv(count($state['deck']), 2);
$dealNow      = min($dealCount, $maxPerPlayer);

if ($dealNow <= 0) {
    json_out([
        "error"        => "No more cards to deal",
        "deck_count"   => count($state['deck']),
        "can_end_game" => is_game_finished($state),
        "flags"        => compute_flags($state, $player)
    ], 409);
}

// Deal
$state['hands']['1'] = [];
$state['hands']['2'] = [];

for ($i = 0; $i < $dealNow; $i++) {
    $state['hands']['1'][] = array_pop($state['deck']);
    $state['hands']['2'][] = array_pop($state['deck']);
}

// Update points snapshot (αν το χρησιμοποιείς live)
$state['points'] = compute_live_points($state);

// Save
if (!$game->saveState($state)) {
    json_out(["error" => "Failed to save state"], 500);
}

// Hide moves from response
$publicState = $state;
unset($publicState['moves']);

json_out([
    "message"    => $isFirstStart ? "Game initialized & cards dealt" : "Cards dealt",
    "game_id"    => $game_id,
    "round"      => (int)$state['round'],
    "turn"       => (int)$state['turn'],
    "dealt_each" => $dealNow,
    "deck_count" => count($state['deck']),
    "points"     => $state['points'],
    "flags"      => compute_flags($state, $player),
    "state"      => $publicState
], 200);
