<?php
declare(strict_types=1);

/**
 * Import produktov z Google Merchant feedu (Atom aj RSS 2.0) alebo z JSON exportu.
 *
 * Bezpečnostné poistky:
 *  - feed sa číta prúdovo (XMLReader), zvládne aj desaťtisíce produktov,
 *  - ak má feed menej ako 30 % produktov oproti minulému importu, produkty sa NEdeaktivujú
 *    (pravdepodobne chybný alebo neúplný feed),
 *  - nákupná cena (cost_of_goods_sold) sa ukladá len pre admin.
 */
final class FeedImporter
{
    private const AVAIL_TEXT = [
        'in stock' => 'skladom',
        'in_stock' => 'skladom',
        'out of stock' => 'vypredané',
        'out_of_stock' => 'vypredané',
        'preorder' => 'predobjednávka',
        'backorder' => 'na objednávku',
    ];

    public static function run(?string $localFile = null, ?callable $log = null, int $seconds = 0): array
    {
        $pdo = db();
        $pdo->prepare('INSERT INTO import_runs(started_at, status) VALUES(?, ?)')->execute([now(), 'running']);
        $runId = (int)$pdo->lastInsertId();
        $lastModified = null;
        $tmp = null;

        try {
            $type = (string)(config('feed.type') ?: 'google_merchant');
            if ($type === 'crawl' && $localFile === null) {
                // Produkty priamo z webu noelo.sk (feed je zastaraný)
                $items = Crawler::run($log, (int)(config('crawl.details_per_run') ?: 400), $seconds);
                $lastModified = now();
            } else {
                if ($localFile === null) {
                    [$tmp, $lastModified] = self::download((string)config('feed.url'));
                    $file = $tmp;
                } else {
                    $file = $localFile;
                    $lastModified = date('Y-m-d H:i:s', (int)filemtime($file));
                }
                $items = $type === 'json' ? self::readJson($file) : self::readGoogleMerchant($file);
            }
            $stats = self::store($items);

            $msg = '';
            if ($lastModified && strtotime($lastModified) < time() - 3600 * (int)(config('feed.max_age_hours') ?: 48)) {
                $msg = 'Pozor: feed bol naposledy vygenerovaný ' . date('j. n. Y', strtotime($lastModified)) . '. Údaje môžu byť zastarané.';
            }
            if ($stats['skipped_deactivation']) {
                $msg = trim($msg . ' Feed obsahoval podozrivo málo produktov, staré produkty sme preto nevypli.');
            }
            $pdo->prepare('UPDATE import_runs SET finished_at=?, status=?, total=?, available=?, deactivated=?, feed_last_modified=?, message=? WHERE id=?')
                ->execute([now(), 'ok', $stats['total'], $stats['available'], $stats['deactivated'], $lastModified, $msg, $runId]);
            Catalog::clearCache();
            return $stats + ['message' => $msg, 'feed_last_modified' => $lastModified];
        } catch (Throwable $e) {
            $pdo->prepare('UPDATE import_runs SET finished_at=?, status=?, message=? WHERE id=?')
                ->execute([now(), 'error', $e->getMessage(), $runId]);
            throw $e;
        } finally {
            if ($tmp && is_file($tmp)) @unlink($tmp);
        }
    }

