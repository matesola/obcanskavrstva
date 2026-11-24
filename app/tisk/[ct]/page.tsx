import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { bill, type ImpactItem } from "@/lib/api";
import { GROUPS, LEVEL, date, kind, perex, subtitle, title } from "@/lib/text";
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
  const b = await load(params);
  if (!b) return { title: "Tisk nenalezen" };
  return { title: title(b), description: perex(b) ?? b.uplny_nazev };
}

function Quote({ text }: { text: string }) {
  return (
    <details className="quote">
      <summary>Citace ze zákona</summary>
      <blockquote>{text}</blockquote>
    </details>
  );
}

function Items({ heading, items, line }: { heading: string; items: ImpactItem[]; line: (i: ImpactItem) => React.ReactNode }) {
  if (items.length === 0) return null;
  return (
    <section className="block">
      <h2>{heading}</h2>
      <ul className="items">
        {items.map((i, k) => (
          <li key={k}>
            {line(i)}
            <Quote text={i.citace} />
          </li>
        ))}
      </ul>
    </section>
  );
}

export default async function BillPage({ params }: Props) {
  const b = await load(params);
  if (!b) notFound();

  const d = b.dopad ?? null;
  const groups = d?.skupiny.map((g) => g.nazev) ?? (b.rozbor?.skupiny ?? []).map((g) => GROUPS[g] ?? g);
  const sub = subtitle(b);
  const p = perex(b);
  const summary = b.rozbor?.shrnuti;
  const votes = [...(b.hlasovani ?? [])].sort((x, y) => y.datum.localeCompare(x.datum)).slice(0, 5);

  return (
    <article className="detail">
      <p className="crumb">
        <Link href="/">Přehled</Link> · Tisk {b.ct} · {kind(b.typ_navrhu)} · {b.navrhovatel}
      </p>
      <h1>{title(b)}</h1>
      {sub && <p className="official">{sub}</p>}
      {p && <p>{p}</p>}

      <section className="block">
        <h2>Kde to je</h2>
        <Path faze={b.faze} labels />
        <p className="small">Předloženo {date(b.predlozeno)}, poslední krok {date(b.posledni_zmena)}.</p>
        {b.senat?.popis?.map((l, k) => <p key={k} className="small">{l}</p>)}
      </section>

      {(summary || groups.length > 0 || d?.ucinnost) && (
        <section className="block">
          <h2>Co se mění</h2>
          {summary && <p>{summary}</p>}
          {groups.length > 0 && (
            <ul className="groups">
              {groups.map((g) => <li key={g}>{g}</li>)}
            </ul>
          )}
          {d?.ucinnost && (
            <div>
              <strong>Platí od:</strong> {d.ucinnost.text}
              <Quote text={d.ucinnost.citace} />
            </div>
          )}
        </section>
      )}

      {d && (
        <>
          <Items heading="Nové povinnosti" items={d.povinnosti} line={(i) => <><span className="who">{i.kdo}:</span> {i.co}</>} />
          <Items heading="Nové pravomoci" items={d.pravomoci} line={(i) => <><span className="who">{i.kdo}:</span> {i.co}</>} />
          <Items heading="Sankce" items={d.sankce} line={(i) => <>{i.za_co}{i.vyse && <>: <strong>{i.vyse}</strong></>}</>} />
          <Items
            heading="Kde je háček"
            items={d.rizika}
            line={(i) => <>{i.popis}{i.pojistka && <span className="guard">Pojistka: {i.pojistka}</span>}</>}
          />
          {d.rizika.length > 0 && (
            <p className="small">Háček popisuje, co text umožňuje, ne co kdo zamýšlí. Pojistky mohou být i v platném zákoně, který návrh mění.</p>
          )}
        </>
      )}

      {b.hlavni_nalez && b.hlavni_nalez.titulek !== b.rozbor?.titulek && (
        <section className="block">
          <h2>Hlavní nález</h2>
          <p><strong>{b.hlavni_nalez.titulek}</strong></p>
          {b.hlavni_nalez.perex && <p>{b.hlavni_nalez.perex}</p>}
          <Quote text={b.hlavni_nalez.citace} />
          <p className="small">Zdroj: {b.hlavni_nalez.zdroj}{b.hlavni_nalez.overeno ? ", ověřeno druhým modelem" : ""}.</p>
        </section>
      )}

      <section className="block">
        <h2>Jak se to prosazuje</h2>
        <p>
          <span className={`level level-${b.uroven}`}>{LEVEL[b.uroven] ?? b.uroven}</span> · skóre postupu {b.skore} ze 100
        </p>
        <ul className="items">
          {b.duvody.filter((r) => r.body > 0).map((r) => (
            <li key={r.klic + r.popis}>
              <strong>+{r.body}</strong> {r.popis}
            </li>
          ))}
        </ul>
        <p className="small">Skóre Hlídače zákonů hodnotí postup (obcházení připomínek, zrychlení, přílepky), ne politický obsah.</p>
      </section>

      {votes.length > 0 && (
        <section className="block">
          <h2>Hlasování ve Sněmovně</h2>
          {votes.map((v) => (
            <a key={v.id} className="vote" href={v.psp_url}>
              <span>{date(v.datum)}</span>
              <span className="bar" title={`pro ${v.pro}, proti ${v.proti}`}>
                <i style={{ width: `${(v.pro / 200) * 100}%` }} />
              </span>
              <span>{v.pro}:{v.proti} {v.vysledek}</span>
            </a>
          ))}
          <p className="small">Černě hlasy pro ze 200 poslanců. Kdo jak hlasoval, je na psp.cz.</p>
        </section>
      )}

      <div className="links">
        <a className="btn" href={b.url}>Detail v Hlídači zákonů</a>
        <a className="btn" href={b.psp_url}>Na psp.cz</a>
      </div>
      <p className="small" style={{ marginTop: 18 }}>{b.uplny_nazev}</p>
    </article>
  );
}
