"use client";
import { useEffect, useState } from "react";
import { VISIBLE_ROLES, type RoleKey } from "@/lib/roles";

const KEY = "vrstva-role";
const MODE = "vrstva-rezim";
type Mode = "osobni" | "vse";

/** Výběr rolí čtou i další komponenty (Od poslední návštěvy). */
export function readRoles(): RoleKey[] {
  try {
    const saved = JSON.parse(localStorage.getItem(KEY) ?? "[]");
    return Array.isArray(saved) ? saved.filter((r) => VISIBLE_ROLES.some((x) => x.key === r)) : [];
  } catch {
    return [];
  }
}

function matches(el: HTMLElement, picked: RoleKey[]): boolean {
  const roles = (el.dataset.roles ?? "").split(" ").filter(Boolean);
  return roles.includes("vsichni") || roles.some((r) => picked.includes(r as RoleKey));
}

/**
 * „Co se vás týká?“ Výběr rolí řídí celý radar: karty, seznamy, vyhlášené zákony, připravované změny
 * i blok Od poslední návštěvy. Více rolí najednou, žádné duplicity (filtruje se každá položka jednou),
 * přepínač Můj přehled / Všechno, uloženo jen v prohlížeči.
 */
export default function Radar() {
  const [picked, setPicked] = useState<RoleKey[]>([]);
  const [mode, setMode] = useState<Mode>("osobni");
  const [open, setOpen] = useState(false);
  const [ready, setReady] = useState(false);
  const [counts, setCounts] = useState<Record<string, number>>({});
  const [total, setTotal] = useState(0);

  useEffect(() => {
    setPicked(readRoles());
    try { setMode((localStorage.getItem(MODE) as Mode) || "osobni"); } catch {}
    // počty: unikátní návrhy na stránce, které se roli týkají (včetně změn, které se týkají všech)
    const els = [...document.querySelectorAll<HTMLElement>("[data-roles][data-ct]")];
    const c: Record<string, number> = {};
    for (const r of VISIBLE_ROLES) c[r.key] = new Set(els.filter((e) => matches(e, [r.key])).map((e) => e.dataset.ct)).size;
    setCounts(c);
    setReady(true);
  }, []);

  useEffect(() => {
    if (!ready) return;
    try { localStorage.setItem(KEY, JSON.stringify(picked)); localStorage.setItem(MODE, mode); } catch {}
    window.dispatchEvent(new Event("vrstva-role"));
    const active = picked.length > 0 && mode === "osobni";
    document.documentElement.dataset.sel = active ? picked.join(" ") : "";
    const els = [...document.querySelectorAll<HTMLElement>("[data-roles]")];
    els.forEach((el) => { el.hidden = active && !matches(el, picked); });
    setTotal(new Set(els.filter((e) => !e.hidden && e.dataset.ct).map((e) => e.dataset.ct)).size);
    document.querySelectorAll<HTMLDetailsElement>("details.more").forEach((det) => {
      if (active) det.open = [...det.querySelectorAll<HTMLElement>("[data-roles]")].some((el) => !el.hidden);
    });
    document.querySelectorAll<HTMLElement>("[data-section]").forEach((sec) => {
      const any = [...sec.querySelectorAll<HTMLElement>("[data-roles]")].some((el) => !el.hidden);
      const note = sec.querySelector<HTMLElement>(".empty-sel");
      if (note) note.hidden = !active || any;
    });
  }, [picked, mode, ready]);

  const toggle = (r: RoleKey) => setPicked((p) => (p.includes(r) ? p.filter((x) => x !== r) : [...p, r]));
  const roles = VISIBLE_ROLES.filter((r) => (counts[r.key] ?? 0) > 0).sort((a, b) => (counts[b.key] ?? 0) - (counts[a.key] ?? 0));
  const names = picked.map((p) => VISIBLE_ROLES.find((r) => r.key === p)?.label.toLowerCase()).join(", ");

  return (
    <section className="radar" aria-label="Co se vás týká">
      <div className="radar-bar">
        {picked.length === 0 ? (
          <span><b>Co se vás týká?</b></span>
        ) : (
          <span>
            <b>{mode === "osobni" ? `Váš přehled (${total})` : "Všechno"}</b>
            <span className="radar-names">{names}</span>
          </span>
        )}
        <span className="radar-actions">
          {picked.length > 0 && (
            <button type="button" className="linkish" onClick={() => setMode(mode === "osobni" ? "vse" : "osobni")}>
              {mode === "osobni" ? "Ukázat všechno" : "Jen moje"}
            </button>
          )}
          <button type="button" className="pick" aria-expanded={open} onClick={() => setOpen(!open)}>
            {picked.length === 0 ? "Vybrat" : open ? "Hotovo" : "Upravit"}
          </button>
        </span>
      </div>
      {open && (
        <div className="radar-panel">
          <p className="lead">Vyberte, co se vás týká, třeba i kvůli rodině nebo zaměstnancům. Číslo je počet návrhů na této stránce. Výběr zůstane jen ve vašem prohlížeči.</p>
          <div className="chips" role="group" aria-label="Co se vás týká">
            {roles.map((r) => (
              <button key={r.key} type="button" aria-pressed={picked.includes(r.key)} onClick={() => toggle(r.key)}>
                {r.label} <span className="count">{counts[r.key]}</span>
              </button>
            ))}
          </div>
          {picked.length > 0 && (
            <p className="sel-note"><button type="button" className="linkish" onClick={() => setPicked([])}>Zrušit výběr</button></p>
          )}
        </div>
      )}
    </section>
  );
}