    /** @return array{0:string,1:?string} [cesta k súboru, last-modified] */
    private static function download(string $url): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'feed');
        $fh = fopen($tmp, 'wb');
        $lastModified = null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'DarcekovyPoradca/' . PORADCA_VERSION,
            CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$lastModified) {
                if (stripos($header, 'last-modified:') === 0) {
                    $ts = strtotime(trim(substr($header, 14)));
                    if ($ts) $lastModified = date('Y-m-d H:i:s', $ts);
                }
                return strlen($header);
            },
        ]);
        if (config('feed.http_user')) {
            curl_setopt($ch, CURLOPT_USERPWD, config('feed.http_user') . ':' . config('feed.http_pass'));
        }
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fh);
        if (!$ok || $code !== 200) {
            @unlink($tmp);
            throw new RuntimeException("Feed sa nepodarilo stiahnuť (HTTP $code) $err");
        }
        return [$tmp, $lastModified];
    }

    /** Prúdové čítanie Google Merchant XML (Atom <entry> aj RSS <item>). */
    public static function readGoogleMerchant(string $file): Generator
    {
        $r = new XMLReader();
        if (!$r->open($file, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOCDATA)) {
            throw new RuntimeException('Feed nie je platné XML.');
        }
        while ($r->read()) {
            if ($r->nodeType !== XMLReader::ELEMENT || !in_array($r->localName, ['entry', 'item'], true)) continue;
            $xml = $r->readOuterXml();
            $node = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
            if (!$node) continue;
            $g = $node->children('http://base.google.com/ns/1.0');
            $plain = $node->children();
            // Atom: <entry xmlns="http://www.w3.org/2005/Atom">
            $atom = $node->children('http://www.w3.org/2005/Atom');
            $get = function (string $name) use ($g, $plain, $atom): string {
                foreach ([$g, $plain, $atom] as $src) {
                    if (isset($src->$name) && trim((string)$src->$name) !== '') return trim((string)$src->$name);
                }
                return '';
            };
            $link = $get('link');
            if ($link === '') {
                foreach ([$atom, $plain] as $src) {
                    if (isset($src->link) && isset($src->link['href'])) { $link = (string)$src->link['href']; break; }
                }
            }
            $price = self::money($get('price'));
            $sale = self::money($get('sale_price'));
            if ($sale !== null && ($price === null || $sale < $price)) $price = $sale;
            $avail = strtolower($get('availability'));
            $gender = strtolower($get('gender'));
            yield [
                'id' => $get('id'),
                'name' => html_entity_decode($get('title'), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'brand' => html_entity_decode($get('brand'), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'category' => html_entity_decode($get('product_type'), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'description' => trim(html_entity_decode(strip_tags($get('description')), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                'url' => $link,
                'image' => $get('image_link'),
                'price' => $price,
                'cost' => self::money($get('cost_of_goods_sold')),
                'available' => in_array($avail, ['in stock', 'in_stock', 'preorder', 'backorder'], true),
                'availability_text' => self::AVAIL_TEXT[$avail] ?? $avail,
                'item_group_id' => $get('item_group_id'),
                'hint_gender' => $gender === 'female' ? 'dievča' : ($gender === 'male' ? 'chlapec' : ''),
            ];
        }
        $r->close();
    }

    /**
     * JSON export z databázy e-shopu: pole objektov
     * {id, name, brand, category, description, url, image, price, cost?, available, availability_text?}
     */
    public static function readJson(string $file): Generator
    {
        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data)) throw new RuntimeException('JSON export nie je platný.');
        foreach ($data as $p) {
            yield [
                'id' => (string)($p['id'] ?? ''),
                'name' => (string)($p['name'] ?? ''),
                'brand' => (string)($p['brand'] ?? ''),
                'category' => (string)($p['category'] ?? ''),
                'description' => trim(strip_tags((string)($p['description'] ?? ''))),
                'url' => (string)($p['url'] ?? ''),
                'image' => (string)($p['image'] ?? ''),
                'price' => isset($p['price']) ? (float)$p['price'] : null,
                'cost' => isset($p['cost']) ? (float)$p['cost'] : null,
                'available' => (bool)($p['available'] ?? false),
                'availability_text' => (string)($p['availability_text'] ?? (!empty($p['available']) ? 'skladom' : 'vypredané')),
                'item_group_id' => (string)($p['item_group_id'] ?? ''),
                'hint_gender' => '',
            ];
        }
    }

    private static function money(string $v): ?float
    {
        if ($v === '') return null;
        if (!preg_match('/(\d+(?:[.,]\d+)?)/', $v, $m)) return null;
        return (float)str_replace(',', '.', $m[1]);
    }

    private static function store(iterable $items): array
    {
        $pdo = db();
        $shop = rtrim((string)config('shop_url'), '/');
        $prevActive = (int)$pdo->query('SELECT COUNT(*) FROM products WHERE active = 1')->fetchColumn();
        $stamp = now();

        $sel = $pdo->prepare('SELECT source_hash FROM products WHERE id = ?');
        $ins = $pdo->prepare('INSERT INTO products(id, name, brand, category, description, url, image, price, cost, available, availability_text,
                item_group_id, active, source_hash, first_seen_at, updated_at, gender, search_text)
            VALUES(:id,:name,:brand,:category,:description,:url,:image,:price,:cost,:available,:availability_text,:item_group_id,1,:hash,:now,:now,:gender,:search)');
        $upd = $pdo->prepare('UPDATE products SET name=:name, brand=:brand, category=:category, description=:description, url=:url, image=:image,
                price=:price, cost=:cost, available=:available, availability_text=:availability_text, item_group_id=:item_group_id,
                active=1, source_hash=:hash, updated_at=:now, search_text=:search WHERE id=:id');
        $touch = $pdo->prepare('UPDATE products SET active = 1, updated_at = ? WHERE id = ?');

        $total = 0; $available = 0;
        $pdo->beginTransaction();
        foreach ($items as $it) {
            if ($it['id'] === '' || $it['name'] === '') continue;
            $total++;
            if ($it['available']) $available++;
            foreach (['url', 'image'] as $k) {
                if ($it[$k] !== '' && !preg_match('~^https?://~', $it[$k])) $it[$k] = $shop . '/' . ltrim($it[$k], '/');
            }
            $hash = md5(json_encode([$it['name'], $it['brand'], $it['category'], $it['description'], $it['url'], $it['image'],
                $it['price'], $it['cost'], $it['available'], $it['availability_text'], $it['item_group_id']]));
            $sel->execute([$it['id']]);
            $old = $sel->fetchColumn();
            $params = [
                ':id' => $it['id'], ':name' => $it['name'], ':brand' => $it['brand'], ':category' => $it['category'],
                ':description' => mb_substr($it['description'], 0, 2000), ':url' => $it['url'], ':image' => $it['image'],
                ':price' => $it['price'], ':cost' => $it['cost'], ':available' => $it['available'] ? 1 : 0,
                ':availability_text' => $it['availability_text'], ':item_group_id' => $it['item_group_id'],
                ':hash' => $hash, ':now' => $stamp,
                ':search' => normalize_text($it['name'] . ' ' . $it['brand'] . ' ' . $it['category'] . ' ' . mb_substr($it['description'], 0, 600)),
            ];
            if ($old === false) {
                $ins->execute($params + [':gender' => $it['hint_gender'] ?: null]);
            } elseif ($old !== $hash) {
                $upd->execute($params);
            } else {
                $touch->execute([$stamp, $it['id']]);
            }
            if ($total % 500 === 0) { $pdo->commit(); $pdo->beginTransaction(); }
        }
        $pdo->commit();

        $deactivated = 0;
        $skipped = false;
        if ($total > 0 && ($prevActive === 0 || $total >= 0.3 * $prevActive)) {
            $st = $pdo->prepare('UPDATE products SET active = 0 WHERE active = 1 AND updated_at < ?');
            $st->execute([$stamp]);
            $deactivated = $st->rowCount();
        } else {
            $skipped = $prevActive > 0;
        }
        return ['total' => $total, 'available' => $available, 'deactivated' => $deactivated, 'skipped_deactivation' => $skipped];
    }
}
