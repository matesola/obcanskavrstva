import Link from "next/link";
import type { Bill } from "@/lib/api";
import { GROUPS, LEVEL, date, kind, perex, subtitle, title } from "@/lib/text";
import Path from "./Path";
import { situace } from "@/lib/situace";

export default function BillCard({ b }: { b: Bill }) {
  const groups = b.rozbor?.skupiny ?? [];
  const p = perex(b);
  const sub = subtitle(b);
  const sit = situace(b.ct);
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
      {sub && <p className="sub">{sub}</p>}
      {p && !worst && <p className="perex">{p}</p>}
      {sit && (
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
