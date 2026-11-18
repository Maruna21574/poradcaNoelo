<?php
declare(strict_types=1);

/**
 * Katalóg produktov: vyhľadávanie pre AI, zvýhodnenia a vylúčenia z adminu.
 *
 * Vyhľadávanie je zámerne jednoduché a predvídateľné: AI zadá hľadané výrazy a filtre (vek, pre koho,
 * cena) a katalóg vráti najvhodnejších kandidátov. AI potom vyberá spomedzi nich.
 */
final class Catalog
{
    private const STOPWORDS = ['a','aj','alebo','do','na','pre','s','so','z','zo','od','v','vo','k','ku','o','po','pri','za',
        'rok','roky','rokov','rocne','rocny','rocnu','rocneho','rocnej','mesiac','mesiacov','dieta','deti','detsky','detska','detske',
        'darcek','darceky','darceka','nieco','nejaky','nejaku','chcem','hladam','eur','euro','max','cca','kus','ks'];

    /** @var array<int,array>|null */
    private static ?array $cache = null;

    /** Načíta dostupné produkty aj so zvýhodneniami (raz za požiadavku). */
    public static function available(): array
    {
        if (self::$cache !== null) return self::$cache;
        $today = date('Y-m-d');
        $rows = db()->query('
            SELECT p.id, p.name, p.brand, p.category, p.description, p.url, p.image, p.price, p.availability_text,
                   p.age_min, p.age_max, p.gender, p.interests, p.gift_score, p.search_text, p.first_seen_at,
                   o.boost AS o_boost, o.boost_until AS o_until, o.ai_note, o.excluded,
                   o.age_min AS o_age_min, o.age_max AS o_age_max, o.gender AS o_gender
            FROM products p LEFT JOIN product_overrides o ON o.product_id = p.id
            WHERE p.active = 1 AND p.available = 1 AND p.price IS NOT NULL
        ')->fetchAll();
        $rules = db()->query('SELECT kind, value, boost, until FROM boost_rules')->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            if ((int)($r['excluded'] ?? 0) === 1) continue;
            $boost = 0;
            if ($r['o_boost'] !== null && ($r['o_until'] === null || $r['o_until'] === '' || $r['o_until'] >= $today)) {
                $boost = (int)$r['o_boost'];
            }
            foreach ($rules as $rule) {
                if ($rule['until'] && $rule['until'] < $today) continue;
                $hit = $rule['kind'] === 'brand'
                    ? normalize_text($r['brand']) === normalize_text($rule['value'])
                    : str_contains(normalize_text($r['category']), normalize_text($rule['value']));
                if ($hit) $boost = max($boost, (int)$rule['boost']);
            }
            $out[] = [
                'id' => (string)$r['id'],
                'name' => $r['name'],
                'brand' => $r['brand'],
                'category' => $r['category'],
                'description' => $r['description'],
                'url' => $r['url'],
                'image' => $r['image'],
                'price' => (float)$r['price'],
                'availability' => $r['availability_text'],
                'age_min' => $r['o_age_min'] ?? $r['age_min'],
                'age_max' => $r['o_age_max'] ?? $r['age_max'],
                'gender' => $r['o_gender'] ?: ($r['gender'] ?: 'unisex'),
                'interests' => $r['interests'],
                'gift_score' => $r['gift_score'] === null ? null : (int)$r['gift_score'],
                'ai_note' => (string)($r['ai_note'] ?? ''),
                'boost' => $boost,
                'search_text' => $r['search_text'],
                'first_seen_at' => $r['first_seen_at'],
            ];
        }
        return self::$cache = $out;
    }

    public static function clearCache(): void
    {
        self::$cache = null;
    }

    public static function find(string $id): ?array
    {
        foreach (self::available() as $p) if ($p['id'] === $id) return $p;
        return null;
    }

    /** Rozloží dopyt na kmene slov (jednoduché skloňovanie v slovenčine). */
    public static function stems(string $query): array
    {
        $stems = [];
        foreach (explode(' ', normalize_text($query)) as $t) {
            if ($t === '' || in_array($t, self::STOPWORDS, true) || ctype_digit($t)) continue;
            $len = strlen($t);
            $stems[] = $len >= 6 ? substr($t, 0, $len - 2) : ($len === 5 ? substr($t, 0, 4) : $t);
        }
        return array_values(array_unique($stems));
    }

