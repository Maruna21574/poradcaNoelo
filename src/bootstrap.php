<?php
declare(strict_types=1);

/**
 * Spoločné načítanie: konfigurácia, databáza, pomocné funkcie.
 */

const PORADCA_VERSION = '1.0.0';

mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Bratislava');

spl_autoload_register(function (string $class): void {
    $file = __DIR__ . '/' . $class . '.php';
    if (is_file($file)) require $file;
});

function config(?string $key = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $file = dirname(__DIR__) . '/config.php';
        if (!is_file($file)) $file = dirname(__DIR__) . '/config.sample.php';
        $cfg = require $file;
    }
    if ($key === null) return $cfg;
    $val = $cfg;
    foreach (explode('.', $key) as $k) {
        if (!is_array($val) || !array_key_exists($k, $val)) return null;
        $val = $val[$k];
    }
    return $val;
}

function db(): PDO
{
    return Db::get();
}

/** Odstráni diakritiku a zmenší písmená – na vyhľadávanie. */
function normalize_text(string $s): string
{
    $s = mb_strtolower($s);
    $map = [
        'á'=>'a','ä'=>'a','č'=>'c','ď'=>'d','é'=>'e','ě'=>'e','í'=>'i','ĺ'=>'l','ľ'=>'l','ň'=>'n','ó'=>'o','ô'=>'o','ö'=>'o',
        'ŕ'=>'r','ř'=>'r','š'=>'s','ť'=>'t','ú'=>'u','ů'=>'u','ü'=>'u','ý'=>'y','ž'=>'z','ł'=>'l','ś'=>'s','ż'=>'z','ź'=>'z','ć'=>'c','ń'=>'n','ę'=>'e','ą'=>'a',
    ];
    $s = strtr($s, $map);
    $s = preg_replace('/[^a-z0-9]+/u', ' ', $s) ?? '';
    return trim(preg_replace('/\s+/', ' ', $s) ?? '');
}

function json_out($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function cors(): bool
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = Settings::allowedOrigins();
    if ($origin !== '') {
        $sameHost = parse_url($origin, PHP_URL_HOST) . (parse_url($origin, PHP_URL_PORT) ? ':' . parse_url($origin, PHP_URL_PORT) : '') === ($_SERVER['HTTP_HOST'] ?? '');
        if (!$sameHost && !in_array($origin, $allowed, true)) return false;
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    return true;
}

function client_ip_hash(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    // IP neukladáme, len jej hash so soľou (GDPR)
    return substr(hash('sha256', $ip . '|' . Settings::get('salt')), 0, 24);
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function random_id(int $bytes = 12): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function price_fmt(?float $p): string
{
    return $p === null ? '–' : number_format($p, 2, ',', ' ') . ' €';
}
