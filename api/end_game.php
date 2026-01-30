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

// Session auth (όπως στα άλλα endpoints)
$player = (string)session_get_player($game_id);

// Πρέπει να έχουν μπει και οι 2
if (empty($state['joined']['1']) || empty($state['joined']['2'])) {
    json_out([
        "error" => "Both players must join before end_game",
        "joined" => $state['joined'],
        "flags" => compute_flags($state, $player),
        "state_view" => make_state_view($state, $player)
    ], 409);
}

// Αν ΔΕΝ έχει τελειώσει το παιχνίδι πραγματικά, μην το κλείνεις
if (!is_game_finished($state)) {
    json_out([
        "error" => "Game not finished yet",
        "deck_count" => count($state['deck']),
        "hands" => [
            "p1_count" => count($state['hands']['1']),
            "p2_count" => count($state['hands']['2'])
        ],
        "table_count" => count($state['table']),
        "flags" => compute_flags($state, $player),
        "state_view" => make_state_view($state, $player)
    ], 409);
}

// Αν δεν έχει δηλωθεί ήδη game_over, finalize table + score τώρα
if (empty($state['game_over'])) {
    // 1) Δώσε τα χαρτιά του τραπεζιού στον last_capture (κανόνας Ξερής/Κολτσίνας)
    finalize_table_to_last_capture($state);

    // 2) Τελικό σκορ
    $final_score = compute_final_score($state);

    $state['winner'] = $final_score['winner'];
    $state['final_score'] = $final_score['score'];
    $state['game_over'] = true;

    // 3) Save
    if (!$game->saveState($state)) {
        json_out(["error" => "Failed to save final game state"], 500);
    }
}

// --- Breakdown για να ελέγχεις ΟΛΑ τα κριτήρια ---
$piles1 = $state['piles']['1'] ?? [];
$piles2 = $state['piles']['2'] ?? [];

$cards1 = count($piles1);
$cards2 = count($piles2);
$majority_bonus = ["1" => 0, "2" => 0];
if ($cards1 > $cards2) $majority_bonus["1"] = 3;
elseif ($cards2 > $cards1) $majority_bonus["2"] = 3;

// ειδικά
$special = [
    "1" => [
        "has_2S" => in_array("2S", $piles1, true),
        "has_10D" => in_array("10D", $piles1, true),
    ],
    "2" => [
        "has_2S" => in_array("2S", $piles2, true),
        "has_10D" => in_array("10D", $piles2, true),
    ],
];

// μετρήσεις K/Q/J/10 (χωρίς 10D)
function rank_only(string $c): string { return substr($c, 0, -1); }

$pointRanks = ["K","Q","J","10"]; // ΣΥΜΦΩΝΑ με τα screenshots σου
$face_points = ["1" => 0, "2" => 0];

foreach ($piles1 as $c) {
    if ($c === "10D") continue;
    if (in_array(rank_only($c), $pointRanks, true)) $face_points["1"]++;
}
foreach ($piles2 as $c) {
    if ($c === "10D") continue;
    if (in_array(rank_only($c), $pointRanks, true)) $face_points["2"]++;
}

$breakdown = [
    "cards_count" => ["1" => $cards1, "2" => $cards2],
    "majority_bonus" => $majority_bonus,
    "special_cards_bonus" => [
        "1" => ($special["1"]["has_2S"] ? 1 : 0) + ($special["1"]["has_10D"] ? 1 : 0),
        "2" => ($special["2"]["has_2S"] ? 1 : 0) + ($special["2"]["has_10D"] ? 1 : 0),
    ],
    "face_10_bonus" => $face_points,
    "xeri_bonus" => [
        "1" => 10 * (int)($state['xeri']["1"] ?? 0),
        "2" => 10 * (int)($state['xeri']["2"] ?? 0),
    ],
    "xeri_jack_bonus" => [
        "1" => 20 * (int)($state['xeri_jack']["1"] ?? 0),
        "2" => 20 * (int)($state['xeri_jack']["2"] ?? 0),
    ],
    "xeri_counts" => $state['xeri'],
    "xeri_jack_counts" => $state['xeri_jack'],
    "special_flags" => $special
];

json_out([
    "message"     => "Game Over",
    "game_id"     => $game_id,
    "winner"      => $state['winner'],
    "final_score" => $state['final_score'],
    "breakdown"   => $breakdown,
    "state_view"  => make_state_view($state, $player),
    "flags"       => compute_flags($state, $player)
], 200);
