<?php
// ΠΑΡΑΔΕΙΓΜΑ start.php


header("Content-Type: application/json");

require_once "../core/Database.php";
require_once "../models/Game.php";

if (!isset($_POST['player1']) || !isset($_POST['player2'])) {
    echo json_encode(["error" => "Missing players"]);
    exit;
}

$player1 = $_POST['player1'];
$player2 = $_POST['player2'];

$database = new Database();
$db = $database->getConnection();

// Δημιουργία νέου παιχνιδιού στον πίνακα games
$stmt = $db->prepare("INSERT INTO games (player1, player2, state_json) VALUES (:p1, :p2, '{}')");
$stmt->execute([
    ':p1' => $player1,
    ':p2' => $player2,
]);

$game_id = $db->lastInsertId();

// --- ΕΔΩ βάζουμε το αρχικό state της Ξερής ---
$initial_state = [
    "turn"   => 1,
    "round"  => 1,
    "deck"   => [],
    "table"  => [],
    "hands"  => [
        "1" => [],
        "2" => []
    ],
    "piles"  => [
        "1" => [],
        "2" => []
    ],
    "points" => [
        "1" => 0,
        "2" => 0
    ],
    "xeri"   => [
        "1" => 0,
        "2" => 0
    ],
    "last_capture" => null
];

$state_json = json_encode($initial_state);

// Ενημέρωση του state_json στη βάση 
$update = $db->prepare("UPDATE games SET state_json = :state WHERE id = :id");
$update->execute([
    ':state' => $state_json,
    ':id'    => $game_id,
]);

// Απάντηση προς τον client
echo json_encode([
    "message" => "Game started",
    "game_id" => $game_id,
    "state"   => $initial_state
], JSON_PRETTY_PRINT);
