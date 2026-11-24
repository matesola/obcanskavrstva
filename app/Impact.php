<?php
// Dopad na občany: koho se text týká, odkdy platí, nové povinnosti, pravomoci úřadů, sankce a rizika s pojistkami.
// Model ho vrací v rozboru textu jako klíč „dopad“ (AiReviewer::FORMAT). Popisuje, co text mění pro lidi,
// nehodnotí, zda je to dobře. Do skóre ani do závažnosti nevstupuje.
// Každá položka kromě skupin musí mít doslovnou citaci ověřenou v textu; bez ní se zahodí.

declare(strict_types=1);

namespace Hlidac;

final class Impact
{
    // pevný číselník skupin (klíč => popisek na webu); model smí vybírat jen z něj, cokoli jiného se zahodí
    public const GROUPS = [
        'vsichni' => 'Všichni občané',
        'zamestnanci' => 'Zaměstnanci',
        'zamestnavatele' => 'Zaměstnavatelé',
        'osvc' => 'OSVČ a živnostníci',
        'firmy' => 'Firmy',
        'rodiny' => 'Rodiny s dětmi',
        'seniori' => 'Důchodci',
        'pecujici' => 'Pečující o blízké',
        'zdravotne_postizeni' => 'Lidé se zdravotním postižením',
        'pacienti' => 'Pacienti a zdravotnictví',
        'studenti' => 'Žáci, studenti a školy',
        'bydleni' => 'Nájemníci a vlastníci bytů',
        'ridici' => 'Řidiči a doprava',
        'dluznici' => 'Dlužníci a exekuce',
        'poplatnici' => 'Daňoví poplatníci',
        'prijemci_davek' => 'Příjemci dávek',
        'nezamestnani' => 'Nezaměstnaní',
        'zemedelci' => 'Zemědělci',
        'chovatele' => 'Chovatelé a majitelé zvířat',
        'drzitele_zbrani' => 'Myslivci a držitelé zbraní',
        'prijemci_dotaci' => 'Příjemci dotací',
        'spotrebitele' => 'Spotřebitelé',
        'cizinci' => 'Cizinci',
        'obce' => 'Obce a kraje',
    ];

    // seznamy položek: klíč => [pole s textem => nejvyšší délka]; citace je u každé položky povinná
    private const LISTS = [
        'povinnosti' => ['kdo' => 80, 'co' => 200],
        'pravomoci' => ['kdo' => 80, 'co' => 200],
        'sankce' => ['za_co' => 160, 'vyse' => 80],
        'rizika' => ['popis' => 240, 'pojistka' => 200],
        'scenare' => ['titulek' => 90, 'kdo' => 80, 'pribeh' => 420, 'pojistka' => 200],
        'pomuze' => ['kdo' => 80, 'jak' => 160],
        'pritizi' => ['kdo' => 80, 'jak' => 160],
    ];
    private const MAX_ITEMS = 4;
    private const MAX_GROUPS = 6;

