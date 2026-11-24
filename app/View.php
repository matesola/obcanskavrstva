<?php
// Společné části HTML: hlavička, patička, štítky.

declare(strict_types=1);

namespace Hlidac;

final class View
{
    /** true = stránka nenačte měřicí skript (odpověď s čerstvě vydaným tokenem nemá číst žádný cizí skript) */
    public static bool $noAnalytics = false;

    public const KIND_LABELS = [
        'newbill' => ['mp', 'Nový návrh'],
        'p90' => ['fast', '§ 90/2'],
        'cut' => ['cut', 'Zkrácená lhůta'],
        'surge' => ['surge', 'Nával PN'],
        'pn' => ['surge', 'Nové PN'],
        'passed' => ['mp', 'Schváleno Sněmovnou'],
        'law' => ['mp', 'Vyhlášeno'],
        'senate' => ['surge', 'Senát'],
        'override' => ['fast', 'Přehlasováno'],
        'prep_velmi' => ['cut', 'Připomínky < 10 dní'],
        'prep_kratke' => ['cut', 'Krátké připomínky'],
        'prep_plan' => ['surge', 'Mimo plán vlády'],
        'prep_vlada' => ['mp', 'Schváleno vládou'],
        'prep_narizeni' => ['cut', 'Nařízení: krátké připomínky'],
    ];

    // krátké názvy důvodů skóre podle klíče (karty, obrázky pro sdílení, dokumentace API)
    public const REASON_SHORT = [
        'mp_bill' => 'poslanecký návrh', 'senate_bill' => 'senátní návrh', 'region_bill' => 'krajský návrh', 'p90_passed' => 'schváleno v 1. čtení',
        'p90_requested' => 'návrh na § 90/2', 'deadline_cut' => 'zkrácená lhůta', 'short_gap' => 'málo času na PN',
        'late_pn' => 'PN na poslední chvíli', 'surge' => 'nával PN', 'pn_other_law' => 'přílepek', 'pn_large' => 'rozsáhlý PN',
        'retro_deadline' => 'lhůta a zpětné použití', 'pn_many' => 'více sporných PN', 'ai_bill' => 'sporný text návrhu',
        'ai' => 'sporné pozměňovací návrhy', 'topic' => 'citlivá oblast', 'omnibus' => 'souhrnná novela', 'watched' => 'sledovaný zákon',
    ];

