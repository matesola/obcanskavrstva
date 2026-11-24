"use client";
import { useEffect, useState } from "react";
import { GROUPS } from "@/lib/text";

const KEY = "vrstva-skupiny";

/**
 * Filtr „Týká se mě“: výběr skupin se pamatuje v prohlížeči (bez účtu a e-mailu)
 * a skrývá karty, které se vybraných skupin netýkají. Karty nesou data-groups.
 */
export default function GroupFilter({ available }: { available: string[] }) {
  const [picked, setPicked] = useState<string[]>([]);

  useEffect(() => {
    try {
      const saved = JSON.parse(localStorage.getItem(KEY) ?? "[]");
      if (Array.isArray(saved)) setPicked(saved.filter((g) => typeof g === "string" && g in GROUPS));
    } catch {}
  }, []);

  useEffect(() => {
    try { localStorage.setItem(KEY, JSON.stringify(picked)); } catch {}
    document.querySelectorAll<HTMLElement>("[data-groups]").forEach((el) => {
      const groups = (el.dataset.groups ?? "").split(" ").filter(Boolean);
      el.hidden = picked.length > 0 && !groups.some((g) => picked.includes(g) || g === "vsichni");
    });
  }, [picked]);

  const toggle = (g: string) => setPicked((p) => (p.includes(g) ? p.filter((x) => x !== g) : [...p, g]));
  const ready = available.length > 0;

  return (
    <details className="filter" open={picked.length > 0}>
      <summary>Týká se mě{picked.length > 0 ? ` (${picked.length})` : ""}</summary>
      {ready ? (
        <div className="chips">
          {Object.keys(GROUPS).filter((g) => g !== "vsichni" && available.includes(g)).map((g) => (
            <button key={g} type="button" aria-pressed={picked.includes(g)} onClick={() => toggle(g)}>
              {GROUPS[g]}
            </button>
          ))}
        </div>
      ) : (
        <p className="note">Třídění podle toho, koho se zákon týká (zaměstnanci, OSVČ, rodiny, chovatelé…), přibude, jakmile ho Hlídač zákonů začne vydávat.</p>
      )}
    </details>
  );
}
