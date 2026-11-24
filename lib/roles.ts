// Životní role pro „Co se vás týká“. Automatická, průhledná klasifikace: pravidla nad názvem návrhu,
// občanským vysvětlením a větou z rozboru Hlídače. Žádné ruční štítkování, takže pokrývá všechny tisky
// a s novými daty se aktualizuje sama. U každé role se ukládá důvod (které slovo a kde), aby bylo vidět,
// proč se návrh zobrazuje. Jde o hrubé třídění, ne o právní posouzení.

export type RoleKey =
  | "zamestnanec" | "firma" | "osvc" | "rodic" | "student" | "duchodce" | "bydleni"
  | "ridic" | "zvirata" | "spotrebitel" | "sporeni" | "zdravi" | "dluhy" | "dane" | "davky" | "volic" | "vsichni";

/**
 * rules: platí pro všechny zdroje (vysvětlení, název, rozbor); explainOnly: jen pro občanské vysvětlení,
 * protože v úředním názvu jde často jen o zmínku (např. „zaměstnavatel“ v názvu zákona o veřejném zdraví);
 * not: výluky proti falešným shodám (audit 2.1).
 */
type Role = { key: RoleKey; label: string; rules: RegExp[]; explainOnly?: RegExp[]; not?: RegExp[]; hidden?: boolean };

export const ROLES: Role[] = [
  { key: "zamestnanec", label: "Zaměstnanec", rules: [/zákoník(?:u)? práce/, /\bmzd/, /odměňován/, /nemocensk/, /pracovněprávn/, /platformov/, /agentur(?:a|y|ám) práce/, /\bvýpověď/, /zákon(?:a|u)? o zaměstnanosti/, /pojistném na sociální zabezpečení/, /prodejní dob/],
    explainOnly: [/zaměstnan(?:ec|ci|ce|cům|ců)\b/], not: [/státních zaměstnanc/] },
  { key: "firma", label: "Zaměstnavatel a podnikatel", rules: [/obchodní(?:ch)? korporac/, /veřejných zakáz|veřejné zakáz/, /evidenci tržeb/, /obchodní(?:ho)? tajemství/],
    explainOnly: [/zaměstnavatel/, /\bfirm/, /prodejc/, /podnikatel/, /\búčetnictví/] },
  { key: "osvc", label: "OSVČ a živnostník", rules: [/(?:^|[^a-zá-ž])osvč/, /živnost/, /samostatně výdělečn/, /paušáln/, /pojistném na sociální zabezpečení/, /evidenci tržeb/] },
  { key: "rodic", label: "Rodič", rules: [/\bdítě/, /rodičovsk/, /výživn/, /sociálně-právní ochran/, /školsk/, /\bžák/, /mládež/],
    explainOnly: [/\bdět(?:i|í|em|mi)\b/, /rodin(?:a|y|ám|né)\b/, /mateřsk/] },
  { key: "student", label: "Student", rules: [/student/, /vysok(?:é|ých|ými|ou) škol/, /vzdělávání/, /\bžák/] },
  { key: "duchodce", label: "Důchodce", rules: [/důchod/, /senior/, /starobní/], explainOnly: [] },
  { key: "bydleni", label: "Nájemník a majitel bytu", rules: [/nájem/, /bydlení/, /\bbyt(?:u|y|ů)?\b/, /nemovit/, /stavební zákon/] },
  { key: "ridic", label: "Řidič", rules: [/silničním provozu/, /řidičsk/, /provozu vozidel/, /pozemních komunikac/, /pohonn/, /dálnič/] },
  { key: "zvirata", label: "Chovatel zvířat", rules: [/zvíř/, /\bps(?:i|ů|a|em)\b/, /kočk/, /myslivo/, /chovatel/] },
  { key: "spotrebitel", label: "Spotřebitel", rules: [/spotřebitel/, /opravou výrobku|oprav(?:ovat|a) výrobk/, /e-shop/, /finančních službách uzavíranými na dálku/, /prodejní dob/],
    explainOnly: [/reklam/] },
  { key: "sporeni", label: "Střadatel a investor", rules: [/penzijn/, /\bspoření/, /dluhopis/, /krypto/, /investiční(?:ch)? (?:společnost|fond)/] },
  { key: "zdravi", label: "Pacient a pečující", rules: [/pacient/, /léči|\bléky|léčiv/, /pečující|péče o/, /sociálních služb/, /elektronizaci zdravotnictví/, /veřejné zdravotní pojištění/],
    explainOnly: [/zdravot/, /nemocnic/], not: [/výkonu zdravotnického povolání/] },
  { key: "dluhy", label: "Dlužník", rules: [/exeku/, /\bdluh(?!opis)|dlužník|dluží/, /nedoplat/, /insolven/] },
  { key: "dane", label: "Daňový poplatník", rules: [/\bdaň\b|\bdaně|\bdaní(?:ch)?\b|\bdani\b/, /\bdph\b|přidané hodnoty/, /poplatník/, /spotřebních daních/],
    explainOnly: [/daňov/], not: [/rozpočtovém určení/, /daňové mlčenlivost/] },
  { key: "davky", label: "Příjemce dávek", rules: [/státní sociální podpo/, /\bdávk/, /existenčním minimu/, /sociální pomoc/, /podpor(?:a|y|u) v nezaměstnanosti/] },
  { key: "volic", label: "Volič", rules: [/\bvoleb\b|volbách|volební/] },
  // změny, které dopadnou prakticky na každého: při filtrování se ukážou u každé role
  { key: "vsichni", label: "Všichni", hidden: true, rules: [/cestovních doklad/, /státních svátc/, /rozhlasových a televizních poplatc/, /poplatk(?:y|ů) za televizi/] },
];

