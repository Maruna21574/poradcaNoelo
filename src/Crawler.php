<?php
declare(strict_types=1);

/**
 * Načítanie produktov priamo z webu noelo.sk (náhrada za zastaraný feed).
 *
 * 1. Zoznamy kategórií: ID, názov, cena, dostupnosť, obrázok, kategórie (rýchle, cca 500 stránok).
 * 2. Detaily produktov: plný názov, značka, popis (postupne, napr. 400 produktov na jedno spustenie).
 *
 * Keď bude k dispozícii aktuálny feed alebo databáza, stačí v config.php zmeniť feed.type a crawler sa prestane používať.
 */
final class Crawler
{
    public const BASE = 'https://www.noelo.sk';

    /** Kategórie, v ktorých hľadáme darčeky (slug => názov). Dá sa zmeniť v config.php: crawl.categories */
    public const CATEGORIES = [
        'vozitka-skakadla-choditka' => 'Vozítka, chodítka', 'hracky-do-auta' => 'Hračky do auta', 'hracky-pre-batolata' => 'Hračky pre batoľatá',
        'kolotoce' => 'Kolotoče', 'hrkalky-a-hryzatka' => 'Hrkálky a hryzátka', 'hudobne-a-zvukove-hracky' => 'Hudobné a zvukové hračky',
        'hracky-do-vody' => 'Hračky k vode', 'hrazdy-a-hracie-deky' => 'Hrazdy a hracie deky', 'kocky-a-puzzle' => 'Kocky a puzzle',
        'zavesne-hracky' => 'Hračky na zavesenie', 'prve-knizky' => 'Knihy pre deti', 'maznave-hracky-a-plysove-hracky' => 'Plyšové hračky',
        'hojdadla-a-odrazadla' => 'Hojdadlá a odrážadlá', 'hracky-na-zahradu' => 'Hračky na záhradu', 'stavebnice' => 'Stavebnice',
        'kociky-pre-babiky' => 'Kočíky pre bábiky', 'kreativne' => 'Kreatívne a motorické', 'lehatka-s-aktivitami' => 'Lehátka s aktivitami',
        'drevene-hracky' => 'Drevené hračky', 'naucne-hracky' => 'Hry a náučné hračky', 'magnetibook' => 'Magnetické knihy',
        'domceky-pre-babiky' => 'Domčeky pre bábiky', 'smartgames' => 'Logické hry', 'fotoaparaty-pre-deti' => 'Fotoaparáty pre deti',
        'magneticke-stavebnice' => 'Magnetické stavebnice', 'karaoke' => 'Slúchadlá a karaoke', 'kreativne-a-tvorive' => 'Kreatívne a tvorivé',
        'babiky' => 'Bábiky', 'lego' => 'Lego', 'auticka-a-vlaciky' => 'Autíčka a vláčiky', 'figurky-postavicky' => 'Figúrky, postavičky',
        'skolske-tasky-1-3' => 'Školské tašky 1.–3. trieda', 'skolske-tasky-4-7' => 'Školské tašky 4.–7. trieda', 'ruksaciky-do-skolky' => 'Ruksačiky do škôlky',
        'peracniky' => 'Peračníky', 'pisacie-potreby' => 'Písacie potreby', 'zapisniky-diare' => 'Zápisníky, diáre',
        'teepee' => 'Teepee stany', 'nalepky-na-stenu' => 'Nálepky na stenu', 'suche-bazeny' => 'Suché bazény', 'doplnky-a-dekoracie-do-izbicky' => 'Dekorácie do izbičky',
        'prilby' => 'Prilby', 'bicykle' => 'Bicykle', 'odrazadla' => 'Odrážadlá', 'kolobezky' => 'Kolobežky', 'trojkolky' => 'Trojkolky', 'zvonceky' => 'Zvončeky na bicykel',
        'kvido' => 'Kvído', 'tetovacky' => 'Tetovačky', 'detske-dazdniky' => 'Dáždniky', 'knihy-pre-deti' => 'Knihy pre deti', 'spolocenske-hry' => 'Spoločenské hry',
        'kuzelne-citanie' => 'Kúzelné čítanie', 'vianocna-kolekcia' => 'Vianočná kolekcia', 'pre-male-paradnice' => 'Pre malé parádnice', 'darcekove-poukazky' => 'Darčekové poukážky',
    ];

    /** Na testy: funkcia(path) => html|null */
    public static $fetch = null;
    private static int $requests = 0;

