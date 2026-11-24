import Link from "next/link";
import type { BillView } from "@/lib/model";
import { ROLE_LABEL } from "@/lib/roles";
import { condition, type Who } from "@/lib/situace";

function Impact({ sign, word, w }: { sign: string; word: string; w?: Who }) {
  if (!w) return null;
  const cond = condition(w.zdroj);
  return (
    <li className={sign === "▲" ? "pm-plus" : "pm-minus"}>
      <span className="pm-sign" aria-hidden="true">{sign}</span>
      <span>
        <span className="pm-word">{word}</span> <b>{w.kdo}</b>: {w.jak}
        {cond && <span className="pm-cond"> ({cond})</span>}
      </span>
    </li>
  );
}

/** Úroveň 1: občanská karta. Titulek o změně v životě, dopad, koho se týká, stav, odpověď „musím něco udělat?“. */
export default function BillCard({ v }: { v: BillView }) {
  const more = v.pomuze.length + v.pritizi.length - (v.pomuze.length ? 1 : 0) - (v.pritizi.length ? 1 : 0);
  return (
    <article className="card" data-roles={v.roles.map((r) => r.key).join(" ")}>
      <div className="meta">
        <span className={`status status-${v.status.kind}`}>{v.status.label}</span>
        {v.uroven === "major" && <span className="flag">Hlídač: závažný postup</span>}
        {v.uroven === "watch" && <span className="flag flag-soft">Hlídač: pozor na postup</span>}
      </div>
      <h3>
        <Link prefetch={false} href={`/tisk/${v.ct}`} className="stretch">{v.title}</Link>
      </h3>
      {v.title !== v.law && <p className="sub">{v.law}</p>}
      {v.pomuze.length + v.pritizi.length > 0 ? (
        <ul className="pm">
          <Impact sign="▲" word="Pomůže:" w={v.pomuze[0]} />
          <Impact sign="▼" word="Přitíží:" w={v.pritizi[0]} />
          {more > 0 && <li className="pm-more">a {more} další dopad{more > 1 ? "y" : ""} v detailu</li>}
        </ul>
      ) : (
        v.summary && <p className="perex">{v.summary}</p>
      )}
      {v.roles.length > 0 && (
        <p className="roles">
          <span className="roles-label">Týká se:</span>{" "}
          {v.roles.map((r, i) => (
            <span key={r.key} className="role" data-role={r.key}>
              {ROLE_LABEL[r.key]}
              <span className="why" data-role={r.key}> (proč: {r.why})</span>
              {i < v.roles.length - 1 ? ", " : ""}
            </span>
          ))}
        </p>
      )}
      <p className="act"><b>Musím něco udělat?</b> {v.status.act}</p>
      {v.stale && <p className="stale">{v.stale}</p>}
    </article>
  );
}

/** Kompaktní řádek pro dlouhé seznamy. */
export function BillRow({ v }: { v: BillView }) {
  return (
    <li data-roles={v.roles.map((r) => r.key).join(" ")}>
      <Link prefetch={false} href={`/tisk/${v.ct}`}>
        <strong>{v.title}</strong>
        <span>
          {v.status.label}
          {v.roles.length > 0 ? ` · týká se: ${v.roles.map((r) => ROLE_LABEL[r.key].toLowerCase()).join(", ")}` : ""}
          {v.uroven === "major" ? " · Hlídač: závažný postup" : ""}
        </span>
      </Link>
    </li>
  );
}
