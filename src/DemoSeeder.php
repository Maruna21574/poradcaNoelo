<?php
declare(strict_types=1);

/**
 * Ukážkové konverzácie pre admin (na predvedenie šéfovi), aby boli štatistiky hneď na čo pozerať.
 * Sú označené ako ukážkové (is_demo = 1) a dajú sa jedným klikom zmazať. AI sa pri tom nevolá.
 */
final class DemoSeeder
{
    private const PERSONAS = [
        [8, 'dievča', 30, ['šperky', 'tvorenie'], 'Hľadám darček pre 8-ročné dievča', 'Rada tvorí a má rada šperky, do 30 €', 'šperky náramky korálky', 'Vianoce'],
        [7, 'dievča', 40, ['šperky'], 'darček pre vnučku 7 rokov', 'má rada šperky, max 40 eur', 'šperkovnica', 'Vianoce'],
        [6, 'chlapec', 60, ['stavanie'], 'Čo kúpiť 6-ročnému chlapcovi?', 'rád stavia, rozpočet okolo 50 €', 'stavebnica', 'Vianoce'],
        [5, 'chlapec', 30, ['dinosaury'], 'Synovec 5 rokov, miluje dinosaury', 'do 30 €', 'dinosaurus', 'narodeniny'],
        [3, 'dievča', 40, ['bábiky'], 'dcérka bude mať 3 roky, čo by ste odporučili?', 'hrá sa s bábikami, do 40 €', 'bábika', 'narodeniny'],
        [2, 'chlapec', 120, ['pohyb'], 'darček pre 2 ročného chlapca', 'niečo na pohyb, do 120 €', 'odrážadlo', 'Vianoce'],
        [4, 'dievča', 25, ['tvorenie'], 'kreatívny darček pre 4-ročnú', 'do 25 €', 'kreatívna sada', 'Vianoce'],
        [0.5, 'nezáleží', 40, ['motorika'], 'Prvé Vianoce bábätka, má 6 mesiacov', 'do 40 €', 'hrazdička hracia deka', 'Vianoce'],
        [9, 'chlapec', 45, ['veda a objavovanie'], '9 ročný chlapec, zaujíma sa o vedu a techniku', 'max 45 €', 'robot veda', 'Vianoce'],
        [10, 'dievča', 35, ['hry'], 'spoločenská hra pre 10-ročné dievča', 'aby sa dala hrať s rodinou, do 35 €', 'hra', 'Vianoce'],
        [7, 'chlapec', 130, ['pohyb'], 'Kolobežka pre 7-ročného syna', 'radšej kvalitnú, do 130 €', 'kolobežka', 'Vianoce'],
        [8, 'dievča', 20, ['škola'], 'Malý darček pre spolužiačku dcéry (8 r.)', 'do 20 €', 'zápisník', 'Vianoce'],
        [5, 'nezáleží', 20, ['knihy'], 'knižka pre 5-ročné dieťa', 'do 20 €', 'kniha', 'Mikuláš'],
        [1, 'dievča', 70, ['motorika'], 'Dievčatko má rok, chcem drevenú hračku', 'do 70 €', 'drevené', 'narodeniny'],
        [11, 'dievča', 50, ['tvorenie'], '11-ročná dcéra, rada kreslí a tvorí', 'do 50 €', 'kreatívna sada', 'Vianoce'],
        [4, 'chlapec', 40, ['hra na niečo'], 'Synček 4 roky, rád sa hrá na doktora', 'do 40 €', 'doktor kufrík', 'Vianoce'],
        [3, 'chlapec', 40, ['autá'], 'trojročný chlapec miluje autíčka a vláčiky', 'do 40 €', 'vláčik auto', 'Vianoce'],
        [6, 'nezáleží', 90, ['fotografovanie'], 'Detský fotoaparát pre 6-ročné dieťa?', 'do 90 €', 'fotoaparát', 'Vianoce'],
        [7, 'dievča', 60, ['Vianoce'], 'Adventný kalendár pre dievča 7 rokov', 'do 60 €', 'adventný kalendár', 'Vianoce'],
        [8, 'chlapec', 30, ['hry'], '8 ročný chlapec, rád rieši hlavolamy', 'do 30 €', 'hlavolam', 'Vianoce'],
        [2, 'dievča', 130, ['hra na niečo'], 'Drevená kuchynka pre 2,5-ročnú dcéru', 'do 130 €', 'kuchynka', 'Vianoce'],
        [9, 'dievča', 60, ['hudba'], 'dcéra 9 rokov, spieva od rána do večera', 'do 60 €', 'karaoke mikrofón', 'Vianoce'],
        [6, 'chlapec', 120, ['škola'], 'Školská taška pre prváka', 'kvalitnú ergonomickú', 'školská taška', 'nástup do školy'],
    ];
    private const UNMET = [
        ['Máte tablet pre 10-ročného chlapca?', 'tablet pre 10-ročného', 10, 'chlapec'],
        ['hľadám PlayStation hru pre 12 ročného', 'hry na PlayStation / herné konzoly', 12, 'chlapec'],
        ['Darček pre 14-ročnú tínedžerku', 'darčeky pre tínedžerov 13+', 14, 'dievča'],
        ['Máte drona pre deti?', 'detský dron', 10, 'chlapec'],
        ['elektrická kolobežka pre 11 ročného', 'elektrická kolobežka', 11, 'chlapec'],
        ['chytré hodinky pre dieťa 8 rokov', 'detské chytré hodinky', 8, 'nezáleží'],
    ];
    private const WHY = ['Sedí vekom aj rozpočtom a podporí %s.', 'Obľúbený darček pre deti, ktoré baví %s.', 'Kvalitné spracovanie, vydrží dlho – ideálne pre %s.', 'Zabaví na dlhé zimné večery a rozvíja %s.'];

