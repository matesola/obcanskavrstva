"use client";
import Link from "next/link";
import { useEffect, useState } from "react";

const KEY = "vrstva-posledni";
type Item = { ct: number; faze: string; title: string };
type Change = { ct: number; title: string; what: string };

/**
 * „Od vaší poslední návštěvy“: porovná fáze návrhů se stavem uloženým v prohlížeči při minulé návštěvě.
 * Ukazuje jen podstatné posuny (nový návrh, jiná fáze), ne administrativní změny data. Bez účtu.
 */
export default function SinceLast({ items }: { items: Item[] }) {
  const [state, setState] = useState<{ since: string; changes: Change[] } | null>(null);

  useEffect(() => {
    let prev: { t: string; f: Record<string, string> } | null = null;
    try { prev = JSON.parse(localStorage.getItem(KEY) ?? "null"); } catch {}
    if (prev && prev.f) {
      const changes: Change[] = [];
      for (const it of items) {
        const before = prev.f[String(it.ct)];
        if (before === undefined) changes.push({ ct: it.ct, title: it.title, what: "nový návrh" });
        else if (before !== it.faze) changes.push({ ct: it.ct, title: it.title, what: `${before} → ${it.faze}` });
      }
      // posuny fáze jsou důležitější než nové návrhy
      changes.sort((a, b) => Number(a.what === "nový návrh") - Number(b.what === "nový návrh"));
      setState({ since: prev.t, changes });
    }
    // nový stav se uloží až po zobrazení, aby změny nezmizely při překreslení
    const t = setTimeout(() => {
      try {
        localStorage.setItem(KEY, JSON.stringify({ t: new Date().toISOString(), f: Object.fromEntries(items.map((i) => [i.ct, i.faze])) }));
      } catch {}
    }, 4000);
    return () => clearTimeout(t);
  }, [items]);

  if (!state) return null;
  const d = new Date(state.since);
  const when = `${d.getDate()}. ${d.getMonth() + 1}.`;
  return (
    <section className="since" aria-label="Od vaší poslední návštěvy">
      <h2>Od vaší poslední návštěvy ({when})</h2>
      {state.changes.length === 0 ? (
        <p className="lead">U žádného návrhu se nezměnila fáze projednávání.</p>
      ) : (
        <ul className="list">
          {state.changes.slice(0, 8).map((c) => (
            <li key={c.ct}>
              <Link prefetch={false} href={`/tisk/${c.ct}`}><strong>{c.title}</strong><span>{c.what}</span></Link>
            </li>
          ))}
          {state.changes.length > 8 && <li className="small">a dalších {state.changes.length - 8}</li>}
        </ul>
      )}
    </section>
  );
}
