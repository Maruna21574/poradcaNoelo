<?php
declare(strict_types=1);

/**
 * Darčekový poradca: vedie rozhovor so zákazníkom, hľadá v katalógu cez nástroje a všetko zapisuje
 * do databázy pre admin (otázky, odpovede, odporúčania, nesplnený dopyt).
 */
final class Advisor
{
    private const MAX_TOOL_ROUNDS = 5;

    public static function systemPrompt(): string
    {
        $name = Settings::get('assistant_name');
        return <<<TXT
Si {$name} – poradca e-shopu noelo.sk (detský svet: hračky, kreatívne sady, hry, knihy, kolobežky a bicykle, školské potreby, veci do izbičky, potreby pre bábätká). Pomáhaš zákazníkom vybrať darček pre dieťa výhradne z ponuky noelo.sk.

AKO POSTUPOVAŤ
1. Zisti vek dieťaťa a či je to dievča alebo chlapec. Ak to zákazník nepovedal, opýtaj sa.
2. Doplň sa 1–2 otázkami, ktoré najviac zúžia výber: čo dieťa baví (tvorenie, pohyb, stavanie, bábiky, hudba, knihy, šperky…), rozpočet, prípadne obľúbená farba. V jednej správe sa pýtaj najviac na 2 veci, spolu najviac 2 kolá otázok. Ak zákazník napíše „prekvap ma“ alebo už dal dosť informácií, hneď hľadaj.
3. Na hľadanie používaj nástroj hladaj_produkty. Hľadaj kľúčovými slovami (napr. „šperkovnica“, „kolobežka“, „lego“, „kreatívna sada“) a vždy zadaj vek, pre koho a cenový limit, ak ich poznáš. Skús 1–3 rôzne nápady, aby bol výber pestrý.
4. Odporuč 3–4 produkty, ktoré sedia vekom, rozpočtom a záujmami. Ak to ide, ponúkni rôzne typy a cenové hladiny. Odporúčaj iba produkty, ktoré ti nástroj vrátil, a nikdy si nevymýšľaj názvy, ceny ani vlastnosti.
5. Ak nič vhodné nie je, povedz to úprimne, ponúkni najbližšiu alternatívu a do poľa „nenaslo“ stručne zapíš, čo zákazník chcel (napr. „tablet pre 10-ročného“).
6. Každú odpoveď ukonči volaním nástroja odpovedz_zakaznikovi.

PRAVIDLÁ
- Bezpečnosť: deťom do 3 rokov odporúčaj len produkty, ktoré sú pre ich vek výslovne určené alebo sú zjavne pre bábätká a batoľatá.
- Produkty označené „ODPORÚČA OBCHOD“ uprednostni, ak sedia rovnako dobre ako ostatné. Nikdy kvôli tomu neodporuč produkt, ktorý nesedí vekom, rozpočtom alebo záujmom. O interných označeniach so zákazníkom nehovor; zdôvodnenie musí vychádzať z vlastností produktu a potrieb dieťaťa. „Poznámku obchodu“ môžeš využiť, ak je pre zákazníka užitočná.
- Bestsellery: ak zákazník chce najpredávanejšie produkty, „čo sa kupuje“, „čo letí“, alebo si nevie vybrať, zavolaj hladaj_produkty so zoradit = bestsellery (a s vekom, pre koho a rozpočtom, ak ich poznáš). Pri produktoch s údajom o predaji môžeš povedať, že patria medzi najobľúbenejšie alebo najpredávanejšie, ale nikdy neuvádzaj čísla predaja ani počty kusov.
- Nesľubuj termíny doručenia. Dostupnosť („skladom“, „do 7 dní“) môžeš uviesť tak, ako je v katalógu.
- Na otázky o objednávkach, doprave, platbe či reklamáciách odkáž na info@noelo.sk alebo telefón 02/222 003 76 (8:00 – 15:00).
- Rozprávaj sa len o výbere darčekov a o ponuke noelo.sk. Iné témy zdvorilo odmietni.
- Štýl: po slovensky, priateľsky, vykaj, stručne (najviac 2–3 vety v jednej odpovedi), bez markdownu.
TXT;
    }

