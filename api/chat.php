<?php
declare(strict_types=1);
/**
 * POST /poradca/api/chat.php
 * Telo: {"conversation_id": "…" | null, "message": "…", "page": "https://…"}
 * Odpoveď: {"conversation_id", "reply", "quick": [], "picks": [{id,name,brand,price,url,image,availability,why}]}
 */
require_once dirname(__DIR__) . '/src/bootstrap.php';

if (!cors()) { json_out(['error' => 'forbidden'], 403); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { json_out(['error' => 'method'], 405); exit; }
if (Settings::get('enabled') !== '1') { json_out(['error' => 'disabled'], 503); exit; }

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in) || trim((string)($in['message'] ?? '')) === '') { json_out(['error' => 'bad_request'], 400); exit; }

// Limit požiadaviek z jednej IP za hodinu
$ip = client_ip_hash();
$hour = date('Y-m-d H');
$pdo = db();
$pdo->prepare('INSERT INTO rate_limits(ip_hash, hour, count) VALUES(?, ?, 1) ON CONFLICT(ip_hash, hour) DO UPDATE SET count = count + 1')
    ->execute([$ip, $hour]);
$st = $pdo->prepare('SELECT count FROM rate_limits WHERE ip_hash = ? AND hour = ?');
$st->execute([$ip, $hour]);
if ((int)$st->fetchColumn() > (int)(config('rate_limit_per_hour') ?: 40)) { json_out(['error' => 'rate_limited'], 429); exit; }
if (random_int(1, 200) === 1) $pdo->prepare('DELETE FROM rate_limits WHERE hour < ?')->execute([date('Y-m-d H', strtotime('-2 hours'))]);

try {
    $out = Advisor::chat(
        isset($in['conversation_id']) ? (string)$in['conversation_id'] : null,
        (string)$in['message'],
        (string)($in['page'] ?? '')
    );
    json_out($out);
} catch (InvalidArgumentException $e) {
    json_out(['error' => 'bad_request'], 400);
} catch (Throwable $e) {
    error_log('[poradca] ' . $e->getMessage());
    json_out(['error' => 'upstream'], 502);
}
