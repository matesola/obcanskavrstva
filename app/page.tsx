import Link from "next/link";
import { bills, upcoming, type Bill } from "@/lib/api";
import { date, humanName, stepIndex, title } from "@/lib/text";
import BillCard from "@/components/BillCard";
import GroupFilter from "@/components/GroupFilter";

export const revalidate = 900; // musí být literál (Next.js), shodně s REVALIDATE v lib/api.ts

const byScoreThenRecent = (a: Bill, b: Bill) =>
  b.skore - a.skore || String(b.posledni_zmena).localeCompare(String(a.posledni_zmena));
const byRecent = (a: Bill, b: Bill) => String(b.posledni_zmena).localeCompare(String(a.posledni_zmena));

/** První věta popisu materiálu vlády. */
function firstSentence(s: string): string {
  const m = s.match(/^(.{40,260}?[.!?])\s+(?=[A-ZÁČĎÉĚÍŇÓŘŠŤÚŮÝŽ])/);
  return (m ? m[1] : s.slice(0, 220)).trim();
}

function CompactList({ items, label }: { items: Bill[]; label: string }) {
  return (
    <details className="more">
      <summary>{label}</summary>
      <ul className="list">
        {items.map((b) => (
          <li key={b.ct} data-groups={(b.rozbor?.skupiny ?? []).join(" ")}>
            <Link href={`/tisk/${b.ct}`}>
              <strong>{title(b)}</strong>
              <span>Tisk {b.ct} · {b.faze}{b.uroven === "major" ? " · závažný postup" : ""}</span>
            </Link>
          </li>
        ))}
      </ul>
    </details>
  );
}

export default async function Home() {
  const [data, prep] = await Promise.all([bills(), upcoming()]);
  const all = data?.tisky ?? [];

  // ve hře: 2. a 3. čtení, tam se rozhoduje o podobě zákona
  const live = all.filter((b) => [2, 3].includes(stepIndex(b.faze))).sort(byScoreThenRecent);
  // prošlo Sněmovnou, míří do Senátu nebo k prezidentovi
  const passed = all.filter((b) => [4, 5].includes(stepIndex(b.faze))).sort(byRecent);
  // čeká na 1. čtení nebo je v něm
  const queue = all.filter((b) => [0, 1].includes(stepIndex(b.faze))).sort(byScoreThenRecent);
  const valid = all.filter((b) => stepIndex(b.faze) === 6).sort(byRecent);
  const govt = prep.filter((p) => p.faze !== "schvaleno").sort((a, b) => b.zmeneno.localeCompare(a.zmeneno)).slice(0, 6);

  const available = [...new Set(all.flatMap((b) => b.rozbor?.skupiny ?? []))];

  return (
    <>
      <section className="hero">
        <h1>
          Co se na vás <mark>chystá</mark>
        </h1>
        <p className="tag">To se nasmějete, lidové vrstvy.</p>
        <p className="dry">
          Zákony, které právě projednává Sněmovna, přeložené do lidské řeči. Koho se týkají, odkdy a kde je háček.
          Každé tvrzení s citací ze zákona.
        </p>
      </section>

      {!data && <p className="empty">Data z Hlídače zákonů se teď nepodařilo načíst. Zkuste to za chvíli.</p>}

      <GroupFilter available={available} />

      <section className="section">
        <h2>Právě ve hře</h2>
        <p className="lead">Druhé a třetí čtení: teď se rozhoduje, jak zákon nakonec bude vypadat.</p>
        {live.slice(0, 8).map((b) => <BillCard key={b.ct} b={b} />)}
        {live.length === 0 && <p className="empty">Teď nic.</p>}
        {live.length > 8 && <CompactList items={live.slice(8)} label={`Dalších ${live.length - 8} ve hře`} />}
      </section>

      {passed.length > 0 && (
        <section className="section">
          <h2>Prošlo Sněmovnou</h2>
          <p className="lead">Míří do Senátu nebo k prezidentovi. Brzy může platit.</p>
          {passed.map((b) => <BillCard key={b.ct} b={b} />)}
        </section>
      )}

      {queue.length > 0 && (
        <section className="section">
          <h2>Ve frontě</h2>
          <p className="lead">Návrhy před prvním čtením nebo v něm. Nahoře ty, u kterých Hlídač zákonů vidí sporný postup.</p>
          {queue.slice(0, 4).map((b) => <BillCard key={b.ct} b={b} />)}
          {queue.length > 4 && <CompactList items={queue.slice(4)} label={`Dalších ${queue.length - 4} návrhů`} />}
        </section>
      )}

      {govt.length > 0 && (
        <section className="section">
          <h2>Chystá vláda</h2>
          <p className="lead">Ještě ani není ve Sněmovně. Teď se k tomu dá nejsnáz něco říct.</p>
          <ul className="list">
            {govt.map((p) => (
              <li key={p.pid}>
                <a href={`https://cosepece.cz/pripravovane.php#prep-${p.pid}`}>
                  <strong>{humanName(p.nazev, p.nazev)}</strong>
                  <span>
                    {p.resort}
                    {p.pripominky ? ` · připomínky ${p.pripominky.dni} dní` : ""}
                    {p.mimo_plan ? " · mimo plán vlády" : ""}
                  </span>
                  {p.popis && <span style={{ display: "block", marginTop: 4 }}>{firstSentence(p.popis)}</span>}
                </a>
              </li>
            ))}
          </ul>
        </section>
      )}

      {valid.length > 0 && (
        <section className="section">
          <h2>Už platí</h2>
          <ul className="list">
            {valid.slice(0, 8).map((b) => (
              <li key={b.ct} data-groups={(b.rozbor?.skupiny ?? []).join(" ")}>
                <Link href={`/tisk/${b.ct}`}>
                  <strong>{title(b)}</strong>
                  <span>Tisk {b.ct} · vyhlášeno {date(b.posledni_zmena)}</span>
                </Link>
              </li>
            ))}
          </ul>
        </section>
      )}

      {data && <p className="small" style={{ marginTop: 32 }}>Data aktualizována {date(data.aktualizovano)}, {data.aktualizovano.slice(11, 16)}.</p>}
    </>
  );
}