    public static function tools(): array
    {
        return [
            [
                'name' => 'hladaj_produkty',
                'description' => 'Vyhľadá dostupné produkty v ponuke noelo.sk. Vráti najvhodnejších kandidátov (id, názov, značka, cena, vek, pre koho, kategória, popis). Dá sa volať viackrát s rôznymi výrazmi. Ak nezadáš výrazy, vráti tipy podľa veku, pohlavia a ceny.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'hladane_vyrazy' => ['type' => 'string', 'description' => 'Kľúčové slová, napr. „šperkovnica“, „kreatívna sada náramky“, „kolobežka“, „lego hasiči“. Používaj základné tvary slov.'],
                        'vek' => ['type' => 'number', 'description' => 'Vek dieťaťa v rokoch (bábätko do 1 roka = 0.5).'],
                        'pre' => ['type' => 'string', 'enum' => ['dievča', 'chlapec', 'nezáleží']],
                        'cena_od' => ['type' => 'number'],
                        'cena_do' => ['type' => 'number', 'description' => 'Maximálna cena v eurách.'],
                        'kategoria' => ['type' => 'string', 'description' => 'Voliteľne časť názvu kategórie, napr. „Kolobežky“, „Kreatívne“, „Spoločenské hry“.'],
                        'zoradit' => ['type' => 'string', 'enum' => ['relevancia', 'bestsellery'], 'description' => 'bestsellery = zoradí podľa skutočného predaja za posledných 30 dní (hľadané výrazy môžu byť prázdne, vek, pre koho a cena sa uplatnia). Predvolene relevancia.'],
                        'limit' => ['type' => 'integer', 'description' => 'Počet výsledkov (predvolene 15, najviac 30).'],
                    ],
                ],
            ],
            [
                'name' => 'odpovedz_zakaznikovi',
                'description' => 'Pošle odpoveď zákazníkovi. Volaj vždy na konci, aj keď sa len pýtaš.',
                'input_schema' => [
                    'type' => 'object',
                    'required' => ['odpoved'],
                    'properties' => [
                        'odpoved' => ['type' => 'string', 'description' => 'Text pre zákazníka (2–3 vety, bez markdownu).'],
                        'rychle_odpovede' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '2–4 krátke možnosti na kliknutie (odpovede na tvoju otázku alebo návrhy na spresnenie).'],
                        'odporucania' => [
                            'type' => 'array',
                            'description' => 'Odporúčané produkty (prázdne, ak sa len pýtaš).',
                            'items' => [
                                'type' => 'object',
                                'required' => ['id', 'preco'],
                                'properties' => [
                                    'id' => ['type' => 'string'],
                                    'preco' => ['type' => 'string', 'description' => 'Jedna veta, prečo sa hodí práve tomuto dieťaťu.'],
                                ],
                            ],
                        ],
                        'profil' => [
                            'type' => 'object',
                            'description' => 'Čo zatiaľ vieš o obdarovanom (na štatistiky).',
                            'properties' => [
                                'vek' => ['type' => 'number'],
                                'pre' => ['type' => 'string', 'enum' => ['dievča', 'chlapec', 'nezáleží']],
                                'rozpocet_do' => ['type' => 'number'],
                                'zaujmy' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'prilezitost' => ['type' => 'string', 'description' => 'Vianoce, narodeniny, meniny…'],
                            ],
                        ],
                        'nenaslo' => ['type' => 'string', 'description' => 'Ak zákazník chcel niečo, čo v ponuke nie je, stručne to opíš. Inak vynechaj.'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Spracuje jednu správu zákazníka.
     * @return array{conversation_id:string, reply:string, quick:array, picks:array}
     */
    public static function chat(?string $conversationId, string $message, string $pageUrl = ''): array
    {
        $pdo = db();
        $message = trim(mb_substr($message, 0, (int)config('max_message_length') ?: 500));
        if ($message === '') throw new InvalidArgumentException('Prázdna správa.');

        $conv = null;
        if ($conversationId) {
            $st = $pdo->prepare('SELECT * FROM conversations WHERE id = ?');
            $st->execute([$conversationId]);
            $conv = $st->fetch() ?: null;
        }
        if (!$conv) {
            $conversationId = random_id();
            $pdo->prepare('INSERT INTO conversations(id, created_at, updated_at, ip_hash, page_url) VALUES(?,?,?,?,?)')
                ->execute([$conversationId, now(), now(), client_ip_hash(), mb_substr($pageUrl, 0, 300)]);
            $conv = ['id' => $conversationId, 'turns' => 0, 'profile_json' => '{}', 'unmet' => ''];
        }
        if ((int)$conv['turns'] >= (int)(config('max_turns') ?: 12)) {
            return [
                'conversation_id' => $conversationId,
                'reply' => 'Táto konverzácia je už dosť dlhá. Kliknite prosím na „Začať odznova“ a opýtajte sa znova. Ďakujem!',
                'quick' => [], 'picks' => [],
            ];
        }

        // História: texty zákazníka a odpovede poradcu vrátane odporučených produktov
        $history = [];
        $st = $pdo->prepare('SELECT role, content, picks_json FROM messages WHERE conversation_id = ? ORDER BY id');
        $st->execute([$conversationId]);
        foreach ($st->fetchAll() as $m) {
            $text = $m['content'];
            if ($m['role'] === 'assistant') {
                $picks = json_decode($m['picks_json'], true) ?: [];
                if ($picks) {
                    $text .= "\n[Odporučené produkty: " . implode('; ', array_map(
                        fn($p) => 'id ' . $p['id'] . ' ' . $p['name'] . ' (' . number_format((float)$p['price'], 2, '.', '') . ' €)', $picks)) . ']';
                }
            }
            $history[] = ['role' => $m['role'], 'content' => $text];
        }
        $history[] = ['role' => 'user', 'content' => $message];

        $result = self::runModel($history);

        // Uloženie
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO messages(conversation_id, role, content, created_at) VALUES(?,?,?,?)')
            ->execute([$conversationId, 'user', $message, now()]);
        $pdo->prepare('INSERT INTO messages(conversation_id, role, content, quick_json, picks_json, searches_json, tokens_in, tokens_out, created_at) VALUES(?,?,?,?,?,?,?,?,?)')
            ->execute([$conversationId, 'assistant', $result['reply'], json_encode($result['quick'], JSON_UNESCAPED_UNICODE),
                json_encode($result['picks'], JSON_UNESCAPED_UNICODE), json_encode($result['searches'], JSON_UNESCAPED_UNICODE),
                $result['tokens_in'], $result['tokens_out'], now()]);
        $msgId = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO recommendations(conversation_id, message_id, product_id, position, boosted, created_at) VALUES(?,?,?,?,?,?)');
        foreach ($result['picks'] as $i => $p) $ins->execute([$conversationId, $msgId, $p['id'], $i + 1, $p['_boost'] > 0 ? 1 : 0, now()]);

        $profile = array_merge(json_decode($conv['profile_json'] ?: '{}', true) ?: [], array_filter($result['profile'], fn($v) => $v !== null && $v !== '' && $v !== []));
        $unmet = trim((string)$conv['unmet']);
        if ($result['unmet'] !== '' && !str_contains($unmet, $result['unmet'])) $unmet = trim($unmet . ($unmet ? ' | ' : '') . $result['unmet']);
        $pdo->prepare('UPDATE conversations SET updated_at = ?, turns = turns + 1, profile_json = ?, unmet = ? WHERE id = ?')
            ->execute([now(), json_encode($profile, JSON_UNESCAPED_UNICODE), mb_substr($unmet, 0, 500), $conversationId]);
        $pdo->commit();

        return [
            'conversation_id' => $conversationId,
            'reply' => $result['reply'],
            'quick' => $result['quick'],
            'picks' => array_map(fn($p) => array_diff_key($p, ['_boost' => 1]), $result['picks']),
        ];
    }

    /**
     * Zavolá model. Ak hlavný model zlyhá (preťaženie, výpadok), skúsi raz záložný model
     * (config 'model_fallback', predvolene Haiku), aby zákazník dostal odpoveď namiesto chyby.
     */
    private static function callModel(array $payload): array
    {
        try {
            return Claude::messages($payload);
        } catch (RuntimeException $e) {
            $fallback = (string)(config('model_fallback') ?: 'claude-haiku-5-5');
            $code = (int)$e->getCode();
            // Chyby, ktoré záložný model nevyrieši (zlý kľúč, zlá požiadavka, chýba kľúč), posielame ďalej
            if ($fallback === '' || $fallback === $payload['model'] || in_array($code, [400, 401, 402, 403], true) || str_contains($e->getMessage(), 'API kľúč')) throw $e;
            error_log('[poradca] záložný model po chybe: ' . $e->getMessage());
            $payload['model'] = $fallback;
            return Claude::messages($payload);
        }
    }

    /** Spustí model s nástrojmi a vráti spracovanú odpoveď. */
    public static function runModel(array $history): array
    {
        $system = [
            ['type' => 'text', 'text' => self::systemPrompt(), 'cache_control' => ['type' => 'ephemeral']],
        ];
        $dynamic = 'Dnes je ' . date('j. n. Y') . '.';
        $extra = trim(Settings::get('extra_instructions'));
        if ($extra !== '') $dynamic .= "\n\nDOPLŇUJÚCE POKYNY OD OBCHODU:\n" . $extra;
        $system[] = ['type' => 'text', 'text' => $dynamic];

        $messages = $history;
        $searches = [];
        $seen = [];           // produkty, ktoré AI v tomto kole videla
        $tokensIn = 0; $tokensOut = 0;
        $final = null;

        for ($round = 1; $round <= self::MAX_TOOL_ROUNDS; $round++) {
            $payload = [
                'model' => (string)config('model_chat'),
                'max_tokens' => 1200,
                'system' => $system,
                'tools' => self::tools(),
                'messages' => $messages,
            ];
            if ($round === self::MAX_TOOL_ROUNDS) {
                $payload['tool_choice'] = ['type' => 'tool', 'name' => 'odpovedz_zakaznikovi'];
            }
            $res = self::callModel($payload);
            $tokensIn += (int)($res['usage']['input_tokens'] ?? 0) + (int)($res['usage']['cache_read_input_tokens'] ?? 0) + (int)($res['usage']['cache_creation_input_tokens'] ?? 0);
            $tokensOut += (int)($res['usage']['output_tokens'] ?? 0);

            $toolResults = [];
            foreach ($res['content'] ?? [] as $block) {
                if (($block['type'] ?? '') !== 'tool_use') continue;
                if ($block['name'] === 'odpovedz_zakaznikovi') {
                    $final = $block['input'];
                    break 2;
                }
                if ($block['name'] === 'hladaj_produkty') {
                    $input = (array)$block['input'];
                    $q = $input + ['limit' => 15];
                    if (($q['pre'] ?? '') === 'nezáleží') unset($q['pre']);
                    $found = Catalog::search($q);
                    foreach ($found as $p) $seen[$p['id']] = $p;
                    $searches[] = ['input' => $input, 'count' => count($found)];
                    $toolResults[] = [
                        'type' => 'tool_result',
                        'tool_use_id' => $block['id'],
                        'content' => $found ? implode("\n", array_map([Catalog::class, 'forAi'], $found)) : 'Nič sa nenašlo. Skús iné slová alebo voľnejšie filtre.',
                    ];
                } else {
                    $toolResults[] = ['type' => 'tool_result', 'tool_use_id' => $block['id'], 'content' => 'Neznámy nástroj.', 'is_error' => true];
                }
            }
            if (!$toolResults) {
                // Model odpovedal bez nástroja – použijeme text
                $final = ['odpoved' => trim(Claude::text($res))];
                break;
            }
            $messages[] = ['role' => 'assistant', 'content' => $res['content']];
            $messages[] = ['role' => 'user', 'content' => $toolResults];
        }

        $final = $final ?? ['odpoved' => 'Prepáčte, nepodarilo sa mi nájsť odpoveď. Skúsite to napísať inak?'];
        $max = max(1, (int)Settings::get('max_picks'));
        $picks = [];
        foreach ((array)($final['odporucania'] ?? []) as $r) {
            $id = (string)($r['id'] ?? '');
            if ($id === '' || isset($picks[$id])) continue;
            $p = $seen[$id] ?? Catalog::find($id);   // len existujúce a dostupné produkty
            if (!$p) continue;
            $picks[$id] = Catalog::forWidget($p, trim((string)($r['preco'] ?? ''))) + ['_boost' => $p['boost']];
            if (count($picks) >= $max) break;
        }
        $profile = (array)($final['profil'] ?? []);
        return [
            'reply' => trim((string)($final['odpoved'] ?? '')) ?: 'Ako vám môžem pomôcť s výberom darčeka?',
            'quick' => array_slice(array_values(array_filter(array_map('strval', (array)($final['rychle_odpovede'] ?? [])))), 0, 4),
            'picks' => array_values($picks),
            'profile' => [
                'vek' => isset($profile['vek']) ? (float)$profile['vek'] : null,
                'pre' => $profile['pre'] ?? null,
                'rozpocet_do' => isset($profile['rozpocet_do']) ? (float)$profile['rozpocet_do'] : null,
                'zaujmy' => array_values(array_filter(array_map('strval', (array)($profile['zaujmy'] ?? [])))),
                'prilezitost' => $profile['prilezitost'] ?? null,
            ],
            'unmet' => trim((string)($final['nenaslo'] ?? '')),
            'searches' => $searches,
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
        ];
    }
}
