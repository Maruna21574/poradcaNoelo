<?php
declare(strict_types=1);

/**
 * Predaje produktov (bestsellery) z databázy e-shopu noelo.sk.
 *
 * Rovnaká logika ako v aplikácii Noelo sklad (sales.php): predaje sa počítajú živo z tabuliek orders / ordereditems,
 * bez stornovaných objednávok a bez zľavových položiek. Poradca si pamätá len súčty za 7 a 30 dní pre každý produkt
 * (tabuľka product_sales), takže pri rozhovore sa do databázy e-shopu nevolá.
 *
 * Pripojenie sa nastavuje v config.php ('shop_db'). Odporúčame samostatného používateľa len na čítanie (SELECT).
 */
final class Sales
{
    private const ORDER_STATUS_STORNO = 4;

    /** Na testy: funkcia, ktorá nahradí dotaz do MySQL a vráti riadky [id, sold_7, sold_30]. */
    public static $mock = null;

    public static function enabled(): bool
    {
        if (is_callable(self::$mock)) return true;
        $c = (array)config('shop_db');
        return !empty($c['host']) && !empty($c['user']) && !empty($c['db']) && class_exists('mysqli');
    }

    /** Je čas obnoviť predaje? (predvolene raz za 6 hodín) */
    public static function due(): bool
    {
        if (!self::enabled()) return false;
        $last = Settings::get('sales_updated');
        $hours = (int)(config('sales_refresh_hours') ?: 6);
        return $last === '' || strtotime($last) < time() - 3600 * $hours;
    }

    /** Má poradca nejaké dáta o predaji? */
    public static function hasData(): bool
    {
        return (int)db()->query('SELECT COUNT(*) FROM product_sales WHERE sold_30 > 0')->fetchColumn() > 0;
    }

    /**
     * Načíta predaje z databázy e-shopu a uloží ich.
     * @return array{rows:int, matched:int}
     */
    public static function refresh(?callable $log = null): array
    {
        $rows = self::fetchRows();
        $stats = self::store($rows);
        Settings::set('sales_updated', now());
        Catalog::clearCache();
        if ($log) $log("Predaje: {$stats['rows']} produktov s predajom za 30 dní, z toho {$stats['matched']} je v katalógu poradcu.");
        return $stats;
    }

    /** @return iterable<array{0:string,1:int,2:int}> */
    private static function fetchRows(): iterable
    {
        if (is_callable(self::$mock)) return (self::$mock)();

        $c = (array)config('shop_db');
        if (!self::enabled()) throw new RuntimeException('Pripojenie na databázu e-shopu (shop_db) nie je nastavené.');

        mysqli_report(MYSQLI_REPORT_OFF);
        $conn = mysqli_init();
        $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 8);
        if (!@$conn->real_connect((string)$c['host'], (string)$c['user'], (string)($c['pass'] ?? ''), (string)$c['db'], (int)($c['port'] ?? 3306))) {
            throw new RuntimeException('Databáza e-shopu: ' . $conn->connect_error);
        }
        $conn->set_charset('utf8mb4');
        // Len čítanie a obmedzený čas dotazu (MySQL 5.7+ / MariaDB)
        @$conn->query('SET SESSION TRANSACTION READ ONLY');
        @$conn->query('SET SESSION MAX_EXECUTION_TIME = 30000');
        @$conn->query('SET SESSION max_statement_time = 30');

        [$minOrder, $minItem] = self::bounds($conn);
        if ($minOrder === 0) { $conn->close(); return []; }

        // Titulok položky bez „zľava“ / „Zľava“ (hex bajty, aby nezáležalo na kódovaní súboru)
        $sql = "SELECT oi.idProduct AS id,
                       SUM(CASE WHEN o.created >= NOW() - INTERVAL 7 DAY THEN oi.count ELSE 0 END) AS sold_7,
                       SUM(oi.count) AS sold_30
                FROM ordereditems oi
                JOIN orders o ON o.id = oi.idOrder
                WHERE oi.id >= $minItem AND o.id >= $minOrder
                  AND o.created >= NOW() - INTERVAL 30 DAY
                  AND o.status <> " . self::ORDER_STATUS_STORNO . "
                  AND oi.price > 0
                  AND oi.title NOT LIKE CONCAT('%', CONVERT(0x5AC4BE617661 USING utf8mb4), '%')
                  AND oi.title NOT LIKE CONCAT('%', CONVERT(0x7AC4BE617661 USING utf8mb4), '%')
                GROUP BY oi.idProduct";
        $res = $conn->query($sql);
        if (!$res) {
            $err = $conn->error;
            $conn->close();
            throw new RuntimeException('Dotaz na predaje zlyhal: ' . $err);
        }
        $out = [];
        while ($r = $res->fetch_assoc()) {
            $out[] = [(string)$r['id'], (int)$r['sold_7'], (int)$r['sold_30']];
        }
        $res->free();
        $conn->close();
        return $out;
    }

    /** Hranice ID objednávok za posledných ~31 dní (tabuľky nemajú index na dátum – ako v sklade). Počíta sa raz za deň. */
    private static function bounds(mysqli $conn): array
    {
        $cached = json_decode(Settings::get('sales_bounds') ?: '{}', true) ?: [];
        if (($cached['date'] ?? '') === date('Y-m-d')) return [(int)$cached['order'], (int)$cached['item']];

        $r = $conn->query('SELECT COALESCE(MIN(id), 0) FROM orders WHERE created >= CURDATE() - INTERVAL 31 DAY');
        $minOrder = $r ? (int)$r->fetch_row()[0] : 0;
        $minItem = 0;
        if ($minOrder > 0) {
            $r = $conn->query("SELECT COALESCE(MIN(id), 0) FROM ordereditems WHERE idOrder >= $minOrder");
            $minItem = $r ? (int)$r->fetch_row()[0] : 0;
        }
        if ($minOrder > 0) Settings::set('sales_bounds', json_encode(['date' => date('Y-m-d'), 'order' => $minOrder, 'item' => $minItem]));
        return [$minOrder, $minItem];
    }

    /** @param iterable<array{0:string,1:int,2:int}> $rows */
    public static function store(iterable $rows): array
    {
        $pdo = db();
        $known = array_flip($pdo->query('SELECT id FROM products')->fetchAll(PDO::FETCH_COLUMN));
        $stamp = now();
        $n = 0; $matched = 0;
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM product_sales');
        $ins = $pdo->prepare('INSERT OR REPLACE INTO product_sales(product_id, sold_7, sold_30, updated_at) VALUES(?,?,?,?)');
        foreach ($rows as [$id, $s7, $s30]) {
            if ($id === '' || $s30 <= 0) continue;
            $ins->execute([$id, $s7, $s30, $stamp]);
            $n++;
            if (isset($known[$id])) $matched++;
        }
        $pdo->commit();
        return ['rows' => $n, 'matched' => $matched];
    }
}