    public static function header(string $title, string $active, string $description = '', array $og = []): void
    {
        $repo = new Repo(db());
        $obdobi = $repo->obdobi();
        $sync = $repo->lastSync();
        // hlavní menu: nejdřív obsah (co se ve Sněmovně a u vlády děje), pak stránky o webu a datech
        $nav = [
            'prehled' => ['index.php', 'Přehled'],
            'tisky' => ['tisky.php', 'Tisky'],
            'zakony' => ['zakon.php', 'Zákony'],
            'upozorneni' => ['upozorneni.php', 'Upozornění'],
            'pripravovane' => ['pripravovane.php', 'Připravované'],
            'metodika' => ['metodika.php', 'Jak hodnotíme'],
            'data' => ['data.php', 'Data a API'],
            'mcp' => ['mcp.php', 'Pro AI asistenty'],
            'zmeny' => ['zmeny.php', 'Co jsme změnili'],
        ];
        $desc = $description !== '' ? $description : 'Pozměňovací návrhy, zrychlená projednávání a zkrácené lhůty v Poslanecké sněmovně.';
        // náhled pro sociální sítě: vlastní obrázek ke každé stránce (og.php), absolutní adresy ze site_url
        $page = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
        $ogUrl = siteUrl((string)($og['url'] ?? ($page === 'index.php' ? '' : $page)));
        $ogImage = siteUrl((string)($og['image'] ?? 'og.php?p=' . rawurlencode($active)));
        $ogTitle = (string)($og['title'] ?? $title);
        $ogAlt = (string)($og['alt'] ?? $ogTitle . ' – Hlídač zákonů');
        ?>
<!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> – Hlídač zákonů</title>
<meta name="description" content="<?= e($desc) ?>">
<link rel="canonical" href="<?= e($ogUrl) ?>">
<meta property="og:type" content="<?= isset($og['url']) ? 'article' : 'website' ?>">
<meta property="og:site_name" content="Hlídač zákonů">
<meta property="og:locale" content="cs_CZ">
<meta property="og:url" content="<?= e($ogUrl) ?>">
<meta property="og:title" content="<?= e($ogTitle) ?>">
<meta property="og:description" content="<?= e($desc) ?>">
<meta property="og:image" content="<?= e($ogImage) ?>">
<meta property="og:image:type" content="image/png">
<meta property="og:image:width" content="<?= OgCard::WIDTH ?>">
<meta property="og:image:height" content="<?= OgCard::HEIGHT ?>">
<meta property="og:image:alt" content="<?= e($ogAlt) ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= e($ogTitle) ?>">
<meta name="twitter:description" content="<?= e($desc) ?>">
<meta name="twitter:image" content="<?= e($ogImage) ?>">
<meta name="twitter:image:alt" content="<?= e($ogAlt) ?>">
<link rel="icon" href="favicon.ico" sizes="32x32">
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<link rel="manifest" href="site.webmanifest">
<meta name="theme-color" content="#15171C">
<link rel="alternate" type="application/rss+xml" title="Hlídač zákonů – upozornění" href="rss.php">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;family=Space+Grotesk:wght@500;600;700&amp;display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=<?= (int)@filemtime(APP_ROOT . '/assets/style.css') ?>">
<?php $umami = (array)config('umami', []); if (!self::$noAnalytics && ($umami['src'] ?? '') !== '' && ($umami['website_id'] ?? '') !== ''): ?>
<script defer src="<?= e($umami['src']) ?>" data-website-id="<?= e($umami['website_id']) ?>"<?php if (($umami['domains'] ?? '') !== ''): ?> data-domains="<?= e($umami['domains']) ?>"<?php endif; ?> data-do-not-track="true"></script>
<?php endif; ?>
</head>
<body>
<header class="top">
  <div class="top-in">
    <a class="brand" href="index.php">
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 21h18"/><path d="M5 21V10"/><path d="M19 21V10"/><path d="M9 21V10"/><path d="M15 21V10"/><path d="M2 10 12 3l10 7z"/></svg>
      <span class="brand-name">Hlídač zákonů</span>
      <span class="brand-sub">Poslanecká sněmovna · <?= $obdobi ?>. volební období</span>
    </a>
    <?php if (!in_array($active, ['prehled', 'hledat', 'tisky'], true)): ?><?php self::searchForm('', 'top'); ?><?php endif; ?>
    <div class="top-row2">
      <nav id="site-nav" aria-label="Hlavní menu">
        <?php foreach ($nav as $key => [$href, $label]): ?>
          <a href="<?= $href ?>"<?= $key === $active ? ' class="active" aria-current="page"' : '' ?>><?= $label ?></a>
        <?php endforeach; ?>
        <a href="rss.php">RSS</a>
      </nav>
      <div class="sync"><?= $sync ? 'Data aktualizována ' . e(formatDate($sync, true)) : 'Data zatím nebyla načtena' ?></div>
    </div>
    <?php // na telefonu je menu jeden posuvný řádek: aktuální stránku posuneme do zorného pole (bez JS zůstane menu na začátku) ?>
    <script>(function(){var n=document.getElementById('site-nav'),a=n&&n.querySelector('a.active');if(a&&n.scrollWidth>n.clientWidth){n.scrollLeft=a.offsetLeft-n.offsetLeft-(n.clientWidth-a.offsetWidth)/2;}})();</script>
  </div>
</header>
<main class="wrap">
<?php
    }

    /** Vyhledávací pole s našeptávačem (assets/search.js); bez JavaScriptu vede na stránku výsledků. */
    public static function searchForm(string $q = '', string $variant = 'hero'): void
    {
        static $n = 0;
        $id = 'q' . (++$n);
        ?>
<form class="search search-<?= e($variant) ?>" action="hledat.php" method="get" role="search" data-search="hledat.php?index=<?= e(SearchIndex::stamp(db())) ?>">
  <div class="search-box">
    <label for="<?= $id ?>" class="sr-only">Hledat tisky, zákony, pozměňovací návrhy a témata</label>
    <input id="<?= $id ?>" name="q" type="search" value="<?= e($q) ?>" placeholder="Tisk, zákon, téma nebo poslanec" autocomplete="off" spellcheck="false"
      role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="<?= $id ?>-list">
    <ul class="suggest" id="<?= $id ?>-list" role="listbox" aria-label="Návrhy" hidden></ul>
  </div>
  <button type="submit">Hledat</button>
</form>
<?php if ($n === 1): ?><script src="assets/search.js?v=<?= (int)@filemtime(APP_ROOT . '/assets/search.js') ?>" defer></script><?php endif; ?>
<?php
    }

