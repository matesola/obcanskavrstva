// Životní role pro „Co se vás týká“. Automatická, průhledná klasifikace: pravidla nad názvem návrhu,
// občanským vysvětlením a větou z rozboru Hlídače. Žádné ruční štítkování, takže pokrývá všechny tisky
// a s novými daty se aktualizuje sama. U každé role se ukládá důvod (které slovo a kde), aby bylo vidět,
// proč se návrh zobrazuje. Jde o hrubé třídění, ne o právní posouzení.

export type RoleKey =
  | "zamestnanec" | "firma" | "osvc" | "rodic" | "student" | "duchodce" | "bydleni"
  | "ridic" | "zvirata" | "spotrebitel" | "sporeni" | "zdravi" | "dluhy" | "dane" | "davky" | "volic";

export const ROLES: { key: RoleKey; label: string; rules: RegExp[] }[] = [
  { key: "zamestnanec", label: "Zaměstnanec", rules: [/zákoník(?:u)? práce/, /zaměstnan(?:ec|ci|ce|cům|ců)\b/, /\bmzd/, /odměňován/, /nemocensk/, /pracovněprávn/, /platformov/, /agentur(?:a|y|ám) práce/, /\bvýpověď/, /zaměstnanost/, /pojistném na sociální zabezpečení/, /prodejní dob/, /svátc/] },
  { key: "firma", label: "Zaměstnavatel a firma", rules: [/zaměstnavatel/, /\bfirm/, /obchodní(?:ch)? korporac/, /účetnictví/, /veřejných zakáz|veřejné zakáz/, /kotovan/, /výrobc/, /prodejc/, /dovozc/, /obchodní(?:ho)? tajemství/, /akcionář/, /evidenci tržeb/, /sociálním podnik/] },
  { key: "osvc", label: "OSVČ a živnostník", rules: [/(?:^|[^a-zá-ž])osvč/, /živnost/, /samostatně výdělečn/, /paušáln/, /pojistném na sociální zabezpečení/, /evidenci tržeb/] },
  { key: "rodic", label: "Rodič", rules: [/\bdět(?:i|í|em|mi)\b/, /\bdítě/, /rodin(?:a|y|ám|né)\b/, /mateřsk/, /rodičovsk/, /výživn/, /sociálně-právní ochran/, /školsk/, /\bžák/, /mládež/] },
  { key: "student", label: "Student", rules: [/student/, /vysok(?:é|ých|ými|ou) škol/, /\bžák/, /vzdělávání/, /studi(?:um|a)\b/] },
  { key: "duchodce", label: "Důchodce", rules: [/důchod/, /senior/, /starobní/] },
  { key: "bydleni", label: "Nájemník a majitel bytu", rules: [/nájem/, /bydlení/, /\bbyt(?:u|y|ů)?\b/, /nemovit/, /stavební zákon/] },
  { key: "ridic", label: "Řidič", rules: [/silničním provozu/, /řidičsk/, /vozid/, /pozemních komunikac/, /pohonn/, /dálnič/] },
  { key: "zvirata", label: "Chovatel zvířat", rules: [/zvíř/, /\bps(?:i|ů|a|em)\b/, /kočk/, /myslivo/, /chovatel/, /týrání/] },
  { key: "spotrebitel", label: "Spotřebitel", rules: [/spotřebitel/, /reklam/, /opravou výrobku|oprav(?:ovat|a) výrobk/, /e-shop/, /spotřebitelsk/, /televizních poplatc|rozhlasových a televizních/, /poplatk(?:y|ů) za televizi/, /energetick/, /prodejní dob/, /požadavcích na výrobky/, /spotřebitelském úvěru/] },
  { key: "sporeni", label: "Střadatel a investor", rules: [/penzijn/, /\bspoření/, /dluhopis/, /kapitálovém trhu/, /krypto/, /investi/] },
  { key: "zdravi", label: "Pacient a pečující", rules: [/zdravot/, /pacient/, /léči|\bléky|léčiv/, /nemocnic/, /pečující|péče o/, /sociálních služb/, /návykov/] },
  { key: "dluhy", label: "Dlužník", rules: [/exeku/, /\bdluh|dlužník|dluží/, /nedoplat/, /insolven/] },
  { key: "dane", label: "Daňový poplatník", rules: [/\bdaň|\bdaně|daní\b|daňov/, /\bdph\b|přidané hodnoty/, /poplatník/, /\bdani\b/] },
  { key: "davky", label: "Příjemce dávek", rules: [/státní sociální podpo/, /dávk/, /existenčním minimu/, /sociální pomoc/, /podpor(?:a|y|u) v nezaměstnanosti/] },
  { key: "volic", label: "Volič", rules: [/\bvoleb\b|volbách|volební/] },
];

// \b v JS nezná českou diakritiku („dětí “ by neprošlo), proto ho nahradíme hranicí slova s písmeny á až ž
const L = "a-zá-ž0-9";
for (const role of ROLES) {
  role.rules = role.rules.map((r) => new RegExp(
    r.source
      .replace(/(^|\(\?:|\(|\|)\\b/g, `$1(?<![${L}])`)
      .replace(/\\b/g, `(?![${L}])`),
    "i",
  ));
}

export const ROLE_LABEL: Record<RoleKey, string> = Object.fromEntries(ROLES.map((r) => [r.key, r.label])) as Record<RoleKey, string>;

export type RoleHit = { key: RoleKey; why: string };

/** Zdroje textu v pořadí důvěry: občanské vysvětlení, název návrhu, věta z rozboru Hlídače. */
export function classify(sources: { label: string; text: string }[]): RoleHit[] {
  const hits: RoleHit[] = [];
  for (const role of ROLES) {
    for (const src of sources) {
      const t = src.text.toLowerCase();
      const rule = role.rules.find((r) => r.test(t));
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