    /**
     * Vyhľadá produkty.
     * @param array{hladane_vyrazy?:string|array,vek?:int|null,pre?:string|null,cena_od?:float|null,cena_do?:float|null,kategoria?:string|null,limit?:int} $q
     */
    public static function search(array $q): array
    {
        $terms = $q['hladane_vyrazy'] ?? '';
        if (is_array($terms)) $terms = implode(' ', $terms);
        $stems = self::stems((string)$terms);
        $age = isset($q['vek']) && $q['vek'] !== '' && $q['vek'] !== null ? (float)$q['vek'] : null;
        $gender = isset($q['pre']) ? normalize_text((string)$q['pre']) : '';
        $gender = str_starts_with($gender, 'diev') ? 'dievča' : (str_starts_with($gender, 'chlap') ? 'chlapec' : '');
        $min = isset($q['cena_od']) && $q['cena_od'] !== null ? (float)$q['cena_od'] : null;
        $max = isset($q['cena_do']) && $q['cena_do'] !== null ? (float)$q['cena_do'] : null;
        $cat = isset($q['kategoria']) ? normalize_text((string)$q['kategoria']) : '';
        $limit = max(1, min(30, (int)($q['limit'] ?? 20)));

        $results = [];
        foreach (self::available() as $p) {
            if ($min !== null && $p['price'] < $min) continue;
            if ($max !== null && $p['price'] > $max) continue;
            if ($cat !== '' && !str_contains(normalize_text($p['category']), $cat)) continue;
            if ($gender !== '' && $p['gender'] !== 'unisex' && $p['gender'] !== $gender) continue;

            $ageFactor = 1.0;
            if ($age !== null) {
                $amin = $p['age_min'];
                $amax = $p['age_max'];
                if ($amin !== null && $age < (float)$amin) continue;               // produkt pre staršie deti – bezpečnosť
                if ($amax !== null && $age > (float)$amax + 1) continue;           // produkt pre menšie deti
                if ($amin === null && $amax === null) $ageFactor = 0.85;           // vek neznámy – mierne penalizujeme
            }
            if ($p['gift_score'] === 0) $ageFactor *= 0.3;                         // spotrebný tovar (plienky…)

            $score = 1.0;
            if ($stems) {
                $words = explode(' ', $p['search_text']);
                $nameWords = explode(' ', normalize_text($p['name']));
                $tagWords = explode(' ', normalize_text($p['brand'] . ' ' . $p['category'] . ' ' . $p['interests']));
                $score = 0.0;
                $matched = 0;
                foreach ($stems as $s) {
                    $w = 0;
                    if (self::anyStartsWith($nameWords, $s)) $w = 3;
                    elseif (self::anyStartsWith($tagWords, $s)) $w = 2;
                    elseif (self::anyStartsWith($words, $s)) $w = 1;
                    if ($w) { $score += $w; $matched++; }
                }
                if ($matched === 0) continue;
                $score *= 0.5 + 0.5 * ($matched / count($stems));
            } else {
                $score = 1 + (int)($p['gift_score'] ?? 1);
            }
            $score *= $ageFactor;
            $score = $score * (1 + 0.35 * $p['boost']) + 0.5 * $p['boost'];
            $p['_score'] = round($score, 3);
            $results[] = $p;
        }
        usort($results, fn($a, $b) => [$b['_score'], $b['first_seen_at']] <=> [$a['_score'], $a['first_seen_at']]);
        return array_slice($results, 0, $limit);
    }

    private static function anyStartsWith(array $words, string $stem): bool
    {
        foreach ($words as $w) if ($w !== '' && str_starts_with($w, $stem)) return true;
        return false;
    }

    /** Krátky textový zápis produktu pre AI (bez nákupnej ceny a interných údajov). */
    public static function forAi(array $p): string
    {
        $age = $p['age_min'] === null && $p['age_max'] === null ? 'vek ?' :
            'vek ' . ($p['age_min'] ?? 0) . '–' . ($p['age_max'] ?? '');
        $parts = [
            'id ' . $p['id'],
            $p['name'],
            $p['brand'] ?: '-',
            number_format($p['price'], 2, '.', '') . ' €',
            $age,
            'pre: ' . $p['gender'],
            $p['category'] ?: '-',
        ];
        if ($p['interests']) $parts[] = 'záujmy: ' . $p['interests'];
        if ($p['availability']) $parts[] = 'dostupnosť: ' . $p['availability'];
        if ($p['boost'] > 0) $parts[] = 'ODPORÚČA OBCHOD' . ($p['boost'] >= 2 ? ' (silno)' : '');
        if ($p['ai_note'] !== '') $parts[] = 'poznámka obchodu: ' . $p['ai_note'];
        $desc = trim(mb_substr(preg_replace('/\s+/', ' ', strip_tags($p['description'])) ?? '', 0, 160));
        if ($desc !== '') $parts[] = 'popis: ' . $desc;
        return implode(' | ', $parts);
    }

    /** Údaje pre kartu vo widgete. */
    public static function forWidget(array $p, string $why): array
    {
        return [
            'id' => $p['id'],
            'name' => $p['name'],
            'brand' => $p['brand'],
            'price' => $p['price'],
            'url' => $p['url'],
            'image' => $p['image'],
            'availability' => $p['availability'],
            'why' => $why,
        ];
    }
}
