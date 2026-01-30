<?php
// state.php — επιστροφή της τρέχουσας κατάστασης παιχνιδιού

header("Content-Type: application/json");

require_once "../core/Database.php";

if (!isset($_GET['game_id'])) {
    echo json_encode(["error" => "Missing game_id"]);
    exit;
}

$game_id = intval($_GET['game_id']);

$database = new Database();
$db = $database->getConnection();

// Ανάγνωση του state_json από τη βάση
$stmt = $db->prepare("SELECT state_json FROM games WHERE id = :id");
$stmt->execute([':id' => $game_id]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo json_encode(["error" => "Game not found"]);
    exit;
}

$state = json_decode($row['state_json'], true);

// Απάντηση προς τον client
echo json_encode([
    "game_id" => $game_id,
    "state"   => $state
], JSON_PRETTY_PRINT);
