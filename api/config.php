<?php
declare(strict_types=1);
/**
 * GET /poradca/api/config.php – nastavenia pre widget (texty, umiestnenie, farby, stránky).
 * Zmeny v admine sa na webe prejavia do 1 minúty bez zásahu do kódu e-shopu.
 */
require_once dirname(__DIR__) . '/src/bootstrap.php';
if (!cors()) { json_out(['error' => 'forbidden'], 403); exit; }

// Zapamätáme si, z ktorých webov sa widget načítava (admin ukáže, či je správne vložený)
$host = parse_url((string)($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_HOST);
if ($host) {
    $seen = json_decode(Settings::get('widget_seen') ?: '{}', true) ?: [];
    if (!isset($seen[$host]) || strtotime($seen[$host]) < time() - 300) {
        $seen[$host] = now();
        arsort($seen);
        Settings::set('widget_seen', json_encode(array_slice($seen, 0, 10, true)));
    }
}

header('Cache-Control: public, max-age=60');
json_out([
    'enabled' => Settings::get('enabled') === '1',
    'name' => Settings::get('assistant_name'),
    'greeting' => Settings::get('greeting'),
    'quick' => Settings::quickStart(),
    'display' => Settings::display(),
]);

// Údržba na pozadí (obnova produktov, označovanie) – zákazník na ňu nečaká
Maintenance::afterResponse();
