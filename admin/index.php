<?php
declare(strict_types=1);
/**
 * Admin Darčekového poradcu.
 * Stránky: prehľad, konverzácie, nesplnený dopyt, produkty (zvýhodnenie/vylúčenie), pravidlá, nastavenia.
 */
require_once dirname(__DIR__) . '/src/bootstrap.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_name('poradca_admin');
session_start();
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

$page = (string)($_GET['p'] ?? 'prehlad');
$user = $_SESSION['user'] ?? null;

function csrf(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . csrf() . '">'; }
function check_csrf(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) { http_response_code(400); exit('Neplatný formulár, obnovte stránku.'); }
}
function url(array $q): string { return '?' . http_build_query($q); }
function redirect(array $q, string $flash = ''): void
{
    if ($flash !== '') $_SESSION['flash'] = $flash;
    header('Location: ' . url($q));
    exit;
}
function fmt_date(?string $d): string { return $d ? date('j. n. Y H:i', strtotime($d)) : '–'; }
function pct(float $v): string { return number_format($v, 1, ',', ' ') . ' %'; }

const BOOST_LABELS = [0 => 'bez zvýhodnenia', 1 => 'mierne', 2 => 'výrazne', 3 => 'maximálne'];
const BOOST_REASONS = ['Dlho na sklade', 'Veľa kusov na sklade', 'Novinka', 'Vyššia marža', 'Akcia / výpredaj', 'Sezónny tovar', 'Iné'];

