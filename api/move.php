<?php
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../models/Game.php';

require_post(['game_id','card']);

$game_id = (int)$_POST['game_id'];
$cardRaw = trim((string)$_POST['card']);
$card = strtoupper($cardRaw);

// Keep same helper names, but guard redeclare
if (!function_exists('xeri_get_rank')) {
    function xeri_get_rank(string $c): string {
        return substr($c, 0, -1);
    }
}

if (!function_exists('xeri_is_valid_card')) {
    function xeri_is_valid_card(string $c): bool {
        return (bool)preg_match('/^(A|K|Q|J|10|[2-9])(S|H|D|C)$/', $c);
    }
}

if (!xeri_is_valid_card($card)) {
    json_out([
        "ok" => false,
        "error" => "Invalid card format",
        "card" => $cardRaw
    ], 422);
}

$db = (new Database())->getConnection();
$game = new Game($db);

if (!$game->load($game_id)) {
    json_out(["ok" => false, "error" => "Game not found"], 404);
}

$state = json_decode($game->state_json, true);
if (!is_array($state)) {
    json_out(["ok" => false, "error" => "Invalid game state JSON"], 500);
}

$state = ensure_state_keys($state);

// session player ("1" or "2")
$player = (string)session_get_player($game_id);
if ($player !== "1" && $player !== "2") {
    json_out([
        "ok" => false,
        "error" => "Not joined (no session)",
        "flags" => compute_flags($state, $player),
        "state_view" => make_state_view($state, $player)
    ], 401);
}

// --------------------
// Guards / Errors
// --------------------

if (!empty($state['game_over'])) {
    json_out([
        "ok" => false,
        "error" => "Game is over",
        "winner" => $state['winner'] ?? 0,
        "final_score" => $state['final_score'] ?? ["1"=>0,"2"=>0],
        "flags" => compute_flags($state, $player),
        "state_view" => make_state_view($state, $player)
    ], 409);
}

if (empty($state['joined']['1']) || empty($state['joined']['2'])) {
    json_out([
        "ok" => false,
        "error" => "Both players must join before move",
        "joined" => $state['joined'],
        "flags" => compute_flags($state, $player),
        "state_view" => make_state_view($state, $player)
    ], 409);
}

if ((string)$state['turn'] !== (string)$player) {
    json_out([
        "ok" => false,
        "error" => "Not your turn",
        "currentTurn" => (int)$state['turn'],
        "you" => (int)$player,
        "flags" => compute_flags($state, $player),
        "state_view" => make_state_view($state, $player)
    ], 403);
}

if (!isset($state['hands'][$player]) || !is_array($state['hands'][$player])) {
    json_out([
        "ok" => false,
        "error" => "Player hand not found",
        "flags" => compute_flags($state, $player),
        "state_view" => make_state_view($state, $player)
    ], 500);
}

$hand =& $state['hands'][$player];

// Αν ο παίκτης δεν έχει κάρτες, δεν επιτρέπεται κίνηση (πρέπει να γίνει roll όταν αδειάσουν και οι 2)
if (count($hand) === 0) {
    json_out([
        "ok" => false,
        "error" => "No cards in hand",
        "flags" => compute_flags($state, $player),
        "state_view" => make_state_view($state, $player)
    ], 409);
}

$cardIndex = array_search($card, $hand, true);
if ($cardIndex === false) {
    json_out([
        "ok" => false,
        "error" => "Card not found in player's hand",
        "card" => $card,
        "flags" => compute_flags($state, $player),
        "state_view" => make_state_view($state, $player)
    ], 409);
}

// --------------------
// Apply Move
// --------------------

// remove from hand
unset($hand[$cardIndex]);
$hand = array_values($hand);

$table =& $state['table'];
if (!is_array($table)) $table = [];

$capture = false;
$isXeri = false;
$isXeriJack = false;

if (count($table) === 0) {
    // αν το τραπέζι είναι άδειο, απλά ρίχνει κάρτα
    $table[] = $card;
} else {
    $topCard = end($table);
    $topRank = xeri_get_rank($topCard);
    $cardRank = xeri_get_rank($card);

    // Κανόνας: Βαλέ (J) μαζεύει πάντα
    if ($cardRank === "J") {
        $capture = true;
        if (count($table) === 1) $isXeriJack = true;
    } elseif ($cardRank === $topRank) {
        // Ίδιος αριθμός/φιγούρα με το πάνω φύλλο -> μάζεμα
        $capture = true;
        if (count($table) === 1) $isXeri = true;
    }

    if ($capture) {
        $captured = $table;
        $captured[] = $card;

        $state['piles'][$player] = $state['piles'][$player] ?? [];
        $state['piles'][$player] = array_merge($state['piles'][$player], $captured);

        // άδειασμα τραπεζιού
        $table = [];

        // τελευταίος που μάζεψε
        $state['last_capture'] = (int)$player;

        // καταγραφή Ξερής
        if ($isXeriJack) {
            $state['xeri_jack'][$player] = ($state['xeri_jack'][$player] ?? 0) + 1;
        } elseif ($isXeri) {
            $state['xeri'][$player] = ($state['xeri'][$player] ?? 0) + 1;
        }
    } else {
        // δεν μάζεψε -> ρίχνει στο τραπέζι
        $table[] = $card;
    }
}

// log move (stored but not returned)
$state['moves'] = $state['moves'] ?? [];
$state['moves'][] = [
    "type" => "PLAY",
    "player" => (int)$player,
    "card" => $card,
    "capture" => $capture,
    "ts" => date('c')
];

// switch turn
$state['turn'] = ($player === "1") ? 2 : 1;
$next_player = (string)$state['turn'];

// update points every move
$state['points'] = compute_live_points($state);

// save
if (!$game->saveState($state)) {
    json_out([
        "ok" => false,
        "error" => "Failed to save state",
        "flags" => compute_flags($state, $player),
        "state_view" => make_state_view($state, $player)
    ], 500);
}

// IMPORTANT:
// - flags: for requester (player who made the call) => after switch, can_move should be FALSE
// - flags_next: for next player (the one who has turn now) => can_move should be TRUE (if has cards)
json_out([
    "ok" => true,
    "message" => "Move played",
    "game_id" => $game_id,
    "player" => (int)$player,
    "played_card" => $card,
    "capture" => $capture,
    "points" => $state['points'],
    "turn_next" => (int)$state['turn'],
    "flags" => compute_flags($state, $player),
    "flags_next" => compute_flags($state, $next_player),
    "state_view" => make_state_view($state, $player)
], 200);
