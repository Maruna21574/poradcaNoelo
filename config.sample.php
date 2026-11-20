<?php
/**
 * Darčekový poradca noelo.sk – konfigurácia
 * Skopírujte tento súbor ako config.php a vyplňte hodnoty.
 * config.php NIKDY nedávajte do verejného repozitára.
 */
return [
    // API kľúč z https://console.anthropic.com (alebo nastavte premennú prostredia ANTHROPIC_API_KEY)
    'anthropic_api_key' => getenv('ANTHROPIC_API_KEY') ?: '',

    // Model pre rozhovor so zákazníkom (kvalita) a model pre automatické označovanie produktov (cena)
    'model_chat' => 'claude-sonnet-5-5',
    'model_tagger' => 'claude-haiku-5-5',

    // Zdroj produktov. Podporované: Google Merchant XML (Atom aj RSS) alebo JSON export z databázy.
    // Neskôr, keď bude k dispozícii databáza, stačí zmeniť 'type' na 'json' a URL na export.
    // POZOR: googleMerchant.xml je zo 3. 10. 2022 – preto zatiaľ 'crawl' = produkty sa načítajú priamo z webu noelo.sk.
    'feed' => [
        'type' => 'crawl',                          // 'crawl' | 'google_merchant' | 'json'
        // 'json' = export z databázy: pole objektov {id, name, brand, category, description, url, image, price, cost?, available, availability_text?}
        'url'  => 'https://www.noelo.sk/cache/googleMerchant.xml',
        // ak je feed chránený heslom (odporúčané, feed obsahuje nákupné ceny):
        'http_user' => '',
        'http_pass' => '',
        // upozornenie v admine, ak je feed starší ako X hodín
        'max_age_hours' => 48,
    ],

    // Načítanie z webu (len pre feed.type = 'crawl')
    'crawl' => [
        'listing_every_hours' => 3,     // ako často prejsť zoznamy kategórií (ceny, dostupnosť)
        'details_per_run' => 400,       // koľko produktových stránok (plný názov, značka, popis) načítať pri jednom spustení
        // 'categories' => ['lego' => 'Lego', ...],   // vlastný zoznam kategórií (inak predvolený zoznam darčekových kategórií)
    ],

    // Predaje z databázy e-shopu (bestsellery). Nechajte prázdne, ak sa nepoužíva.
    // ODPORÚČANIE: vytvorte samostatného MySQL používateľa len s právom SELECT na tabuľky orders a ordereditems.
    // Tieto údaje patria LEN do config.php na serveri, nikdy do gitu.
    'shop_db' => [
        'host' => '',      // napr. db.r2.websupport.sk
        'port' => 3306,
        'user' => '',
        'pass' => '',
        'db'   => '',
    ],
    'sales_refresh_hours' => 6,     // ako často obnoviť predaje

    // Adresa e-shopu (na doplnenie relatívnych URL)
    'shop_url' => 'https://www.noelo.sk',

    // Weby, na ktorých môže poradca bežať (ďalšie sa pridávajú v admine → Umiestnenie a vloženie)
    'allowed_origins' => ['https://www.noelo.sk', 'https://noelo.sk'],

    // Automatická obnova produktov na pozadí (bez cronu). false = len cez cron / tlačidlo v admine.
    'auto_update' => true,
    'auto_update_every_seconds' => 120,

    // Prihlasovanie do adminu. Heslo vygenerujete príkazom:
    //   php -r "echo password_hash('vase-heslo', PASSWORD_DEFAULT), PHP_EOL;"
    'admin_users' => [
        // 'meno' => '$2y$10$....',
    ],

    // Kde sa ukladá databáza (priečinok musí byť zapisovateľný a NEDOSTUPNÝ z webu)
    'data_dir' => __DIR__ . '/data',

    // Tajný token pre spúšťanie importu cez URL (api/cron.php?token=…), ak hosting nevie spúšťať PHP skripty cronom.
    // Vygenerujte náhodný reťazec, napr.: php -r "echo bin2hex(random_bytes(16));"
    'cron_token' => '',

    // Ochrana pred zneužitím
    'rate_limit_per_hour' => 40,     // správ z jednej IP za hodinu
    'max_message_length'  => 500,    // znakov v jednej správe zákazníka
    'max_turns'           => 12,     // max. počet správ zákazníka v jednej konverzácii

    // Ako dlho uchovávať konverzácie (GDPR) – dni
    'retention_days' => 180,
];
