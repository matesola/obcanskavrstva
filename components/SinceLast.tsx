"use client";
import Link from "next/link";
import { useEffect, useState } from "react";
import type { RoleKey } from "@/lib/roles";
import { readRoles } from "./Radar";

const KEY = "vrstva-posledni";
export type SinceItem = { ct: number; faze: string; title: string; roles: string[]; pn: string; uc: string };
type Change = { ct: number; title: string; what: string; mine: boolean; rank: number };

function phaseWords(before: string, now: string): string {
  if (now === "Vyhlášeno") return "vyšel ve Sbírce zákonů (pozor: vyhlášení není účinnost)";
  if (["Schváleno Sněmovnou", "V Senátu"].includes(now)) return "schválila Sněmovna, rozhoduje Senát";
  if (now === "U prezidenta") return "schválen, čeká na prezidenta";
  if (["Zamítnuto", "Vzat zpět", "Vráceno předkladateli"].includes(now)) return `skončil: ${now.toLowerCase()}`;
  return `posun: ${before} → ${now}`;
}

/**
 * „Od vaší poslední návštěvy“: jen podstatné změny (nový návrh, posun fáze, rozhodnutí o pozměňovacích
 * návrzích, nově známá účinnost), ne technické obnovení dat. Při výběru rolí nejdřív to, co se vás týká.
 */
export default function SinceLast({ items }: { items: SinceItem[] }) {
  const [state, setState] = useState<{ since: string; changes: Change[] } | null>(null);
  const [roles, setRoles] = useState<RoleKey[]>([]);
  const [all, setAll] = useState(false);

  useEffect(() => {
    const upd = () => setRoles(readRoles());
    upd();
    window.addEventListener("vrstva-role", upd);
    return () => window.removeEventListener("vrstva-role", upd);
  }, []);

  useEffect(() => {
    let prev: { t: string; f: Record<string, string>; p?: Record<string, string>; u?: Record<string, string> } | null = null;
    try { prev = JSON.parse(localStorage.getItem(KEY) ?? "null"); } catch {}
    if (prev && prev.f) {
      const changes: Change[] = [];
      for (const it of items) {
        const id = String(it.ct);
        const before = prev.f[id];
        const base = { ct: it.ct, title: it.title, mine: false };
        if (before === undefined) changes.push({ ...base, what: "nový návrh", rank: 3 });
        else if (before !== it.faze) changes.push({ ...base, what: phaseWords(before, it.faze), rank: 1 });
        else if (prev.p && prev.p[id] !== undefined && prev.p[id] !== it.pn && it.pn) changes.push({ ...base, what: "Sněmovna rozhodla o pozměňovacích návrzích", rank: 2 });
        else if (prev.u && (prev.u[id] ?? "") !== it.uc && it.uc) changes.push({ ...base, what: `nově známá navrhovaná účinnost: ${it.uc}`, rank: 2 });
      }
      setState({ since: prev.t, changes: changes.map((c) => ({ ...c, roles: items.find((i) => i.ct === c.ct)?.roles ?? [] })) as unknown as Change[] });
    }
    const t = setTimeout(() => {
      try {
        localStorage.setItem(KEY, JSON.stringify({
          t: new Date().toISOString(),
          f: Object.fromEntries(items.map((i) => [i.ct, i.faze])),
          p: Object.fromEntries(items.map((i) => [i.ct, i.pn])),
          u: Object.fromEntries(items.map((i) => [i.ct, i.uc])),
        }));
      } catch {}
    }, 4000);
    return () => clearTimeout(t);
  }, [items]);

  if (!state) return null;
  const isMine = (c: Change) => {
    const r = (c as unknown as { roles: string[] }).roles;
    return r.includes("vsichni") || r.some((x) => roles.includes(x as RoleKey));
  };
  const mine = roles.length ? state.changes.filter(isMine) : [];
  const rest = roles.length ? state.changes.filter((c) => !isMine(c)) : state.changes;
  const sorted = (l: Change[]) => [...l].sort((a, b) => a.rank - b.rank);
  const d = new Date(state.since);
  const when = `${d.getDate()}. ${d.getMonth() + 1}.`;
  const shown = roles.length ? sorted(mine) : sorted(rest).slice(0, 5);

  const head = roles.length
    ? mine.length ? `${mine.length} ${mine.length === 1 ? "změna" : mine.length < 5 ? "změny" : "změn"} u návrhů, které se vás týkají` : "u vašich témat nic podstatného"
    : state.changes.length ? `${state.changes.length} ${state.changes.length === 1 ? "podstatná změna" : state.changes.length < 5 ? "podstatné změny" : "podstatných změn"}` : "nic podstatného";
  const item = (c: Change) => (
    <li key={c.ct}><Link prefetch={false} href={`/tisk/${c.ct}`}><strong>{c.title}</strong><span>{c.what}</span></Link></li>
  );

  // sbalené na jeden řádek, aby první karta zůstala na první obrazovce
  return (
    <details className="since" aria-label="Od vaší poslední návštěvy">
      <summary className="since-head"><b>Od minula ({when}):</b> {head}</summary>
      {shown.length > 0 && <ul className="list">{shown.map(item)}</ul>}
      {roles.length > 0 && rest.length > 0 && (
        <details open={all} onToggle={(e) => setAll((e.target as HTMLDetailsElement).open)}>
          <summary className="small">Další změny mimo vaše témata ({rest.length})</summary>
          <ul className="list">{sorted(rest).slice(0, 10).map(item)}</ul>
        </details>
      )}
    </details>
  );
}
