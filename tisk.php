<?php
// Detail tisku: příznaky, průběh projednávání, pozměňovací návrhy a jejich předkladatelé.

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

use Hlidac\Analyzer;
use Hlidac\OgCard;
use Hlidac\Repo;
use Hlidac\Revisions;
use Hlidac\View;

$repo = new Repo(db());
$ct = (int)($_GET['ct'] ?? 0);
$t = $ct > 0 ? $repo->bill($ct) : null;

if ($t === null) {
    http_response_code(404);
    View::header('Tisk nenalezen', 'tisky');
    echo '<section class="card pad"><h1>Tisk nenalezen</h1><p><a href="tisky.php">Zpět na seznam tisků</a></p></section>';
    View::footer();
    exit;
}

$obdobi = $repo->obdobi();
$steps = $repo->steps($ct);
$pn = $repo->amendments($ct);
$authors = $repo->amendmentAuthors($ct);
$alerts = $repo->alerts(10, 0, $ct);
$texts = $repo->pnTexts($ct);
$pnOutcomes = $repo->pnOutcomes($ct);
$billText = $repo->billText($ct);
$votes = $repo->votes($ct);
$prep = $repo->prep($ct);
// hlasování, kterým skončil krok projednávání (popis kroku místo obecného názvu)
$stepByVote = [];
foreach ($steps as $k) {
    if (!empty($k['id_hlasovani'])) {
        $stepByVote[(int)$k['id_hlasovani']] = $k['stav'] . ($k['akce'] !== '' ? ' – ' . $k['akce'] : '');
    }
}
// počty uložených verzí textu a rozborů (odkaz „Historie“ jen u víc než jedné verze)
$billRev = Revisions::counts(db(), 'tisk', null, $ct)[$ct] ?? ['text' => 0, 'ai' => 0];
$pnRev = Revisions::counts(db(), 'pn', null, $ct);
$laws = array_filter(explode(',', (string)$t['zakony']));

// délka projednávání od předložení po poslední krok
$days = null;
if ($t['predlozeno'] && $t['posledni']) {
    $days = (int)round((strtotime($t['posledni']) - strtotime($t['predlozeno'])) / 86400);
}

// barevné rozlišení důležitých kroků na časové ose
$dotClass = function (array $k): string {
    $typ = (int)$k['typ_stavu'];
    if (in_array($typ, Analyzer::STAV_P90, true)) {
        return 'dot-fast';
    }
    if (in_array((int)$k['akce_id'], Analyzer::AKCE_ZKRACENI, true)) {
        return 'dot-cut';
    }
    if (in_array($typ, [64, 11, 8, 12], true)) {
        return 'dot-main';
    }
    return '';
};

// náhled pro sociální sítě: obrázek s aktuálním skóre (v = otisk obsahu, ať sítě po změně skóre stáhnou nový)
$ogCard = OgCard::forBill($t, config());
$ogDesc = 'Skóre závažnosti postupu ' . (int)$t['score'] . '/100. ' . $ogCard['text'] . ' ' . $t['uplny_nazev'];
View::header('Tisk ' . $ct . ' – ' . $t['nazev'], 'tisky', mb_substr($ogDesc, 0, 300), [
    'url' => 'tisk.php?ct=' . $ct,
    'image' => 'og.php?ct=' . $ct . '&v=' . OgCard::cacheKey($ogCard),
    'title' => 'Tisk ' . $ct . ': ' . OgCard::cleanTitle((string)($t['uplny_nazev'] ?: $t['nazev'])),
    'alt' => 'Tisk ' . $ct . ', skóre ' . (int)$t['score'] . ' ze 100. ' . $ogCard['text'],
]);
?>
<p class="crumbs"><a href="tisky.php">Tisky</a> › Tisk <?= $ct ?></p>
<section class="detail-head">
  <span class="chips"><?= View::scoreBadge((int)$t['score']) ?><?= View::chips($t['flags']) ?></span>
  <h1><?= e($t['uplny_nazev'] ?: $t['nazev']) ?></h1>
  <p class="muted">Tisk <?= $ct ?> · <?= e($t['typ_navrhu']) ?> návrh · předkladatel <?= e($t['navrhovatel']) ?> · předloženo <?= e(formatDate($t['predlozeno'])) ?><?= $t['sbirka'] ? ' · vyhlášeno jako ' . e($t['sbirka']) . ' Sb.' : '' ?></p>
  <div class="actions">
    <a class="btn" href="<?= e(View::pspTiskUrl($ct, $obdobi)) ?>">Otevřít na psp.cz</a>
  </div>
