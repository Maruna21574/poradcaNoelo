<?php
declare(strict_types=1);

/**
 * Databáza (SQLite). Schéma je napísaná tak, aby sa dala neskôr preniesť do MySQL.
 */
final class Db
{
    private static ?PDO $pdo = null;
    private const SCHEMA_VERSION = 3;

    public static function get(): PDO
    {
        if (self::$pdo) return self::$pdo;
        $dir = (string)config('data_dir');
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Neviem vytvoriť priečinok pre dáta: ' . $dir);
        }
        $pdo = new PDO('sqlite:' . $dir . '/poradca.sqlite', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        self::$pdo = $pdo;
        self::migrate($pdo);
        return $pdo;
    }

    /** Len na testy: použije zadané PDO. */
    public static function set(PDO $pdo): void
    {
        self::$pdo = $pdo;
        self::migrate($pdo);
    }

    private static function migrate(PDO $pdo): void
    {
        $v = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
        if ($v >= self::SCHEMA_VERSION) return;
        if ($v === 2) {
            self::migrateV3($pdo);
            $pdo->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
            return;
        }
        if ($v === 1) {
            self::migrateV2($pdo);
            self::migrateV3($pdo);
            $pdo->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
            return;
        }

        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS products (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    brand TEXT NOT NULL DEFAULT '',
    category TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '',
    url TEXT NOT NULL DEFAULT '',
    image TEXT NOT NULL DEFAULT '',
    price REAL,
    cost REAL,                             -- nákupná cena (len pre admin, nikdy nejde k AI ani zákazníkovi)
    available INTEGER NOT NULL DEFAULT 0,
    availability_text TEXT NOT NULL DEFAULT '',
    item_group_id TEXT NOT NULL DEFAULT '',
    active INTEGER NOT NULL DEFAULT 1,     -- 0 = už nie je vo feede
    source_hash TEXT NOT NULL DEFAULT '',
    first_seen_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    -- automatické označenie (AI)
    age_min INTEGER, age_max INTEGER,
    gender TEXT,                           -- dievča | chlapec | unisex
    interests TEXT NOT NULL DEFAULT '',    -- čiarkou oddelené
    gift_score INTEGER,                    -- 0 = nevhodné ako darček … 3 = výborný darček
    tags_hash TEXT NOT NULL DEFAULT '',
    tagged_at TEXT,
    search_text TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_products_avail ON products(active, available);

CREATE TABLE IF NOT EXISTS product_overrides (
    product_id TEXT PRIMARY KEY,
    boost INTEGER NOT NULL DEFAULT 0,      -- 0 žiadne, 1 mierne, 2 výrazné, 3 maximálne
    boost_reason TEXT NOT NULL DEFAULT '',
    boost_until TEXT,                      -- YYYY-MM-DD vrátane, NULL = bez obmedzenia
    ai_note TEXT NOT NULL DEFAULT '',      -- poznámka pre AI (napr. „novinka“, „ideálne k stavebnici X“)
    excluded INTEGER NOT NULL DEFAULT 0,   -- 1 = poradca produkt nikdy neodporučí
    age_min INTEGER, age_max INTEGER, gender TEXT,   -- ručná oprava automatických značiek
    updated_by TEXT NOT NULL DEFAULT '',
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS boost_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kind TEXT NOT NULL,                    -- brand | category
    value TEXT NOT NULL,
    boost INTEGER NOT NULL,
    reason TEXT NOT NULL DEFAULT '',
    until TEXT,
    created_by TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS conversations (
    id TEXT PRIMARY KEY,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    ip_hash TEXT NOT NULL DEFAULT '',
    page_url TEXT NOT NULL DEFAULT '',
    turns INTEGER NOT NULL DEFAULT 0,
    profile_json TEXT NOT NULL DEFAULT '{}',   -- vek, pre koho, rozpočet, záujmy, príležitosť
    unmet TEXT NOT NULL DEFAULT '',            -- čo zákazník chcel a v ponuke nebolo
    rating INTEGER                              -- 1 = palec hore, -1 = palec dole
);
CREATE INDEX IF NOT EXISTS idx_conv_created ON conversations(created_at);

CREATE TABLE IF NOT EXISTS messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    conversation_id TEXT NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
    role TEXT NOT NULL,                        -- user | assistant
    content TEXT NOT NULL,
    quick_json TEXT NOT NULL DEFAULT '[]',
    picks_json TEXT NOT NULL DEFAULT '[]',
    searches_json TEXT NOT NULL DEFAULT '[]',  -- čo AI hľadala v katalógu
    tokens_in INTEGER NOT NULL DEFAULT 0,
    tokens_out INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_msg_conv ON messages(conversation_id);

CREATE TABLE IF NOT EXISTS recommendations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    conversation_id TEXT NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
    message_id INTEGER NOT NULL,
    product_id TEXT NOT NULL,
    position INTEGER NOT NULL,
    boosted INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_rec_product ON recommendations(product_id);
CREATE INDEX IF NOT EXISTS idx_rec_created ON recommendations(created_at);

CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    conversation_id TEXT NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
    product_id TEXT NOT NULL DEFAULT '',
    type TEXT NOT NULL,                        -- open | click | add_to_cart | rating_up | rating_down
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_events_type ON events(type, created_at);

CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS import_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    started_at TEXT NOT NULL,
    finished_at TEXT,
    status TEXT NOT NULL,                      -- running | ok | error
    total INTEGER NOT NULL DEFAULT 0,
    available INTEGER NOT NULL DEFAULT 0,
    deactivated INTEGER NOT NULL DEFAULT 0,
    feed_last_modified TEXT,
    message TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS rate_limits (
    ip_hash TEXT NOT NULL,
    hour TEXT NOT NULL,
    count INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (ip_hash, hour)
);
SQL);
        self::migrateV2($pdo);
        self::migrateV3($pdo);
        $pdo->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
    }

    /** v3: predaje produktov z databázy e-shopu (bestsellery) */
    private static function migrateV3(PDO $pdo): void
    {
        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS product_sales (
    product_id TEXT PRIMARY KEY,
    sold_7 INTEGER NOT NULL DEFAULT 0,         -- kusov za posledných 7 dní
    sold_30 INTEGER NOT NULL DEFAULT 0,        -- kusov za posledných 30 dní
    updated_at TEXT NOT NULL
);
SQL);
    }

    /** v2: ukážkové konverzácie a podrobnosti z načítania webu */
    private static function migrateV2(PDO $pdo): void
    {
        $cols = array_column($pdo->query('PRAGMA table_info(conversations)')->fetchAll(), 'name');
        if (!in_array('is_demo', $cols, true)) $pdo->exec('ALTER TABLE conversations ADD COLUMN is_demo INTEGER NOT NULL DEFAULT 0');
        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS crawl_items (
    id TEXT PRIMARY KEY,
    slug TEXT NOT NULL,
    list_name TEXT NOT NULL DEFAULT '',
    price REAL,
    availability_text TEXT NOT NULL DEFAULT '',
    image TEXT NOT NULL DEFAULT '',
    categories TEXT NOT NULL DEFAULT '',
    seen_at TEXT NOT NULL,
    full_name TEXT NOT NULL DEFAULT '',
    brand TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '',
    detail_at TEXT
);
SQL);
    }
}
