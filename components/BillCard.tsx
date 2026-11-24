import Link from "next/link";
import type { Bill } from "@/lib/api";
import { GROUPS, LEVEL, date, kind, perex, subtitle, title } from "@/lib/text";
import Path from "./Path";

export default function BillCard({ b }: { b: Bill }) {
  const groups = b.rozbor?.skupiny ?? [];
  const p = perex(b);
  const sub = subtitle(b);
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
      {p && <p className="perex">{p}</p>}
      {groups.length > 0 && (
        <ul className="groups">
          {groups.map((g) => <li key={g}>{GROUPS[g] ?? g}</li>)}
        </ul>
      )}
      <Path faze={b.faze} />
    </Link>
  );
}
