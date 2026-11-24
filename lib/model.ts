// Občanský pohled na tisk: z dat Hlídače zákonů a podkladů Občanské vrstvy složí vše, co potřebuje karta,
// detail i hledání. Tři oddělené dimenze, nikdy jedno souhrnné skóre:
//  - občanská relevance: role (koho se týká, s důvodem), komu pomůže / přitíží,
//  - časová naléhavost: stav návrhu, účinnost, odpověď „Musím už něco udělat?“,
//  - legislativní rizikovost: původní hodnocení Hlídače zákonů beze změny (úroveň, skóre, důvody).
import type { Bill, BillDetail } from "./api";
import { agendaOf, type Agenda } from "./agenda";
import { classify, ROLE_LABEL, type RoleHit, type RoleKey } from "./roles";
import { resolved, situace, type Situation, type Who } from "./situace";
import { civicStatus, type CivicStatus } from "./status";
import { humanName, stepIndex } from "./text";

export type Coverage = "plne" | "castecne" | "zadne";

export type BillView = {
  ct: number;
  title: string; // občanský titulek
  titleSource: "rozbor Hlídače" | "Občanská vrstva" | "název návrhu";
  law: string; // lidský název zákona
  summary: string | null; // jedna věta, co se mění
  summarySource: string | null;
  roles: RoleHit[];
  pomuze: Who[];
  pritizi: Who[];
  status: CivicStatus;
  ucinnost: string | null;
  faze: string;
  step: number;
  posledni: string | null;
  uroven: string;
  skore: number;
  hlidacReason: string | null; // hlavní důvod hodnocení Hlídače (jedna věta)
  url: string;
  psp_url: string;
  coverage: Coverage;
  stale: string | null; // podklady psané pro jinou fázi
  extra: string; // další text pro hledání (koho se týká)
  agenda: Agenda; // typ občanského dopadu (přímý, nepřímý, chod státu, nejasný)
  agendaNote: string | null; // u přímého dopadu bez role: koho (např. „cizinci“)
  pnSig: string; // rozhodnuté pozměňovací návrhy (jen u tisků s načteným detailem), pro „Od minula“
};

/**
 * Věta z rozboru AI uložená v důvodech skóre Hlídače („Rozbor textu návrhu: závažnost 6/10 – …“).
 * Rozlišuje text návrhu a pozměňovací návrh, aby se jeden nevydával za druhý.
 */
export function aiSentence(b: Bill): { text: string; pn: string | null } | null {
  for (const key of ["ai_bill", "ai"]) {
    const r = b.duvody.find((d) => d.klic === key);
    const m = r?.popis.match(/závažnost \d+\/10\s*[\u2013\u2014-]\s*(.+?)(?:;|$)/);
    if (m) return { text: m[1].trim(), pn: key === "ai" ? (r?.popis.match(/PN č\. (\d+)/)?.[1] ?? "") : null };
  }
  return null;
}

function mainReason(b: Bill): string | null {
  const top = [...b.duvody].filter((d) => d.body > 0).sort((x, y) => y.body - x.body)[0];
  if (!top) return null;
  return top.popis.replace(/\s*[–—]\s*/g, ": ").replace(/;.*$/, "");
}

export function view(b: Bill, detail?: BillDetail | null): BillView {
  // ručně psané vysvětlení, které denní kontrola označila jako možná zastaralé (jiný text návrhu), se nezobrazuje
  const raw = situace(b.ct);
  const hidden = raw?.skryto ?? null;
  const local = hidden ? null : raw;
  const sit: Situation | null = hidden ? null : resolved(b.ct, detail ?? null) ?? local;
  const law = humanName(b.nazev, b.uplny_nazev);
  const upTitle = b.rozbor?.titulek || detail?.dopad?.zbystrit || null;
  const civic = upTitle || sit?.zbystrit || null;
  const ai = aiSentence(b);
  const roleSources = [
    { label: "vysvětlení", text: [civic ?? "", ...(sit?.pomuze ?? []), ...(sit?.pritizi ?? []), ...(sit?.koho ?? [])].map((x) => (typeof x === "string" ? x : `${x.kdo} ${x.jak}`)).join(" · ") },
    { label: "název", text: b.uplny_nazev.replace(/^Návrh (?:posla|zastupitel)[^,]*? na vydání /i, "") },
    ...(ai ? [{ label: "rozbor Hlídače", text: ai.text }] : []),
  ];
  // nejdřív dopady přímo z textu návrhu, pak ty, které závisí na pozměňovacím návrhu
  const textFirst = (l: Who[] = []) => [...l].sort((a, b) => Number(a.zdroj.startsWith("PN")) - Number(b.zdroj.startsWith("PN")));
  const pomuze = textFirst(sit?.pomuze);
  const pritizi = textFirst(sit?.pritizi);
  // role: u ručně ověřených podkladů platí ruční výběr (audit 2.1), jinak automatická pravidla
  const auto = classify(roleSources);
  const roles: RoleHit[] = local?.role
    ? local.role.filter((k): k is RoleKey => k in ROLE_LABEL).map((k) => auto.find((a) => a.key === k) ?? { key: k, why: "ručně ověřeno z vysvětlení" })
    : auto;
  const ag = local?.dopad
    ? { kind: local.dopad as Agenda, note: local.uzka ?? null }
    : agendaOf(`${b.uplny_nazev} ${civic ?? ""}`, roles.length > 0, Boolean(civic));
  return {
    agenda: ag.kind,
    agendaNote: local?.uzka ?? ag.note,
    ct: b.ct,
    title: civic ?? law,
    titleSource: upTitle ? "rozbor Hlídače" : civic ? "Občanská vrstva" : "název návrhu",
    law,
    summary: b.rozbor?.perex || (ai ? (ai.pn !== null ? `Pozměňovací návrh${ai.pn ? ` č. ${ai.pn}` : ""}: ${ai.text}` : ai.text) : null),
    summarySource: b.rozbor?.perex || (ai && ai.pn === null) ? "rozbor textu návrhu na Hlídači zákonů (AI)" : ai ? "rozbor pozměňovacího návrhu na Hlídači zákonů (AI); platí, jen pokud projde" : null,
    roles,
    pomuze,
    pritizi,
    status: civicStatus(b.faze),
    ucinnost: local?.ucinnost ?? null,
    faze: b.faze,
    step: stepIndex(b.faze),
    posledni: b.posledni_zmena,
    uroven: b.uroven,
    skore: b.skore,
    hlidacReason: mainReason(b),
    url: b.url,
    psp_url: b.psp_url,
    coverage: civic && (pomuze.length || pritizi.length || upTitle) ? "plne" : ai ? "castecne" : "zadne",
    pnSig: (detail?.vysledky_pn ?? []).filter((r) => !["ceka", "neurceno"].includes(r.stav)).map((r) => `${r.cislo}:${r.stav}`).sort().join(","),
    extra: (sit?.koho ?? []).map((k) => `${k.kdo} ${k.jak}`).join(" "),
    stale: hidden
      ? `Občanské vysvětlení je dočasně skryté: ${hidden}`
      : local?.faze && local.faze !== b.faze ? `Vysvětlení jsme psali ve fázi „${local.faze}“, návrh je teď ve fázi „${b.faze}“. Podmínky pozměňovacích návrhů hlídáme automaticky, zbytek může být zastaralý.` : null,
  };
}
