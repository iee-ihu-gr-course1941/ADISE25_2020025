<?php
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../models/Game.php';

// Μόνο GET
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_out([
        "ok" => false,
        "error" => "METHOD_NOT_ALLOWED",
        "method" => $_SERVER['REQUEST_METHOD'] ?? null,
        "allowed" => ["GET"]
    ], 405);
}

require_get(['game_id']);
$game_id = (int)$_GET['game_id'];

$db = (new Database())->getConnection();
$game = new Game($db);

if (!$game->load($game_id)) {
    json_out(["ok" => false, "error" => "Game not found"], 404);
}

$state = json_decode($game->state_json, true);
if (!is_array($state)) {
    json_out(["ok" => false, "error" => "Invalid state_json"], 500);
}

$state = ensure_state_keys($state);

// Viewer από session (αν υπάρχει)
$viewer = null;
if (isset($_SESSION['xeri_player'][(string)$game_id])) {
    $v = (string)$_SESSION['xeri_player'][(string)$game_id];
    if ($v === "1" || $v === "2") {
        $viewer = $v;
    }
}

// Points: live ή final
if (!empty($state['game_over'])) {
    if (isset($state['final_score']) && is_array($state['final_score'])) {
        $state['points'] = $state['final_score'];
    } else {
        $state['points'] = compute_live_points($state);
    }
} else {
    $state['points'] = compute_live_points($state);
}

// Flags (με viewer για σωστό can_move)
$flags = compute_flags($state, $viewer);

// State view (φιλτραρισμένο)
$state_view = make_state_view($state, $viewer);

json_out([
    "ok" => true,
    "game_id" => $game_id,
    "flags" => $flags,
    "state_view" => $state_view
], 200);