</section>

<section class="kpis" aria-label="Klíčová čísla">
  <div class="card kpi">
    <div class="kpi-label">Pozměňovací návrhy</div>
    <div class="kpi-value"><?= count($pn) ?></div>
    <div class="kpi-note">od <?= count($authors) ?> <?= count($authors) === 1 ? 'poslance' : 'poslanců' ?></div>
  </div>
  <div class="card kpi">
    <div class="kpi-label">Fáze</div>
    <div class="kpi-value kpi-small"><?= e($t['faze']) ?></div>
    <div class="kpi-note">poslední změna <?= e(formatDate($t['posledni'])) ?></div>
  </div>
  <div class="card kpi">
    <div class="kpi-label">Projednávání trvá</div>
    <div class="kpi-value"><?= $days !== null ? $days . ' dní' : '—' ?></div>
    <div class="kpi-note">od předložení po poslední krok</div>
  </div>
  <div class="card kpi">
    <div class="kpi-label">Zákony v názvu</div>
    <div class="kpi-value"><?= count($laws) ?: '—' ?></div>
    <div class="kpi-note"><?= $laws ? implode(', ', array_map(fn($l) => '<a href="' . e(Hlidac\Laws::url($l)) . '">' . e($l) . ' Sb.</a>', array_slice($laws, 0, 3))) : 'nový zákon nebo souhrnná novela' ?></div>
  </div>
</section>

<section class="card pad" aria-label="Proč toto skóre">
  <h2>Proč skóre <?= (int)$t['score'] ?> ze 100</h2>
  <?php if ($t['reasons_list'] === []): ?>
    <p class="muted">Žádné procedurální příznaky – běžné projednávání.</p>
  <?php else: ?>
    <?= View::reasons($t['reasons_list']) ?>
  <?php endif; ?>
  <?php $review = $repo->reviewStatus($ct)[$ct] ?? ['total' => 0, 'done' => 0]; ?>
  <?php if ($review['total'] > 0 && $review['done'] < $review['total']): ?>
    <p class="small"><strong>Rozbor textů není úplný:</strong> posouzeno <?= (int)$review['done'] ?> z <?= (int)$review['total'] ?> textů (návrh zákona a pozměňovací návrhy). Neposouzené texty do skóre zatím nepřispívají, nízké skóre proto neznamená „bez problému“.</p>
  <?php endif; ?>
  <p class="muted small">Skóre hodnotí postup projednávání a formu pozměňovacích návrhů, ne politický obsah zákona. <a href="metodika.php">Jak skóre počítáme.</a> <a href="<?= e((string)config('issues_url', '')) ?>">Nesouhlasíte? Napište nám.</a></p>
</section>

<?php if ($prep !== null): ?>
<?php
  // příprava u vlády: obvyklá délka připomínkového řízení u vládních návrhů je 4 týdny (data 2024–2026: 25–35 dní)
  $gov = $prep['druh'] === 'vladni';
  $days = $prep['dni'] !== null ? (int)$prep['dni'] : null;