    public static function footer(): void
    {
        $issues = (string)config('issues_url', '');
        ?>
<footer class="foot">
  <p>Zdroj: <a href="https://www.psp.cz/sqw/hp.sqw?k=1300">otevřená data Poslanecké sněmovny</a> (tisky, sněmovní dokumenty, poslanci). Počty PN jsou podané písemné pozměňovací návrhy; Sněmovna neeviduje, které z nich byly platně podány ve 2. čtení. Příznaky se počítají automaticky z historie tisku.</p>
  <p class="foot-disclaimer"><strong>Upozornění:</strong> Obsah vzniká automaticky z otevřených dat a část rozborů (shrnutí, titulky a hodnocení závažnosti textů) připravuje umělá inteligence, která se může mýlit. Údaje mají informativní povahu, nejsou právní radou ani oficiálním stanoviskem Sněmovny a před dalším použitím je ověřte v původních zdrojích. <a href="metodika.php#odpovednost">Omezení odpovědnosti</a></p>
  <p><a href="zmeny.php">Co jsme změnili</a> · <a href="zakon.php">Zákony</a> · <a href="data.php">Data a API</a> · <a href="mcp.php">Pro AI asistenty (MCP)</a> · <a href="https://github.com/FrantisekJanos/cosepece">Zdrojový kód (AGPL-3.0)</a><?php if ($issues !== ''): ?> · <a href="<?= e($issues) ?>">Našli jste chybu nebo máte podnět? Napište nám.</a><?php endif; ?></p>
</footer>
</main>
</body>
</html>
<?php
    }

    public static function scoreBadge(int $score): string
    {
        // barevné skóre: závažné / pozor / běžné
        $level = Scorer::level($score, config());
        $labels = [Scorer::LEVEL_MAJOR => 'Závažné', Scorer::LEVEL_WATCH => 'Pozor', Scorer::LEVEL_NORMAL => 'Běžné'];
        return '<span class="score score-' . $level . '" title="Skóre závažnosti ' . $score . ' ze 100">'
            . '<b>' . $score . '</b> ' . $labels[$level] . '</span>';
    }

    public static function reasons(array $reasons, int $max = 0): string
    {
        $list = $max > 0 ? array_slice($reasons, 0, $max) : $reasons;
        if ($list === []) {
            return '';
        }
        $out = '<ul class="reasons">';
        foreach ($list as $r) {
            $out .= '<li><span class="pts">+' . (int)$r['points'] . '</span>' . e($r['label'])
                . (!empty($r['capped']) ? ' <span class="muted small">(strop skupiny)</span>' : '') . '</li>';
        }
        return $out . '</ul>';
    }

    public static function reasonShortLabels(array $reasons, int $max = 3): array
    {
        // krátké názvy hlavních důvodů skóre (karta na homepage, obrázek pro sdílení); plné důvody jsou v detailu tisku
        $short = self::REASON_SHORT;
        $labels = [];
        // důvod s 0 body (např. celý ustřižený stropem skupiny) skóre nezvýšil, do zkratky nepatří
        foreach ($reasons as $r) {
            if ((int)($r['points'] ?? 1) <= 0) {
                continue;
            }
            $labels[] = $short[$r['key']] ?? (string)$r['key'];
            if (count($labels) >= $max) {
                break;
            }
        }
        return $labels;
    }

    public static function reasonKeysSummary(array $reasons, int $max = 3): string
    {
        // krátký výčet hlavních důvodů skóre pro kartu na homepage
        return e(implode(', ', self::reasonShortLabels($reasons, $max)));
    }

    public static function chips(array $flags): string
    {
        $out = '';
        foreach ($flags as [$class, $label]) {
            $out .= '<span class="chip chip-' . e($class) . '">' . e($label) . '</span>';
        }
        return $out;
    }

    public static function kindChip(string $kind): string
    {
        [$class, $label] = self::KIND_LABELS[$kind] ?? ['surge', $kind];
        return '<span class="chip chip-' . e($class) . '">' . e($label) . '</span>';
    }

