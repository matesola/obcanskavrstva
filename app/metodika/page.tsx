import type { Metadata } from "next";
import Link from "next/link";
import { AGENDA_LABEL, type Agenda } from "@/lib/agenda";
import { loadAll } from "@/lib/load";

export const revalidate = 900;
export const metadata: Metadata = { title: "Jak to děláme" };

export default async function Metodika() {
  const { views } = await loadAll();
  const inPlay = views.filter((v) => v.step >= 0 && v.step <= 5);
  const kinds: Agenda[] = ["primy", "neprimy", "institucionalni", "nejasne"];
  const n = (k: Agenda) => inPlay.filter((v) => v.agenda === k).length;
  const expl = (k: Agenda) => inPlay.filter((v) => v.agenda === k && v.coverage === "plne").length;
  return (
    <article className="detail">
      <h1>Jak to děláme</h1>
      <p className="official">Co je fakt, co shrnutí a co náš výklad. Občanská vrstva je nezávislý side projekt nad daty Hlídače zákonů.</p>

      <section className="block">
        <h2>Pokrytí</h2>
        <p>Z {inPlay.length} projednávaných návrhů:</p>
        <ul className="items">
          {kinds.map((k) => (
            <li key={k}><span className="who">{AGENDA_LABEL[k]}:</span> {n(k)} návrhů, z toho {expl(k)} s občanským vysvětlením</li>
          ))}
        </ul>
        <p className="small">
          Přímý dopad: návrh mění peníze, povinnosti nebo práva konkrétní skupiny lidí. Nepřímý: ceny, služby, veřejné finance, prostředí.
          Chod státu: úřady, soudy, Sněmovna. „Neumíme určit“: z názvu a dostupných rozborů to nejde poznat, raději to přiznáme než hádáme.
        </p>
      </section>

      <section className="block">
        <h2>Čtyři úrovně jistoty</h2>
        <ul className="items">
          <li><span className="who">A · existence:</span> citace opravdu je v textu návrhu. Kontrolujeme automaticky každý den.</li>
          <li><span className="who">B · kontext:</span> ustanovení jsme přečetli v souvislosti s rozborem Hlídače zákonů, ne s celým zněním zákona.</li>
          <li><span className="who">C · výklad:</span> titulek, „komu pomůže / přitíží“ a scénář „V nejhorším případě“ jsou náš výklad. Ne fakt, ne předpověď.</li>
          <li><span className="who">D · aktuálnost:</span> datum ruční kontroly. Když se změní fáze nebo text návrhu, výklad označíme, a když by mohl mýlit, skryjeme ho.</li>
        </ul>
      </section>

      <section className="block">
        <h2>Co neděláme</h2>
        <p>
          Nehodnotíme, jestli je zákon dobrý. Nespojujeme relevanci, naléhavost a rizikovost do jednoho čísla. Hodnocení postupu
          přebíráme od Hlídače zákonů beze změny. Datum účinnosti nevymýšlíme: když ho nemáme, napíšeme to.
        </p>
      </section>
      <p><Link href="/">Zpět na přehled</Link></p>
    </article>
  );
}