?>
<section class="card pad prep" aria-label="Příprava návrhu u vlády">
  <h2><?= $gov ? 'Příprava návrhu u vlády' : 'Stanovisko vlády k návrhu' ?></h2>
  <span class="chips">
    <?php if ($days === null && !$gov): ?><span class="chip chip-cut">Vláda si připomínky nevyžádala</span>
    <?php elseif ($days === null && !$prep['znovu'] && (int)$t['druh'] !== 3): ?><span class="chip chip-surge">Připomínkové řízení nedohledáno</span>
    <?php elseif ($gov && $days < 21): ?><span class="chip chip-cut">Připomínky <?= $days ?> dní (obvykle 4 týdny)</span>
    <?php elseif (!$gov): ?><span class="chip chip-surge">Připomínky ke stanovisku <?= $days ?> dní</span><?php endif; ?>
    <?php if ($prep['mimo_plan']): ?><span class="chip chip-surge">Mimo plán legislativních prací</span><?php endif; ?>
    <?php if ($prep['vyjimka']): ?><span class="chip chip-surge">Udělena výjimka</span><?php endif; ?>
  </span>
  <dl>
    <?php if ($days !== null): ?>
      <dt><?= $gov ? 'Připomínkové řízení' : 'Připomínky' ?></dt>
      <dd><?= e(formatDate($prep['pripominky_od'])) ?> – <?= e(formatDate($prep['pripominky_do'])) ?> · <strong><?= $days ?> dní</strong>, <?= (int)$prep['mist'] ?> připomínkových míst</dd>
      <?php if ($gov): ?><dt></dt><dd><div class="bar" title="<?= $days ?> z obvyklých 28 dní"><span style="width:<?= min(100, (int)round($days / 28 * 100)) ?>%"></span></div><span class="muted small">obvyklá délka u vládních návrhů: 4 týdny</span></dd><?php endif; ?>
    <?php else: ?>
      <dt><?= $gov ? 'Připomínkové řízení' : 'Připomínky' ?></dt>
      <dd><?php if (!$gov): ?>vláda si k návrhu připomínky nevyžádala<?php elseif ($prep['znovu']): ?>u tohoto materiálu není; jde o znovu předložený návrh, připomínky mohly proběhnout u předchozí verze<?php elseif ((int)$t['druh'] === 3): ?>státní rozpočet má vlastní postup přípravy<?php else: ?>v otevřených datech Úřadu vlády (od roku 2023) jsme ho k tomuto materiálu nenašli<?php endif; ?></dd>
    <?php endif; ?>
    <?php if (!empty($prep['vlada'])): ?><dt><?= $gov ? 'Vláda schválila' : 'Vláda projednala' ?></dt><dd><?= e(formatDate($prep['vlada'])) ?></dd><?php endif; ?>
    <?php if ($gov && trim((string)$prep['duvod']) !== ''): ?><dt>Důvod předložení</dt><dd><?= e((string)$prep['duvod']) ?></dd><?php endif; ?>
    <?php if (trim((string)$prep['poznamka']) !== ''): ?><dt>Poznámka</dt><dd class="small"><?= e((string)$prep['poznamka']) ?></dd><?php endif; ?>
  </dl>
  <p class="muted small"><?= $gov ? '' : 'Text poslaneckého návrhu nepřipravovalo ministerstvo; připomínky se týkají stanoviska vlády, ne úprav návrhu. ' ?>Zdroj: <a href="<?= e(Hlidac\GovPrep::url((string)$prep['pid'])) ?>">materiál ve VeKLEP</a> (otevřená data Úřadu vlády), kde je i stanovisko Legislativní rady vlády a připomínky. Do skóre nevstupuje.</p>
</section>
<?php endif; ?>

