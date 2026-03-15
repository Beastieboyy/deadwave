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
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$action = $_GET['action'] ?? '';

switch($action) {
    case 'leaderboard':
        $period = $_GET['period'] ?? 'all';
        $where = '';
        if($period === 'today') $where = 'WHERE submitted_at >= CURDATE()';
        elseif($period === 'week') $where = 'WHERE submitted_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)';
        $stmt = $pdo->query("SELECT player_name, score, level_reached, kills, stars, submitted_at FROM dtm_scores $where ORDER BY score DESC LIMIT 50");
        echo json_encode(['success' => true, 'scores' => $stmt->fetchAll()]);
        break;

    case 'submit':
        $input = json_decode(file_get_contents('php://input'), true);
        if(!$input || empty($input['name'])) { echo json_encode(['error'=>'Missing name']); exit; }
        $name  = substr(preg_replace('/[^a-zA-Z0-9_ -]/', '', $input['name']), 0, 50);
        $score = intval($input['score'] ?? 0);
        $level = intval($input['level'] ?? 1);
        $kills = intval($input['kills'] ?? 0);
        $stars = intval($input['stars'] ?? 0);
        $pdo->prepare("INSERT INTO dtm_scores (player_name, score, level_reached, kills, stars) VALUES (?,?,?,?,?)")->execute([$name,$score,$level,$kills,$stars]);
        $stmt = $pdo->prepare("SELECT COUNT(*) as rank FROM dtm_scores WHERE score > ?");
        $stmt->execute([$score]);
        echo json_encode(['success'=>true, 'rank'=>$stmt->fetch()['rank']+1]);
        break;

    case 'register':
        $data = json_decode(file_get_contents('php://input'), true);
        $username = strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '', $data['username']??'')));
        $display  = substr(trim($data['display']??$username), 0, 30);
        $password = $data['password'] ?? '';
        if(strlen($username)<3){ echo json_encode(['error'=>'Username must be at least 3 characters']); exit; }
        if(strlen($username)>20){ echo json_encode(['error'=>'Username too long (max 20)']); exit; }
        if(strlen($password)<4){ echo json_encode(['error'=>'Password must be at least 4 characters']); exit; }
        $check=$pdo->prepare('SELECT id FROM dtm_players WHERE username=?');$check->execute([$username]);
        if($check->fetch()){ echo json_encode(['error'=>'Username already taken']); exit; }
        $hash=password_hash($password,PASSWORD_DEFAULT);
        $pdo->prepare('INSERT INTO dtm_players (username,password_hash,display_name) VALUES (?,?,?)')->execute([$username,$hash,$display]);
        $id=$pdo->lastInsertId();
        $token=bin2hex(random_bytes(32));
        $pdo->prepare('INSERT INTO dtm_tokens (token,player_id,expires) VALUES (?,?,DATE_ADD(NOW(),INTERVAL 90 DAY))')->execute([$token,$id]);
        echo json_encode(['success'=>true,'token'=>$token,'player'=>['id'=>$id,'username'=>$username,'display_name'=>$display,'upgrades'=>'{"sneakers":0,"backpack":0,"phone":0,"hoodie":0}','unlocked_levels'=>'[1,2,3]','stars'=>'{}','total_snacks'=>0,'highest_level'=>1]]);
        break;

    case 'login':
        $data = json_decode(file_get_contents('php://input'), true);
        $username = strtolower(trim($data['username']??''));
        $password = $data['password'] ?? '';
        $stmt=$pdo->prepare('SELECT * FROM dtm_players WHERE username=?');$stmt->execute([$username]);
        $player=$stmt->fetch();
        if(!$player||!$player['password_hash']||!password_verify($password,$player['password_hash'])){ echo json_encode(['error'=>'Wrong username or password']); exit; }
        $token=bin2hex(random_bytes(32));
        $pdo->prepare('INSERT INTO dtm_tokens (token,player_id,expires) VALUES (?,?,DATE_ADD(NOW(),INTERVAL 90 DAY))')->execute([$token,$player['id']]);
        $pdo->prepare('UPDATE dtm_players SET last_login=NOW() WHERE id=?')->execute([$player['id']]);
        unset($player['password_hash']);
        echo json_encode(['success'=>true,'token'=>$token,'player'=>$player]);
        break;

    case 'validate':
        $token=$_GET['token']??'';
        $stmt=$pdo->prepare('SELECT p.* FROM dtm_players p JOIN dtm_tokens t ON p.id=t.player_id WHERE t.token=? AND t.expires>NOW()');
        $stmt->execute([$token]);$player=$stmt->fetch();
        if(!$player){ echo json_encode(['error'=>'Session expired']); exit; }
        unset($player['password_hash']);
        echo json_encode(['success'=>true,'player'=>$player]);
        break;

    case 'save':
        $data=json_decode(file_get_contents('php://input'),true);
        $token=$data['token']??'';
        $stmt=$pdo->prepare('SELECT player_id FROM dtm_tokens WHERE token=? AND expires>NOW()');
        $stmt->execute([$token]);$row=$stmt->fetch();
        if(!$row){ echo json_encode(['error'=>'Not authenticated']); exit; }
        $pid=$row['player_id'];$fields=[];$vals=[];
        if(isset($data['total_snacks'])){$fields[]='total_snacks=?';$vals[]=(int)$data['total_snacks'];}
        if(isset($data['highest_level'])){$fields[]='highest_level=?';$vals[]=(int)$data['highest_level'];}
        if(isset($data['total_kills'])){$fields[]='total_kills=?';$vals[]=(int)$data['total_kills'];}
        if(isset($data['upgrades'])){$fields[]='upgrades=?';$vals[]=json_encode($data['upgrades']);}
        if(isset($data['unlocked_levels'])){$fields[]='unlocked_levels=?';$vals[]=json_encode($data['unlocked_levels']);}
        if(isset($data['stars'])){$fields[]='stars=?';$vals[]=json_encode($data['stars']);}
        if(!empty($fields)){$vals[]=$pid;$pdo->prepare('UPDATE dtm_players SET '.implode(',',$fields).' WHERE id=?')->execute($vals);}
        echo json_encode(['success'=>true]);
        break;

    case 'logout':
        $data=json_decode(file_get_contents('php://input'),true);
        $pdo->prepare('DELETE FROM dtm_tokens WHERE token=?')->execute([$data['token']??'']);
        echo json_encode(['success'=>true]);
        break;

    case 'player':
        $name=$_GET['name']??'';
        if(!$name){ echo json_encode(['error'=>'Missing name']); exit; }
        $stmt=$pdo->prepare("SELECT * FROM dtm_players WHERE username=?");$stmt->execute([$name]);
        $player=$stmt->fetch();if($player)unset($player['password_hash']);
        echo json_encode(['success'=>true,'player'=>$player]);
        break;

    default:
        echo json_encode(['error'=>'Unknown action','valid_actions'=>['leaderboard','submit','register','login','validate','save','logout','player']]);
}