/* ---------- prihlásenie ---------- */
if ($page === 'logout') { session_destroy(); header('Location: ?'); exit; }
if (!$user) {
    $err = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $name = (string)($_POST['user'] ?? '');
        $hash = config('admin_users')[$name] ?? null;
        if ($hash && password_verify((string)($_POST['pass'] ?? ''), $hash)) {
            session_regenerate_id(true);
            $_SESSION['user'] = $name;
            header('Location: ?');
            exit;
        }
        sleep(1);
        $err = 'Nesprávne meno alebo heslo.';
    }
    $noUsers = empty(config('admin_users'));
    ?><!DOCTYPE html><html lang="sk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Prihlásenie – Darčekový poradca</title>
    <style>*{box-sizing:border-box}body{font-family:"Segoe UI",system-ui,sans-serif;background:#f5f2ef;display:grid;place-items:center;min-height:100vh;margin:0;color:#685f52}
    form{background:#fff;padding:44px 40px 36px;border-radius:20px;box-shadow:0 8px 40px rgba(160,146,134,.15);width:100%;max-width:400px;text-align:center}
    form img{height:60px;width:auto}.bdg{display:inline-block;background:linear-gradient(135deg,#ef7b84,#00d4c5);color:#fff;font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;padding:3px 10px;border-radius:999px;margin:10px 0 26px}
    label{display:block;text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#a09286;margin-bottom:6px}
    input{width:100%;padding:11px 14px;border:1.5px solid #dfe8ea;background:#ebf8fb;border-radius:10px;margin:6px 0 14px;font:inherit;font-size:14px;color:#685f52;outline:none;text-transform:none;letter-spacing:0}input:focus{border-color:#00d4c5;box-shadow:0 0 0 3px rgba(0,212,197,.12)}
    button{width:100%;padding:12px;border:0;border-radius:10px;background:linear-gradient(135deg,#ef7b84,#e06870);color:#fff;font-weight:700;font-size:14px;cursor:pointer;margin-top:6px}button:hover{opacity:.92}
    p{margin:0 0 16px;font-size:13px;color:#a09286}.err{background:rgba(229,62,62,.08);border:1px solid rgba(229,62,62,.2);color:#e53e3e;border-radius:8px;padding:10px 14px;text-align:left}</style></head><body>
    <form method="post"><img src="https://noelo.sk/img/design/logo-new-v2-sk.png" alt="Noelo.sk"><br><span class="bdg">Darčekový poradca</span>
    <?php if ($noUsers): ?><p class="err">V config.php nie je nastavený žiadny používateľ (admin_users).</p><?php endif; ?>
    <?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?>
    <label>Meno<input name="user" autocomplete="username" required></label><label>Heslo<input name="pass" type="password" autocomplete="current-password" required></label><button>Prihlásiť</button></form></body></html><?php
    exit;
}

/* ---------- akcie (POST) ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    check_csrf();
    $action = (string)($_POST['action'] ?? '');
    $pdo = db();
    if ($action === 'save_product') {
        $id = (string)$_POST['id'];
        $boost = max(0, min(3, (int)($_POST['boost'] ?? 0)));
        $until = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['boost_until'] ?? '')) ? $_POST['boost_until'] : null;
        $num = fn($k) => ($_POST[$k] ?? '') === '' ? null : max(0, min(99, (int)$_POST[$k]));
        $gender = in_array($_POST['gender'] ?? '', ['dievča', 'chlapec', 'unisex'], true) ? $_POST['gender'] : null;
        $pdo->prepare('INSERT INTO product_overrides(product_id, boost, boost_reason, boost_until, ai_note, excluded, age_min, age_max, gender, updated_by, updated_at)
                VALUES(?,?,?,?,?,?,?,?,?,?,?)
                ON CONFLICT(product_id) DO UPDATE SET boost=excluded.boost, boost_reason=excluded.boost_reason, boost_until=excluded.boost_until,
                ai_note=excluded.ai_note, excluded=excluded.excluded, age_min=excluded.age_min, age_max=excluded.age_max, gender=excluded.gender,
                updated_by=excluded.updated_by, updated_at=excluded.updated_at')
            ->execute([$id, $boost, mb_substr((string)($_POST['boost_reason'] ?? ''), 0, 60), $until, mb_substr(trim((string)($_POST['ai_note'] ?? '')), 0, 200),
                !empty($_POST['excluded']) ? 1 : 0, $num('age_min'), $num('age_max'), $gender, $user, now()]);
        redirect(['p' => 'produkt', 'id' => $id], 'Uložené. Poradca zmenu použije hneď pri ďalšej otázke.');
    }
    if ($action === 'quick_boost') {
        $id = (string)$_POST['id'];
        $boost = max(0, min(3, (int)$_POST['boost']));
        $pdo->prepare('INSERT INTO product_overrides(product_id, boost, boost_reason, updated_by, updated_at) VALUES(?,?,?,?,?)
            ON CONFLICT(product_id) DO UPDATE SET boost=excluded.boost, updated_by=excluded.updated_by, updated_at=excluded.updated_at')
            ->execute([$id, $boost, $boost ? 'Iné' : '', $user, now()]);
        redirect(['p' => 'produkty'] + array_intersect_key($_GET, array_flip(['q', 'f', 'cat', 'pg'])), 'Zvýhodnenie uložené.');
    }
    if ($action === 'add_rule') {
        $kind = $_POST['kind'] === 'category' ? 'category' : 'brand';
        $value = trim((string)$_POST['value']);
        $until = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['until'] ?? '')) ? $_POST['until'] : null;
        if ($value !== '') {
            $pdo->prepare('INSERT INTO boost_rules(kind, value, boost, reason, until, created_by, created_at) VALUES(?,?,?,?,?,?,?)')
                ->execute([$kind, $value, max(1, min(3, (int)$_POST['boost'])), mb_substr((string)$_POST['reason'], 0, 60), $until, $user, now()]);
        }
        redirect(['p' => 'pravidla'], 'Pravidlo pridané.');
    }
    if ($action === 'delete_rule') {
        $pdo->prepare('DELETE FROM boost_rules WHERE id = ?')->execute([(int)$_POST['id']]);
        redirect(['p' => 'pravidla'], 'Pravidlo zmazané.');
    }
    if ($action === 'save_settings') {
        foreach (['assistant_name', 'greeting', 'quick_start', 'extra_instructions', 'max_picks'] as $k) {
            Settings::set($k, trim((string)($_POST[$k] ?? '')));
        }
        Settings::set('enabled', !empty($_POST['enabled']) ? '1' : '0');
        redirect(['p' => 'nastavenia'], 'Nastavenia uložené.');
    }
    if ($action === 'save_display') {
        $in = fn($k) => trim((string)($_POST[$k] ?? ''));
        $pick = fn($k, $allowed, $def) => in_array($in($k), $allowed, true) ? $in($k) : $def;
        $color = fn($k, $def) => preg_match('/^#[0-9a-fA-F]{6}$/', $in($k)) ? $in($k) : $def;
        $lines = fn($k) => implode("\n", array_slice(array_filter(array_map(fn($l) => mb_substr(trim($l), 0, 200), explode("\n", $in($k)))), 0, 50));
        Settings::set('d_position', $pick('d_position', ['right-bottom', 'left-bottom', 'right-top', 'left-top', 'none'], 'right-bottom'));
        Settings::set('d_offset_x', (string)max(0, min(400, (int)$in('d_offset_x'))));
        Settings::set('d_offset_y', (string)max(0, min(600, (int)$in('d_offset_y'))));
        Settings::set('d_button_text', mb_substr($in('d_button_text'), 0, 40) ?: 'Darčekový poradca');
        Settings::set('d_color_main', $color('d_color_main', '#22c4b2'));
        Settings::set('d_color_accent', $color('d_color_accent', '#ef7b84'));
        Settings::set('d_pages_mode', $pick('d_pages_mode', ['all', 'home', 'list'], 'all'));
        Settings::set('d_pages', $lines('d_pages'));
        Settings::set('d_exclude', $lines('d_exclude'));
        Settings::set('d_device', $pick('d_device', ['all', 'desktop', 'mobile'], 'all'));
        Settings::set('d_teaser_text', mb_substr($in('d_teaser_text'), 0, 80));
        Settings::set('d_teaser_delay', (string)max(0, min(120, (int)$in('d_teaser_delay'))));
        Settings::set('d_inline_enabled', !empty($_POST['d_inline_enabled']) ? '1' : '0');
        Settings::set('d_inline_selector', mb_substr(str_replace(['<', '>'], '', $in('d_inline_selector')), 0, 120));
        Settings::set('d_inline_where', $pick('d_inline_where', ['before', 'after', 'prepend', 'append'], 'before'));
        Settings::set('d_inline_pages_mode', $pick('d_inline_pages_mode', ['all', 'home', 'list'], 'home'));
        Settings::set('d_inline_pages', $lines('d_inline_pages'));
        Settings::set('d_inline_title', mb_substr($in('d_inline_title'), 0, 80) ?: Settings::DEFAULTS['d_inline_title']);
        Settings::set('d_inline_text', mb_substr($in('d_inline_text'), 0, 200) ?: Settings::DEFAULTS['d_inline_text']);
        Settings::set('cart_mode', $pick('cart_mode', ['real', 'demo', 'off'], 'real'));
        $origins = array_filter(array_map(fn($o) => rtrim(trim($o), '/'), explode("\n", $in('allowed_origins'))), fn($o) => (bool)preg_match('~^https?://[a-z0-9.-]+(:\d+)?$~i', $o));
        Settings::set('allowed_origins', implode("\n", $origins));
        redirect(['p' => 'zobrazenie'], 'Uložené. Na webe sa zmena prejaví do 1 minúty – bez zásahu do kódu e-shopu.');
    }
    if ($action === 'seed_demo') {
        $n = DemoSeeder::run(140);
        redirect(['p' => 'prehlad'], "Vytvorených $n ukážkových konverzácií.");
    }
    if ($action === 'clear_demo') {
        $n = DemoSeeder::clear();
        redirect(['p' => 'prehlad'], "Zmazaných $n ukážkových konverzácií.");
    }
    if ($action === 'run_import') {
        try {
            @set_time_limit(120);
            $r = FeedImporter::run(null, null, 45);   // na webe max. ~45 s, ďalej pokračuje cron
            if (config('feed.type') === 'crawl') Tagger::run(200);
            redirect(['p' => 'nastavenia'], "Import hotový: {$r['total']} produktov, {$r['available']} dostupných. " . $r['message']);
        } catch (Throwable $e) {
            redirect(['p' => 'nastavenia'], 'Import zlyhal: ' . $e->getMessage());
        }
    }
    if ($action === 'refresh_sales') {
        try {
            @set_time_limit(90);
            $r = Sales::refresh();
            redirect(['p' => 'nastavenia'], "Predaje obnovené: {$r['rows']} produktov s predajom, {$r['matched']} z nich je v katalógu poradcu.");
        } catch (Throwable $e) {
            redirect(['p' => 'nastavenia'], 'Obnova predajov zlyhala: ' . $e->getMessage());
        }
    }
    redirect(['p' => $page]);
}

/* ---------- rozloženie ---------- */
$days = in_array((int)($_GET['d'] ?? 30), [7, 30, 90], true) ? (int)($_GET['d'] ?? 30) : 30;
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$lastImport = db()->query('SELECT * FROM import_runs ORDER BY id DESC LIMIT 1')->fetch() ?: null;
$feedOld = $lastImport && $lastImport['feed_last_modified'] && strtotime($lastImport['feed_last_modified']) < time() - 3600 * (int)(config('feed.max_age_hours') ?: 48);
$nav = ['prehlad' => ['📊', 'Prehľad'], 'konverzacie' => ['💬', 'Konverzácie'], 'dopyt' => ['🔍', 'Čo ľudia hľadali a nemáme'],
    'produkty' => ['🎁', 'Produkty a zvýhodnenie'], 'pravidla' => ['⭐', 'Hromadné zvýhodnenie'], 'zobrazenie' => ['🧩', 'Umiestnenie a vloženie'], 'nastavenia' => ['⚙️', 'Nastavenia a import']];
$navKey = $page === 'konverzacia' ? 'konverzacie' : ($page === 'produkt' ? 'produkty' : $page);

function bar_list(array $data, string $color = '#22c4b2'): string
{
    $max = max(1, ...array_values($data ?: [0]));
    $total = array_sum($data);
    $out = '<div class="bars">';
    foreach ($data as $k => $v) {
        $w = round(100 * $v / $max);
        $p = $total ? round(100 * $v / $total) : 0;
        $out .= '<div class="bar-row"><span class="bl">' . h((string)$k) . '</span><span class="bt"><i style="width:' . $w . '%;background:' . $color . '"></i></span><span class="bv">' . $v . ' <small>' . $p . ' %</small></span></div>';
    }
    return $out . '</div>';
}
?><!DOCTYPE html>
<html lang="sk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($nav[$navKey][1] ?? 'Admin') ?> – Darčekový poradca</title>
<style>
:root{--teal:#00d4c5;--teal-d:#00a99d;--pink:#ef7b84;--pink-d:#e06970;--txt:#685f52;--muted:#a09286;--line:#dfe8ea;--bg:#f5f2ef;--card:#fff;--lt:#ebf8fb;--warn:#fff4e5;--warn-b:#f1c27d}
*{box-sizing:border-box}body{margin:0;font:13px/1.5 "Segoe UI",system-ui,-apple-system,sans-serif;color:var(--txt);background:var(--bg)}
a{color:var(--teal-d);text-decoration:none}a:hover{text-decoration:underline}
.layout{min-height:100vh}
nav.top{position:sticky;top:0;z-index:100;background:#fff;border-bottom:3px solid var(--lt);box-shadow:0 3px 16px rgba(0,212,197,.12);padding:0 20px}
.nav-in{max-width:1440px;margin:0 auto;display:flex;align-items:center;gap:6px;min-height:58px;flex-wrap:wrap}
.nav-logo{display:flex;align-items:center;gap:10px;margin-right:10px;padding:4px 0;flex-shrink:0}.nav-logo:hover{text-decoration:none}.nav-logo img{height:38px;width:auto;max-width:none;flex-shrink:0;display:block}
.nav-badge{background:linear-gradient(135deg,var(--pink),var(--teal));color:#fff;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:3px 8px;border-radius:6px;white-space:nowrap}
.nav-div{width:1px;height:24px;background:var(--line);margin:0 6px;flex-shrink:0}
.nav-links{display:flex;gap:4px;flex-wrap:wrap}
.nav-links a{padding:6px 12px;border-radius:8px;font-size:12px;color:var(--muted);white-space:nowrap;transition:all .15s}.nav-links a:hover{color:var(--txt);background:var(--lt);text-decoration:none}
.nav-links a.on{color:#fff;background:var(--pink)}
.nav-user{margin-left:auto;font-size:11px;color:var(--muted);white-space:nowrap}.nav-user a{margin-left:8px;padding:5px 10px;border:1px solid var(--line);border-radius:8px;color:var(--muted)}.nav-user a:hover{color:var(--teal-d);border-color:var(--teal);text-decoration:none}
main{padding:24px 20px 60px;max-width:1440px;margin:0 auto}
h1{font-size:20px;font-weight:700;margin:0 0 4px}h2{font-size:16px;font-weight:700;margin:0 0 12px}.sub{color:var(--muted);margin:0 0 20px}
.flash{background:#e6f8f5;border:1px solid #b5ebe3;padding:10px 14px;border-radius:10px;margin-bottom:16px}
.info{background:var(--lt);border:1px solid var(--line);padding:10px 14px;border-radius:10px;margin-bottom:16px}.b-demo{background:#f1ece5;color:#a09286}
.warn{background:var(--warn);border:1px solid var(--warn-b);padding:10px 14px;border-radius:10px;margin-bottom:16px}
.grid{display:grid;gap:14px}.g4{grid-template-columns:repeat(4,1fr)}.g3{grid-template-columns:repeat(3,1fr)}.g2{grid-template-columns:repeat(2,1fr)}
@media(max-width:1100px){.g4{grid-template-columns:repeat(2,1fr)}.g3,.g2{grid-template-columns:1fr}.nav-user{margin-left:0}}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:18px;box-shadow:0 1px 4px rgba(104,95,82,.06)}
.kpi{border-left:3px solid var(--pink)}.grid>.kpi:nth-child(4n+2){border-left-color:var(--teal)}.grid>.kpi:nth-child(4n+3){border-left-color:#276749}.grid>.kpi:nth-child(4n+4){border-left-color:#dd6b20}
.kpi .v{font-size:24px;font-weight:700;line-height:1.1}.kpi .l{color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px}.kpi .s{font-size:11px;color:var(--muted);margin-top:4px}
.tabs{display:flex;gap:4px;margin-bottom:18px}.tabs a{padding:6px 14px;border-radius:8px;background:#fff;border:1px solid var(--line);color:var(--muted);font-size:12px;font-weight:500}.tabs a:hover{color:var(--txt);border-color:var(--teal);background:var(--lt);text-decoration:none}.tabs a.on{background:var(--pink);color:#fff;border-color:var(--pink)}
.bars{display:flex;flex-direction:column;gap:7px}.bar-row{display:grid;grid-template-columns:120px 1fr 70px;gap:10px;align-items:center;font-size:13px}
.bt{height:10px;background:#f1ece5;border-radius:6px;overflow:hidden}.bt i{display:block;height:100%;border-radius:6px}.bv{text-align:right;font-weight:600}.bv small{color:var(--muted);font-weight:400}
.chips{display:flex;flex-wrap:wrap;gap:6px}.chip{background:var(--lt);color:var(--teal-d);border-radius:999px;padding:2px 10px;font-size:11px;font-weight:600}.chip b{margin-left:4px}
table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:9px 12px;border-bottom:1px solid var(--line);vertical-align:middle}th{background:var(--lt);font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);font-weight:600;white-space:nowrap}
tr:hover td{background:rgba(0,212,197,.05)}.num{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
.thumb{width:44px;height:44px;object-fit:contain;border-radius:8px;background:#fff;border:1px solid var(--line)}
.badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:600;white-space:nowrap}
.b-boost{background:#fff0d9;color:#a6620b}.b-ex{background:#f4e1e1;color:#a33}.b-ok{background:#e6f8f5;color:var(--teal-d)}.b-un{background:#fde8ea;color:#b4434e}.b-mute{background:#f1ece5;color:var(--muted)}
.btn{display:inline-block;border:1px solid var(--pink);border-radius:8px;padding:7px 14px;font:600 12px "Segoe UI",system-ui,sans-serif;cursor:pointer;background:var(--pink);color:#fff;transition:all .15s}
.btn:hover{background:var(--pink-d);border-color:var(--pink-d);text-decoration:none}.btn.pink{background:var(--pink)}.btn.ghost{background:#fff;color:var(--muted);border:1px solid var(--line)}.btn.ghost:hover{color:var(--teal-d);border-color:var(--teal);background:var(--lt)}.btn.sm{padding:5px 10px;font-size:11px}
form.inline{display:inline}input[type=text],input[type=search],input[type=date],input[type=number],input[type=password],select,textarea{font:inherit;padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:#fff;color:var(--txt);outline:none;transition:border-color .15s}
input:focus,select:focus,textarea:focus{border-color:var(--pink)}
textarea{width:100%;min-height:90px}label.f{display:block;margin-bottom:14px}label.f span{display:block;font-weight:600;margin-bottom:4px}label.f small{display:block;color:var(--muted);font-weight:400}
.filters{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:14px}
.chat{display:flex;flex-direction:column;gap:10px;max-width:760px}.msg{padding:10px 14px;border-radius:14px;max-width:85%;white-space:pre-wrap}
.msg.user{align-self:flex-end;background:var(--pink);color:#fff;border-bottom-right-radius:4px}.msg.assistant{background:#fff;border:1px solid var(--line);border-bottom-left-radius:4px}
.search-note{font-size:12px;color:var(--muted);padding-left:6px}.picks{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px;max-width:760px}
.pick{display:flex;gap:8px;background:#fff;border:1px solid var(--line);border-radius:10px;padding:8px;font-size:12.5px}.pick img{width:48px;height:48px;object-fit:contain}
.chart{display:flex;align-items:flex-end;gap:3px;height:150px;padding-top:10px}.chart .c{flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;gap:2px;height:100%}
.chart .c i{width:100%;background:var(--teal);border-radius:4px 4px 0 0;min-height:1px}.chart .c i.k{background:var(--pink)}.chart .c span{font-size:10px;color:var(--muted)}
.legend{display:flex;gap:14px;font-size:12px;color:var(--muted)}.legend i{display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:5px;vertical-align:-1px}
.pager{display:flex;gap:6px;margin-top:14px}.muted{color:var(--muted)}.right{text-align:right}
</style></head><body><div class="layout">
<nav class="top"><div class="nav-in">
<a class="nav-logo" href="?p=prehlad"><img src="https://noelo.sk/img/design/logo-new-v2-sk.png" alt="Noelo.sk"><span class="nav-badge">Darčekový poradca</span></a>
<span class="nav-div"></span>
<div class="nav-links"><?php foreach ($nav as $k => [$ico, $label]): ?><a href="?p=<?= $k ?>" class="<?= $navKey === $k ? 'on' : '' ?>"><?= $ico ?> <?= h($label) ?></a><?php endforeach; ?></div>
<div class="nav-user"><?= h($user) ?><a href="?p=logout">Odhlásiť</a></div>
</div></nav>
<main>
<?php if ($flash): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>
<?php if ($feedOld): ?><div class="warn">⚠️ Produktový feed bol naposledy vygenerovaný <b><?= fmt_date($lastImport['feed_last_modified']) ?></b>. Ceny a dostupnosť v poradcovi môžu byť zastarané.</div><?php endif; ?>
<?php $demoCount = DemoSeeder::count(); if ($demoCount): ?><div class="info">ℹ️ Admin obsahuje <b><?= $demoCount ?> ukážkových konverzácií</b> (vymyslené, na predvedenie štatistík). Skutočné otázky zákazníkov sa pridávajú naživo.
  <form method="post" class="inline" onsubmit="return confirm('Zmazať všetky ukážkové konverzácie?')"><?= csrf_field() ?><input type="hidden" name="action" value="clear_demo"><button class="btn ghost sm">Zmazať ukážkové dáta</button></form></div><?php endif; ?>
<?php if (Settings::get('enabled') !== '1'): ?><div class="warn">Poradca je momentálne <b>vypnutý</b> – zákazníci ho na webe nevidia. Zapnete ho v Nastaveniach.</div><?php endif; ?>
<?php
$tabs = function (string $p) use ($days) {
    $o = '<div class="tabs">';
    foreach ([7 => '7 dní', 30 => '30 dní', 90 => '90 dní'] as $d => $l) $o .= '<a class="' . ($d === $days ? 'on' : '') . '" href="' . url(['p' => $p, 'd' => $d]) . '">' . $l . '</a>';
    return $o . '</div>';
};

/* ================= PREHĽAD ================= */
if ($page === 'prehlad'):
    $k = Stats::kpis($days);
    $daily = Stats::daily($days);
    $prof = Stats::profiles($days);
    $terms = Stats::searchTerms($days, 12);
    $top = Stats::topProducts($days, 10);
    $unmet = array_slice(Stats::unmet($days), 0, 6);
    $bp = Stats::boostPerformance($days);
    $maxDay = max(1, ...array_values(array_map(fn($d) => $d["conversations"], $daily)));
    $bestsellers = db()->query("SELECT p.id, p.name, p.image, p.available, s.sold_7, s.sold_30,
        (SELECT COUNT(*) FROM recommendations r WHERE r.product_id = p.id AND r.created_at >= '" . date('Y-m-d H:i:s', strtotime("-$days days")) . "') recs
        FROM product_sales s JOIN products p ON p.id = s.product_id WHERE p.active = 1 ORDER BY s.sold_30 DESC LIMIT 10")->fetchAll();
    $salesUpdated = Settings::get('sales_updated');
?>
<h1>Prehľad</h1><p class="sub">Ako zákazníci používajú poradcu a čo hľadajú.</p>
<?= $tabs('prehlad') ?>
<div class="grid g4">
  <div class="card kpi"><div class="l">Konverzácie</div><div class="v"><?= $k['conversations'] ?></div><div class="s"><?= $k['messages'] ?> otázok · Ø <?= str_replace('.', ',', (string)$k['avg_turns']) ?> správy</div></div>
  <div class="card kpi"><div class="l">Dostali odporúčanie</div><div class="v"><?= $k['with_recs'] ?></div><div class="s"><?= $k['recommendations'] ?> odporučených produktov</div></div>
  <div class="card kpi"><div class="l">Klikli na produkt</div><div class="v"><?= pct($k['ctr']) ?></div><div class="s"><?= $k['clicks'] ?> preklikov na noelo.sk</div></div>
  <div class="card kpi"><div class="l">Pridali do košíka</div><div class="v"><?= pct($k['cart_rate']) ?></div><div class="s"><?= $k['carts'] ?> pridaní · <?= $k['unmet'] ?>× „nemáme“</div></div>
</div>
<div class="grid g2" style="margin-top:16px">
  <div class="card"><h2>Konverzácie po dňoch</h2>
    <div class="chart"><?php foreach ($daily as $d => $v): ?><div class="c" title="<?= h(date('j. n.', strtotime($d))) ?>: <?= $v['conversations'] ?> konverzácií, <?= $v['clicks'] ?> preklikov"><i style="height:<?= round(100 * $v['conversations'] / $maxDay) ?>%"></i><?php if ($days <= 30): ?><span><?= date('j.', strtotime($d)) ?></span><?php endif; ?></div><?php endforeach; ?></div>
  </div>
  <div class="card"><h2>Pre koho ľudia hľadajú</h2><?= bar_list($prof['age']) ?><div style="height:14px"></div><?= bar_list($prof['gender'], '#ef7b84') ?></div>
</div>
<div class="grid g3" style="margin-top:16px">
  <div class="card"><h2>Rozpočet</h2><?= bar_list($prof['budget'], '#d9a441') ?></div>
  <div class="card"><h2>Záujmy detí</h2><div class="chips"><?php foreach ($prof['interests'] as $t => $c): ?><span class="chip"><?= h($t) ?><b><?= $c ?></b></span><?php endforeach; ?><?php if (!$prof['interests']): ?><span class="muted">Zatiaľ žiadne údaje.</span><?php endif; ?></div>
    <?php if ($prof['occasion']): ?><h2 style="margin-top:16px">Príležitosť</h2><div class="chips"><?php foreach ($prof['occasion'] as $t => $c): ?><span class="chip"><?= h($t) ?><b><?= $c ?></b></span><?php endforeach; ?></div><?php endif; ?></div>
  <div class="card"><h2>Čo AI najčastejšie hľadala</h2><div class="chips"><?php foreach ($terms as $t => $c): ?><span class="chip"><?= h($t) ?><b><?= $c ?></b></span><?php endforeach; ?><?php if (!$terms): ?><span class="muted">Zatiaľ žiadne údaje.</span><?php endif; ?></div></div>
</div>
<div class="grid g2" style="margin-top:16px">
  <div class="card"><h2>Najčastejšie odporúčané produkty</h2>
    <table><tr><th></th><th>Produkt</th><th class="num">Odporučené</th><th class="num">Prekliky</th></tr>
    <?php foreach ($top as $t): ?><tr><td><?php if ($t['image']): ?><img class="thumb" src="<?= h($t['image']) ?>" alt=""><?php endif; ?></td>
      <td><a href="?p=produkt&id=<?= urlencode($t['product_id']) ?>"><?= h($t['name'] ?? $t['product_id']) ?></a><?php if ($t['boosted']): ?> <span class="badge b-boost">zvýhodnený</span><?php endif; ?></td>
      <td class="num"><?= $t['recs'] ?></td><td class="num"><?= $t['clicks'] ?></td></tr><?php endforeach; ?>
    <?php if (!$top): ?><tr><td colspan="4" class="muted">Zatiaľ žiadne odporúčania.</td></tr><?php endif; ?></table></div>
  <div class="card"><h2>Čo ľudia hľadali a nemáme <a class="btn ghost sm" style="float:right" href="?p=dopyt&d=<?= $days ?>">všetko</a></h2>
    <table><?php foreach ($unmet as $u): ?><tr><td><?= h($u['text']) ?></td><td class="num"><span class="badge b-un"><?= $u['count'] ?>×</span></td></tr><?php endforeach; ?>
    <?php if (!$unmet): ?><tr><td class="muted">Zatiaľ nič – poradca zatiaľ vždy niečo našiel.</td></tr><?php endif; ?></table>
    <h2 style="margin-top:18px">Zvýhodnené vs. ostatné produkty</h2>
    <table><tr><th></th><th class="num">Odporučené</th><th class="num">Prekliky</th><th class="num">Úspešnosť</th></tr>
      <?php foreach ([1 => 'Zvýhodnené', 0 => 'Ostatné'] as $b => $l): $r = $bp[$b]; ?><tr><td><?= $l ?></td><td class="num"><?= $r['recs'] ?></td><td class="num"><?= $r['clicks'] ?></td><td class="num"><?= $r['recs'] ? pct(100 * $r['clicks'] / $r['recs']) : '–' ?></td></tr><?php endforeach; ?></table></div>
</div>
<div class="card" style="margin-top:16px"><h2>🔥 Najpredávanejšie na noelo.sk <span class="muted" style="font-weight:400;font-size:12px">(posledných 30 dní<?= $salesUpdated ? ', obnovené ' . fmt_date($salesUpdated) : '' ?>)</span>
    <a class="btn ghost sm" style="float:right" href="?p=produkty&f=best">všetky</a></h2>
  <?php if ($bestsellers): ?>
  <table><tr><th></th><th>Produkt</th><th class="num">Predané 30 dní</th><th class="num">Predané 7 dní</th><th class="num">Odporučené poradcom (<?= $days ?> dní)</th></tr>
  <?php foreach ($bestsellers as $b): ?><tr><td><?php if ($b['image']): ?><img class="thumb" src="<?= h($b['image']) ?>" alt=""><?php endif; ?></td>
    <td><a href="?p=produkt&id=<?= urlencode((string)$b['id']) ?>"><?= h($b['name']) ?></a><?php if (!$b['available']): ?> <span class="badge b-mute">vypredané</span><?php endif; ?></td>
    <td class="num"><b><?= (int)$b['sold_30'] ?></b> ks</td><td class="num"><?= (int)$b['sold_7'] ?> ks</td>
    <td class="num"><?= (int)$b['recs'] ?><?= (int)$b['recs'] === 0 ? ' <span class="badge b-un">neodporúčaný</span>' : '' ?></td></tr><?php endforeach; ?></table>
  <?php else: ?><p class="muted">Zatiaľ bez dát o predaji. Pripojenie na databázu e-shopu sa nastavuje v <code>config.php</code> (<code>shop_db</code>), stav nájdete v časti <a href="?p=nastavenia">Nastavenia a import</a>.</p><?php endif; ?></div>

<?php /* ================= KONVERZÁCIE ================= */
elseif ($page === 'konverzacie'):
    $q = trim((string)($_GET['q'] ?? ''));
    $f = (string)($_GET['f'] ?? '');
    $pg = max(1, (int)($_GET['pg'] ?? 1));
    $where = ['c.turns > 0']; $params = [];
    if ($q !== '') { $where[] = 'EXISTS(SELECT 1 FROM messages m WHERE m.conversation_id = c.id AND m.content LIKE ?)'; $params[] = '%' . $q . '%'; }
    if ($f === 'unmet') $where[] = "c.unmet <> ''";
    if ($f === 'click') $where[] = "EXISTS(SELECT 1 FROM events e WHERE e.conversation_id = c.id AND e.type = 'click')";
    if ($f === 'cart') $where[] = "EXISTS(SELECT 1 FROM events e WHERE e.conversation_id = c.id AND e.type = 'add_to_cart')";
    if ($f === 'bad') $where[] = 'c.rating = -1';
    if ($f === 'real') $where[] = 'c.is_demo = 0';
    $w = implode(' AND ', $where);
    $cnt = db()->prepare("SELECT COUNT(*) FROM conversations c WHERE $w"); $cnt->execute($params); $total = (int)$cnt->fetchColumn();
    $st = db()->prepare("SELECT c.*, (SELECT content FROM messages m WHERE m.conversation_id = c.id AND m.role = 'user' ORDER BY id LIMIT 1) first_q,
        (SELECT COUNT(*) FROM recommendations r WHERE r.conversation_id = c.id) recs,
        (SELECT COUNT(*) FROM events e WHERE e.conversation_id = c.id AND e.type = 'click') clicks,
        (SELECT COUNT(*) FROM events e WHERE e.conversation_id = c.id AND e.type = 'add_to_cart') carts
        FROM conversations c WHERE $w ORDER BY c.created_at DESC LIMIT 30 OFFSET " . (($pg - 1) * 30));
    $st->execute($params);
?>
<h1>Konverzácie</h1><p class="sub">Čo sa zákazníci pýtali a ako poradca odpovedal. <?= $total ?> konverzácií.</p>
<form class="filters"><input type="hidden" name="p" value="konverzacie"><input type="search" name="q" value="<?= h($q) ?>" placeholder="Hľadať v texte…">
  <select name="f"><option value="">Všetky</option><option value="click" <?= $f === 'click' ? 'selected' : '' ?>>S preklikom na produkt</option><option value="cart" <?= $f === 'cart' ? 'selected' : '' ?>>S pridaním do košíka</option><option value="unmet" <?= $f === 'unmet' ? 'selected' : '' ?>>Nenašlo sa vhodné</option><option value="bad" <?= $f === 'bad' ? 'selected' : '' ?>>Zákazník nebol spokojný 👎</option><option value="real" <?= $f === 'real' ? 'selected' : '' ?>>Len skutočné (bez ukážkových)</option></select>
  <button class="btn">Filtrovať</button></form>
<div class="card" style="padding:6px 8px"><table><tr><th>Kedy</th><th>Prvá otázka</th><th>Profil</th><th class="num">Odporúčania</th><th>Výsledok</th></tr>
<?php foreach ($st->fetchAll() as $c): $p = json_decode($c['profile_json'], true) ?: []; ?>
<tr><td class="muted" style="white-space:nowrap"><?= fmt_date($c['created_at']) ?><?php if ($c['is_demo']): ?> <span class="badge b-demo">ukážková</span><?php endif; ?></td>
  <td><a href="?p=konverzacia&id=<?= urlencode($c['id']) ?>"><?= h(mb_strimwidth((string)$c['first_q'], 0, 90, '…')) ?></a></td>
  <td class="muted"><?= h(implode(' · ', array_filter([isset($p['vek']) ? $p['vek'] . ' r.' : '', $p['pre'] ?? '', !empty($p['rozpocet_do']) ? 'do ' . (int)$p['rozpocet_do'] . ' €' : '']))) ?></td>
  <td class="num"><?= $c['recs'] ?></td>
  <td><?php if ($c['carts']): ?><span class="badge b-ok">🛒 košík</span><?php elseif ($c['clicks']): ?><span class="badge b-ok">klik</span><?php endif; ?>
      <?php if ($c['unmet']): ?><span class="badge b-un">nenašlo</span><?php endif; ?><?php if ((int)$c['rating'] === -1): ?> 👎<?php elseif ((int)$c['rating'] === 1): ?> 👍<?php endif; ?></td></tr>
<?php endforeach; ?></table></div>
<div class="pager"><?php if ($pg > 1): ?><a class="btn ghost sm" href="<?= url(['p' => 'konverzacie', 'q' => $q, 'f' => $f, 'pg' => $pg - 1]) ?>">← novšie</a><?php endif; ?>
<?php if ($pg * 30 < $total): ?><a class="btn ghost sm" href="<?= url(['p' => 'konverzacie', 'q' => $q, 'f' => $f, 'pg' => $pg + 1]) ?>">staršie →</a><?php endif; ?></div>

<?php /* ================= DETAIL KONVERZÁCIE ================= */
elseif ($page === 'konverzacia'):
    $st = db()->prepare('SELECT * FROM conversations WHERE id = ?'); $st->execute([(string)($_GET['id'] ?? '')]); $c = $st->fetch();
    if (!$c): ?><p>Konverzácia neexistuje.</p><?php else:
    $msgs = db()->prepare('SELECT * FROM messages WHERE conversation_id = ? ORDER BY id'); $msgs->execute([$c['id']]);
    $ev = db()->prepare("SELECT product_id, type FROM events WHERE conversation_id = ?"); $ev->execute([$c['id']]);
    $clicked = []; foreach ($ev->fetchAll() as $e) $clicked[$e['product_id']][$e['type']] = true;
    $p = json_decode($c['profile_json'], true) ?: [];
?>
<p><a href="?p=konverzacie">← späť na konverzácie</a></p>
<h1>Konverzácia z <?= fmt_date($c['created_at']) ?></h1>
<p class="sub"><?= h(implode(' · ', array_filter([isset($p['vek']) ? 'vek ' . $p['vek'] : '', $p['pre'] ?? '', !empty($p['rozpocet_do']) ? 'rozpočet do ' . (int)$p['rozpocet_do'] . ' €' : '', implode(', ', (array)($p['zaujmy'] ?? [])), $p['prilezitost'] ?? '']))) ?>
<?php if ($c['unmet']): ?> · <span class="badge b-un">nenašlo: <?= h($c['unmet']) ?></span><?php endif; ?></p>
<div class="chat">
<?php foreach ($msgs->fetchAll() as $m): ?>
  <?php if ($m['role'] === 'assistant'): foreach ((array)json_decode($m['searches_json'], true) as $s): $in = $s['input'] ?? []; ?>
    <div class="search-note">🔎 AI hľadala: „<?= h((string)($in['hladane_vyrazy'] ?? '(bez výrazov)')) ?>“<?= isset($in['vek']) ? ' · vek ' . h((string)$in['vek']) : '' ?><?= !empty($in['pre']) ? ' · ' . h((string)$in['pre']) : '' ?><?= isset($in['cena_do']) ? ' · do ' . h((string)$in['cena_do']) . ' €' : '' ?> → <?= (int)($s['count'] ?? 0) ?> výsledkov</div>
  <?php endforeach; endif; ?>
  <div class="msg <?= $m['role'] ?>"><?= h($m['content']) ?></div>
  <?php $picks = json_decode($m['picks_json'], true) ?: []; if ($picks): ?><div class="picks"><?php foreach ($picks as $pk): ?>
    <div class="pick"><?php if (!empty($pk['image'])): ?><img src="<?= h($pk['image']) ?>" alt=""><?php endif; ?><div><a href="?p=produkt&id=<?= urlencode((string)$pk['id']) ?>"><b><?= h($pk['name']) ?></b></a><br><?= price_fmt((float)$pk['price']) ?>
      <?php if (!empty($clicked[$pk['id']]['add_to_cart'])): ?> <span class="badge b-ok">🛒</span><?php elseif (!empty($clicked[$pk['id']]['click'])): ?> <span class="badge b-ok">klik</span><?php endif; ?><br><span class="muted"><?= h($pk['why'] ?? '') ?></span></div></div>
  <?php endforeach; ?></div><?php endif; ?>
<?php endforeach; ?>
</div>
<?php endif;

/* ================= NESPLNENÝ DOPYT ================= */
elseif ($page === 'dopyt'):
    $rows = Stats::unmet($days);
?>
<h1>Čo ľudia hľadali a nemáme</h1><p class="sub">Požiadavky, pre ktoré poradca nenašiel vhodný produkt. Priamy tip, čo doplniť do sortimentu.</p>
<?= $tabs('dopyt') ?>
<div class="card" style="padding:6px 8px"><table><tr><th>Požiadavka</th><th class="num">Koľkokrát</th><th>Naposledy</th><th>Konverzácie</th></tr>
<?php foreach ($rows as $u): ?><tr><td><?= h($u['text']) ?></td><td class="num"><span class="badge b-un"><?= $u['count'] ?>×</span></td><td class="muted"><?= fmt_date($u['last']) ?></td>
  <td><?php foreach ($u['conversations'] as $i => $cid): ?><a href="?p=konverzacia&id=<?= urlencode($cid) ?>">#<?= $i + 1 ?></a> <?php endforeach; ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="4" class="muted">Za toto obdobie nič.</td></tr><?php endif; ?></table></div>

<?php /* ================= PRODUKTY ================= */
elseif ($page === 'produkty'):
    $q = trim((string)($_GET['q'] ?? '')); $f = (string)($_GET['f'] ?? 'avail'); $cat = trim((string)($_GET['cat'] ?? ''));
    $pg = max(1, (int)($_GET['pg'] ?? 1));
    $where = ['p.active = 1']; $params = [];
    if ($q !== '') { $where[] = '(p.name LIKE ? OR p.brand LIKE ? OR p.id = ?)'; array_push($params, "%$q%", "%$q%", $q); }
    if ($cat !== '') { $where[] = 'p.category LIKE ?'; $params[] = "%$cat%"; }
    if ($f === 'avail') $where[] = 'p.available = 1';
    if ($f === 'boost') $where[] = 'o.boost > 0';
    if ($f === 'ex') $where[] = 'o.excluded = 1';
    if ($f === 'margin') { $where[] = 'p.available = 1 AND p.cost IS NOT NULL AND p.price > 0'; }
    if ($f === 'best') { $where[] = 'p.available = 1 AND s.sold_30 > 0'; }
    $w = implode(' AND ', $where);
    $order = $f === 'margin' ? '(p.price - p.cost) / p.price DESC' : ($f === 'best' ? 's.sold_30 DESC, s.sold_7 DESC' : ($f === 'recs' ? 'recs DESC' : 'COALESCE(o.boost,0) DESC, recs DESC, p.name'));
    $cnt = db()->prepare("SELECT COUNT(*) FROM products p LEFT JOIN product_overrides o ON o.product_id = p.id LEFT JOIN product_sales s ON s.product_id = p.id WHERE $w"); $cnt->execute($params); $total = (int)$cnt->fetchColumn();
    $st = db()->prepare("SELECT p.*, o.boost, o.boost_until, o.boost_reason, o.excluded, COALESCE(s.sold_30, 0) sold_30,
        (SELECT COUNT(*) FROM recommendations r WHERE r.product_id = p.id) recs,
        (SELECT COUNT(*) FROM events e WHERE e.product_id = p.id AND e.type = 'click') clicks
        FROM products p LEFT JOIN product_overrides o ON o.product_id = p.id LEFT JOIN product_sales s ON s.product_id = p.id WHERE $w ORDER BY $order LIMIT 40 OFFSET " . (($pg - 1) * 40));
    $st->execute($params);
    $base = ['p' => 'produkty', 'q' => $q, 'f' => $f, 'cat' => $cat];
?>
<h1>Produkty a zvýhodnenie</h1><p class="sub">Povedzte poradcovi, čo má odporúčať častejšie (napr. tovar dlho na sklade) alebo čo neodporúčať vôbec.</p>
<form class="filters"><input type="hidden" name="p" value="produkty"><input type="search" name="q" value="<?= h($q) ?>" placeholder="Názov, značka alebo ID…">
  <input type="text" name="cat" value="<?= h($cat) ?>" placeholder="Kategória…" style="width:160px">
  <select name="f"><?php foreach (['avail' => 'Dostupné', 'all' => 'Všetky', 'boost' => 'Zvýhodnené', 'ex' => 'Vylúčené', 'best' => '🔥 Bestsellery (predaj 30 dní)', 'recs' => 'Najviac odporúčané', 'margin' => 'Najvyššia marža'] as $k2 => $l): ?><option value="<?= $k2 ?>" <?= $f === $k2 ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
  <button class="btn">Hľadať</button><span class="muted"><?= $total ?> produktov</span></form>
<div class="card" style="padding:6px 8px"><table>
<tr><th></th><th>Produkt</th><th class="num">Cena</th><th class="num">Marža</th><th>Vek · pre</th><th class="num">Predaj 30 d</th><th class="num">Odporuč.</th><th class="num">Kliky</th><th>Zvýhodnenie</th></tr>
<?php foreach ($st->fetchAll() as $p):
    $margin = ($p['cost'] !== null && $p['price'] > 0) ? 100 * ($p['price'] - $p['cost']) / $p['price'] : null;
    $active = (int)$p['boost'] > 0 && (!$p['boost_until'] || $p['boost_until'] >= date('Y-m-d')); ?>
<tr><td><?php if ($p['image']): ?><img class="thumb" src="<?= h($p['image']) ?>" alt="" loading="lazy"><?php endif; ?></td>
  <td><a href="?p=produkt&id=<?= urlencode($p['id']) ?>"><b><?= h($p['name']) ?></b></a><br><span class="muted"><?= h($p['brand']) ?> · <?= h($p['category'] ?: 'bez kategórie') ?></span>
    <?php if (!$p['available']): ?> <span class="badge b-mute">vypredané</span><?php endif; ?><?php if ($p['excluded']): ?> <span class="badge b-ex">vylúčený</span><?php endif; ?></td>
  <td class="num"><?= price_fmt((float)$p['price']) ?></td><td class="num"><?= $margin === null ? '–' : pct($margin) ?></td>
  <td class="muted"><?= $p['age_min'] === null ? '?' : h($p['age_min'] . '–' . ($p['age_max'] ?? '')) ?> · <?= h($p['gender'] ?? '?') ?></td>
  <td class="num"><?= (int)$p['sold_30'] > 0 ? (int)$p['sold_30'] . ' ks' : '<span class="muted">–</span>' ?></td>
  <td class="num"><?= $p['recs'] ?></td><td class="num"><?= $p['clicks'] ?></td>
  <td><form method="post" class="inline" action="<?= url($base + ['pg' => $pg]) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="quick_boost"><input type="hidden" name="id" value="<?= h($p['id']) ?>">
    <select name="boost" onchange="this.form.submit()"><?php foreach (BOOST_LABELS as $b => $l): ?><option value="<?= $b ?>" <?= ($active ? (int)$p['boost'] : 0) === $b ? 'selected' : '' ?>><?= $b ? '⭐ ' . $l : $l ?></option><?php endforeach; ?></select></form>
    <?php if ($active && $p['boost_until']): ?><br><span class="muted" style="font-size:12px">do <?= date('j. n.', strtotime($p['boost_until'])) ?></span><?php endif; ?></td></tr>
<?php endforeach; ?></table></div>
<div class="pager"><?php if ($pg > 1): ?><a class="btn ghost sm" href="<?= url($base + ['pg' => $pg - 1]) ?>">← predchádzajúce</a><?php endif; ?>
<?php if ($pg * 40 < $total): ?><a class="btn ghost sm" href="<?= url($base + ['pg' => $pg + 1]) ?>">ďalšie →</a><?php endif; ?></div>

<?php /* ================= DETAIL PRODUKTU ================= */
elseif ($page === 'produkt'):
    $id = (string)($_GET['id'] ?? '');
    $st = db()->prepare('SELECT p.*, o.boost, o.boost_reason, o.boost_until, o.ai_note, o.excluded, o.age_min o_age_min, o.age_max o_age_max, o.gender o_gender, o.updated_by, o.updated_at o_updated
        FROM products p LEFT JOIN product_overrides o ON o.product_id = p.id WHERE p.id = ?');
    $st->execute([$id]); $p = $st->fetch();
    if (!$p): ?><p>Produkt neexistuje.</p><?php else:
    $s2 = db()->prepare("SELECT (SELECT COUNT(*) FROM recommendations WHERE product_id = ?) recs, (SELECT COUNT(*) FROM events WHERE product_id = ? AND type = 'click') clicks, (SELECT COUNT(*) FROM events WHERE product_id = ? AND type = 'add_to_cart') carts");
    $s2->execute([$id, $id, $id]); $ps = $s2->fetch();
    $margin = ($p['cost'] !== null && $p['price'] > 0) ? 100 * ($p['price'] - $p['cost']) / $p['price'] : null;
?>
<p><a href="?p=produkty">← späť na produkty</a></p>
<div class="grid" style="grid-template-columns:200px 1fr;align-items:start">
  <div class="card"><?php if ($p['image']): ?><img src="<?= h($p['image']) ?>" alt="" style="width:100%;border-radius:10px"><?php endif; ?></div>
  <div><h1><?= h($p['name']) ?></h1>
    <p class="sub"><?= h($p['brand']) ?> · <?= h($p['category'] ?: 'bez kategórie') ?> · <b><?= price_fmt((float)$p['price']) ?></b><?= $margin !== null ? ' · marža ' . pct($margin) : '' ?> · <?= h($p['availability_text']) ?> · <a href="<?= h($p['url']) ?>" target="_blank" rel="noopener">zobraziť na webe ↗</a></p>
    <div class="grid g3"><div class="card kpi"><div class="l">Poradca odporučil</div><div class="v"><?= $ps['recs'] ?>×</div></div><div class="card kpi"><div class="l">Prekliky</div><div class="v"><?= $ps['clicks'] ?></div></div><div class="card kpi"><div class="l">Do košíka</div><div class="v"><?= $ps['carts'] ?></div></div></div>
  </div>
</div>
<div class="grid g2" style="margin-top:16px">
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="action" value="save_product"><input type="hidden" name="id" value="<?= h($p['id']) ?>">
  <h2>⭐ Zvýhodnenie v poradcovi</h2>
  <label class="f"><span>Ako veľmi zvýhodniť</span><select name="boost"><?php foreach (BOOST_LABELS as $b => $l): ?><option value="<?= $b ?>" <?= (int)$p['boost'] === $b ? 'selected' : '' ?>><?= ucfirst($l) ?></option><?php endforeach; ?></select>
    <small>Poradca zvýhodnený produkt uprednostní, ale len ak sedí vekom, rozpočtom a záujmami dieťaťa.</small></label>
  <label class="f"><span>Dôvod</span><select name="boost_reason"><option value="">—</option><?php foreach (BOOST_REASONS as $r): ?><option <?= $p['boost_reason'] === $r ? 'selected' : '' ?>><?= h($r) ?></option><?php endforeach; ?></select></label>
  <label class="f"><span>Platí do (vrátane)</span><input type="date" name="boost_until" value="<?= h($p['boost_until'] ?? '') ?>"><small>Prázdne = bez obmedzenia.</small></label>
  <label class="f"><span>Poznámka pre AI</span><input type="text" name="ai_note" value="<?= h($p['ai_note'] ?? '') ?>" maxlength="200" style="width:100%" placeholder="napr. „Novinka, výborný darček pod stromček.“"><small>AI ju môže využiť pri odporúčaní. Píšte len pravdivé informácie.</small></label>
  <label class="f"><input type="checkbox" name="excluded" value="1" <?= $p['excluded'] ? 'checked' : '' ?>> <b>Nikdy neodporúčať</b> <small>(napr. chybný popis, končiaci tovar)</small></label>
  <h2 style="margin-top:20px">Oprava automatických značiek</h2>
  <p class="muted" style="margin-top:-6px">AI odhadla: vek <?= $p['age_min'] === null ? '?' : h($p['age_min'] . '–' . ($p['age_max'] ?? '')) ?>, pre: <?= h($p['gender'] ?? '?') ?>, záujmy: <?= h($p['interests'] ?: '–') ?></p>
  <div class="grid g3"><label class="f"><span>Vek od</span><input type="number" name="age_min" min="0" max="18" value="<?= h((string)($p['o_age_min'] ?? '')) ?>"></label>
  <label class="f"><span>Vek do</span><input type="number" name="age_max" min="0" max="99" value="<?= h((string)($p['o_age_max'] ?? '')) ?>"></label>
  <label class="f"><span>Pre</span><select name="gender"><option value="">(automaticky)</option><?php foreach (['dievča', 'chlapec', 'unisex'] as $g): ?><option <?= $p['o_gender'] === $g ? 'selected' : '' ?>><?= $g ?></option><?php endforeach; ?></select></label></div>
  <button class="btn pink">Uložiť</button><?php if ($p['updated_by']): ?> <span class="muted">Naposledy upravil <?= h($p['updated_by']) ?>, <?= fmt_date($p['o_updated']) ?></span><?php endif; ?>
</form>
<div class="card"><h2>Ako to vidí AI</h2><p class="muted">Takto dostane AI tento produkt vo výsledkoch vyhľadávania:</p>
<?php $cp = Catalog::find($p['id']); ?>
<pre style="white-space:pre-wrap;background:#faf7f3;padding:12px;border-radius:8px;font-size:12.5px"><?= $cp ? h(Catalog::forAi($cp)) : 'Produkt sa poradcovi momentálne neponúka (je vypredaný, neaktívny alebo vylúčený).' ?></pre>
<h2 style="margin-top:16px">Popis z e-shopu</h2><p style="font-size:13px"><?= nl2br(h(mb_strimwidth($p['description'], 0, 900, '…'))) ?></p></div>
</div>
<?php endif;

/* ================= HROMADNÉ PRAVIDLÁ ================= */
elseif ($page === 'pravidla'):
    $rules = db()->query('SELECT * FROM boost_rules ORDER BY created_at DESC')->fetchAll();
    $brands = db()->query("SELECT brand, COUNT(*) c FROM products WHERE active = 1 AND available = 1 AND brand <> '' GROUP BY brand ORDER BY c DESC LIMIT 200")->fetchAll();
?>
<h1>Hromadné zvýhodnenie</h1><p class="sub">Zvýhodnite celú značku alebo kategóriu naraz, napr. „všetky DJECO do Vianoc“.</p>
<div class="grid g2">
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="action" value="add_rule"><h2>Nové pravidlo</h2>
  <label class="f"><span>Čo zvýhodniť</span><select name="kind"><option value="brand">Značku</option><option value="category">Kategóriu (časť názvu)</option></select></label>
  <label class="f"><span>Názov značky / kategórie</span><input type="text" name="value" list="brands" required style="width:100%"><datalist id="brands"><?php foreach ($brands as $b): ?><option value="<?= h($b['brand']) ?>"><?php endforeach; ?></datalist></label>
  <label class="f"><span>Sila</span><select name="boost"><?php foreach ([1, 2, 3] as $b): ?><option value="<?= $b ?>"><?= ucfirst(BOOST_LABELS[$b]) ?></option><?php endforeach; ?></select></label>
  <label class="f"><span>Dôvod</span><select name="reason"><?php foreach (BOOST_REASONS as $r): ?><option><?= h($r) ?></option><?php endforeach; ?></select></label>
  <label class="f"><span>Platí do</span><input type="date" name="until"></label>
  <button class="btn pink">Pridať pravidlo</button></form>
<div class="card"><h2>Aktívne pravidlá</h2><table><tr><th>Čo</th><th>Sila</th><th>Platí do</th><th></th></tr>
<?php foreach ($rules as $r): $exp = $r['until'] && $r['until'] < date('Y-m-d'); ?><tr style="<?= $exp ? 'opacity:.5' : '' ?>"><td><b><?= h($r['value']) ?></b><br><span class="muted"><?= $r['kind'] === 'brand' ? 'značka' : 'kategória' ?> · <?= h($r['reason']) ?></span></td>
  <td><span class="badge b-boost"><?= BOOST_LABELS[(int)$r['boost']] ?></span></td><td><?= $r['until'] ? date('j. n. Y', strtotime($r['until'])) . ($exp ? ' (vypršalo)' : '') : 'bez obmedzenia' ?></td>
  <td class="right"><form method="post" class="inline" onsubmit="return confirm('Zmazať pravidlo?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_rule"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn ghost sm">Zmazať</button></form></td></tr>
<?php endforeach; ?><?php if (!$rules): ?><tr><td colspan="4" class="muted">Žiadne pravidlá.</td></tr><?php endif; ?></table></div>
</div>

<?php /* ================= UMIESTNENIE A VLOŽENIE ================= */
elseif ($page === 'zobrazenie'):
    $s = Settings::all();
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . preg_replace('~/admin(/.*)?$~', '', (string)parse_url($_SERVER['REQUEST_URI'] ?? '/poradca/admin/', PHP_URL_PATH));
    $snippet = '<script src="' . $base . '/widget/poradca.js" async></script>';
    $seen = json_decode(Settings::get('widget_seen') ?: '{}', true) ?: [];
    $sel = fn($name, $opts) => '<select name="' . $name . '">' . implode('', array_map(fn($v, $l) => '<option value="' . h($v) . '"' . ($s[$name] === $v ? ' selected' : '') . '>' . h($l) . '</option>', array_keys($opts), $opts)) . '</select>';
?>
<h1>Umiestnenie a vloženie</h1><p class="sub">Kde a ako sa poradca zobrazí na webe. Kód na vloženie sa nemení – všetko ostatné nastavíte tu.</p>
<div class="card" style="margin-bottom:16px"><h2>🧩 Kód pre programátora</h2>
  <p>Programátor vloží tento jeden riadok do šablóny e-shopu (ideálne pred <code>&lt;/body&gt;</code> alebo do hlavičky). Stačí to urobiť raz.</p>
  <pre id="snip" style="background:#2f2b26;color:#5de8de;padding:14px;border-radius:10px;white-space:pre-wrap;font-size:13px"><?= h($snippet) ?></pre>
  <button type="button" class="btn" onclick="navigator.clipboard.writeText(document.getElementById('snip').textContent).then(()=>{this.textContent='✓ Skopírované'})">Kopírovať kód</button>
  <p class="muted" style="margin-top:12px">Stav: <?php if ($seen): ?><?php foreach ($seen as $hst => $t): ?><span class="badge b-ok"><?= h($hst) ?></span> naposledy <?= fmt_date($t) ?> &nbsp; <?php endforeach; ?><?php else: ?>poradca sa zatiaľ nenačítal na žiadnom webe.<?php endif; ?></p>
</div>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_display">
<div class="grid g2">
  <div class="card"><h2>Plávajúce tlačidlo</h2>
    <label class="f"><span>Umiestnenie</span><?= $sel('d_position', ['right-bottom' => 'Vpravo dole', 'left-bottom' => 'Vľavo dole', 'right-top' => 'Vpravo hore (napr. pod hlavičkou)', 'left-top' => 'Vľavo hore', 'none' => 'Nezobrazovať (len vložený blok)']) ?></label>
    <div class="grid g2"><label class="f"><span>Odsadenie od okraja (px)</span><input type="number" name="d_offset_x" min="0" max="400" value="<?= h($s['d_offset_x']) ?>"></label>
      <label class="f"><span>Odsadenie zhora/zdola (px)</span><input type="number" name="d_offset_y" min="0" max="600" value="<?= h($s['d_offset_y']) ?>"><small>Napr. 160 = pod hlavičkou webu</small></label></div>
    <label class="f"><span>Text tlačidla</span><input type="text" name="d_button_text" value="<?= h($s['d_button_text']) ?>" maxlength="40" style="width:100%"></label>
    <div class="grid g2"><label class="f"><span>Hlavná farba</span><input type="color" name="d_color_main" value="<?= h($s['d_color_main']) ?>"></label>
      <label class="f"><span>Farba tlačidiel</span><input type="color" name="d_color_accent" value="<?= h($s['d_color_accent']) ?>"></label></div>
    <label class="f"><span>Bublina s textom</span><input type="text" name="d_teaser_text" value="<?= h($s['d_teaser_text']) ?>" maxlength="80" style="width:100%"></label>
    <label class="f"><span>Zobraziť bublinu po (sekundách)</span><input type="number" name="d_teaser_delay" min="0" max="120" value="<?= h($s['d_teaser_delay']) ?>"><small>0 = bublinu nezobrazovať. Zobrazí sa raz za návštevu.</small></label>
  </div>
  <div class="card"><h2>Na ktorých stránkach</h2>
    <label class="f"><span>Zobraziť tlačidlo</span><?= $sel('d_pages_mode', ['all' => 'Na všetkých stránkach', 'home' => 'Len na úvodnej stránke', 'list' => 'Len na vybraných stránkach']) ?></label>
    <label class="f"><span>Vybrané stránky</span><textarea name="d_pages" placeholder="/hracky*&#10;/pre-male-paradnice&#10;/vianocna-kolekcia"><?= h($s['d_pages']) ?></textarea><small>Jedna adresa na riadok, hviezdička = čokoľvek (napr. <code>/hracky*</code>). Platí pri „Len na vybraných stránkach“.</small></label>
    <label class="f"><span>Nikdy nezobrazovať na</span><textarea name="d_exclude"><?= h($s['d_exclude']) ?></textarea><small>Napr. košík a pokladňa, aby poradca nerušil pri nákupe.</small></label>
    <label class="f"><span>Zariadenia</span><?= $sel('d_device', ['all' => 'Počítač aj mobil', 'desktop' => 'Len počítač', 'mobile' => 'Len mobil']) ?></label>
  </div>
  <div class="card"><h2>Vložený blok „Neviete, čo darovať?“</h2>
    <label class="f"><input type="checkbox" name="d_inline_enabled" value="1" <?= $s['d_inline_enabled'] === '1' ? 'checked' : '' ?>> <b>Vložiť blok priamo do stránky</b></label>
    <div class="grid g2"><label class="f"><span>Kam (CSS selektor)</span><input type="text" name="d_inline_selector" value="<?= h($s['d_inline_selector']) ?>" style="width:100%"><small>Na noelo.sk: <code>.secP</code> = prvá sekcia produktov, <code>#mainBan</code> = hlavné bannery</small></label>
      <label class="f"><span>Pozícia</span><?= $sel('d_inline_where', ['before' => 'Pred prvok', 'after' => 'Za prvok', 'prepend' => 'Na začiatok prvku', 'append' => 'Na koniec prvku']) ?></label></div>
    <label class="f"><span>Na ktorých stránkach</span><?= $sel('d_inline_pages_mode', ['home' => 'Len na úvodnej stránke', 'all' => 'Na všetkých stránkach', 'list' => 'Len na vybraných stránkach']) ?></label>
    <label class="f"><span>Vybrané stránky pre blok</span><textarea name="d_inline_pages" style="min-height:60px"><?= h($s['d_inline_pages']) ?></textarea></label>
    <label class="f"><span>Nadpis</span><input type="text" name="d_inline_title" value="<?= h($s['d_inline_title']) ?>" style="width:100%"></label>
    <label class="f"><span>Text</span><input type="text" name="d_inline_text" value="<?= h($s['d_inline_text']) ?>" style="width:100%"></label>
  </div>
  <div class="card"><h2>Košík a povolené weby</h2>
    <label class="f"><span>Tlačidlo „do košíka“ pri produktoch</span><?= $sel('cart_mode', ['real' => 'Pridať do skutočného košíka e-shopu', 'demo' => 'Len simulovať (ukážka)', 'off' => 'Nezobrazovať (len odkaz na produkt)']) ?></label>
    <label class="f"><span>Weby, na ktorých môže poradca bežať</span><textarea name="allowed_origins"><?= h($s['allowed_origins']) ?></textarea><small>Jedna adresa na riadok, napr. <code>https://www.noelo.sk</code>. Na iných weboch poradca odpovedať nebude.</small></label>
    <p class="muted">Ukážka: <a href="<?= h(preg_replace('~/poradca$~', '', $base) ?: '/') ?>/" target="_blank" rel="noopener">otvoriť kópiu úvodnej stránky s poradcom ↗</a></p>
  </div>
</div>
<p style="margin-top:16px"><button class="btn pink">Uložiť nastavenia zobrazenia</button></p>
</form>

<?php /* ================= NASTAVENIA ================= */
elseif ($page === 'nastavenia'):
    $s = Settings::all();
    $prodStats = db()->query('SELECT COUNT(*) total, SUM(available) avail, SUM(age_min IS NOT NULL) tagged FROM products WHERE active = 1')->fetch();
    $runs = db()->query('SELECT * FROM import_runs ORDER BY id DESC LIMIT 5')->fetchAll();
?>
<h1>Nastavenia a import</h1><p class="sub">Správanie poradcu a zdroj produktov.</p>
<div class="grid g2">
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="action" value="save_settings"><h2>Poradca</h2>
  <label class="f"><input type="checkbox" name="enabled" value="1" <?= $s['enabled'] === '1' ? 'checked' : '' ?>> <b>Poradca je zapnutý na webe</b></label>
  <label class="f"><span>Názov</span><input type="text" name="assistant_name" value="<?= h($s['assistant_name']) ?>" style="width:100%"></label>
  <label class="f"><span>Uvítacia správa</span><textarea name="greeting"><?= h($s['greeting']) ?></textarea></label>
  <label class="f"><span>Rýchle voľby na začiatku</span><textarea name="quick_start"><?= h($s['quick_start']) ?></textarea><small>Každá na samostatnom riadku.</small></label>
  <label class="f"><span>Doplňujúce pokyny pre AI</span><textarea name="extra_instructions" placeholder="napr. Pri kolobežke vždy ponúkni aj prilbu. Do Vianoc spomeň, že objednávky do 18. 12. doručíme načas."><?= h($s['extra_instructions']) ?></textarea><small>Platí hneď. Píšte len pravdivé informácie.</small></label>
  <label class="f"><span>Maximálny počet odporúčaní</span><input type="number" name="max_picks" min="1" max="6" value="<?= h($s['max_picks']) ?>"></label>
  <button class="btn pink">Uložiť nastavenia</button></form>
<div class="card"><h2>Produkty</h2>
  <p>Aktívnych produktov: <b><?= (int)$prodStats['total'] ?></b>, dostupných: <b><?= (int)$prodStats['avail'] ?></b>, označených AI: <b><?= (int)$prodStats['tagged'] ?></b></p>
  <p class="muted">Zdroj: <?= config('feed.type') === 'crawl' ? 'priamo z webu noelo.sk (feed googleMerchant.xml je zo 3. 10. 2022)' : h((string)config('feed.url')) ?></p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="run_import"><button class="btn">Spustiť import teraz</button> <span class="muted">Bežne beží automaticky (cron).</span></form>
  <h2 style="margin-top:20px">Predaje (bestsellery)</h2>
  <?php $sn = (int)db()->query('SELECT COUNT(*) FROM product_sales WHERE sold_30 > 0')->fetchColumn(); $su = Settings::get('sales_updated'); ?>
  <?php if (Sales::enabled()): ?>
    <p>Pripojenie na databázu e-shopu: <span class="badge b-ok">nastavené</span> · posledná obnova: <b><?= $su ? fmt_date($su) : 'ešte neprebehla' ?></b> · produktov s predajom za 30 dní: <b><?= $sn ?></b></p>
    <p class="muted">Obnova beží automaticky približne každých <?= (int)(config('sales_refresh_hours') ?: 6) ?> hodín. Poradca podľa nej odporúča bestsellery a zákazník ich nájde cez tlačidlo „Čo sa najviac kupuje".</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="refresh_sales"><button class="btn ghost">Obnoviť predaje teraz</button></form>
  <?php else: ?>
    <p>Pripojenie na databázu e-shopu: <span class="badge b-mute">nenastavené</span></p>
    <p class="muted">Bestsellery sa zapnú po vyplnení <code>shop_db</code> v <code>config.php</code> na serveri (najlepšie používateľ len na čítanie).</p>
  <?php endif; ?>
  <h2 style="margin-top:20px">Posledné importy</h2><table><tr><th>Kedy</th><th>Stav</th><th class="num">Produktov</th><th>Feed vygenerovaný</th></tr>
  <?php foreach ($runs as $r): ?><tr><td><?= fmt_date($r['started_at']) ?></td><td><span class="badge <?= $r['status'] === 'ok' ? 'b-ok' : ($r['status'] === 'error' ? 'b-un' : 'b-mute') ?>"><?= h($r['status']) ?></span></td><td class="num"><?= (int)$r['total'] ?></td><td><?= fmt_date($r['feed_last_modified']) ?></td></tr>
  <?php if ($r['message']): ?><tr><td colspan="4" class="muted" style="font-size:12.5px"><?= h($r['message']) ?></td></tr><?php endif; ?><?php endforeach; ?>
  <?php if (!$runs): ?><tr><td colspan="4" class="muted">Import ešte neprebehol.</td></tr><?php endif; ?></table>
  <h2 style="margin-top:20px">Ukážkové dáta</h2><p class="muted">Na predvedenie štatistík. Ukážkové konverzácie: <?= DemoSeeder::count() ?>.</p>
  <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="seed_demo"><button class="btn ghost">Vytvoriť ukážkové konverzácie</button></form>
  <form method="post" class="inline" onsubmit="return confirm('Zmazať ukážkové konverzácie?')"><?= csrf_field() ?><input type="hidden" name="action" value="clear_demo"><button class="btn ghost">Zmazať ukážkové</button></form></div>
</div>
<?php else: ?><p>Stránka neexistuje.</p><?php endif; ?>
</main></div></body></html>
<?php Maintenance::afterResponse(); // obnova produktov na pozadí, bez cronu
