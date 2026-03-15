<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');
if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

$host = '127.0.0.1';
$db   = 'donttellmama';
$user = 'dtm_user';
$pass = 'DontTellMama2025';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch(PDOException $e) {
    echo json_encode(['error' => 'Database connection failed', 'detail' => $e->getMessage()]);
    exit;
}

$action = $_GET['action'] ?? '';

switch($action) {
    case 'leaderboard':
        $period = $_GET['period'] ?? 'all';
        $where = '';
        if($period === 'today') {
            $where = 'WHERE submitted_at >= CURDATE()';
        } elseif($period === 'week') {
            $where = 'WHERE submitted_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)';
        }
        $stmt = $pdo->query("SELECT player_name, score, level_reached, kills, stars, submitted_at FROM dtm_scores $where ORDER BY score DESC LIMIT 50");
        $scores = $stmt->fetchAll();
        echo json_encode(['success' => true, 'scores' => $scores]);
        break;

    case 'submit':
        $input = json_decode(file_get_contents('php://input'), true);
        if(!$input || empty($input['name'])) {
            echo json_encode(['error' => 'Missing name']);
            exit;
        }
        $name  = substr(preg_replace('/[^a-zA-Z0-9_ -]/', '', $input['name']), 0, 50);
        $score = intval($input['score'] ?? 0);
        $level = intval($input['level'] ?? 1);
        $kills = intval($input['kills'] ?? 0);
        $stars = intval($input['stars'] ?? 0);

        // Insert score
        $stmt = $pdo->prepare("INSERT INTO dtm_scores (player_name, score, level_reached, kills, stars) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$name, $score, $level, $kills, $stars]);

        // Update or create player profile
        $stmt = $pdo->prepare("INSERT INTO dtm_players (player_name, total_snacks, highest_level, total_kills) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE total_kills = total_kills + VALUES(total_kills), highest_level = GREATEST(highest_level, VALUES(highest_level)), last_played = CURRENT_TIMESTAMP");
        $stmt->execute([$name, 0, $level, $kills]);

        // Get rank
        $stmt = $pdo->prepare("SELECT COUNT(*) as rank FROM dtm_scores WHERE score > ?");
        $stmt->execute([$score]);
        $rank = $stmt->fetch()['rank'] + 1;

        echo json_encode(['success' => true, 'rank' => $rank]);
        break;

    case 'player':
        $name = $_GET['name'] ?? '';
        if(!$name) {
            echo json_encode(['error' => 'Missing name']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT * FROM dtm_players WHERE player_name = ?");
        $stmt->execute([$name]);
        $player = $stmt->fetch();
        if($player) {
            echo json_encode(['success' => true, 'player' => $player]);
        } else {
            echo json_encode(['success' => true, 'player' => null]);
        }
        break;

    default:
        echo json_encode(['error' => 'Unknown action', 'valid_actions' => ['leaderboard', 'submit', 'player']]);
}
