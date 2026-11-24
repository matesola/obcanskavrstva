// Koho se tisk týká a „v nejhorším případě“ (data/situace.json, citace ověřuje scripts/check-situace.mjs).
// Synchronizace s Hlídačem zákonů:
//  - když Hlídač vydá dopad.scenare (rozbor AI s ověřenými citacemi), mají jeho data přednost před ručními,
//  - položky podmíněné pozměňovacím návrhem se řídí výsledkem z API (vysledky_pn): prošlo = přeznačí se,
//    padlo nebo nezaznělo = zmizí, čeká = zůstane „jen pokud projde“.
import data from "@/data/situace.json";
import type { BillDetail, PnResult } from "./api";

export type Who = { kdo: string; jak: string; zdroj: string };
export type Worst = { titulek: string; pribeh: string; podminka: string; pojistka: string; citace: string[]; zdroj: string };
export type Situation = { faze?: string; zbystrit?: string; ucinnost?: string; srovnani?: { dnes: string; navrh: string; zdroj: string }; pomuze?: Who[]; pritizi?: Who[]; koho: Who[]; nejhorsi: Worst[] };

const all = (data as unknown as { tisky: Record<string, Situation> }).tisky;

export function situace(ct: number): Situation | null {
  return all[String(ct)] ?? null;
}

export function covered(): number[] {
  return Object.keys(all).map(Number);
}

/** Čísla PN, na kterých položka závisí („PN č. 2112 a 2114“ → [2112, 2114]). Prázdné = přímo v textu návrhu. */
function pnNumbers(text: string): number[] {
  const m = text.match(/PN č\. ([\d ,a]+)/);
  return m ? (m[1].match(/\d{3,5}/g) ?? []).map(Number) : [];
}

const PASSED = new Set(["prijat", "castecne"]);
const DEAD = new Set(["zamitnut", "nezaznel", "nehlasovatelny"]);

type Verdict = "passed" | "partly" | "dead" | "pending";

function verdict(nums: number[], results: PnResult[]): Verdict {
  if (nums.length === 0) return "pending";
  const states = nums.map((n) => results.find((r) => r.cislo === n)?.stav ?? "ceka");
  if (states.some((s) => PASSED.has(s))) return states.every((s) => s === "prijat") ? "passed" : "partly";
  if (states.every((s) => DEAD.has(s))) return "dead";
  return "pending";
}

/**
 * Situace pro tisk po sladění s aktuálními daty Hlídače. Null = nic k zobrazení.
 * Bez detailu tisku (vysledky_pn) vrací ruční podklady beze změny.
 */
export function resolved(ct: number, detail?: BillDetail | null): Situation | null {
  // 1) data z Hlídače mají přednost
  const upstream = detail?.dopad?.scenare ?? [];
  const up = detail?.dopad;
  if (up && (upstream.length > 0 || (up.pomuze ?? []).length > 0 || (up.pritizi ?? []).length > 0 || up.zbystrit)) {
    const fromUp = (l?: { [k: string]: string }[]) => (l ?? []).map((x) => ({ kdo: x.kdo, jak: x.jak, zdroj: "rozbor AI na Hlídači" }));
    const loc = situace(ct);
    return {
      zbystrit: up.zbystrit || loc?.zbystrit,
      pomuze: (up.pomuze ?? []).length ? fromUp(up.pomuze) : loc?.pomuze,
      pritizi: (up.pritizi ?? []).length ? fromUp(up.pritizi) : loc?.pritizi,
      koho: loc?.koho ?? [],
      nejhorsi: upstream.length > 0
        ? upstream.map((x) => ({
            titulek: x.titulek, pribeh: x.kdo ? `${x.kdo}: ${x.pribeh}` : x.pribeh, podminka: "Z rozboru textu na Hlídači zákonů",
            pojistka: x.pojistka, citace: [x.citace], zdroj: "rozbor AI",
          }))
        : loc?.nejhorsi ?? [],
    };
  }
  const local = situace(ct);
  if (!local) return null;
  const results = detail?.vysledky_pn ?? [];
  if (results.length === 0) return local;

  // 2) podmínky pozměňovacích návrhů podle výsledků hlasování
  const people = (list: Who[] = []) => list.flatMap((k) => {
    const v = verdict(pnNumbers(k.zdroj), results);
    if (v === "dead") return [];
    if (v === "passed") return [{ ...k, zdroj: k.zdroj.replace(/, (jen pokud projd(?:e|ou)|výsledek zatím nelze určit)$/, ", prošlo") }];
    if (v === "partly") return [{ ...k, zdroj: k.zdroj.replace(/, (jen pokud projd(?:e|ou)|výsledek zatím nelze určit)$/, ", prošlo zčásti") }];
    return [k];
  });
  const koho = people(local.koho);
  const pomuze = people(local.pomuze);
  const pritizi = people(local.pritizi);
  const nejhorsi = local.nejhorsi.flatMap((w) => {
    const v = verdict(pnNumbers(w.zdroj), results);
    if (v === "dead") return [];
    if (v === "passed") return [{ ...w, podminka: `Pozměňovací návrh ${w.zdroj.replace(/^PN /, "")} prošel` }];
    if (v === "partly") return [{ ...w, podminka: `Pozměňovací návrh ${w.zdroj.replace(/^PN /, "")} prošel zčásti` }];
    return [w];
  });
  return { ...local, koho, pomuze, pritizi, nejhorsi };
}

/** Krátká poznámka k podmíněné položce: „PN č. 2181, jen pokud projde“ (bez jména autora). */
export function condition(zdroj: string): string | null {
  return zdroj.startsWith("PN") ? zdroj.replace(/ \([^)]*\)/, "") : null;
}