<?php if ($billText !== null && ($billText['ai'] !== null || array_diff_key($billText['signals'], ['laws' => true]) !== [] || Revisions::hasHistory($billRev))): ?>
<section class="card pad" aria-label="Rozbor textu návrhu">
  <h2>Rozbor textu návrhu</h2>
  <?php $bai = $billText['ai']; ?>
  <span class="chips">
    <?php if ($bai !== null): ?><span class="chip <?= (int)$billText['ai_score'] >= 6 ? 'chip-cut' : 'chip-surge' ?>">AI <?= (int)$billText['ai_score'] ?>/10</span><?php endif; ?>
    <?php if (!empty($bai['zpetna_ucinnost']) || !empty($billText['signals']['retro'])): ?><span class="chip chip-cut">Zpětné použití</span><?php endif; ?>
    <?php if (!empty($bai['zkraceni_lhut']) || !empty($billText['signals']['deadline'])): ?><span class="chip chip-cut">Změna lhůt</span><?php endif; ?>
    <?php if (!empty($bai['prospech_uzke_skupiny'])): ?><span class="chip chip-cut">Prospěch úzké skupiny</span><?php endif; ?>
    <?php if (isset($bai['souvisi_s_predmetem']) && $bai['souvisi_s_predmetem'] === false): ?><span class="chip chip-cut">Nesouvisí s předmětem</span><?php endif; ?>
  </span>
  <?= View::aiRounds($billText) ?>
  <?php if (!empty($bai['shrnuti'])): ?><p><?= e($bai['shrnuti']) ?></p><?php endif; ?>
  <?php if (!empty($bai['duvod'])): ?><p class="muted"><?= e($bai['duvod']) ?></p><?php endif; ?>
  <?= View::impact($bai) ?>
  <?php foreach ((array)($bai['upravy'] ?? []) as $note): ?><p class="muted small">Automatická korekce: <?= e((string)$note) ?></p><?php endforeach; ?>
  <?php
    // doslovné citace: ověřené citace AI, jinak pasáže nalezené pravidly
    $quotes = !empty($bai['citace']) ? $bai['citace'] : array_merge($billText['signals']['deadline'] ?? [], $billText['signals']['retro'] ?? []);
  ?>
  <?php foreach (array_slice($quotes, 0, 3) as $q): ?>
    <blockquote class="quote"><?= e(trim((string)$q)) ?></blockquote>
  <?php endforeach; ?>
  <p class="muted small">Citace jsou doslovně z textu návrhu<?= $billText['docx_url'] ? ' (<a href="' . e($billText['docx_url']) . '">Word na psp.cz</a>)' : '' ?>.
    <?= $bai !== null ? 'Rozbor připravil jazykový model (AI) a může se mýlit; hodnotí formu a postup, ne politický obsah. Rozhodující je původní text.' : 'Pasáže vybrala automatická pravidla.' ?></p>
  <?php if (Revisions::hasHistory($billRev)): ?><p class="small"><a href="<?= e(Revisions::url('tisk', $ct)) ?>">Historie (<?= e(Revisions::versionsLabel($billRev['text'] + $billRev['ai'])) ?>)</a> <span class="muted">– předchozí znění textu a rozbory</span></p><?php endif; ?>
</section>
<?php endif; ?>

<?php $senateLines = Hlidac\Analyzer::senateLines(Hlidac\Analyzer::senate($steps)); ?>
<?php if ($senateLines !== []): ?>
<section class="card pad" id="senat" aria-label="Senát a kroky po něm">
  <h2>Senát a kroky po něm</h2>
  <?php foreach ($senateLines as $line): ?>
    <p><?= e($line) ?></p>
  <?php endforeach; ?>
  <p class="muted small">Jen popis postupu, do skóre nevstupuje. Data jsou podle záznamů Sněmovny (den, kdy výsledek dorazil); den hlasování Senátu, jeho výbory a zpravodaje najdete na <a href="https://www.senat.cz/">senat.cz</a>.</p>
</section>
<?php endif; ?>

