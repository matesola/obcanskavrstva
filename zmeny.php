<?php
// Přehled změn webu pro čtenáře: co se opravilo a jak se změnilo hodnocení. Nové záznamy se přidávají nahoru.

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

use Hlidac\View;

// [datum, nadpis, popis, čísla PR na GitHubu, mění skóre?]
$changes = [
    ['2026-11-24', 'Co to znamená pro lidi',
        'Rozbor textu návrhu nově popisuje i dopad na občany: koho se změna týká (z pevného seznamu skupin, například zaměstnanci, OSVČ, rodiny s dětmi, chovatelé), odkdy má platit, jaké přináší nové povinnosti, pravomoci úřadů a sankce, a kde text dává pravomoc nebo výjimku bez jasných mezí (rizika a pojistky). Každá položka musí mít doslovnou citaci, kterou ověříme v textu návrhu; co citaci nemá, nezobrazíme. Dopad nehodnotí, zda je změna dobrá, a do skóre nevstupuje. Je v detailu tisku a v API (api.php?tisk=…, pole dopad). Všechny texty proto AI postupně posoudí znovu; dokud nový rozbor nedoběhne, zůstává zobrazený ten dosavadní, a skóre tisků se při přepočtu může mírně pohnout.',
        [], true],
    ['2026-11-23', 'Všechny hlavní stránky v horním menu',
        'Stránky Zákony, Data a API, Pro AI asistenty a Co jsme změnili byly dosud jen v patičce. Teď jsou v horním menu vedle ostatních. Na telefonu je menu jeden řádek, který se posouvá do strany, takže nezabírá víc místa než dřív.',
        [75], false],
    ['2026-11-23', 'Které pozměňovací návrhy prošly',
        'U tisků po 3. čtení nově u každého písemného pozměňovacího návrhu ukazujeme, zda byl přijat nebo zamítnut, s odkazem na hlasování a se stanoviskem výboru. Poznáme i návrh, který byl podán písemně, ale ve 2. čtení nezazněl. Bereme to ze souhrnu pozměňovacích návrhů, stenozáznamu a výsledků hlasování, bez umělé inteligence. Kde hlasování nejde k návrhu přiřadit jednoznačně, píšeme „nelze určit“. U tisků před 3. čtením ukazujeme jen to, zda návrh ve 2. čtení zazněl. Poznáme i návrh, o kterém se nehlasovalo, protože se stal nehlasovatelným, a společné hlasování o více návrzích. Do skóre to zatím nevstupuje.',
        [72, 73, 74], false],
    ['2026-11-23', 'Rychlejší načítání stránek',
        'Web při každém otevření stránky znovu kontroloval strukturu databáze. Teď to dělá jen po nasazení nové verze, takže se stránky načítají znatelně rychleji.',
        [71], false],
    ['2026-11-21', 'Nové pozměňovací návrhy a kroky do hodiny',
        'Sněmovna vydává otevřená data jednou denně v noci, takže nový pozměňovací návrh nebo krok projednávání se u nás dřív objevil až druhý den ráno. U tisků, které se v posledních dvou týdnech pohnuly nebo jsou na pořadu schůze, teď každou hodinu čteme i stránku historie tisku na psp.cz a nové návrhy a kroky přebíráme hned. Noční data zůstávají hlavním zdrojem: jakmile záznam přinesou, nahradí ten ze stránky. Stránka neuvádí všechny kroky, ty zbylé doplní noční data jako dosud.',
        [], false],
    ['2026-11-21', 'Hlasy jednotlivých poslanců pro AI asistenty',
        'Napojení pro AI asistenty (MCP) nově u každého hlasování vrací, jak hlasoval každý poslanec (pro, proti, zdržel se, nehlasoval, nepřítomen) a ve kterém klubu ten den byl. Ukládáme i hlasování, která se nepodařilo přiřadit ke konkrétnímu tisku (například o pořadu schůze); jdou najít podle čísla hlasování. Data jsou z otevřených dat Sněmovny.',
        [], false],
    ['2026-11-20', 'Senát a kroky po něm',
        'U tisku nově ukazujeme, jak návrh dopadl v Senátu: schválen, vrácen s pozměňovacími návrhy, zamítnut, Senát se jím odmítl zabývat, nebo se ve lhůtě 30 dnů nevyjádřil. U návrhu, který v Senátu právě je, uvádíme konec lhůty. Ukazujeme také, zda Sněmovna Senát přehlasovala nebo přijala jeho znění a zda přehlasovala veto prezidenta. Na jiný výsledek než schválení a na přehlasování chodí upozornění. Do skóre to nevstupuje; data jsou z historie tisku na psp.cz.',
        [61], false],
    ['2026-11-20', 'Připravovaná nařízení vlády',
        'Vedle připravovaných zákonů nově sledujeme i návrhy nařízení vlády. Ta do Sněmovny nejdou, po připomínkovém řízení je schválí jen vláda, a přitom často ukládají nová práva a povinnosti. Na stránce Připravované je přepínač Zákony / Nařízení vlády: u každého nařízení je fáze, délka připomínkového řízení a datum schůze vlády, kratší připomínky jsou označené. Na připomínky kratší než 10 dní chodí upozornění (úroveň Pozor). Data jsou z otevřených dat Úřadu vlády; nelegislativní materiály v nich nejsou.',
        [60], false],
    ['2026-11-20', 'Bezpečnější nasazování nových verzí',
        'Nová verze webu se před nasazením sama otestuje a po nasazení se ověří, že web odpovídá; když ne, vrátí se předchozí verze. Testy běží automaticky u každé navržené změny, nově i proti stejné databázi, jaká je v provozu. Krátký výpadek při výměně verze už nepřeruší odesílání rozborů AI.',
        [62], false],
    ['2026-11-19', 'Opravy po kontrole kódu',
        'Prošli jsme nově přidané části webu a opravili deset nedostatků. Hledání teď snese i chybějící nebo přebývající písmeno a překlep ve slově v jiném tvaru („rozpcet“, „energticky“). Upozornění na připravovaný zákon se neopakuje, když materiál dostane u vlády novou verzi, a odběr RSS nebo dat podle čísla zákona nově obsahuje i upozornění na připravované změny tohoto zákona. U opakovaných rozborů AI platí pojistky i po výpočtu mediánu, takže rozbor se stropem závažnosti strop nepřekročí. Dokumentace dat popisuje upozornění na připravované zákony.',
        [58], false],
    ['2026-11-19', 'Jak funguje napojení pro AI asistenty',
        'Stránka Pro AI asistenty (MCP) nově vysvětluje, kdo co dělá: odpovědi píše váš asistent, náš server jen vydává data. Popisuje hledání podle slov a nově i podle významu, které najde projevy řečené jinými slovy. Uvádí také, že při hledání podle významu odchází text hledaného dotazu do OpenAI, jak to vypnout a co si o voláních zaznamenáváme (jen nástroj, token a čas, ne text dotazů).',
        [57], false],
    ['2026-11-19', 'Druhá bezpečnostní prověrka',
        'Po přidání hledání, stránek zákonů a tokenů pro AI asistenty jsme web znovu prověřili zvenku i nezávislou kontrolou kódu. Opravili jsme chybu, kdy příliš velké číslo v adrese skončilo chybou serveru, a zpřísnili vydávání tokenů: formulář jde odeslat jen z naší stránky a z adresy, která si ho načetla, souběžné pokusy limit nepřekročí a token z webu, který nikdo týden nepoužije nebo ho tři měsíce nepoužívá, přestane platit. Stránky odmítají jiné metody než čtení a soubory začínající tečkou nejsou dostupné.',
        [56], false],
    ['2026-11-19', 'Token pro AI asistenty přímo na webu',
        'Přístupový token k MCP serveru si teď každý vytvoří sám na stránce Pro AI asistenty (MCP), bez registrace a bez e-mailu. Token se zobrazí jen jednou, rovnou i v návodu k připojení; ukládáme jen jeho otisk. Proti zneužití platí nejvýš 3 tokeny denně z jedné adresy a denní strop pro všechny.',
        [55], false],
    ['2026-11-19', 'Napojení pro AI asistenty (MCP)',
        'Nová stránka Pro AI asistenty (MCP) popisuje, jak připojit Claude a další AI asistenty přímo k našim datům: návrhy zákonů se skóre, texty návrhů a pozměňovacích návrhů po paragrafech, celé rozpravy ve Sněmovně, projevy, hlasování a ověření citací. Je tam návod k připojení, přehled 21 nástrojů, ukázkové dotazy a jak získat přístupový token. Odkaz je v patičce a na stránce Data a API.',
        [54], false],
    ['2026-11-19', 'Upozornění na připravované zákony',
        'Upozornění nově hlídají i přípravu zákonů u vlády: připomínkové řízení kratší než 3 týdny (úroveň Pozor, kratší než 10 dní Závažné a jde i na sociální sítě), návrh předložený mimo plán legislativních prací vlády (Pozor) a schválení návrhu vládou (běžná agenda). Upozornění vedou na stránku Připravované, a jakmile návrh dostane číslo sněmovního tisku, do VeKLEP. Jsou v přehledu upozornění, v RSS i v API. Dosavadní případy se zapsaly bez rozeslání.',
        [52], false],
    ['2026-11-19', 'Spolehlivější rozbor AI: opakované běhy a namátková kontrola',
        'Text, kterému rychlý model dá hraniční závažnost 3–7 (tam se rozhoduje, zda dostane body), posoudí třikrát a platí prostřední hodnota; jedno nahodilé hodnocení tak skóre neurčí. Každý desátý text s nízkým hodnocením nově posoudí i silnější model, aby se ukázalo, kolik problémů rychlý model přehlíží; když najde závažný problém, jeho rozbor platí. Na stránce Jak hodnotíme je nový oddíl Jak ověřujeme rozbor AI s průběžnými čísly o shodě modelů. Skóre některých tisků se může změnit, jak doběhnou opakované běhy. Díky podnětu z GitHubu (#7).',
        [50], true],
    ['2026-11-19', 'Stránka zákona',
        'Každý zákon má vlastní stránku (např. zakon.php?z=159/2006, odkaz z detailu tisku i z hledání): všechny tisky tohoto období, které ho mění, pozměňovací návrhy k jiným tiskům, jejichž text tento zákon mění (možné přílepky), a připravované změny u vlády. Odkaz vede i na platné znění v e-Sbírce a na RSS jen k tomuto zákonu. Přehled všech zákonů je v patičce pod odkazem Zákony. Skóre se nemění.',
        [51], false],
    ['2026-11-19', 'Chytré vyhledávání s našeptávačem',
        'Vyhledávání na úvodní stránce a v hlavičce ostatních stránek našeptává už při psaní: tisky (i podle shrnutí rozboru AI, takže „dotace“ najde novelu zákona o střetu zájmů), pozměňovací návrhy a jejich autory, zákony podle čísla i názvu, připravované zákony u vlády a druhy problémů. Hledá se bez ohledu na diakritiku, stačí začátek slova, snese různé tvary slova i jeden překlep. Číslo tisku vede rovnou na tisk. Ovládá se i klávesnicí (šipky, Enter, Esc, „/“ skočí do hledání). Našeptávání běží v prohlížeči, dotazy se nikam neposílají ani neukládají. U tisku jsou nově vidět všechny pozměňovací návrhy, ne jen prvních 30.',
        [49], false],
    ['2026-11-19', 'Upozornění: AI se může mýlit',
        'Na každé stránce je upozornění, že obsah vzniká automaticky a že rozbory, titulky a hodnocení závažnosti připravuje umělá inteligence, která se může mýlit. Stránka Jak hodnotíme má nový oddíl Upozornění a omezení odpovědnosti: co AI dělá a co může pokazit, že skóre není obvinění, že nejde o právní radu a že rozhodující je znění na psp.cz a v e-Sbírce.',
        [48], false],
    ['2026-11-19', 'Řazení a filtr hlavních nálezů',
        'Hlavní nálezy na úvodní stránce jdou seřadit nejen podle celkového skóre, ale i podle závažnosti nálezu v rozboru textu (jen ověřené nálezy doložené citací), podle nejnovějšího dění a podle toho, kde se blíží hlasování ve Sněmovně. Filtr „Co hledám“ vybere jeden druh problému: přílepky, rozsáhlé změny v pozměňovacích návrzích, zkrácené lhůty, § 90/2 bez 2. čtení, obcházení připomínek nebo zpětné použití. Volba je v adrese, takže odkaz jde poslat dál. Přílepky, rozsáhlé změny v PN a zpětné použití jsou nově i mezi filtry na stránce Tisky. Skóre se nemění.',
        [46], false],
    ['2026-11-19', 'Dokumentace dat a API, limity dotazů',
        'Nová stránka Data a API (odkaz v patičce) popisuje všechny strojové výstupy: adresy JSON API a RSS, filtry, význam každého pole, číselníky (důvody skóre, druhy upozornění, fáze), chybové kódy a příklady pro curl, JavaScript a Python. Ukázky odpovědí jsou živá data. Zároveň platí limity počtu dotazů z jedné IP adresy (API 60 za minutu, RSS 30, stránky 240), aby web nešlo snadno přetížit; při překročení vrátí server kód 429. Při bezpečnostní prověrce jsme také vypnuli vypisování chybových hlášení do stránky, přidali ochranu proti hádání přístupových tokenů a bezpečnostní hlavičky prohlížeče.',
        [45], false],
    ['2026-11-19', 'Připravované zákony',
        'Nová stránka Připravované ukazuje návrhy zákonů, které ministerstva připravují a Sněmovna je ještě nedostala: zda jsou v připomínkovém řízení, po něm, u vlády, nebo je vláda už schválila. U každého je délka připomínkového řízení a počet připomínkových míst. Kratší připomínky a nedohledané připomínkové řízení označujeme stejně jako u tisků. Jakmile návrh dostane číslo sněmovního tisku, přesune se mezi tisky. Skóre se nemění. Data: otevřená data Úřadu vlády, také jako api.php?pripravovane=1. Díky podnětu z GitHubu.',
        [44], false],
    ['2026-11-19', 'Příprava návrhu u vlády: připomínkové řízení',
        'U tisku je nově vidět, jak návrh prošel přípravou u vlády: kdy a jak dlouho probíhalo připomínkové řízení, kolik míst se k návrhu vyjadřovalo, kdy ho vláda schválila a proč byl předložen. U poslaneckých návrhů ukazujeme, zda si vláda ke svému stanovisku vyžádala připomínky. Popis bodů za poslanecký návrh jsme upřesnili: dřív tvrdil, že návrh „neprošel připomínkovým řízením“, ale vláda si připomínky ke stanovisku vyžádala u většiny takových návrhů. Skóre se nemění. Data: otevřená data Úřadu vlády. Díky podnětu z GitHubu.',
        [43], false],
    ['2026-11-19', 'Odstup před 3. čtením od rozeslání souhrnu pozměňovacích návrhů',
        'Krátký čas mezi pozměňovacími návrhy a 3. čtením se počítá od skutečného rozeslání souhrnu platně podaných pozměňovacích návrhů (sněmovní tisk X/n), ne od konce rozpravy. Když souhrn rozeslán nebyl (zkrácené jednání), důvod to výslovně uvádí. U tisku je odkaz na tento souhrn. Díky podnětu z GitHubu.',
        [42], true],
    ['2026-11-19', 'Víc hlavních nálezů na úvodní stránce',
        'Hlavní nálezy zahrnují tisky od 45 bodů (dřív od 50) a hned je vidět 9 karet místo 6. Úroveň „Závažné“ se nemění, zůstává od 50 bodů; karty mezi 45 a 49 body mají štítek „Pozor“.',
        [41], false],
    ['2026-11-19', 'Silný ověřený nález se neztratí',
        'Hlavní nálezy na úvodní stránce ukazují i tisky pod hranicí 50 bodů, pokud mají nález AI se závažností aspoň 8/10, který ověřil silnější model a doložil citací z textu. Týká se to např. novely zákona o střetu zájmů (tisk 221), kde rozbor našel zkrácení lhůty pro vymáhání dotací z 10 na 4 roky i pro dřívější případy. Samotné skóre se nemění.',
        [40], false],
    ['2026-11-19', 'Data pro další nástroje a filtrované RSS',
        'Nový strojový výstup api.php vrací tisky, jejich skóre s důvody, hlasování a upozornění ve formátu JSON (např. api.php?tisk=221 nebo api.php?since=2026-11-01&min_score=50). RSS jde filtrovat podle zákona, tisku a skóre (např. rss.php?zakon=159/2006 nebo rss.php?min_score=60).',
        [33], false],
    ['2026-11-19', 'Víc pozměňovacích návrhů neznamená automaticky víc bodů',
        'Čím víc pozměňovacích návrhů tisk má, tím spíš mezi nimi AI najde jeden závažný i náhodou. Plné body za rozbor nejhoršího návrhu proto dostane tisk jen tehdy, když jsou podobně závažné aspoň dva návrhy, nebo aspoň čtvrtina všech. Ojedinělý nález mezi více návrhy dá polovinu bodů. Body za „víc problematických návrhů“ vyžadují kromě aspoň tří takových návrhů i to, aby tvořily aspoň pětinu všech návrhů k tisku (od deseti stačí samotný počet). Na datech z 18. 11. to snížilo skóre dvou tisků, úroveň žádného se nezměnila.',
        [35], true],
    ['2026-11-19', 'Historie verzí textů a rozborů',
        'Web si pamatuje každé znění textu návrhu i pozměňovacího návrhu a každý rozbor AI. Když se text nebo hodnocení změní, je na detailu tisku odkaz „Historie“ se seznamem verzí a s vyznačeným rozdílem mezi nimi. Texty a rozbory z minulého volebního období se po jeho skončení archivují, nemažou.',
        [34], false],
    ['2026-11-19', 'Hlasování ve Sněmovně u každého tisku',
        'Na detailu tisku jsou všechna hlasování k němu: výsledek, počty pro a proti a rozdělení podle poslaneckých klubů, u závěrečných hlasování i to, o jaký krok šlo (např. přehlasované veto). Jmenovité výsledky vedou na psp.cz. Do skóre hlasování nevstupují. Které pozměňovací návrhy prošly, otevřená data zatím neříkají.',
        [32], false],
    ['2026-11-19', 'Stejný nález se nepočítá dvakrát',
        'Když text mění lhůtu pro vymáhání a použije ji zpětně, dával se za to bod pravidlem a k tomu ještě z rozboru AI, který tentýž nález potvrdil. Teď se počítají jen body z rozboru. Týká se to jediného tisku: novela zákona o střetu zájmů (tisk 221) má 47 bodů místo 62.',
        [30], true],
    ['2026-11-19', 'Kalibrace skóre podle připomínek',
        'Poslanecký návrh dostane plné body až tehdy, když skutečně postupuje do 2. čtení. Návrh na schválení v 1. čtení (§ 90/2) se boduje jen u tisku, který se ještě projednává. Pozměňovací návrhy na poslední chvíli se počítají, jen když jde o většinu návrhů k tisku. Citlivá oblast (střet zájmů, dotace, média…) dává 5 bodů místo 15, protože jde o redakční výběr. Vysoké hodnocení pozměňovacího návrhu od AI se naplno počítá až po ověření silnějším modelem. Přílepek se posuzuje i proti zákonům, které mění samotný návrh. Závažných tisků je teď 10 místo 16.',
        [26], true],
    ['2026-11-19', 'Rozbor AI: obě kola a doložení citací',
        'U každého rozboru je vidět, který model ho udělal, a po ověření silnějším modelem i výsledek prvního kola. Titulek nálezu na úvodní stránce se ukáže jen u rozboru doloženého citací, která se ověřila v textu.',
        [22, 23], false],
    ['2026-11-19', 'Přesnější čtení textů',
        'Text ve Wordu se čte bez sledovaných změn (škrtnutý text se nepočítá). Věta „nemá zpětnou účinnost“ už se nepovažuje za zpětnost. Když Sněmovna vymění dokument tisku, web stáhne nové znění a rozbor zopakuje.',
        [20, 22], false],
    ['2026-11-19', 'Údaje o tisku přímo z číselníků Sněmovny',
        'Návrhy krajů a Prahy už nejsou vedené jako poslanecké. Návrh na schválení v 1. čtení se pozná hned z údajů tisku. Zkrácená lhůta pro výbory (např. 30 dní místo 60) se počítá jako zkrácená lhůta. Tisk vrácený předkladateli má správnou fázi.',
        [19, 21], true],
    ['2026-11-19', 'Hledání a upozornění',
        'Hledání tisků nezáleží na diakritice („skolsky“ najde „Školský“). Nával pozměňovacích návrhů se počítá podle dne podání. Tisky se stejným číslem z různých volebních období se nepletou.',
        [19, 21], false],
    ['2026-11-19', 'Náhled odkazu při sdílení',
        'Každá stránka má při sdílení na sociálních sítích obrázek s názvem, a u tisku i se skóre a hlavními důvody.',
        [16, 17], false],
    ['2026-11-18', 'Nový rozbor všech textů',
        'Model AI nově nedostává jméno předkladatele ani typ návrhu, aby hodnocení nemohla ovlivnit politická příslušnost. Všechny texty se posoudily znovu. U tisku je vidět, kolik textů je posouzeno, a „neposouzeno“ se už neplete s „bez problému“.',
        [14, 15], true],
    ['2026-11-18', 'Datum vyhlášení a krátké lhůty',
        '„Vyhlášeno ve Sbírce zákonů“ má skutečné datum vyhlášení, ne datum doručení. Odstup mezi pozměňovacími návrhy a 3. čtením se počítá od skutečného projednávání. Neúplná data ze Sněmovny už nepřepíšou přehled.',
        [12, 13, 14], true],
];

