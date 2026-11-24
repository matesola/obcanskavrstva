import Link from "next/link";
import { upcoming } from "@/lib/api";
import { byCivic, loadAll } from "@/lib/load";
import type { BillView } from "@/lib/model";
import type { RoleKey } from "@/lib/roles";
import { date, humanName } from "@/lib/text";
import BillCard, { BillRow } from "@/components/BillCard";
import Radar from "@/components/Radar";
import SinceLast from "@/components/SinceLast";
import data from "@/data/situace.json";

export const revalidate = 900; // musí být literál (Next.js), shodně s REVALIDATE v lib/api.ts

function firstSentence(s: string): string {
  const m = s.match(/^(.{40,260}?[.!?])\s+(?=[A-ZÁČĎÉĚÍŇÓŘŠŤÚŮÝŽ])/);
  return (m ? m[1] : s.slice(0, 220)).trim();
}

function Section({ id, title, lead, cards, rows, rowsLabel }: {
  id: string; title: string; lead: string; cards: BillView[]; rows?: BillView[]; rowsLabel?: string;
}) {
  return (
    <section className="section" data-section={id} aria-labelledby={`h-${id}`}>
      <h2 id={`h-${id}`}>{title}</h2>
      <p className="lead">{lead}</p>
      {cards.map((v) => <BillCard key={v.ct} v={v} />)}
      {rows && rows.length > 0 && (
        <details className="more">
          <summary>{rowsLabel ?? `Další (${rows.length})`}</summary>
          <ul className="list">{rows.map((v) => <BillRow key={v.ct} v={v} />)}</ul>
        </details>
      )}
      <p className="empty empty-sel" hidden>Ve vybraných rolích tu teď nic není.</p>
    </section>
  );
}

export default async function Home() {
  const [{ views, aktualizovano }, prep] = await Promise.all([loadAll(), upcoming()]);
  const at = (steps: number[]) => views.filter((v) => steps.includes(v.step)).sort(byCivic);

  const live = at([2, 3]);
  const passed = at([4, 5]);
  const queue = at([0, 1]);
  const valid = views.filter((v) => v.step === 6).sort((a, b) => String(b.posledni).localeCompare(String(a.posledni)));
  const govt = prep.filter((p) => p.faze !== "schvaleno").sort((a, b) => b.zmeneno.localeCompare(a.zmeneno)).slice(0, 5);

  const inPlay = views.filter((v) => v.step >= 0 && v.step <= 5);
  const counts: Partial<Record<RoleKey, number>> = {};
  for (const v of inPlay) for (const r of v.roles) counts[r.key] = (counts[r.key] ?? 0) + 1;
  const explained = inPlay.filter((v) => v.coverage === "plne").length;

  return (
    <>
      <section className="hero">
        <h1>Co se na vás <mark>chystá</mark></h1>
        <p className="tag">„To se nasmějete, lidové vrstvy.“</p>
        <p className="dry">
          Co Sněmovna chystá, lidskou řečí: koho se to týká, komu to pomůže, komu přitíží a jestli už musíte něco dělat.
          Z dat <a href="https://cosepece.cz/">Hlídače zákonů</a>.
        </p>
        <form action="/hledat" className="search" role="search">
          <label htmlFor="q" className="sr">Hledat téma</label>
          <input id="q" name="q" type="search" placeholder="Hledat: důchod, nájem, poplatky za televizi, psi…" />
          <button type="submit">Hledat</button>
        </form>
      </section>

      <SinceLast items={views.map((v) => ({ ct: v.ct, faze: v.faze, title: v.title }))} />
      <Radar counts={counts} />

      <Section id="ted" title="Rozhoduje se teď" lead="Druhé a třetí čtení ve Sněmovně. Teď se rozhoduje, jak zákon bude vypadat. Nic z toho zatím neplatí."
        cards={live.slice(0, 8)} rows={live.slice(8)} rowsLabel={`Další návrhy ve hře (${Math.max(0, live.length - 8)})`} />

      {passed.length > 0 && (
        <Section id="schvaleno" title="Schválila Sněmovna, ještě neplatí" lead="Rozhoduje Senát nebo prezident. Platit začne až po vyhlášení ve Sbírce zákonů a od data účinnosti." cards={passed} />
      )}

      <Section id="fronta" title="Čeká na projednání" lead="Návrhy před prvním čtením nebo v něm. Mnoho z nich se nikdy neprojedná."
        cards={queue.slice(0, 3)} rows={queue.slice(3)} rowsLabel={`Všechny čekající návrhy (${Math.max(0, queue.length - 3)})`} />

      {govt.length > 0 && (
        <section className="section" aria-labelledby="h-vlada">
          <h2 id="h-vlada">Chystá vláda</h2>
          <p className="lead">Ještě to není ve Sněmovně. Detaily a připomínky k nim jsou na Hlídači zákonů.</p>
          <ul className="list">
            {govt.map((p) => (
              <li key={p.pid}>
                <a href={`https://cosepece.cz/pripravovane.php#prep-${p.pid}`}>
                  <strong>{humanName(p.nazev, p.nazev)}</strong>
                  <span>{p.resort}{p.pripominky ? ` · připomínky ${p.pripominky.dni} dní` : ""}{p.mimo_plan ? " · mimo plán vlády" : ""}</span>
                  {p.popis && <span className="list-note">{firstSentence(p.popis)}</span>}
                </a>
              </li>
            ))}
          </ul>
        </section>
      )}

      {valid.length > 0 && (
        <section className="section" data-section="vyhlaseno" aria-labelledby="h-vyhlaseno">
          <h2 id="h-vyhlaseno">Nedávno vyhlášeno</h2>
          <p className="lead">Prošlo celým procesem a vyšlo ve Sbírce zákonů. Pozor: vyhlášení neznamená, že se podle zákona už postupuje. Rozhoduje datum účinnosti.</p>
          <ul className="list">{valid.slice(0, 8).map((v) => <BillRow key={v.ct} v={v} />)}</ul>
          <p className="empty empty-sel" hidden>Ve vybraných rolích tu teď nic není.</p>
        </section>
      )}

      <section className="about" aria-label="O datech">
        <h2>Odkud to víme</h2>
        <p>
          Seznam návrhů, fáze, hodnocení postupu a rozbory textů jsou z <a href="https://cosepece.cz/">Hlídače zákonů</a> (cosepece.cz),
          který bere data z otevřených dat Poslanecké sněmovny a Úřadu vlády. Občanská vrstva k tomu přidává občanské titulky,
          „komu pomůže / přitíží“, role a odpověď na otázku „musím něco udělat?“.
        </p>
        <p>
          Občanské vysvětlení má zatím <b>{explained} z {inPlay.length}</b> projednávaných návrhů (všechny ve druhém a třetím čtení a po Sněmovně).
          U ostatních ukazujeme jen to, co víme z Hlídače: název, fázi a větu z jeho rozboru, pokud ji má. Role u nich určujeme automaticky z názvu.
        </p>
        <p className="small">
          Data Hlídače zákonů: {date(aktualizovano)}, {aktualizovano.slice(11, 16)}. Občanská vysvětlení: stav k {date((data as { stav_k: string }).stav_k)}.
          Když se návrh posune do jiné fáze, u vysvětlení to označíme. <Link href="/hledat">Hledat téma</Link>
        </p>
      </section>
    </>
  );
}
