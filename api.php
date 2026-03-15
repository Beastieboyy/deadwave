<?php
header('Content-Type: application/json; charset=utf-8');

$host = '127.0.0.1';
$db   = 'donttellmama';
$user = 'dtm_user';
$pass = 'DontTellMama2025';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(Exception $e) {
    echo json_encode(['error' => 'DB connection failed']); exit;
}

$action = $_GET['action'] ?? '';
$BLACKLIST = ['test','tester','admin','root','debug','vince'];

if ($action === 'ping') {
    echo json_encode(['success'=>true,'message'=>'API alive','time'=>date('H:i:s')]);
    exit;
}

if ($action === 'submit') {
    $name  = substr(preg_replace('/[^a-zA-Z0-9_ ]/', '', $_GET['name'] ?? 'Player'), 0, 20);
    $pin   = preg_replace('/[^0-9]/', '', $_GET['pin'] ?? '0000');
    $score = (int)($_GET['score'] ?? 0);
    $level = (int)($_GET['level'] ?? 1);
    $kills = (int)($_GET['kills'] ?? 0);
    $stars = (int)($_GET['stars'] ?? 0);
    if (empty($name)) $name = 'Player';
    $pinHash = hash('sha256', $pin);

    if (in_array(strtolower($name), $BLACKLIST)) {
        $pdo->prepare('INSERT INTO dtm_scores (player_name, pin_hash, score, level_reached, kills, stars) VALUES (?,?,?,?,?,?)')->execute([$name,$pinHash,$score,$level,$kills,$stars]);
        echo json_encode(['success'=>true,'rank'=>999]);
        exit;
    }

    $check = $pdo->prepare('SELECT pin_hash FROM dtm_scores WHERE player_name = ? AND pin_hash != ? AND pin_hash != "" LIMIT 1');
    $check->execute([$name, $pinHash]);
    if ($check->fetch()) {
        echo json_encode(['error' => 'Wrong PIN for that name! Try a different name.']);
        exit;
    }

    $stmt = $pdo->prepare('INSERT INTO dtm_scores (player_name, pin_hash, score, level_reached, kills, stars) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$name, $pinHash, $score, $level, $kills, $stars]);

    $rank = $pdo->prepare('SELECT COUNT(*) + 1 AS r FROM (SELECT MAX(score) as score FROM dtm_scores GROUP BY player_name) t WHERE t.score > ?');
    $rank->execute([$score]);
    $r = (int)$rank->fetch(PDO::FETCH_ASSOC)['r'];

    echo json_encode(['success'=>true,'rank'=>$r]);
    exit;
}

if ($action === 'leaderboard') {
    $period = $_GET['period'] ?? 'all';
    $where = '';
    if ($period === 'today') $where = "WHERE DATE(submitted_at) = CURDATE()";
    if ($period === 'week')  $where = "WHERE submitted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";

    $bl = implode(',', array_map(fn($n)=>$pdo->quote($n), $BLACKLIST));
    $and = $where ? 'AND' : 'WHERE';
    $sql = "SELECT player_name, MAX(score) as score, MAX(level_reached) as level_reached, MAX(kills) as kills, MAX(stars) as stars FROM dtm_scores $where $and LOWER(player_name) NOT IN ($bl) GROUP BY player_name ORDER BY score DESC LIMIT 20";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success'=>true,'scores'=>$rows]);
    exit;
}

if ($action === 'personal') {
    $name    = $_GET['name'] ?? '';
    $pin     = preg_replace('/[^0-9]/', '', $_GET['pin'] ?? '');
    $pinHash = hash('sha256', $pin);

    $stmt = $pdo->prepare('SELECT score, level_reached, kills, stars FROM dtm_scores WHERE player_name = ? AND pin_hash = ? ORDER BY score DESC LIMIT 1');
    $stmt->execute([$name, $pinHash]);
    $best = $stmt->fetch(PDO::FETCH_ASSOC);

    $rank = 0;
    if ($best) {
        $r = $pdo->prepare('SELECT COUNT(*) + 1 AS r FROM (SELECT MAX(score) as score FROM dtm_scores GROUP BY player_name) t WHERE t.score > ?');
        $r->execute([$best['score']]);
        $rank = (int)$r->fetch(PDO::FETCH_ASSOC)['r'];
    }
    echo json_encode(['success'=>true,'best'=>$best,'rank'=>$rank]);
    exit;
}

echo json_encode(['error'=>'Unknown action']);
