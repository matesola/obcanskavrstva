<?php
// Volitelný rozbor textu návrhů zákonů a pozměňovacích návrhů přes Claude (výchozí levný model Haiku):
// backend 'cli' = claude -p přes předplatné (na stroji s Claude Code), 'api' = placené API s klíčem.
// Hodnotí formu a postup (přílepek, zpětná účinnost, zkrácení lhůt, prospěch konkrétního subjektu),
// ne politickou správnost návrhu. Každé zjištění musí být doloženo doslovnou citací, kterou ověříme v textu.

declare(strict_types=1);

namespace Hlidac;

final class AiReviewer
{
    private const API = 'https://api.anthropic.com/v1/messages';

    // verze promptu a pravidel: po změně promptu ji zvyšte, fronta pak postupně znovu posoudí starší rozbory
    // (původní výsledek zůstává zobrazený, dokud ho nový nenahradí). 2 = bez jména předkladatele a typu návrhu (#7),
    // 3 = navíc „dopad“ na občany (app/Impact.php), do závažnosti nevstupuje
    public const PROMPT_VERSION = 3;
    // namátkový vzorek nízkých hodnocení pro silnější model: texty s číslem ≡ SAMPLE_REMAINDER (mod sample_every)
    public const SAMPLE_REMAINDER = 3;

    private const CRITERIA = <<<'TXT'
Nehodnotíš, zda je návrh politicky dobrý nebo špatný. Hodnotíš jen, zda jde o procedurálně problematický zásah, který obchází běžný legislativní proces, typicky:
- přílepek: mění jiný zákon nebo jinou oblast, než které se týká projednávaný návrh,
- zpětná účinnost nebo použití nových pravidel na již vzniklé situace (např. „i tehdy, pokud … přede dnem nabytí účinnosti“),
- zkrácení lhůt pro vymáhání, kontrolu, promlčení nebo prekluzi (např. vracení dotací, odvody za porušení rozpočtové kázně),
- úprava, z níž má zjevně prospěch úzce vymezený nebo konkrétní subjekt či skupina,
- zásadní nová úprava (nový režim, nové instituce, velké výdaje nebo výjimky) vložená pozměňovacím návrhem nebo ustanovením mimo deklarovaný předmět návrhu.
Legislativně-technické úpravy, opravy, posuny účinnosti a drobné věcné změny související s předmětem návrhu mají nízkou závažnost.
Zvlášť závažné je, když se zkrácení lhůty pro vymáhání nebo kontrolu použije i zpětně na již vzniklé případy.
Zpětnost rozlišuj podle směru:
- ve prospěch veřejnosti (rozšíření nebo prodloužení nároků, dávek a lhůt pro občany, plošné snížení povinností, odstranění tvrdosti) je běžná a má nízkou závažnost (0–3),
- závažná je zpětná úprava, která oslabuje vymáhání, kontrolu, sankce nebo odpovědnost, zkracuje lhůty, v nichž může stát něco vymáhat nebo kontrolovat, nebo zvýhodňuje úzkou skupinu,
- zpětné uložení nové povinnosti nebo daně je sporné podle toho, koho a jak tíží.
Zpětnost posuzuj vůči dni nabytí účinnosti zákona, ne vůči dnešku: pozdější datum účinnosti, přechodné období nebo lhůta do budoucna zpětností nejsou.
TXT;

    private const PROMPT_PN = <<<'TXT'
Jsi nestranný legislativní analytik. Posuzuješ písemný pozměňovací návrh poslance k návrhu zákona v Poslanecké sněmovně ČR.
{CRITERIA}

Projednávaný návrh: {BILL}
{HINTS}
Text pozměňovacího návrhu{TRUNCATED}:
<<<
{TEXT}
>>>

{FORMAT}
TXT;

    private const PROMPT_BILL = <<<'TXT'
Jsi nestranný legislativní analytik. Posuzuješ normativní text návrhu zákona v Poslanecké sněmovně ČR (bez důvodové zprávy).
{CRITERIA}
U návrhu zákona hledej hlavně ustanovení, která s deklarovaným účelem návrhu souvisí jen volně nebo která by čtenář názvu nečekal
(např. novela zákona o střetu zájmů, která zároveň zkracuje lhůtu pro vymáhání dotací v rozpočtových pravidlech a použije ji zpětně).

Název návrhu: {BILL}
{HINTS}
Text návrhu{TRUNCATED}:
<<<
{TEXT}
>>>

{FORMAT}
TXT;