    public static function run(int $count = 140): int
    {
        mt_srand(7);
        $pdo = db();
        $made = 0;
        $pdo->beginTransaction();
        for ($i = 0; $i < $count; $i++) {
            $daysBack = (int)abs(self::gauss() * 7) % 21;
            $ts = date('Y-m-d H:i:s', strtotime("-$daysBack days") - mt_rand(0, 11 * 3600));
            $id = 'demo' . random_id(8);
            if (mt_rand(1, 100) <= 9) {
                [$q, $u, $age, $g] = self::UNMET[array_rand(self::UNMET)];
                self::conv($id, $ts, ['vek' => $age, 'pre' => $g], $u);
                self::msg($id, 'user', $q, $ts);
                self::msg($id, 'assistant', 'Takýto produkt v ponuke noelo.sk nemáme, prepáčte. Môžem vám však ukázať obľúbené hry a stavebnice pre tento vek – chcete?', $ts, [], [['input' => ['hladane_vyrazy' => explode(' ', $u)[0], 'vek' => $age], 'count' => 0]]);
                $made++;
                continue;
            }
            [$age, $g, $budget, $ints, $q, $follow, $sq, $occ] = self::PERSONAS[array_rand(self::PERSONAS)];
            $found = Catalog::search(['hladane_vyrazy' => $sq, 'vek' => $age, 'pre' => $g === 'nezáleží' ? null : $g, 'cena_do' => $budget, 'limit' => 8]);
            if (!$found) continue;
            shuffle($found);
            $picks = array_slice($found, 0, 3);
            $kto = $g === 'dievča' ? 'ju' : ($g === 'chlapec' ? 'ho' : 'dieťa');
            self::conv($id, $ts, ['vek' => $age, 'pre' => $g, 'rozpocet_do' => $budget, 'zaujmy' => $ints, 'prilezitost' => $occ], '', mt_rand(1, 100) <= 45 ? [1, 1, 1, -1][mt_rand(0, 3)] : null);
            self::msg($id, 'user', $q, $ts);
            self::msg($id, 'assistant', "Rád pomôžem! Čo $kto baví a aký máte približne rozpočet?", $ts);
            self::msg($id, 'user', $follow, $ts);
            $cards = array_map(fn($p) => Catalog::forWidget($p, sprintf(self::WHY[array_rand(self::WHY)], $ints[0])), $picks);
            $mid = self::msg($id, 'assistant', 'Tu je niekoľko tipov, ktoré by sa mohli hodiť:', $ts, $cards,
                [['input' => ['hladane_vyrazy' => $sq, 'vek' => $age, 'pre' => $g, 'cena_do' => $budget], 'count' => count($found)]]);
            foreach ($picks as $pos => $p) {
                $pdo->prepare('INSERT INTO recommendations(conversation_id, message_id, product_id, position, boosted, created_at) VALUES(?,?,?,?,?,?)')
                    ->execute([$id, $mid, $p['id'], $pos + 1, $p['boost'] > 0 ? 1 : 0, $ts]);
            }
            if (mt_rand(1, 100) <= 52) {
                self::event($id, $picks[0]['id'], 'click', $ts);
                if (mt_rand(1, 100) <= 45) self::event($id, $picks[0]['id'], 'add_to_cart', $ts);
            }
            $made++;
        }
        $pdo->commit();
        return $made;
    }

    public static function clear(): int
    {
        $st = db()->prepare('DELETE FROM conversations WHERE is_demo = 1');
        $st->execute();
        return $st->rowCount();
    }

    public static function count(): int
    {
        return (int)db()->query('SELECT COUNT(*) FROM conversations WHERE is_demo = 1')->fetchColumn();
    }

    private static function conv(string $id, string $ts, array $profile, string $unmet, ?int $rating = null): void
    {
        db()->prepare('INSERT INTO conversations(id, created_at, updated_at, ip_hash, page_url, turns, profile_json, unmet, rating, is_demo) VALUES(?,?,?,?,?,?,?,?,?,1)')
            ->execute([$id, $ts, $ts, 'demo', '', $unmet ? 1 : 2, json_encode($profile, JSON_UNESCAPED_UNICODE), $unmet, $rating]);
    }

    private static function msg(string $id, string $role, string $text, string $ts, array $picks = [], array $searches = []): int
    {
        db()->prepare('INSERT INTO messages(conversation_id, role, content, picks_json, searches_json, created_at) VALUES(?,?,?,?,?,?)')
            ->execute([$id, $role, $text, json_encode($picks, JSON_UNESCAPED_UNICODE), json_encode($searches, JSON_UNESCAPED_UNICODE), $ts]);
        return (int)db()->lastInsertId();
    }

    private static function event(string $id, string $pid, string $type, string $ts): void
    {
        db()->prepare('INSERT INTO events(conversation_id, product_id, type, created_at) VALUES(?,?,?,?)')->execute([$id, $pid, $type, $ts]);
    }

    private static function gauss(): float
    {
        return sqrt(-2 * log(mt_rand(1, mt_getrandmax()) / mt_getrandmax())) * cos(2 * M_PI * mt_rand() / mt_getrandmax());
    }
}
