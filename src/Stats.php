<?php
declare(strict_types=1);

/**
 * Štatistiky pre admin.
 */
final class Stats
{
    public static function since(int $days): string
    {
        return date('Y-m-d 00:00:00', strtotime('-' . max(0, $days - 1) . ' days'));
    }

    public static function kpis(int $days): array
    {
        $s = self::since($days);
        $pdo = db();
        $one = function (string $sql, array $p = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($p); return (float)$st->fetchColumn(); };
        $conv = $one('SELECT COUNT(*) FROM conversations WHERE created_at >= ? AND turns > 0', [$s]);
        $withRecs = $one('SELECT COUNT(DISTINCT conversation_id) FROM recommendations WHERE created_at >= ?', [$s]);
        $withClick = $one("SELECT COUNT(DISTINCT conversation_id) FROM events WHERE type = 'click' AND created_at >= ?", [$s]);
        $withCart = $one("SELECT COUNT(DISTINCT conversation_id) FROM events WHERE type = 'add_to_cart' AND created_at >= ?", [$s]);
        return [
            'conversations' => (int)$conv,
            'messages' => (int)$one("SELECT COUNT(*) FROM messages WHERE role = 'user' AND created_at >= ?", [$s]),
            'with_recs' => (int)$withRecs,
            'recommendations' => (int)$one('SELECT COUNT(*) FROM recommendations WHERE created_at >= ?', [$s]),
            'clicks' => (int)$one("SELECT COUNT(*) FROM events WHERE type = 'click' AND created_at >= ?", [$s]),
            'carts' => (int)$one("SELECT COUNT(*) FROM events WHERE type = 'add_to_cart' AND created_at >= ?", [$s]),
            'ctr' => $withRecs > 0 ? round(100 * $withClick / $withRecs, 1) : 0,
            'cart_rate' => $withRecs > 0 ? round(100 * $withCart / $withRecs, 1) : 0,
            'unmet' => (int)$one("SELECT COUNT(*) FROM conversations WHERE unmet <> '' AND created_at >= ?", [$s]),
            'avg_turns' => round($one('SELECT AVG(turns) FROM conversations WHERE created_at >= ? AND turns > 0', [$s]), 1),
            'tokens' => (int)$one('SELECT COALESCE(SUM(tokens_in + tokens_out), 0) FROM messages WHERE created_at >= ?', [$s]),
            'rating_up' => (int)$one('SELECT COUNT(*) FROM conversations WHERE rating = 1 AND created_at >= ?', [$s]),
            'rating_down' => (int)$one('SELECT COUNT(*) FROM conversations WHERE rating = -1 AND created_at >= ?', [$s]),
        ];
    }