<div class="split">
  <section class="card pad main-col" aria-label="Průběh projednávání">
    <h2>Průběh projednávání</h2>
    <?php if ($steps === []): ?><p class="muted">Historie zatím není k dispozici.</p><?php endif; ?>
    <ol class="timeline">
      <?php foreach ($steps as $k): ?>
        <li>
          <span class="tl-date"><?= e(formatDate($k['datum'])) ?></span>
          <span class="tl-dot <?= $dotClass($k) ?>" aria-hidden="true"></span>
          <span class="tl-text"><strong><?= e($k['stav']) ?></strong><?= $k['akce'] !== '' ? ' – ' . e($k['akce']) : '' ?></span>
        </li>
      <?php endforeach; ?>
    </ol>
  </section>

  <aside class="side-col">
    <section class="card pad">
      <h2>Předkladatelé PN</h2>
      <?php if ($authors === []): ?><p class="muted">K tisku nebyl podán žádný písemný pozměňovací návrh.</p><?php endif; ?>
      <ul class="plain-list">
        <?php foreach ($authors as $a): ?>
          <li><span><?= e($a['osoba']) ?></span><span class="strong"><?= (int)$a['n'] ?></span></li>
        <?php endforeach; ?>
      </ul>
    </section>
    <section class="card pad">
      <h2>Pozměňovací návrhy</h2>
      <p class="muted small">Hodnocení „AI N/10“ a shrnutí připravuje jazykový model a může se mýlit; rozhodující je text návrhu na psp.cz.</p>
      <?php if ($pnOutcomes !== []): ?>
        <p class="muted small">Výsledek ve 3. čtení (přijat, zamítnut…) bereme ze souhrnu pozměňovacích návrhů, stenozáznamu a hlasování, bez AI. Kde hlasování nejde přiřadit jednoznačně, uvádíme „nelze určit“. Senát a prezident mohou znění ještě změnit.</p>
      <?php endif; ?>
      <?php if (!empty($t['souhrn_pn'])): ?>
        <p class="small"><a href="https://www.psp.cz/sqw/text/tiskt.sqw?o=<?= (int)$obdobi ?>&amp;ct=<?= $ct ?>&amp;ct1=<?= (int)$t['souhrn_pn'] ?>">Souhrn platně podaných pozměňovacích návrhů (tisk <?= $ct ?>/<?= (int)$t['souhrn_pn'] ?>)</a> <span class="muted">rozeslán <?= e(formatDate((string)$t['souhrn_pn_at'] . ':00', true)) ?></span></p>
      <?php endif; ?>
      <ul class="plain-list">
        <?php foreach ($pn as $i => $p): ?>
          <?php if ($i === 30): ?></ul><details class="pn-more"><summary>Další pozměňovací návrhy (<?= count($pn) - 30 ?>)</summary><ul class="plain-list"><?php endif; ?>
          <?php $x = $texts[(int)$p['id_dokument']] ?? null; ?>
          <li class="pn-item" id="pn-<?= (int)$p['cislo'] ?>">
            <span><a href="<?= e(View::pspSdUrl((int)$p['cislo'], $obdobi)) ?>">Návrh č. <?= (int)$p['cislo'] ?></a> <span class="muted small"><?= e($p['osoba']) ?> · <?= e(formatDate($p['cas'], true)) ?></span></span>
            <?php if (isset($pnOutcomes[(int)$p['cislo']])): ?><span class="pn-outcome"><?= View::pnOutcome($pnOutcomes[(int)$p['cislo']]) ?></span><?php endif; ?>
            <?php if ($x !== null): ?>
              <span class="chips">
                <?php if (!empty($x['signals']['other_laws'])): ?><span class="chip chip-cut">Přílepek: <?= e(implode(', ', $x['signals']['other_laws'])) ?></span><?php endif; ?>
                <?php if (!empty($x['signals']['retro_deadline']) && !empty($x['signals']['deadline'])): ?><span class="chip chip-cut">Lhůta a zpětné použití</span><?php endif; ?>
                <?php if (!empty($x['signals']['large'])): ?><span class="chip chip-surge">Rozsáhlý</span><?php endif; ?>
                <?php if ($x['ai_score'] !== null): ?><span class="chip <?= (int)$x['ai_score'] >= 6 ? 'chip-cut' : 'chip-surge' ?>">AI <?= (int)$x['ai_score'] ?>/10</span><?php endif; ?>
              </span>
              <?php if (!empty($x['ai']['shrnuti'])): ?><span class="small pn-summary"><?= e($x['ai']['shrnuti']) ?></span><?php endif; ?>
              <?= View::aiRounds($x) ?>
              <?php $rev = $pnRev[(int)$p['id_dokument']] ?? []; ?>
              <?php if (Revisions::hasHistory($rev)): ?><a class="small" href="<?= e(Revisions::url('pn', (int)$p['id_dokument'])) ?>">Historie (<?= e(Revisions::versionsLabel($rev['text'] + $rev['ai'])) ?>)</a><?php endif; ?>
              <?php foreach (array_slice($x['ai']['citace'] ?? [], 0, 1) as $q): ?><blockquote class="quote small"><?= e(trim((string)$q)) ?></blockquote><?php endforeach; ?>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if (count($pn) > 30): ?></details>
      <script>
      // odkaz na konkrétní PN (#pn-123, např. z hledání): rozbalit skryté a posunout se na něj;
      // i při změně kotvy na téže stránce (výběr z našeptávače stránku znovu nenačte)
      (function () {
        function show() {
          var el = location.hash.indexOf('#pn-') === 0 ? document.getElementById(location.hash.slice(1)) : null;
          var box = el && el.closest('details');
          if (box && !box.open) { box.open = true; el.scrollIntoView(); }
        }
        show();
        window.addEventListener('hashchange', show);
      })();
      </script>
      <?php endif; ?>
    </section>
  </aside>
