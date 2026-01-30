<?php 
// core/bootstrap.php
// Common helpers for Xeri API

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ----------------------------
 * JSON output (pretty)
 * ---------------------------- */
if (!function_exists('json_out')) {
    function json_out(array $data, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        // Pretty output so curl shows vertically
        echo json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
        echo "\n";
        exit;
    }
}

/* ----------------------------
 * Request validators
 * ---------------------------- */
if (!function_exists('require_post')) {
    function require_post(array $fields = []): void {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method !== 'POST') {
            json_out([
                "ok" => false,
                "error" => "METHOD_NOT_ALLOWED",
                "method" => $method,
                "allowed" => ["POST"]
            ], 405);
        }

        foreach ($fields as $f) {
            if (!isset($_POST[$f]) || trim((string)$_POST[$f]) === '') {
                json_out([
                    "ok" => false,
                    "error" => "MISSING_FIELD",
                    "field" => $f
                ], 422);
            }
        }
    }
}

if (!function_exists('require_get')) {
    function require_get(array $fields = []): void {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method !== 'GET') {
            json_out([
                "ok" => false,
                "error" => "METHOD_NOT_ALLOWED",
                "method" => $method,
                "allowed" => ["GET"]
            ], 405);
        }

        foreach ($fields as $f) {
            if (!isset($_GET[$f]) || trim((string)$_GET[$f]) === '') {
                json_out([
                    "ok" => false,
                    "error" => "MISSING_FIELD",
                    "field" => $f
                ], 422);
            }
        }
    }
}

/* ----------------------------
 * Session helpers
 * ---------------------------- */
if (!function_exists('session_set_player')) {
    function session_set_player(int $game_id, string $player): void {
        $_SESSION['xeri_player'][(string)$game_id] = (string)$player;
    }
}

if (!function_exists('session_get_player')) {
    function session_get_player(int $game_id): string {
        $gid = (string)$game_id;
        if (!isset($_SESSION['xeri_player'][$gid])) {
            json_out(["error" => "Not joined (no session)"], 401);
        }
        return (string)$_SESSION['xeri_player'][$gid];
    }
}

/* ----------------------------
 * Token helpers
 * ---------------------------- */
if (!function_exists('rand_token')) {
    function rand_token(int $bytes = 16): string {
        return bin2hex(random_bytes($bytes));
    }
}

if (!function_exists('token_to_player')) {
    function token_to_player(array $state, string $token): ?string {
        $t1 = $state['tokens']['1'] ?? null;
        $t2 = $state['tokens']['2'] ?? null;
        if ($token !== '' && $t1 !== null && hash_equals((string)$t1, $token)) return "1";
        if ($token !== '' && $t2 !== null && hash_equals((string)$t2, $token)) return "2";
        return null;
    }
}

/* ----------------------------
 * State normalization
 * ---------------------------- */
if (!function_exists('ensure_state_keys')) {
    function ensure_state_keys(array $state): array {
        // game phase
        $state['game_over'] = $state['game_over'] ?? false;
        $state['winner']    = $state['winner'] ?? 0;

        // IMPORTANT: started means "game initialized via /games/roll" (deck/table prepared)
        // MUST default to false, otherwise can_roll will be wrong.
        $state['started'] = $state['started'] ?? false;

        // Keep this field for backward compatibility if some endpoints used it.
        // It should become true when the deck is built (first roll).
        $state['deck_initialized'] = $state['deck_initialized'] ?? false;

        $state['turn']  = $state['turn']  ?? 1;
        $state['round'] = $state['round'] ?? 1;

        $state['deck']  = $state['deck']  ?? [];
        $state['table'] = $state['table'] ?? [];

        $state['hands'] = $state['hands'] ?? ["1" => [], "2" => []];
        $state['hands']['1'] = $state['hands']['1'] ?? [];
        $state['hands']['2'] = $state['hands']['2'] ?? [];

        $state['piles'] = $state['piles'] ?? ["1" => [], "2" => []];
        $state['piles']['1'] = $state['piles']['1'] ?? [];
        $state['piles']['2'] = $state['piles']['2'] ?? [];

        $state['xeri'] = $state['xeri'] ?? ["1" => 0, "2" => 0];
        $state['xeri']['1'] = (int)($state['xeri']['1'] ?? 0);
        $state['xeri']['2'] = (int)($state['xeri']['2'] ?? 0);

        $state['xeri_jack'] = $state['xeri_jack'] ?? ["1" => 0, "2" => 0];
        $state['xeri_jack']['1'] = (int)($state['xeri_jack']['1'] ?? 0);
        $state['xeri_jack']['2'] = (int)($state['xeri_jack']['2'] ?? 0);

        $state['points'] = $state['points'] ?? ["1" => 0, "2" => 0];
        $state['points']['1'] = (int)($state['points']['1'] ?? 0);
        $state['points']['2'] = (int)($state['points']['2'] ?? 0);

        $state['final_score'] = $state['final_score'] ?? ["1" => 0, "2" => 0];
        $state['final_score']['1'] = (int)($state['final_score']['1'] ?? 0);
        $state['final_score']['2'] = (int)($state['final_score']['2'] ?? 0);

        $state['last_capture'] = $state['last_capture'] ?? null;

        $state['joined'] = $state['joined'] ?? ["1" => false, "2" => false];
        $state['joined']['1'] = (bool)($state['joined']['1'] ?? false);
        $state['joined']['2'] = (bool)($state['joined']['2'] ?? false);

        $state['tokens'] = $state['tokens'] ?? ["1" => "", "2" => ""];
        $state['tokens']['1'] = (string)($state['tokens']['1'] ?? "");
        $state['tokens']['2'] = (string)($state['tokens']['2'] ?? "");

        $state['moves'] = $state['moves'] ?? [];

        return $state;
    }
}

