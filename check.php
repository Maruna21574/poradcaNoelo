<?php
declare(strict_types=1);
/**
 * Kontrola hostingu: otvorte https://VASA-DOMENA/poradca/check.php po nahratí súborov.
 * Nezobrazuje žiadne tajné údaje. Po úspešnej kontrole ho môžete zmazať.
 */
header('Content-Type: text/html; charset=utf-8');
$rows = [];
$ok = function (string $label, bool $pass, string $info = '') use (&$rows) { $rows[] = [$label, $pass, $info]; };
$ok('PHP 8.1 alebo novšie', version_compare(PHP_VERSION, '8.1.0', '>='), PHP_VERSION);
foreach (['pdo_sqlite', 'curl', 'xmlreader', 'mbstring', 'dom', 'json'] as $e) $ok("Rozšírenie $e", extension_loaded($e));
$ok('config.php existuje', is_file(__DIR__ . '/config.php'), 'skopírujte config.sample.php → config.php');
$cfg = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$ok('API kľúč je nastavený', !empty($cfg['anthropic_api_key']));
$ok('Admin používateľ je nastavený', !empty($cfg['admin_users']));
$dir = $cfg['data_dir'] ?? __DIR__ . '/data';
$ok('Priečinok data/ je zapisovateľný', is_dir($dir) && is_writable($dir), $dir);
$test = function (string $url): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 10, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true]);
    curl_exec($ch); $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return (string)$c;
};
$c = $test('https://www.noelo.sk/'); $ok('Server sa dostane na www.noelo.sk', $c === '200', "HTTP $c");
$c = $test('https://api.anthropic.com/v1/messages'); $ok('Server sa dostane na api.anthropic.com', $c !== '0', "HTTP $c (405 je v poriadku)");
if (is_file($dir . '/poradca.sqlite')) {
    $pdo = new PDO('sqlite:' . $dir . '/poradca.sqlite');
    $n = (int)$pdo->query('SELECT COUNT(*) FROM products WHERE active = 1 AND available = 1')->fetchColumn();
    $ok('Produkty načítané (cron/import.php)', $n > 0, "$n dostupných produktov");
} else {
    $ok('Produkty načítané (cron/import.php)', false, 'databáza ešte neexistuje – spustite import');
}
?><!DOCTYPE html><html lang="sk"><meta charset="utf-8"><title>Kontrola – Darčekový poradca</title>
<body style="font-family:system-ui,sans-serif;max-width:720px;margin:40px auto;color:#4a4339"><h1>Kontrola hostingu</h1>
<table style="width:100%;border-collapse:collapse"><?php foreach ($rows as [$l, $p, $i]): ?>
<tr style="border-bottom:1px solid #eee"><td style="padding:8px"><?= $p ? '✅' : '❌' ?></td><td style="padding:8px"><?= htmlspecialchars($l) ?></td><td style="padding:8px;color:#888"><?= htmlspecialchars($i) ?></td></tr>
<?php endforeach; ?></table></body></html>
