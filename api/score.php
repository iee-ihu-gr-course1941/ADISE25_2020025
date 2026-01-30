<?php
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../models/Game.php';

require_get(['game_id']);
$game_id = (int)$_GET['game_id'];

$db = (new Database())->getConnection();
$game = new Game($db);

if (!$game->load($game_id)) {
    json_out(["error" => "Game not found"], 404);
}

$state = json_decode($game->state_json, true);
if (!is_array($state)) {
    json_out(["error" => "Invalid state_json"], 500);
}

$state['piles'] = $state['piles'] ?? ["1"=>[], "2"=>[]];
$state['xeri'] = $state['xeri'] ?? ["1"=>0, "2"=>0];
$state['xeri_jack'] = $state['xeri_jack'] ?? ["1"=>0, "2"=>0];

$live = compute_live_points($state);

json_out([
    "message" => "Live score",
    "game_id" => $game_id,
    "points" => $live,
    "note" => "The +3 majority-cards bonus is calculated at end_game only."
]);