/* ----------------------------
 * Game rules helpers
 * ---------------------------- */
if (!function_exists('both_players_joined')) {
    function both_players_joined(array $state): bool {
        $state = ensure_state_keys($state);
        return !empty($state['joined']['1']) && !empty($state['joined']['2']);
    }
}

if (!function_exists('build_deck')) {
    function build_deck(): array {
        $ranks = ["A","2","3","4","5","6","7","8","9","10","J","Q","K"];
        $suits = ["S","H","D","C"];
        $deck = [];
        foreach ($suits as $s) {
            foreach ($ranks as $r) {
                $deck[] = $r.$s;
            }
        }
        return $deck;
    }
}

/**
 * Game finished ONLY if:
 * - deck was initialized at least once (prevents end_game=true at time 0)
 * - deck empty and both hands empty
 */
if (!function_exists('is_game_finished')) {
    function is_game_finished(array $state): bool {
        $state = ensure_state_keys($state);
        if (empty($state['deck_initialized'])) return false;
        return count($state['deck']) === 0
            && count($state['hands']['1']) === 0
            && count($state['hands']['2']) === 0;
    }
}

/* ----------------------------
 * Scoring (live points snapshot)
 * ---------------------------- */
if (!function_exists('card_rank')) {
    function card_rank(string $c): string {
        return substr($c, 0, -1);
    }
}

if (!function_exists('card_suit')) {
    function card_suit(string $c): string {
        return substr($c, -1);
    }
}

if (!function_exists('compute_live_points')) {
    function compute_live_points(array $state): array {
        $state = ensure_state_keys($state);

        // live points: final bonus (+3 most cards) is applied in end_game
        $points = ["1" => 0, "2" => 0];

        // +10 per xeri, +20 per xeri_jack
        foreach (["1","2"] as $pl) {
            $points[$pl] += 10 * (int)($state['xeri'][$pl] ?? 0);
            $points[$pl] += 20 * (int)($state['xeri_jack'][$pl] ?? 0);
        }

        // +1 for 2S, +1 for 10D
        foreach (["1","2"] as $pl) {
            $pile = $state['piles'][$pl] ?? [];
            if (in_array("2S", $pile, true))  $points[$pl] += 1;
            if (in_array("10D", $pile, true)) $points[$pl] += 1;
        }

        // +1 for each K,Q,J,A,10 (excluding 10D to avoid double count)
        $pointRanks = ["A","K","Q","J","10"];
        foreach (["1","2"] as $pl) {
            $pile = $state['piles'][$pl] ?? [];
            foreach ($pile as $c) {
                if ($c === "10D") continue;
                if (in_array(card_rank($c), $pointRanks, true)) {
                    $points[$pl] += 1;
                }
            }
        }

        return $points;
    }
}

/* ----------------------------
 * Flags for UI/CLI flow
 * ---------------------------- */
if (!function_exists('compute_flags')) {
    function compute_flags(array $state, ?string $viewer = null): array {
        $state = ensure_state_keys($state);

        $joined_ok = both_players_joined($state);

        $is_over  = !empty($state['game_over']);
        $started  = !empty($state['started']); // true only after first /roll init

        $hands1_empty = empty($state['hands']['1']);
        $hands2_empty = empty($state['hands']['2']);
        $hands_empty  = $hands1_empty && $hands2_empty;

        $deck_count = is_array($state['deck']) ? count($state['deck']) : 0;

        // Roll:
        // - allowed when both joined, not game_over, and hands are empty
        // - if NOT started yet, allow even if deck_count=0 (roll will init deck/table)
        // - if started, allow only if deck_count>0
        $can_roll = $joined_ok && !$is_over && $hands_empty && ( (!$started) || ($deck_count > 0) );

        // Move:
        // - must have cards in hands and it's viewer's turn
        $turn = (string)$state['turn'];
        $can_move = $joined_ok && !$is_over && !$hands_empty
            && ($viewer === "1" || $viewer === "2")
            && $turn === $viewer;

        // End game:
        // - only when started=true, not game_over, and truly finished
        $can_end_game = $joined_ok && $started && !$is_over && is_game_finished($state);

        return [
            "joined_ok"    => $joined_ok,
            "can_roll"     => $can_roll,
            "can_move"     => $can_move,
            "can_end_game" => $can_end_game
        ];
    }
}