    public static function daily(int $days): array
    {
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) $out[date('Y-m-d', strtotime("-$i days"))] = ['conversations' => 0, 'clicks' => 0];
        $st = db()->prepare("SELECT substr(created_at,1,10) d, COUNT(*) c FROM conversations WHERE created_at >= ? AND turns > 0 GROUP BY d");
        $st->execute([self::since($days)]);
        foreach ($st->fetchAll() as $r) if (isset($out[$r['d']])) $out[$r['d']]['conversations'] = (int)$r['c'];
        $st = db()->prepare("SELECT substr(created_at,1,10) d, COUNT(*) c FROM events WHERE type='click' AND created_at >= ? GROUP BY d");
        $st->execute([self::since($days)]);
        foreach ($st->fetchAll() as $r) if (isset($out[$r['d']])) $out[$r['d']]['clicks'] = (int)$r['c'];
        return $out;
    }

    /** Rozdelenie podľa veku, pohlavia, rozpočtu, záujmov a príležitosti. */
    public static function profiles(int $days): array
    {
        $st = db()->prepare("SELECT profile_json FROM conversations WHERE created_at >= ? AND turns > 0");
        $st->execute([self::since($days)]);
        $age = ['bábätko (0–1)' => 0, '1–3 roky' => 0, '3–5 rokov' => 0, '6–8 rokov' => 0, '9–12 rokov' => 0, '13+ rokov' => 0];
        $gender = ['dievča' => 0, 'chlapec' => 0, 'nezáleží' => 0];
        $budget = ['do 20 €' => 0, '20–40 €' => 0, '40–70 €' => 0, '70–120 €' => 0, 'nad 120 €' => 0];
        $interests = [];
        $occasion = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $p = json_decode((string)$json, true) ?: [];
            if (isset($p['vek'])) {
                $v = (float)$p['vek'];
                $k = $v < 1 ? 'bábätko (0–1)' : ($v < 3 ? '1–3 roky' : ($v < 6 ? '3–5 rokov' : ($v < 9 ? '6–8 rokov' : ($v < 13 ? '9–12 rokov' : '13+ rokov'))));
                $age[$k]++;
            }
            if (!empty($p['pre']) && isset($gender[$p['pre']])) $gender[$p['pre']]++;
            if (!empty($p['rozpocet_do'])) {
                $b = (float)$p['rozpocet_do'];
                $k = $b <= 20 ? 'do 20 €' : ($b <= 40 ? '20–40 €' : ($b <= 70 ? '40–70 €' : ($b <= 120 ? '70–120 €' : 'nad 120 €')));
                $budget[$k]++;
            }
            foreach ((array)($p['zaujmy'] ?? []) as $z) {
                $z = mb_strtolower(trim((string)$z));
                if ($z !== '') $interests[$z] = ($interests[$z] ?? 0) + 1;
            }
            if (!empty($p['prilezitost'])) {
                $o = mb_strtolower(trim((string)$p['prilezitost']));
                $occasion[$o] = ($occasion[$o] ?? 0) + 1;
            }
        }
        arsort($interests); arsort($occasion);
        return ['age' => $age, 'gender' => $gender, 'budget' => $budget,
            'interests' => array_slice($interests, 0, 12, true), 'occasion' => array_slice($occasion, 0, 6, true)];
    }

    /** Čo AI najčastejšie hľadala v katalógu. */
    public static function searchTerms(int $days, int $limit = 15): array
    {
        $st = db()->prepare("SELECT searches_json FROM messages WHERE role='assistant' AND created_at >= ?");
        $st->execute([self::since($days)]);
        $terms = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $json) {
            foreach ((array)json_decode((string)$json, true) as $s) {
                $t = mb_strtolower(trim((string)($s['input']['hladane_vyrazy'] ?? '')));
                if ($t !== '') $terms[$t] = ($terms[$t] ?? 0) + 1;
            }
        }
        arsort($terms);
        return array_slice($terms, 0, $limit, true);
    }

    public static function topProducts(int $days, int $limit = 15): array
    {
        $st = db()->prepare("
            SELECT r.product_id, p.name, p.brand, p.price, p.image, p.url, COUNT(*) AS recs,
                   (SELECT COUNT(*) FROM events e WHERE e.product_id = r.product_id AND e.type = 'click' AND e.created_at >= :s) AS clicks,
                   (SELECT COUNT(*) FROM events e WHERE e.product_id = r.product_id AND e.type = 'add_to_cart' AND e.created_at >= :s) AS carts,
                   MAX(r.boosted) AS boosted
            FROM recommendations r LEFT JOIN products p ON p.id = r.product_id
            WHERE r.created_at >= :s
            GROUP BY r.product_id ORDER BY recs DESC LIMIT $limit");
        $st->execute([':s' => self::since($days)]);
        return $st->fetchAll();
    }

    /** Nesplnený dopyt – čo ľudia chceli a v ponuke nebolo. */
    public static function unmet(int $days): array
    {
        $st = db()->prepare("SELECT id, unmet, created_at FROM conversations WHERE unmet <> '' AND created_at >= ? ORDER BY created_at DESC");
        $st->execute([self::since($days)]);
        $groups = [];
        foreach ($st->fetchAll() as $r) {
            foreach (explode(' | ', $r['unmet']) as $u) {
                $u = trim($u);
                if ($u === '') continue;
                $key = normalize_text($u);
                if (!isset($groups[$key])) $groups[$key] = ['text' => $u, 'count' => 0, 'last' => $r['created_at'], 'conversations' => []];
                $groups[$key]['count']++;
                if (count($groups[$key]['conversations']) < 5) $groups[$key]['conversations'][] = $r['id'];
            }
        }
        uasort($groups, fn($a, $b) => [$b['count'], $b['last']] <=> [$a['count'], $a['last']]);
        return array_values($groups);
    }

    public static function boostPerformance(int $days): array
    {
        $s = self::since($days);
        $st = db()->prepare("
            SELECT r.boosted, COUNT(*) recs,
                   SUM(EXISTS(SELECT 1 FROM events e WHERE e.conversation_id = r.conversation_id AND e.product_id = r.product_id AND e.type='click')) clicks
            FROM recommendations r WHERE r.created_at >= ? GROUP BY r.boosted");
        $st->execute([$s]);
        $out = [0 => ['recs' => 0, 'clicks' => 0], 1 => ['recs' => 0, 'clicks' => 0]];
        foreach ($st->fetchAll() as $r) $out[(int)$r['boosted']] = ['recs' => (int)$r['recs'], 'clicks' => (int)$r['clicks']];
        return $out;
    }
}
