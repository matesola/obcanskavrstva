import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { bill, type ImpactItem } from "@/lib/api";
import { view } from "@/lib/model";
import { ROLE_LABEL } from "@/lib/roles";
import { condition, resolved, situace, type Who } from "@/lib/situace";
import { LEVEL, date, kind } from "@/lib/text";
import Path from "@/components/Path";

export const revalidate = 900; // musí být literál (Next.js), shodně s REVALIDATE v lib/api.ts

// stránky tisků se vygenerují při první návštěvě a pak obnovují na pozadí (ISR)
export async function generateStaticParams() {
  return [];
}

type Props = { params: Promise<{ ct: string }> };

async function load(params: Props["params"]) {
  const { ct } = await params;
  const n = Number(ct);
  if (!Number.isInteger(n) || n <= 0 || n > 99999) return null;
  return bill(n);
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  // výpadek zdroje nesmí shodit metadata; stránka sama pak ukáže srozumitelnou chybu
  let b;
  try { b = await load(params); } catch { return { title: "Hlídač zákonů neodpovídá" }; }
  if (!b) return { title: "Tisk nenalezen" };
  const v = view(b, b);
  return { title: v.title, description: `${v.status.label}. ${v.summary ?? v.law}` };
}

function Quote({ text, label = "Citace ze zákona" }: { text: string; label?: string }) {
  return (
    <details className="quote">
      <summary>{label}</summary>
      <blockquote>„{text}“</blockquote>
    </details>
  );
}