    /**
     * Dopad na občany z rozboru AI (app/Impact.php): koho se týká, odkdy, povinnosti, pravomoci, sankce,
     * rizika a pojistky. Každá položka má doslovnou citaci (sbalenou). Prázdný řetězec, když dopad chybí.
     */
    public static function impact(?array $ai): string
    {
        $impact = Impact::normalize($ai['dopad'] ?? null);
        if (!Impact::hasContent($impact)) {
            return '';
        }
        $quote = fn(string $q): string => '<details class="impact-quote"><summary>Citace</summary><blockquote class="quote">' . e($q) . '</blockquote></details>';
        $html = '<div class="impact" id="dopad"><h3>Co to znamená pro lidi</h3>';
        if ($impact['skupiny'] !== []) {
            $html .= '<p class="chips"><span class="muted small">Týká se:</span> ';
            foreach (Impact::groupLabels($impact['skupiny']) as $label) {
                $html .= '<span class="chip chip-watch">' . e($label) . '</span> ';
            }
            $html .= '</p>';
        }
        if ($impact['ucinnost'] !== null) {
            $html .= '<div class="impact-when"><strong>Platí od:</strong> ' . e($impact['ucinnost']['text']) . $quote($impact['ucinnost']['citace']) . '</div>';
        }
        $lists = [
            'povinnosti' => ['Nové povinnosti', fn(array $i) => '<strong>' . e($i['kdo']) . ':</strong> ' . e($i['co'])],
            'pravomoci' => ['Nové pravomoci', fn(array $i) => '<strong>' . e($i['kdo']) . ':</strong> ' . e($i['co'])],
            'sankce' => ['Sankce', fn(array $i) => e($i['za_co']) . ($i['vyse'] !== '' ? ': <strong>' . e($i['vyse']) . '</strong>' : '')],
            'rizika' => ['Rizika a pojistky', fn(array $i) => e($i['popis']) . ($i['pojistka'] !== '' ? '<br><span class="muted">Pojistka: ' . e($i['pojistka']) . '</span>' : '')],
        ];
        foreach ($lists as $key => [$title, $line]) {
            if ($impact[$key] === []) {
                continue;
            }
            $html .= '<h4>' . e($title) . '</h4><ul class="impact-list">';
            foreach ($impact[$key] as $item) {
                $html .= '<li>' . $line($item) . $quote($item['citace']) . '</li>';
            }
            $html .= '</ul>';
        }
        $html .= '<p class="muted small">Popis dopadu připravil jazykový model (AI) a může se mýlit. Nehodnotí, zda je změna dobrá, a do skóre nevstupuje.'
            . ($impact['rizika'] !== [] ? ' Rizika popisují, co text umožňuje, ne co kdo zamýšlí; pojistky mohou být i v platném zákoně, který návrh mění.' : '')
            . '</p></div>';
        return $html;
    }

    public static function aiRounds(array $x): string
    {
        // kdo rozbor udělal; po ověření silnějším modelem i výsledek 1. kola
        if (($x['ai_model'] ?? null) === null || $x['ai_score'] === null) {
            return '';
        }
        if ((string)$x['ai_model'] !== AiReviewer::verifyModel(config())) {
            return '<span class="muted small">Rozbor: ' . e((string)$x['ai_model']) . '</span>';
        }
        $prev = $x['ai_prev_score'] !== null ? ' (první kolo ' . e((string)$x['ai_prev_model']) . ': ' . (int)$x['ai_prev_score'] . '/10)' : '';
        return '<span class="muted small">Ověřeno druhým modelem ' . e((string)$x['ai_model']) . $prev . '</span>';
    }

    public static function isPastPeriod(array $alert): bool
    {
        static $current = null;
        $current ??= (int)(db()->getMeta('obdobi_cislo', '0') ?? 0);
        return !empty($alert['obdobi']) && $current > 0 && (int)$alert['obdobi'] !== $current;
    }

    /** @var array<string, array{pid: string, nazev: string, druh: string}>|null PID kterékoli verze materiálu => aktuální řádek */
    private static ?array $upcoming = null;

    /**
     * Připravované zákony podle PID kterékoli verze materiálu (upozornění může nést PID starší verze).
     * Načte se jednou na požadavek; výpis upozornění by se jinak ptal databáze u každé položky.
     */
    public static function upcomingIndex(): array
    {
        if (self::$upcoming === null) {
            self::$upcoming = [];
            foreach (['zakon' => 'pripravovane', 'narizeni' => 'pripravovana_narizeni'] as $kind => $table) {
                foreach (db()->all("SELECT pid, pidy, nazev FROM $table") as $u) {
                    $row = ['pid' => (string)$u['pid'], 'nazev' => (string)$u['nazev'], 'druh' => $kind];
                    foreach (array_filter(array_merge([(string)$u['pid']], explode(',', (string)$u['pidy']))) as $pid) {
                        self::$upcoming[$pid] ??= $row;
                    }
                }
            }
        }
        return self::$upcoming;
    }

    /** Po změně tabulky pripravovane (synchronizace, testy) se tabulka PID načte znovu. */
    public static function forgetUpcoming(): void
    {
        self::$upcoming = null;
    }

