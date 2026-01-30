<?php

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../models/Game.php';

require_post(['game_id', 'token']);

$game_id = (int)$_POST['game_id'];
$token   = trim((string)$_POST['token']);

$db   = (new Database())->getConnection();
$game = new Game($db);

// Έλεγχος αν υπάρχει παιχνίδι
if (!$game->load($game_id)) {
    json_out(["error" => "Game not found"], 404);
}

// Φόρτωση κατάστασης παιχνιδιού
$state = json_decode($game->state_json, true);
if (!is_array($state)) {
    json_out(["error" => "Invalid game state JSON"], 500);
}

// Έλεγχος token → παίκτης
$player = token_to_player($state, $token);
if ($player === null) {
    json_out(["error" => "Invalid token"], 401);
}

$playerKey = (string)$player; // "1" ή "2"

// ✅ 1) Ενημέρωση state: joined (ώστε roll/state να δουλεύουν)
if (!isset($state['joined']) || !is_array($state['joined'])) {
    $state['joined'] = ["1" => false, "2" => false];
}
$state['joined'][$playerKey] = true;

// ✅ 2) Αποθήκευση παίκτη στο session
session_set_player($game_id, $playerKey);

// ✅ 3) Αποθήκευση νέου state στη βάση
if (!$game->saveState($state)) {
    json_out(["error" => "Failed to save state"], 500);
}

// Επιτυχής είσοδος
json_out([
    "message" => "Joined successfully",
    "game_id" => $game_id,
    "player"  => (int)$player
], 200);
