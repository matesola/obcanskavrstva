// Koho se tisk týká a „v nejhorším případě“ (data/situace.json, citace ověřuje scripts/check-situace.mjs).
import data from "@/data/situace.json";

export type Who = { kdo: string; jak: string; zdroj: string };
export type Worst = { titulek: string; pribeh: string; podminka: string; pojistka: string; citace: string[]; zdroj: string };
export type Situation = { koho: Who[]; nejhorsi: Worst[] };

const all = (data as { tisky: Record<string, Situation> }).tisky;

export function situace(ct: number): Situation | null {
  return all[String(ct)] ?? null;
}

/** Jen přímo v textu (ne podmíněné pozměňovacím návrhem)? */
export function certain(w: Worst): boolean {
  return w.podminka.startsWith("Přímo");
}