    /** Štítek s výsledkem pozměňovacího návrhu ve 3. čtení (PnOutcome) a odkazy na hlasování. */
    public static function pnOutcome(?array $o): string
    {
        if ($o === null) {
            return '';
        }
        $state = (string)$o['stav'];
        $class = match ($state) {
            'prijat', 'castecne' => 'chip chip-fast',
            'zamitnut' => 'chip chip-mp',
            default => 'chip',
        };
        // před 3. čtením stačí písmeno ze souhrnu, výsledek ještě není
        $label = match ($state) {
            'ceka' => 'Zazněl ve 2. čtení',
            default => PnOutcome::STATES[$state] ?? $state,
        };
        $letters = trim((string)$o['pismena']);
        $html = '<span class="' . $class . '">' . e($label) . ($letters !== '' ? ' · ' . e($letters) : '') . '</span>';
        $links = [];
        foreach (array_slice((array)$o['hlasovani'], 0, 8) as $v) {
            $links[] = '<a href="https://www.psp.cz/sqw/hlasy.sqw?g=' . (int)$v['id'] . '">č. ' . (int)$v['cislo']
                . (!empty($v['varianta']) ? ' (var. ' . (int)$v['varianta'] . ')' : '') . ' ' . (($v['vysledek'] ?? '') === 'A' ? 'přijato' : 'zamítnuto') . '</a>';
        }
        $note = ($links !== [] ? 'hlasování ' . implode(', ', $links) : '')
            . (!empty($o['stanovisko']) ? ($links !== [] ? ' · ' : '') . 'stanovisko výboru: ' . e((string)$o['stanovisko']) : '');
        return $html . ($note !== '' ? ' <span class="muted small">' . $note . '</span>' : '');
    }

    public static function alertUrl(array $alert, bool $absolute = false): string
    {
        // připravovaný zákon (bez tisku): na stránku připravovaných, a když už tam není, do VeKLEP
        if (str_starts_with((string)$alert['kind'], 'prep_')) {
            $pid = (string)substr((string)$alert['alert_key'], strpos((string)$alert['alert_key'], ':') + 1);
            $current = self::upcomingIndex()[$pid] ?? null;
            if ($current === null) {
                return GovPrep::url($pid);
            }
            $path = 'pripravovane.php' . ($current['druh'] === 'narizeni' ? '?druh=narizeni' : '') . '#prep-' . rawurlencode($current['pid']);
            return $absolute ? siteUrl($path) : $path;
        }
        // upozornění minulého období vede na psp.cz (tisk se stejným číslem je teď jiný návrh)
        if (self::isPastPeriod($alert)) {
            return self::pspTiskUrl((int)$alert['ct'], (int)$alert['obdobi']);
        }
        $path = 'tisk.php?ct=' . (int)$alert['ct'];
        return $absolute ? siteUrl($path) : $path;
    }

    public static function pspTiskUrl(int $ct, int $obdobi): string
    {
        return 'https://www.psp.cz/sqw/historie.sqw?o=' . $obdobi . '&T=' . $ct;
    }

    public static function pspSdUrl(int $cislo, int $obdobi): string
    {
        return 'https://www.psp.cz/sqw/sd.sqw?cd=' . $cislo . '&o=' . $obdobi;
    }

    public static function alertList(array $alerts): void
    {
        if ($alerts === []) {
            echo '<p class="empty">Zatím žádná upozornění.</p>';
            return;
        }
        foreach ($alerts as $a): ?>
          <article class="alert">
            <div class="alert-when"><?= e(formatDate($a['event_at'], true)) ?></div>
            <div class="alert-body">
              <div class="alert-meta"><?= self::scoreBadge((int)$a['score']) ?><?= self::kindChip($a['kind']) ?><a href="<?= e(self::alertUrl($a)) ?>"><?= (int)$a['ct'] > 0 ? 'tisk ' . (int)$a['ct'] : ($a['kind'] === 'prep_narizeni' ? 'nařízení vlády' : 'připravovaný zákon') ?><?= self::isPastPeriod($a) ? ' (' . (int)$a['obdobi'] . '. období)' : '' ?></a><?= (int)$a['watched'] ? '<span class="chip chip-watch">Sledovaný</span>' : '' ?></div>
              <h3><a href="<?= e(self::alertUrl($a)) ?>"><?= e($a['title']) ?></a></h3>
              <p><?= e($a['body']) ?></p>
            </div>
          </article>
        <?php endforeach;
    }
}
