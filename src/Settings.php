<?php
declare(strict_types=1);

/**
 * Nastavenia, ktoré sa dajú meniť v admine.
 */
final class Settings
{
    public const DEFAULTS = [
        'enabled' => '1',
        'assistant_name' => 'Darčekový poradca',
        'greeting' => 'Dobrý deň! 🎁 Pomôžem vám vybrať darček z ponuky noelo.sk. Pre koho ho hľadáte a koľko má rokov?',
        'quick_start' => "Darček pre 8-ročné dievča\nPre 5-ročného chlapca do 30 €\nPre bábätko\nČo sa najviac kupuje 🔥",
        'extra_instructions' => '',
        'max_picks' => '4',
        'salt' => '',
        // --- Umiestnenie a zobrazenie na webe (nastavuje sa v admine) ---
        'allowed_origins' => "https://www.noelo.sk\nhttps://noelo.sk",
        'cart_mode' => 'real',                 // real = skutočný košík e-shopu, demo = len simulácia, off = bez tlačidla
        'd_position' => 'right-bottom',        // right-bottom | left-bottom | right-top | left-top | none
        'd_offset_x' => '22',
        'd_offset_y' => '22',
        'd_button_text' => 'Darčekový poradca',
        'd_color_main' => '#22c4b2',
        'd_color_accent' => '#ef7b84',
        'd_pages_mode' => 'all',               // all | home | list
        'd_pages' => '',
        'd_exclude' => "/kosik*\n/pokladna*\n/prihlasenie*\n/registracia*",
        'd_device' => 'all',                   // all | desktop | mobile
        'd_teaser_text' => 'Hľadáte darček? Poradím vám 🎁',
        'd_teaser_delay' => '8',               // sekundy, 0 = nezobrazovať
        'd_inline_enabled' => '1',
        'd_inline_selector' => '.secP',
        'd_inline_where' => 'before',          // before | after | prepend | append
        'd_inline_pages_mode' => 'home',
        'd_inline_pages' => '',
        'd_inline_title' => 'Neviete, čo darovať?',
        'd_inline_text' => 'Napíšte, pre koho hľadáte darček, a náš Darčekový poradca vám ho pomôže vybrať.',
    ];

    /** Nastavenia zobrazenia pre widget (verejné, bez citlivých údajov). */
    public static function display(): array
    {
        $s = self::all();
        $lines = fn(string $k) => array_values(array_filter(array_map('trim', explode("\n", $s[$k]))));
        return [
            'position' => $s['d_position'], 'offsetX' => (int)$s['d_offset_x'], 'offsetY' => (int)$s['d_offset_y'],
            'buttonText' => $s['d_button_text'], 'colorMain' => $s['d_color_main'], 'colorAccent' => $s['d_color_accent'],
            'pagesMode' => $s['d_pages_mode'], 'pages' => $lines('d_pages'), 'exclude' => $lines('d_exclude'), 'device' => $s['d_device'],
            'teaserText' => $s['d_teaser_text'], 'teaserDelay' => (int)$s['d_teaser_delay'],
            'inline' => [
                'enabled' => $s['d_inline_enabled'] === '1', 'selector' => $s['d_inline_selector'], 'where' => $s['d_inline_where'],
                'pagesMode' => $s['d_inline_pages_mode'], 'pages' => $lines('d_inline_pages'),
                'title' => $s['d_inline_title'], 'text' => $s['d_inline_text'],
            ],
            'cart' => $s['cart_mode'],
        ];
    }

    /** @return string[] povolené domény (config.php + admin) */
    public static function allowedOrigins(): array
    {
        $fromAdmin = array_filter(array_map(fn($o) => rtrim(trim($o), '/'), explode("\n", self::get('allowed_origins'))));
        return array_values(array_unique(array_merge((array)config('allowed_origins'), $fromAdmin)));
    }

    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            $rows = db()->query('SELECT key, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
            self::$cache = array_merge(self::DEFAULTS, $rows ?: []);
            if (self::$cache['salt'] === '') {
                self::set('salt', bin2hex(random_bytes(16)));
            }
        }
        return self::$cache;
    }

    public static function get(string $key): string
    {
        return (string)(self::all()[$key] ?? '');
    }

    public static function set(string $key, string $value): void
    {
        $st = db()->prepare('INSERT INTO settings(key, value) VALUES(?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
        $st->execute([$key, $value]);
        if (self::$cache !== null) self::$cache[$key] = $value;
    }

    /** @return string[] */
    public static function quickStart(): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", self::get('quick_start')))));
    }

    public static function reset(): void
    {
        self::$cache = null;
    }
}
