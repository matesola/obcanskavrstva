"use client";
import { useEffect, useState } from "react";
import { ROLES, type RoleKey } from "@/lib/roles";

const KEY = "vrstva-role";

/**
 * „Co se vás týká?“ Výběr životních rolí: více najednou, uložení jen v prohlížeči (žádný účet, žádná data
 * nikam neodcházejí), snadné zrušení. Filtruje karty a řádky s data-roles a u karet ukáže, proč se zobrazují.
 */
export default function Radar({ counts }: { counts: Partial<Record<RoleKey, number>> }) {
  const [picked, setPicked] = useState<RoleKey[]>([]);
  const [ready, setReady] = useState(false);
  const [all, setAll] = useState(false);

  useEffect(() => {
    try {
      const saved = JSON.parse(localStorage.getItem(KEY) ?? "[]");
      if (Array.isArray(saved)) setPicked(saved.filter((r): r is RoleKey => ROLES.some((x) => x.key === r)));
    } catch {}
    setReady(true);
  }, []);

  useEffect(() => {
    if (!ready) return;
    try { localStorage.setItem(KEY, JSON.stringify(picked)); } catch {}
    document.documentElement.dataset.sel = picked.join(" ");
    document.querySelectorAll<HTMLElement>("[data-roles]").forEach((el) => {
      const roles = (el.dataset.roles ?? "").split(" ").filter(Boolean);
      el.hidden = picked.length > 0 && !roles.some((r) => picked.includes(r as RoleKey));
    });
    // seznamy „Další…“: při výběru rolí je otevřeme, jinak by relevantní návrhy zůstaly schované
    document.querySelectorAll<HTMLDetailsElement>("details.more").forEach((det) => {
      const visible = [...det.querySelectorAll<HTMLElement>("[data-roles]")].some((el) => !el.hidden);
      if (picked.length > 0) det.open = visible;
    });
    // prázdné oddíly: místo prázdna věta
    document.querySelectorAll<HTMLElement>("[data-section]").forEach((sec) => {
      const visible = [...sec.querySelectorAll<HTMLElement>("[data-roles]")].some((el) => !el.hidden);
      const note = sec.querySelector<HTMLElement>(".empty-sel");
      if (note) note.hidden = picked.length === 0 || visible;
    });
  }, [picked, ready]);

  const toggle = (r: RoleKey) => setPicked((p) => (p.includes(r) ? p.filter((x) => x !== r) : [...p, r]));
  // nejčastější role hned, ostatní po rozbalení (na mobilu by jinak zabraly celou obrazovku)
  const roles = ROLES.filter((r) => (counts[r.key] ?? 0) > 0).sort((a, b) => (counts[b.key] ?? 0) - (counts[a.key] ?? 0));
  const shown = all ? roles : roles.filter((r, i) => i < 7 || picked.includes(r.key));

  return (
    <section className="radar" aria-labelledby="radar-h">
      <h2 id="radar-h">Co se vás týká?</h2>
      <p className="lead">Vyberte, kým jste. Ukážeme jen návrhy, které se vás můžou týkat, a u každého proč. Výběr zůstane jen ve vašem prohlížeči.</p>
      <div className="chips" role="group" aria-label="Životní role">
        {shown.map((r) => (
          <button key={r.key} type="button" aria-pressed={picked.includes(r.key)} onClick={() => toggle(r.key)}>
            {r.label} <span className="count">{counts[r.key]}</span>
          </button>
        ))}
        {!all && roles.length > shown.length && (
          <button type="button" className="more-roles" onClick={() => setAll(true)}>Další role ({roles.length - shown.length})</button>
        )}
      </div>
      {picked.length > 0 && (
        <p className="sel-note">
          Filtrujeme podle {picked.length === 1 ? "role" : "rolí"}: {picked.map((p) => ROLES.find((r) => r.key === p)?.label).join(", ")}.{" "}
          <button type="button" className="linkish" onClick={() => setPicked([])}>Zobrazit všechno</button>
        </p>
      )}
    </section>
  );
}