    public const PROMPT = <<<'TXT'
"dopad": popis, co text mění pro lidi, firmy a úřady. Nehodnotí, zda je to dobře, a do "zavaznost" nevstupuje.
- "skupiny": 0 až 6 klíčů z tohoto seznamu (jen přímo dotčené, ne nepřímo): {GROUPS}. "vsichni" jen u změn, které dopadnou prakticky na každého.
- "ucinnost": kdy změna začne platit, např. „1. ledna 2028“ nebo „dnem vyhlášení“, s citací; když to text neuvádí, dej null.
- "povinnosti": nové nebo zpřísněné povinnosti ("kdo" je musí plnit, "co" musí udělat).
- "pravomoci": nové nebo rozšířené pravomoci státu, úřadů, policie, obcí nebo soukromých subjektů vůči lidem ("kdo" ji získá, "co" smí).
- "sankce": nové nebo vyšší pokuty, zákazy a jiné tresty ("za_co", "vyse" např. „až 500 000 Kč“).
- "rizika": nejvýš 3 místa, kde text dává pravomoc, výjimku nebo volné uvážení bez jasně vymezených mezí, takže by šel použít i jinak nebo šíře, než ohlašuje účel. "popis" věcně popíše mechanismus (co text umožňuje a jaké meze chybí), bez spekulací o úmyslech a bez jmen. "pojistka": co tentýž text výslovně omezuje (soudní přezkum, podmínky, lhůty, souhlas, kontrola); když v textu žádná není, napiš „v textu návrhu neuvedena“. Když riziko nevidíš, dej prázdné pole; běžné povinnosti a sankce rizikem nejsou.
- "zbystrit": jedna věta do 120 znaků pro běžného čtenáře, proč by měl zbystřit (nejdůležitější přímý dopad nebo sporné místo), bez hodnocení; u čistě technické úpravy prázdný řetězec.
- "pomuze" a "pritizi": nejvýš 3 skupiny lidí nebo firem, kterým text přímo přidá (peníze, práva, úlevy, výjimky), a nejvýš 3, kterým přímo přitíží (náklady, povinnosti, omezení, sankce, ztráta nároku). "kdo" konkrétně (např. „důchodci od 80 let“, „zaměstnavatelé do 10 zaměstnanců“), "jak" krátce a s čísly z textu. Jen přímé dopady, které text uvádí; nepřímé dopady, odhady a politické hodnocení vynech.
- "scenare": nejvýš 2 modelové situace „nejhorší čtení“ pro konkrétního člověka nebo firmu z dotčených skupin (např. „OSVČ s paušální daní“, „obec do 2 000 obyvatel“, „chovatel psa“). "titulek" krátce shrne, co se mu může stát, "kdo" je ta modelová osoba, "pribeh" ve 2 až 3 větách popíše, co mu text při doslovném a nejpřísnějším čtení uloží nebo umožní vůči němu (lhůty, částky, pravomoci přesně podle textu), "pojistka" co tomu v textu brání (nebo „v textu návrhu neuvedena“). Žádné spekulace o úmyslech, o tom, co úřady udělají, ani čísla, která v textu nejsou. Když text nikomu nic podstatného neukládá, dej prázdné pole.
U povinností, pravomocí, sankcí, rizik, scénářů a skupin v "pomuze" a "pritizi" uveď nejvýš 4 nejdůležitější položky a u každé "citace": jedna věta zkopírovaná z textu přesně znak po znaku, která ji dokládá. Položku bez takové citace vynech.
TXT;

    public const FORMAT_EXAMPLE = '"dopad": {"skupiny": ["klíč"], "ucinnost": {"text": "…", "citace": "…"}, "povinnosti": [{"kdo": "…", "co": "…", "citace": "…"}], "pravomoci": [{"kdo": "…", "co": "…", "citace": "…"}], "sankce": [{"za_co": "…", "vyse": "…", "citace": "…"}], "rizika": [{"popis": "…", "pojistka": "…", "citace": "…"}], "scenare": [{"titulek": "…", "kdo": "…", "pribeh": "…", "pojistka": "…", "citace": "…"}], "zbystrit": "…", "pomuze": [{"kdo": "…", "jak": "…", "citace": "…"}], "pritizi": [{"kdo": "…", "jak": "…", "citace": "…"}]}';

    public static function prompt(): string
    {
        return str_replace('{GROUPS}', implode(', ', array_keys(self::GROUPS)), self::PROMPT);
    }

    /**
     * Ověří citace v dopadu proti textu (AiReviewer::verifyQuotes) a položky bez ověřené citace zahodí.
     * Vrací [dopad, počet zahozených položek]. Bez klíče „dopad“ v odpovědi vrací [null, 0].
     */
    public static function verify(mixed $impact, string $text): array
    {
        if (!is_array($impact)) {
            return [null, 0];
        }
        $dropped = 0;
        $check = function (mixed $quote) use ($text): ?string {
            $ok = AiReviewer::verifyQuotes([(string)(is_string($quote) ? $quote : '')], $text);
            return $ok[0] ?? null;
        };
        if (is_array($impact['ucinnost'] ?? null)) {
            $q = $check($impact['ucinnost']['citace'] ?? '');
            if ($q === null) {
                $impact['ucinnost'] = null;
                $dropped++;
            } else {
                $impact['ucinnost']['citace'] = $q;
            }
        }
        foreach (array_keys(self::LISTS) as $list) {
            $kept = [];
            foreach (is_array($impact[$list] ?? null) ? $impact[$list] : [] as $item) {
                $q = is_array($item) ? $check($item['citace'] ?? '') : null;
                if ($q === null) {
                    $dropped++;
                    continue;
                }
                $item['citace'] = $q;
                $kept[] = $item;
            }
            $impact[$list] = $kept;
        }
        return [$impact, $dropped];
    }