$repo = 'https://github.com/FrantisekJanos/cosepece';
View::header('Co jsme změnili', 'zmeny', 'Přehled oprav a změn v hodnocení na Hlídači zákonů.');
?>
<section class="hero">
  <div>
    <h1>Co jsme změnili</h1>
    <p class="lead">Opravy a změny v tom, jak web čte data Sněmovny a jak počítá skóre. Změny vycházejí hlavně z připomínek čtenářů. Každá má odkaz na konkrétní úpravu kódu.</p>
  </div>
</section>

<section class="card pad" aria-label="Seznam změn">
  <ul class="changes">
    <?php foreach ($changes as [$date, $title, $text, $prs, $score]): ?>
      <li class="change">
        <div class="change-meta">
          <time datetime="<?= e($date) ?>"><?= e(formatDate($date)) ?></time>
          <?php if ($score): ?><span class="chip chip-cut">Mění skóre</span><?php endif; ?>
        </div>
        <h3><?= e($title) ?></h3>
        <p><?= e($text) ?></p>
        <p class="muted small">Úprava kódu: <?= implode(', ', array_map(fn(int $n) => '<a href="' . e($repo . '/pull/' . $n) . '">#' . $n . '</a>', $prs)) ?></p>
      </li>
    <?php endforeach; ?>
  </ul>
  <p class="muted small">Jak se skóre počítá teď, popisuje stránka <a href="metodika.php">Jak hodnotíme</a>. <?php if ((string)config('issues_url', '') !== ''): ?><a href="<?= e((string)config('issues_url', '')) ?>">Našli jste chybu nebo máte podnět? Napište nám.</a><?php endif; ?></p>
</section>
<?php
View::footer();
