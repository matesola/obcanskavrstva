"use client";
import Link from "next/link";
import { useEffect, useMemo, useState } from "react";
import { search, type SearchItem } from "@/lib/search";

const EXAMPLES = ["důchod", "poplatky za televizi", "nájem", "psi", "mateřská", "daně", "pokuty", "exekuce", "práce o svátcích", "příspěvky zaměstnavatele"];

export default function Search({ items }: { items: SearchItem[] }) {
  const [q, setQ] = useState("");
  useEffect(() => {
    setQ(new URLSearchParams(window.location.search).get("q") ?? "");
  }, []);
  const results = useMemo(() => search(items, q), [items, q]);
  const update = (v: string) => {
    setQ(v);
    const u = new URL(window.location.href);
    if (v) u.searchParams.set("q", v); else u.searchParams.delete("q");
    window.history.replaceState(null, "", u);
  };
  return (
    <>
      <div className="search">
        <label htmlFor="q2" className="sr">Hledat téma</label>
        <input id="q2" type="search" value={q} onChange={(e) => update(e.target.value)} placeholder="Napište, co vás zajímá" autoFocus />
      </div>
      {q.trim() === "" ? (
        <p className="chips-inline">
          Zkuste: {EXAMPLES.map((e, i) => (
            <span key={e}><button type="button" className="linkish" onClick={() => update(e)}>{e}</button>{i < EXAMPLES.length - 1 ? ", " : ""}</span>
          ))}
        </p>
      ) : (
        <>
          <p className="lead" aria-live="polite">{results.length ? `Nalezeno ${results.length}` : "Nic jsme nenašli. Zkuste jiné slovo, třeba obecnější."}</p>
          <ul className="list">
            {results.slice(0, 40).map((r) => (
              <li key={r.ct}>
                <Link prefetch={false} href={`/tisk/${r.ct}`}><strong>{r.title}</strong><span>{r.meta}</span></Link>
              </li>
            ))}
          </ul>
        </>
      )}
    </>
  );
}