function Side({ title, sign, word, list }: { title: string; sign: string; word: string; list: Who[] }) {
  if (list.length === 0) return null;
  return (
    <div className="side">
      <h3>{title}</h3>
      <ul className={`items pm-detail ${sign === "▲" ? "pm-plus" : "pm-minus"}`}>
        {list.map((w) => (
          <li key={w.kdo + w.jak}>
            <span className="pm-sign" aria-hidden="true">{sign}</span> <span className="sr">{word}</span>
            <span className="who">{w.kdo}</span>: {w.jak}
            <span className="guard">{condition(w.zdroj) ? `Jen pokud projde ${condition(w.zdroj)?.replace(/, jen pokud projd(?:e|ou)$/, "")}` : "Přímo v textu návrhu"}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}

function Items({ heading, items, line }: { heading: string; items: ImpactItem[]; line: (i: ImpactItem) => React.ReactNode }) {
  if (items.length === 0) return null;
  return (
    <div className="side">
      <h3>{heading}</h3>
      <ul className="items">
        {items.map((i, k) => (
          <li key={k}>{line(i)}<Quote text={i.citace} /></li>
        ))}
      </ul>
    </div>
  );
}

/** Kde se dá ozvat: jen skutečné možnosti podle fáze, žádné výzvy. */
function Participation({ step }: { step: number }) {
  if (step >= 0 && step <= 3) {
    return (
      <ul className="plain">
        <li>Kdy se o návrhu jedná, je v <a href="https://psp.cz/sqw/hp.sqw?k=1002">přehledu schůzí Sněmovny</a>; jednání jsou veřejná a vysílají se živě.</li>
        <li>Názor můžete napsat poslancům svého kraje: <a href="https://www.psp.cz/sqw/hp.sqw?k=192">seznam poslanců s kontakty</a>.</li>
      </ul>
    );
  }
  if (step === 4) {
    return (
      <ul className="plain">
        <li>Senát má na návrh ze Sněmovny 30 dní. Napsat můžete senátorovi svého obvodu: <a href="https://www.senat.cz/senatori/index.php">seznam senátorů</a>.</li>
      </ul>
    );
  }
  if (step === 5) return <p>Prezident může zákon do 15 dnů vrátit Sněmovně (veto), nebo ho podepsat.</p>;
  return null;
}

export default async function BillPage({ params }: Props) {
  const b = await load(params);
  if (!b) notFound();

  const v = view(b, b);
  const sit = resolved(b.ct, b) ?? situace(b.ct);
  const local = situace(b.ct);
  const d = b.dopad ?? null;
  const cmp = (local as { srovnani?: { dnes: string; navrh: string; zdroj: string } } | null)?.srovnani;
  const reasons = [...b.duvody].filter((r) => r.body > 0).sort((x, y) => y.body - x.body).slice(0, 2);

  return (
    <article className="detail">
      <p className="crumb">
        <Link href="/">Přehled</Link> · Tisk {b.ct} · {kind(b.typ_navrhu)} · {b.navrhovatel}
      </p>
      <h1>{v.title}</h1>
      <p className="official">{v.law}{v.titleSource !== "název návrhu" ? ` · titulek: ${v.titleSource}` : ""}</p>

      <div className="nowbox">
        <p className={`status status-${v.status.kind}`}>{v.status.label}</p>
        <p><b>Musím něco udělat?</b> {v.status.act}</p>
        <p className="small">Účinnost: {v.ucinnost ? `podle návrhu ${v.ucinnost}` : "zatím neznámá"}.</p>
        {v.stale && <p className="stale">{v.stale}</p>}
      </div>

      {(v.summary || cmp) && (
        <section className="block">
          <h2>Co se mění</h2>
          {v.summary && <p>{v.summary} <span className="src">({v.summarySource})</span></p>}
          {b.rozbor?.shrnuti && b.rozbor.shrnuti !== v.summary && <p>{b.rozbor.shrnuti}</p>}
          {cmp && (
            <dl className="cmp">
              <div><dt>Dnes</dt><dd>{cmp.dnes}</dd></div>
              <div><dt>Podle návrhu</dt><dd>{cmp.navrh}</dd></div>
              <p className="src">{condition(cmp.zdroj) ? `Jen pokud projde ${cmp.zdroj.replace(/, jen pokud projde$/, "")}.` : "Podle textu návrhu, shrnuto z rozboru Hlídače."}</p>
            </dl>
          )}
        </section>
      )}

      {(v.pomuze.length > 0 || v.pritizi.length > 0 || v.roles.length > 0) && (
        <section className="block">
          <h2>Koho se to týká</h2>
          <Side title="Komu by pomohl" sign="▲" word="Pomůže:" list={v.pomuze} />
          <Side title="Komu by přitížil" sign="▼" word="Přitíží:" list={v.pritizi} />
          {sit && sit.koho.length > 0 && v.pomuze.length + v.pritizi.length === 0 && (
            <div className="side">
              <h3>Další dotčení</h3>
              <ul className="items">
                {sit.koho.map((k) => (
                  <li key={k.kdo}><span className="who">{k.kdo}</span>: {k.jak}<span className="guard">{condition(k.zdroj) ? `Jen pokud projde ${condition(k.zdroj)?.replace(/, (jen pokud projd(?:e|ou)|výsledek zatím nelze určit)$/, "")}` : "Přímo v textu návrhu"}</span></li>
                ))}
              </ul>
            </div>
          )}
          {v.roles.length > 0 && (
            <p className="small">
              Zařazeno do rolí: {v.roles.map((r) => `${ROLE_LABEL[r.key]} (${r.why})`).join("; ")}. Role určujeme automaticky podle slov v textu, jde o hrubé třídění.
            </p>
          )}
          {(v.pomuze.length > 0 || v.pritizi.length > 0) && (
            <p className="small">Pomůže a přitíží popisuje přímé dopady textu (peníze, povinnosti, práva), ne hodnocení, zda je zákon dobrý. Shrnuto z rozboru textu a pozměňovacích návrhů na Hlídači zákonů.</p>
          )}
        </section>
      )}

      {d && (d.povinnosti.length + d.pravomoci.length + d.sankce.length > 0) && (
        <section className="block">
          <h2>Podrobněji z rozboru</h2>
          <Items heading="Nové povinnosti" items={d.povinnosti} line={(i) => <><span className="who">{i.kdo}:</span> {i.co}</>} />
          <Items heading="Nové pravomoci" items={d.pravomoci} line={(i) => <><span className="who">{i.kdo}:</span> {i.co}</>} />
          <Items heading="Sankce" items={d.sankce} line={(i) => <>{i.za_co}{i.vyse && <>: <strong>{i.vyse}</strong></>}</>} />
        </section>
      )}

      {sit && sit.nejhorsi.length > 0 && (
        <section className="block">
          <h2>V nejhorším případě</h2>
          <p className="small">Teoretický scénář: co nejpřísnější čtení textu dovolí. Není to předpověď ani běžný očekávaný výsledek.</p>
          {sit.nejhorsi.map((w) => (
            <div key={w.titulek} className="worst">
              <span className="cond">{w.podminka}</span>
              <h3>{w.titulek}</h3>
              <p>{w.pribeh}</p>
              <p className="guard-line"><b>Co tomu brání:</b> {w.pojistka}</p>
              <details className="quote">
                <summary>Citace ze zákona ({w.zdroj})</summary>
                {w.citace.map((q) => <blockquote key={q}>„{q}“</blockquote>)}
              </details>
            </div>
          ))}
        </section>
      )}

      <section className="block">
        <h2>Kde to je a co bude dál</h2>
        <Path faze={b.faze} labels />
        <p className="small">Předloženo {date(b.predlozeno)}, poslední krok {date(b.posledni_zmena)}.</p>
        {b.senat?.popis?.map((l, k) => <p key={k} className="small">{l}</p>)}
        <Participation step={v.step} />
      </section>

      <section className="block">
        <h2>Proč Hlídač zákonů upozorňuje</h2>
        <p>
          Hlídač hodnotí <b>postup</b>, jakým se zákon prosazuje (obcházení připomínek, zrychlení, přílepky), ne jeho obsah ani to, jak moc se vás týká.
          Tento návrh: <span className={`level level-${b.uroven}`}>{LEVEL[b.uroven] ?? b.uroven}</span>, skóre {b.skore} ze 100.
        </p>
        {reasons.length > 0 && (
          <ul className="items">{reasons.map((r) => <li key={r.klic + r.popis}><strong>+{r.body}</strong> {r.popis.replace(/\s*[\u2013\u2014]\s*/g, ": ")}</li>)}</ul>
        )}
      </section>

      <section className="block verify">
        <h2>Ověřit a zjistit víc</h2>
        <div className="links">
          <a className="btn btn-main" href={b.url}>Podrobný rozbor na Hlídači zákonů</a>
          <a className="btn" href={b.psp_url}>Původní dokument na psp.cz</a>
        </div>
        <p className="small">
          Na Hlídači najdete rozbor textu s citacemi, všechny pozměňovací návrhy, legislativní historii a hlasování.
          Úplný název: {b.uplny_nazev}
        </p>
        <p className="small">
          Co je odkud: stav, fáze a hodnocení postupu jsou z Hlídače zákonů; titulek, „komu pomůže / přitíží“ a odpověď „musím něco udělat?“
          přidává Občanská vrstva; citace jsou doslovně z textu na psp.cz.
        </p>
      </section>
    </article>
  );
}
