import Link from "next/link";
import type { Bill } from "@/lib/api";
import { GROUPS, LEVEL, date, kind, perex, subtitle, title } from "@/lib/text";
import Path from "./Path";
import { condition, type Situation, type Who } from "@/lib/situace";

function Side({ sign, label, list }: { sign: string; label: string; list?: Who[] }) {
  if (!list || list.length === 0) return null;
  return (
    <ul className={`pm pm-${sign === "▲" ? "plus" : "minus"}`} aria-label={label}>
      {list.slice(0, 2).map((w) => (
        <li key={w.kdo}>
          <span className="pm-sign" aria-hidden="true">{sign}</span>
          <span><b>{w.kdo}</b>: {w.jak}{condition(w.zdroj) && <span className="pm-cond"> ({condition(w.zdroj)})</span>}</span>
        </li>
      ))}
      {list.length > 2 && <li className="pm-more">a {list.length - 2} další v detailu</li>}
    </ul>
  );
}

export default function BillCard({ b, sit }: { b: Bill; sit?: Situation | null }) {
  const groups = b.rozbor?.skupiny ?? [];
  const p = perex(b);
  const sub = subtitle(b);
  const worst = b.uroven === "major" ? sit?.nejhorsi[0] : undefined;
  return (
    <Link href={`/tisk/${b.ct}`} className="card" data-groups={groups.join(" ")}>
      <div className="meta">
        {b.uroven !== "normal" && <span className={`level level-${b.uroven}`}>{LEVEL[b.uroven] ?? b.uroven}</span>}
        <span>Tisk {b.ct}</span>
        <span>{kind(b.typ_navrhu)}</span>
        {b.posledni_zmena && <span>{date(b.posledni_zmena)}</span>}
      </div>
      <h3>{title(b)}</h3>
      {sub && !(sit?.zbystrit && !b.rozbor?.titulek) && <p className="sub">{sub}</p>}
      {sit?.zbystrit && <p className="alert"><span className="alert-label">Proč zbystřit</span> {sit.zbystrit}</p>}
      {p && !worst && !sit?.zbystrit && <p className="perex">{p}</p>}
      <Side sign="▲" label="Komu pomůže" list={sit?.pomuze} />
      <Side sign="▼" label="Komu přitíží" list={sit?.pritizi} />
      {sit && sit.koho.length > 0 && !(sit.pomuze?.length || sit.pritizi?.length) && (
        <p className="who-line">
          <b>Koho:</b> {sit.koho.slice(0, 3).map((k) => k.kdo).join(" · ")}
          {sit.koho.length > 3 ? ` a další` : ""}
        </p>
      )}
      {worst && (
        <div className="worst-mini">
          <span className="worst-label">V nejhorším případě</span>
          <strong>{worst.titulek}</strong>
          <span className="cond">{worst.podminka}</span>
        </div>
      )}
      {groups.length > 0 && !sit && (
        <ul className="groups">
          {groups.map((g) => <li key={g}>{GROUPS[g] ?? g}</li>)}
        </ul>
      )}
      <Path faze={b.faze} />
    </Link>
  );
}
