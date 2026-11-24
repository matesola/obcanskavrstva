// Typ občanského dopadu (audit 2.1). Absence role neznamená absenci dopadu, proto každý tisk spadá
// do jednoho ze čtyř stavů. Pravidla jsou průhledná a platí i pro nové tisky; nic se nevymýšlí.
//  - primy: přímý dopad na lidi v některé roli, nebo na konkrétní užší skupinu (uvedenou jménem)
//  - neprimy: dopad přes ceny, služby, životní prostředí nebo veřejné finance, konkrétně ho neumíme popsat
//  - institucionalni: chod státu, úřadů, soudů, Sněmovny; běžný člověk nic nemusí
//  - nejasne: z dostupných dat to nejde spolehlivě určit

export type Agenda = "primy" | "neprimy" | "institucionalni" | "nejasne";

export const AGENDA_LABEL: Record<Agenda, string> = {
  primy: "Přímý dopad",
  neprimy: "Nepřímý dopad",
  institucionalni: "Chod státu",
  nejasne: "Dopad zatím neumíme určit",
};

// užší skupiny s přímým dopadem, které nejsou mezi rolemi
const NARROW: [RegExp, string][] = [
  [/cizinc|azyl|dočasné ochran|uprchl/i, "cizinci"],
  [/autorizovaných architektů|autorizační/i, "architekti a inženýři"],
  [/mistrovsk/i, "řemeslníci (mistrovská zkouška)"],
  [/cestovního ruchu/i, "cestovní kanceláře"],
  [/plemenář/i, "chovatelé hospodářských zvířat"],
  [/výkonu zdravotnického povolání/i, "lékaři a farmaceuti"],
  [/majetku nevysvětleného původu/i, "lidé s majetkem nevysvětleného původu"],
];

const INSTITUTIONAL = /jednacím řádu|ústav(?:a|y|ní zákon)|sbírce zákonů|kompetenční|zřízení ministerstev|o obcích|obecní zřízení|rozpočtovém určení|rozpočtových pravidl|rozpočtové odpovědnosti|zastupování státu|nejvyšším kontrolním|zeměměřick|státních zaměstnanc|předpoklady pro výkon|výběru osob do řídících|nominační|elektronických úkonech|vytvářejících důvěru|registru smluv|zemských znacích|prevenci korupce|střetu zájmů|justiční spolupráci|soudní řád správní|policii české republiky|trestní řád|vnitřní správy|veřejných rozpočtů|správě dat/i;
const INDIRECT = /energetick|odpad|obalech|vodní zákon|o vodách|ochraně přírody|životní prostředí|ekologick|letectví|technických požadavcích|chemick|standardizac|hospodářské soutěže|kritické infrastruktur|liniový|urychlení výstavby|mediálních služeb|elektronických komunikací|ekodesign|podpoře sportu|výzkumu|státním rozpočtu|zdravotnických prostředcích/i;

export function agendaOf(text: string, hasRoles: boolean, explained: boolean): { kind: Agenda; note: string | null } {
  if (hasRoles) return { kind: "primy", note: null };
  const narrow = NARROW.find(([r]) => r.test(text));
  if (narrow) return { kind: "primy", note: narrow[1] };
  if (INSTITUTIONAL.test(text)) return { kind: "institucionalni", note: null };
  if (INDIRECT.test(text)) return { kind: "neprimy", note: null };
  if (explained) return { kind: "primy", note: "užší skupina, viz vysvětlení" };
  return { kind: "nejasne", note: null };
}
