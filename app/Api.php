<?php
// Strojový výstup pro další nástroje (api.php, JSON, jen čtení) a společné filtry pro RSS (#28).

declare(strict_types=1);

namespace Hlidac;

final class Api
{
    public const VERSION = 1;
    private const MAX_LIMIT = 500;

    public function __construct(private Db $db, private Repo $repo, private array $config)
    {
    }

    /** @return array{0:int, 1:array} HTTP kód a tělo odpovědi */
    public function handle(array $q): array
    {
        try {
            if (isset($q['tisk'])) {
                $ct = (int)$q['tisk'];
                $bill = $ct > 0 ? $this->bill($ct) : null;
                return $bill === null ? [404, ['error' => 'Tisk nenalezen']] : [200, $this->wrap(['tisk' => $bill])];
            }
            if (!empty($q['pripravovane'])) {
                // ?druh=narizeni: připravovaná nařízení vlády (výchozí jsou návrhy zákonů)
                return [200, $this->wrap(['pripravovane' => $this->upcoming(($q['druh'] ?? '') === 'narizeni' ? 'narizeni' : 'zakon')])];
            }
            $filters = self::filters($q);
            if (!empty($q['upozorneni'])) {
                return [200, $this->wrap(['upozorneni' => $this->alerts($filters)])];
            }
            return [200, $this->wrap(['tisky' => $this->bills($filters)])];
        } catch (\InvalidArgumentException $ex) {
            return [400, ['error' => $ex->getMessage()]];
        }
    }

    public static function filters(array $q): array
    {
        // společné filtry: since=YYYY-MM-DD, min_score=0–100, zakon=159/2006, tisk=221 (u upozornění), limit
        $since = trim((string)($q['since'] ?? ''));
        if ($since !== '' && !preg_match('~^\d{4}-\d{2}-\d{2}$~', $since)) {
            throw new \InvalidArgumentException('since musí být datum ve tvaru YYYY-MM-DD');
        }
        $law = trim((string)($q['zakon'] ?? $q['law'] ?? ''));
        if ($law !== '' && !preg_match('~^\d+/\d{4}$~', $law)) {
            throw new \InvalidArgumentException('zakon musí být číslo zákona ve tvaru 159/2006');
        }
        $min = $q['min_score'] ?? $q['min'] ?? null;
        return [
            'since' => $since,
            'min_score' => $min === null || $min === '' ? null : max(0, min(100, (int)$min)),
            'zakon' => $law,
            'tisk' => isset($q['ct']) ? (int)$q['ct'] : null,
            'limit' => max(1, min(self::MAX_LIMIT, (int)($q['limit'] ?? 100))),
        ];
    }

    public function bills(array $f): array
    {
        // tisky aktuálního období od poslední změny, se skóre a důvody
        $out = [];
        foreach ($this->repo->bills('', '', 1000, 'recent', (int)($f['min_score'] ?? 0)) as $t) {
            if ($f['since'] !== '' && (string)$t['posledni'] < $f['since']) {
                continue;
            }
            if ($f['zakon'] !== '' && !in_array($f['zakon'], explode(',', (string)$t['zakony']), true)) {
                continue;
            }
            $out[] = $this->summary($t);
            if (count($out) >= $f['limit']) {
                break;
            }
        }
        return $out;
    }

    public function bill(int $ct): ?array
    {
        $t = $this->repo->bill($ct);
        if ($t === null) {
            return null;
        }
        $out = $this->summary($t);
        $out['hlasovani'] = array_map(fn(array $v) => [
            'id' => (int)$v['id_hlasovani'], 'datum' => $v['datum'], 'vysledek' => $v['vysledek'] === 'A' ? 'přijato' : 'zamítnuto',
            'pro' => (int)$v['pro'], 'proti' => (int)$v['proti'], 'zdrzel' => (int)$v['zdrzel'], 'kvorum' => (int)$v['kvorum'],
            'kluby' => array_map(fn(array $c) => ['klub' => $c['klub'], 'pro' => (int)$c['pro'], 'proti' => (int)$c['proti'],
                'zdrzel_nehlasoval' => (int)$c['zdrzel'], 'nepritomen' => (int)$c['nepritomen']], $v['kluby']),
            'psp_url' => 'https://www.psp.cz/sqw/hlasy.sqw?g=' . (int)$v['id_hlasovani'],
        ], $this->repo->votes($ct));
        $out['upozorneni'] = array_map([$this, 'alert'], $this->repo->alerts(100, 0, $ct));
        $status = $this->repo->reviewStatus($ct)[$ct] ?? ['total' => 0, 'done' => 0];
        $out['rozbor_textu'] = ['posouzeno' => (int)$status['done'], 'celkem' => (int)$status['total']];
        // dopad textu návrhu na občany z rozboru AI (null, dokud ho rozbor neobsahuje); do skóre nevstupuje
        $out['dopad'] = Impact::forApi($this->repo->billText($ct)['ai'] ?? null);
        // výsledek písemných pozměňovacích návrhů ve 3. čtení (prázdné, dokud není souhrn PN a hlasování)
        $out['vysledky_pn'] = array_values(array_map(fn(array $r) => [
            'cislo' => (int)$r['cislo'], 'pismena' => array_values(array_filter(array_map('trim', explode(',', (string)$r['pismena'])))),
            'stav' => $r['stav'], 'stanovisko_vyboru' => $r['stanovisko'],
            'hlasovani' => array_map(fn(array $v) => ['cislo' => (int)$v['cislo'], 'vysledek' => ($v['vysledek'] ?? '') === 'A' ? 'přijato' : 'zamítnuto',
                'pismeno' => $v['pismeno'] ?? null, 'varianta' => $v['varianta'] ?? null, 'psp_url' => 'https://www.psp.cz/sqw/hlasy.sqw?g=' . (int)$v['id']], $r['hlasovani']),
        ], $this->repo->pnOutcomes($ct)));
        // průchod Senátem a kroky po něm (null, dokud návrh do Senátu nedošel); jen popis postupu, do skóre nevstupuje
        $senate = Analyzer::senate($this->repo->steps($ct));
        $out['senat'] = $senate['doruceno'] !== null ? $senate + ['popis' => Analyzer::senateLines($senate)] : null;
        return $out;
    }

