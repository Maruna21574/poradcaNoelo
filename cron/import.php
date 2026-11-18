<?php
declare(strict_types=1);
/**
 * Import produktov + automatické označenie nových produktov.
 * Spúšťajte cronom, napr. každú hodinu:
 *   0 * * * * php /cesta/k/poradca/cron/import.php >> /cesta/k/poradca/data/import.log 2>&1
 * Voľby:  --no-tag      len import bez AI označovania
 *         --file=feed.xml   import z lokálneho súboru (test)
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/src/bootstrap.php';

$opts = getopt('', ['no-tag', 'file:', 'tag-limit:']);
$log = fn(string $m) => print('[' . date('Y-m-d H:i:s') . "] $m\n");

try {
    $log('Import začína…');
    $r = FeedImporter::run($opts['file'] ?? null, $log);
    $log("Produktov vo feede: {$r['total']}, dostupných: {$r['available']}, vypnutých: {$r['deactivated']}");
    if ($r['message']) $log($r['message']);
} catch (Throwable $e) {
    $log('CHYBA importu: ' . $e->getMessage());
    exit(1);
}

if (!isset($opts['no-tag'])) {
    $n = Tagger::run((int)($opts['tag-limit'] ?? 2000), $log);
    $log("AI označilo $n produktov.");
}

// GDPR: mazanie starých konverzácií
$days = (int)(config('retention_days') ?: 180);
$st = db()->prepare('DELETE FROM conversations WHERE created_at < ?');
$st->execute([date('Y-m-d H:i:s', strtotime("-$days days"))]);
if ($st->rowCount()) $log('Zmazaných starých konverzácií: ' . $st->rowCount());
$log('Hotovo.');
