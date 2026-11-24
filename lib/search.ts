// Hledání lidskou řečí: bez diakritiky, podle kmene slova (důchody → důchod), s občanskými synonymy
// (televize → poplatky, rozhlas; psi → zvířata). Jednoduché a předvídatelné, žádné AI.

/**
 * Pole s váhou: přímá tematická shoda (titulek, název zákona) > role > dopad (pomůže/přitíží, koho se týká)
 * > pouhá zmínka v shrnutí. U výsledku ukazujeme, kde se našel.
 */
export type SearchField = "titulek" | "zakon" | "role" | "dopad" | "shrnuti";
export type SearchItem = { ct: number; title: string; meta: string; fields: Partial<Record<SearchField, string>>; boost?: number };
export type SearchHit = SearchItem & { where: string };

const WEIGHT: Record<SearchField, number> = { titulek: 12, zakon: 10, role: 7, dopad: 5, shrnuti: 2 };
const WHERE: Record<SearchField, string> = { titulek: "v titulku", zakon: "v názvu zákona", role: "u role", dopad: "v dopadech", shrnuti: "ve shrnutí" };
const ORDER: SearchField[] = ["titulek", "zakon", "role", "dopad", "shrnuti"];

export const norm = (s: string) => s.toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "");

// kmen slova: prvních n znaků (aspoň 4), aby prošly české tvary
const stem = (w: string) => (w.length <= 4 ? w : w.slice(0, Math.max(4, w.length - 2)));

// občanský výraz (kmen) → co hledat v textech (kmeny)
const SYN: Record<string, string[]> = {
  televiz: ["televiz", "rozhlas", "ceska televiz"], rozhlas: ["rozhlas", "televiz"], poplat: ["poplat"],
  mater: ["materst", "materske", "penezit pomoc", "rodin"], materska: ["materst", "penezit pomoc"],
  psi: ["zvir", "psu", "psi", "kock", "chovat"], pes: ["zvir", "psu", "psi", "chovat"], kock: ["kock", "zvir"], zvir: ["zvir", "tyran", "chovat"],
  najem: ["najem", "bydle", "bytu", "byty", "bytov"], bydle: ["bydle", "najem", "bytu", "byty", "nemovit"], hypot: ["uver", "hypot", "bydle"],
  duchod: ["duchod", "senior", "starob"], senior: ["duchod", "senior"], penze: ["duchod", "penzij"], penzij: ["penzij", "sporen"],
  vypove: ["zakonik prace", "zamestnan", "vypove"], prace: ["zakonik prace", "zamestnan", "prac"], zamestn: ["zamestnan", "zakonik prace"],
  mzd: ["mzd", "odmen", "plat"], plat: ["odmen", "mzd", "platov"], svat: ["svat", "svatc", "prodejni dob"], obchod: ["prodejni dob", "obchod"],
  dan: ["dan", "dph", "poplatnik"], dane: ["dan", "dph"], dph: ["dph", "prida hodno", "dan"],
  pokut: ["pokut", "sankc", "prestup", "trest"], trest: ["trest", "pokut"],
  prispev: ["prispev"], auto: ["ridic", "silnic", "vozid", "pohonn"], ridic: ["ridic", "silnic", "vozid"], benzin: ["pohonn", "ropy", "palivo"],
  drog: ["navykov", "drog"], zbran: ["zbran", "zbroj", "strelivo"], exekuc: ["exeku", "dluzn", "vyzivn"], dluh: ["dluhy", "dluhu", "dluzn", "exeku", "nedoplat"],
  skol: ["skol", "zak", "stud", "vzdelav"], student: ["stud", "vysok", "skol"], deti: ["det", "rodin", "zak", "mladez"], rodin: ["rodin", "det", "materst"],
  nemocen: ["nemocensk"], lek: ["lek", "leciv", "zdravot", "pacient"], doktor: ["zdravot", "lekar", "pacient"], zdravi: ["zdravot", "pacient"],
  krypto: ["krypto", "bitcoin"], bitcoin: ["bitcoin", "krypto"], eshop: ["e-shop", "elektronick rozhrani", "spotrebitel"], oprav: ["oprav"],
  davk: ["davk", "socialni podpor", "socialni pomoc"], volb: ["volb", "volebn"], energi: ["energet", "elektrin"], elektri: ["elektrin", "energet"],
  policie: ["polici"], cizin: ["cizin", "azyl", "uprchl"], uprchl: ["uprchl", "azyl", "docasn ochran"],
};

function expansions(token: string): string[] {
  const t = norm(token);
  const st = stem(t);
  const out = new Set<string>([st]);
  for (const [k, v] of Object.entries(SYN)) if (t.startsWith(k) || k.startsWith(st)) v.forEach((x) => out.add(x));
  return [...out];
}

const STOP = new Set(["za", "na", "pro", "se", "do", "od", "po", "ve", "ze", "jak", "co", "kdy", "zakon", "zakony", "novela"]);

/** Výsledky seřazené podle shody; u víceslovného dotazu stačí, když sedí aspoň polovina slov. */
export function search(items: SearchItem[], q: string): SearchHit[] {
  const tokens = norm(q).split(/[^a-z0-9-]+/).filter((t) => t.length >= 2 && !STOP.has(t));
  if (tokens.length === 0) return [];
  const exp = tokens.map(expansions);
  // nejdřív musí sedět všechna slova; když to nic nenajde, stačí polovina
  const strict = rank(items, exp, tokens.length);
  return strict.length > 0 ? strict : rank(items, exp, Math.ceil(tokens.length / 2));
}

function rank(items: SearchItem[], exp: string[][], need: number): SearchHit[] {
  const scored: { it: SearchHit; s: number }[] = [];
  for (const it of items) {
    const hay = Object.fromEntries(ORDER.map((f) => [f, norm(it.fields[f] ?? "")])) as Record<SearchField, string>;
    let matched = 0;
    let s = 0;
    const found = new Set<SearchField>();
    for (const e of exp) {
      // každé slovo dotazu se počítá jen v nejsilnějším poli, kde se našlo
      const f = ORDER.find((k) => e.some((x) => hay[k].includes(x)));
      if (f) { matched++; s += WEIGHT[f]; found.add(f); }
    }
    if (matched >= need) {
      const best = ORDER.filter((f) => found.has(f)).slice(0, 2).map((f) => WHERE[f]).join(" a ");
      scored.push({ it: { ...it, where: `Nalezeno ${best}` }, s: s + matched * 10 + (it.boost ?? 0) });
    }
  }
  return scored.sort((a, b) => b.s - a.s).map((x) => x.it);
}