    public function alerts(array $f): array
    {
        return array_map([$this, 'alert'], $this->alertRows($f));
    }

    public function alertRows(array $f): array
    {
        // upozornění (řádky tabulky alerts); bez min_score jen od úrovně „Pozor“ (stejně jako RSS)
        $min = $f['min_score'] ?? (int)Scorer::weights($this->config)['level_watch'];
        $rows = $f['tisk'] !== null ? $this->repo->alerts(self::MAX_LIMIT, 0, $f['tisk']) : $this->repo->alerts(2000, 0, null, $min);
        $laws = null;
        if ($f['zakon'] !== '') {
            $laws = [];
            foreach ($this->db->all('SELECT ct, zakony FROM tisky') as $t) {
                if (in_array($f['zakon'], explode(',', (string)$t['zakony']), true)) {
                    $laws[(int)$t['ct']] = true;
                }
            }
        }
        $out = [];
        foreach ($rows as $a) {
            if ((int)$a['score'] < $min || ($f['since'] !== '' && substr((string)$a['event_at'], 0, 10) < $f['since'])) {
                continue;
            }
            if ($laws !== null) {
                // připravovaný zákon nemá číslo tisku: zákon se pozná z názvu materiálu (titulek upozornění
                // ho nese zkrácený, aktuální název je v tabulce připravovaných)
                if ($a['kind'] === 'prep_narizeni') {
                    // nařízení vlády není zákon; čísla nařízení a zákonů se překrývají
                    $match = false;
                } elseif (str_starts_with((string)$a['kind'], 'prep_')) {
                    $pid = (string)substr((string)$a['alert_key'], strpos((string)$a['alert_key'], ':') + 1);
                    $match = in_array($f['zakon'], Laws::numbersIn($a['title'] . ' ' . (View::upcomingIndex()[$pid]['nazev'] ?? '')), true);
                } else {
                    $match = !View::isPastPeriod($a) && isset($laws[(int)$a['ct']]);
                }
                if (!$match) {
                    continue;
                }
            }
            $out[] = $a;
            if (count($out) >= $f['limit']) {
                break;
            }
        }
        return $out;
    }

    public function upcoming(string $kind = 'zakon'): array
    {
        // připravované zákony u vlády nebo nařízení vlády (otevřená data ODok), od nejpokročilejší fáze
        return array_map(fn(array $u) => [
            'pid' => $u['pid'], 'druh' => $kind, 'nazev' => $u['nazev'], 'resort' => $u['resort'], 'faze' => $u['faze'], 'stav_odok' => $u['stav'],
            'zalozeno' => $u['zalozeno'], 'zmeneno' => $u['zmeneno'],
            'pripominky' => $u['dni'] !== null ? ['od' => $u['pripominky_od'], 'do' => $u['pripominky_do'], 'dni' => (int)$u['dni'], 'mist' => (int)$u['mist']] : null,
            'vlada_projednala' => $u['vlada'], 'mimo_plan' => (bool)$u['mimo_plan'], 'vyjimka' => (bool)$u['vyjimka'],
            'popis' => $u['popis'], 'duvod' => $u['duvod'], 'veklep_url' => GovPrep::url((string)$u['pid']),
        ], $this->repo->upcoming($kind));
    }

    private function summary(array $t): array
    {
        $obdobi = (int)$this->db->getMeta('obdobi_cislo', '0');
        return [
            'ct' => (int)$t['ct'],
            'obdobi' => $obdobi,
            'nazev' => $t['nazev'],
            'uplny_nazev' => $t['uplny_nazev'],
            'typ_navrhu' => $t['typ_navrhu'],
            'navrhovatel' => $t['navrhovatel'],
            'faze' => $t['faze'],
            'predlozeno' => $t['predlozeno'],
            'posledni_zmena' => $t['posledni'],
            'zakony' => array_values(array_filter(explode(',', (string)$t['zakony']))),
            'skore' => (int)$t['score'],
            'uroven' => Scorer::level((int)$t['score'], $this->config),
            'duvody' => array_map(fn(array $r) => ['klic' => $r['key'], 'body' => (int)$r['points'], 'popis' => $r['label']],
                $t['reasons_list'] ?? (json_decode((string)$t['reasons'], true) ?: [])),
            'priznaky' => array_map(fn(array $f) => $f[1], $t['flags'] ?? []),
            'url' => siteUrl('tisk.php?ct=' . (int)$t['ct']),
            'psp_url' => View::pspTiskUrl((int)$t['ct'], $obdobi),
        ];
    }

    private function alert(array $a): array
    {
        return [
            'id' => $a['alert_key'], 'typ' => $a['kind'], 'ct' => (int)$a['ct'] > 0 ? (int)$a['ct'] : null, 'obdobi' => $a['obdobi'] !== null ? (int)$a['obdobi'] : null,
            'datum' => $a['event_at'], 'titulek' => $a['title'], 'text' => $a['body'], 'skore' => (int)$a['score'],
            'url' => View::alertUrl($a, true),
        ];
    }

    private function wrap(array $data): array
    {
        return ['verze' => self::VERSION, 'zdroj' => siteUrl(), 'aktualizovano' => $this->repo->lastSync()] + $data;
    }
}
