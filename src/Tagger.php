<?php
declare(strict_types=1);

/**
 * Automatické označenie produktov pomocou AI: vekové rozpätie, pre koho, záujmy, vhodnosť ako darček.
 * Spúšťa sa po importe (cron). Označí len nové alebo zmenené produkty, takže beží lacno.
 * Ručné opravy z adminu (product_overrides) majú vždy prednosť.
 */
final class Tagger
{
    private const BATCH = 40;

    public static function hashFor(array $p): string
    {
        return md5($p['name'] . '|' . $p['category'] . '|' . mb_substr($p['description'], 0, 400));
    }

    /** @return int počet označených produktov */
    public static function run(int $maxProducts = 2000, ?callable $log = null): int
    {
        $pdo = db();
        $rows = $pdo->query('SELECT id, name, brand, category, description, tags_hash FROM products WHERE active = 1 ORDER BY available DESC, updated_at DESC')->fetchAll();
        $todo = array_values(array_filter($rows, fn($p) => $p['tags_hash'] !== self::hashFor($p)));
        $todo = array_slice($todo, 0, $maxProducts);
        $done = 0;
        foreach (array_chunk($todo, self::BATCH) as $batch) {
            try {
                $tags = self::tagBatch($batch);
            } catch (Throwable $e) {
                if ($log) $log('Chyba pri označovaní: ' . $e->getMessage());
                break;
            }
            $upd = $pdo->prepare('UPDATE products SET age_min=?, age_max=?, gender=?, interests=?, gift_score=?, tags_hash=?, tagged_at=? WHERE id=?');
            $byId = [];
            foreach ($batch as $p) $byId[(string)$p['id']] = $p;
            foreach ($tags as $t) {
                $id = (string)($t['id'] ?? '');
                if (!isset($byId[$id])) continue;
                $min = isset($t['vek_od']) && is_numeric($t['vek_od']) ? max(0, min(18, (int)floor((float)$t['vek_od']))) : null;
                $max = isset($t['vek_do']) && is_numeric($t['vek_do']) ? max(0, min(99, (int)ceil((float)$t['vek_do']))) : null;
                if ($min !== null && $max !== null && $max < $min) $max = $min;
                $gender = in_array($t['pre'] ?? '', ['dievča', 'chlapec', 'unisex'], true) ? $t['pre'] : 'unisex';
                $interests = implode(', ', array_slice(array_map('strval', (array)($t['zaujmy'] ?? [])), 0, 6));
                $gift = isset($t['darcek']) ? max(0, min(3, (int)$t['darcek'])) : 1;
                $upd->execute([$min, $max, $gender, $interests, $gift, self::hashFor($byId[$id]), now(), $id]);
                $done++;
            }
            if ($log) $log("Označených $done / " . count($todo));
        }
        Catalog::clearCache();
        return $done;
    }

    private static function tagBatch(array $batch): array
    {
        $lines = [];
        foreach ($batch as $p) {
            $desc = mb_substr(preg_replace('/\s+/', ' ', $p['description']) ?? '', 0, 350);
            $lines[] = "id {$p['id']} | {$p['name']} | {$p['brand']} | {$p['category']} | $desc";
        }
        $res = Claude::messages([
            'model' => (string)config('model_tagger'),
            'max_tokens' => 4000,
            'system' => 'Si odborník na detské hračky a produkty. Ku každému produktu urči: vek_od a vek_do v rokoch (podľa popisu, inak podľa bežnej praxe; bábätko = 0; ak nie je horná hranica, odhadni rozumnú), pre koho (dievča, chlapec alebo unisex – „dievča“ či „chlapec“ len ak je produkt zjavne cielený), 1–5 záujmov po slovensky (napr. tvorenie, šperky, pohyb, stavanie, hudba, knihy, príroda, technika, hra na obchod, bábiky, vesmír, dinosaury) a „darcek“ 0–3 (0 = spotrebný tovar, nehodí sa ako darček; 3 = výborný darček). Odpovedz len nástrojom uloz_znacky.',
            'tools' => [[
                'name' => 'uloz_znacky',
                'description' => 'Uloží značky produktov.',
                'input_schema' => [
                    'type' => 'object',
                    'required' => ['produkty'],
                    'properties' => ['produkty' => ['type' => 'array', 'items' => [
                        'type' => 'object',
                        'required' => ['id', 'vek_od', 'pre', 'darcek'],
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'vek_od' => ['type' => 'number'],
                            'vek_do' => ['type' => 'number'],
                            'pre' => ['type' => 'string', 'enum' => ['dievča', 'chlapec', 'unisex']],
                            'zaujmy' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'darcek' => ['type' => 'integer'],
                        ],
                    ]]],
                ],
            ]],
            'tool_choice' => ['type' => 'tool', 'name' => 'uloz_znacky'],
            'messages' => [['role' => 'user', 'content' => "Produkty:\n" . implode("\n", $lines)]],
        ]);
        foreach ($res['content'] ?? [] as $b) {
            if (($b['type'] ?? '') === 'tool_use' && $b['name'] === 'uloz_znacky') return (array)($b['input']['produkty'] ?? []);
        }
        return [];
    }
}
