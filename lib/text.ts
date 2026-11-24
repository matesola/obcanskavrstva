// Převod sněmovních zkratek a dat do lidské řeči.
import type { Bill, BillDetail } from "./api";

// číselník skupin, shodný s app/Impact.php v Hlídači zákonů
export const GROUPS: Record<string, string> = {
  vsichni: "Všichni",
  zamestnanci: "Zaměstnanci",
  zamestnavatele: "Zaměstnavatelé",
  osvc: "OSVČ a živnostníci",
  firmy: "Firmy",
  rodiny: "Rodiny s dětmi",
  seniori: "Důchodci",
  pecujici: "Pečující o blízké",
  zdravotne_postizeni: "Lidé s postižením",
  pacienti: "Pacienti",
  studenti: "Žáci a studenti",
  bydleni: "Nájemníci a vlastníci bytů",
  ridici: "Řidiči",
  dluznici: "Dlužníci a exekuce",
  poplatnici: "Daňoví poplatníci",
  prijemci_davek: "Příjemci dávek",
  nezamestnani: "Nezaměstnaní",
  zemedelci: "Zemědělci",
  chovatele: "Chovatelé zvířat",
  drzitele_zbrani: "Myslivci a držitelé zbraní",
  prijemci_dotaci: "Příjemci dotací",
  spotrebitele: "Spotřebitelé",
  cizinci: "Cizinci",
  obce: "Obce a kraje",
};

/** Zkratky ze sněmovního krátkého názvu („Novela z. o …“, „Vl.n.z.“). Jen záloha, když nejde použít úplný název. */
const ABBR: [RegExp, string][] = [
  [/někt\.\s?/g, "některých "], [/opatř\.\s?/g, "opatřeních "], [/souv\.\s?/g, "souvislosti "],
  [/ozbroj\.\s?konf\.\s?/g, "ozbrojeným konfliktem "], [/způsob\.\s?/g, "způsobilosti "], [/zdrav\.\s?/g, "zdravotnického "],
  [/podmín\.\s?/g, "podmínkách "], [/trest\.(\s|$)/g, "trestních$1"], [/urč\.\s?/g, "určování "], [/provoz\.\s?/g, "provozoven "],
  [/shrom\.\s?/g, "shromažďování "], [/\s*-\s*související$/g, " (související změny)"], [/kt\.\s?/g, "kterým "], [/ústav\.\s?/g, "ústavního "],
];

function expandShort(nazev: string): string {
  let n = nazev;
  for (const [re, to] of ABBR) n = n.replace(re, to);
  return n
    .replace(/^Vl\.\s?n\.\s?z\.,?\s*/, "Zákon ")
    .replace(/^N\.\s?z\.,?\s*/, "Zákon ")
    .replace(/^Novela z\. - /, "Novela: ")
    .replace(/^Novela ústav\. z\. - /, "Novela ústavy: ")
    .replace(/\bz\. o /g, "zákona o ")
    .replace(/\bz\. na /g, "zákona na ")
    .replace(/\s+[-\u2013\u2014]\s+/g, ": ")
    .trim();
}

const cap = (s: string) => s.charAt(0).toUpperCase() + s.slice(1);

/**
 * Lidský název z úplného názvu tisku: „Vládní návrh zákona, kterým se mění zákon č. 586/1992 Sb., o daních z příjmů, …“
 * → „Novela zákona o daních z příjmů“. Když úplný název nejde rozebrat, rozepíše zkratky z krátkého názvu.
 */
export function humanName(nazev: string, uplny?: string): string {
  const fromFull = fromFullName(uplny ?? "");
  // dlouhý úplný název (výčty, celé názvy orgánů) nahradí krátký, pokud nemá nesrozumitelné zkratky
  const short = expandShort(nazev);
  const shortHasAbbr = /[a-zá-ž]\.[a-zá-ž]/i.test(short) || /[a-zá-ž]{1,6}\.(\s|,|$)/i.test(short.replace(/\bč\.|\bSb\./g, ""));
  if (fromFull && (fromFull.length <= 72 || shortHasAbbr || nazev === uplny)) return fromFull;
  return shortHasAbbr && fromFull ? fromFull : short;
}

