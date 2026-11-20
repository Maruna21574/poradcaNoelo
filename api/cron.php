<?php
declare(strict_types=1);
/**
 * Spustenie importu cez URL – pre hostingy, kde cron vie volať len webovú adresu.
 *   https://VASA-DOMENA/poradca/api/cron.php?token=TAJNY_TOKEN
 * Token nastavte v config.php ('cron_token'). Jedno spustenie trvá max. ~50 s a pokračuje tam, kde skončilo,
 * preto ho nastavte napr. každých 10 minút.
 */
require_once dirname(__DIR__) . '/src/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');
$token = (string)config('cron_token');
if ($token === '' || !hash_equals($token, (string)($_GET['token'] ?? ''))) { http_response_code(403); exit("Neplatný token.\n"); }
@set_time_limit(120);
ignore_user_abort(true);
$log = function (string $m) { echo '[' . date('H:i:s') . "] $m\n"; @ob_flush(); flush(); };
try {
    $r = FeedImporter::run(null, $log, 50);
    $log("Produktov: {$r['total']}, dostupných: {$r['available']}");
    if (Sales::due()) {
        try { Sales::refresh($log); } catch (Throwable $e) { $log('CHYBA predajov: ' . $e->getMessage()); }
    }
    $n = Tagger::run(120, $log);
    $log("AI označilo $n produktov.");
} catch (Throwable $e) {
    http_response_code(500);
    $log('CHYBA: ' . $e->getMessage());
}