    private const FORMAT = <<<'TXT'
Odpověz pouze JSON objektem bez dalšího textu:
{"titulek": "…", "perex": "…", "shrnuti": "1–2 věty, co text mění", "souvisi_s_predmetem": true, "jine_zakony": ["číslo/rok"], "zpetna_ucinnost": false, "zpetnost_smer": "neni", "zkraceni_lhut": false, "prospech_uzke_skupiny": false, "zasadni_nova_uprava": false, "zavaznost": 0, "duvod": "1 věta, proč tato závažnost", "citace": ["doslovně zkopírovaná věta z textu, která zjištění dokládá"], {IMPACT_EXAMPLE}}
"zpetnost_smer": jedna z hodnot "neni", "ve_prospech_verejnosti", "oslabuje_vymahani_nebo_kontrolu", "zvyhodnuje_uzkou_skupinu", "nova_povinnost".
"titulek": nejdůležitější konkrétní změna srozumitelně pro běžného čtenáře, nejvýš 90 znaků, bez názvu zákona a bez čísel zákonů (např. „Lhůta pro vymáhání neoprávněných dotací se zkracuje na 4 roky – i zpětně“). Slovo „zpětně“ použij v titulku jen tehdy, když zpětnost není ve prospěch veřejnosti.
"perex": jedna věta, nejvýš 160 znaků, proč je postup procedurálně sporný (např. zpětné použití, přílepek, zkrácení lhůty). Pokud nic sporného není, popiš věcně hlavní změnu.
Pravidla pro všechny texty: piš jen to, co přímo vyplývá z citovaného textu. Žádná hodnoticí ani expresivní slova, žádné spekulace o motivech, žádná jména osob ani firem, která v textu nejsou. „souvisi_s_predmetem“ dej false jen tehdy, když změna nemá s předmětem ani názvem návrhu nic společného; změny zákonů, které název návrhu výslovně ohlašuje („a další související zákony“), za nesouvisející nepovažuj.
"zavaznost" je celé číslo 0–10 (0 = technická úprava, 10 = závažný procedurální problém).
"citace" obsahuje 0–3 věty zkopírované z textu přesně znak po znaku (bez úprav a zkracování), první citace má dokládat titulek; pokud nic problematického nenajdeš, dej prázdné pole.
{IMPACT}
TXT;

    public function __construct(private Db $db, private array $config)
    {
    }

    public static function apiKey(array $config): string
    {
        // klíč z config.php, jinak z proměnné prostředí ANTHROPIC_API_KEY
        $key = (string)($config['ai']['api_key'] ?? '');
        return $key !== '' ? $key : (string)(getenv('ANTHROPIC_API_KEY') ?: '');
    }

    public static function backend(array $config): string
    {
        // 'cli' = Claude Code přes předplatné (claude -p), 'api' = placené API s klíčem
        return ($config['ai']['backend'] ?? 'cli') === 'api' ? 'api' : 'cli';
    }

    public static function cliPath(array $config): ?string
    {
        // cesta ke claude CLI; na sdíleném hostingu obvykle není, AI pak běží vzdáleně (bin/ai.php --remote)
        if (!function_exists('proc_open')) {
            return null;
        }
        $path = (string)($config['ai']['cli_path'] ?? 'claude');
        if (str_contains($path, '/') || str_contains($path, '\\')) {
            // s cli_prefix (např. [PHP_BINARY] na Windows) stačí, že soubor existuje
            $prefixed = !empty($config['ai']['cli_prefix']);
            return is_file($path) && ($prefixed || is_executable($path)) ? $path : null;
        }
        $dirs = array_merge(explode(PATH_SEPARATOR, (string)getenv('PATH')), [(string)getenv('HOME') . '/.local/bin', '/usr/local/bin', '/opt/homebrew/bin']);
        foreach ($dirs as $dir) {
            if ($dir !== '' && is_file($dir . '/' . $path) && is_executable($dir . '/' . $path)) {
                return $dir . '/' . $path;
            }
        }
        return null;
    }

    public function available(): bool
    {
        // lze AI spustit přímo na tomto stroji?
        if (empty($this->config['ai']['enabled'])) {
            return false;
        }
        return self::backend($this->config) === 'api' ? self::apiKey($this->config) !== '' : self::cliPath($this->config) !== null;
    }

