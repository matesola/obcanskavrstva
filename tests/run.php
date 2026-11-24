<?php
// Test celé synchronizace nad reálnými řádky z psp.cz: php tests/run.php

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/hlidac-test-' . getmypid();
mkdir($tmp, 0775, true);

// testovací data a konfigurace
passthru(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/make_fixtures.php') . ' ' . escapeshellarg($tmp . '/src'));
$base = require dirname(__DIR__) . '/config.example.php';
// HLIDAC_TEST_DSN (+ _USER, _PASS) spustí testy proti jiné databázi, např. prázdnému PostgreSQL
$base['db_dsn'] = getenv('HLIDAC_TEST_DSN') ?: 'sqlite:' . $tmp . '/test.sqlite';
$base['db_user'] = getenv('HLIDAC_TEST_USER') ?: null;
$base['db_pass'] = getenv('HLIDAC_TEST_PASS') ?: null;
$base['source_dir'] = $tmp . '/src';
$base['notify']['dry_run_log'] = $tmp . '/posts.log';
$base['odok'] = ['enabled' => true, 'local_dir' => $tmp . '/src/odok'];
$base['site_url'] = 'https://hlidac.test';
$base['notify']['dry_run'] = true;
$base['pn_text']['enabled'] = false; // test běží bez sítě; analýzu textu testujeme přímo
$base['tisk_text']['enabled'] = false;
$base['history_html']['enabled'] = false; // stránky historie tisků se testují zvlášť s falešným stahováním
$base['ai']['enabled'] = false;
$base['pn_outcome']['enabled'] = false; // souhrny PN se stahují z psp.cz; čtení a párování testujeme přímo
file_put_contents($tmp . '/config.php', '<?php return ' . var_export($base, true) . ';');
putenv('HLIDAC_CONFIG=' . $tmp . '/config.php');

require dirname(__DIR__) . '/app/bootstrap.php';

// index vyhledávání se jmenuje podle databáze a testy mají pokaždé jinou: po běhu ho z data/cache uklidíme
register_shutdown_function(function (): void {
    foreach (glob(Hlidac\SearchIndex::path() . '*') ?: [] as $file) {
        @unlink($file);
    }
});

$failures = 0;
function check(string $label, mixed $actual, mixed $expected): void
{
    global $failures;
    $ok = $actual === $expected;
    if (!$ok) {
        $failures++;
    }
    echo ($ok ? '  OK   ' : '  FAIL ') . $label . ($ok ? '' : ' → ' . var_export($actual, true) . ' (čekáno ' . var_export($expected, true) . ')') . "\n";
}

echo "1. běh (bootstrap)\n";
$r = Hlidac\Sync::run();
check('volební období', $r['import']['obdobi'], 10);
check('počet tisků (bez usnesení a 9. období)', $r['import']['tisky'], 4);
check('počet PN (bez typu 12 a 9. období)', $r['import']['pn'], 10);

$t = fn(int $ct) => db()->one('SELECT * FROM tisky WHERE ct = ?', [$ct]);
check('tisk 90: navrženo § 90/2', (int)$t(90)['p90'], 1);
check('tisk 90: neprošel v 1. čtení', (int)$t(90)['p90ok'], 0);
check('tisk 90: zkrácená lhůta', (int)$t(90)['zl'], 1);
check('tisk 90: fáze', $t(90)['faze'], 'Vyhlášeno');
check('tisk 90: Sbírka', $t(90)['sbirka'], '155/2026');
check('tisk 90: 3 PN', (int)$t(90)['pn_count'], 3);
check('tisk 78: schváleno v 1. čtení', (int)$t(78)['p90ok'], 1);
check('tisk 78: Sbírka', $t(78)['sbirka'], '59/2026');
check('tisk 311: zkrácená lhůta', (int)$t(311)['zl'], 1);
check('tisk 311: fáze', $t(311)['faze'], '2. čtení');
check('tisk 311: zákon v názvu', $t(311)['zakony'], '586/1992');
check('tisk 221: poslanecký', $t(221)['typ_navrhu'], 'poslanecký');
check('tisk 221: fáze', $t(221)['faze'], '1. čtení');
check('tisk 221: zákon v názvu', $t(221)['zakony'], '159/2006');
check('autor PN', db()->value('SELECT osoba FROM pn WHERE cislo = 2312'), 'Marian Jurečka');
check('bootstrap nic neposlal', (int)db()->value('SELECT COUNT(*) FROM alerts WHERE posted <> 2'), 0);
check('upozornění vytvořena', (int)db()->value('SELECT COUNT(*) FROM alerts') > 5, true);
check('tisk 221 sledovaný', (int)db()->value("SELECT watched FROM alerts WHERE alert_key = 'newbill:221'"), 1);
check('tisk 78: datum vyhlášení', $t(78)['vyhlaseno'], '2026-05-12');
check('tisk 78: „Vyhlášeno“ s datem vyhlášení, ne doručení', db()->value("SELECT event_at FROM alerts WHERE alert_key = 'law:78'"), '2026-05-12 00:00:00');
check('tisk 311 bez vyhlášení nemá „Vyhlášeno“', db()->value("SELECT 1 FROM alerts WHERE alert_key = 'law:311'"), null);
// dřív vytvořené upozornění s datem doručení se při dalším běhu opraví
db()->run("UPDATE alerts SET event_at = '2026-04-28 00:00:00', body = 'x' WHERE alert_key = 'law:78'");
(new Hlidac\AlertBuilder(db(), config()))->run();
check('staré datum „Vyhlášeno“ opraveno', db()->value("SELECT event_at FROM alerts WHERE alert_key = 'law:78'"), '2026-05-12 00:00:00');
// předčasné „Vyhlášeno“ (zákon ještě není ve Sbírce) se odstraní, odeslané na sítě zůstane
db()->run("INSERT INTO alerts (alert_key, kind, ct, event_at, created_at, title, body, watched, posted) VALUES ('law:311', 'law', 311, '2026-11-04 00:00:00', '2026-11-04 00:00:00', 'Vyhlášeno', 'x', 0, 2)");
db()->run("INSERT INTO alerts (alert_key, kind, ct, event_at, created_at, title, body, watched, posted) VALUES ('law:221', 'law', 221, '2026-11-04 00:00:00', '2026-11-04 00:00:00', 'Vyhlášeno', 'x', 0, 1)");
(new Hlidac\AlertBuilder(db(), config()))->run();
check('předčasné „Vyhlášeno“ bez data vyhlášení odstraněno', db()->value("SELECT 1 FROM alerts WHERE alert_key = 'law:311'"), null);
check('odeslané na sítě zůstane', (int)db()->value("SELECT COUNT(*) FROM alerts WHERE alert_key = 'law:221'"), 1);
check('správné „Vyhlášeno“ zůstane', (int)db()->value("SELECT COUNT(*) FROM alerts WHERE alert_key = 'law:78'"), 1);
db()->run("DELETE FROM alerts WHERE alert_key = 'law:221'");

echo "Skóre závažnosti\n";
$sc = fn(int $ct) => (int)db()->value('SELECT score FROM tisky WHERE ct = ?', [$ct]);
$keys = fn(int $ct) => array_column(json_decode((string)db()->value('SELECT reasons FROM tisky WHERE ct = ?', [$ct]), true), 'key');
check('tisk 78: § 90/2 prošlo + poslanecký = 40', $sc(78), 40);
check('tisk 90: § 90/2 navrženo + zkrácení + souhrnná novela = 28', $sc(90), 28);
check('tisk 221: poslanecký v 1. čtení 5 + citlivá oblast 5 + sledovaný 10 = 20', $sc(221), 20);
check('tisk 311: PN na poslední chvíli', in_array('late_pn', $keys(311), true), true);
check('tisk 311: zkrácená lhůta + PN na poslední chvíli + sledovaný = 35', $sc(311), 35);

echo "Analýza textu PN\n";
$pnTextSample = "Pozměňovací návrh\nk vládnímu návrhu zákona, kterým se mění zákon č. 586/1992 Sb.\n"
    . "V zákoně č. 252/1997 Sb., o zemědělství, ve znění pozdějších předpisů, se § 2 mění takto:\n"
    . "Za § 21i se vkládají nové § 21j až 21m.\n"
    . str_repeat('Text úpravy. ', 1200)
    . "\nOdůvodnění\nV zákoně č. 999/2020 Sb. se nic nemění, jen příklad v odůvodnění.";
$a = Hlidac\PnText::analyze($pnTextSample, ['586/1992'], config());
check('přílepek rozpoznán', $a['signals']['other_laws'] ?? [], ['252/1997']);
check('odůvodnění se nepočítá', in_array('999/2020', $a['laws'], true), false);
check('rozsáhlý PN', isset($a['signals']['large']), true);
check('skóre pravidel PN', $a['score'], 30);
db()->run("INSERT INTO pn_text (id_dokument, ct, cislo, text, chars, laws, signals, rule_score, fetched_at) VALUES (31312, 311, 2312, 'x', 15000, '252/1997', ?, 30, ?)",
    [json_encode($a['signals']), date('Y-m-d H:i:s')]);
$scorer = new Hlidac\Scorer(db(), config());
$scorer->scoreBills();
$scorer->scoreAlerts();
check('tisk 311 po přílepku je závažný (55)', $sc(311), 55);
check('úroveň závažnosti', Hlidac\Scorer::level($sc(311), config()), 'major');
// rozbor AI potvrdil, že změna jiného zákona s předmětem souvisí → přílepek se nepočítá
db()->run("UPDATE pn_text SET ai_json = ? WHERE id_dokument = 31312", [json_encode(['souvisi_s_predmetem' => true])]);
$scorer->scoreBills();
check('související změna jiného zákona není přílepek', in_array('pn_other_law', $keys(311), true), false);
db()->run("UPDATE pn_text SET ai_json = ? WHERE id_dokument = 31312", [json_encode(['souvisi_s_predmetem' => false])]);
$scorer->scoreBills();
check('přílepek potvrzený rozborem zůstává', $sc(311), 55);
// krátký PN se souvisejícím jiným zákonem: rule_score > 0, ale ani přílepek, ani „rozsáhlá úprava“ (issue #7, bod 1)
db()->run("UPDATE pn_text SET signals = ?, ai_json = ? WHERE id_dokument = 31312",
    [json_encode(['other_laws' => ['252/1997']]), json_encode(['souvisi_s_predmetem' => true])]);
$scorer->scoreBills();
check('související krátký PN nedá „rozsáhlou úpravu“', in_array('pn_large', $keys(311), true), false);
db()->run("UPDATE pn_text SET signals = ?, ai_json = NULL WHERE id_dokument = 31312", [json_encode($a['signals'])]);
$scorer->scoreBills();
check('po vrácení signálů zase 55', $sc(311), 55);
// tři krátké PN se souvisejícím přílepkem (rule_score 20, AI 3) nejsou problematické: bez pn_many i pn_large (issue #7, bod 1)
$relatedPn = [json_encode(['other_laws' => ['252/1997']]), json_encode(['souvisi_s_predmetem' => true])];
db()->run('UPDATE pn_text SET signals = ?, ai_json = ?, rule_score = 20, ai_score = 3 WHERE id_dokument = 31312', $relatedPn);
foreach ([31313 => 2313, 31330 => 2330] as $idPn => $cisloPn) {
    db()->run("INSERT INTO pn_text (id_dokument, ct, cislo, text, chars, laws, signals, rule_score, ai_json, ai_score, fetched_at) VALUES (?, 311, ?, 'x', 500, '252/1997', ?, 20, ?, 3, ?)",
        [$idPn, $cisloPn, $relatedPn[0], $relatedPn[1], date('Y-m-d H:i:s')]);
}
$scorer->scoreBills();
check('3 související přílepky: bez pn_many a pn_large', array_values(array_intersect(['pn_many', 'pn_large'], $keys(311))), []);
// jeden nesouvisející přílepek a dva rozsáhlé PN = 3 problematické → pn_many
db()->run('UPDATE pn_text SET ai_json = ? WHERE id_dokument = 31312', [json_encode(['souvisi_s_predmetem' => false])]);
db()->run('UPDATE pn_text SET signals = ?, ai_json = NULL, rule_score = 10 WHERE id_dokument IN (31313, 31330)', [json_encode(['large' => 15000])]);
$scorer->scoreBills();
check('přílepek + 2 rozsáhlé PN: pn_many', in_array('pn_many', $keys(311), true), true);
// úklid: testovací PN pryč, PN 31312 do původního stavu
db()->run('DELETE FROM pn_text WHERE id_dokument IN (31313, 31330)');
db()->run('UPDATE pn_text SET signals = ?, ai_json = NULL, rule_score = 30, ai_score = NULL WHERE id_dokument = 31312', [json_encode($a['signals'])]);
$scorer->scoreBills();
check('po úklidu testovacích PN zase 55', $sc(311), 55);

echo "2. běh (bez nových dat)\n";
$r2 = Hlidac\Sync::run();
check('žádná nová upozornění', $r2['alerts_new'], 0);

echo "3. běh (nový PN k tisku 221 a zkrácení lhůty u 221)\n";
$zip = new ZipArchive();
$zip->open($tmp . '/src/sd.zip');
$sd = iconv('CP1250', 'UTF-8', $zip->getFromName('sd_dokument.unl'));
$sd .= "40001|174|3001|13|||221|6205|" . date('Y-m-d H:i:s') . "|\n";
$zip->addFromString('sd_dokument.unl', iconv('UTF-8', 'CP1250', $sd));
$zip->close();
$zip->open($tmp . '/src/tisky.zip');
$hist = iconv('CP1250', 'UTF-8', $zip->getFromName('hist.unl'));
$hist .= "300001|43343|" . date('Y-m-d') . " 00:00||2031|||||||||||\n";
$zip->addFromString('hist.unl', iconv('UTF-8', 'CP1250', $hist));
$zip->close();

$r3 = Hlidac\Sync::run();
check('2 nová upozornění', $r3['alerts_new'], 2);
check('tisk 221 se zkrácenou lhůtou (45)', $sc(221), 45);
check('odesláno jen nad prahem (dry run): zkrácení 55, PN 45 čeká', [$r3['posts']['sent'], $r3['posts']['held']], [1, 1]);
$log = (string)@file_get_contents($tmp . '/posts.log');
check('příspěvek obsahuje odkaz', str_contains($log, 'https://hlidac.test/tisk.php?ct=221'), true);
check('příspěvek o zkrácené lhůtě', str_contains($log, 'Zkrácená lhůta: Novela z. o střetu zájmů'), true);
@unlink($tmp . '/posts.log');

echo "4. běh (běžný PN pod prahem se nepošle)\n";
db()->run("UPDATE alerts SET posted = 1 WHERE posted = 0");
$zip->open($tmp . '/src/sd.zip');
$sd = iconv('CP1250', 'UTF-8', $zip->getFromName('sd_dokument.unl'));
$sd .= "40002|174|3002|13|||90|6687|" . date('Y-m-d H:i:s') . "|\n";
$zip->addFromString('sd_dokument.unl', iconv('UTF-8', 'CP1250', $sd));
$zip->close();
$r4 = Hlidac\Sync::run();
check('vzniklo upozornění', $r4['alerts_new'], 1);
check('nic neodesláno (skóre 28 < 50)', $r4['posts']['sent'], 0);
check('pod prahem čeká na rozbor textů (hold_hours)', [$r4['posts']['held'], $r4['posts']['filtered']], [1, 0]);
db()->run('UPDATE alerts SET created_at = ? WHERE posted = 0', [date('Y-m-d H:i:s', time() - 49 * 3600)]);
$r4b = (new Hlidac\Publisher(db(), config()))->run();
check('po uplynutí čekání potlačeno', [$r4b['filtered'], $r4b['sent']], [1, 0]);

echo "Text návrhu zákona (tisk 221: lhůta pro vymáhání dotací 4 roky i zpětně)\n";
$billSample = "Návrh\nZÁKON\nkterým se mění zákon č. 159/2006 Sb., o střetu zájmů\n"
    . "V § 44a odst. 11 se za větu předposlední vkládá věta „Řízení o uložení odvodu za porušení rozpočtové kázně lze zahájit nejpozději do 4 let od porušení rozpočtové kázně.“.\n"
    . "Řízení zahájená přede dnem nabytí účinnosti tohoto zákona se dokončí podle dosavadních právních předpisů.\n"
    . "Lhůta pro zahájení řízení o vrácení dotace se posuzuje podle zákona č. 256/2000 Sb., ve znění účinném ode dne nabytí účinnosti tohoto zákona, i tehdy, pokud byla neoprávněná platba dotace poskytnuta přede dnem nabytí účinnosti tohoto zákona.\n"
    . "DŮVODOVÁ ZPRÁVA\nI tehdy, pokud to bylo přede dnem nabytí účinnosti, lhůta 10 let pro vrácení dotace se nemění.";
$ba = Hlidac\TiskText::analyze($billSample);
check('důvodová zpráva se nepočítá', count($ba['signals']['retro'] ?? []), 1);
check('zpětné použití rozpoznáno', str_contains($ba['signals']['retro'][0] ?? '', 'i tehdy, pokud byla neoprávněná platba'), true);
check('změna lhůty rozpoznána', str_contains($ba['signals']['deadline'][0] ?? '', 'nejpozději do 4 let'), true);
check('běžné přechodné ustanovení není zpětné', Hlidac\PnText::procedureSignals("Řízení zahájená přede dnem nabytí účinnosti tohoto zákona se dokončí podle dosavadních právních předpisů."), []);
// jeden dlouhý odstavec z Wordu se dvěma nesouvisejícími větami nesmí dát „lhůtu i zpětně“ (PN 2056 k tisku 174)
$paragraph = "15. V § 28c se vkládá věta „Úřad nařídí péči i tehdy, pokud k týrání došlo přede dnem nabytí účinnosti tohoto zákona.“. "
    . "16. V § 28d se vkládá věta „Odvolání proti rozhodnutí o záloze nemá odkladný účinek a řízení lze zahájit nejpozději do 2 let.“.";
check('dlouhý odstavec: zpětnost bez lhůty nedá body', isset(Hlidac\PnText::procedureSignals($paragraph)['retro_deadline']), false);
check('zpětně použitá lhůta tisku 221 se pozná', isset($ba['signals']['retro_deadline']), true);
$capped = Hlidac\Scorer::applyCaps([
    ['key' => 'pn_other_law', 'points' => 20, 'label' => 'a'], ['key' => 'ai_bill', 'points' => 18, 'label' => 'b'],
    ['key' => 'ai', 'points' => 24, 'label' => 'c'], ['key' => 'mp_bill', 'points' => 15, 'label' => 'd'],
], Hlidac\Scorer::weights(config()));
check('strop obsahové skupiny 45', array_sum(array_column($capped, 'points')), 60);
check('krácený důvod zůstane vidět', count($capped), 4);
$quotes = Hlidac\AiReviewer::verifyQuotes([
    'Řízení o uložení odvodu za porušení rozpočtové kázně lze zahájit nejpozději do 4 let od porušení rozpočtové kázně.',
    'Lhůta pro vrácení dotací se zkracuje z 10 na 4 roky.', // vymyšlená citace
], $billSample);
check('ověřená citace zůstane, vymyšlená se zahodí', count($quotes), 1);
$noQuote = Hlidac\AiReviewer::sanitize(['zavaznost' => 8, 'citace' => [], 'titulek' => 'x']);
check('nález bez ověřené citace má nejvýš 2', $noQuote['zavaznost'], 2);
check('snížení bez citace je zapsané', count($noQuote['upravy'] ?? []), 1);
check('nález s citací zůstane', Hlidac\AiReviewer::sanitize(['zavaznost' => 8, 'citace' => $quotes, 'titulek' => 'x'])['zavaznost'], 8);
db()->run("INSERT INTO tisk_text (ct, text, chars, signals, ai_score, ai_json, fetched_at, ai_at) VALUES (221, ?, ?, ?, 8, ?, ?, ?)", [
    $billSample, mb_strlen($billSample), json_encode($ba['signals'], JSON_UNESCAPED_UNICODE),
    json_encode(['shrnuti' => 'Zkracuje lhůtu pro vymáhání dotací a použije ji zpětně.', 'zavaznost' => 8, 'zpetna_ucinnost' => true,
        'zkraceni_lhut' => true, 'duvod' => 'Zpětné zkrácení lhůty pro vymáhání dotací.', 'citace' => $quotes], JSON_UNESCAPED_UNICODE),
    date('Y-m-d H:i:s'), date('Y-m-d H:i:s'),
]);
$scorer->scoreBills();
check('tisk 221: AI potvrdila zpětnou lhůtu → pravidlové body se nepřičtou podruhé', in_array('retro_deadline', $keys(221), true), false);
check('tisk 221: rozbor textu návrhu', in_array('ai_bill', $keys(221), true), true);
check('tisk 221: 45 + 8×3 = 69', $sc(221), 69);
$_GET = ['ct' => '221'];
ob_start();
(function (string $file): void {
    require $file;
})(APP_ROOT . '/tisk.php');
$html221 = (string)ob_get_clean();
check('detail tisku ukazuje rozbor a citaci', str_contains($html221, 'Rozbor textu návrhu') && str_contains($html221, 'nejpozději do 4 let'), true);
// celá cesta saveResult: nález bez ověřené citace se uloží se závažností 2 a do skóre tisku se nepromítne (issue #7, bod 2)
$saved221 = db()->one('SELECT ai_json, ai_score, ai_at, ai_model FROM tisk_text WHERE ct = 221');
$saveReviewer = new Hlidac\AiReviewer(db(), config());
$saveReviewer->saveResult('tisk', 221, ['zavaznost' => 8, 'titulek' => 'x', 'citace' => ['Vymyšlená věta, která v textu návrhu vůbec není.']]);
check('saveResult: vymyšlená citace → ai_score 2', (int)db()->value('SELECT ai_score FROM tisk_text WHERE ct = 221'), 2);
$scorer->scoreBills();
check('saveResult: bez ověřené citace bez ai_bill', in_array('ai_bill', $keys(221), true), false);
$saveReviewer->saveResult('tisk', 221, ['zavaznost' => 8, 'titulek' => 'x', 'citace' => ['   ', '']]);
check('saveResult: citace jen z mezer → ai_score 2', (int)db()->value('SELECT ai_score FROM tisk_text WHERE ct = 221'), 2);
check('uložený rozbor bez klíče citace se nesníží', Hlidac\AiReviewer::sanitize(['zavaznost' => 8, 'titulek' => 'x'])['zavaznost'], 8);
db()->run('UPDATE tisk_text SET ai_json = ?, ai_score = ?, ai_at = ?, ai_model = ? WHERE ct = 221',
    [$saved221['ai_json'], $saved221['ai_score'], $saved221['ai_at'], $saved221['ai_model']]);
$scorer->scoreBills();
check('tisk 221 po vrácení rozboru zase 69', $sc(221), 69);

echo "AI rozbor přes claude CLI (falešné CLI, bez sítě)\n";
$fakeCli = $tmp . '/claude.php';
file_put_contents($fakeCli, '#!' . PHP_BINARY . "\n" . <<<'FAKE'
<?php
$prompt = stream_get_contents(STDIN);
$args = implode(' ', $argv);
$ok = getenv('ANTHROPIC_API_KEY') === false && str_contains($args, '--tools') && str_contains($prompt, 'Text návrhu');
// silnější model (druhé kolo) vrátí jinou závažnost, ať se pozná, že se opravdu volal
$score = !$ok ? 0 : (str_contains($args, '--model claude-sonnet-5-5') ? 7 : 9);
$result = ['titulek' => 'Lhůta pro vymáhání dotací se zkracuje na 4 roky – i zpětně', 'perex' => str_repeat('Dlouhý perex. ', 30), 'shrnuti' => 'Zkracuje lhůtu a použije ji zpětně.', 'zavaznost' => $score, 'zpetna_ucinnost' => true, 'zpetnost_smer' => 'oslabuje_vymahani_nebo_kontrolu', 'zkraceni_lhut' => true,
    'duvod' => 'Zpětné zkrácení lhůty pro vymáhání dotací.',
    'citace' => ['Řízení o uložení odvodu za porušení rozpočtové kázně lze zahájit nejpozději do 4 let od porušení rozpočtové kázně.', 'Vymyšlená věta, která v textu není.']];
echo json_encode(['type' => 'result', 'is_error' => false, 'stop_reason' => 'end_turn', 'result' => "```json\n" . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n```"]);
FAKE);
chmod($fakeCli, 0755);
putenv('ANTHROPIC_API_KEY=nesmi-se-pouzit');
$aiConfig = config();
$aiConfig['ai'] = ['enabled' => true, 'backend' => 'cli', 'cli_path' => $fakeCli, 'cli_prefix' => [PHP_BINARY], 'model' => 'claude-haiku-5-5'];
db()->run('UPDATE tisk_text SET ai_at = NULL, ai_score = NULL WHERE ct = 221');
$reviewer = new Hlidac\AiReviewer(db(), $aiConfig);
check('fronta: text tisku 221 s nápovědou pravidel', str_contains($reviewer->queue(5, 221)[0]['prompt'] ?? '', 'možné zpětné použití'), true);
check('rozbor přes CLI proběhl', $reviewer->run(5, 221), ['reviewed' => 1, 'errors' => 0]);
check('CLI bez API klíče a bez nástrojů (závažnost 9)', (int)db()->value('SELECT ai_score FROM tisk_text WHERE ct = 221'), 9);
check('vymyšlená citace z CLI zahozena', count(json_decode((string)db()->value('SELECT ai_json FROM tisk_text WHERE ct = 221'), true)['citace']), 1);
putenv('ANTHROPIC_API_KEY');
$aiConfig['ai']['cli_path'] = $tmp . '/neexistuje';
check('perex zkrácen pro homepage', mb_strlen(json_decode((string)db()->value('SELECT ai_json FROM tisk_text WHERE ct = 221'), true)['perex']) <= 220, true);
check('model prvního kola uložen', db()->value('SELECT ai_model FROM tisk_text WHERE ct = 221'), 'claude-haiku-5-5');
$aiConfig['ai']['cli_path'] = $fakeCli;
$aiConfig['ai']['verify_min'] = 6;
$reviewer = new Hlidac\AiReviewer(db(), $aiConfig);
check('fronta ověření: závažný nález 221', array_column($reviewer->queue(5, 221, true), 'id'), [221]);
check('ověření silnějším modelem', $reviewer->run(5, 221, true), ['reviewed' => 1, 'errors' => 0]);
check('výsledek ověření nahradí první kolo', [(int)db()->value('SELECT ai_score FROM tisk_text WHERE ct = 221'), db()->value('SELECT ai_model FROM tisk_text WHERE ct = 221')], [7, 'claude-sonnet-5-5']);
check('první kolo zůstane uložené', [(int)db()->value('SELECT ai_prev_score FROM tisk_text WHERE ct = 221'), db()->value('SELECT ai_prev_model FROM tisk_text WHERE ct = 221')], [9, 'claude-haiku-5-5']);
check('na detailu je vidět i první kolo', str_contains(Hlidac\View::aiRounds(db()->one('SELECT * FROM tisk_text WHERE ct = 221')), 'první kolo claude-haiku-5-5: 9/10'), true);
check('ověřený nález se znovu neověřuje', $reviewer->queue(5, 221, true), []);
check('rozbor nese aktuální verzi promptu', (int)db()->value('SELECT ai_prompt FROM tisk_text WHERE ct = 221'), Hlidac\AiReviewer::PROMPT_VERSION);
check('aktuální rozbor není ve frontě', $reviewer->queue(5, 221), []);
// rozbor podle starší verze promptu: zůstane zobrazený, ale vrátí se do fronty; ověření ho přeskočí
db()->run('UPDATE tisk_text SET ai_prompt = NULL WHERE ct = 221');
check('starší verze promptu se znovu posoudí', array_column($reviewer->queue(5, 221), 'id'), [221]);
check('starší rozbor se mezitím neověřuje', $reviewer->queue(5, 221, true), []);
check('starší rozbor zůstává zobrazený', (int)db()->value('SELECT ai_score FROM tisk_text WHERE ct = 221'), 7);
db()->run('UPDATE tisk_text SET ai_prompt = ? WHERE ct = 221', [Hlidac\AiReviewer::PROMPT_VERSION]);
check('„zpětně“ v titulku zůstane u oslabení vymáhání', str_contains(json_decode((string)db()->value('SELECT ai_json FROM tisk_text WHERE ct = 221'), true)['titulek'], 'zpětně'), true);
$aiConfig['ai']['cli_path'] = $tmp . '/neexistuje';

echo "Pojistky nad odpovědí AI\n";
$fav = Hlidac\AiReviewer::sanitize(['titulek' => 'Vyšší příspěvek na péči se vyplatí i zpětně od 1. 1. 2026', 'zavaznost' => 6, 'zpetnost_smer' => 've_prospech_verejnosti']);
check('příznivá zpětnost: závažnost nejvýš 3', $fav['zavaznost'], 3);
check('příznivá zpětnost: bez „zpětně“ v titulku', $fav['titulek'], 'Vyšší příspěvek na péči se vyplatí od 1. 1. 2026');
check('korekce je zapsaná', count($fav['upravy']), 2);
check('„– i zpětně“ na konci', Hlidac\AiReviewer::stripRetroWord('Stávající souhlasy zůstávají – i zpětně'), 'Stávající souhlasy zůstávají');
check('„, tedy i zpětně“', Hlidac\AiReviewer::stripRetroWord('Zákaz platí od 1. 7. 2026, tedy i zpětně'), 'Zákaz platí od 1. 7. 2026');
check('„zpětně“ na začátku', Hlidac\AiReviewer::stripRetroWord('Zpětně se ruší poplatek'), 'Se ruší poplatek');
$hard = Hlidac\AiReviewer::sanitize(['titulek' => 'Daň se použije i zpětně za rok 2026', 'zavaznost' => 8, 'zpetnost_smer' => 'nova_povinnost']);
check('zpětná nová daň zůstane beze změny', [$hard['zavaznost'], $hard['titulek'], isset($hard['upravy'])], [8, 'Daň se použije i zpětně za rok 2026', false]);

echo "Dopad na občany (app/Impact.php)\n";
$q221 = 'Řízení o uložení odvodu za porušení rozpočtové kázně lze zahájit nejpozději do 4 let od porušení rozpočtové kázně.';
$impactRaw = [
    'skupiny' => ['prijemci_dotaci', 'Zemědělci', 'neexistuje', 'prijemci_dotaci', 'obce'],
    'ucinnost' => ['text' => '1. ledna 2028', 'citace' => 'Tento zákon nabývá účinnosti dnem 1. ledna 2028.'], // v textu není
    'povinnosti' => [['kdo' => 'Úřady', 'co' => 'Zahájit řízení do 4 let', 'citace' => $q221], ['kdo' => 'x', 'co' => 'bez citace']],
    'pravomoci' => [],
    'sankce' => 'nesmysl',
    'zbystrit' => 'Lhůta pro vymáhání dotací se zkracuje na 4 roky.',
    'pomuze' => [['kdo' => 'Příjemci dotací', 'jak' => 'kratší doba, po kterou lze dotaci vymáhat', 'citace' => $q221]],
    'pritizi' => [['kdo' => 'Úřady', 'jak' => 'méně času na kontrolu', 'citace' => 'Vymyšlená citace, která v textu návrhu určitě není.']],
    'scenare' => [['titulek' => 'Úřad má na vrácení dotace jen 4 roky', 'kdo' => 'obec, která dotaci poskytla', 'pribeh' => 'Řízení o odvodu lze zahájit nejpozději do 4 let.', 'pojistka' => 'v textu návrhu neuvedena', 'citace' => $q221],
        ['titulek' => 'bez citace', 'kdo' => 'x', 'pribeh' => 'y']],
    'rizika' => [['popis' => 'Kratší lhůta omezuje vymáhání neoprávněně čerpaných dotací.', 'pojistka' => 'v textu návrhu neuvedena', 'citace' => $q221],
        ['popis' => 'Vymyšlené riziko', 'pojistka' => '', 'citace' => 'Věta, která v textu návrhu vůbec není, ale je dost dlouhá.']],
];
$norm = Hlidac\Impact::normalize($impactRaw);
check('dopad: skupiny jen z číselníku, bez duplicit', $norm['skupiny'], ['prijemci_dotaci', 'obce']);
check('dopad: položka bez citace se zahodí', count($norm['povinnosti']), 1);
check('dopad: nesmyslný seznam je prázdný', $norm['sankce'], []);
check('dopad: úklid je idempotentní', Hlidac\Impact::normalize($norm), $norm);
check('dopad: prázdný dopad nic nezobrazí', Hlidac\Impact::hasContent(Hlidac\Impact::normalize([])), false);
check('dopad: starý rozbor bez dopadu ho nedostane', array_key_exists('dopad', Hlidac\AiReviewer::sanitize(['zavaznost' => 3, 'titulek' => 'x'])), false);
check('dopad: formát odpovědi ho po modelu chce', str_contains(Hlidac\AiReviewer::format(), '"dopad": {"skupiny"') && str_contains(Hlidac\AiReviewer::format(), 'drzitele_zbrani'), true);
$keep221 = db()->one('SELECT ai_json, ai_score, ai_at, ai_model, ai_prompt, ai_runs, ai_run_count, ai_prev_json, ai_prev_score, ai_prev_model FROM tisk_text WHERE ct = 221');
$impactReviewer = new Hlidac\AiReviewer(db(), config());
$impactReviewer->saveResult('tisk', 221, ['zavaznost' => 8, 'titulek' => 'Lhůta pro vymáhání dotací se zkracuje na 4 roky', 'citace' => [$q221], 'dopad' => $impactRaw], 'claude-sonnet-5-5');
$stored = json_decode((string)db()->value('SELECT ai_json FROM tisk_text WHERE ct = 221'), true);
check('dopad: neověřené citace z rozboru vyřazeny', [$stored['dopad']['ucinnost'], count($stored['dopad']['rizika']), count($stored['dopad']['povinnosti'])], [null, 1, 1]);
check('dopad: vyřazení je zapsané v korekcích', in_array('z dopadu vyřazeny položky bez citace ověřené v textu (5)', $stored['upravy'] ?? [], true), true);
check('dopad: do závažnosti nevstupuje', (int)db()->value('SELECT ai_score FROM tisk_text WHERE ct = 221'), 8);
check('dopad: pomůže s citací, přitíží bez citace vyřazeno, proč zbystřit', [count($stored['dopad']['pomuze']), count($stored['dopad']['pritizi']), $stored['dopad']['zbystrit']],
    [1, 0, 'Lhůta pro vymáhání dotací se zkracuje na 4 roky.']);
check('dopad: nejhorší čtení s ověřenou citací', [count($stored['dopad']['scenare']), $stored['dopad']['scenare'][0]['kdo'] ?? null], [1, 'obec, která dotaci poskytla']);
db()->run('UPDATE tisk_text SET ai_json = ?, ai_score = ?, ai_at = ?, ai_model = ?, ai_prompt = ?, ai_runs = ?, ai_run_count = ?, ai_prev_json = ?, ai_prev_score = ?, ai_prev_model = ? WHERE ct = 221',
    array_values($keep221));
db()->run("UPDATE tisky SET posledni = '2020-01-01', faze = '1. čtení' WHERE ct = 221"); // rozpracovaný, dlouho bez aktivity
$scorer->scoreBills();
$_GET = [];
ob_start();
(function (string $file): void {
    require $file;
})(APP_ROOT . '/index.php');
$home = (string)ob_get_clean();
check('homepage: srozumitelný titulek místo názvu novely', str_contains($home, 'Lhůta pro vymáhání dotací se zkracuje na 4 roky – i zpětně'), true);
check('homepage: citace u nálezu', str_contains($home, 'nejpozději do 4 let'), true);
check('bez CLI se AI přeskočí', (new Hlidac\AiReviewer(db(), $aiConfig))->run(), ['reviewed' => 0, 'errors' => 0, 'skipped' => true]);

echo "Vypínač rozesílky notify.post\n";
db()->run('UPDATE alerts SET posted = 2 WHERE posted = 0');
$postAlert = fn(string $key, int $watched) => db()->run(
    "INSERT INTO alerts (alert_key, kind, ct, event_at, created_at, title, body, watched, posted, score) VALUES (?, 'cut', 311, '2026-11-18 10:00:00', '2026-11-18 10:00:00', 't', 'b', ?, 0, 90)",
    [$key, $watched]);
$postCfg = config();
$postCfg['notify']['dry_run'] = true;
$postAlert('test:off', 1);
$postCfg['notify']['post'] = 'off';
$st = (new Hlidac\Publisher(db(), $postCfg))->run();
check('post=off: nic se neodešle (ani v dry_run)', $st['sent'], 0);
check('post=off: upozornění zůstane jen na webu', (int)db()->value("SELECT posted FROM alerts WHERE alert_key = 'test:off'"), 3);
$postAlert('test:watch-no', 0);
$postAlert('test:watch-yes', 1);
$postCfg['notify']['post'] = 'watch';
$st = (new Hlidac\Publisher(db(), $postCfg))->run();
check('post=watch: odejde jen sledované', $st['sent'], 1);
check('post=watch: nesledované se neposílá', (int)db()->value("SELECT posted FROM alerts WHERE alert_key = 'test:watch-no'"), 3);
check('post=watch: sledované odesláno', (int)db()->value("SELECT posted FROM alerts WHERE alert_key = 'test:watch-yes'"), 1);
db()->run("DELETE FROM alerts WHERE alert_key LIKE 'test:%'");
db()->run("DELETE FROM post_log WHERE alert_key LIKE 'test:%'");

echo "Prompt AI bez jména předkladatele a typu návrhu\n";
$promptOf = function (string $method, array $row): string {
    $m = new ReflectionMethod(Hlidac\AiReviewer::class, $method);
    return $m->invoke(new Hlidac\AiReviewer(db(), config()), $row, 'text', []);
};
$pnPrompt = $promptOf('pnPrompt', ['uplny_nazev' => 'Návrh X', 'osoba' => 'Marian Jurečka', 'signals' => '{}']);
check('prompt PN neobsahuje jméno předkladatele', str_contains($pnPrompt, 'Jurečka'), false);
$billPrompt = $promptOf('billPrompt', ['uplny_nazev' => 'Návrh X', 'nazev' => 'X', 'typ_navrhu' => 'poslanecký', 'signals' => '{}']);
check('prompt návrhu neobsahuje typ návrhu', str_contains($billPrompt, 'poslanecký') || str_contains($billPrompt, 'poslanci'), false);

echo "Neúplný export nepřepíše data\n";
$bad = $tmp . '/src-bad';
@mkdir($bad, 0775, true);
foreach (['sd.zip', 'poslanci.zip'] as $f) {
    copy($tmp . '/src/' . $f, $bad . '/' . $f);
}
copy($tmp . '/src/tisky.zip', $bad . '/tisky.zip');
$zipBad = new ZipArchive();
$zipBad->open($bad . '/tisky.zip');
$zipBad->addFromString('tisky.unl', '');   // prázdný soubor se stejným jménem
$zipBad->close();
$countsBefore = [(int)db()->value('SELECT COUNT(*) FROM tisky'), (int)db()->value('SELECT COUNT(*) FROM kroky')];
$syncBefore = db()->getMeta('last_sync');
$importError = null;
try {
    (new Hlidac\Importer(db(), new Hlidac\Source('', $tmp . '/cache-bad', $bad)))->run();
} catch (RuntimeException $ex) {
    $importError = $ex->getMessage();
}
check('prázdný tisky.unl import zastaví', $importError !== null && str_contains($importError, 'Import zastaven'), true);
check('tisky a kroky zůstaly', [(int)db()->value('SELECT COUNT(*) FROM tisky'), (int)db()->value('SELECT COUNT(*) FROM kroky')], $countsBefore);
check('last_sync se neposunul', db()->getMeta('last_sync'), $syncBefore);
check('důvod zapsán v meta', str_contains((string)db()->getMeta('import_error'), 'propad počtu řádků kroky') || str_contains((string)db()->getMeta('import_error'), 'žádné tisky'), true);
(new Hlidac\Importer(db(), new Hlidac\Source('', $tmp . '/cache-bad', $tmp . '/src')))->run();
check('úplný import chybu smaže', db()->getMeta('import_error'), '');

echo "Stav rozboru textů\n";
$repoTest = new Hlidac\Repo(db());
$rs = $repoTest->reviewStatus();
check('tisk 311: počet textů k posouzení = PN', $rs[311]['total'] ?? null, (int)db()->value('SELECT COUNT(*) FROM pn WHERE ct = 311') + (int)db()->value('SELECT COUNT(*) FROM tisk_text WHERE ct = 311'));
check('štítek bez rozboru', Hlidac\Repo::reviewLabel(['total' => 3, 'done' => 0]), 'Text neposouzen');
check('štítek částečně', Hlidac\Repo::reviewLabel(['total' => 3, 'done' => 1]), 'Posouzeno 1 z 3 textů');
check('štítek vše posouzeno', Hlidac\Repo::reviewLabel(['total' => 3, 'done' => 3]), null);
check('jeden tisk = stejný výsledek jako hromadně', $repoTest->reviewStatus(311)[311] ?? null, $rs[311] ?? null);

echo "Tokeny se neukládají do chyb\n";
$leak = Hlidac\Http::redact('HTTP chyba při volání https://api.telegram.org/bot123456:ABC-tajne/sendMessage: timeout; '
    . 'https://web.cz/ai_api.php?token=tajne123&action=queue; Authorization: Bearer tajneJWT.x; {"accessJwt":"tajne.jwt"}');
check('maskování tokenů v chybě', str_contains($leak, 'tajne'), false);
check('maskování ponechá zbytek zprávy', str_contains($leak, 'api.telegram.org/bot***/sendMessage: timeout'), true);
$reviewerLeak = new Hlidac\AiReviewer(db(), config());
$leakId = (int)db()->value('SELECT id_dokument FROM pn_text ORDER BY id_dokument LIMIT 1');
$reviewerLeak->saveError('pn', $leakId, 'HTTP 500 z https://web.cz/ai_api.php?token=tajne123&action=result: x');
check('saveError uloží chybu bez tokenu', str_contains((string)db()->value('SELECT error FROM pn_text WHERE id_dokument = ?', [$leakId]), 'tajne'), false);
// dotaz klienta na frontu (ai_api.php?action=queue) se zapíše jako známka života klienta AI (hlídání v botovi reels)
$reviewerLeak->markPolled();
check('markPolled zapíše čas dotazu na frontu', (bool)preg_match('~^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$~', (string)db()->getMeta('ai_queue_seen_at')), true);

echo "Krátký čas mezi PN a 3. čtením (tisk 198: „zahájeno“ datované po „ukončeno“)\n";
$gapRow = fn(int $id, string $d, int $typ, int $akce) => db()->run(
    'INSERT INTO kroky (id_hist, id_tisk, ct, datum, typ_stavu, stav, akce_id, akce) VALUES (?, 0, 311, ?, ?, ?, ?, ?)',
    [$id, "$d 00:00:00", $typ, '', $akce, '']);
db()->run('DELETE FROM kroky WHERE ct = 311 AND typ_stavu IN (5, 21)');
$gapRow(990001, '2026-06-30', 21, 23);
$gapRow(990002, '2026-06-30', 5, 86);   // administrativní krok se nepočítá
$gapRow(990003, '2026-07-02', 5, 71);   // projednávání ukončeno
$gapRow(990004, '2026-07-08', 5, 238);  // zahájeno (datované později)
db()->run('UPDATE tisky SET souhrn_pn = NULL, souhrn_pn_at = NULL WHERE ct = 311'); // bez souhrnu PN: od kroku „Pozměňovací návrhy“
(new Hlidac\Scorer(db(), config()))->scoreBills();
$gap = array_values(array_filter(json_decode((string)db()->value('SELECT reasons FROM tisky WHERE ct = 311'), true),
    fn(array $r): bool => $r['key'] === 'short_gap'));
check('odstup se počítá od nejdřívějšího kroku 3. čtení', $gap[0]['label'] ?? null, 'Mezi pozměňovacími návrhy a 3. čtením jen 2 dny (souhrn pozměňovacích návrhů nebyl rozeslán jako sněmovní tisk)');

echo "Předkladatel, § 90/2 a lhůta pro výbory z údajů tisku\n";
check('kraj podle id_navrh', Hlidac\Analyzer::typNavrhu(2, 'Návrh Zastupitelstva Jihomoravského kraje na vydání zákona', 16), 'krajský');
check('kraj podle názvu i s velkým Z', Hlidac\Analyzer::typNavrhu(2, 'Návrh Zastupitelstva hlavního města Prahy na vydání zákona'), 'krajský');
check('skupina poslanců', Hlidac\Analyzer::typNavrhu(2, 'Návrh poslanců …', 3), 'poslanecký');
check('vláda podle id_navrh', Hlidac\Analyzer::typNavrhu(1, 'Vládní návrh zákona', 1), 'vládní');
check('lhůta pro výbory 30 dní = zkrácená', [Hlidac\Analyzer::committeeCut(30), Hlidac\Analyzer::committeeCut(60), Hlidac\Analyzer::committeeCut(null)], [true, false, false]);
$imp = db()->one('SELECT p90, zl, lhuta, typ_navrhu FROM tisky WHERE ct = 221');
check('importované tisky mají lhůtu a typ', is_array($imp) && array_key_exists('lhuta', $imp), true);
db()->run("UPDATE tisky SET zl = 1, lhuta = 30 WHERE ct = 90");
(new Hlidac\Scorer(db(), config()))->scoreBills();
$cut = array_values(array_filter(json_decode((string)db()->value('SELECT reasons FROM tisky WHERE ct = 90'), true),
    fn(array $r): bool => $r['key'] === 'deadline_cut'));
check('důvod uvádí zkrácenou lhůtu pro výbory', $cut[0]['label'] ?? null, 'Zkrácená lhůta pro výbory: 30 dní místo 60');
db()->run("UPDATE tisky SET p90 = 1 WHERE ct = 90");
db()->run("DELETE FROM alerts WHERE alert_key = 'p90:90'");
(new Hlidac\AlertBuilder(db(), config()))->run();
check('návrh na § 90/2 z údajů tisku vytvoří upozornění', db()->value("SELECT kind FROM alerts WHERE alert_key = 'p90:90'"), 'p90');

echo "Opakované stažení neúspěšných textů\n";
$retryCt = (int)db()->value("SELECT ct FROM tisky WHERE faze <> 'Vyhlášeno' ORDER BY ct LIMIT 1");
db()->run('DELETE FROM tisk_text WHERE ct = ?', [$retryCt]);
db()->run("INSERT INTO tisk_text (ct, docx_url, text, chars, signals, fetched_at, error) VALUES (?, NULL, '', 0, '[]', ?, 'Chyba sítě')", [$retryCt, date('Y-m-d H:i:s')]);
$queue = fn(): array => array_map('intval', array_column(Hlidac\TiskText::queue(db(), 1000, 24), 'ct'));
check('čerstvá chyba se hned neopakuje', in_array($retryCt, $queue(), true), false);
db()->run('UPDATE tisk_text SET fetched_at = ? WHERE ct = ?', [date('Y-m-d H:i:s', time() - 25 * 3600), $retryCt]);
check('po 24 h se stažení zopakuje', in_array($retryCt, $queue(), true), true);
db()->run("UPDATE tisk_text SET text = 'obsah', error = NULL WHERE ct = ?", [$retryCt]);
check('úspěšně stažený text se neopakuje', in_array($retryCt, $queue(), true), false);

echo "Hledání bez diakritiky\n";
check('fold', Hlidac\Repo::fold('Školský ZÁKON'), 'skolsky zakon');
$repoQ = new Hlidac\Repo(db());
$nameWord = explode(' ', (string)db()->value('SELECT nazev FROM tisky WHERE ct = 221'));
$hit = fn(string $q): bool => in_array(221, array_map(fn($t) => (int)$t['ct'], $repoQ->bills('', $q)), true);
check('hledání „střetu“ najde tisk 221', $hit('STŘETU'), true);
check('hledání bez diakritiky „stretu zajmu“', $hit('stretu zajmu'), true);
check('hledání podle čísla tisku', $hit('221'), true);

echo "Čtvrtá várka: Word, zápor zpětnosti, ověřený rozbor, Bluesky\n";
$docx = $tmp . '/tracked.docx';
$dz = new ZipArchive();
$dz->open($docx, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$dz->addFromString('word/document.xml', '<w:document><w:body><w:p><w:r><w:t>Lhůta činí </w:t></w:r>'
    . '<w:del w:id="1" w:author="X"><w:r><w:delText>10 let</w:delText></w:r></w:del>'
    . '<w:ins w:id="2" w:author="X"><w:r><w:t>4 roky</w:t></w:r></w:ins><w:r><w:t>.</w:t></w:r></w:p></w:body></w:document>');
$dz->close();
check('smazaný text ze sledovaných změn se nečte', Hlidac\PnText::docxToText((string)file_get_contents($docx)), 'Lhůta činí 4 roky.');
check('„nemá zpětnou účinnost“ není zpětnost', Hlidac\PnText::procedureSignals('Toto rozhodnutí nemá zpětnou účinnost, a to ani v případě zajištění zvířete.'), []);
check('„zpětné použití se vylučuje“ není zpětnost', Hlidac\PnText::negatesRetro('Zpětné použití se vylučuje.'), true);
check('skutečná zpětná účinnost zůstává', isset(Hlidac\PnText::procedureSignals('Ustanovení má zpětnou účinnost ke dni 1. ledna 2020.')['retro']), true);

$guardCt = (int)db()->value('SELECT ct FROM tisk_text ORDER BY ct LIMIT 1');
$verifier = Hlidac\AiReviewer::verifyModel(config());
db()->run("UPDATE tisk_text SET text = 'Lhůta činí 4 roky.', ai_json = '{}', ai_score = 7, ai_model = ?, ai_prompt = ?, ai_at = ? WHERE ct = ?",
    [$verifier, Hlidac\AiReviewer::PROMPT_VERSION, date('Y-m-d H:i:s'), $guardCt]);
$guard = new Hlidac\AiReviewer(db(), config());
$guard->saveResult('tisk', $guardCt, ['zavaznost' => 1, 'titulek' => 'x', 'citace' => []], Hlidac\AiReviewer::model(config()));
check('pozdní výsledek 1. kola nepřepíše ověřený rozbor', [(int)db()->value('SELECT ai_score FROM tisk_text WHERE ct = ?', [$guardCt]),
    db()->value('SELECT ai_model FROM tisk_text WHERE ct = ?', [$guardCt])], [7, $verifier]);
$guard->saveResult('tisk', $guardCt, ['zavaznost' => 5, 'titulek' => 'y', 'citace' => ['Lhůta činí 4 roky.']], $verifier);
check('ověřovací model rozbor přepsat smí', (int)db()->value('SELECT ai_score FROM tisk_text WHERE ct = ?', [$guardCt]), 5);

echo "Spolehlivost rozboru AI (#7 bod 7)\n";
check('medián (u sudého počtu nižší)', [Hlidac\AiReviewer::median([5, 2, 6]), Hlidac\AiReviewer::median([5, 2]), Hlidac\AiReviewer::median([4])], [5, 2, 4]);
$haiku = Hlidac\AiReviewer::model(config());
$sonnet = Hlidac\AiReviewer::verifyModel(config());
// tisk 311: 311 % 4 = 3 → při sample_every = 4 je ve vzorku nízkých hodnocení
$relCfg = config();
$relCfg['ai']['sample_every'] = 4;
$rel = new Hlidac\AiReviewer(db(), $relCfg);
db()->run('DELETE FROM tisk_text WHERE ct = 311');
db()->run("INSERT INTO tisk_text (ct, text, chars, signals, fetched_at) VALUES (311, 'Lhůta činí 4 roky.', 18, '[]', ?)", [date('Y-m-d H:i:s')]);
$inQueue = fn(bool $verify) => in_array(311, array_column($rel->queue(50, 311, $verify), 'id'), true);
$state = fn() => db()->one('SELECT ai_score, ai_run_count, ai_runs, ai_json FROM tisk_text WHERE ct = 311');
$rel->saveResult('tisk', 311, ['zavaznost' => 5, 'titulek' => 'a', 'citace' => ['Lhůta činí 4 roky.']], $haiku);
check('hraniční hodnocení 5 → zpět do fronty na další běh', [(int)$state()['ai_run_count'], $inQueue(false)], [1, true]);
$rel->saveResult('tisk', 311, ['zavaznost' => 2, 'titulek' => 'b', 'citace' => ['Lhůta činí 4 roky.']], $haiku);
check('po 2. běhu nižší medián a ještě 3. běh', [(int)$state()['ai_score'], (int)$state()['ai_run_count'], $inQueue(false)], [2, 2, true]);
$rel->saveResult('tisk', 311, ['zavaznost' => 6, 'titulek' => 'c', 'citace' => ['Lhůta činí 4 roky.']], $haiku);
$st = $state();
// uložený je jen aktuální rozbor: po 2. běhu „b“ (medián 2), po 3. běhu bližší mediánu 5 je „c“ (6) než „b“ (2)
check('po 3. běhu medián 5, rozbor z běhu blíž mediánu, fronta hotová', [(int)$st['ai_score'], (int)$st['ai_run_count'], json_decode($st['ai_runs'], true),
    json_decode($st['ai_json'], true)['titulek'], $inQueue(false)], [5, 3, [5, 2, 6], 'c', false]);
db()->run('UPDATE tisk_text SET ai_score = 1, ai_runs = NULL, ai_run_count = NULL, ai_prev_score = NULL, ai_prev_model = NULL WHERE ct = 311');
check('nízké hodnocení mimo pásmo se neopakuje', $inQueue(false), false);
check('nízké hodnocení ve vzorku jde k ověření silnějším modelem', $inQueue(true), true);
$other = config();
$other['ai']['sample_every'] = 5;
check('nízké hodnocení mimo vzorek k ověření nejde', in_array(311, array_column((new Hlidac\AiReviewer(db(), $other))->queue(50, 311, true), 'id'), true), false);
// sample_every 2 a 3: pevný zbytek 3 by nikdy nenastal, vzorek by byl prázdný (311 % 2 = 1)
$two = config();
$two['ai']['sample_every'] = 2;
check('zbytek vzorku podle sample_every', array_map([Hlidac\AiReviewer::class, 'sampleRemainder'], [10, 4, 3, 2]), [3, 3, 0, 1]);
check('vzorek funguje i při sample_every = 2', in_array(311, array_column((new Hlidac\AiReviewer(db(), $two))->queue(50, 311, true), 'id'), true), true);
$rel->saveResult('tisk', 311, ['zavaznost' => 7, 'titulek' => 'd', 'citace' => ['Lhůta činí 4 roky.']], $sonnet);
$stats = (new Hlidac\Repo(db()))->aiReliability($relCfg);
check('statistika vzorku: silnější model našel přehlédnutý problém', [$stats['sample']['n'], $stats['sample']['to_points'], $stats['sample']['to_finding'], $stats['sample']['higher']], [1, 1, 1, 1]);
db()->run('DELETE FROM tisk_text WHERE ct = 311');
db()->run("DELETE FROM ai_revize WHERE druh = 'tisk' AND ref_id = 311");
// pojistky platí i po mediánu: běhy [3 (strop: zpětnost ve prospěch veřejnosti), 5, 7] mají medián 5,
// při shodě vzdáleností zůstane rozbor se stropem 3 a ten medián nesmí překročit
db()->run("INSERT INTO tisk_text (ct, text, chars, signals, fetched_at) VALUES (311, 'Lhůta činí 4 roky.', 18, '[]', ?)", [date('Y-m-d H:i:s')]);
$rel->saveResult('tisk', 311, ['zavaznost' => 5, 'titulek' => 'strop', 'zpetnost_smer' => 've_prospech_verejnosti', 'citace' => ['Lhůta činí 4 roky.']], $haiku);
$rel->saveResult('tisk', 311, ['zavaznost' => 5, 'titulek' => 'e', 'citace' => ['Lhůta činí 4 roky.']], $haiku);
$rel->saveResult('tisk', 311, ['zavaznost' => 7, 'titulek' => 'f', 'citace' => ['Lhůta činí 4 roky.']], $haiku);
$st = $state();
check('strop pojistky platí i po mediánu opakovaných běhů', [(int)$st['ai_score'], json_decode($st['ai_runs'], true), json_decode($st['ai_json'], true)['titulek'],
    (int)json_decode($st['ai_json'], true)['zavaznost']], [3, [3, 5, 7], 'strop', 3]);
db()->run('DELETE FROM tisk_text WHERE ct = 311');
db()->run("DELETE FROM ai_revize WHERE druh = 'tisk' AND ref_id = 311");

$bskyUrl = 'https://hlidac.test/tisk.php?ct=221';
$fitted = Hlidac\Notifier\Bluesky::fit(str_repeat('á', 300), $bskyUrl);
check('Bluesky: text i s odkazem do 300 znaků', mb_strlen($fitted . "\n" . $bskyUrl) <= 300 && str_ends_with($fitted, '…'), true);
check('Bluesky: krátký text beze změny', Hlidac\Notifier\Bluesky::fit('Krátce', $bskyUrl), 'Krátce');
check('testy nepíšou do data/posts.log', is_file(APP_ROOT . '/data/posts.log') && filemtime(APP_ROOT . '/data/posts.log') >= $_SERVER['REQUEST_TIME'], false);

echo "Pátá várka: vrácený tisk, nával podle dne, PN před předlohou, nové období\n";
check('vrácený tisk (rozpočet 17)', Hlidac\Analyzer::analyze([
    ['typ_stavu' => 1, 'akce_id' => 29, 'datum' => '2025-11-12 00:00:00'],
    ['typ_stavu' => 6, 'akce_id' => 24, 'datum' => '2025-11-26 00:00:00'],
])['faze'], 'Vráceno předkladateli');

$surgeDay = date('Y-m-d', strtotime('-1 day'));
$addPn = function (int $from, int $count, int $ct, string $day): void {
    for ($i = 0; $i < $count; $i++) {
        db()->run('INSERT INTO pn (id_dokument, cislo, ct, id_osoba, osoba, cas) VALUES (?, ?, ?, ?, ?, ?)',
            [$from + $i, $from + $i, $ct, 1, 'Test Poslanec', $day . ' 1' . ($i % 10) . ':00:00']);
    }
};
$builder = new Hlidac\AlertBuilder(db(), config());
$addPn(970000, 6, 221, $surgeDay);
$builder->run();
check('6 PN za den ještě není nával', (int)db()->value("SELECT COUNT(*) FROM alerts WHERE kind = 'surge' AND ct = 221 AND event_at LIKE ?", [$surgeDay . '%']), 0);
$addPn(970100, 5, 221, $surgeDay);
$builder->run();
check('11 PN téhož dne ve dvou bězích = nával', (int)db()->value("SELECT COUNT(*) FROM alerts WHERE kind = 'surge' AND ct = 221 AND event_at LIKE ?", [$surgeDay . '%']), 1);
$addPn(970200, 3, 221, $surgeDay);
$builder->run();
check('další PN téhož dne už druhý nával nevytvoří', (int)db()->value("SELECT COUNT(*) FROM alerts WHERE kind = 'surge' AND ct = 221 AND event_at LIKE ?", [$surgeDay . '%']), 1);

$addPn(970300, 1, 9999, $surgeDay);
$builder->run();
check('PN k tisku, který ještě není v přehledu, čeká', db()->value('SELECT 1 FROM pn_seen WHERE id_dokument = 970300'), null);
db()->run('DELETE FROM pn WHERE id_dokument >= 970000 AND id_dokument < 980000');

// přelom období: tisk 221 nového období je jiný návrh než tisk 221 minulého
$keysBefore = (int)db()->value('SELECT COUNT(*) FROM alerts');
// texty a rozbory minulého období se nesmažou, ale přesunou do archivu a historie (#2 bod 2)
db()->run('DELETE FROM tisk_text WHERE ct = 311');
db()->run("INSERT INTO tisk_text (ct, docx_url, text, chars, signals, fetched_at, ai_json, ai_score, ai_model, ai_prompt, ai_at)
    VALUES (311, 'https://www.psp.cz/sqw/text/orig2.sqw?idd=900', 'Text tisku 311 minulého období.', 31, '{}', ?, ?, 6, 'claude-haiku-5-5', 2, ?)",
    [date('Y-m-d H:i:s'), json_encode(['titulek' => 'Starý rozbor', 'zavaznost' => 6]), date('Y-m-d H:i:s')]);
$textsBefore = [(int)db()->value('SELECT COUNT(*) FROM tisk_text'), (int)db()->value('SELECT COUNT(*) FROM pn_text')];
$aiBefore = (int)db()->value('SELECT COUNT(*) FROM tisk_text WHERE ai_at IS NOT NULL') + (int)db()->value('SELECT COUNT(*) FROM pn_text WHERE ai_at IS NOT NULL');
db()->run('UPDATE alerts SET obdobi = 9');
db()->setMeta('obdobi_id', '999');
db()->setMeta('obdobi_cislo', '9');
(new Hlidac\Importer(db(), new Hlidac\Source('', $tmp . '/cache-switch', $tmp . '/src')))->run();
check('stará upozornění dostala klíč s obdobím', (int)db()->value("SELECT COUNT(*) FROM alerts WHERE alert_key LIKE '9/%'"), $keysBefore);
check('texty minulého období nejsou v pracovních tabulkách', [(int)db()->value('SELECT COUNT(*) FROM tisk_text'), (int)db()->value('SELECT COUNT(*) FROM pn_text')], [0, 0]);
check('texty minulého období jsou celé v archivu', [(int)db()->value('SELECT COUNT(*) FROM tisk_text_archiv WHERE obdobi = 9'), (int)db()->value('SELECT COUNT(*) FROM pn_text_archiv WHERE obdobi = 9')], $textsBefore);
check('archiv drží i rozbor AI', db()->value('SELECT ai_score FROM tisk_text_archiv WHERE obdobi = 9 AND ct = 311') !== null, true);
check('historie má texty minulého období', (int)db()->value("SELECT COUNT(*) FROM text_revize WHERE obdobi = 9 AND druh = 'tisk' AND ref_id = 311"), 1);
check('historie má všechny rozbory minulého období', $aiBefore > 0 && (int)db()->value('SELECT COUNT(*) FROM ai_revize WHERE obdobi = 9') >= $aiBefore, true);
check('nové období starou historii nepoužije', [Hlidac\Revisions::counts(db(), 'tisk')[311] ?? null, (int)db()->value('SELECT COUNT(*) FROM text_revize WHERE obdobi = 10')], [null, 0]);
check('nové období začne bootstrapem', db()->getMeta('bootstrapped'), null);
$created = (new Hlidac\AlertBuilder(db(), config()))->run();
check('nová upozornění vzniknou i se stejným číslem tisku', $created > 0 && db()->value("SELECT kind FROM alerts WHERE alert_key = 'newbill:221'") !== null, true);
check('nová upozornění jsou bootstrap (neposílají se)', (int)db()->value("SELECT COUNT(*) FROM alerts WHERE obdobi = 10 AND posted <> 2"), 0);
$old = db()->one("SELECT * FROM alerts WHERE alert_key LIKE '9/%' LIMIT 1");
check('staré upozornění vede na psp.cz', str_contains(Hlidac\View::alertUrl($old), 'psp.cz') && str_contains(Hlidac\View::alertUrl($old), 'o=9'), true);
check('detail tisku ukazuje jen upozornění aktuálního období', array_unique(array_map(fn($a) => (int)$a['obdobi'],
    (new Hlidac\Repo(db()))->alerts(100, 0, 221))), [10]);

echo "Šestá várka: titulek jen s citací, lhůta a zpětnost, obnova textu tisku\n";
$hlCt = (int)db()->value("SELECT ct FROM tisky WHERE faze <> 'Vyhlášeno' ORDER BY ct LIMIT 1");
db()->run('DELETE FROM tisk_text WHERE ct = ?', [$hlCt]);
db()->run("INSERT INTO tisk_text (ct, docx_url, text, chars, signals, fetched_at, ai_json, ai_score, ai_at) VALUES (?, 'https://www.psp.cz/x', 'text', 4, '{}', ?, ?, 7, ?)",
    [$hlCt, date('Y-m-d H:i:s', time() - 8 * 86400), json_encode(['titulek' => 'Nález bez citace', 'citace' => []]), date('Y-m-d H:i:s')]);
check('nález bez ověřené citace nedá titulek na homepage', (new Hlidac\Repo(db()))->headline($hlCt), null);
db()->run('UPDATE tisk_text SET ai_json = ? WHERE ct = ?', [json_encode(['titulek' => 'Nález', 'perex' => '', 'citace' => ['doslovná věta']]), $hlCt]);
check('nález s citací titulek dá', (new Hlidac\Repo(db()))->headline($hlCt)['title'] ?? null, 'Nález');
check('text starší než týden se ověří proti psp.cz', in_array($hlCt, array_map('intval', array_column(Hlidac\TiskText::staleQueue(db(), 1000, 7), 'ct')), true), true);
db()->run('UPDATE tisk_text SET checked_at = ? WHERE ct = ?', [date('Y-m-d H:i:s'), $hlCt]);
check('právě ověřený text se znovu neověřuje', in_array($hlCt, array_map('intval', array_column(Hlidac\TiskText::staleQueue(db(), 1000, 7), 'ct')), true), false);

db()->run("UPDATE tisk_text SET signals = ?, ai_score = NULL, ai_at = NULL WHERE ct = ?", [json_encode(['retro' => ['x'], 'retro_deadline' => ['x']]), $hlCt]);
(new Hlidac\Scorer(db(), config()))->scoreBills();
$keys = array_column(json_decode((string)db()->value('SELECT reasons FROM tisky WHERE ct = ?', [$hlCt]), true) ?: [], 'key');
check('zpětnost bez změny lhůty v textu nedá body za lhůtu', in_array('retro_deadline', $keys, true), false);
db()->run("UPDATE tisk_text SET signals = ? WHERE ct = ?", [json_encode(['retro' => ['x'], 'retro_deadline' => ['x'], 'deadline' => ['y']]), $hlCt]);
(new Hlidac\Scorer(db(), config()))->scoreBills();
$keys = array_column(json_decode((string)db()->value('SELECT reasons FROM tisky WHERE ct = ?', [$hlCt]), true) ?: [], 'key');
check('změna lhůty i zpětné použití body dají', in_array('retro_deadline', $keys, true), true);
db()->run('DELETE FROM tisk_text WHERE ct = ?', [$hlCt]);

echo "Metodika skóre (#7): postup tisku, pozdní PN, ověřené AI u PN, přílepek proti textu návrhu\n";
$reasonsOf = function (int $ct): array {
    $out = [];
    foreach (json_decode((string)db()->value('SELECT reasons FROM tisky WHERE ct = ?', [$ct]), true) ?: [] as $r) {
        $out[$r['key']] = $r;
    }
    return $out;
};
$rescore = fn() => (new Hlidac\Scorer(db(), config()))->scoreBills();
$progressSteps = db()->all('SELECT * FROM kroky WHERE ct = 221 AND typ_stavu IN (2, 3, 4, 5, 21, 64, 12, 14, 63, 8, 13, 11)');
db()->run('DELETE FROM kroky WHERE ct = 221 AND typ_stavu IN (2, 3, 4, 5, 21, 64, 12, 14, 63, 8, 13, 11)');
db()->run('UPDATE tisky SET p90ok = 0 WHERE ct = 221');
$rescore();
check('poslanecký návrh v 1. čtení: 5 b.', $reasonsOf(221)['mp_bill']['points'] ?? null, 5);
db()->run("INSERT INTO kroky (id_hist, id_tisk, ct, datum, typ_stavu, stav, akce_id, akce) VALUES (990101, 43343, 221, '2026-10-01 00:00:00', 2, '', 0, '')");
$rescore();
check('poslanecký návrh ve 2. čtení: 15 b.', $reasonsOf(221)['mp_bill']['points'] ?? null, 15);
db()->run('DELETE FROM kroky WHERE id_hist = 990101');
foreach ($progressSteps as $k) {
    db()->insertMany('kroky', array_keys(array_filter($k, 'is_string', ARRAY_FILTER_USE_KEY)), [$k]);
}
db()->run("UPDATE tisky SET p90 = 1, p90ok = 0, faze = 'Vzat zpět' WHERE ct = 221");
$rescore();
check('§ 90/2 u vzatého zpět bez bodů', isset($reasonsOf(221)['p90_requested']), false);
db()->run("UPDATE tisky SET p90 = 0, faze = '1. čtení' WHERE ct = 221");

// AI u PN: neověřený vysoký nález nejvýš 5/10, po ověření naplno
$pnRow = db()->one('SELECT id_dokument FROM pn WHERE ct = 221 LIMIT 1');
db()->run('DELETE FROM pn_text WHERE id_dokument = ?', [(int)$pnRow['id_dokument']]);
db()->run("INSERT INTO pn_text (id_dokument, ct, cislo, text, chars, laws, signals, rule_score, ai_json, ai_score, ai_model, ai_at)
    VALUES (?, 221, 1, 'x', 1, '', '{}', 0, ?, 9, 'claude-haiku-5-5', ?)", [(int)$pnRow['id_dokument'], json_encode(['citace' => ['x']]), date('Y-m-d H:i:s')]);
$rescore();
check('neověřený nález PN 9/10 se počítá jako 5/10', $reasonsOf(221)['ai']['points'] ?? null, 15);
db()->run('UPDATE pn_text SET ai_model = ? WHERE id_dokument = ?', [Hlidac\AiReviewer::verifyModel(config()), (int)$pnRow['id_dokument']]);
$rescore();
check('ověřený nález PN 9/10 naplno', $reasonsOf(221)['ai']['points'] ?? null, 27);

// přílepek: PN mění zákon, který mění i samotný text návrhu → není přílepek
db()->run("UPDATE pn_text SET ai_score = NULL, ai_json = NULL, ai_model = NULL, signals = ?, rule_score = 20 WHERE id_dokument = ?",
    [json_encode(['other_laws' => ['89/2012']]), (int)$pnRow['id_dokument']]);
$rescore();
check('PN měnící jiný zákon = přílepek', isset($reasonsOf(221)['pn_other_law']), true);
$billBackup = db()->one('SELECT * FROM tisk_text WHERE ct = 221');
db()->run('DELETE FROM tisk_text WHERE ct = 221');
db()->run("INSERT INTO tisk_text (ct, text, chars, signals) VALUES (221, 'x', 1, ?)", [json_encode(['laws' => ['89/2012']])]);
$rescore();
check('zákon mění i text návrhu → není přílepek', isset($reasonsOf(221)['pn_other_law']), false);
check('zákony z textu návrhu', Hlidac\TiskText::analyze("Část druhá\nV zákoně č. 89/2012 Sb., občanský zákoník, se § 1 mění takto:")['signals']['laws'] ?? [], ['89/2012']);
db()->run('DELETE FROM pn_text WHERE id_dokument = ?', [(int)$pnRow['id_dokument']]);
db()->run('DELETE FROM tisk_text WHERE ct = 221');
if ($billBackup !== null) {
    db()->insertMany('tisk_text', array_keys(array_filter($billBackup, 'is_string', ARRAY_FILTER_USE_KEY)), [$billBackup]);
}
$rescore();

// pozdní PN relativně k tisku
$w = Hlidac\Scorer::weights(config());
check('výchozí váhy: citlivá oblast 5, podíl pozdních PN 50 %', [$w['topic'], $w['late_pn_share'], $w['mp_bill_early']], [5, 0.5, 5]);

echo "Metodika skóre (#7 bod 6): víc PN = víc šancí na závažný nález\n";
// testovací tisk bez procedurálních signálů: vládní, bez § 90/2, bez zkrácené lhůty, bez citlivé oblasti;
// $ai = [pořadí PN => závažnost AI (ověřeno silnějším modelem)], $extra = [pořadí PN => sloupce pn_text]
$verifiedModel = Hlidac\AiReviewer::verifyModel(config());
$fixtureBill = function (int $ct, int $pnCount, array $ai, array $extra = []) use ($verifiedModel): void {
    $t = array_filter(db()->one('SELECT * FROM tisky WHERE ct = 90'), 'is_string', ARRAY_FILTER_USE_KEY);
    $t = array_replace($t, array_intersect_key(['id_tisk' => 980000 + $ct, 'ct' => $ct, 'typ_navrhu' => 'vládní', 'p90' => 0, 'p90ok' => 0, 'zl' => 0,
        'rj' => 0, 'lhuta' => null, 'faze' => '2. čtení', 'nazev' => 'Testovací návrh', 'uplny_nazev' => 'Testovací návrh', 'zakony' => ''], $t));
    db()->insertMany('tisky', array_keys($t), [$t]);
    for ($i = 1; $i <= $pnCount; $i++) {
        $id = $ct * 1000 + $i;
        db()->run('INSERT INTO pn (id_dokument, cislo, ct, id_osoba, osoba, cas) VALUES (?, ?, ?, 1, ?, ?)',
            [$id, $id, $ct, 'Test Poslanec', '2026-10-0' . (1 + $i % 9) . ' 10:00:00']);
        if (isset($ai[$i]) || isset($extra[$i])) {
            $row = array_replace(['id_dokument' => $id, 'ct' => $ct, 'cislo' => $id, 'text' => 'x', 'chars' => 1, 'laws' => '', 'signals' => '{}',
                'rule_score' => 0, 'ai_json' => isset($ai[$i]) ? json_encode(['citace' => ['x']]) : null, 'ai_score' => $ai[$i] ?? null,
                'ai_model' => isset($ai[$i]) ? $verifiedModel : null, 'ai_at' => isset($ai[$i]) ? date('Y-m-d H:i:s') : null,
                'fetched_at' => date('Y-m-d H:i:s')], $extra[$i] ?? []);
            db()->insertMany('pn_text', array_keys($row), [$row]);
        }
    }
};
$dropBill = function (int $ct): void {
    db()->run('DELETE FROM pn_text WHERE ct = ?', [$ct]);
    db()->run('DELETE FROM pn WHERE ct = ?', [$ct]);
    db()->run('DELETE FROM tisk_text WHERE ct = ?', [$ct]);
    db()->run('DELETE FROM tisky WHERE ct = ?', [$ct]);
};
check('výchozí prahy bodu 6 v config scoring',
    [$w['pn_many_min'], $w['pn_many_share'], $w['pn_many_abs'], $w['pn_flag_ai_min'], $w['ai_pn_full_min'], $w['ai_pn_full_count'], $w['ai_pn_full_share'], $w['ai_pn_single_factor']],
    [3, 0.2, 10, 6, 6, 2, 0.25, 0.5]);

// tisk D: 4 PN, 3 s ověřenou AI 6 → pn_many i plné body AI
$fixtureBill(9801, 4, [1 => 6, 2 => 6, 3 => 6]);
// tisk E: 100 PN, 3 s AI 6 → 3 % PN nestačí na pn_many; 3 podobně závažné PN ale plné body AI dají
$fixtureBill(9802, 100, [1 => 6, 2 => 6, 3 => 6]);
// tisk E2: 100 PN, 10 s AI 6 → absolutní počet pn_many_abs stačí i bez podílu
$fixtureBill(9803, 100, array_fill(1, 10, 6));
// jediný PN s AI 6 mezi 20 → polovina bodů
$fixtureBill(9804, 20, [1 => 6]);
// jediný PN s AI 6 mezi 3 → tvoří třetinu PN, plné body
$fixtureBill(9805, 3, [1 => 6]);
// jediný PN s neověřenou AI 9 (počítá se jako 5/10) mezi 20 → 15 b., polovina = 8
$fixtureBill(9806, 20, [1 => 9], [1 => ['ai_model' => 'claude-haiku-5-5']]);
// nejhorší 9/10 a druhý 6/10 mezi 20 → oba dosáhnou prahu 6, plné body za 9
$fixtureBill(9807, 20, [1 => 9, 2 => 6]);
// dva PN 5/10 mezi 20 → práh min(6, 5) = 5, dva PN, plné body
$fixtureBill(9808, 20, [1 => 5, 2 => 5]);
$rescore();
$d = $reasonsOf(9801);
check('tisk D (3 z 4 PN s AI 6): pn_many', [isset($d['pn_many']), str_contains($d['pn_many']['label'] ?? '', '3 z 4')], [true, true]);
check('tisk D: plné body AI 18 a důvod „3 z 4 PN“', [$d['ai']['points'] ?? null, str_contains($d['ai']['label'] ?? '', '3 z 4 PN se závažností ≥ 6')], [18, true]);
$e = $reasonsOf(9802);
check('tisk E (3 ze 100 PN): bez pn_many', isset($e['pn_many']), false);
check('tisk E: tři PN s AI 6 = plné body AI', $e['ai']['points'] ?? null, 18);
check('tisk E2 (10 ze 100 PN): pn_many podle absolutního počtu', isset($reasonsOf(9803)['pn_many']), true);
$single = $reasonsOf(9804)['ai'] ?? [];
check('jediný PN s AI 6 mezi 20: polovina bodů', [$single['points'] ?? null, str_contains($single['label'] ?? '', '1 z 20 PN') && str_contains($single['label'] ?? '', '50 % bodů')], [9, true]);
check('jediný PN s AI 6 mezi 3: plné body', $reasonsOf(9805)['ai']['points'] ?? null, 18);
check('neověřený nález 9/10 mezi 20 PN: 5/10 a polovina', $reasonsOf(9806)['ai']['points'] ?? null, 8);
check('nejhorší 9/10 a další 6/10 mezi 20 PN: plné body', $reasonsOf(9807)['ai']['points'] ?? null, 27);
check('dva PN 5/10 mezi 20: plné body', $reasonsOf(9808)['ai']['points'] ?? null, 15);
check('zkratka důvodů vynechá důvod s 0 body',
    Hlidac\View::reasonShortLabels([['key' => 'retro_deadline', 'points' => 0, 'label' => 'x'], ['key' => 'ai', 'points' => 18, 'label' => 'y'], ['key' => 'topic', 'points' => 5, 'label' => 'z']], 2),
    ['sporné pozměňovací návrhy', 'citlivá oblast']);
foreach (range(9801, 9808) as $fixtureCt) {
    $dropBill($fixtureCt);
}
$rescore();

echo "Hlasování k tiskům (#5, #24)\n";
$votesSource = new Hlidac\Source('', $tmp . '/cache-votes', $tmp . '/src');
(new Hlidac\Importer(db(), $votesSource))->run();
$vr = (new Hlidac\Votes(db(), $votesSource))->run();
check('nespárované hlasování se uloží bez tisku (ct 0)', [$vr['nesparovano'] ?? null,
    (int)db()->value('SELECT ct FROM hlasovani WHERE id_hlasovani = 87152')], [1, 0]);
check('hlasy poslanců: řádek za poslance a hlasování (uložil už běh synchronizace)', [is_int($vr['hlasy_poslancu'] ?? null),
    (int)db()->value('SELECT COUNT(*) FROM hlasovani_poslanci')], [true, 15]);
$mpVotes = [];
foreach (db()->all('SELECT p.id_osoba, p.klub, p.hlas FROM hlasovani_poslanci p WHERE p.id_hlasovani = 86762 ORDER BY p.klub') as $c) {
    $mpVotes[] = [$c['klub'], $c['hlas']];
}
check('hlasy poslanců: klub ke dni hlasování a kód hlasu', $mpVotes, [['ANO2011', 'A'], ['ODS', '@'], ['Piráti', 'K']]);
// opakovaný běh hlasy nezdvojí, hlasy k hlasování, které ze zdroje zmizelo, se smažou
db()->run('INSERT INTO hlasovani_poslanci (id_hlasovani, id_osoba, klub, hlas) VALUES (1, 1, NULL, ?)', ['A']);
$vr2 = (new Hlidac\Votes(db(), $votesSource))->run();
check('hlasy poslanců: opakovaný běh bez duplicit a úklid', [$vr2['hlasy_poslancu'], (int)db()->value('SELECT COUNT(*) FROM hlasovani_poslanci'),
    db()->value('SELECT 1 FROM hlasovani_poslanci WHERE id_hlasovani = 1')], [0, 15, null]);
check('hlasování k tisku 78 (bez hlasování k jinému tisku se stejným bodem)', [$vr['votes'],
    array_map('intval', array_column(db()->all('SELECT id_hlasovani FROM hlasovani WHERE ct = 78 ORDER BY id_hlasovani'), 'id_hlasovani'))],
    [4, [86762, 86763, 87173, 87174]]);
check('závěrečná hlasování z historie tisku', array_map('intval', array_column(db()->all('SELECT id_hlasovani FROM hlasovani WHERE krok = 1 ORDER BY id_hlasovani'), 'id_hlasovani')), [86763, 87174]);
check('výsledek a počty', db()->one('SELECT datum, vysledek, pro, proti FROM hlasovani WHERE id_hlasovani = 87173'),
    ['datum' => '2026-04-15 15:58', 'vysledek' => 'R', 'pro' => 54, 'proti' => 103]);
$byClub = [];
foreach (db()->all('SELECT klub, pro, proti, zdrzel, nepritomen FROM hlasovani_kluby WHERE id_hlasovani = 86762 ORDER BY klub') as $c) {
    $byClub[$c['klub']] = [(int)$c['pro'], (int)$c['proti'], (int)$c['zdrzel'], (int)$c['nepritomen']];
}
check('rozdělení podle klubů', $byClub, ['ANO2011' => [1, 0, 0, 0], 'ODS' => [0, 0, 0, 1], 'Piráti' => [0, 0, 1, 0]]);
check('normalizace názvu hlasování', Hlidac\Votes::normName('Novela z. o státních svátcích**'), 'novela z. o státních svátcích');
$_GET = ['ct' => '78'];
ob_start();
(function (string $file): void {
    require $file;
})(APP_ROOT . '/tisk.php');
$voteHtml = (string)ob_get_clean();
check('detail tisku ukazuje hlasování s klubem a odkazem na psp.cz', str_contains($voteHtml, 'Hlasování ve Sněmovně')
    && str_contains($voteHtml, 'hlasy.sqw?g=87174') && str_contains($voteHtml, 'ANO2011'), true);

echo "JSON API a filtry RSS (#28)\n";
$api = new Hlidac\Api(db(), new Hlidac\Repo(db()), config());
[$st, $body] = $api->handle(['tisk' => '78']);
check('api.php?tisk=78: detail s důvody, hlasováním a odkazy', [$st, $body['tisk']['ct'] ?? null, isset($body['tisk']['duvody'], $body['tisk']['upozorneni']),
    count($body['tisk']['hlasovani'] ?? []), str_contains((string)($body['tisk']['psp_url'] ?? ''), 'psp.cz')], [200, 78, true, 4, true]);
check('api.php?tisk=…: neznámý tisk 404', $api->handle(['tisk' => '99999'])[0], 404);
check('api.php?since=špatně: 400', $api->handle(['since' => '1. 11. 2026'])[0], 400);
check('api.php?zakon=špatně: 400', $api->handle(['zakon' => 'střet'])[0], 400);
[, $list] = $api->handle(['zakon' => '586/1992']);
check('filtr podle zákona', array_column($list['tisky'], 'ct'), [311]);
[, $future] = $api->handle(['since' => '2999-01-01']);
check('filtr since', $future['tisky'], []);
[, $al] = $api->handle(['upozorneni' => '1', 'min_score' => '0', 'ct' => '78', 'limit' => '2']);
check('upozornění k tisku s limitem', [count($al['upozorneni']), array_unique(array_column($al['upozorneni'], 'ct'))], [2, [78]]);
$_GET = ['zakon' => '586/1992', 'min_score' => '0'];
ob_start();
(function (string $file): void {
    require $file;
})(APP_ROOT . '/rss.php');
$rssXml = (string)ob_get_clean();
$rssItems = substr_count($rssXml, '<item>');
check('RSS filtrované podle zákona má jen položky tisku 311', $rssItems > 0 && !str_contains($rssXml, 'tisk.php?ct=78<') && !str_contains($rssXml, 'tisk.php?ct=221<'), true);
$_GET = [];


echo "Historie verzí textů a rozborů (#2 bod 2)\n";
// počty verzí přibylé v této části (dřívější části testu už rozbory tisku 221 ukládaly)
$revTotal = fn(string $table, string $kind, int $id): int => (int)db()->value("SELECT COUNT(*) FROM $table WHERE druh = ? AND obdobi = 10 AND ref_id = ?", [$kind, $id]);
$revBase = [];
$revCount = function (string $table, string $kind, int $id) use ($revTotal, &$revBase): int {
    $revBase[$table . $kind . $id] ??= $revTotal($table, $kind, $id);
    return $revTotal($table, $kind, $id) - $revBase[$table . $kind . $id];
};
foreach ([['text_revize', 'tisk', 221], ['ai_revize', 'tisk', 221], ['text_revize', 'tisk', 311], ['ai_revize', 'tisk', 311]] as [$tb, $kd, $rid]) {
    $revCount($tb, $kd, $rid);
}
$alertsQueued = fn(): int => (int)db()->value('SELECT COUNT(*) FROM alerts WHERE posted = 0');
$queuedBefore = $alertsQueued();
$revBackup = db()->one('SELECT * FROM tisk_text WHERE ct = 221');
db()->run('DELETE FROM tisk_text WHERE ct = 221');
$textV1 = "ČÁST PRVNÍ\nV zákoně č. 159/2006 Sb. se § 1 mění.\nŘízení o odvodu lze zahájit nejpozději do 10 let od porušení rozpočtové kázně.\nČÁST DRUHÁ\nÚčinnost <b>1. 1. 2027</b>";
$textV2 = "ČÁST PRVNÍ\nV zákoně č. 159/2006 Sb. se § 1 mění.\nŘízení o odvodu lze zahájit nejpozději do 4 let od porušení rozpočtové kázně.\nUstanovení se použije i tehdy, pokud byla neoprávněná platba dotace poskytnuta přede dnem nabytí účinnosti tohoto zákona.\nČÁST DRUHÁ\nÚčinnost <b>1. 1. 2027</b>";
$tiskSaver = new Hlidac\TiskText(db(), config());
$tiskSaver->save(221, 'https://www.psp.cz/sqw/text/orig2.sqw?idd=1001', $textV1, null);
check('první stažení = 1 verze textu', $revCount('text_revize', 'tisk', 221), 1);
$tiskSaver->save(221, 'https://www.psp.cz/sqw/text/orig2.sqw?idd=1002', $textV2, null);
check('změněný text = 2 verze', $revCount('text_revize', 'tisk', 221), 2);
$tiskSaver->save(221, 'https://www.psp.cz/sqw/text/orig2.sqw?idd=1002', $textV2, null);
check('stejný text znovu = pořád 2 verze', $revCount('text_revize', 'tisk', 221), 2);
check('verze drží odkaz na dokument (idd)', db()->value("SELECT docx_url FROM text_revize WHERE druh = 'tisk' AND obdobi = 10 AND ref_id = 221 AND verze = 2"), 'https://www.psp.cz/sqw/text/orig2.sqw?idd=1002');

$revReviewer = new Hlidac\AiReviewer(db(), config());
$haiku = Hlidac\AiReviewer::model(config());
$sonnet = Hlidac\AiReviewer::verifyModel(config());
$quote221 = 'Řízení o odvodu lze zahájit nejpozději do 4 let od porušení rozpočtové kázně.';
$result1 = ['titulek' => 'Lhůta pro vymáhání se zkracuje na 4 roky', 'zavaznost' => 7, 'zpetnost_smer' => 'oslabuje_vymahani_nebo_kontrolu', 'citace' => [$quote221]];
$revReviewer->saveResult('tisk', 221, $result1, $haiku);
check('rozbor = 1 verze rozboru', $revCount('ai_revize', 'tisk', 221), 1);
$revReviewer->saveResult('tisk', 221, $result1, $haiku);
check('stejný rozbor znovu = pořád 1', $revCount('ai_revize', 'tisk', 221), 1);
$revReviewer->saveResult('tisk', 221, ['titulek' => 'Lhůta pro vymáhání dotací se zkracuje na 4 roky – i zpětně', 'zavaznost' => 8] + $result1, $sonnet);
check('2. kolo = 2 verze rozboru', $revCount('ai_revize', 'tisk', 221), 2);
$aiRevs = array_slice(Hlidac\Revisions::aiVersions(db(), 'tisk', 221, 10), -2);
check('verze rozboru: model, závažnost, prompt', [$aiRevs[0]['ai_model'], (int)$aiRevs[0]['ai_score'], $aiRevs[1]['ai_model'], (int)$aiRevs[1]['ai_score'], (int)$aiRevs[1]['ai_prompt']],
    [$haiku, 7, $sonnet, 8, Hlidac\AiReviewer::PROMPT_VERSION]);
check('rozbor ví, ke které verzi textu patří', $aiRevs[1]['text_hash'], sha1((string)db()->value('SELECT text FROM tisk_text WHERE ct = 221')));
$revReviewer->saveResult('tisk', 221, $result1, $haiku);
check('opožděné 1. kolo po ověření historii nemění', $revCount('ai_revize', 'tisk', 221), 2);
$tiskSaver->save(221, 'https://www.psp.cz/sqw/text/orig2.sqw?idd=1003', $textV2 . "\nNový odstavec.", null);
check('nové znění: 3 verze textu, rozbory zůstanou v historii', [$revCount('text_revize', 'tisk', 221), $revCount('ai_revize', 'tisk', 221), db()->value('SELECT ai_at FROM tisk_text WHERE ct = 221')], [3, 2, null]);

// data z doby před zavedením historie: před přepsáním se zapíšou (i uschované 1. kolo)
$revPn = (int)db()->value('SELECT id_dokument FROM pn WHERE ct = 221 ORDER BY id_dokument LIMIT 1');
db()->run('DELETE FROM pn_text WHERE id_dokument = ?', [$revPn]);
db()->run("INSERT INTO pn_text (id_dokument, ct, cislo, docx_url, text, chars, laws, signals, rule_score, ai_json, ai_score, ai_model, ai_prompt, ai_at, ai_prev_json, ai_prev_score, ai_prev_model, fetched_at)
    VALUES (?, 221, 1, NULL, 'Starý text PN.', 14, '', '{}', 0, ?, 6, ?, 2, ?, ?, 8, ?, ?)",
    [$revPn, json_encode(['titulek' => 'Ověřeno', 'zavaznost' => 6]), $sonnet, date('Y-m-d H:i:s'), json_encode(['titulek' => 'První kolo', 'zavaznost' => 8]), $haiku, date('Y-m-d H:i:s')]);
(new Hlidac\PnText(db(), config()))->save(['id_dokument' => $revPn, 'ct' => 221, 'cislo' => 1], null, 'Nový text PN.', ['chars' => 13, 'laws' => [], 'signals' => [], 'score' => 0], null);
check('PN: starý i nový text v historii', $revTotal('text_revize', 'pn', $revPn), 2);
check('PN: 1. i 2. kolo starého rozboru v historii', array_map(fn($r) => (int)$r['ai_score'], Hlidac\Revisions::aiVersions(db(), 'pn', $revPn, 10)), [8, 6]);

// jednorázové doplnění historie: idempotentní, bez nových upozornění
db()->run('DELETE FROM tisk_text WHERE ct = 311');
db()->run("INSERT INTO tisk_text (ct, docx_url, text, chars, signals, fetched_at, ai_json, ai_score, ai_model, ai_prompt, ai_at) VALUES (311, NULL, 'Text tisku 311.', 15, '{}', ?, ?, 3, ?, 2, ?)",
    [date('Y-m-d H:i:s'), json_encode(['titulek' => 'Rozbor 311', 'zavaznost' => 3]), $haiku, date('Y-m-d H:i:s')]);
db()->setMeta('revize_backfill', '');
$historyTotal = fn(): int => (int)db()->value('SELECT COUNT(*) FROM text_revize') + (int)db()->value('SELECT COUNT(*) FROM ai_revize');
$filled = Hlidac\Revisions::backfill(db());
check('doplnění historie zapíše chybějící text i rozbor', [$filled >= 2, $revCount('text_revize', 'tisk', 311), $revCount('ai_revize', 'tisk', 311)], [true, 1, 1]);
$totalAfter = $historyTotal();
check('druhé doplnění nic nepřidá', [Hlidac\Revisions::backfill(db()), $historyTotal()], [0, $totalAfter]);
db()->setMeta('revize_backfill', '');
check('ani po smazání značky nic nepřidá', [Hlidac\Revisions::backfill(db()), $historyTotal()], [0, $totalAfter]);
check('historie nevyrobí upozornění k odeslání', $alertsQueued(), $queuedBefore);
check('pracovní data se doplněním nezměnila', (int)db()->value('SELECT ai_score FROM tisk_text WHERE ct = 311'), 3);

// rozdíl textů
check('rozdíl po řádcích', Hlidac\Diff::ops(['a', 'b', 'c'], ['a', 'x', 'c']), [['=', 'a'], ['-', 'b'], ['+', 'x'], ['=', 'c']]);
check('rozdíl: vložení i smazání', Hlidac\Diff::ops(['a', 'b'], ['b', 'c']), [['-', 'a'], ['=', 'b'], ['+', 'c']]);
$d = Hlidac\Diff::render($textV1, $textV2);
check('rozdíl verzí: změněný a přidaný odstavec', [$d['added'], $d['removed']], [2, 1]);
$dHtml = implode("\n", array_column($d['blocks'], 'html'));
check('rozdíl po slovech', str_contains($dHtml, '<del>10</del>') && str_contains($dHtml, '<ins>4</ins>'), true);
$escHtml = implode('', array_column(Hlidac\Diff::render("x\n<b>a</b>", "x\n<b>c</b>")['blocks'], 'html'));
check('rozdíl je escapovaný', str_contains($escHtml, '&lt;b&gt;') && !str_contains($escHtml, '<b>'), true);
$long = array_map(fn($i) => 'Odstavec číslo ' . $i . ' s nějakým textem.', range(1, 4000));
$long2 = $long;
foreach ([5, 900, 1800, 2500, 3999] as $i) {
    $long2[$i] = 'Změněný odstavec ' . $i . '.';
}
$started = microtime(true);
$dl = Hlidac\Diff::render(implode("\n", $long), implode("\n", $long2));
check('dlouhý text: rozdíl rychle a přesně', [$dl['added'], $dl['removed'], microtime(true) - $started < 5], [5, 5, true]);
$dx = Hlidac\Diff::ops(range(1, 3000), array_reverse(range(1, 3000)), 50);
check('příliš mnoho změn: blok místo přesného rozdílu', count(array_filter($dx, fn($o) => $o[0] === '+')), 3000);

$_GET = ['ct' => '221'];
ob_start();
(function (string $file): void {
    require $file;
})(APP_ROOT . '/tisk.php');
$revHtml = (string)ob_get_clean();
check('detail tisku odkazuje na historii textu', str_contains($revHtml, 'revize.php?druh=tisk&amp;id=221') && str_contains($revHtml, 'Historie (' . Hlidac\Revisions::versionsLabel(3 + $revTotal('ai_revize', 'tisk', 221)) . ')'), true);
check('detail tisku odkazuje na historii PN', str_contains($revHtml, 'revize.php?druh=pn&amp;id=' . $revPn), true);
$_GET = ['druh' => 'tisk', 'id' => '221', 'a' => '1', 'b' => '2'];
ob_start();
(function (string $file): void {
    require $file;
})(APP_ROOT . '/revize.php');
$revPage = (string)ob_get_clean();
check('stránka historie: verze, rozdíl a rozbory', [str_contains($revPage, 'Verze textu'), str_contains($revPage, '<del>10</del>'), str_contains($revPage, 'idd 1002'),
    str_contains($revPage, 'předtím 7/10'), str_contains($revPage, 'nový titulek')], [true, true, true, true, true]);
check('stránka historie escapuje text', str_contains($revPage, '&lt;b&gt;1. 1. 2027&lt;/b&gt;') && !str_contains($revPage, '<b>1. 1. 2027</b>'), true);
// úklid: tisk 221 zpět do stavu před testem historie (historie zůstává)
db()->run('DELETE FROM tisk_text WHERE ct = 221');
if ($revBackup !== null) {
    db()->insertMany('tisk_text', array_keys(array_filter($revBackup, 'is_string', ARRAY_FILTER_USE_KEY)), [$revBackup]);
}
db()->run('DELETE FROM pn_text WHERE id_dokument = ?', [$revPn]);
$rescore();

echo "Hlavní nálezy: ověřený nález AI pod prahem skóre\n";
$ctStrong = (int)db()->value("SELECT ct FROM tisky WHERE faze <> 'Vyhlášeno' AND score < 50 ORDER BY ct LIMIT 1");
$bak = db()->one('SELECT * FROM tisk_text WHERE ct = ?', [$ctStrong]);
db()->run('DELETE FROM tisk_text WHERE ct = ?', [$ctStrong]);
db()->run("INSERT INTO tisk_text (ct, text, chars, signals, ai_json, ai_score, ai_model, ai_at) VALUES (?, 'x', 1, '{}', ?, 9, 'claude-haiku-5-5', ?)",
    [$ctStrong, json_encode(['titulek' => 'Silný nález', 'citace' => ['doslovná věta']]), date('Y-m-d H:i:s')]);
$inTop = fn(): ?array => array_values(array_filter((new Hlidac\Repo(db()))->topFindings(50, 60), fn($t) => (int)$t['ct'] === $ctStrong))[0] ?? null;
check('neověřený nález 9/10 pod prahem do hlavních nálezů nejde', $inTop(), null);
db()->run('UPDATE tisk_text SET ai_model = ? WHERE ct = ?', [Hlidac\AiReviewer::verifyModel(config()), $ctStrong]);
check('ověřený nález 9/10 s citací jde do hlavních nálezů i pod prahem', $inTop()['strong_ai'] ?? null, 9);
db()->run('UPDATE tisk_text SET ai_json = ? WHERE ct = ?', [json_encode(['titulek' => 'Silný nález', 'citace' => []]), $ctStrong]);
check('bez citace ne', $inTop(), null);
db()->run('DELETE FROM tisk_text WHERE ct = ?', [$ctStrong]);
if ($bak !== null) {
    db()->insertMany('tisk_text', array_keys(array_filter($bak, 'is_string', ARRAY_FILTER_USE_KEY)), [$bak]);
}

echo "Souhrn pozměňovacích návrhů (tisk X/n) a odstup od jeho rozeslání\n";
(new Hlidac\Importer(db(), new Hlidac\Source('', $tmp . '/cache-souhrn', $tmp . '/src')))->run();
check('souhrn PN z tisky_za (druh 10)', db()->one('SELECT souhrn_pn, souhrn_pn_at FROM tisky WHERE ct = 311'), ['souhrn_pn' => 3, 'souhrn_pn_at' => '2026-11-05 10:24']);
check('tisk bez souhrnu', db()->value('SELECT souhrn_pn FROM tisky WHERE ct = 221'), null);
db()->run('DELETE FROM kroky WHERE ct = 311 AND typ_stavu IN (5, 21)');
db()->run("INSERT INTO kroky (id_hist, id_tisk, ct, datum, typ_stavu, stav, akce_id, akce) VALUES (990201, 43433, 311, '2026-11-01 00:00:00', 21, '', 23, ''), (990202, 43433, 311, '2026-11-08 00:00:00', 5, '', 71, '')");
(new Hlidac\Scorer(db(), config()))->scoreBills();
$gapLabel = fn(int $ct) => array_values(array_filter(json_decode((string)db()->value('SELECT reasons FROM tisky WHERE ct = ?', [$ct]), true) ?: [], fn($r) => $r['key'] === 'short_gap'))[0]['label'] ?? null;
check('odstup od rozeslání souhrnu (5. 11. → 8. 11.)', $gapLabel(311), 'Mezi rozesláním souhrnu pozměňovacích návrhů a 3. čtením jen 3 dny');
db()->run("UPDATE tisky SET souhrn_pn = NULL, souhrn_pn_at = NULL WHERE ct = 311");
(new Hlidac\Scorer(db(), config()))->scoreBills();
check('bez souhrnu od kroku PN a s poznámkou (1. 11. → 8. 11. = 7 dní, signál ne)', $gapLabel(311), null);
db()->run("UPDATE kroky SET datum = '2026-11-08 00:00:00' WHERE id_hist = 990201");
(new Hlidac\Scorer(db(), config()))->scoreBills();
check('bez souhrnu ve stejný den', $gapLabel(311), 'Pozměňovací návrhy a 3. čtení ve stejný den (souhrn pozměňovacích návrhů nebyl rozeslán jako sněmovní tisk)');
db()->run('DELETE FROM kroky WHERE id_hist IN (990201, 990202)');

echo "Příprava návrhu u vlády (otevřená data ODok)\n";
$prepSource = new Hlidac\Source('', $tmp . '/cache-prep', $tmp . '/src');
(new Hlidac\Importer(db(), $prepSource))->run();
$pr = (new Hlidac\GovPrep(db(), $prepSource, config()))->run();
$prepOf = fn(int $ct) => db()->one('SELECT druh, pid, pripominky_od, pripominky_do, dni, mist, vlada, mimo_plan FROM priprava WHERE ct = ?', [$ct]);
check('vládní návrh: připomínky z verze se stejným názvem (bez „Vládní“)', $prepOf(90),
    ['druh' => 'vladni', 'pid' => 'KORNDQGFVQKI', 'pripominky_od' => '2025-12-01', 'pripominky_do' => '2025-12-15', 'dni' => 14, 'mist' => 23, 'vlada' => '2026-01-26', 'mimo_plan' => 0]);
check('vládní návrh bez nalezených připomínek, mimo plán', [$prepOf(311)['dni'], $prepOf(311)['mimo_plan']], [null, 1]);
check('poslanecký návrh: připomínky ke stanovisku podle čísla tisku', [$prepOf(221)['druh'], $prepOf(221)['dni'], $prepOf(221)['mist']], ['stanovisko', 8, 7]);
check('poslanecký návrh bez připomínek', [$prepOf(78)['druh'], $prepOf(78)['dni']], ['stanovisko', null]);
check('materiál z minulého období se stejným číslem tisku se nepoužije', $prepOf(90)['druh'], 'vladni');
(new Hlidac\Scorer(db(), config()))->scoreBills();
$mp = array_values(array_filter(json_decode((string)db()->value('SELECT reasons FROM tisky WHERE ct = 221'), true) ?: [], fn($r) => $r['key'] === 'mp_bill'))[0]['label'] ?? '';
check('popis bodů za poslanecký návrh uvádí připomínky ke stanovisku', str_contains($mp, 'vláda si ke stanovisku vyžádala připomínky 7 míst na 8 dní') && !str_contains($mp, 'neprošel'), true);
$_GET = ['ct' => '90'];
ob_start();
(function (string $file): void {
    require $file;
})(APP_ROOT . '/tisk.php');
$prepHtml = (string)ob_get_clean();
check('detail tisku ukazuje přípravu u vlády', str_contains($prepHtml, 'Příprava návrhu u vlády') && str_contains($prepHtml, 'Připomínky 14 dní (obvykle 4 týdny)')
    && str_contains($prepHtml, 'odok.gov.cz/portal/veklep/material/KORNDQGFVQKI/'), true);

echo "Senát a kroky po něm (#6)\n";
$k = fn(int $typ, int $akce, string $date): array => ['typ_stavu' => $typ, 'akce_id' => $akce, 'datum' => $date . ' 00:00:00'];
$sen = fn(array $kroky): array => Hlidac\Analyzer::senate($kroky);
check('v Senátu: lhůta 30 dní od doručení', array_intersect_key($sen([$k(64, 22, '2026-11-02'), $k(12, 3, '2026-11-05')]), array_flip(['doruceno', 'vysledek', 'lhuta_do'])),
    ['doruceno' => '2026-11-05', 'vysledek' => null, 'lhuta_do' => '2026-12-05']);
check('Senát schválil', $sen([$k(12, 3, '2026-03-01'), $k(8, 21, '2026-03-20'), $k(8, 14, '2026-03-21')])['vysledek'], 'schvalen');
$s163 = $sen([$k(64, 22, '2026-04-15'), $k(12, 3, '2026-04-15'), $k(14, 27, '2026-04-22'), $k(14, 19, '2026-04-22'), $k(8, 20, '2026-04-24'), $k(8, 14, '2026-04-24')]);
check('Senát zamítl, Sněmovna přehlasovala (tisk 163)', [$s163['vysledek'], $s163['rozhodnuto'], $s163['snemovna'], $s163['snemovna_datum'], $s163['lhuta_do']],
    ['zamitnut', '2026-04-22', 'setrvala', '2026-04-24', null]);
check('popis přehlasování s odstupem', Hlidac\Analyzer::senateLines($s163),
    ['Senát návrh zamítl (22. 4. 2026).', 'Sněmovna setrvala na původním znění a Senát přehlasovala (24. 4. 2026, 2 dny poté).']);
check('Sněmovna přijala znění Senátu (tisk 57)', array_values(array_intersect_key($sen([$k(12, 3, '2026-04-07'), $k(14, 24, '2026-05-07'), $k(14, 19, '2026-05-07'), $k(8, 15, '2026-05-26')]),
    array_flip(['vysledek', 'snemovna']))), ['vracen', 'prijala']);
check('nezabýval se, nevyjádřil se', [$sen([$k(12, 3, '2026-07-21'), $k(8, 11, '2026-08-24')])['vysledek'], $sen([$k(12, 3, '2026-09-29'), $k(8, 6, '2026-10-22')])['vysledek']], ['nezabyval', 'necinny']);
check('návrh, který do Senátu nedošel', [$sen([$k(1, 0, '2026-01-01'), $k(5, 0, '2026-02-01')])['doruceno'], Hlidac\Analyzer::senateLines($sen([]))], [null, []]);
$s90 = $sen((new Hlidac\Repo(db()))->steps(90));
check('tisk 90: vrácen, Sněmovna setrvala, veto, znovu setrvala', [$s90['doruceno'], $s90['vysledek'], $s90['snemovna'], $s90['veto'], $s90['veto_prehlasovano']],
    ['2026-05-26', 'vracen', 'setrvala', '2026-07-22', '2026-08-26']);
$senAlerts = [];
foreach (db()->all("SELECT alert_key, kind, score, posted, title FROM alerts WHERE kind IN ('senate', 'override') AND ct = 90 AND alert_key NOT LIKE '%/%' ORDER BY alert_key") as $a) {
    $senAlerts[$a['alert_key']] = [$a['kind'], (int)$a['posted'], $a['title']];
}
check('upozornění na Senát a přehlasování (první běh se neposílá)', $senAlerts, [
    'override:90' => ['override', 2, 'Sněmovna přehlasovala Senát: ' . db()->value('SELECT nazev FROM tisky WHERE ct = 90')],
    'senate:90' => ['senate', 2, 'Senát vrátil s pozměňovacími návrhy: ' . db()->value('SELECT nazev FROM tisky WHERE ct = 90')],
    'vetoover:90' => ['override', 2, 'Sněmovna přehlasovala veto prezidenta: ' . db()->value('SELECT nazev FROM tisky WHERE ct = 90')],
]);
(new Hlidac\Scorer(db(), config()))->scoreAlerts();
check('upozornění na Senát nepřidává body (skóre = skóre tisku)', (int)db()->value("SELECT COUNT(*) FROM alerts a JOIN tisky t ON t.ct = a.ct
    WHERE a.kind IN ('senate', 'override') AND a.alert_key NOT LIKE '%/%' AND a.score <> t.score"), 0);
$api90 = (new Hlidac\Api(db(), new Hlidac\Repo(db()), config()))->bill(90);
$api221 = (new Hlidac\Api(db(), new Hlidac\Repo(db()), config()))->bill(221);
check('API: průchod Senátem u tisku 90, null u tisku mimo Senát', [$api90['senat']['vysledek'] ?? null, count($api90['senat']['popis'] ?? []), $api221['senat']], ['vracen', 4, null]);
check('detail tisku 90 ukazuje Senát', str_contains($prepHtml, 'Senát a kroky po něm') && str_contains($prepHtml, 'Senát návrh vrátil Sněmovně s pozměňovacími návrhy (22. 6. 2026).')
    && str_contains($prepHtml, 'veto prezidenta přehlasovala (26. 8. 2026)'), true);

echo "Připravované zákony (#29)\n";
$up = [];
foreach ((new Hlidac\Repo(db()))->upcoming() as $u) {
    $up[$u['pid']] = $u;
}
check('jen návrhy zákonů tohoto období mimo Sněmovnu, od nejpokročilejší fáze', array_keys($up), ['KORNUP000003', 'KORNUP000004', 'KORNUP000001']);
check('verze téhož materiálu: fáze z nejnovější, připomínky ze starší', [$up['KORNUP000003']['faze'], $up['KORNUP000003']['dni'], $up['KORNUP000003']['mist'],
    $up['KORNUP000003']['vlada'], $up['KORNUP000003']['mimo_plan'], $up['KORNUP000003']['verzi'], $up['KORNUP000003']['zalozeno']],
    ['schvaleno', 7, 22, '2026-11-04', 1, 2, '2026-09-29']);
check('u vlády bez připomínek', [$up['KORNUP000004']['faze'], $up['KORNUP000004']['dni']], ['vlada', null]);
check('v připomínkovém řízení', [$up['KORNUP000001']['faze'], $up['KORNUP000001']['dni'], $up['KORNUP000001']['resort']], ['pripominky', 29, 'Ministerstvo X']);
check('vládní návrhy už ve Sněmovně (tisky 90, 311) mezi připravovanými nejsou', array_intersect(array_keys($up), ['KORNDQGFVQKI', 'KORNDY5H6KU4', 'KORNDQAAAAAA']), []);
check('fáze ze stavu ODok', array_map([Hlidac\GovPrep::class, 'phaseOf'], ['2 - v připomínkovém řízení', '3 - připomínkové řízení ukončeno', '7 - zařazeno do evidence', 'B - signováno', 'CE – zaevidováno v PSP', 'DZV - návrh vzat zpět', 'K - publikováno ve Sbírce zákonů']),
    ['pripominky', 'po_pripominkach', 'vlada', 'schvaleno', null, null, null]);
$_GET = [];
ob_start();
(function (string $file): void {
    require $file;
})(APP_ROOT . '/pripravovane.php');
$upHtml = (string)ob_get_clean();
[$apiCode, $apiBody] = (new Hlidac\Api(db(), new Hlidac\Repo(db()), config()))->handle(['pripravovane' => '1']);

echo "Připravovaná nařízení vlády (#59)\n";
$nvRows = [];
foreach ((new Hlidac\Repo(db()))->upcoming('narizeni') as $u) {
    $nvRows[$u['pid']] = $u;
}
check('nařízení v přípravě a čerstvě schválené, dávno schválené už ne', array_keys($nvRows), ['KORNNV000005', 'KORNNV000003', 'KORNNV000001']);
check('krátké připomínky; název resortu podle zkratky z jiných záznamů', [$nvRows['KORNNV000001']['dni'] ?? null, $nvRows['KORNNV000001']['mist'] ?? null,
    $nvRows['KORNNV000001']['faze'] ?? null, $nvRows['KORNNV000001']['resort'] ?? null], [7, 73, 'po_pripominkach', 'Ministerstvo X']);
check('každoroční nařízení se stejným názvem se s loňským nespojí', [$nvRows['KORNNV000003']['verzi'] ?? null, $nvRows['KORNNV000003']['dni'] ?? null,
    $nvRows['KORNNV000003']['faze'] ?? null, $nvRows['KORNNV000003']['pidy'] ?? null], [1, null, 'vlada', 'KORNNV000003']);
check('schválené nařízení: fáze a délka připomínek', [$nvRows['KORNNV000005']['faze'] ?? null, $nvRows['KORNNV000005']['dni'] ?? null], ['schvaleno', 22]);
check('nařízení nejsou mezi připravovanými zákony', array_intersect(array_keys($up), array_keys($nvRows)), []);
$_GET = ['druh' => 'narizeni'];
ob_start();
(function (string $file): void {
    require $file;
})(APP_ROOT . '/pripravovane.php');
$nvHtml = (string)ob_get_clean();
$_GET = [];
check('stránka nařízení vlády', [str_contains($nvHtml, '<h1>Připravovaná nařízení vlády</h1>'), str_contains($nvHtml, 'Připomínky 7 dní (obvykle 3 týdny)'),
    str_contains($nvHtml, 'id="prep-KORNNV000001"'), str_contains($nvHtml, 'KORNUP000001'), str_contains($upHtml, 'pripravovane.php?druh=narizeni')],
    [true, true, true, false, true]);
// hlavní menu: všechny stěžejní stránky, každá jednou, cíle existují a aktuální stránka je označená
preg_match('~<nav id="site-nav".*?</nav>~s', $nvHtml, $navM);
preg_match_all('~<a href="([^"]+)"( class="active" aria-current="page")?>([^<]+)</a>~', $navM[0] ?? '', $navLinks, PREG_SET_ORDER);
check('hlavní menu: stránky a pořadí', array_column($navLinks, 1),
    ['index.php', 'tisky.php', 'zakon.php', 'upozorneni.php', 'pripravovane.php', 'metodika.php', 'data.php', 'mcp.php', 'zmeny.php', 'rss.php']);
check('hlavní menu: cíle existují', array_values(array_filter(array_column($navLinks, 1), static fn(string $f): bool => !is_file(APP_ROOT . '/' . $f))), []);
check('hlavní menu: označená je jen aktuální stránka', array_column(array_values(array_filter($navLinks, static fn(array $l): bool => ($l[2] ?? '') !== '')), 1), ['pripravovane.php']);
[$nvCode, $nvBody] = (new Hlidac\Api(db(), new Hlidac\Repo(db()), config()))->handle(['pripravovane' => '1', 'druh' => 'narizeni']);
check('API: nařízení jen s druh=narizeni, výchozí zůstávají zákony', [$nvCode, array_column($nvBody['pripravovane'], 'druh'), array_unique(array_column($apiBody['pripravovane'], 'druh'))],
    [200, ['narizeni', 'narizeni', 'narizeni'], ['zakon']]);
check('api.php?pripravovane=1', [$apiCode, count($apiBody['pripravovane']), $apiBody['pripravovane'][0]['pripominky']['dni'] ?? null], [200, 3, 7]);
check('stránka připravovaných zákonů: fáze, štítky a odkaz do VeKLEP', str_contains($upHtml, 'Schváleno vládou') && str_contains($upHtml, 'Připomínky 7 dní (obvykle 4 týdny)')
    && str_contains($upHtml, 'Připomínkové řízení nedohledáno') && str_contains($upHtml, 'Mimo plán legislativních prací')
    && str_contains($upHtml, 'odok.gov.cz/portal/veklep/material/KORNUP000001/') && !str_contains($upHtml, 'ekonomické ochraně státu'), true);

echo "Řazení a filtr hlavních nálezů\n";
$fr = new Hlidac\Repo(db());
$base = $fr->findings(0);
$sets = array_map(fn(string $s) => array_column($fr->findings(0, $s)['rows'], 'ct'), array_keys(Hlidac\Repo::FINDING_SORTS));
check('každé řazení vrací stejné tisky', array_map(fn(array $c) => (sort($c) ? $c : $c), $sets) === array_fill(0, count($sets), (function () use ($sets) { $c = $sets[0]; sort($c); return $c; })()), true);
$aiRows = $fr->findings(0, 'ai')['rows'];
$aiVals = array_map(fn($t) => $t['ai_verified'] ?? -1, $aiRows);
$sortedAi = $aiVals;
rsort($sortedAi);
check('řazení podle AI: ověřená závažnost sestupně, bez nálezu na konci', $aiVals, $sortedAi);
check('fáze před hlasováním', array_map([Hlidac\Repo::class, 'votePhaseRank'], ['3. čtení', 'Sněmovna po vetu', '2. čtení', '1. čtení', 'V Senátu', 'Vyhlášeno']), [0, 0, 1, 2, 3, 3]);
$voteRanks = array_map(fn($t) => Hlidac\Repo::votePhaseRank((string)$t['faze']), $fr->findings(0, 'hlasovani')['rows']);
$sortedRanks = $voteRanks;
sort($sortedRanks);
check('řazení „blíží se hlasování“ podle fáze', $voteRanks, $sortedRanks);
check('druh problému z důvodů skóre, ověřené AI a krátkých připomínek',
    [$fr->problemGroups(['reasons' => json_encode([['key' => 'pn_other_law'], ['key' => 'p90_requested'], ['key' => 'surge']])]),
     $fr->problemGroups(['reasons' => '[]'], ['score' => 8, 'rider' => true, 'retro' => true], true),
     $fr->problemGroups(['reasons' => json_encode([['key' => 'late_pn']])])],
    [['prilepky', 'par90'], ['prilepky', 'pripominky', 'zpetne'], []]);
foreach (array_keys(Hlidac\Repo::PROBLEM_GROUPS) as $g) {
    $sub = $fr->findings(0, 'skore', $g);
    $ok = count($sub['rows']) === $base['counts'][$g] && array_filter($sub['rows'], fn($t) => !in_array($g, $t['groups'], true)) === [];
    if (!$ok) {
        break;
    }
}
check('filtr druhu: jen tisky toho druhu, počet sedí se štítkem', $ok, true);
$_GET = ['razeni' => 'ai', 'druh' => 'zpetne'];
ob_start();
(function (string $file): void {
    require $file;
})(APP_ROOT . '/index.php');
$idx = (string)ob_get_clean();
check('úvodní stránka s řazením a filtrem v adrese', str_contains($idx, 'Závažnost textu (AI)') && str_contains($idx, 'Co hledám')
    && (bool)preg_match('~class="pill active"[^>]*>Zpětné použití~', $idx) && (bool)preg_match('~class="pill active"[^>]*>Závažnost textu \(AI\)~', $idx), true);
$_GET = ['razeni' => 'cokoli', 'druh' => '<script>'];
ob_start();
(function (string $file): void {
    require $file;
})(APP_ROOT . '/index.php');
$idx = (string)ob_get_clean();
check('neznámé řazení a druh se ignorují', (bool)preg_match('~class="pill active"[^>]*>Celkové skóre~', $idx) && !str_contains($idx, '<script>&'), true);
$_GET = [];

echo "Vyhledávání\n";
$si = (new Hlidac\SearchIndex(db()))->build();
$kinds = array_unique(array_column($si['items'], 'k'));
sort($kinds);
check('index obsahuje tisky, zákony, PN, autory a témata', array_values(array_intersect(['osoba', 'pn', 'tema', 'tisk', 'zakon'], $kinds)), ['osoba', 'pn', 'tema', 'tisk', 'zakon']);
$first = fn(string $q) => (function ($r) { $k = array_key_first($r); return $k === null ? null : [$k, $r[$k][0]['id']]; })(Hlidac\SearchIndex::search($si, $q));
check('číslo tisku najde tisk jako první', [$first('221'), $first('tisk 221'), $first('sněmovní tisk 221')], [['tisk', '221'], ['tisk', '221'], ['tisk', '221']]);
check('číslo zákona najde zákon', $first('159/2006'), ['zakon', '159/2006']);
check('bez diakritiky a začátkem slova', $first('stret zaj')[1] ?? null, $first('Střet zájmů')[1] ?? null);
check('česká koncovka a překlep', [Hlidac\SearchIndex::stem('zbrane'), Hlidac\SearchIndex::stem('dotace'), Hlidac\SearchIndex::stem('zakon'),
    Hlidac\SearchIndex::nearlyEqual('dotcae', 'dotace'), Hlidac\SearchIndex::nearlyEqual('dotaxe', 'dotace'), Hlidac\SearchIndex::nearlyEqual('dotce', 'dotace'), Hlidac\SearchIndex::nearlyEqual('ditcae', 'dotace')],
    ['zbran', 'dotac', '', true, true, true, false]);
$finds221 = fn(string $q): bool => in_array('221', array_column(Hlidac\SearchIndex::search($si, $q)['tisk'] ?? [], 'id'), true);
check('jeden překlep: prohození, chybějící a přebývající písmeno, záměna ve slově s jinou koncovkou',
    [$finds221('stertu zajmu'), $finds221('stetu zajmu'), $finds221('strretu zajmu'), $finds221('stzete zajmu'), Hlidac\SearchIndex::search($si, 'xqzwvk')],
    [true, true, true, true, []]);
check('začátek slova s překlepem', [Hlidac\SearchIndex::fuzzyPrefix('rozpcet', 'rozpocet'), Hlidac\SearchIndex::fuzzyPrefix('rozpoocet', 'rozpocet'),
    Hlidac\SearchIndex::fuzzyPrefix('zbarn', 'zbranich'), Hlidac\SearchIndex::fuzzyPrefix('kocka', 'zakon')], [true, true, true, false]);
check('číslo se nehledá začátkem („22“ nenajde 221)', array_filter(Hlidac\SearchIndex::search($si, '22')['tisk'] ?? [], fn($t) => $t['id'] === '221'), []);
check('druh problému jako téma', $first('přílepky'), ['tema', 'prilepky']);
check('prázdný dotaz nic nevrací', Hlidac\SearchIndex::search($si, '  '), []);
$_GET = ['q' => 'střet zájmů'];
ob_start();
(function (string $file): void {
    require $file;
})(APP_ROOT . '/hledat.php');
$sh = (string)ob_get_clean();
check('stránka výsledků: skupiny a odkaz na tisk', str_contains($sh, 'výsledků pro „střet zájmů“') && str_contains($sh, 'href="tisk.php?ct=221"') && str_contains($sh, 'data-search'), true);
$_GET = ['q' => '<script>alert(1)</script>'];
ob_start();
(function (string $file): void {
    require $file;
})(APP_ROOT . '/hledat.php');
$sh = (string)ob_get_clean();
check('dotaz je v HTML escapovaný', !str_contains($sh, '<script>alert(1)') && str_contains($sh, '&lt;script&gt;'), true);
$_GET = [];

echo "Stránka zákona\n";
$lw = new Hlidac\Laws(db());
check('čísla zákonů v názvu', Hlidac\Laws::numbersIn('Návrh zákona, kterým se mění zákon č. 90/2024 Sb., o zbraních, a zákon č. 586/1992 Sb.'), ['90/2024', '586/1992']);
check('název zákona ze slovníku (bez „ve znění“)', $lw->name('159/2006'), 'o střetu zájmů');
check('tisky měnící zákon', array_map(fn($t) => (int)$t['ct'], $lw->bills('159/2006')), [221]);
check('platné znění v e-Sbírce', Hlidac\Laws::eSbirkaUrl('159/2006'), 'https://e-sbirka.gov.cz/sb/2006/159');
check('neplatné číslo zákona', [Hlidac\Laws::valid('159/2006'), Hlidac\Laws::valid('159'), Hlidac\Laws::valid('<b>')], [true, false, false]);
foreach ([['z' => '159/2006'], []] as $get) {
    $_GET = $get;
    ob_start();
    (function (string $file): void {
        require $file;
    })(APP_ROOT . '/zakon.php');
    $zh = (string)ob_get_clean();
    check($get === [] ? 'přehled zákonů' : 'stránka zákona 159/2006', $get === []
        ? str_contains($zh, 'href="zakon.php?z=159%2F2006"') && str_contains($zh, 'o střetu zájmů')
        : str_contains($zh, 'Zákon č. 159/2006 Sb., o střetu zájmů') && str_contains($zh, 'href="tisk.php?ct=221"') && str_contains($zh, 'rss.php?zakon=159%2F2006'), true);
}
$_GET = [];

echo "Tokeny MCP na webu\n";
check('tvar tokenu jako hlidac_rag.tokens', (bool)preg_match('~^hz_[A-Za-z0-9_-]{43}$~', Hlidac\McpTokens::generate()), true);
check('jméno tokenu bez řídicích znaků a zkrácené', Hlidac\McpTokens::label("rešerše\nk článku") === 'web: ' . date('Y-m-d') . ' · rešerše k článku'
    && mb_strlen(Hlidac\McpTokens::label(str_repeat('x', 300))) === mb_strlen('web: ' . date('Y-m-d') . ' · ') + 80, true);
// id se čísluje samo: v SQLite INTEGER PRIMARY KEY, v PostgreSQL (HLIDAC_TEST_DSN) SERIAL, v MySQL AUTO_INCREMENT
$tokId = match (db()->driver) { 'pgsql' => 'SERIAL PRIMARY KEY', 'mysql' => 'INTEGER PRIMARY KEY AUTO_INCREMENT', default => 'INTEGER PRIMARY KEY' };
db()->run("CREATE TABLE IF NOT EXISTS mcp_token (id $tokId, jmeno VARCHAR(255) NOT NULL, hash VARCHAR(64) NOT NULL UNIQUE, vytvoren VARCHAR(40) NOT NULL, naposledy VARCHAR(40), zrusen VARCHAR(40))");
$tokCfg = config();
$tokCfg['mcp'] = ['token_table' => 'mcp_token', 'tokens_per_day' => 2];
$tok = new Hlidac\McpTokens(db(), $tokCfg);
$secret = (new ReflectionMethod($tok, 'secret'))->invoke($tok);
$ipA = '198.51.100.9';
$ft = $tok->formToken($ipA, time() - 10);
check('ověření formuláře: v pořádku, rychle, vypršelo, podvržené, z jiné adresy', [
    Hlidac\McpTokens::verifyFormToken($ft, $secret, $ipA),
    Hlidac\McpTokens::verifyFormToken($tok->formToken($ipA), $secret, $ipA) !== null,
    Hlidac\McpTokens::verifyFormToken($tok->formToken($ipA, time() - 4000), $secret, $ipA) !== null,
    Hlidac\McpTokens::verifyFormToken(substr($ft, 0, -1) . (substr($ft, -1) === 'a' ? 'b' : 'a'), $secret, $ipA) !== null,
    Hlidac\McpTokens::verifyFormToken($ft, $secret, '203.0.113.200') !== null,
], [null, true, true, true, true]);
check('klíč podpisu formuláře není odvozený z cron_token', $secret !== hash('sha256', 'hlidac-form|' . (string)config('cron_token', '')) && strlen((string)db()->getMeta('form_secret', '')) === 64, true);
$site = 'https://cosepece.cz';
check('formulář jen z naší stránky (Origin, Sec-Fetch-Site)', [
    Hlidac\McpTokens::sameOrigin([], $site),
    Hlidac\McpTokens::sameOrigin(['HTTP_ORIGIN' => 'https://cosepece.cz', 'HTTP_SEC_FETCH_SITE' => 'same-origin'], $site),
    Hlidac\McpTokens::sameOrigin(['HTTP_ORIGIN' => 'https://evil.example'], $site),
    Hlidac\McpTokens::sameOrigin(['HTTP_SEC_FETCH_SITE' => 'cross-site'], $site),
    Hlidac\McpTokens::sameOrigin(['HTTP_ORIGIN' => 'http://cosepece.cz'], $site),
    Hlidac\McpTokens::sameOrigin(['HTTP_ORIGIN' => 'null'], $site),
    Hlidac\McpTokens::sameOrigin(['HTTP_ORIGIN' => 'https://cosepece.cz.evil.example'], $site),
], [true, true, false, false, false, false, false]);
check('odeslání z cizího webu token nevydá', $tok->issue(['formular' => $ft], $ipA, ['HTTP_ORIGIN' => 'https://evil.example'])['error'] ?? null, 'Formulář lze odeslat jen z této stránky. Načtěte ji znovu.');
check('skryté pole vyplněné robotem', isset($tok->issue(['formular' => $ft, 'web' => 'http://spam'], $ipA)['error']), true);
check('podpis z jiné adresy token nevydá', isset($tok->issue(['formular' => $ft], '203.0.113.200')['error']), true);
$r1 = $tok->issue(['formular' => $ft, 'ucel' => 'test'], $ipA);
check('vydaný token: v tabulce jen jeho sha256', isset($r1['token']) && db()->value('SELECT jmeno FROM mcp_token WHERE hash = ?', [hash('sha256', (string)($r1['token'] ?? ''))]) === 'web: ' . date('Y-m-d') . ' · test'
    && db()->value('SELECT COUNT(*) FROM mcp_token WHERE hash = ?', [(string)($r1['token'] ?? '')]) == 0, true);
$tok->issue(['formular' => $tok->formToken('198.51.100.10', time() - 10)], '198.51.100.10');
check('denní strop pro všechny', $tok->issue(['formular' => $tok->formToken('198.51.100.11', time() - 10)], '198.51.100.11')['error'] ?? null, 'Dnešní počet nových tokenů je vyčerpaný. Zkuste to zítra.');
// úklid nepoužívaných tokenů: nikdy nepoužitý starší než týden a nepoužívaný déle než 90 dní; ruční tokeny zůstávají
$ago = fn(int $days) => gmdate('Y-m-d H:i:s', time() - 86400 * $days) . '+00:00';
db()->run('DELETE FROM mcp_token');
foreach ([['web: starý nepoužitý', 'h1', $ago(8), null], ['web: nový nepoužitý', 'h2', $ago(2), null], ['web: dlouho nepoužívaný', 'h3', $ago(200), $ago(100)],
    ['web: používaný', 'h4', $ago(200), $ago(3)], ['novinář (ruční)', 'h5', $ago(300), null]] as [$jm, $h, $v, $n]) {
    db()->run('INSERT INTO mcp_token (jmeno, hash, vytvoren, naposledy) VALUES (?, ?, ?, ?)', [$jm, $h, $v, $n]);
}
check('úklid zruší jen nepoužívané tokeny z webu', [$tok->revokeStale(), array_column(db()->all('SELECT hash FROM mcp_token WHERE zrusen IS NOT NULL ORDER BY hash'), 'hash'), $tok->revokeStale()], [2, ['h1', 'h3'], 0]);
check('bez tabulky služby rag/ (SQLite) se tokeny nevydávají', (new Hlidac\McpTokens(db(), config()))->available(), false);

$_GET = [];
ob_start();
(function (string $file): void {
    require $file;
})(APP_ROOT . '/mcp.php');
$mcpHtml = (string)ob_get_clean();
check('stránka MCP vysvětluje hledání podle významu a co odchází do OpenAI', str_contains($mcpHtml, 'id="jak-funguje"') && str_contains($mcpHtml, 'odchází text hledaného dotazu do OpenAI')
    && str_contains($mcpHtml, 'rezim="fulltext"') && str_contains($mcpHtml, 'Text dotazů ani odpovědí neukládáme'), true);

echo "Limit dotazů\n";
$rlDir = $tmp . '/ratelimit';
$rl = fn(string $ip) => Hlidac\RateLimit::hit('api', 3, 60, $ip, $rlDir);
check('v limitu projde, nad limit vrátí Retry-After', [$rl('203.0.113.5'), $rl('203.0.113.5'), $rl('203.0.113.5'), is_int($rl('203.0.113.5'))], [null, null, null, true]);
check('jiná adresa má vlastní počítadlo', $rl('203.0.113.6'), null);
check('klient pro limity: IPv4 beze změny, IPv6 po sítích /56', [Hlidac\RateLimit::client('203.0.113.5'), Hlidac\RateLimit::client('2001:db8:aa:bb01::1') === Hlidac\RateLimit::client('2001:db8:aa:bbff:1234::9'),
    Hlidac\RateLimit::client('2001:db8:aa:bb01::1') === Hlidac\RateLimit::client('2001:db8:aa:cc01::1')], ['203.0.113.5', true, false]);
check('IPv6 ze stejné /64 sítě se počítá dohromady', [Hlidac\RateLimit::hit('api', 1, 60, '2001:db8::1', $rlDir), is_int(Hlidac\RateLimit::hit('api', 1, 60, '2001:db8::ffff', $rlDir))], [null, true]);
check('soubory neobsahují IP adresu', array_filter(array_map('file_get_contents', glob($rlDir . '/*')), fn($c) => str_contains($c, '203.0.113')) === []
    && array_filter(glob($rlDir . '/*'), fn($f) => str_contains($f, '203.0.113')) === [], true);
$peek = fn() => Hlidac\RateLimit::hit('auth', 2, 900, '198.51.100.7', $rlDir, false);
$fail = fn() => Hlidac\RateLimit::hit('auth', 2, 900, '198.51.100.7', $rlDir);
$states = [$peek()];
$fail();
$states[] = $peek();
$fail();
$states[] = is_int($peek());
check('chybné tokeny: po vyčerpání pokusů je adresa zablokovaná, kontrola sama nepočítá', $states, [null, null, true]);
check('limit 0 = bez omezení', Hlidac\RateLimit::hit('api', 0, 60, '203.0.113.5', $rlDir), null);

echo "Které pozměňovací návrhy prošly (#5)\n";
// souhrn s výslovnými písmeny (jako tisk 90/2)
$sum1 = Hlidac\PnOutcome::parseSummary("Pozměňovací návrhy přednesené ve druhém čtení dne 15. dubna 2026\nA.\tPoslankyně Vendula Svobodová\nSD 839\nV čl. II bodě 25 …\nB.\tPoslankyně Alena Schillerová\nB1. SD 825\nV čl. II …\nB2. SD 826\nD.\tPoslanec Karel Haas\nSD 837\nK části druhé …");
check('souhrn: písmena s číslem dokumentu', [$sum1['pismena'], $sum1['odhad']], [['A' => [839], 'B1' => [825], 'B2' => [826], 'D' => [837]], []]);
// písmena předkladatelů čísluje Word (v textu chybí): odvodí se z pořadí, výslovné „B1.“ odhad potvrdí; oddíl výboru zabírá písmeno
$sum2 = Hlidac\PnOutcome::parseSummary("Pozměňovací návrhy přednesené ve druhém čtení dne 7. července 2026.\nPoslanec Adam Vojtěch\nSD 1546\nPoslanec Vojtěch Munzar\nB1. SD 1538\nB2. SD 1539\nPoslankyně Jana Filipovičová\n(SD 1537)\nPoslanec Jan Novák\nD.1.\nPřihlašuje se k návrhu\n(SD 633)\nD.2 (SD 779)");
check('souhrn: písmeno odvozené z pořadí a různé zápisy SD', [$sum2['pismena'], $sum2['odhad']],
    [['A' => [1546], 'B1' => [1538], 'B2' => [1539], 'C' => [1537], 'D1' => [633], 'D2' => [779]], ['A', 'C']]);
$sum3 = Hlidac\PnOutcome::parseSummary("Pozměňovací návrhy obsažené v usnesení garančního výboru č. 29\nV čl. I se vkládá bod X12\nPozměňovací návrhy přednesené ve druhém čtení\nPoslankyně Berenika Peštová\nSD 1790\nPoslanec Petr Hladík\nC1. SD 1807\nV textu se zmiňuje i SD 1999.");
check('souhrn: oddíl návrhů výboru zabírá písmeno A; zmíněná čísla', [$sum3['pismena'], $sum3['odhad'], $sum3['zminena']], [['B' => [1790], 'C1' => [1807]], ['B'], [1790, 1807, 1999]]);
$sum4 = Hlidac\PnOutcome::parseSummary("Poslanec Jan První\nSD 10\nPoslanec Jan Druhý\nSD 20\nPoslanec Jan Třetí\nF1. SD 30");
check('souhrn: odhad, který nesedí s výslovným písmenem, se zahodí', [$sum4['pismena'], $sum4['odhad']], [['F1' => [30]], []]);
// párování hlasování podle stenozáznamu
$vt = [901 => ['cislo' => 12, 'vysledek' => 'A'], 902 => ['cislo' => 13, 'vysledek' => 'R'], 903 => ['cislo' => 14, 'vysledek' => 'R'], 904 => ['cislo' => 15, 'vysledek' => 'A'],
    905 => ['cislo' => 16, 'vysledek' => 'A'], 906 => ['cislo' => 17, 'vysledek' => 'R'], 907 => ['cislo' => 18, 'vysledek' => 'A'], 908 => ['cislo' => 19, 'vysledek' => 'A'], 909 => ['cislo' => 20, 'vysledek' => 'A']];
$zp = fn(string $t): array => ['predsedajici' => false, 'text' => $t, 'hlasovani' => []];
$pr = fn(array $ids, string $t = 'Zahajuji hlasování. Kdo je pro? Návrh byl přijat.'): array => ['predsedajici' => true, 'text' => $t, 'hlasovani' => $ids];
$mv = Hlidac\PnOutcome::mapVotes([
    $zp('Nejprve budeme hlasovat o proceduře, pak návrh A, návrh B1 a návrh D.'), $pr([901]),                       // procedura: víc písmen bez jména → nic
    $zp('Pozměňovací návrh A paní poslankyně Svobodové, překročení výdajů. Stanovisko výboru: nedoporučující.'), $pr([902]),
    $zp('Návrh s označením B1, navrhovatel Alena Schillerová. Stanovisko: doporučující.'), $pr([904]),
    $zp('D-a, varianta 1, pan poslanec Haas.'), $pr([903]),
    $zp('D-a, varianta 2, pan poslanec Haas.'), $pr([905]),
    $zp('Pozměňovací návrh pod písmenem B2 kolegyně Schillerové.'), $pr([906, 907]),                                 // dvě hlasování v jednom projevu → nic
    $zp('Díky přijetí návrhu B1 se stal nehlasovatelným návrh A a jdeme na pozměňovací návrh B2 paní ministryně Schillerové.'), $pr([908]),
    $zp('Návrh D pana poslance Haase.'), $pr([909]), $pr([909], 'To bylo zmatečné hlasování, začneme znovu.'),      // zmatečné → vynechat
], $vt, ['A', 'B1', 'B2', 'D'], ['A' => ['Vendula Svobodová'], 'B1' => ['Alena Schillerová'], 'B2' => ['Alena Schillerová'], 'D' => ['Karel Haas']]);
check('hlasování přiřazená k písmenům (ohlášení, varianty, víc písmen podle jména, zmatečné hlasování)',
    array_map(fn($v) => [$v['cislo'], $v['pismeno'], $v['varianta'], $v['vysledek'], $v['stanovisko']], $mv),
    [[13, 'A', null, 'R', 'nedoporučující'], [14, 'D-a', 1, 'R', null], [15, 'B1', null, 'A', 'doporučující'], [16, 'D-a', 2, 'A', null], [19, 'B2', null, 'A', null]]);
$oc = Hlidac\PnOutcome::outcomes($sum1, $mv, [839 => 'Vendula Svobodová', 825 => 'Alena Schillerová', 826 => 'Alena Schillerová', 837 => 'Karel Haas', 836 => 'Karel Haas']);
check('výsledek návrhů: zamítnut, přijat, přijatá varianta, nezazněl ve 2. čtení', array_map(fn($r) => $r['stav'], $oc), [839 => 'zamitnut', 825 => 'prijat', 826 => 'prijat', 837 => 'prijat', 836 => 'nezaznel']);
check('ohlášení jmenuje předkladatele (skloňované příjmení)', [Hlidac\PnOutcome::namesAuthor('Pozměňovací návrh E1 pana poslance Jurečky', 'Marian Jurečka'),
    Hlidac\PnOutcome::namesAuthor('návrh paní poslankyně Sedmihradské', 'Lucie Sedmihradská'), Hlidac\PnOutcome::namesAuthor('návrh pana poslance Haase', 'Marian Jurečka')], [true, true, false]);
// písmeno odvozené z pořadí platí jen se jménem předkladatele; návrh bez přiřazeného hlasování je „nelze určit“
$mvGuess = [['pismeno' => 'A', 'zaklad' => 'A', 'varianta' => null, 'stanovisko' => null, 'ohlaseni' => 'Návrh A pana poslance Nováka.', 'id_hlasovani' => 1, 'cislo' => 5, 'vysledek' => 'A']];
check('odhadnuté písmeno bez shody jména a návrh bez hlasování → nelze určit',
    array_map(fn($r) => $r['stav'], Hlidac\PnOutcome::outcomes($sum2, $mvGuess, [1546 => 'Adam Vojtěch', 1538 => 'Vojtěch Munzar', 1537 => 'Jana Filipovičová', 2000 => 'Někdo Jiný'])),
    [1546 => 'neurceno', 1538 => 'neurceno', 1537 => 'neurceno', 2000 => 'nezaznel']);
// popis procedury se nepočítá; číslo sněmovního dokumentu určí přesné písmeno; „návrh B“ bez čísla neplatí pro B1 a B2
$mv2 = Hlidac\PnOutcome::mapVotes([
    $zp('Výbor navrhuje následující proceduru: začneme pozměňovacím návrhem B1 pana poslance Nováka.'), $pr([901]),
    $zp('Nyní budeme hlasovat pozměňovací návrh B, sněmovní dokument 825, který řeší koordinaci prací.'), $pr([902]),
    $zp('Nyní poslední pozměňovací návrh B paní ministryně Schillerové.'), $pr([904]),
], $vt, ['A', 'B1', 'B2', 'D'], ['B1' => ['Alena Schillerová'], 'B2' => ['Alena Schillerová']], [825 => 'B1', 826 => 'B2', 839 => 'A', 836 => '']);
check('popis procedury se přeskočí, číslo dokumentu určí písmeno', array_map(fn($v) => [$v['cislo'], $v['zaklad']], $mv2), [[13, 'B1'], [15, 'B']]);
check('hlasování o „návrhu B“ bez čísla se k B1 ani B2 nepřiřadí', array_map(fn($r) => [$r['stav'], count($r['hlasovani'])],
    Hlidac\PnOutcome::outcomes($sum1, $mv2, [825 => 'Alena Schillerová', 826 => 'Alena Schillerová'])), [825 => ['zamitnut', 1], 826 => ['neurceno', 0]]);
// zpravodaj jmenuje číslo dokumentu návrhu, kterému souhrn nepřiřadil písmeno: výsledek se přesto určí
$mv3 = Hlidac\PnOutcome::mapVotes([$zp('Nyní pozměňovací návrh D, sněmovní dokument 836, poslance Králíčka. Stanovisko souhlasné.'), $pr([904])], $vt, ['A', 'B1'], [], [836 => '', 839 => 'A']);
check('číslo dokumentu stačí i bez písmene ze souhrnu', [array_map(fn($v) => [$v['cislo'], $v['sd']], $mv3),
    array_map(fn($r) => $r['stav'], Hlidac\PnOutcome::outcomes(['pismena' => ['A' => [839]], 'odhad' => [], 'zminena' => [839, 836]], $mv3, [836 => 'Jiří Jiný', 839 => 'Vendula Svobodová']))],
    [[[15, 836]], [836 => 'prijat', 839 => 'neurceno']]);
// čtení po větách: nehlasovatelné návrhy, „(dokument) 1219“ bez slova sněmovní, společné hlasování
$sdl = [1175 => 'G2', 1235 => 'Q11', 1178 => 'G3', 1068 => 'H4', 1239 => 'H5', 1155 => 'G1', 1290 => ''];
$ann = fn(string $t): array => array_map(fn($a) => [$a['pismeno'], $a['sd']], Hlidac\PnOutcome::announced($t, ['G1', 'G2', 'G3', 'Q11', 'H4', 'H5', 'E1', 'E2', 'E3'], [], $sdl));
check('ohlášení: nehlasovatelné návrhy se vynechají, platí číslo dokumentu a označení zpravodaje',
    $ann('Tímto se staly nehlasovatelnými G2, (dokument) 1175, a Q11, (dokument) 1235. Nyní k hlasování třináct, (návrh) G3, (dokument) 1178, Davida Pražáka. Hospodářský výbor doporučuje.'), [['G3', 1178]]);
check('ohlášení: společné hlasování o dvou dokumentech', $ann('Bod sedmnáct, jedním hlasováním (návrh) H4, (dokument) 1068; (návrh) H5, (dokument) 1239, kolegy Niemiece. Výbor nedoporučuje.'), [['H4', 1068], ['H5', 1239]]);
check('ohlášení: dva dokumenty bez společného hlasování → nic', $ann('Nyní návrh H4, dokument 1068, a potom H5, dokument 1239.'), []);
check('ohlášení: řada písmen „E1 až E3“ je společné hlasování', $ann('Nyní pozměňovací návrhy E1 až E3 společně jedním hlasováním.'), [['E1', null], ['E2', null], ['E3', null]]);
check('ohlášení: výčet za množným číslem je společné hlasování, výčet za jednotným číslem ne',
    [$ann('Jedním hlasováním pozměňovací návrhy pod písmeny E1, E2 a E3, terminologické úpravy.'), $ann('Pozměňovací návrhy E1 a E2 kolegy Nováka.'), $ann('Pozměňovací návrh E1, E2 je obdobný.')],
    [[['E1', null], ['E2', null], ['E3', null]], [['E1', null], ['E2', null]], []]);
check('ohlášení: návrh bez písmene v souhrnu podle čísla dokumentu a označení zpravodaje', $ann('G1 se nehlasuje. Nyní hlasování dvanáct, pozměňovací návrh Cc, (dokument) 1290, Bereniky Peštové.'), [['Cc', 1290]]);
check('stanovisko výboru i ve tvaru „výbor nedoporučuje“', array_column(Hlidac\PnOutcome::announced('Bod patnáct, (návrh) G3, (dokument) 1178, kolega Pražák. Hospodářský výbor nedoporučuje.', ['G3'], [], $sdl), 'stanovisko'), ['nedoporučující']);
$nv = Hlidac\PnOutcome::notVoted([
    $zp('Tímto se staly nehlasovatelnými G2, (dokument) 1175, a Q11, (dokument) 1235. Nyní k hlasování třináct, (návrh) G3, (dokument) 1178.'),
    $zp('(Návrh) G1, (dokument) 1155, Davida Pražáka se nehlasuje, jelikož byly přijaty návrhy H4 a H5.'),
    $zp('Pokud bude přijat návrh E1, stane se nehlasovatelným návrh E2.'),                            // podmíněné: nepočítá se
    $zp('Díky přijetí návrhu E1 se stal nehlasovatelným návrh E3 a jdeme na pozměňovací návrh E2.'),
    $zp('Navrhuji proceduru: návrh H4 je nehlasovatelný.'),                                           // popis procedury: nepočítá se
], ['G1', 'G2', 'G3', 'Q11', 'H4', 'H5', 'E1', 'E2', 'E3'], $sdl);
check('nehlasovatelné návrhy: jen jednoznačné výroky, bez návrhů uvedených jako důvod', $nv, ['sd' => [1175, 1235, 1155], 'pismena' => ['E3']]);
check('ohlášení za výrokem o nehlasovatelných („…, takže bychom měli hlasovat návrh G“); odkaz na proceduru nevadí',
    array_map(fn($a) => $a['pismeno'], Hlidac\PnOutcome::announced('Díky přijetí pozměňovacího návrhu E1 se staly nehlasovatelné ty, které máte v hlasovací proceduře jako G2 a Q11, takže bychom nyní měli hlasovat pozměňovací návrh pod písmenem G3. Je to návrh kolegy Pražáka.',
        ['G1', 'G2', 'G3', 'Q11', 'E1'], ['G3' => ['David Pražák']], [])), ['G3']);
check('nehlasovatelné: návrh, o kterém se hned potom hlasuje, mezi ně nepatří; podbod K14.1 není návrh K14',
    Hlidac\PnOutcome::notVoted([$zp('Díky přijetí návrhu E1 se staly nehlasovatelné G2 a Q11, takže bychom nyní měli hlasovat pozměňovací návrh pod písmenem G3.'),
        $zp('Nyní se stává nehlasovatelným návrh G1.1, G1.2 a E2.')], ['G1', 'G2', 'G3', 'Q11', 'E1', 'E2'], []), ['sd' => [], 'pismena' => ['G2', 'Q11', 'E2']]);
check('popis procedury × odkaz na ni', [Hlidac\PnOutcome::announced('Výbor navrhuje následující proceduru: nejprve návrh G1 pana Pražáka.', ['G1'], [], []),
    array_column(Hlidac\PnOutcome::announced('Podle schválené procedury nyní hlasujeme pozměňovací návrh G1 pana Pražáka.', ['G1'], [], []), 'pismeno')], [[], ['G1']]);
check('nehlasovatelný návrh má vlastní stav; hlasování má přednost', array_map(fn($r) => $r['stav'], Hlidac\PnOutcome::outcomes(
    ['pismena' => ['G2' => [1175], 'G3' => [1178], 'E3' => [900], 'E2' => [901]], 'odhad' => [], 'zminena' => [1175, 1178, 900, 901]],
    [['pismeno' => 'G3', 'zaklad' => 'G3', 'sd' => 1178, 'varianta' => null, 'stanovisko' => null, 'ohlaseni' => '', 'id_hlasovani' => 1, 'cislo' => 13, 'vysledek' => 'A']],
    [1175 => 'David Pražák', 1178 => 'David Pražák', 900 => 'Jan Novák', 901 => 'Jan Novák'], true, ['sd' => [1175, 1178], 'pismena' => ['E3']])),
    [1175 => 'nehlasovatelny', 1178 => 'prijat', 900 => 'nehlasovatelny', 901 => 'neurceno']);
check('před 3. čtením návrh čeká na hlasování, výsledek se neuvádí', array_map(fn($r) => $r['stav'],
    Hlidac\PnOutcome::outcomes($sum1, [], [839 => 'Vendula Svobodová', 825 => 'Alena Schillerová', 836 => 'Karel Haas'], false)), [839 => 'ceka', 825 => 'ceka', 836 => 'nezaznel']);
check('štítek před 3. čtením', strip_tags(Hlidac\View::pnOutcome(['stav' => 'ceka', 'pismena' => 'B1', 'hlasovani' => [], 'stanovisko' => null])), 'Zazněl ve 2. čtení · B1');
// celý výpočet nad databází: souhrn tisku 90, hlasování a stenozáznam (schéma steno jako u služby rag/)
if (db()->driver === 'sqlite') {
    db()->pdo->exec("ATTACH DATABASE ':memory:' AS steno");
} elseif (db()->driver === 'pgsql') {
    db()->pdo->exec('CREATE SCHEMA IF NOT EXISTS steno');
}
if (in_array(db()->driver, ['sqlite', 'pgsql'], true)) {
    db()->pdo->exec('CREATE TABLE steno.projev (schuze INTEGER, datum VARCHAR(10), poradi INTEGER, ct INTEGER, predsedajici INTEGER, text TEXT, hlasovani TEXT)');
    $pn90 = array_map('intval', array_column(db()->all('SELECT cislo FROM pn WHERE ct = 90 ORDER BY cislo LIMIT 3'), 'cislo'));
    db()->run('INSERT INTO pn_souhrn (ct, cislo_za, pismena, chars, fetched_at) VALUES (90, 2, ?, 100, ?)',
        [json_encode(['pismena' => ['A' => [$pn90[0]], 'B1' => [$pn90[1]]], 'odhad' => [], 'zminena' => [$pn90[0], $pn90[1]]]), date('Y-m-d H:i:s')]);
    db()->run("INSERT INTO hlasovani (id_hlasovani, ct, schuze, cislo, datum, vysledek, nazev) VALUES (990001, 90, 99, 14, '2026-05-15 12:40', 'R', 'test'), (990002, 90, 99, 15, '2026-05-15 12:41', 'A', 'test')");
    foreach ([[1, 0, 'Pozměňovací návrh A. Stanovisko výboru: nedoporučující.', ''], [2, 1, 'Zahajuji hlasování. Návrh nebyl přijat.', '{990001}'],
        [3, 0, 'Pozměňovací návrh B1. Stanovisko: doporučující.', ''], [4, 1, 'Zahajuji hlasování. Návrh byl přijat.', '{990002}']] as [$por, $chair, $txt, $hl]) {
        db()->run("INSERT INTO steno.projev (schuze, datum, poradi, ct, predsedajici, text, hlasovani) VALUES (99, '2026-05-15', ?, 90, ?, ?, ?)", [$por, $chair, $txt, $hl]);
    }
    $po = new Hlidac\PnOutcome(db(), config());
    $poStats = $po->compute();
    $poRows = (new Hlidac\Repo(db()))->pnOutcomes(90);
    check('výpočet nad databází: přijat, zamítnut, nezazněl; se stenozáznamem', [$po->stenoAvailable(), $poStats['prirazeno_hlasovani'], $poRows[$pn90[0]]['stav'] ?? null, $poRows[$pn90[1]]['stav'] ?? null,
        $poRows[$pn90[2]]['stav'] ?? null, $poRows[$pn90[1]]['hlasovani'][0]['cislo'] ?? null, $poRows[$pn90[0]]['stanovisko'] ?? null], [true, 2, 'zamitnut', 'prijat', 'nezaznel', 15, 'nedoporučující']);
    $_GET = ['ct' => '90'];
    ob_start();
    (function (string $file): void {
        require $file;
    })(APP_ROOT . '/tisk.php');
    $poHtml = (string)ob_get_clean();
    $_GET = [];
    check('detail tisku ukazuje výsledek návrhu a odkaz na hlasování', [str_contains($poHtml, 'Přijat · B1'), str_contains($poHtml, 'Zamítnut · A'), str_contains($poHtml, 'Nezazněl ve 2. čtení'),
        str_contains($poHtml, 'hlasy.sqw?g=990002'), str_contains($poHtml, 'stanovisko výboru: nedoporučující')], [true, true, true, true, true]);
    $poApi = (new Hlidac\Api(db(), new Hlidac\Repo(db()), config()))->bill(90)['vysledky_pn'];
    check('API: výsledky pozměňovacích návrhů', [count($poApi) === count(db()->all('SELECT 1 FROM pn WHERE ct = 90')), array_values(array_filter($poApi, fn($r) => $r['cislo'] === $pn90[1]))[0]['stav'] ?? null], [true, 'prijat']);
    // bez stenozáznamu (hosting bez služby rag/) zůstane jen informace, zda návrh zazněl
    db()->pdo->exec('DROP TABLE steno.projev');
    if (db()->driver === 'sqlite') {
        db()->pdo->exec('DETACH DATABASE steno');
    } else {
        db()->pdo->exec('DROP SCHEMA steno');
    }
    $po->compute();
    $poRows = (new Hlidac\Repo(db()))->pnOutcomes(90);
    check('bez stenozáznamu: jen zazněl / nezazněl', [$po->stenoAvailable(), $poRows[$pn90[0]]['stav'] ?? null, $poRows[$pn90[2]]['stav'] ?? null], [false, 'neurceno', 'nezaznel']);
    db()->run('DELETE FROM hlasovani WHERE id_hlasovani IN (990001, 990002)');
    $po->compute();
    check('bez hlasování po souhrnu (před 3. čtením): čeká', (new Hlidac\Repo(db()))->pnOutcomes(90)[$pn90[0]]['stav'] ?? null, 'ceka');
    db()->run('DELETE FROM hlasovani WHERE id_hlasovani IN (990001, 990002)');
    db()->run('DELETE FROM pn_souhrn WHERE ct = 90');
    db()->run('DELETE FROM pn_vysledek WHERE ct = 90');
}

echo "Kontrola struktury databáze jen po změně schématu\n";
check('po migraci se kontrola při dalším požadavku přeskočí', [db()->migrateIfNeeded(), db()->getMeta('schema_stamp') === Hlidac\Db::schemaStamp()], [false, true]);
db()->setMeta('schema_stamp', 'starší verze');
check('jiný otisk schématu (nová verze) migraci spustí a zapíše nový otisk', [db()->migrateIfNeeded(), db()->getMeta('schema_stamp') === Hlidac\Db::schemaStamp(), db()->migrateIfNeeded()], [true, true, false]);
// chybějící sloupec po obnově ze starší zálohy: starý otisk → migrace ho doplní
if (db()->driver === 'sqlite') {
    $fresh = new Hlidac\Db('sqlite:' . $tmp . '/nova.sqlite', null, null);
    check('nová databáze bez tabulek se založí', [$fresh->migrateIfNeeded(), $fresh->getMeta('schema_stamp') === Hlidac\Db::schemaStamp(), $fresh->migrateIfNeeded(),
        (int)$fresh->value('SELECT COUNT(*) FROM tisky')], [true, true, false, 0]);
}

echo "Opakování odeslání při výpadku webu\n";
check('přechodné stavy: bez odpovědi a 502–504 ano, 500 a 403 ne', array_map([Hlidac\Http::class, 'transient'], [0, 502, 503, 504, 500, 403, 200]), [true, true, true, true, false, false, false]);
// místní server: první dva dotazy 502 (jako proxy při výměně kontejneru), pak 200
$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)substr(strrchr((string)stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
file_put_contents($tmp . '/router.php', '<?php $f = __DIR__ . "/hits.txt"; $n = (int)@file_get_contents($f) + 1; file_put_contents($f, $n);'
    . ' if ($n <= (int)getenv("FAIL_FIRST")) { http_response_code(502); exit; } header("Content-Type: application/json"); echo json_encode(["ok" => true, "pokus" => $n]);');
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $tmp . '/router.php'], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, null, ['FAIL_FIRST' => '2'] + getenv());
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port, $en, $es, 0.1); $i++) {
    usleep(100_000);
}
$url = 'http://127.0.0.1:' . $port . '/';
$without = (function () use ($url) { try { Hlidac\Http::post($url, [], ['a' => 1]); return 'ok'; } catch (Throwable $ex) { return str_contains($ex->getMessage(), 'HTTP 502') ? '502' : $ex->getMessage(); } })();
@unlink($tmp . '/hits.txt');
$with = Hlidac\Http::post($url, [], ['a' => 1], true, 10, [0.05, 0.05, 0.05]);
@unlink($tmp . '/hits.txt');
$tooFew = (function () use ($url) { try { Hlidac\Http::post($url, [], ['a' => 1], true, 10, [0.05]); return 'ok'; } catch (Throwable $ex) { return str_contains($ex->getMessage(), 'HTTP 502') ? '502' : $ex->getMessage(); } })();
proc_terminate($server);
proc_close($server);
check('bez opakování chyba 502, s opakováním úspěch na třetí pokus, málo pokusů zase chyba', [$without, $with, $tooFew], ['502', ['ok' => true, 'pokus' => 3], '502']);

echo "Upozornění na připravované zákony\n";
// bez dat (ODok vypnutý nebo nedostupný) se první běh nepočítá, jinak by se dosavadní stav později rozeslal jako novinky
$prepRows = db()->all('SELECT * FROM pripravovane');
check('PID všech verzí materiálu', array_column($prepRows, 'pidy', 'pid')['KORNUP000003'] ?? null, 'KORNUP000003,KORNUP000002');
db()->run('DELETE FROM pripravovane');
db()->run("DELETE FROM meta WHERE k = 'prep_alerts_bootstrapped'");
(new Hlidac\AlertBuilder(db(), config()))->run();
check('bez připravovaných zákonů se první běh nezapíše', db()->getMeta('prep_alerts_bootstrapped'), null);
db()->insertMany('pripravovane', array_keys($prepRows[0]), $prepRows);
Hlidac\View::forgetUpcoming();
db()->run("DELETE FROM meta WHERE k = 'prep_alerts_bootstrapped'");
(new Hlidac\AlertBuilder(db(), config()))->run();
(new Hlidac\Scorer(db(), config()))->scoreAlerts();
$prepAlerts = [];
foreach (db()->all("SELECT alert_key, kind, ct, score, posted, title FROM alerts WHERE kind LIKE 'prep_%' AND kind <> 'prep_narizeni' AND alert_key NOT LIKE '%/%' ORDER BY alert_key") as $a) {
    $prepAlerts[$a['alert_key']] = [$a['kind'], (int)$a['ct'], (int)$a['score'], (int)$a['posted']];
}
check('krátké připomínky (7 dní), mimo plán a schválení vládou; první běh se neposílá', $prepAlerts, [
    'prep_plan:KORNUP000003' => ['prep_plan', 0, 30, 2],
    'prep_review:KORNUP000003' => ['prep_velmi', 0, 50, 2],
    'prep_vlada:KORNUP000003' => ['prep_vlada', 0, 0, 2],
]);
check('titulek s délkou připomínek', db()->value("SELECT title FROM alerts WHERE alert_key = 'prep_review:KORNUP000003'"),
    'Připomínky k návrhu jen 7 dní: Návrh zákona, kterým se mění zákon č. 300/2013 Sb., o Vojenské policii.');
check('druhý běh nic nepřidá', (new Hlidac\AlertBuilder(db(), config()))->run(), 0);
$pa = db()->one("SELECT * FROM alerts WHERE alert_key = 'prep_review:KORNUP000003'");
// nová verze materiálu má jiný PID: upozornění se neopakuje a starší upozornění vede na aktuální položku
db()->run("UPDATE pripravovane SET pid = 'KORNUP000009', pidy = 'KORNUP000009,KORNUP000003,KORNUP000002' WHERE pid = 'KORNUP000003'");
Hlidac\View::forgetUpcoming();
check('nová verze materiálu upozornění neopakuje', [(new Hlidac\AlertBuilder(db(), config()))->run(),
    (int)db()->value("SELECT COUNT(*) FROM alerts WHERE alert_key LIKE 'prep_%:KORNUP000009'"), Hlidac\View::alertUrl($pa)],
    [0, 0, 'pripravovane.php#prep-KORNUP000009']);
db()->run("UPDATE pripravovane SET pid = 'KORNUP000003', pidy = 'KORNUP000003,KORNUP000002' WHERE pid = 'KORNUP000009'");
Hlidac\View::forgetUpcoming();
$prepIds = function (string $law): array {
    $ids = array_values(array_filter(array_column((new Hlidac\Api(db(), new Hlidac\Repo(db()), config()))->alerts(
        Hlidac\Api::filters(['zakon' => $law, 'min_score' => '0', 'limit' => '500'])), 'id'), fn($id) => str_starts_with($id, 'prep_')));
    sort($ids);
    return $ids;
};
check('filtr podle zákona vrací i upozornění na připravované změny zákona', [$prepIds('300/2013'), $prepIds('159/2006')],
    [['prep_plan:KORNUP000003', 'prep_review:KORNUP000003', 'prep_vlada:KORNUP000003'], []]);
$nvAlert = db()->one("SELECT * FROM alerts WHERE alert_key = 'prep_nv:KORNNV000001'") ?? [];
check('upozornění na krátké připomínky k nařízení vlády (Pozor, první běh se neposílá)',
    [$nvAlert['kind'] ?? null, (int)($nvAlert['score'] ?? -1), (int)($nvAlert['posted'] ?? -1), (int)($nvAlert['ct'] ?? -1),
        str_starts_with((string)($nvAlert['title'] ?? ''), 'Připomínky k nařízení vlády jen 7 dní: Nařízení vlády, kterým se mění nařízení vlády č. 222/2010 Sb.')],
    ['prep_narizeni', 30, 2, 0, true]);
check('jen jedno upozornění na nařízení (delší připomínky a schválení se nehlásí)', (int)db()->value("SELECT COUNT(*) FROM alerts WHERE kind = 'prep_narizeni' AND alert_key NOT LIKE '%/%'"), 1);
check('odkaz upozornění vede na přehled nařízení vlády', Hlidac\View::alertUrl($nvAlert), 'pripravovane.php?druh=narizeni#prep-KORNNV000001');
check('filtr podle zákona nařízení nevrací (číslo nařízení není číslo zákona)', $prepIds('222/2010'), []);
check('odkaz na připravovaný zákon, po odchodu do Sněmovny do VeKLEP', [Hlidac\View::alertUrl($pa),
    (function () use ($pa) { db()->run("DELETE FROM pripravovane WHERE pid = 'KORNUP000003'"); Hlidac\View::forgetUpcoming(); return Hlidac\View::alertUrl($pa); })()],
    ['pripravovane.php#prep-KORNUP000003', 'https://odok.gov.cz/portal/veklep/material/KORNUP000003/']);
check('zákon se pozná z titulku upozornění, i když materiál už mezi připravovanými není', count($prepIds('300/2013')), 3);
$apiPrep = array_values(array_filter((new Hlidac\Api(db(), new Hlidac\Repo(db()), config()))->alerts(Hlidac\Api::filters(['since' => '2026-09-29', 'min_score' => '50', 'limit' => '500'])),
    fn($a) => $a['id'] === 'prep_review:KORNUP000003'));
check('API: upozornění bez čísla tisku', array_key_exists(0, $apiPrep) ? [$apiPrep[0]['ct'], $apiPrep[0]['skore']] : 'chybí', [null, 50]);

echo "Stránky\n";
foreach (['index.php' => [], 'pripravovane.php' => [], 'data.php' => [], 'mcp.php' => [], 'tisky.php' => ['f' => 'p90'], 'tisk.php' => ['ct' => '90'], 'upozorneni.php' => [], 'metodika.php' => [], 'zmeny.php' => [], 'rss.php' => [], 'revize.php' => ['druh' => 'tisk', 'id' => '221'], 'api.php' => ['tisk' => '221']] as $page => $get) {
    $_GET = $get;
    ob_start();
    try {
        (function (string $file): void {
            require $file;
        })(APP_ROOT . '/' . $page);
        $html = ob_get_clean();
        check("$page se vyrenderuje", strlen($html) > 500 && !str_contains($html, 'Warning') && !str_contains($html, 'Fatal'), true);
        file_put_contents($tmp . '/' . str_replace('.php', '.html', $page), $html);
    } catch (Throwable $ex) {
        ob_end_clean();
        check("$page se vyrenderuje", $ex->getMessage(), 'bez chyby');
    }
}

echo "Náhledy pro sociální sítě\n";
$tiskHtml = (string)@file_get_contents($tmp . '/tisk.html');
check('tisk má og:image s vlastním obrázkem', (bool)preg_match('~<meta property="og:image" content="[^"]*og\.php\?ct=90&amp;v=[0-9a-f]{16}"~', $tiskHtml), true);
check('tisk má twitter:card pro velký obrázek', str_contains($tiskHtml, '<meta name="twitter:card" content="summary_large_image">'), true);
check('tisk má og:url na detail', (bool)preg_match('~<meta property="og:url" content="[^"]*tisk\.php\?ct=90"~', $tiskHtml), true);
check('přehled má obrázek stránky', str_contains((string)@file_get_contents($tmp . '/index.html'), 'og.php?p=prehled'), true);
check('název bez výčtu předkladatelů',
    Hlidac\OgCard::cleanTitle('Návrh poslanců Radka Vondráčka, Davida Pražáka a Filipa Turka na vydání zákona, kterým se mění zákon č. 159/2006 Sb., o střetu zájmů, ve znění pozdějších předpisů'),
    'Zákon, kterým se mění zákon č. 159/2006 Sb., o střetu zájmů');
check('vládní návrh ústavního zákona', Hlidac\OgCard::cleanTitle('Vládní návrh ústavního zákona o bezpečnosti České republiky'), 'Ústavní zákon o bezpečnosti České republiky');
check('krátký název zůstane', Hlidac\OgCard::cleanTitle('Návrh zákona'), 'Návrh zákona');
if (Hlidac\OgCard::available()) {
    $font = APP_ROOT . '/assets/fonts/NotoSans-Bold.ttf';
    $lines = Hlidac\OgCard::fit(str_repeat('slovo ', 200), 40, $font, 600, 2, $complete);
    check('dlouhý text se zalomí a zkrátí', [count($lines), $complete, str_ends_with($lines[1], '…')], [2, false, true]);
    $card = Hlidac\OgCard::forBill(db()->one('SELECT * FROM tisky WHERE ct = 90') + ['reasons_list' => []], config());
    check('karta tisku má skóre a důvod', [$card['score'] !== null, str_starts_with($card['text'], 'Běžné') || str_starts_with($card['text'], 'Proč:')], [true, true]);
    $png = Hlidac\OgCard::cached($card, $tmp . '/og');
    $size = getimagesize($png);
    check('obrázek má 1200 × 630 px', [$size[0] ?? 0, $size[1] ?? 0, $size['mime'] ?? ''], [1200, 630, 'image/png']);
    check('druhé volání použije cache', Hlidac\OgCard::cached($card, $tmp . '/og'), $png);
} else {
    echo "  (GD s FreeType není k dispozici, kreslení obrázku se netestuje)\n";
}
check('záložní obrázek existuje', is_file(APP_ROOT . '/assets/og-default.png'), true);
echo "Reels: fronta úloh generátoru (ReelJobs)\n";
// tabulky vlastní Python (reels/hlidac_reels/schema_reels.sql); test si vytvoří přenositelné kopie
$rjId = match (db()->driver) { 'pgsql' => 'SERIAL PRIMARY KEY', 'mysql' => 'INTEGER PRIMARY KEY AUTO_INCREMENT', default => 'INTEGER PRIMARY KEY' };
$rjTs = db()->driver === 'pgsql' ? 'TIMESTAMPTZ' : 'VARCHAR(40)';
$rjKey = db()->driver === 'mysql' ? '`key`' : 'key';
// focus_pn: v PostgreSQL integer[] jako v produkci, jinde text ve tvaru pole PostgreSQL ('{2312,2313}')
$rjPnType = db()->driver === 'pgsql' ? 'INTEGER[]' : 'TEXT';
db()->run("CREATE TABLE IF NOT EXISTS reel_jobs (id $rjId, source VARCHAR(20) NOT NULL, ct INTEGER, format_hint VARCHAR(40), reason_keys TEXT,
    status VARCHAR(20) NOT NULL DEFAULT 'queued', claimed_at $rjTs, claim_token VARCHAR(64), attempts INTEGER NOT NULL DEFAULT 0, error TEXT,
    set_id BIGINT, created_at $rjTs NOT NULL, finished_at $rjTs, notified_at $rjTs, focus_pn $rjPnType)");
// pro jeden tisk a množinu PN nejvýš jedna aktivní úloha (částečný index jako v produkci; MySQL ho neumí)
if (db()->driver !== 'mysql') {
    db()->run("CREATE UNIQUE INDEX IF NOT EXISTS reel_jobs_active_ct_pn ON reel_jobs (ct, COALESCE(focus_pn, '{}')) WHERE status IN ('queued','claimed') AND ct IS NOT NULL");
}
db()->run("CREATE TABLE IF NOT EXISTS reel_queue (id $rjId, source VARCHAR(20) NOT NULL DEFAULT 'ukazka', ct INTEGER, video_path TEXT NOT NULL,
    video_sha256 VARCHAR(64) NOT NULL, caption TEXT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'queued', format VARCHAR(40),
    created_at $rjTs, published_at $rjTs)");
db()->run("CREATE TABLE IF NOT EXISTS reel_state ($rjKey VARCHAR(100) PRIMARY KEY, value TEXT)");

// tisky s titulkem z rozboru AI (221 s textem „Nahrazuje se“, 311 a 90 bez něj); původní řádky se na konci vrátí
$rjTextBak = db()->all('SELECT * FROM tisk_text WHERE ct IN (90, 221, 311)');
$rjTiskBak = db()->all('SELECT ct, score, reasons, lhuta FROM tisky WHERE ct IN (90, 221, 311)');
db()->run('DELETE FROM tisk_text WHERE ct IN (90, 221, 311)');
foreach ([221 => 'Čl. I: V § 44a odst. 1 se slova „10 let“ Nahrazuje se slovy „4 let“.', 311 => 'V § 21 se doplňuje odstavec 3.', 90 => 'Text návrhu.'] as $rjCt => $rjText) {
    db()->run("INSERT INTO tisk_text (ct, text, chars, signals, ai_score, ai_json, ai_model, fetched_at, ai_at) VALUES (?, ?, ?, '{}', 8, ?, 'claude-haiku-5-5', ?, ?)",
        [$rjCt, $rjText, mb_strlen($rjText), json_encode(['titulek' => "Nález $rjCt", 'perex' => "Perex $rjCt", 'citace' => ["citace $rjCt"]]), date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
}
db()->run('UPDATE tisky SET score = 99, lhuta = 30, reasons = ? WHERE ct = 221', [json_encode([
    ['key' => 'sensitive', 'label' => 'Citlivá oblast', 'points' => 5],
    ['key' => 'deadline_cut', 'label' => 'Zkrácená lhůta pro výbory: 30 dní místo 60', 'points' => 15],
    ['key' => 'ai_bill', 'label' => 'Rozbor textu návrhu', 'points' => 24],
], JSON_UNESCAPED_UNICODE)]);
db()->run('UPDATE tisky SET score = 98 WHERE ct = 311');

$rjClock = strtotime('2026-11-20 12:00:00');
$jobs = new Hlidac\ReelJobs(db(), config(), function () use (&$rjClock): int { return $rjClock; });
$rjAdd = function (string $source, ?int $ct, array $extra = []) use ($jobs, &$rjClock): int {
    db()->run('INSERT INTO reel_jobs (source, ct, status, attempts, claimed_at, claim_token, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [
        $source, $ct, $extra['status'] ?? 'queued', $extra['attempts'] ?? 0,
        isset($extra['claimed_ago']) ? $jobs->stamp($rjClock - $extra['claimed_ago']) : null, $extra['token'] ?? null,
        $jobs->stamp($rjClock - ($extra['age'] ?? 60)),
    ]);
    return (int)db()->value('SELECT MAX(id) FROM reel_jobs');
};
$rjStatus = fn(int $id) => db()->one('SELECT status, attempts, error FROM reel_jobs WHERE id = ?', [$id]);
$rjReset = function (): void { db()->run('DELETE FROM reel_jobs'); db()->run('DELETE FROM reel_queue'); };

$rjReset();
check('prázdná fronta → null', $jobs->claim(), null);

$rjAdd('fresh', 221, ['age' => 3600]);
$rjAdd('manual', 90, ['age' => 60]);
$rjC = $jobs->claim();
check('ruční úloha má přednost před fresh', [$rjC['ct'] ?? null, $rjC['source'] ?? null], [90, 'manual']);
check('claim_token má 32 hex znaků', (bool)preg_match('~^[0-9a-f]{32}$~', (string)($rjC['claim_token'] ?? '')), true);

$rjReset();
$rjOld = $rjAdd('fresh', 221, ['age' => 8 * 86400]);
check('fresh starší 7 dní se nevydá', $jobs->claim(), null);
check('fresh starší 7 dní → expired', $rjStatus($rjOld)['status'], 'expired');
$rjReset();
$rjOldClaimed = $rjAdd('fresh', 221, ['age' => 8 * 86400, 'status' => 'claimed', 'attempts' => 1, 'claimed_ago' => 61 * 60, 'token' => str_repeat('d', 32)]);
check('prošlá fresh vrácená z claimed se nevydá', $jobs->claim(), null);
check('prošlá fresh vrácená z claimed → expired', $rjStatus($rjOldClaimed)['status'], 'expired');

$rjReset();
$rjStale = $rjAdd('manual', 221, ['status' => 'claimed', 'attempts' => 1, 'claimed_ago' => 61 * 60, 'token' => str_repeat('a', 32)]);
$rjC = $jobs->claim();
check('claimed bez výsledku po 61 min se vydá znovu', [$rjC['job_id'] ?? null, (int)$rjStatus($rjStale)['attempts']], [$rjStale, 2]);
check('starý token po novém claimu neplatí', $jobs->checkClaim($rjStale, str_repeat('a', 32)), false);
check('finish se starým tokenem → false', $jobs->finish($rjStale, str_repeat('a', 32)), false);
check('checkClaim s novým tokenem', $jobs->checkClaim($rjStale, (string)($rjC['claim_token'] ?? '')), true);
check('finish s novým tokenem', [$jobs->finish($rjStale, (string)($rjC['claim_token'] ?? '')), db()->value('SELECT status FROM reel_jobs WHERE id = ?', [$rjStale]),
    (int)db()->value('SELECT set_id FROM reel_jobs WHERE id = ?', [$rjStale])], [true, 'done', $rjStale]);
check('hotová úloha: token už neplatí', $jobs->checkClaim($rjStale, (string)($rjC['claim_token'] ?? '')), false);

$rjReset();
$rjDead = $rjAdd('manual', 221, ['status' => 'claimed', 'attempts' => 3, 'claimed_ago' => 61 * 60, 'token' => str_repeat('b', 32)]);
check('po 3 pokusech se nevydá', $jobs->claim(), null);
check('po 3 pokusech → failed', [$rjStatus($rjDead)['status'], $rjStatus($rjDead)['error']], ['failed', 'PC nedokončilo úlohu 3×']);
$rjFresh = $rjAdd('manual', 311, ['status' => 'claimed', 'attempts' => 1, 'claimed_ago' => 59 * 60, 'token' => str_repeat('c', 32)]);
check('claimed před 59 min zůstává vydaná', [$jobs->claim(), $rjStatus($rjFresh)['status']], [null, 'claimed']);

// auto_pick: první nález bez videa; tisk s řádkem v reel_queue se přeskočí
$rjReset();
$rjRepo = new Hlidac\Repo(db());
$rjOrder = array_values(array_map(fn($t) => (int)$t['ct'], array_filter(
    $rjRepo->topFindings((int)Hlidac\Scorer::weights(config())['level_major'], 60), fn($t) => $rjRepo->headline((int)$t['ct']) !== null)));
check('test auto_pick má aspoň dva nálezy s titulkem', count($rjOrder) >= 2 && in_array(221, $rjOrder, true) && in_array(311, $rjOrder, true), true);
$rjVideo = fn(int $ct) => db()->run("INSERT INTO reel_queue (source, ct, video_path, video_sha256, caption, status) VALUES ('ukazka', ?, 'x.mp4', ?, 'popisek', 'published')", [$ct, sha1((string)$ct)]);
$rjVideo($rjOrder[0] ?? 0);
$rjAuto = $rjAdd('auto_pick', null);
$rjC = $jobs->claim();
check('auto_pick vybere nález bez videa', [$rjC['ct'] ?? null, (int)db()->value('SELECT ct FROM reel_jobs WHERE id = ?', [$rjAuto])], [$rjOrder[1] ?? -1, $rjOrder[1] ?? -1]);
check('claim: published prázdné u tisku bez videa', $rjC['published'] ?? null, []);
$rjPubCt = $rjOrder[0] ?? 0;
db()->run("UPDATE reel_queue SET caption = 'Výbory měly 30 dní místo 60.' WHERE ct = ?", [$rjPubCt]);
db()->run("INSERT INTO reel_queue (source, ct, video_path, video_sha256, caption, status) VALUES ('manual', ?, 'y.mp4', ?, 'zamítnuto', 'rejected')", [$rjPubCt, sha1('rej' . $rjPubCt)]);
$rjPub = $rjAdd('manual', $rjPubCt);
$rjC = $jobs->claim();
check('claim: published jen vydaná videa tisku', array_column($rjC['published'] ?? [], 'caption'), ['Výbory měly 30 dní místo 60.']);
db()->run("UPDATE reel_jobs SET status = 'done' WHERE id = ?", [$rjPub]);
foreach (array_slice($rjOrder, 2) as $rjCt) {
    $rjVideo($rjCt);
}
// souběh: tisk mezitím dostala jiná aktivní úloha → unikátní index; přiřazení vrátí 'conflict', ne výjimku
if (db()->driver !== 'mysql') {
    $rjRace = $rjAdd('auto_pick', null);
    $rjAssign = new ReflectionMethod($jobs, 'assignCt');
    try {
        $rjRes = $rjAssign->invoke($jobs, $rjRace, $rjOrder[1] ?? -1);
    } catch (Throwable $ex) {
        $rjRes = get_class($ex) . ': ' . $ex->getMessage();
    }
    check('souběžné přiřazení obsazeného tisku → conflict bez výjimky', [$rjRes, db()->value('SELECT ct FROM reel_jobs WHERE id = ?', [$rjRace])], ['conflict', null]);
    db()->run('DELETE FROM reel_jobs WHERE id = ?', [$rjRace]);
}
$rjAuto2 = $rjAdd('auto_pick', null);
check('auto_pick bez volného nálezu se nevydá', $jobs->claim(), null);
check('auto_pick bez volného nálezu → failed', [$rjStatus($rjAuto2)['status'], $rjStatus($rjAuto2)['error']], ['failed', 'není nález bez videa']);

// fail: zpět do fronty při attempts < 3, jinak failed
$rjReset();
$rjF = $rjAdd('manual', 221);
$rjC = $jobs->claim();
check('fail při 1. pokusu → queued', $jobs->fail($rjF, (string)($rjC['claim_token'] ?? ''), 'render spadl'), 'queued');
check('fail uloží chybu', $rjStatus($rjF)['error'], 'render spadl');
$rjC = $jobs->claim();
$jobs->fail($rjF, (string)($rjC['claim_token'] ?? ''), 'znovu');
$rjC = $jobs->claim();
check('3. pokus vydán', (int)$rjStatus($rjF)['attempts'], 3);
check('fail při 3. pokusu → failed', $jobs->fail($rjF, (string)($rjC['claim_token'] ?? ''), 'potřetí'), 'failed');
check('fail se starým tokenem nic nezmění', [$jobs->fail($rjF, (string)($rjC['claim_token'] ?? ''), 'x'), $rjStatus($rjF)['error']], ['conflict', 'potřetí']);

// karta v2 a údaje claimu
$rjReset();
$rjAdd('manual', 221);
$rjC = $jobs->claim();
$card = $jobs->cardV2(221);
$rjKeys = ['ct', 'nazev', 'faze', 'score', 'title', 'perex', 'quote', 'quote_source', 'verified', 'reasons', 'url', 'data_date'];
check('karta v2 má základní klíče', array_values(array_diff($rjKeys, array_keys($card ?? []))), []);
check('karta v2: titulek a citace z rozboru', [$card['ct'] ?? null, $card['score'] ?? null, $card['title'] ?? null, $card['quote'] ?? null, $card['quote_source'] ?? null, $card['verified'] ?? null],
    [221, 99, 'Nález 221', 'citace 221', 'text návrhu', false]);
check('karta v2: důvody podle bodů sestupně', array_column($card['reasons'] ?? [], 'points'), [24, 15, 5]);
check('karta v2: odkaz a datum dat', [$card['url'] ?? null, $card['data_date'] ?? null], ['https://hlidac.test/tisk.php?ct=221', '2026-11-20']);
check('karta v2: zkrácená lhůta pro výbory', $card['deadline'] ?? null, ['label' => 'Lhůta pro výbory', 'normal' => 60, 'actual' => 30, 'unit' => 'dní']);
check('karta v2: počet PN podle tabulky pn', $card['pn']['count'] ?? 0, (int)db()->value('SELECT COUNT(*) FROM pn WHERE ct = 221'));
check('claim: reason_keys podle bodů', $rjC['reason_keys'] ?? null, ['ai_bill', 'deadline_cut', 'sensitive']);
check('claim: karta v2 přiložena', $rjC['card_v2']['ct'] ?? null, 221);
check('claim: has_amendments při „Nahrazuje se“', $rjC['has_amendments'] ?? null, true);
$rjReset();
$rjAdd('manual', 311);
check('has_amendments bez „nahrazuje se“', $jobs->claim()['has_amendments'] ?? null, false);
check('karta v2 bez titulku → null', $jobs->cardV2(78), null);
$rjNo = $rjAdd('manual', 78);
check('úloha k tisku bez titulku se nevydá → failed', [$jobs->claim(), $rjStatus($rjNo)['status']], [null, 'failed']);

// úloha k vybraným PN (/pn): claim vrací focus_pn a údaje PN pro zadání generátoru
$rjReset();
$rjAdd('manual', 311);
$rjC = $jobs->claim();
check('claim úlohy k celému tisku: focus_pn null', [array_key_exists('focus_pn', $rjC ?? []), $rjC['focus_pn'] ?? null, $rjC['focus_pn_info'] ?? 'x'], [true, null, []]);
// texty PN 2312 a 2313: chybějící řádek se vloží (a na konci smaže), existujícímu se vrátí ai_json
$rjAiBak = db()->all('SELECT id_dokument, ai_json FROM pn_text WHERE id_dokument IN (31312, 31313)');
$rjAiNew = array_values(array_diff([31312, 31313], array_map('intval', array_column($rjAiBak, 'id_dokument'))));
foreach ($rjAiNew as $rjIdd) {
    db()->run("INSERT INTO pn_text (id_dokument, ct, cislo, text, chars, rule_score) VALUES (?, 311, ?, 'x', 1, 0)", [$rjIdd, $rjIdd - 29000]);
}
db()->run('UPDATE pn_text SET ai_json = ? WHERE id_dokument = 31312', [json_encode(['souvisi_s_predmetem' => false])]);
db()->run('UPDATE pn_text SET ai_json = ? WHERE id_dokument = 31313', [json_encode(['titulek' => 'Titulek PN 2313'], JSON_UNESCAPED_UNICODE)]);
$rjReset();
$rjPnJob = $rjAdd('manual', 311);
db()->run('UPDATE reel_jobs SET focus_pn = ? WHERE id = ?', ['{2312,2313}', $rjPnJob]);
// stejný tisk bez PN a s jinou množinou PN běží vedle sebe, stejná množina je duplicita
if (db()->driver !== 'mysql') {
    $rjOther = $rjAdd('manual', 311, ['status' => 'done']);
    $rjDup = null;
    try {
        db()->run("UPDATE reel_jobs SET status = 'queued', focus_pn = ? WHERE id = ?", ['{2312,2313}', $rjOther]);
    } catch (\PDOException $e) {
        $rjDup = 'duplicita';
    }
    check('stejná množina PN k tisku je duplicita', $rjDup, 'duplicita');
    db()->run('DELETE FROM reel_jobs WHERE id = ?', [$rjOther]);
    $rjWhole = $rjAdd('manual', 311, ['age' => 30]);
    check('úloha k celému tisku vedle úlohy k PN', (int)db()->value("SELECT COUNT(*) FROM reel_jobs WHERE ct = 311 AND status = 'queued'"), 2);
    db()->run('DELETE FROM reel_jobs WHERE id = ?', [$rjWhole]);
}
$rjC = $jobs->claim();
check('claim úlohy k PN: focus_pn jako pole čísel', [$rjC['job_id'] ?? null, $rjC['focus_pn'] ?? null], [$rjPnJob, [2312, 2313]]);
check('claim úlohy k PN: údaje PN', $rjC['focus_pn_info'] ?? null, [
    ['cislo' => 2312, 'osoba' => 'Marian Jurečka', 'cas' => '2026-11-13 09:44:24', 'titulek' => null,
     'url_psp' => 'https://www.psp.cz/sqw/sd.sqw?cd=2312&o=10'],
    ['cislo' => 2313, 'osoba' => 'Marian Jurečka', 'cas' => '2026-11-13 09:45:15', 'titulek' => 'Titulek PN 2313',
     'url_psp' => 'https://www.psp.cz/sqw/sd.sqw?cd=2313&o=10'],
]);
check('pole PostgreSQL → čísla', [Hlidac\ReelJobs::parsePnArray('{2376,2377}'), Hlidac\ReelJobs::parsePnArray(null), Hlidac\ReelJobs::parsePnArray('{}'), Hlidac\ReelJobs::parsePnArray('x')],
    [[2376, 2377], null, null, null]);
foreach ($rjAiBak as $rjRow) {
    db()->run('UPDATE pn_text SET ai_json = ? WHERE id_dokument = ?', [$rjRow['ai_json'], $rjRow['id_dokument']]);
}
foreach ($rjAiNew as $rjIdd) {
    db()->run('DELETE FROM pn_text WHERE id_dokument = ?', [$rjIdd]);
}

// heartbeat: vložení i přepsání bez ON CONFLICT
$jobs->heartbeat();
$rjClock += 300;
$jobs->heartbeat();
check('heartbeat uloží čas workeru', [(int)db()->value('SELECT COUNT(*) FROM reel_state'),
    strtotime((string)db()->value("SELECT value FROM reel_state WHERE $rjKey = 'worker_seen_at'"))], [1, $rjClock]);

echo "Reels: rozhraní pro worker na PC (ReelsApi)\n";
$raInbox = $tmp . '/reels-inbox';
mkdir($raInbox, 0775, true);
$raToken = 'worker-' . bin2hex(random_bytes(8));
$raCfg = config();
$raCfg['reels'] = ['worker_token' => $raToken, 'inbox_dir' => $raInbox];
$api = new Hlidac\ReelsApi(db(), $raCfg, function () use (&$rjClock): int { return $rjClock; });
$raH = fn(string $claim = '', array $extra = []): array => ['X-Hlidac-Token' => $raToken, 'X-Claim-Token' => $claim] + $extra;
$raMp4 = fn(int $len = 4096): string => "\x00\x00\x00\x18ftypisom" . str_repeat("\x01", max(0, $len - 12));
$raJson = fn(array $data): string => json_encode($data, JSON_UNESCAPED_UNICODE);
// varianta 1 = šablona v2 (bez formátu), ostatní v3 s formátem; stejné hodnoty posílá worker a čte bot (inbox.py)
$raVariant = fn(int $n, array $over = []): array => $over + ['n' => $n, 'template' => $n === 1 ? 'template_v2' : 'template_v3',
    'format' => $n === 1 ? null : 'zmena', 'caption' => "Popisek $n", 'card' => ['ct' => 221]];

$rjReset();
check('bez tokenu → 403', $api->handle('GET', 'claim', [], '', [])[0], 403);
check('špatný token → 403', $api->handle('GET', 'claim', [], '', ['X-Hlidac-Token' => 'x'])[0], 403);
$raZ = new Hlidac\ReelsApi(db(), ['reels' => ['worker_token' => 'ZMENTE_TOKEN', 'inbox_dir' => $raInbox]] + $raCfg);
check('token ZMENTE v konfiguraci → 403', $raZ->handle('GET', 'claim', [], '', ['X-Hlidac-Token' => 'ZMENTE_TOKEN'])[0], 403);
$raE = new Hlidac\ReelsApi(db(), ['reels' => ['worker_token' => '', 'inbox_dir' => $raInbox]] + $raCfg);
check('prázdný token v konfiguraci → 403', $raE->handle('GET', 'claim', [], '', ['X-Hlidac-Token' => ''])[0], 403);
check('hlavičky bez ohledu na velikost písmen', $api->handle('GET', 'claim', [], '', ['x-hlidac-token' => $raToken]), [200, ['job' => null]]);

$raJob = $rjAdd('manual', 221);
[$raSt, $raRes] = $api->handle('GET', 'claim', [], '', $raH());
$raClaim = (string)($raRes['job']['claim_token'] ?? '');
check('claim → 200 a claim_token 32 hex', [$raSt, (bool)preg_match('~^[0-9a-f]{32}$~', $raClaim), $raRes['job']['job_id'] ?? null], [200, true, $raJob]);

$raDir = $raInbox . '/' . $raJob;
check('video se špatným claim tokenem → 409', $api->handle('PUT', 'video', ['job_id' => (string)$raJob, 'n' => '1'], $raMp4(), $raH(str_repeat('0', 32)))[0], 409);
check('video se špatným claim tokenem: soubor nevznikl', is_file($raDir . '/variant-1.mp4'), false);
check('video bez ftyp → 400', $api->handle('PUT', 'video', ['job_id' => (string)$raJob, 'n' => '1'], str_repeat('x', 100), $raH($raClaim))[0], 400);
check('video > 30 MB podle Content-Length → 413', $api->handle('PUT', 'video', ['job_id' => (string)$raJob, 'n' => '1'], $raMp4(),
    $raH($raClaim, ['Content-Length' => (string)(30 * 1024 * 1024 + 1)]))[0], 413);
check('video > 30 MB podle délky těla → 413', $api->handle('PUT', 'video', ['job_id' => (string)$raJob, 'n' => '1'], $raMp4(30 * 1024 * 1024 + 1), $raH($raClaim))[0], 413);
check('video n mimo 1–3 → 400', $api->handle('PUT', 'video', ['job_id' => (string)$raJob, 'n' => '4'], $raMp4(), $raH($raClaim))[0], 400);
check('po odmítnutých videích žádný soubor', glob($raDir . '/*') ?: [], []);
check('video 1 → 200', $api->handle('PUT', 'video', ['job_id' => (string)$raJob, 'n' => '1'], $raMp4(), $raH($raClaim))[0], 200);
check('video 2 → 200', $api->handle('PUT', 'video', ['job_id' => (string)$raJob, 'n' => '2'], $raMp4(5000), $raH($raClaim))[0], 200);
check('videa uložena bez .part', [is_file($raDir . '/variant-1.mp4'), filesize($raDir . '/variant-2.mp4'), glob($raDir . '/*.part') ?: []], [true, 5000, []]);
check('video s neznámou orientací → 400', $api->handle('PUT', 'video', ['job_id' => (string)$raJob, 'n' => '2', 'orientation' => 'ctverec'], $raMp4(), $raH($raClaim))[0], 400);
check('video na šířku bez ftyp → 400', $api->handle('PUT', 'video', ['job_id' => (string)$raJob, 'n' => '2', 'orientation' => 'landscape'], str_repeat('x', 100), $raH($raClaim))[0], 400);
check('video na šířku > 30 MB → 413', $api->handle('PUT', 'video', ['job_id' => (string)$raJob, 'n' => '2', 'orientation' => 'landscape'], $raMp4(30 * 1024 * 1024 + 1), $raH($raClaim))[0], 413);
check('video na šířku se špatným claim tokenem → 409', $api->handle('PUT', 'video', ['job_id' => (string)$raJob, 'n' => '2', 'orientation' => 'landscape'], $raMp4(), $raH(str_repeat('0', 32)))[0], 409);
check('video 2 na šířku → 200', $api->handle('PUT', 'video', ['job_id' => (string)$raJob, 'n' => '2', 'orientation' => 'landscape'], $raMp4(6000), $raH($raClaim)), [200, ['ok' => true, 'bytes' => 6000, 'orientation' => 'landscape']]);
check('video na šířku vedle videa na výšku', [filesize($raDir . '/variant-2-sirka.mp4'), filesize($raDir . '/variant-2.mp4')], [6000, 5000]);
check('orientation=portrait = video na výšku', $api->handle('PUT', 'video', ['job_id' => (string)$raJob, 'n' => '1', 'orientation' => 'portrait'], $raMp4(), $raH($raClaim))[0], 200);

check('result s 1 variantou → 400', $api->handle('POST', 'result', [], $raJson(['job_id' => $raJob, 'variants' => [$raVariant(1)]]), $raH($raClaim))[0], 400);
check('result s 1 variantou: manifest nevznikl', is_file($raDir . '/manifest.json'), false);
check('result s variantou bez videa → 400', $api->handle('POST', 'result', [], $raJson(['job_id' => $raJob, 'variants' => [$raVariant(1), $raVariant(3)]]), $raH($raClaim))[0], 400);
$raLong = $raVariant(2);
$raLong['caption'] = str_repeat('ž', 2121);
check('result s popiskem > 2120 znaků → 400', $api->handle('POST', 'result', [], $raJson(['job_id' => $raJob, 'variants' => [$raVariant(1), $raLong]]), $raH($raClaim))[0], 400);
$raBad = fn(array $v2): int => $api->handle('POST', 'result', [], $raJson(['job_id' => $raJob, 'variants' => [$raVariant(1), $v2]]), $raH($raClaim))[0];
check('result: v2 s formátem → 400', $raBad($raVariant(2, ['template' => 'template_v2', 'format' => 'zmena'])), 400);
check('result: v3 bez formátu → 400', $raBad($raVariant(2, ['format' => null])), 400);
check('result: neznámá šablona → 400', $raBad($raVariant(2, ['template' => 'v2'])), 400);
check('result: v3 s neznámým formátem → 400', $raBad($raVariant(2, ['format' => 'zkratka'])), 400);
check('result: odmítnuté šablony nezapíšou manifest', is_file($raDir . '/manifest.json'), false);
check('result se špatným claim tokenem → 409', $api->handle('POST', 'result', [], $raJson(['job_id' => $raJob, 'variants' => [$raVariant(1), $raVariant(2)]]), $raH(str_repeat('0', 32)))[0], 409);
check('GET ?action=result → 400', $api->handle('GET', 'result', [], '', $raH($raClaim))[0], 400);
check('neznámá akce → 400', $api->handle('POST', 'smaz', [], '', $raH($raClaim))[0], 400);
[$raSt, $raRes] = $api->handle('POST', 'result', [], $raJson(['job_id' => $raJob, 'variants' => [$raVariant(1), $raVariant(2)]]), $raH($raClaim));
$raManifest = json_decode((string)@file_get_contents($raDir . '/manifest.json'), true);
check('result se 2 variantami → 200, úloha done', [$raSt, $rjStatus($raJob)['status']], [200, 'done']);
check('manifest: úloha, tisk, zdroj a varianty', [$raManifest['job_id'] ?? null, $raManifest['ct'] ?? null, $raManifest['source'] ?? null,
    array_column($raManifest['variants'] ?? [], 'n'), $raManifest['variants'][1]['caption'] ?? null, $raManifest['variants'][0]['card'] ?? null, glob($raDir . '/*.part') ?: []],
    [$raJob, 221, 'manual', [1, 2], 'Popisek 2', ['ct' => 221], []]);
check('manifest: v2 s format null (přijato), v3 s formátem', [array_key_exists('format', $raManifest['variants'][0] ?? []), $raManifest['variants'][0]['template'] ?? null,
    $raManifest['variants'][0]['format'] ?? null, $raManifest['variants'][1]['template'] ?? null, $raManifest['variants'][1]['format'] ?? null],
    [true, 'template_v2', null, 'template_v3', 'zmena']);
check('manifest: šířka jen u varianty s nahraným videem na šířku', array_column($raManifest['variants'] ?? [], 'landscape'), [false, true]);

// výsledek po vypršení claimu: úloha se vrátila do fronty a vydala jinému claimu → 409, nic se nezapíše
$rjReset();
$raJob2 = $rjAdd('manual', 221);
$raOld = (string)($api->handle('GET', 'claim', [], '', $raH())[1]['job']['claim_token'] ?? '');
$api->handle('PUT', 'video', ['job_id' => (string)$raJob2, 'n' => '1'], $raMp4(), $raH($raOld));
$api->handle('PUT', 'video', ['job_id' => (string)$raJob2, 'n' => '2'], $raMp4(), $raH($raOld));
$rjClock += 61 * 60;
$raNew = (string)($api->handle('GET', 'claim', [], '', $raH())[1]['job']['claim_token'] ?? '');
check('nový claim po vypršení', [$raNew !== '' && $raNew !== $raOld, $rjStatus($raJob2)['status']], [true, 'claimed']);
check('nový claim smaže videa předchozího pokusu', is_file($raInbox . '/' . $raJob2 . '/variant-1.mp4'), false);
check('result se starým tokenem po vypršení → 409', $api->handle('POST', 'result', [], $raJson(['job_id' => $raJob2, 'variants' => [$raVariant(1), $raVariant(2)]]), $raH($raOld))[0], 409);
check('result se starým tokenem: manifest nevznikl', is_file($raInbox . '/' . $raJob2 . '/manifest.json'), false);
check('fail se starým tokenem → 409', $api->handle('POST', 'fail', [], $raJson(['job_id' => $raJob2, 'error' => 'x']), $raH($raOld))[0], 409);

// fail: úloha zpět do fronty, složka úlohy smazaná
[$raSt, $raRes] = $api->handle('POST', 'fail', [], $raJson(['job_id' => $raJob2, 'error' => 'render spadl']), $raH($raNew));
check('fail → queued a složka smazaná', [$raSt, $raRes, $rjStatus($raJob2)['status'], is_dir($raInbox . '/' . $raJob2)], [200, ['status' => 'queued'], 'queued', false]);

check('heartbeat → ok', $api->handle('POST', 'heartbeat', [], '', $raH()), [200, ['ok' => true]]);
$raMissing = new Hlidac\ReelsApi(db(), ['reels' => ['worker_token' => $raToken, 'inbox_dir' => $tmp . '/neni-inbox']] + $raCfg);
[$raSt, $raRes] = $raMissing->handle('PUT', 'video', ['job_id' => '1', 'n' => '1'], $raMp4(), $raH('x'));
check('chybějící mezisložka → 500 s hláškou', [$raSt, str_contains((string)($raRes['error'] ?? ''), 'inbox_dir')], [500, true]);
// bez mezisložky se úloha nevydá (jinak by visela 60 min a generátor běžel zbytečně), fail ale projde
$rjReset();
$raJob3 = $rjAdd('manual', 221);
[$raSt, $raRes] = $raMissing->handle('GET', 'claim', [], '', $raH());
check('claim bez mezisložky → 500, úloha zůstane queued', [$raSt, str_contains((string)($raRes['error'] ?? ''), 'inbox_dir'), $rjStatus($raJob3)['status']], [500, true, 'queued']);
$raClaim3 = (string)($api->handle('GET', 'claim', [], '', $raH())[1]['job']['claim_token'] ?? '');
[$raSt, $raRes] = $raMissing->handle('POST', 'fail', [], $raJson(['job_id' => $raJob3, 'error' => 'disk']), $raH($raClaim3));
check('fail bez mezisložky → 200 a úloha zpět ve frontě', [$raSt, $raRes, $rjStatus($raJob3)['status']], [200, ['status' => 'queued'], 'queued']);
$raRo = $tmp . '/reels-inbox-ro';
mkdir($raRo, 0555, true);
if (!is_writable($raRo)) { // root (Docker) zapisuje i do 0555, tam se kontrola přeskočí
    $raRoApi = new Hlidac\ReelsApi(db(), ['reels' => ['worker_token' => $raToken, 'inbox_dir' => $raRo]] + $raCfg);
    check('claim do nezapisovatelné mezisložky → 500', $raRoApi->handle('GET', 'claim', [], '', $raH())[0], 500);
}

echo "Reels: snímek hlavních nálezů (ReelsSnapshot)\n";
db()->run('UPDATE tisky SET score = 99, reasons = ? WHERE ct = 221', [json_encode([
    ['key' => 'sensitive', 'label' => 'Citlivá oblast', 'points' => 5],
    ['key' => 'deadline_cut', 'label' => 'Zkrácená lhůta pro výbory: 30 dní místo 60', 'points' => 15],
    ['key' => 'ai_bill', 'label' => 'Rozbor textu návrhu', 'points' => 24],
], JSON_UNESCAPED_UNICODE)]);
$rsLevel = (int)Hlidac\Scorer::weights(config())['level_major'];
$rsCount = Hlidac\ReelsSnapshot::write(db(), new Hlidac\Repo(db()), $rsLevel);
$rsSnap = json_decode((string)db()->value("SELECT value FROM reel_state WHERE $rjKey = 'top_findings'"), true);
check('snímek: tisk 221 s klíči důvodů podle bodů', $rsSnap['items']['221'] ?? null, ['ai_bill', 'deadline_cut', 'sensitive']);
check('snímek: počet tisků a čas s pásmem', [$rsCount > 0, $rsCount === count($rsSnap['items'] ?? []), (bool)preg_match('~^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$~', (string)($rsSnap['at'] ?? ''))],
    [true, true, true]);
$rsNoHeadline = array_filter(array_keys($rsSnap['items'] ?? []), fn($c) => (new Hlidac\Repo(db()))->headline((int)$c) === null);
check('snímek: jen tisky s titulkem', $rsNoHeadline, []);
// druhý zápis přepíše řádek (bez ON CONFLICT)
Hlidac\ReelsSnapshot::write(db(), new Hlidac\Repo(db()), $rsLevel);
check('snímek: druhý zápis bez duplicity', (int)db()->value("SELECT COUNT(*) FROM reel_state WHERE $rjKey = 'top_findings'"), 1);

// úklid
$rjReset();
db()->run('DELETE FROM reel_state');
db()->run('DELETE FROM tisk_text WHERE ct IN (90, 221, 311)');
foreach ($rjTextBak as $rjRow) {
    db()->insertMany('tisk_text', array_keys($rjRow), [$rjRow]);
}
foreach ($rjTiskBak as $rjRow) {
    db()->run('UPDATE tisky SET score = ?, reasons = ?, lhuta = ? WHERE ct = ?', [$rjRow['score'], $rjRow['reasons'], $rjRow['lhuta'], $rjRow['ct']]);
}

echo "Historie běhů synchronizace (sync_beh)\n";
// předchozí běhy testu: každý má řádek s výsledkem a bez chyby
$sbRows = db()->all('SELECT * FROM sync_beh ORDER BY id');
check('sync_beh: řádek za každý běh', count($sbRows) >= 4, true);
check('sync_beh: dokončené běhy ok s výsledkem',
    array_values(array_unique(array_map(fn($r) => [(int)$r['ok'], $r['konec'] !== null, $r['chyba'],
        is_array(json_decode((string)$r['vysledek'], true))], $sbRows), SORT_REGULAR)), [[1, true, null, true]]);
check('sync_beh: výsledek obsahuje import', isset(json_decode((string)end($sbRows)['vysledek'], true)['import']), true);
check('sync_beh: čas začátku ve tvaru Y-m-d H:i:s', (bool)preg_match('~^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$~', (string)end($sbRows)['zacatek']), true);
check('sync_beh: latest = poslední úspěšný', Hlidac\SyncRuns::lastOk(db())['id'] ?? null, end($sbRows)['id']);
// nedokončený běh (proces skončil zvenku) a běh starší než 90 dní
$sbMax = (int)db()->value('SELECT MAX(id) FROM sync_beh');
db()->run('INSERT INTO sync_beh (id, zacatek) VALUES (?, ?)', [$sbMax + 1, date('Y-m-d H:i:s', time() - 600)]);
db()->run('INSERT INTO sync_beh (id, zacatek, konec, ok) VALUES (?, ?, ?, 1)', [$sbMax + 2, date('Y-m-d H:i:s', time() - 91 * 86400), date('Y-m-d H:i:s', time() - 91 * 86400)]);
Hlidac\Sync::run();
$sbUnfinished = db()->one('SELECT ok, konec, chyba FROM sync_beh WHERE id = ?', [$sbMax + 1]);
check('sync_beh: nedokončený běh označen', [(int)$sbUnfinished['ok'], $sbUnfinished['konec'] !== null, $sbUnfinished['chyba']],
    [0, true, Hlidac\SyncRuns::UNFINISHED]);
check('sync_beh: běh starší 90 dní smazán', db()->value('SELECT id FROM sync_beh WHERE id = ?', [$sbMax + 2]), null);
check('sync_beh: nový běh dokončen', (int)db()->value('SELECT ok FROM sync_beh WHERE id = (SELECT MAX(id) FROM sync_beh)'), 1);
// chyba běhu: zapíše se ok = 0 a text chyby, výjimka jde dál
$sbZip = $tmp . '/src/tisky.zip';
rename($sbZip, $sbZip . '.bak');
$sbError = null;
try {
    Hlidac\Sync::run();
} catch (Throwable $ex) {
    $sbError = $ex->getMessage();
}
rename($sbZip . '.bak', $sbZip);
$sbLast = db()->one('SELECT ok, konec, chyba FROM sync_beh WHERE id = (SELECT MAX(id) FROM sync_beh)');
check('sync_beh: chyba běhu prošla dál', $sbError !== null, true);
check('sync_beh: chybný běh ok = 0 s textem chyby', [(int)$sbLast['ok'], $sbLast['konec'] !== null, $sbLast['chyba'] !== null && $sbLast['chyba'] !== ''],
    [0, true, true]);
check('sync_beh: lastOk přeskočí chybný běh', (int)(Hlidac\SyncRuns::lastOk(db())['ok'] ?? 0), 1);

echo "Stránky historie tisků (HistoryHtml, díra 6)\n";
// uložená stránka historie tisku 311 z psp.cz (windows-1250) ze 19. 11. 2026
$hhRaw = (string)file_get_contents(__DIR__ . '/html/historie-311.html');
$hhParsed = Hlidac\HistoryHtml::parse(iconv('CP1250', 'UTF-8', $hhRaw), '2026-11-20 12:00:00');
check('historie: kroky z LLLP (id_hist, přechod, datum z textu záznamu)', array_map(fn($k) => [$k['id_hist'], $k['id_prechod'], $k['datum']], $hhParsed['kroky']), [
    [212169, 2116, '2026-09-22 00:00:00'], [212263, 2011, '2026-10-01 00:00:00'], [212296, 2013, '2026-10-13 00:00:00'],
    [212584, 22, '2026-11-13 00:00:00'], [212587, 2030, '2026-11-20 12:00:00']]);
check('historie: PN z tabulky (id dokumentu ze jména souboru, autor, čas podání)', $hhParsed['pn'][0] ?? null,
    ['id_dokument' => 31015, 'cislo' => 2015, 'id_osoba' => 6687, 'osoba' => 'Jan Jakob', 'cas' => '2026-10-15 11:53:35']);
check('historie: PN s id_dokument a číslem', count($hhParsed['pn']) > 10 && count(array_unique(array_column($hhParsed['pn'], 'id_dokument'))) === count($hhParsed['pn']), true);
// nový krok, který noční data ještě nemají (přechod 2030 jako u 212587)
$hhExtra = iconv('UTF-8', 'CP1250', '<!-- LLLP 212999 2030 3000 --><ul class="document-log"><!-- EndR 212999, 30 (30) --><li>Nové pozměňovací návrhy rozeslány 20.&nbsp;11.&nbsp;2026.</li><!--/ Document Log -->');
$hhUrls = [];
$hhCfg = ['history_html' => ['enabled' => true, 'per_run' => 5, 'days' => 14, 'delay_ms' => 0]];
$hhFetch = function (string $url) use (&$hhUrls, $hhRaw, $hhExtra): string {
    $hhUrls[] = $url;
    // jiné tisky: stránka bez kroků a PN
    return str_ends_with($url, '&t=311') ? $hhExtra . $hhRaw : '<html></html>';
};
$hhNow = strtotime('2026-11-20 12:00:00');
$hhSource = new Hlidac\Source('', $tmp . '/cache-hh', $tmp . '/src');
$hh = new Hlidac\HistoryHtml(db(), $hhSource, $hhCfg, $hhFetch);
$hhCands = array_column($hh->candidates(5, 14, $hhNow), 'ct');
check('historie: kandidáti = tisky se změnou za 14 dní (bez vyhlášených)', [in_array(311, $hhCands, true), array_intersect($hhCands, [78, 90])], [true, []]);
db()->run("UPDATE tisky SET faze = '3. čtení' WHERE ct = 78"); // vrátí další běh Importeru
check('historie: pořad běžící schůze má přednost', array_column($hh->candidates(5, 14, strtotime('2026-04-20 12:00:00')), 'ct')[0] ?? null, 78);
db()->run("UPDATE tisky SET faze = 'Vyhlášeno' WHERE ct = 78");
$hhPnKnown = (int)db()->value('SELECT COUNT(*) FROM pn WHERE ct = 311');
$hhPnNew = count(array_filter($hhParsed['pn'], fn($p) => db()->value('SELECT 1 FROM pn WHERE id_dokument = ?', [$p['id_dokument']]) === null));
$hhStats = $hh->run($hhNow);
check('historie: stažené stránky kandidátů', $hhUrls, array_map(fn($ct) => 'https://www.psp.cz/sqw/historie.sqw?o=10&t=' . $ct, $hhCands));
check('historie: uložen nový krok a PN, které otevřená data nemají', [$hhStats['stazeno'], $hhStats['kroky'], $hhStats['pn'] === $hhPnNew, $hhPnNew > 0],
    [count($hhCands), 1, true, true]);
$hhImport = (new Hlidac\Importer(db(), $hhSource))->run();
check('historie: Importer přimíchá krok a PN', [$hhImport['html_kroky'], $hhImport['html_pn'], (int)db()->value('SELECT COUNT(*) FROM pn WHERE ct = 311')],
    [1, $hhPnNew, $hhPnKnown + $hhPnNew]);
$hhStep = db()->one('SELECT ct, datum, stav FROM kroky WHERE id_hist = 212999');
$hhStepRef = db()->one('SELECT stav FROM kroky WHERE id_hist = 212587');
check('historie: krok s datem a stavem podle přechodu', [$hhStep['ct'] ?? null, $hhStep['datum'] ?? null, ($hhStep['stav'] ?? null) === $hhStepRef['stav']],
    [311, '2026-11-20 00:00:00', true]);
$hhPnRow = db()->one('SELECT cislo, osoba, cas FROM pn WHERE id_dokument = 31040');
check('historie: PN se jménem autora a časem podání', [$hhPnRow['cislo'] ?? null, ($hhPnRow['osoba'] ?? '') !== '', $hhPnRow['cas'] ?? null], [2040, true, '2026-10-19 16:18:44']);
// druhý běh nic nezdvojí; záznam, který už je v otevřených datech, a neověřený starší než týden zmizí
$hh->run($hhNow);
db()->run("INSERT INTO html_kroky (id_hist, id_tisk, ct, id_prechod, datum, seen_at) VALUES (212587, 43433, 311, 2030, NULL, ?)", [date('Y-m-d H:i:s')]);
db()->run("INSERT INTO html_pn (id_dokument, cislo, ct, id_osoba, osoba, cas, seen_at) VALUES (39999, 2999, 311, NULL, 'X', NULL, ?)", [date('Y-m-d H:i:s', time() - 8 * 86400)]);
$hhImport2 = (new Hlidac\Importer(db(), $hhSource))->run();
check('historie: bez duplicit po dalším běhu', [(int)db()->value('SELECT COUNT(*) FROM kroky WHERE id_hist IN (212587, 212999)'),
    (int)db()->value('SELECT COUNT(*) FROM pn WHERE ct = 311')], [2, $hhPnKnown + $hhPnNew]);
check('historie: potvrzený a starý záznam smazán', [db()->value('SELECT 1 FROM html_kroky WHERE id_hist = 212587'), db()->value('SELECT 1 FROM html_pn WHERE id_dokument = 39999')],
    [null, null]);
// výpadek psp.cz: chyba se počítá, běh nespadne
$hhDown = new Hlidac\HistoryHtml(db(), $hhSource, $hhCfg, function (string $url): string {
    throw new RuntimeException('HTTP 503');
});
check('historie: chyba stažení se jen započítá, po 3 za sebou konec', $hhDown->run($hhNow)['chyby'], min(3, count($hhCands)));
check('historie: vypnuto v konfiguraci', (new Hlidac\HistoryHtml(db(), $hhSource, ['history_html' => ['enabled' => false]], $hhFetch))->run($hhNow), ['stazeno' => 0]);
// úklid: tabulky a data bez záznamů ze stránek historie (i z pn, jinak by Importer viděl propad počtu PN)
foreach (db()->all('SELECT id_dokument FROM html_pn') as $hhRow) {
    db()->run('DELETE FROM pn WHERE id_dokument = ?', [$hhRow['id_dokument']]);
}
db()->run('DELETE FROM kroky WHERE id_hist = 212999');
db()->run('DELETE FROM html_kroky');
db()->run('DELETE FROM html_pn');
(new Hlidac\Importer(db(), $hhSource))->run();
check('historie: po úklidu původní počet PN', (int)db()->value('SELECT COUNT(*) FROM pn WHERE ct = 311'), $hhPnKnown);

// čekání na databázi před synchronizací (bin/sync.php): opakuje spojení, dokud se nepodaří nebo nevyprší čas
$wdTries = 0;
$wdOk = Hlidac\Db::waitUntilReady(static function () use (&$wdTries): void {
    if (++$wdTries < 3) {
        throw new PDOException('connection refused');
    }
}, 5, 0);
check('čekání na DB: třetí pokus uspěje', [$wdOk, $wdTries], [true, 3]);
$wdTries = 0;
$wdOk = Hlidac\Db::waitUntilReady(static function () use (&$wdTries): void {
    $wdTries++;
    throw new PDOException('connection refused');
}, 0, 0);
check('čekání na DB: po vypršení času vrátí false', [$wdOk, $wdTries], [false, 1]);
check('čekání na DB: skutečná databáze odpoví hned', Hlidac\Db::waitUntilReady(
    static fn() => new Hlidac\Db(config('db_dsn'), config('db_user'), config('db_pass')), 0, 0), true);
$wdOther = '';
try {
    Hlidac\Db::waitUntilReady(static function (): void {
        throw new RuntimeException('jiná chyba');
    }, 5, 0);
} catch (RuntimeException $ex) {
    $wdOther = $ex->getMessage();
}
check('čekání na DB: jiné chyby než spojení nepolyká', $wdOther, 'jiná chyba');

echo "\nVýstupy: $tmp\n";
echo $failures === 0 ? "VŠE OK\n" : "CHYB: $failures\n";
exit($failures === 0 ? 0 : 1);
