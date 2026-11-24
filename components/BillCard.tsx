import Link from "next/link";
import { AGENDA_LABEL } from "@/lib/agenda";
import type { BillView } from "@/lib/model";
import { ROLE_LABEL } from "@/lib/roles";
import { condition, type Who } from "@/lib/situace";

/** Jedna nejdůležitější věta o dopadu: nejdřív přímo z textu návrhu, až pak z pozměňovacího návrhu. */
function keyImpact(v: BillView): { sign: string; word: string; w: Who } | null {
  const all = [
    ...v.pritizi.map((w) => ({ sign: "▼", word: "Přitížilo by:", w })),
    ...v.pomuze.map((w) => ({ sign: "▲", word: "Pomohlo by:", w })),
  ];
  return all.find((x) => !condition(x.w.zdroj)) ?? all[0] ?? null;
}

export function relevance(v: BillView): string {
  const roles = v.roles.filter((r) => r.key !== "vsichni");
  if (v.roles.some((r) => r.key === "vsichni")) return "Týká se prakticky všech";
  if (roles.length) return `Týká se: ${roles.slice(0, 3).map((r) => ROLE_LABEL[r.key].toLowerCase()).join(", ")}${roles.length > 3 ? " a dalších" : ""}`;
  if (v.agenda === "primy" && v.agendaNote) return `Týká se: ${v.agendaNote}`;
  return AGENDA_LABEL[v.agenda];
}

/**
 * Úroveň 1: občanská karta. Pořadí podle zadání 2.1: titulek, jedna věta praktického významu,
 * osobní relevance (u filtru i proč), stav a naléhavost v jednom řádku, cesta k podrobnostem.
 */
export default function BillCard({ v }: { v: BillView }) {
  const impact = keyImpact(v);
  const cond = impact ? condition(impact.w.zdroj) : null;
  return (
    <article className="card" data-roles={v.roles.map((r) => r.key).join(" ")} data-ct={v.ct}>
      <h3>
        <Link prefetch={false} href={`/tisk/${v.ct}`} className="stretch">{v.title}</Link>
      </h3>
      {impact ? (
        <p className={`impact ${impact.sign === "▲" ? "pm-plus" : "pm-minus"}`}>
          <span className="pm-sign" aria-hidden="true">{impact.sign}</span>{" "}
          <span className="pm-word">{impact.word}</span> {impact.w.kdo.toLowerCase()}: {impact.w.jak}
          {cond && <span className="pm-cond"> ({cond})</span>}
        </p>
      ) : (
        v.summary && v.title !== v.summary && <p className="perex">{v.summary}</p>
      )}
      <p className="roles">
        {relevance(v)}
        {v.roles.map((r) => (
          <span key={r.key} className="why" data-role={r.key}> · u vás jako „{ROLE_LABEL[r.key].toLowerCase()}“, protože {r.why}</span>
        ))}
      </p>
      <p className="state">
        <span className={`status status-${v.status.kind}`}>{v.status.short}</span>
        {v.uroven === "major" && <span className="flag-text"> · Hlídač: závažný postup</span>}
      </p>
      {v.stale && <p className="stale">{v.stale}</p>}
      <p className="card-links">
        <span className="sub">{v.title !== v.law ? v.law : `Tisk ${v.ct}`}</span>
        <a href={v.url} className="to-hlidac">Na Hlídači zákonů ↗</a>
      </p>
    </article>
  );
}

/** Kompaktní řádek pro dlouhé seznamy. */
export function BillRow({ v }: { v: BillView }) {
  return (
    <li data-roles={v.roles.map((r) => r.key).join(" ")} data-ct={v.ct}>
      <Link prefetch={false} href={`/tisk/${v.ct}`}>
        <strong>{v.title}</strong>
        <span>{v.status.short} · {relevance(v).toLowerCase()}</span>
      </Link>
    </li>
  );
}