    public static function get(string $path): ?string
    {
        self::$requests++;
        if (is_callable(self::$fetch)) return (self::$fetch)($path);
        usleep(120000); // šetrne k serveru e-shopu
        $ch = curl_init(self::BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 25, CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (DarcekovyPoradca/' . PORADCA_VERSION . '; +noelo.sk)',
        ]);
        $html = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        if (!is_string($html) || $code !== 200 || str_contains($final, '/404')) return null;
        return $html;
    }

    private static function dom(string $html): DOMXPath
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        return new DOMXPath($doc);
    }

    /** Produkty z jednej stránky zoznamu kategórie. */
    public static function parseListing(string $html): array
    {
        $x = self::dom($html);
        $out = [];
        foreach ($x->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' lis ')]/a[@href]") as $a) {
            /** @var DOMElement $a */
            $id = trim((string)$x->evaluate("string(.//input[@name='product-id']/@value)", $a));
            if ($id === '') continue;
            $priceTxt = trim((string)$x->evaluate("string(.//div[@class='p'])", $a));
            $out[] = [
                'id' => $id,
                'slug' => ltrim((string)$a->getAttribute('href'), '/'),
                'name' => trim(preg_replace('/\s+/u', ' ', (string)$x->evaluate("string(.//div[@class='t'])", $a)) ?? ''),
                'price' => preg_match('/(\d+(?:[.,]\d+)?)/', $priceTxt, $m) ? (float)str_replace(',', '.', $m[1]) : null,
                'availability' => trim((string)$x->evaluate("string(.//div[contains(@class,'avl')])", $a)),
                'image' => (string)$x->evaluate("string(.//img/@src)", $a),
            ];
        }
        return $out;
    }

    /** Údaje z produktovej stránky. */
    public static function parseDetail(string $html): array
    {
        $name = preg_match('~<h1[^>]*>(.*?)</h1>~su', $html, $m) ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
        $desc = preg_match('~property=["\']og:description["\']\s+content=(["\'])(.*?)\1~su', $html, $m) ? html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
        $brand = '';
        if (preg_match('~Značka:\s*(?:<[^>]*>\s*)*([^<\n]{2,50}?)\s*(?:<|Kód)~u', $html, $m)) $brand = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\xC2\xA0");
        $price = preg_match('~product:price:amount["\']\s+content=["\']([\d.]+)~', $html, $m) ? (float)$m[1] : null;
        $avail = '';
        if (($i = mb_strpos($html, 'Dostupnosť')) !== false) {
            $t = html_entity_decode(strip_tags(mb_substr($html, $i, 400)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $t = trim(preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', $t)) ?? '');
            if (preg_match('~Dostupnosť:?\s*(.+?)(?:\.|Značka|$)~u', $t, $mm)) $avail = trim($mm[1]);
        }
        return ['name' => preg_replace('/\s+/u', ' ', $name) ?? '', 'brand' => $brand, 'description' => trim(preg_replace('/\s+/u', ' ', $desc) ?? ''), 'price' => $price, 'availability' => $avail];
    }

    /**
     * Prejde kategórie a uloží zoznam produktov. Pokračuje tam, kde minule skončilo (časový limit hostingu).
     * @return bool true = celý cyklus (všetky kategórie) je hotový
     */
    public static function crawlListings(?callable $log = null, int $deadline = 0): bool
    {
        $pdo = db();
        $cats = (array)(config('crawl.categories') ?: self::CATEGORIES);
        $slugs = array_keys($cats);
        $cycle = Settings::get('crawl_cycle');
        if ($cycle === '') { $cycle = now(); Settings::set('crawl_cycle', $cycle); Settings::set('crawl_cat_index', '0'); }
        $idx = (int)Settings::get('crawl_cat_index');
        $sel = $pdo->prepare('SELECT seen_at, categories FROM crawl_items WHERE id = ?');
        $up = $pdo->prepare('INSERT INTO crawl_items(id, slug, list_name, price, availability_text, image, categories, seen_at) VALUES(?,?,?,?,?,?,?,?)
            ON CONFLICT(id) DO UPDATE SET slug=excluded.slug, list_name=excluded.list_name, price=excluded.price,
            availability_text=excluded.availability_text, image=excluded.image, categories=excluded.categories, seen_at=excluded.seen_at');
        for (; $idx < count($slugs); $idx++) {
            if ($deadline && time() >= $deadline) { Settings::set('crawl_cat_index', (string)$idx); return false; }
            $slug = $slugs[$idx];
            $catName = $cats[$slug];
            $found = [];
            for ($page = 1; $page <= 80; $page++) {
                $html = self::get($page === 1 ? '/' . $slug : '/kategoria-' . $slug . '/strana-' . $page);
                if ($html === null) break;
                $items = self::parseListing($html);
                $new = 0;
                foreach ($items as $it) if (!isset($found[$it['id']])) { $found[$it['id']] = $it; $new++; }
                if (!$items || $new === 0) break;   // koniec kategórie (stránkovanie sa opakuje)
            }
            $pdo->beginTransaction();
            foreach ($found as $id => $it) {
                $sel->execute([$id]);
                $old = $sel->fetch();
                $list = ($old && $old['seen_at'] === $cycle && $old['categories'] !== '') ? explode('|', $old['categories']) : [];
                if (!in_array($catName, $list, true)) $list[] = $catName;
                $up->execute([$id, $it['slug'], $it['name'], $it['price'], $it['availability'], $it['image'], implode('|', $list), $cycle]);
            }
            $pdo->commit();
            if ($log) $log("  $catName: " . count($found) . ' produktov');
            Settings::set('crawl_cat_index', (string)($idx + 1));
        }
        Settings::set('crawl_last_listing', $cycle);
        Settings::set('crawl_cycle', '');
        return true;
    }

    /** Doplní detaily (plný názov, značka, popis) – najprv nové produkty, potom najstaršie. */
    public static function crawlDetails(int $max = 400, ?callable $log = null, int $deadline = 0): int
    {
        $pdo = db();
        $rows = $pdo->query("SELECT id, slug FROM crawl_items WHERE detail_at IS NULL OR detail_at < '" . date('Y-m-d H:i:s', strtotime('-7 days')) . "'
            ORDER BY detail_at IS NOT NULL, detail_at LIMIT " . max(1, $max))->fetchAll();
        $upd = $pdo->prepare('UPDATE crawl_items SET full_name = ?, brand = ?, description = ?, price = COALESCE(?, price), availability_text = CASE WHEN ? <> \'\' THEN ? ELSE availability_text END, detail_at = ? WHERE id = ?');
        $done = 0;
        foreach ($rows as $r) {
            if ($deadline && time() >= $deadline) break;
            $html = self::get('/' . $r['slug']);
            if ($html === null) {
                $pdo->prepare('UPDATE crawl_items SET detail_at = ? WHERE id = ?')->execute([now(), $r['id']]);
                continue;
            }
            $d = self::parseDetail($html);
            $upd->execute([$d['name'], $d['brand'], mb_substr($d['description'], 0, 1500), $d['price'], $d['availability'], $d['availability'], now(), $r['id']]);
            $done++;
            if ($log && $done % 100 === 0) $log("  detaily: $done / " . count($rows));
        }
        return $done;
    }

    /**
     * Spustí načítanie a vráti produkty vo formáte pre FeedImporter.
     * $seconds = časový limit (0 = bez limitu, napr. cron cez príkazový riadok).
     */
    public static function run(?callable $log = null, int $detailLimit = 400, int $seconds = 0): Generator
    {
        $deadline = $seconds > 0 ? time() + $seconds : 0;
        $last = Settings::get('crawl_last_listing');
        $hours = (int)(config('crawl.listing_every_hours') ?: 3);
        $inProgress = Settings::get('crawl_cycle') !== '';
        if ($inProgress || $last === '' || strtotime($last) < time() - $hours * 3600) {
            if ($log) $log($inProgress ? 'Pokračujem v načítaní kategórií z noelo.sk…' : 'Načítavam zoznamy kategórií z noelo.sk…');
            $done = self::crawlListings($log, $deadline);
            if ($log) $log($done ? 'Všetky kategórie sú načítané (' . self::$requests . ' stránok).' : 'Časový limit – pokračujem pri ďalšom spustení.');
        }
        $d = self::crawlDetails($detailLimit, $log, $deadline);
        if ($log) $log("Doplnené detaily: $d produktov.");
        return self::items();
    }

    public static function items(): Generator
    {
        $since = Settings::get('crawl_last_listing') ?: '2000-01-01';   // posledný úplný cyklus (staršie = už nie sú na webe)
        $st = db()->prepare('SELECT * FROM crawl_items WHERE seen_at >= ?');
        $st->execute([$since]);
        foreach ($st as $r) {
            $cats = $r['categories'] === '' ? [] : explode('|', $r['categories']);
            // Bestsellery, ktoré e-shop vkladá do každej kategórie, by mali desiatky kategórií – tie nezapočítame
            $category = count($cats) > 8 ? '' : implode(', ', $cats);
            $avail = mb_strtolower(trim($r['availability_text']));
            $soldOut = str_contains($avail, 'vypredan') || str_contains($avail, 'nedostupn');
            $text = $avail === '' ? '' : (preg_match('/^\d{1,2}\.\d{1,2}\./', $avail) ? 'dostupné od ' . $avail : $r['availability_text']);
            $brand = $r['brand'];
            $name = $r['full_name'] !== '' ? $r['full_name'] : rtrim($r['list_name'], '. …');
            yield [
                'id' => $r['id'], 'name' => $name, 'brand' => $brand, 'category' => $category,
                'description' => $r['description'], 'url' => self::BASE . '/' . $r['slug'],
                'image' => $r['image'] !== '' ? self::BASE . $r['image'] : '',
                'price' => $r['price'] !== null ? (float)$r['price'] : null, 'cost' => null,
                'available' => !$soldOut, 'availability_text' => $soldOut ? 'vypredané' : $text,
                'item_group_id' => '', 'hint_gender' => '',
            ];
        }
    }
}