    public static function model(array $config): string
    {
        // levný model pro hrubé třídění všech textů
        return (string)($config['ai']['model'] ?? 'claude-haiku-5-5');
    }

    /** Opakované běhy a namátkový vzorek (#7 bod 7), výchozí hodnoty lze přepsat v config.php → ai. */
    /** Klient AI se zeptal na frontu (ai_api.php?action=queue): meta ai_queue_seen_at, čte ji hlídání dat. */
    public function markPolled(): void
    {
        $this->db->setMeta('ai_queue_seen_at', date('Y-m-d H:i:s'));
    }

    public static function repeatConfig(array $config): array
    {
        $ai = $config['ai'] ?? [];
        return [
            'runs' => max(1, (int)($ai['repeat_runs'] ?? 3)),        // kolik běhů u hraničního hodnocení (medián)
            'min' => (int)($ai['repeat_min'] ?? 3),                  // hraniční pásmo: od…
            'max' => (int)($ai['repeat_max'] ?? 7),                  // …do (výš ověřuje silnější model)
            'sample_every' => max(2, (int)($ai['sample_every'] ?? 10)), // každý n-tý nízko hodnocený text…
            'sample_below' => (int)($ai['sample_below'] ?? 4),        // …s hodnocením pod touto hranicí jde k ověření
        ];
    }

    /**
     * Zbytek po dělení, podle kterého se vybírá namátkový vzorek (číslo textu % sample_every).
     * U sample_every 2 a 3 by pevný zbytek 3 nikdy nenastal a vzorek by byl prázdný.
     */
    public static function sampleRemainder(int $every): int
    {
        return self::SAMPLE_REMAINDER % max(1, $every);
    }

    /** Medián závažností; u sudého počtu nižší ze dvou prostředních (opatrnější). */
    public static function median(array $scores): int
    {
        $s = array_map('intval', array_values($scores));
        sort($s);
        return $s === [] ? 0 : $s[intdiv(count($s) - 1, 2)];
    }

    public static function verifyModel(array $config): string
    {
        // silnější model, který znovu posoudí jen nejzávažnější nálezy (ty jdou na homepage a na sítě)
        return (string)($config['ai']['verify_model'] ?? 'claude-sonnet-5-5');
    }

    public function run(?int $limit = null, ?int $onlyCt = null, bool $verify = false): array
    {
        // rozbor přímo nad místní databází (cron na stroji s CLI nebo s API klíčem);
        // $verify = druhé kolo silnějším modelem nad nálezy se závažností ≥ ai.verify_min
        $stats = ['reviewed' => 0, 'errors' => 0];
        if (!$this->available()) {
            return $stats + ['skipped' => true];
        }
        $model = $verify ? self::verifyModel($this->config) : self::model($this->config);
        foreach ($this->queue($limit ?? (int)($this->config['ai']['per_run'] ?? 20), $onlyCt, $verify) as $item) {
            try {
                $this->saveResult($item['kind'], $item['id'], $this->callModel($item['prompt'], $model), $model);
                $stats['reviewed']++;
            } catch (\Throwable $ex) {
                // chyba ověření nesmaže původní rozbor, jen se zapíše
                $this->saveError($item['kind'], $item['id'], $ex->getMessage());
                $stats['errors']++;
            }
        }
        return $stats;
    }