/* ----------------------------
 * Public state view (hide moves + hide opponent hand)
 * ---------------------------- */
if (!function_exists('make_state_view')) {
    function make_state_view(array $state, ?string $viewer): array {
        $state = ensure_state_keys($state);

        $view = [
            "game_over" => (bool)$state['game_over'],
            "started" => (bool)$state['started'],
            "turn" => (int)$state['turn'],
            "round" => (int)$state['round'],
            "deck_count" => count($state['deck']),
            "table" => $state['table'],
            "joined" => $state['joined'],
            "last_capture" => $state['last_capture'],
            "xeri" => $state['xeri'],
            "xeri_jack" => $state['xeri_jack'],
            "points" => $state['points'],
            "final_score" => $state['final_score'],
            "winner" => (int)$state['winner'],
            "piles_count" => [
                "1" => count($state['piles']['1']),
                "2" => count($state['piles']['2'])
            ],
            "hands" => [
                "p1_count" => count($state['hands']['1']),
                "p2_count" => count($state['hands']['2'])
            ]
        ];

        // Show only viewer's hand (and only if viewer is known)
        if ($viewer === "1") {
            $view["hands"]["p1"] = $state['hands']['1'];
        } elseif ($viewer === "2") {
            $view["hands"]["p2"] = $state['hands']['2'];
        }

        return $view;
    }
}

/* ----------------------------
 * END GAME HELPERS (XERI RULES)
 * ---------------------------- */

/**
 * Δίνει τα χαρτιά του τραπεζιού στον last_capture.
 * Αν last_capture είναι null (δεν έγινε ποτέ capture),
 * τα δίνουμε στον παίκτη που έπαιξε τελευταίος:
 * αφού στο move κάνεις switch turn, ο "τελευταίος" = αντίθετος του state['turn'].
 */
if (!function_exists('finalize_table_to_last_capture')) {
    function finalize_table_to_last_capture(array &$state): void {
        $state = ensure_state_keys($state);

        if (empty($state['table'])) return;

        $lc = $state['last_capture'];

        if ($lc !== "1" && $lc !== "2" && $lc !== 1 && $lc !== 2) {
            $turn = (string)$state['turn']; // next player
            $lc = ($turn === "1") ? "2" : "1";
        } else {
            $lc = (string)$lc;
        }

        $state['piles'][$lc] = $state['piles'][$lc] ?? [];
        $state['piles'][$lc] = array_merge($state['piles'][$lc], $state['table']);
        $state['table'] = [];
        $state['last_capture'] = (int)$lc;
    }
}

/**
 * Υπολογισμός τελικού σκορ σύμφωνα με τους κανόνες screenshot.
 */
if (!function_exists('compute_final_score')) {
    function compute_final_score(array $state): array {
        $state = ensure_state_keys($state);

        $piles = [
            "1" => $state['piles']['1'] ?? [],
            "2" => $state['piles']['2'] ?? [],
        ];

        $score = ["1" => 0, "2" => 0];

        // 1) +10 ανά Ξερή, +20 ανά Ξερή με Βαλέ
        foreach (["1","2"] as $pl) {
            $score[$pl] += 10 * (int)($state['xeri'][$pl] ?? 0);
            $score[$pl] += 20 * (int)($state['xeri_jack'][$pl] ?? 0);
        }

        // 2) +1 για 2♠, +1 για 10♦
        foreach (["1","2"] as $pl) {
            if (in_array("2S", $piles[$pl], true))  $score[$pl] += 1;
            if (in_array("10D", $piles[$pl], true)) $score[$pl] += 1;
        }

        // 3) +1 για κάθε K,Q,J,A,10 (ΟΧΙ το 10♦ γιατί ήδη μετρήθηκε)
        $pointRanks = ["A","K","Q","J","10"];
        foreach (["1","2"] as $pl) {
            foreach ($piles[$pl] as $c) {
                if ($c === "10D") continue;
                if (in_array(card_rank($c), $pointRanks, true)) {
                    $score[$pl] += 1;
                }
            }
        }

        // 4) +3 σε όποιον έχει τα περισσότερα χαρτιά (ισοβαθμία => κανείς)
        $c1 = count($piles["1"]);
        $c2 = count($piles["2"]);
        if ($c1 > $c2) $score["1"] += 3;
        elseif ($c2 > $c1) $score["2"] += 3;

        $winner = 0;
        if ($score["1"] > $score["2"]) $winner = 1;
        elseif ($score["2"] > $score["1"]) $winner = 2;

        return ["score" => $score, "winner" => $winner];
    }
}