// \b v JS nezná českou diakritiku („dětí “ by neprošlo), proto ho nahradíme hranicí slova s písmeny á až ž
const L = "a-zá-ž0-9";
const fix = (r: RegExp) => new RegExp(r.source.replace(/(^|\(\?:|\(|\|)\\b/g, `$1(?<![${L}])`).replace(/\\b/g, `(?![${L}])`), "i");
for (const role of ROLES) {
  role.rules = role.rules.map(fix);
  role.explainOnly = (role.explainOnly ?? []).map(fix);
  role.not = (role.not ?? []).map(fix);
}

export const ROLE_LABEL: Record<RoleKey, string> = Object.fromEntries(ROLES.map((r) => [r.key, r.label])) as Record<RoleKey, string>;
export const VISIBLE_ROLES = ROLES.filter((r) => !r.hidden);

export type RoleHit = { key: RoleKey; why: string };

/** Zdroje textu v pořadí důvěry: občanské vysvětlení, název návrhu, věta z rozboru Hlídače. */
export function classify(sources: { label: string; text: string }[]): RoleHit[] {
  const hits: RoleHit[] = [];
  for (const role of ROLES) {
    for (const src of sources) {
      const t = src.text.toLowerCase();
      if (role.not?.some((r) => r.test(t))) continue;
      const rules = src.label === "vysvětlení" ? [...role.rules, ...(role.explainOnly ?? [])] : role.rules;
      const rule = rules.find((r) => r.test(t));
      if (rule) {
        hits.push({ key: role.key, why: `${src.label}: „${wordAround(src.text, t.search(rule), (t.match(rule)?.[0] ?? "").length)}“` });
        break;
      }
    }
  }
  return hits;
}

/** Celé slovo (nebo slova) kolem nalezeného kmene, ať důvod nezní jako „výrobc“. */
function wordAround(text: string, start: number, len: number): string {
  const isWord = (c: string) => /[\p{L}\p{N}-]/u.test(c);
  let a = start;
  let b = start + len;
  while (a > 0 && isWord(text[a - 1])) a--;
  while (b < text.length && isWord(text[b])) b++;
  return text.slice(a, b).trim();
}