    public function queue(int $limit, ?int $onlyCt = null, bool $verify = false): array
    {
        // texty čekající na rozbor (bez tisků, které už jsou zákonem): nejdřív samotné návrhy zákonů od nejnověji
        // předložených, pak PN od nejnovějších. Při ověření: už posouzené texty se závažností ≥ ai.verify_min,
        // které silnější model ještě neviděl, od nejzávažnějších tisků.
        $cfg = $this->config['ai'] ?? [];
        $items = [];
        $params = [];
        $filter = '';
        if ($onlyCt !== null) {
            $filter .= ' AND x.ct = ?';
            $params[] = $onlyCt;
        }
        $r = self::repeatConfig($this->config);
        if ($verify) {
            // ověřuje se jen rozbor podle aktuální verze promptu: nálezy od verify_min a namátkový vzorek
            // nízkých hodnocení (#7 bod 7: přehlédnutý problém s nízkým hodnocením by se jinak nikdy nezkontroloval);
            // vzorek je pevný podle čísla textu (každý sample_every-tý), aby šel později vyhodnotit
            // čísla z konfigurace přímo v dotazu (int): vázaný parametr SQLite porovnává jako text
            $where = 'x.ai_at IS NOT NULL AND x.ai_prompt = ? AND (x.ai_model IS NULL OR x.ai_model <> ?)'
                . ' AND (x.ai_score >= ' . (int)($cfg['verify_min'] ?? 6) . ' OR (x.ai_score < ' . $r['sample_below']
                . ' AND x.{ID} % ' . $r['sample_every'] . ' = ' . self::sampleRemainder($r['sample_every']) . '))';
            $params = array_merge([self::PROMPT_VERSION, self::verifyModel($this->config)], $params);
            $orderBills = 't.score DESC, x.ai_score DESC, t.ct DESC';
            $orderPn = 't.score DESC, x.ai_score DESC, p.cas DESC';
        } else {
            // nové texty, pak rozbory podle starší verze promptu (od nejzávažnějších tisků), pak opakované běhy
            // u hraničních hodnocení (#7 bod 7): dokud jich není repeat_runs, posoudí se text znovu a platí medián
            $where = '(x.ai_at IS NULL OR x.ai_prompt IS NULL OR x.ai_prompt < ?'
                . ' OR (x.ai_prompt = ? AND x.ai_model = ? AND COALESCE(x.ai_run_count, 1) < ' . $r['runs']
                . ' AND (COALESCE(x.ai_run_count, 1) > 1 OR x.ai_score BETWEEN ' . $r['min'] . ' AND ' . $r['max'] . ')))';
            $params = array_merge([self::PROMPT_VERSION, self::PROMPT_VERSION, self::model($this->config)], $params);
            $orderBills = 'CASE WHEN x.ai_at IS NULL THEN 0 WHEN x.ai_prompt IS NULL OR x.ai_prompt < ' . self::PROMPT_VERSION . ' THEN 1 ELSE 2 END, t.score DESC, t.predlozeno DESC, t.ct DESC';
            $orderPn = 'CASE WHEN x.ai_at IS NULL THEN 0 WHEN x.ai_prompt IS NULL OR x.ai_prompt < ' . self::PROMPT_VERSION . ' THEN 1 ELSE 2 END, t.score DESC, p.cas DESC';
        }
        $bills = $this->db->all(
            "SELECT x.ct, x.text, x.signals, t.uplny_nazev, t.nazev, t.typ_navrhu FROM tisk_text x
             JOIN tisky t ON t.ct = x.ct
             WHERE " . str_replace('{ID}', 'ct', $where) . " AND x.chars > 0 AND t.faze <> 'Vyhlášeno'$filter
             ORDER BY $orderBills
             LIMIT " . max(0, $limit),
            $params
        );
        foreach ($bills as $row) {
            $text = PnText::normativePart((string)$row['text']);
            $items[] = ['kind' => 'tisk', 'id' => (int)$row['ct'], 'ct' => (int)$row['ct'], 'prompt' => $this->billPrompt($row, $text, $cfg)];
        }
        $left = $limit - count($items);
        if ($left > 0) {
            $rows = $this->db->all(
                "SELECT x.*, p.osoba, t.uplny_nazev FROM pn_text x
                 JOIN pn p ON p.id_dokument = x.id_dokument
                 JOIN tisky t ON t.ct = x.ct
                 WHERE " . str_replace('{ID}', 'id_dokument', $where) . " AND x.chars > 0 AND t.faze <> 'Vyhlášeno'$filter
                 ORDER BY $orderPn LIMIT " . $left,
                $params
            );
            foreach ($rows as $row) {
                $text = PnText::normativePart((string)$row['text']);
                $items[] = ['kind' => 'pn', 'id' => (int)$row['id_dokument'], 'ct' => (int)$row['ct'], 'prompt' => $this->pnPrompt($row, $text, $cfg)];
            }
        }
        return $items;
    }