</div>

<?php if ($votes !== []): ?>
<section class="card pad" aria-label="Hlasování">
  <h2>Hlasování ve Sněmovně</h2>
  <p class="muted small">Všechna hlasování k tomuto tisku, včetně hlasování o jednotlivých pozměňovacích návrzích. Otevřená data neříkají, o kterém pozměňovacím návrhu se hlasovalo; jmenovité výsledky a podrobnosti jsou na psp.cz. Hlasování do skóre nevstupují.</p>
  <ul class="votes">
    <?php foreach ($votes as $i => $v): ?>
      <?php if ($i === 10): ?></ul><details class="votes-more"><summary>Další hlasování (<?= count($votes) - 10 ?>)</summary><ul class="votes"><?php endif; ?>
      <li class="vote">
        <div class="vote-head">
          <span class="chip <?= $v['vysledek'] === 'A' ? 'chip-mp' : 'chip-cut' ?>"><?= $v['vysledek'] === 'A' ? 'Přijato' : 'Zamítnuto' ?></span>
          <strong><?= e($stepByVote[(int)$v['id_hlasovani']] ?? 'Hlasování č. ' . (int)$v['cislo']) ?></strong>
          <span class="muted small"><?= e(formatDate($v['datum'], true)) ?> · <?= (int)$v['schuze'] ?>. schůze · pro <?= (int)$v['pro'] ?>, proti <?= (int)$v['proti'] ?>, zdrželo se <?= (int)$v['zdrzel'] ?> (potřeba <?= (int)$v['kvorum'] ?>)</span>
          <a class="small" href="https://www.psp.cz/sqw/hlasy.sqw?g=<?= (int)$v['id_hlasovani'] ?>">jmenovitě na psp.cz</a>
        </div>
        <?php if ($v['kluby'] !== []): ?>
          <div class="vote-scroll"><table class="table-compact vote-clubs">
            <thead><tr><th>Klub</th><th>Pro</th><th>Proti</th><th>Zdržel se / nehlasoval</th><th>Nepřítomen</th></tr></thead>
            <tbody>
              <?php foreach ($v['kluby'] as $c): ?>
                <tr><td><?= e($c['klub']) ?></td><td><?= (int)$c['pro'] ?></td><td><?= (int)$c['proti'] ?></td><td><?= (int)$c['zdrzel'] ?></td><td><?= (int)$c['nepritomen'] ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table></div>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php if (count($votes) > 10): ?></details><?php endif; ?>
</section>
<?php endif; ?>

<section class="card" aria-label="Upozornění k tisku">
  <div class="card-head"><h2>Upozornění k tisku</h2></div>
  <?php View::alertList($alerts); ?>
</section>
<?php
View::footer();