function fromFullName(uplny: string): string | null {
  const full = (uplny ?? "").replace(/\s+/g, " ").trim();
  const m = full.match(/(ústavního )?zákona,? (.+)$/);
  if (m) {
    const constitutional = Boolean(m[1]);
    const tail = m[2];
    const amend = tail.match(/^kterým se mění (?:ústavní )?zákon č\. [\d/]+ Sb\., (?:kterým se mění zákon č\. [\d/]+ Sb\., )?(.+)$/);
    if (amend) {
      // krátké jméno v závorce má přednost: „(zákon o silničním provozu)“, „(energetický zákon)“
      const paren = amend[1].match(/\((zákon o [^)]+|[^)]*zákon[^)]*|[^)]*řád|[^)]*zákoník)\)/);
      let subject = paren ? paren[1] : amend[1].split(/, ve znění|, a (?:další|některé|zákon)| a o změn|, kterým/)[0];
      subject = subject.replace(/,$/, "").trim();
      if (constitutional) return `Novela ústavního zákona: ${subject}`;
      if (/^zákon o /.test(subject)) return `Novela ${subject.replace(/^zákon/, "zákona")}`;
      if (/^(o|na) /.test(subject)) return `Novela zákona ${subject}`;
      return `Novela: ${subject}`;
    }
    const repeal = tail.match(/^kterým se zrušuje (.+)$/);
    if (repeal) return cap(`zrušení ${repeal[1].replace(/^zákon\b/, "zákona")}`);
    const many = tail.match(/^kterým se mění (.+)$/);
    if (many) return cap(`změna ${many[1].replace(/^některé zákony/, "některých zákonů").replace(/^zákony/, "zákonů")}`.split(/, ve znění/)[0]);
    const fresh = tail.match(/^(o .+)$/);
    const freshParen = tail.match(/\((zákon o [^)]+|[^)]*zákon)\)/);
    if (fresh && freshParen) return `Nový ${freshParen[1]}`;
    if (fresh) return `${constitutional ? "Ústavní zákon" : "Nový zákon"} ${fresh[1].split(/ a o změně| a o zrušení/)[0]}`.replace(/^Nový zákon o státním rozpočtu/, "Zákon o státním rozpočtu");
  }
  return null;
}

/** Věta z rozboru AI uložená v důvodech skóre („Rozbor textu návrhu: závažnost 6/10 – …“). */
function reasonSentence(b: Bill): string | null {
  for (const key of ["ai_bill", "ai"]) {
    const r = b.duvody.find((d) => d.klic === key);
    if (!r) continue;
    const m = r.popis.match(/závažnost \d+\/10\s*[–—-]\s*(.+?)(?:;|$)/);
    if (m) return m[1].trim();
  }
  return null;
}

/** Nadpis karty: srozumitelný titulek z rozboru, jinak lidsky přepsaný název. */
export function title(b: Bill | BillDetail): string {
  const d = b as BillDetail;
  return b.rozbor?.titulek || d.hlavni_nalez?.titulek || humanName(b.nazev, b.uplny_nazev);
}

/** Podnadpis: perex z rozboru, jinak věta z důvodů skóre. Když je nadpis z rozboru, ukážeme pod ním název zákona. */
export function subtitle(b: Bill | BillDetail): string | null {
  const d = b as BillDetail;
  if (b.rozbor?.titulek || d.hlavni_nalez?.titulek) return humanName(b.nazev, b.uplny_nazev);
  return reasonSentence(b);
}

export function perex(b: Bill | BillDetail): string | null {
  const d = b as BillDetail;
  return b.rozbor?.perex || d.hlavni_nalez?.perex || (b.rozbor || d.hlavni_nalez ? reasonSentence(b) : null);
}

// cesta zákonem: index kroku podle fáze z Hlídače (app/Analyzer.php)
export const STEPS = ["Návrh", "1. čtení", "2. čtení", "3. čtení", "Senát", "Prezident", "Platí"];

export function stepIndex(faze: string): number {
  if (["Podáno", "Organizační výbor", "U vlády", "Stanovisko vlády"].includes(faze)) return 0;
  if (faze === "1. čtení") return 1;
  if (faze === "2. čtení") return 2;
  if (faze === "3. čtení") return 3;
  if (["Schváleno Sněmovnou", "V Senátu", "Sněmovna po Senátu"].includes(faze)) return 4;
  if (["U prezidenta", "Sněmovna po vetu"].includes(faze)) return 5;
  if (faze === "Vyhlášeno") return 6;
  return -1; // vzat zpět, zamítnut, vrácen
}

export function stopped(faze: string): boolean {
  return stepIndex(faze) === -1;
}

export function nextStep(faze: string): string {
  switch (stepIndex(faze)) {
    case 0: return "Čeká na první čtení ve Sněmovně";
    case 1: return "Sněmovna rozhodne, zda návrh pustí dál";
    case 2: return "Poslanci podávají pozměňovací návrhy";
    case 3: return "Závěrečné hlasování ve Sněmovně";
    case 4: return "Rozhoduje Senát";
    case 5: return "Podpis prezidenta";
    case 6: return "Vyšlo ve Sbírce zákonů";
    default: return `Návrh skončil: ${faze.toLowerCase()}`;
  }
}

export const LEVEL: Record<string, string> = { major: "Závažné", watch: "Pozor", normal: "Běžné" };

export function date(iso: string | null | undefined): string {
  if (!iso) return "";
  const [y, m, d] = iso.slice(0, 10).split("-").map(Number);
  return `${d}. ${m}. ${y}`;
}

export function kind(typ: string): string {
  return { "vládní": "vládní návrh", "poslanecký": "návrh poslanců", "senátní": "návrh Senátu", "krajský": "návrh kraje" }[typ] ?? typ;
}
