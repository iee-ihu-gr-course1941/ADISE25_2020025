<?php
require_once __DIR__ . '/core/bootstrap.php';

// -------- path resolve --------
$path = '';

if (!empty($_SERVER['PATH_INFO'])) {
    $path = trim((string)$_SERVER['PATH_INFO'], '/');
} else {
    $uri = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '';
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');

    if ($script !== '' && strpos($uri, $script) === 0) {
        $path = trim(substr($uri, strlen($script)), '/');
    } else {
        $path = trim($uri, '/');
    }
}

// Normalize (remove duplicate slashes)
$path = preg_replace('#/+#', '/', $path ?? '');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

// Small helper for method guarding
$allow = function(array $methods) use ($method) {
    if (!in_array($method, $methods, true)) {
        json_out([
            "ok" => false,
            "error" => "METHOD_NOT_ALLOWED",
            "method" => $method,
            "allowed" => $methods
        ], 405);
    }
};

switch ($path) {
    case '':
    case 'ping':
        $allow(['GET']);
        json_out(["ok" => true, "service" => "xeri-api"]);
        break;

    // START (you allow both /games and /games/start)
    case 'games':
    case 'games/start':
        $allow(['POST']);
        require __DIR__ . '/api/start.php';
        break;

    case 'games/join':
        $allow(['POST']);
        require __DIR__ . '/api/join.php';
        break;

    case 'games/roll':
        $allow(['POST']);
        require __DIR__ . '/api/roll.php';
        break;

    case 'games/move':
        $allow(['POST']);
        require __DIR__ . '/api/move.php';
        break;

    case 'games/state':
        $allow(['GET']);
        require __DIR__ . '/api/state.php';
        break;

    case 'games/score':
        $allow(['GET']);
        require __DIR__ . '/api/score.php';
        break;

    case 'games/end_game':
        $allow(['POST']);
        require __DIR__ . '/api/end_game.php';
        break;

    default:
        json_out([
            "ok" => false,
            "error" => "NOT_FOUND",
            "method" => $method,
            "path" => "/" . $path
        ], 404);
}
