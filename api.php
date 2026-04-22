<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec("CREATE TABLE IF NOT EXISTS ssb4_store (
        k VARCHAR(255) NOT NULL PRIMARY KEY,
        v LONGTEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed']);
    exit;
}

$action = $_GET['action'] ?? null;
$body   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? $action;
}

// ── CHECK ADMIN PASSWORD ──
if ($action === 'checkAdmin') {
    $pw = $body['pw'] ?? '';
    if ($pw && password_verify($pw, ADMIN_PW_HASH)) {
        echo json_encode(['ok' => true]);
    } else {
        http_response_code(401);
        echo json_encode(['ok' => false]);
    }
    exit;

// ── GET ALL ──
} elseif ($action === 'getAll') {
    $rows = $pdo->query("SELECT k, v FROM ssb4_store")->fetchAll(PDO::FETCH_ASSOC);
    $out  = new stdClass();
    foreach ($rows as $row) {
        $key       = $row['k'];
        $out->$key = json_decode($row['v'], true);
    }
    echo json_encode($out);

// ── SET ALL ──
} elseif ($action === 'setAll') {
    $data = $body['data'] ?? [];

    // Safety: refuse to replace many rows with very few (catches accidental wipes)
    $currentCount = (int)$pdo->query("SELECT COUNT(*) FROM ssb4_store")->fetchColumn();
    $newCount = count($data);
    if ($currentCount > 5 && $newCount < intval($currentCount * 0.4)) {
        http_response_code(409);
        echo json_encode(['error' => "Refusing: would replace {$currentCount} rows with {$newCount}"]);
        exit;
    }

    $pdo->beginTransaction();
    try {
        $pdo->exec("DELETE FROM ssb4_store");
        if (!empty($data)) {
            $stmt = $pdo->prepare("INSERT INTO ssb4_store (k, v) VALUES (?, ?)");
            foreach ($data as $k => $v) {
                $stmt->execute([$k, json_encode($v, JSON_UNESCAPED_UNICODE)]);
            }
        }
        $pdo->commit();
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Write failed: ' . $e->getMessage()]);
    }

// ── BACKUP (called by cron) ──
} elseif ($action === 'backup') {
    $pw = $body['pw'] ?? $_GET['pw'] ?? '';
    if (!$pw || !password_verify($pw, ADMIN_PW_HASH)) {
        http_response_code(401);
        echo json_encode(['error' => 'unauthorized']);
        exit;
    }
    $rows = $pdo->query("SELECT k, v FROM ssb4_store")->fetchAll(PDO::FETCH_ASSOC);
    $out  = new stdClass();
    foreach ($rows as $row) {
        $key       = $row['k'];
        $out->$key = json_decode($row['v'], true);
    }
    $json    = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $dir     = __DIR__ . '/backups';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $filename = $dir . '/backup_' . date('Y-m-d_H-i-s') . '.json';
    file_put_contents($filename, $json);

    // Keep only last 30 backups
    $files = glob($dir . '/backup_*.json');
    usort($files, fn($a,$b) => strcmp($b,$a));
    foreach (array_slice($files, 30) as $old) unlink($old);

    echo json_encode(['ok' => true, 'file' => basename($filename), 'rows' => count($rows)]);

} else {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
}
