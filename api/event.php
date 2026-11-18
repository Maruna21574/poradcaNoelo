<?php
declare(strict_types=1);
/**
 * POST /poradca/api/event.php – meranie: {"conversation_id", "type": "open|click|add_to_cart|rating_up|rating_down", "product_id"?}
 */
require_once dirname(__DIR__) . '/src/bootstrap.php';

if (!cors()) { json_out(['error' => 'forbidden'], 403); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { json_out(['error' => 'method'], 405); exit; }

$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
$conv = (string)($in['conversation_id'] ?? '');
$type = (string)($in['type'] ?? '');
if (!in_array($type, ['open', 'click', 'add_to_cart', 'rating_up', 'rating_down'], true) || $conv === '') {
    json_out(['error' => 'bad_request'], 400); exit;
}
$pdo = db();
$st = $pdo->prepare('SELECT 1 FROM conversations WHERE id = ?');
$st->execute([$conv]);
if (!$st->fetchColumn()) { json_out(['ok' => false], 404); exit; }

if ($type === 'rating_up' || $type === 'rating_down') {
    $pdo->prepare('UPDATE conversations SET rating = ? WHERE id = ?')->execute([$type === 'rating_up' ? 1 : -1, $conv]);
}
$pdo->prepare('INSERT INTO events(conversation_id, product_id, type, created_at) VALUES(?,?,?,?)')
    ->execute([$conv, mb_substr((string)($in['product_id'] ?? ''), 0, 64), $type, now()]);
json_out(['ok' => true]);
