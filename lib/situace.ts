// Koho se tisk týká a „v nejhorším případě“ (data/situace.json, citace ověřuje scripts/check-situace.mjs).
// Synchronizace s Hlídačem zákonů:
//  - když Hlídač vydá dopad.scenare (rozbor AI s ověřenými citacemi), mají jeho data přednost před ručními,
//  - položky podmíněné pozměňovacím návrhem se řídí výsledkem z API (vysledky_pn): prošlo = přeznačí se,
//    padlo nebo nezaznělo = zmizí, čeká = zůstane „jen pokud projde“.
import data from "@/data/situace.json";
import type { BillDetail, PnResult } from "./api";

export type Who = { kdo: string; jak: string; zdroj: string };
export type Worst = { titulek: string; pribeh: string; podminka: string; pojistka: string; citace: string[]; zdroj: string };
export type Situation = { faze?: string; koho: Who[]; nejhorsi: Worst[] };

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
  if (upstream.length > 0) {
    return {
      koho: [],
      nejhorsi: upstream.map((x) => ({
        titulek: x.titulek, pribeh: x.kdo ? `${x.kdo}: ${x.pribeh}` : x.pribeh, podminka: "Z rozboru textu na Hlídači zákonů",
        pojistka: x.pojistka, citace: [x.citace], zdroj: "rozbor AI",
      })),
    };
  }
  const local = situace(ct);
  if (!local) return null;
  const results = detail?.vysledky_pn ?? [];
  if (results.length === 0) return local;

  // 2) podmínky pozměňovacích návrhů podle výsledků hlasování
  const koho = local.koho.flatMap((k) => {
    const v = verdict(pnNumbers(k.zdroj), results);
    if (v === "dead") return [];
    if (v === "passed") return [{ ...k, zdroj: k.zdroj.replace(/, (jen pokud projd(?:e|ou)|výsledek zatím nelze určit)$/, ", prošlo") }];
    if (v === "partly") return [{ ...k, zdroj: k.zdroj.replace(/, (jen pokud projd(?:e|ou)|výsledek zatím nelze určit)$/, ", prošlo zčásti") }];
    return [k];
  });
  const nejhorsi = local.nejhorsi.flatMap((w) => {
    const v = verdict(pnNumbers(w.zdroj), results);
    if (v === "dead") return [];
    if (v === "passed") return [{ ...w, podminka: `Pozměňovací návrh ${w.zdroj.replace(/^PN /, "")} prošel` }];
    if (v === "partly") return [{ ...w, podminka: `Pozměňovací návrh ${w.zdroj.replace(/^PN /, "")} prošel zčásti` }];
    return [w];
  });
  return koho.length === 0 && nejhorsi.length === 0 ? null : { ...local, koho, nejhorsi };
}
