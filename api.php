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

// ── GET ALL ──
if ($action === 'getAll') {
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

} else {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
}
