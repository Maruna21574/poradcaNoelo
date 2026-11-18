<?php
declare(strict_types=1);

/**
 * Automatická údržba bez cronu.
 *
 * Widget pri každom zobrazení stránky načíta nastavenia (api/config.php). Po odoslaní odpovede
 * (zákazník na nič nečaká) sa tu spraví malý kúsok práce: obnovia sa produkty a ceny, AI označí nové produkty.
 * Zámok zaručí, že naraz beží len jedna údržba, a interval, že sa nespúšťa zbytočne často.
 * Cron (cron/import.php alebo api/cron.php) je voliteľný – ak ho nastavíte, údržba bude pravidelnejšia.
 */
final class Maintenance
{
    public static function due(): bool
    {
        if (config('auto_update') === false) return false;
        $last = Settings::get('last_tick');
        return $last === '' || strtotime($last) < time() - (int)(config('auto_update_every_seconds') ?: 120);
    }

    /** Spustí údržbu, ak je čas a nikto iný ju práve nerobí. */
    public static function tick(int $seconds = 25): void
    {
        if (!self::due()) return;
        $lock = fopen(rtrim((string)config('data_dir'), '/') . '/maintenance.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return;
        try {
            Settings::reset();
            if (!self::due()) return;
            Settings::set('last_tick', now());
            @set_time_limit($seconds + 30);
            $deadline = time() + $seconds;
            if (self::importNeeded()) FeedImporter::run(null, null, max(5, $deadline - time()));
            if (time() < $deadline - 8 && (string)config('anthropic_api_key') !== '') Tagger::run(40);
        } catch (Throwable $e) {
            error_log('[poradca] údržba: ' . $e->getMessage());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function importNeeded(): bool
    {
        if (config('feed.type') === 'crawl') {
            if (Settings::get('crawl_cycle') !== '') return true;
            $last = Settings::get('crawl_last_listing');
            if ($last === '' || strtotime($last) < time() - 3600 * (int)(config('crawl.listing_every_hours') ?: 3)) return true;
            return (int)db()->query("SELECT COUNT(*) FROM crawl_items WHERE detail_at IS NULL OR detail_at < '" . date('Y-m-d H:i:s', strtotime('-7 days')) . "'")->fetchColumn() > 0;
        }
        $last = db()->query("SELECT MAX(started_at) FROM import_runs WHERE status = 'ok'")->fetchColumn();
        return !$last || strtotime((string)$last) < time() - 3600;
    }

    /** Pošle odpoveď prehliadaču a pokračuje v práci na pozadí (PHP-FPM). */
    public static function afterResponse(): void
    {
        if (!self::due()) return;
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            ignore_user_abort(true);
            self::tick();
        }
    }
}
