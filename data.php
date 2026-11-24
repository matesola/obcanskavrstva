<?php
// Dokumentace strojových výstupů (api.php, rss.php) pro vývojáře a novináře: adresy, parametry, pole,
// číselníky, chyby, limity a příklady. Ukázky odpovědí se berou živě z Api, takže nezastarají.

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

use Hlidac\Api;
use Hlidac\GovPrep;
use Hlidac\Repo;
use Hlidac\Scorer;
use Hlidac\View;

$api = new Api(db(), new Repo(db()), config());
$base = siteUrl('api.php');
$w = Scorer::weights(config());

// ukázka odpovědi: první položky seznamů a zkrácené dlouhé texty, ať je vidět struktura
$trim = function ($value, int $depth = 0) use (&$trim) {
    if (is_string($value)) {
        return mb_strlen($value) > 140 ? mb_substr($value, 0, 137) . '…' : $value;
    }
    if (!is_array($value)) {
        return $value;
    }
    $isList = array_is_list($value);
    $out = [];
    foreach ($value as $k => $v) {
        if ($isList && $k >= ($depth === 0 ? 1 : 2)) {
            break;
        }
        $out[$k] = $trim($v, $depth + 1);
    }
    return $out;
};
$example = function (array $query) use ($api, $trim): string {
    [, $body] = $api->handle($query);
    foreach (['tisky', 'upozorneni', 'pripravovane'] as $list) {
        if (isset($body[$list])) {
            $body[$list] = array_slice($body[$list], 0, 1);
        }
    }
    if (isset($body['tisk'])) {
        foreach (['hlasovani', 'upozorneni', 'duvody'] as $list) {
            $body['tisk'][$list] = array_slice($body['tisk'][$list] ?? [], 0, 1);
        }
        if (isset($body['tisk']['hlasovani'][0]['kluby'])) {
            $body['tisk']['hlasovani'][0]['kluby'] = array_slice($body['tisk']['hlasovani'][0]['kluby'], 0, 1);
        }
    }
    return json_encode($trim($body, 1), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
};
// tisk s hlasováním pro ukázku detailu (jinak 221)
$exampleCt = (int)(db()->value('SELECT t.ct FROM tisky t JOIN kroky k ON k.id_tisk = t.id_tisk JOIN hlasovani h ON h.id_hlasovani = k.id_hlasovani ORDER BY t.score DESC LIMIT 1') ?: 221);

$endpoints = [
    'tisky' => ['Tisky', 'api.php', 'Návrhy zákonů aktuálního volebního období seřazené od poslední změny, se skóre a důvody.',
        ['since', 'min_score', 'zakon', 'limit'], ['limit' => '3']],
    'tisk' => ['Detail tisku', 'api.php?tisk={číslo}', 'Jeden tisk: vše jako v seznamu a navíc hlasování ve Sněmovně (i podle klubů), upozornění a stav rozboru textů.',
        [], ['tisk' => (string)$exampleCt]],
    'upozorneni' => ['Upozornění', 'api.php?upozorneni=1', 'Upozornění na kroky v projednávání (nové pozměňovací návrhy, § 90/2, zkrácené lhůty, schválení, vyhlášení) a na připravované zákony u vlády (krátké připomínky, mimo plán), od nejnovějších. Bez min_score jen od úrovně „Pozor“ (' . (int)$w['level_watch'] . ' bodů).',
        ['since', 'min_score', 'zakon', 'ct', 'limit'], ['upozorneni' => '1', 'limit' => '3']],
    'pripravovane' => ['Připravované zákony', 'api.php?pripravovane=1', 'Návrhy zákonů, které připravuje vláda a Sněmovna je ještě nedostala, od nejpokročilejší fáze. S parametrem druh=narizeni připravovaná nařízení vlády (ta do Sněmovny nejdou). Jiné filtry nemá.',
        [], ['pripravovane' => '1']],
];
$filters = [
    'since' => ['YYYY-MM-DD', 'jen tisky změněné / upozornění od tohoto dne (včetně)', 'since=2026-11-01'],
    'min_score' => ['0–100', 'jen se skóre aspoň tolik (alias min)', 'min_score=50'],
    'zakon' => ['číslo/rok', 'jen tisky, které mění daný zákon, a upozornění k nim i k připravovaným změnám tohoto zákona (alias law)', 'zakon=159/2006'],
    'ct' => ['číslo', 'jen upozornění k tomuto tisku', 'ct=221'],
    'limit' => ['1–500', 'nejvýš tolik položek (výchozí 100)', 'limit=20'],
];
$fields = [
    'Tisk' => [
        ['ct', 'číslo', 'číslo sněmovního tisku (v každém volebním období se čísluje znovu od 1)'],
        ['obdobi', 'číslo', 'volební období (10 = od roku 2025)'],
        ['nazev', 'text', 'krátký název podle psp.cz'],
        ['uplny_nazev', 'text', 'úplný název návrhu'],
        ['typ_navrhu', 'text', 'předkladatel: vládní, poslanecký, senátní, krajský'],
        ['navrhovatel', 'text', 'navrhovatel podle psp.cz'],
        ['faze', 'text', 'fáze projednávání (číselník níže)'],
        ['predlozeno', 'datum', 'den předložení Sněmovně (YYYY-MM-DD)'],
        ['posledni_zmena', 'datum a čas', 'poslední krok v historii tisku (YYYY-MM-DD HH:MM:SS)'],
        ['zakony', 'pole textů', 'čísla zákonů, které návrh mění (např. 159/2006)'],
        ['skore', 'číslo', 'skóre závažnosti postupu 0–100'],
        ['uroven', 'text', 'major (od ' . (int)$w['level_major'] . '), watch (od ' . (int)$w['level_watch'] . '), normal'],
        ['duvody', 'pole objektů', 'body skóre: klic (číselník níže), body, popis (čitelný důvod v češtině)'],
        ['priznaky', 'pole textů', 'štítky jako na webu (Poslanecký návrh, Zkrácená lhůta…)'],
        ['url', 'adresa', 'detail tisku na tomto webu'],
        ['psp_url', 'adresa', 'historie tisku na psp.cz'],
    ],
    'Detail tisku (navíc)' => [
        ['hlasovani', 'pole objektů', 'id, datum, vysledek (přijato / zamítnuto), pro, proti, zdrzel, kvorum, kluby (klub, pro, proti, zdrzel_nehlasoval, nepritomen), psp_url'],
        ['upozorneni', 'pole objektů', 'upozornění k tisku (pole jako níže)'],
        ['rozbor_textu', 'objekt', 'posouzeno / celkem: kolik textů (návrh a pozměňovací návrhy) už posoudil rozbor AI'],
        ['rozbor', 'objekt nebo null', 'srozumitelné shrnutí z rozboru textu návrhu (AI): titulek, perex, skupiny (klíče z číselníku dopadu), v detailu i shrnuti a zavaznost (0 až 10); null = text návrhu zatím nemá rozbor; je i v seznamu tisků'],
        ['hlavni_nalez', 'objekt nebo null', 'nejzávažnější nález doložený citací z textu návrhu nebo pozměňovacího návrhu (od závažnosti 4): titulek, perex, citace, zdroj, overeno (ověřeno silnějším modelem)'],
        ['dopad', 'objekt nebo null', 'dopad textu návrhu na občany z rozboru AI (do skóre nevstupuje): skupiny (klic, nazev z pevného číselníku), ucinnost (text, citace nebo null), povinnosti (kdo, co, citace), pravomoci (kdo, co, citace), sankce (za_co, vyse, citace), rizika (popis, pojistka, citace), scenare = nejhorší čtení pro modelovou osobu (titulek, kdo, pribeh, pojistka, citace); každá citace je ověřená v textu návrhu; null = rozbor dopad zatím neobsahuje'],
        ['vysledky_pn', 'pole objektů', 'výsledek písemných pozměňovacích návrhů ve 3. čtení: cislo, pismena (ze souhrnu po 2. čtení), stav (prijat, castecne, zamitnut, nehlasovatelny = podle zpravodaje se o něm nehlasovalo, nezaznel = v souhrnu po 2. čtení není, ceka = 3. čtení ještě neproběhlo, neurceno = nelze určit), stanovisko_vyboru, hlasovani (cislo, vysledek, pismeno, varianta, psp_url); prázdné pole, dokud souhrn a hlasování nejsou'],
        ['senat', 'objekt nebo null', 'průchod Senátem: doruceno, vysledek (schvalen, vracen, zamitnut, nezabyval, necinny, bez_usneseni nebo null), rozhodnuto, lhuta_do (30 dní od doručení, dokud Senát nerozhodl), snemovna (setrvala = přehlasovala Senát, prijala = přijala jeho znění), snemovna_datum, veto, veto_prehlasovano, popis (věty česky); null = návrh do Senátu nedošel'],
    ],
    'Upozornění' => [
        ['id', 'text', 'jednoznačný klíč upozornění (vhodný k odstranění duplicit)'],
        ['typ', 'text', 'druh upozornění (číselník níže)'],
        ['ct', 'číslo nebo null', 'číslo tisku; null u upozornění na připravovaný zákon (typ prep_…), který číslo tisku ještě nemá'],
        ['obdobi', 'číslo', 'volební období tisku'],
        ['datum', 'datum a čas', 'kdy událost nastala'],
        ['titulek', 'text', 'nadpis upozornění'],
        ['text', 'text', 'krátký popis'],
        ['skore', 'číslo', 'skóre tisku + příplatek za druh upozornění; u připravovaného zákona jen body podle druhu (' . implode(', ', array_map(fn($k, $v) => $k . ' ' . $v, array_keys(Hlidac\AlertBuilder::UPCOMING_KINDS), Hlidac\AlertBuilder::UPCOMING_KINDS)) . ')'],
        ['url', 'adresa', 'odkaz na web (detail tisku; u připravovaného zákona stránka připravovaných zákonů nebo VeKLEP)'],
    ],
    'Připravovaný zákon' => [
        ['pid', 'text', 'identifikátor materiálu v eKLEP/VeKLEP'],
        ['druh', 'text', 'zakon (návrh zákona) nebo narizeni (návrh nařízení vlády, s parametrem druh=narizeni)'],
        ['nazev', 'text', 'název materiálu'],
        ['resort', 'text', 'ministerstvo nebo úřad, který návrh připravuje'],
        ['faze', 'text', 'pripominky, po_pripominkach, vlada, schvaleno'],
        ['stav_odok', 'text', 'původní stav v datech Úřadu vlády'],
        ['zalozeno', 'datum', 'založení první verze materiálu'],
        ['zmeneno', 'datum', 'poslední změna kterékoli verze'],
        ['pripominky', 'objekt nebo null', 'od, do, dni, mist (počet připomínkových míst); null = připomínkové řízení nenalezeno'],
        ['vlada_projednala', 'datum nebo null', 'schůze vlády'],
        ['mimo_plan', 'ano/ne', 'předloženo mimo plán legislativních prací'],
        ['vyjimka', 'ano/ne', 'udělena výjimka (např. z povinnosti hodnocení dopadů)'],
        ['popis', 'text', 'o čem návrh je (od předkladatele)'],
        ['duvod', 'text', 'důvod předložení (od předkladatele)'],
        ['veklep_url', 'adresa', 'materiál ve VeKLEP: texty, připomínky, stanovisko Legislativní rady vlády'],
    ],
];
$alertKinds = [
    'newbill' => 'nový poslanecký nebo senátní návrh',
    'p90' => 'navrženo nebo schváleno podle § 90 odst. 2 (bez 2. a 3. čtení)',
    'cut' => 'zkrácení lhůty pro projednání',
    'pn' => 'nové pozměňovací návrhy',
    'surge' => 'nával pozměňovacích návrhů najednou',
    'passed' => 'schváleno Sněmovnou',
    'law' => 'vyhlášeno ve Sbírce zákonů',
    'senate' => 'Senát návrh zamítl, vrátil s pozměňovacími návrhy, odmítl se jím zabývat nebo se nevyjádřil',
    'override' => 'Sněmovna přehlasovala Senát nebo veto prezidenta',
    'prep_velmi' => 'připravovaný zákon: připomínkové řízení kratší než 10 dní',
    'prep_kratke' => 'připravovaný zákon: připomínkové řízení kratší než 3 týdny',
    'prep_plan' => 'připravovaný zákon: předloženo mimo plán legislativních prací vlády',
    'prep_vlada' => 'připravovaný zákon: vláda návrh schválila',
    'prep_narizeni' => 'připravované nařízení vlády: připomínkové řízení kratší než 10 dní',
];
$phases = ['Podáno', 'Organizační výbor', 'U vlády', 'Stanovisko vlády', '1. čtení', '2. čtení', '3. čtení', 'Schváleno Sněmovnou',
    'V Senátu', 'Sněmovna po Senátu', 'U prezidenta', 'Sněmovna po vetu', 'Vyhlášeno', 'Vzat zpět', 'Zamítnuto', 'Vráceno předkladateli'];

$toc = [
    'zaklady' => 'Základy',
    'adresy' => 'Adresy',
    'filtry' => 'Filtry',
    'pole' => 'Popis polí',
    'ciselniky' => 'Číselníky',
    'chyby' => 'Chyby',
    'limity' => 'Limity a slušné chování',
    'mcp' => 'Pro AI asistenty (MCP)',
    'rss' => 'RSS',
    'priklady' => 'Příklady kódu',
    'podminky' => 'Zdroje a podmínky',
];

View::header('Data a API', 'data', 'Jak stáhnout data Hlídače zákonů: JSON API a RSS, adresy, parametry, popis polí a příklady.');
?>
<section class="hero">
  <div>
    <h1>Data a API</h1>
    <p class="lead">Všechno, co je na webu, je i strojově: tisky se skóre a důvody, hlasování, upozornění a připravované zákony ve formátu JSON a upozornění v RSS. Bez registrace a bez klíče.</p>
  </div>
</section>

<div class="docs">
  <nav class="docs-toc card pad" aria-label="Obsah dokumentace">
    <strong>Obsah</strong>
    <ol>
      <?php foreach ($toc as $id => $label): ?>
        <li><a href="#<?= e($id) ?>"><?= e($label) ?></a><?php if ($id === 'adresy'): ?>
          <ol><?php foreach ($endpoints as $key => $ep): ?><li><a href="#api-<?= e($key) ?>"><?= e($ep[0]) ?></a></li><?php endforeach; ?></ol>
        <?php endif; ?></li>
      <?php endforeach; ?>
    </ol>
  </nav>

  <div class="docs-body">
    <section class="card pad" id="zaklady">
      <h2>Základy</h2>
      <p>Rychlý start: <a href="api.php?limit=5"><code>api.php?limit=5</code></a> vrátí pět naposledy změněných tisků.</p>
      <dl class="docs-dl">
        <dt>Adresa</dt><dd><code><?= e($base) ?></code></dd>
        <dt>Metoda</dt><dd>jen <code>GET</code>, jen čtení</dd>
        <dt>Formát</dt><dd>JSON v UTF-8 (<code>Content-Type: application/json</code>), české znaky nejsou escapované</dd>
        <dt>CORS</dt><dd><code>Access-Control-Allow-Origin: *</code> – jde volat přímo z prohlížeče na jiném webu</dd>
        <dt>Cache</dt><dd><code>Cache-Control: public, max-age=300</code> – odpověď se může 5 minut opakovat</dd>
        <dt>Aktualizace</dt><dd>data se obnovují z psp.cz a Úřadu vlády zhruba každou hodinu; čas posledního běhu je v poli <code>aktualizovano</code></dd>
        <dt>Období</dt><dd>jen aktuální volební období Sněmovny</dd>
      </dl>
      <p>Každá odpověď má stejnou obálku:</p>
      <pre class="code"><code>{
  "verze": <?= Api::VERSION ?>,                            // verze formátu
  "zdroj": "<?= e(siteUrl()) ?>",
  "aktualizovano": "2026-11-19 15:01:06",  // poslední synchronizace dat
  "tisky": [ … ]                           // nebo "tisk", "upozorneni", "pripravovane"
}</code></pre>
      <p class="muted small"><strong>Stabilita:</strong> v rámci verze <?= Api::VERSION ?> můžeme přidávat nová pole, ale existující neodebereme ani nepřejmenujeme. Nekompatibilní změnu označíme novou verzí a oznámíme ji v <a href="zmeny.php">přehledu změn</a>. Neznámá pole proto ignorujte.</p>
    </section>

    <section class="card pad" id="adresy">
      <h2>Adresy</h2>
      <?php foreach ($endpoints as $key => [$label, $path, $desc, $params, $query]): ?>
        <?php $exampleUrl = 'api.php?' . http_build_query($query); ?>
        <article class="endpoint" id="api-<?= e($key) ?>">
          <h3><span class="method">GET</span> <code><?= e($path) ?></code></h3>
          <p><strong><?= e($label) ?>.</strong> <?= e($desc) ?></p>
          <?php if ($params !== []): ?><p class="small">Filtry: <?= implode(', ', array_map(fn(string $p) => '<a href="#filtry"><code>' . e($p) . '</code></a>', $params)) ?></p><?php endif; ?>
          <p class="small">Vyzkoušet: <a href="<?= e($exampleUrl) ?>"><code><?= e($exampleUrl) ?></code></a></p>
          <details>
            <summary>Ukázka odpovědi (zkrácená, živá data)</summary>
            <pre class="code"><code><?= e($example($query)) ?></code></pre>
          </details>
        </article>
      <?php endforeach; ?>
    </section>

    <section class="card pad" id="filtry">
      <h2>Filtry</h2>
      <p>Parametry v adrese, lze je kombinovat (např. <a href="api.php?zakon=159/2006&amp;min_score=25"><code>api.php?zakon=159/2006&amp;min_score=25</code></a>). Stejné filtry platí pro RSS.</p>
      <div class="table-wrap"><table class="docs-table">
        <thead><tr><th>Parametr</th><th>Hodnota</th><th>Význam</th><th>Příklad</th></tr></thead>
        <tbody>
          <?php foreach ($filters as $name => [$type, $desc, $ex]): ?>
            <tr><td><code><?= e($name) ?></code></td><td><?= e($type) ?></td><td><?= e($desc) ?></td><td><code><?= e($ex) ?></code></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    </section>

    <section class="card pad" id="pole">
      <h2>Popis polí</h2>
      <?php foreach ($fields as $group => $rows): ?>
        <h3><?= e($group) ?></h3>
        <div class="table-wrap"><table class="docs-table">
          <thead><tr><th>Pole</th><th>Typ</th><th>Význam</th></tr></thead>
          <tbody>
            <?php foreach ($rows as [$name, $type, $desc]): ?>
              <tr><td><code><?= e($name) ?></code></td><td class="nowrap"><?= e($type) ?></td><td><?= e($desc) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endforeach; ?>
    </section>

    <section class="card pad" id="ciselniky">
      <h2>Číselníky</h2>
      <h3>Klíče důvodů skóre (<code>duvody[].klic</code>)</h3>
      <p class="small muted">Body a podmínky jednotlivých signálů popisuje stránka <a href="metodika.php">Jak hodnotíme</a>.</p>
      <div class="table-wrap"><table class="docs-table">
        <tbody>
          <?php foreach (View::REASON_SHORT as $key => $label): ?>
            <tr><td><code><?= e($key) ?></code></td><td><?= e($label) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
      <h3>Druhy upozornění (<code>typ</code>)</h3>
      <div class="table-wrap"><table class="docs-table">
        <tbody>
          <?php foreach ($alertKinds as $key => $label): ?>
            <tr><td><code><?= e($key) ?></code></td><td><?= e($label) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
      <h3>Fáze tisku (<code>faze</code>)</h3>
      <p><?= implode(' · ', array_map(fn(string $p) => '<code>' . e($p) . '</code>', $phases)) ?></p>
      <h3>Fáze připravovaného zákona</h3>
      <p><code>pripominky</code> v připomínkovém řízení · <code>po_pripominkach</code> vypořádání připomínek · <code>vlada</code> předloženo vládě · <code>schvaleno</code> schváleno vládou, čeká na předložení Sněmovně</p>
    </section>

    <section class="card pad" id="chyby">
      <h2>Chyby</h2>
      <p>Chyba vrací HTTP kód a objekt s polem <code>error</code> (bez obálky):</p>
      <div class="table-wrap"><table class="docs-table">
        <thead><tr><th>Kód</th><th>Kdy</th><th>Příklad</th></tr></thead>
        <tbody>
          <tr><td>400</td><td>chybný filtr</td><td><code>{"error": "since musí být datum ve tvaru YYYY-MM-DD"}</code></td></tr>
          <tr><td>404</td><td>tisk v aktuálním období neexistuje</td><td><code>{"error": "Tisk nenalezen"}</code></td></tr>
          <tr><td>429</td><td>příliš mnoho dotazů (viz limity); hlavička <code>Retry-After</code> říká, za kolik sekund to zkusit znovu</td><td><code>{"error": "Příliš mnoho dotazů…"}</code></td></tr>
          <tr><td>5xx</td><td>chyba serveru nebo údržba – zkuste to později</td><td></td></tr>
        </tbody>
      </table></div>
    </section>

    <section class="card pad" id="limity">
      <h2>Limity a slušné chování</h2>
      <ul>
        <li>Nejvýš <strong><?= Hlidac\RateLimit::limit('api', 60) ?> dotazů za minutu</strong> z jedné IP adresy (RSS <?= Hlidac\RateLimit::limit('rss', 30) ?>, webové stránky <?= Hlidac\RateLimit::limit('web', 240) ?>). Při překročení vrátíme 429 s hlavičkou <code>Retry-After</code>.</li>
        <li>Data se mění zhruba jednou za hodinu, častější stahování nic nového nepřinese. Pro průběžné sledování stačí dotaz s <code>since</code> jednou za hodinu, nebo RSS.</li>
        <li>Celé období se vejde do jednoho dotazu (<code>limit=500</code>); není potřeba stahovat tisk po tisku.</li>
        <li>Pokud potřebujete víc (výzkum, hromadná analýza), <a href="<?= e((string)config('issues_url', '')) ?>">napište nám</a>.</li>
      </ul>
    </section>

    <section class="card pad" id="mcp">
      <h2>Pro AI asistenty (MCP)</h2>
      <p>Pro Claude a další AI asistenty je k dispozici MCP server s 21 nástroji nad návrhy zákonů, jejich texty, pozměňovacími návrhy, rozpravami ve Sněmovně a hlasováním. Návod k připojení a přehled nástrojů je na stránce <a href="mcp.php">Pro AI asistenty (MCP)</a>.</p>
    </section>

    <section class="card pad" id="rss">
      <h2>RSS</h2>
      <p><a href="rss.php"><code><?= e(siteUrl('rss.php')) ?></code></a> – upozornění od úrovně „Pozor“, nejnovější nahoře, nejvýš 50 (parametrem <code>limit</code> až 100).</p>
      <ul>
        <li><a href="rss.php?zakon=159/2006"><code>rss.php?zakon=159/2006</code></a> – jen tisky měnící daný zákon</li>
        <li><a href="rss.php?ct=221"><code>rss.php?ct=221</code></a> – jen jeden tisk (všechna jeho upozornění)</li>
        <li><a href="rss.php?min_score=60"><code>rss.php?min_score=60</code></a> – jen to nejzávažnější</li>
        <li><a href="rss.php?vse=1"><code>rss.php?vse=1</code></a> – včetně běžné agendy</li>
      </ul>
    </section>

    <section class="card pad" id="priklady">
      <h2>Příklady kódu</h2>
      <h3>Příkazová řádka (curl + jq)</h3>
      <pre class="code"><code>curl -s '<?= e($base) ?>?min_score=50&amp;limit=500' | jq -r '.tisky[] | "\(.skore)\t\(.ct)\t\(.nazev)"'</code></pre>
      <h3>JavaScript (prohlížeč i Node.js)</h3>
      <pre class="code"><code>const res = await fetch('<?= e($base) ?>?upozorneni=1&amp;since=2026-11-01');
const { upozorneni } = await res.json();
for (const a of upozorneni) console.log(a.datum, a.titulek, a.url);</code></pre>
      <h3>Python</h3>
      <pre class="code"><code>import requests

data = requests.get('<?= e($base) ?>', params={'tisk': 221}, timeout=30).json()
tisk = data['tisk']
print(tisk['skore'], tisk['uroven'])
for d in tisk['duvody']:
    print(f"{d['body']:>3}  {d['popis']}")</code></pre>
    </section>

    <section class="card pad" id="podminky">
      <h2>Zdroje a podmínky</h2>
      <ul>
        <li>Data o tiscích, krocích, pozměňovacích návrzích a hlasování pocházejí z <a href="https://www.psp.cz/sqw/hp.sqw?k=1300">otevřených dat Poslanecké sněmovny</a>, příprava u vlády z <a href="https://odok.gov.cz/portal/katalog/stranky/opendata/">otevřených dat Úřadu vlády</a>.</li>
        <li>Skóre, důvody a upozornění počítá tento web automaticky podle <a href="metodika.php">veřejné metodiky</a>; rozbory textů dělá jazykový model a mohou se mýlit. Hodnotíme postup a formu, ne politický obsah.</li>
        <li>Při dalším použití uveďte zdroj (Hlídač zákonů, <?= e(parse_url(siteUrl(), PHP_URL_HOST) ?: siteUrl()) ?>) a u přebíraných údajů i původní zdroj.</li>
        <li>Zdrojový kód je <a href="https://github.com/FrantisekJanos/cosepece">na GitHubu</a> pod licencí AGPL-3.0. Chyby a nápady na nová data: <a href="<?= e((string)config('issues_url', '')) ?>">GitHub issues</a>.</li>
      </ul>
    </section>
  </div>
</div>
<?php
View::footer();