    /**
     * Strukturální úklid (bez textu, takže jde použít i na uložené rozbory): skupiny jen z číselníku,
     * u položek povinná citace, zkrácené texty, nejvýš MAX_ITEMS položek. Opakované použití nic nemění.
     */
    public static function normalize(mixed $impact): ?array
    {
        if (!is_array($impact)) {
            return null;
        }
        $clean = fn(mixed $v, int $max): string => self::clip(trim(preg_replace('~\s+~u', ' ', is_scalar($v) ? (string)$v : '') ?? ''), $max);

        $groups = [];
        foreach (is_array($impact['skupiny'] ?? null) ? $impact['skupiny'] : [] as $g) {
            $g = is_string($g) ? strtolower(trim($g)) : '';
            if (isset(self::GROUPS[$g]) && !in_array($g, $groups, true)) {
                $groups[] = $g;
            }
        }
        $out = ['skupiny' => array_slice($groups, 0, self::MAX_GROUPS), 'ucinnost' => null,
            'zbystrit' => $clean($impact['zbystrit'] ?? '', 140)];

        $u = $impact['ucinnost'] ?? null;
        if (is_array($u) && $clean($u['text'] ?? '', 120) !== '' && $clean($u['citace'] ?? '', 600) !== '') {
            $out['ucinnost'] = ['text' => $clean($u['text'], 120), 'citace' => $clean($u['citace'], 600)];
        }
        foreach (self::LISTS as $list => $fields) {
            $items = [];
            foreach (is_array($impact[$list] ?? null) ? $impact[$list] : [] as $item) {
                if (!is_array($item) || $clean($item['citace'] ?? '', 600) === '') {
                    continue;
                }
                $row = [];
                foreach ($fields as $field => $max) {
                    $row[$field] = $clean($item[$field] ?? '', $max);
                }
                // položka bez hlavního textu (první pole) nemá co říct
                if (reset($row) === '') {
                    continue;
                }
                $row['citace'] = $clean($item['citace'], 600);
                $items[] = $row;
            }
            $out[$list] = array_slice($items, 0, match ($list) { 'rizika', 'pomuze', 'pritizi' => 3, 'scenare' => 2, default => self::MAX_ITEMS });
        }
        return $out;
    }

    /** Je v dopadu něco k zobrazení? */
    public static function hasContent(?array $impact): bool
    {
        if ($impact === null) {
            return false;
        }
        if ($impact['skupiny'] !== [] || $impact['ucinnost'] !== null || ($impact['zbystrit'] ?? '') !== '') {
            return true;
        }
        foreach (array_keys(self::LISTS) as $list) {
            if (($impact[$list] ?? []) !== []) {
                return true;
            }
        }
        return false;
    }

    /** Popisky skupin pro web a API. */
    public static function groupLabels(array $keys): array
    {
        return array_values(array_map(fn(string $k) => self::GROUPS[$k], array_filter($keys, fn($k) => is_string($k) && isset(self::GROUPS[$k]))));
    }

    /** Dopad pro api.php?tisk= (null, dokud rozbor dopad neobsahuje). */
    public static function forApi(?array $ai): ?array
    {
        $impact = self::normalize($ai['dopad'] ?? null);
        if (!self::hasContent($impact)) {
            return null;
        }
        $impact['skupiny'] = array_map(fn(string $k) => ['klic' => $k, 'nazev' => self::GROUPS[$k]], $impact['skupiny']);
        return $impact;
    }

    private static function clip(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)) . '…' : $s;
    }
}