    public function saveResult(string $kind, int $id, array $result, ?string $model = null): void
    {
        // uložení rozboru; citace ověříme proti textu v naší databázi (vymyšlené zahodíme)
        [$table, $column] = self::target($kind);
        $text = $this->db->value("SELECT text FROM $table WHERE $column = ?", [$id]);
        if ($text === null) {
            throw new \RuntimeException('Neznámý text ' . $kind . ' ' . $id);
        }
        // opožděný výsledek 1. kola nesmí přepsat rozbor, který už ověřil silnější model (#2 bod 4)
        $model = mb_substr($model ?? self::model($this->config), 0, 60);
        $verifier = self::verifyModel($this->config);
        if ($model !== $verifier) {
            $current = $this->db->one("SELECT ai_model, ai_prompt FROM $table WHERE $column = ?", [$id]);
            if ($current !== null && (string)$current['ai_model'] === $verifier && (int)$current['ai_prompt'] === self::PROMPT_VERSION) {
                return;
            }
        }
        $normative = PnText::normativePart((string)$text);
        $result['citace'] = self::verifyQuotes((array)($result['citace'] ?? []), $normative);
        // dopad na občany: položky bez ověřené citace se zahodí (do závažnosti dopad nevstupuje)
        if (array_key_exists('dopad', $result)) {
            [$result['dopad'], $dropped] = Impact::verify($result['dopad'], $normative);
            if ($dropped > 0) {
                $result['upravy'] = array_values(array_unique(array_merge((array)($result['upravy'] ?? []),
                    ['z dopadu vyřazeny položky bez citace ověřené v textu (' . $dropped . ')'])));
            }
        }
        $result = self::sanitize($result);
        // opakovaný běh rychlého modelu u hraničního hodnocení: platí medián běhů a rozbor z běhu,
        // jehož závažnost je mediánu nejblíž (#7 bod 7)
        $runs = [(int)$result['zavaznost']];
        if ($model !== $verifier) {
            $prev = $this->db->one("SELECT ai_json, ai_score, ai_model, ai_prompt, ai_runs, ai_run_count FROM $table WHERE $column = ? AND ai_at IS NOT NULL", [$id]);
            $r = self::repeatConfig($this->config);
            $count = (int)($prev['ai_run_count'] ?? 1) ?: 1;
            if ($prev !== null && (string)$prev['ai_model'] === $model && (int)$prev['ai_prompt'] === self::PROMPT_VERSION && $count < $r['runs']
                && ($count > 1 || ((int)$prev['ai_score'] >= $r['min'] && (int)$prev['ai_score'] <= $r['max']))) {
                $earlier = json_decode((string)$prev['ai_runs'], true);
                $runs = array_merge(is_array($earlier) && $earlier !== [] ? array_map('intval', $earlier) : [(int)$prev['ai_score']], $runs);
                $median = self::median($runs);
                $kept = json_decode((string)$prev['ai_json'], true);
                if (is_array($kept) && abs((int)$kept['zavaznost'] - $median) <= abs((int)$result['zavaznost'] - $median)) {
                    $result = $kept;
                }
                // závažnosti jednotlivých běhů jsou ve sloupci ai_runs (rozbor sám se nemění, historie verzí nebobtná)
                $result['zavaznost'] = $median;
                // pojistky mají přednost před mediánem: ponechaný rozbor může mít strop (zpětnost ve prospěch
                // veřejnosti nejvýš 3, bez ověřené citace nejvýš 2), který medián ostatních běhů nesmí překročit
                $result = self::sanitize($result);
            }
        }
        $own = !$this->db->pdo->inTransaction();
        if ($own) {
            $this->db->pdo->beginTransaction();
        }
        try {
            // historie rozborů (#2 bod 2): dosavadní rozbor, který v ní ještě není, se zapíše dřív, než se přepíše
            Revisions::recordAi($this->db, $kind, $id, 'zaloha');
            if ($model === $verifier) {
                // 2. kolo: dosavadní rozbor 1. kola uschováme vedle (na webu „první kolo: X/10“)
                $this->db->run("UPDATE $table SET ai_prev_json = ai_json, ai_prev_score = ai_score, ai_prev_model = ai_model
                    WHERE $column = ? AND ai_at IS NOT NULL AND (ai_model IS NULL OR ai_model <> ?)", [$id, $verifier]);
            }
            $this->db->run("UPDATE $table SET ai_json = ?, ai_score = ?, ai_at = ?, ai_model = ?, ai_prompt = ?, error = NULL,
                ai_runs = ?, ai_run_count = ? WHERE $column = ?",
                [json_encode($result, JSON_UNESCAPED_UNICODE), (int)$result['zavaznost'], date('Y-m-d H:i:s'),
                    $model, self::PROMPT_VERSION, $model === $verifier ? null : json_encode($runs), $model === $verifier ? null : count($runs), $id]);
            // nová verze rozboru (jen když se liší obsah, model nebo verze promptu)
            Revisions::recordAi($this->db, $kind, $id, 'rozbor');
            if ($own) {
                $this->db->pdo->commit();
            }
        } catch (\Throwable $ex) {
            if ($own) {
                $this->db->pdo->rollBack();
            }
            throw $ex;
        }
    }

    public static function sanitize(array $result): array
    {
        // pojistky nad odpovědí modelu (levný model občas přehání):
        // - zpětnost ve prospěch veřejnosti má nejvýš závažnost 3,
        // - „zpětně“ v titulku jen u zpětnosti, která oslabuje vymáhání, zvýhodňuje úzkou skupinu nebo ukládá povinnost,
        // - nález bez ověřené citace má nejvýš závažnost 2,
        // - titulek a perex pro homepage držíme krátké.
        // Každý zásah zapíšeme do „upravy“, aby byl na webu dohledatelný.
        $notes = [];
        $direction = (string)($result['zpetnost_smer'] ?? 'neni');
        $score = max(0, min(10, (int)($result['zavaznost'] ?? 0)));
        if ($direction === 've_prospech_verejnosti' && $score > 3) {
            $notes[] = 'závažnost snížena z ' . $score . ' na 3: zpětnost je ve prospěch veřejnosti';
            $score = 3;
        }
        // nález bez jediné ověřené citace není doložený → nejvýš 2 (issue #7, bod 2); platí pro výsledky,
        // které prošly ověřením citací v saveResult (mají klíč „citace“), i pro už uložené rozbory (--resanitize)
        $quoted = array_filter((array)($result['citace'] ?? []), fn($q) => trim((string)$q) !== '');
        if (array_key_exists('citace', $result) && $quoted === [] && $score > 2) {
            $notes[] = 'závažnost snížena z ' . $score . ' na 2: žádná citace se nepodařila ověřit v textu';
            $score = 2;
        }
        $result['zavaznost'] = $score;

        $title = trim(preg_replace('~\s+~u', ' ', (string)($result['titulek'] ?? '')) ?? '');
        if (in_array($direction, ['neni', 've_prospech_verejnosti'], true) && preg_match('~\bzpětně\b~iu', $title)) {
            $title = self::stripRetroWord($title);
            $notes[] = 'z titulku odstraněno „zpětně“: rozbor neuvádí zpětnost v neprospěch veřejnosti';
        }
        $result['titulek'] = $title;
        foreach (['titulek' => 120, 'perex' => 220] as $field => $max) {
            $value = trim(preg_replace('~\s+~u', ' ', (string)($result[$field] ?? '')) ?? '');
            $result[$field] = mb_strlen($value) > $max ? rtrim(mb_substr($value, 0, $max - 1)) . '…' : $value;
        }
        // dopad na občany: skupiny jen z číselníku, položky jen s citací (jen u rozborů, které dopad mají)
        if (array_key_exists('dopad', $result)) {
            $result['dopad'] = Impact::normalize($result['dopad']);
        }
        if ($notes !== []) {
            $result['upravy'] = array_values(array_unique(array_merge((array)($result['upravy'] ?? []), $notes)));
        }
        return $result;
    }

    public static function stripRetroWord(string $title): string
    {
        // odstraní „(– | , | tedy) (i | a) zpětně“ i samostatné „zpětně“ a uklidí interpunkci
        $t = preg_replace('~\s*[–—-]\s*(?:(?:tedy|a|i)\s+)*zpětně\b~iu', '', $title) ?? $title;
        $t = preg_replace('~,?\s+(?:(?:tedy|a|i|to)\s+)*zpětně\b~iu', '', $t) ?? $t;
        $t = preg_replace('~^zpětně\s+~iu', '', $t) ?? $t;
        $t = trim(preg_replace('~\s+~u', ' ', $t) ?? $t, " \t,;–—-");
        return $t === '' ? $t : mb_strtoupper(mb_substr($t, 0, 1)) . mb_substr($t, 1);
    }

    public function resanitizeStored(): int
    {
        // znovu použije pojistky na už uložené rozbory (po změně pravidel), bez volání modelu
        $n = 0;
        foreach (['tisk', 'pn'] as $kind) {
            [$table, $column] = self::target($kind);
            foreach ($this->db->all("SELECT $column AS id, ai_json, ai_score FROM $table WHERE ai_at IS NOT NULL AND ai_json IS NOT NULL") as $row) {
                $ai = json_decode((string)$row['ai_json'], true);
                if (!is_array($ai)) {
                    continue;
                }
                $clean = self::sanitize($ai);
                if ($clean !== $ai || (int)$clean['zavaznost'] !== (int)$row['ai_score']) {
                    // původní i opravený rozbor zůstanou v historii rozborů
                    Revisions::recordAi($this->db, $kind, (int)$row['id'], 'zaloha');
                    $this->db->run("UPDATE $table SET ai_json = ?, ai_score = ? WHERE $column = ?",
                        [json_encode($clean, JSON_UNESCAPED_UNICODE), (int)$clean['zavaznost'], (int)$row['id']]);
                    Revisions::recordAi($this->db, $kind, (int)$row['id'], 'pojistky');
                    $n++;
                }
            }
        }
        return $n;
    }

    public function saveError(string $kind, int $id, string $message): void
    {
        // chybu zapíšeme; ai_at zůstane prázdné a další běh to zkusí znovu
        [$table, $column] = self::target($kind);
        $this->db->run("UPDATE $table SET error = ? WHERE $column = ?", [mb_substr(Http::redact($message), 0, 500), $id]);
    }

    private static function target(string $kind): array
    {
        return match ($kind) {
            'tisk' => ['tisk_text', 'ct'],
            'pn' => ['pn_text', 'id_dokument'],
            default => throw new \InvalidArgumentException('Neznámý druh textu: ' . $kind),
        };
    }

    private function billPrompt(array $row, string $text, array $cfg): string
    {
        // typ a předkladatel se modelu záměrně neposílají: chybějící připomínkové řízení boduje Scorer
        // (nesčítat dvakrát) a jméno ani strana nemají vliv na posouzení postupu (#7)
        [$body, $truncated] = self::clip($text, (int)($cfg['max_chars'] ?? 150000));
        return strtr(self::PROMPT_BILL, [
            '{CRITERIA}' => self::CRITERIA,
            '{BILL}' => (string)($row['uplny_nazev'] ?: $row['nazev']),
            '{HINTS}' => self::hints((string)$row['signals']),
            '{TRUNCATED}' => $truncated ? ' (zkráceno)' : '',
            '{TEXT}' => $body,
            '{FORMAT}' => self::format(),
        ]);
    }

    private function pnPrompt(array $row, string $text, array $cfg): string
    {
        [$body, $truncated] = self::clip($text, (int)($cfg['max_chars'] ?? 150000));
        return strtr(self::PROMPT_PN, [
            '{CRITERIA}' => self::CRITERIA,
            '{BILL}' => (string)$row['uplny_nazev'],
            '{HINTS}' => self::hints((string)$row['signals']),
            '{TRUNCATED}' => $truncated ? ' (zkráceno)' : '',
            '{TEXT}' => $body,
            '{FORMAT}' => self::format(),
        ]);
    }

    public static function format(): string
    {
        // formát odpovědi včetně dopadu na občany (app/Impact.php)
        return strtr(self::FORMAT, ['{IMPACT_EXAMPLE}' => Impact::FORMAT_EXAMPLE, '{IMPACT}' => Impact::prompt()]);
    }

    private static function clip(string $text, int $maxChars): array
    {
        return mb_strlen($text) > $maxChars ? [mb_substr($text, 0, $maxChars), true] : [$text, false];
    }

    private static function hints(string $signalsJson): string
    {
        // pravidlové nálezy předáme modelu jako vodítko, které má ověřit (ne převzít)
        $signals = json_decode($signalsJson, true) ?: [];
        $lines = [];
        foreach ($signals['retro'] ?? [] as $q) {
            $lines[] = '- možné zpětné použití: „' . $q . '“';
        }
        foreach ($signals['deadline'] ?? [] as $q) {
            $lines[] = '- změna lhůty: „' . $q . '“';
        }
        if (!empty($signals['other_laws'])) {
            $lines[] = '- mění i zákony: ' . implode(', ', $signals['other_laws']);
        }
        return $lines === [] ? '' : "Automatická pravidla upozornila na tato místa (ověř, zda jde opravdu o problém):\n" . implode("\n", $lines) . "\n";
    }

    public static function verifyQuotes(array $quotes, string $text): array
    {
        // ponechá jen citace, které se v textu vyskytují (po sjednocení mezer a uvozovek)
        $norm = fn(string $s): string => mb_strtolower(trim(preg_replace('~\s+~u', ' ', str_replace(['„', '“', '”', '"', '‚', '‘', '’'], '', $s)) ?? $s));
        $haystack = $norm($text);
        $out = [];
        foreach ($quotes as $q) {
            $q = trim((string)$q);
            $needle = $norm(rtrim($q, '.…'));
            if (mb_strlen($needle) >= 15 && str_contains($haystack, $needle)) {
                $out[] = mb_substr($q, 0, 600);
            }
        }
        return array_slice(array_values(array_unique($out)), 0, 3);
    }

    public function callModel(string $prompt, ?string $model = null): array
    {
        $model ??= self::model($this->config);
        return self::backend($this->config) === 'api' ? $this->callApi($prompt, $model) : $this->callCli($prompt, $model);
    }

    private function callApi(string $prompt, string $model): array
    {
        $cfg = $this->config['ai'] ?? [];
        $response = Http::post(self::API, [
            'x-api-key: ' . self::apiKey($this->config),
            'anthropic-version: 2023-06-01',
        ], [
            'model' => $model,
            'max_tokens' => (int)($cfg['max_tokens'] ?? 8000),
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ], true, 180);
        $stop = (string)($response['stop_reason'] ?? '');
        if ($stop === 'refusal') {
            throw new \RuntimeException('Model odmítl odpovědět');
        }
        $out = '';
        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $out .= $block['text'];
            }
        }
        return self::parseJson($out, $stop === 'max_tokens' ? ' (došel limit max_tokens)' : '');
    }

    private function callCli(string $prompt, string $model): array
    {
        // claude -p přes předplatné: bez nástrojů, bez MCP, bez nastavení projektu, prompt na stdin
        $cfg = $this->config['ai'] ?? [];
        $cli = self::cliPath($this->config);
        if ($cli === null) {
            throw new \RuntimeException('claude CLI nenalezeno (nastavte ai.cli_path)');
        }
        $cmd = [...array_map('strval', (array)($cfg['cli_prefix'] ?? [])), $cli, '-p', '--model', $model, '--tools', '',
            '--output-format', 'json', '--no-session-persistence', '--setting-sources', '', '--strict-mcp-config',
            '--system-prompt', 'Jsi nestranný legislativní analytik. Odpovídáš pouze JSON objektem podle zadání.'];
        // bez API klíče v prostředí, aby se účtovalo předplatné a ne API
        $env = getenv();
        unset($env['ANTHROPIC_API_KEY'], $env['ANTHROPIC_AUTH_TOKEN']);
        $env['PATH'] = ($env['PATH'] ?? '/usr/bin:/bin') . PATH_SEPARATOR . dirname($cli);

        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, sys_get_temp_dir(), $env);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Nelze spustit claude CLI');
        }
        fwrite($pipes[0], $prompt);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = time() + (int)($cfg['timeout'] ?? 300);
        while (true) {
            $stdout .= (string)stream_get_contents($pipes[1]);
            $stderr .= (string)stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            if (time() > $deadline) {
                proc_terminate($proc);
                throw new \RuntimeException('claude CLI nedoběhlo včas');
            }
            usleep(100_000);
        }
        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $envelope = json_decode($stdout, true);
        if (!is_array($envelope)) {
            throw new \RuntimeException('claude CLI: neplatný výstup ' . mb_substr(trim($stderr . ' ' . $stdout), 0, 300));
        }
        if (!empty($envelope['is_error'])) {
            throw new \RuntimeException('claude CLI: ' . mb_substr((string)($envelope['result'] ?? 'chyba'), 0, 300));
        }
        if (($envelope['stop_reason'] ?? '') === 'refusal') {
            throw new \RuntimeException('Model odmítl odpovědět');
        }
        return self::parseJson((string)($envelope['result'] ?? ''), '');
    }

    private static function parseJson(string $out, string $note): array
    {
        // z odpovědi vezmeme první JSON objekt
        if (!preg_match('~\{.*\}~s', $out, $m)) {
            throw new \RuntimeException('Odpověď AI neobsahuje JSON' . $note);
        }
        $data = json_decode($m[0], true);
        if (!is_array($data)) {
            throw new \RuntimeException('Neplatný JSON od AI');
        }
        return $data;
    }
}
