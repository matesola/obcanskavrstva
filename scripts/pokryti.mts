// Audit občanského pokrytí (zadání 2.1): npx tsx scripts/pokryti.mts > docs/pokryti.md
// Bere živý seznam tisků z API Hlídače a stejný model jako web (lib/model.ts).
import { view } from "../lib/model.ts";
import { AGENDA_LABEL } from "../lib/agenda.ts";
import { ROLE_LABEL } from "../lib/roles.ts";

const API = process.env.COSEPECE_API ?? "https://cosepece.cz/api.php";
const data = await (await fetch(`${API}?limit=500`)).json();
const views = data.tisky.map((b: any) => view(b, null));
const live = views.filter((v: any) => v.step >= 0 && v.step <= 5);
const count = (a: any[], f: (x: any) => boolean) => a.filter(f).length;
const pct = (n: number, d: number) => (d ? `${Math.round((n / d) * 100)} %` : "0 %");

const out: string[] = [];
out.push(`# Občanské pokrytí`, ``, `Stav dat Hlídače: ${data.aktualizovano}. Generováno skriptem scripts/pokryti.mts.`, ``);
out.push(`## Metodika`, ``,
  `Každý tisk má jeden typ dopadu: **přímý** (některá role nebo jmenovaná užší skupina), **nepřímý** (ceny, služby, prostředí, veřejné finance; konkrétně neumíme popsat), **chod státu** (úřady, soudy, Sněmovna) a **nejasný** (z dostupných dat nejde určit). Pravidla jsou v lib/agenda.ts a lib/roles.ts. U 41 tisků s ručním vysvětlením platí ručně ověřené role a typ dopadu (data/situace.json). Role z úředního názvu se berou jen z jednoznačných výrazů (např. „zákoník práce“), slova jako „zaměstnavatel“ nebo „zdravotní“ jen z občanského vysvětlení.`, ``);
for (const [name, set] of [["Všechny tisky", views], ["Projednávané (od podání po prezidenta)", live]] as const) {
  out.push(`## ${name}: ${set.length}`, ``, `| Typ dopadu | Počet | Podíl | S občanským vysvětlením |`, `|---|---:|---:|---:|`);
  for (const k of Object.keys(AGENDA_LABEL)) {
    const n = count(set, (v) => v.agenda === k);
    out.push(`| ${AGENDA_LABEL[k as keyof typeof AGENDA_LABEL]} | ${n} | ${pct(n, set.length)} | ${count(set, (v) => v.agenda === k && v.coverage === "plne")} |`);
  }
  const direct = set.filter((v: any) => v.agenda === "primy");
  out.push(``, `- Přímý dopad s občanským vysvětlením: **${count(direct, (v) => v.coverage === "plne")} z ${direct.length}** (${pct(count(direct, (v) => v.coverage === "plne"), direct.length)}).`);
  const assigns = set.flatMap((v: any) => v.roles.map((r: any) => r.why.split(":")[0]));
  out.push(`- Přiřazení rolí: ${assigns.length}; z vysvětlení nebo ručně ${count(assigns, (w) => w === "vysvětlení" || w.startsWith("ručně"))}, z názvu ${count(assigns, (w) => w === "název")}, z věty rozboru Hlídače ${count(assigns, (w) => w === "rozbor Hlídače")}.`);
  out.push(`- Nevyřešená klasifikace (nejasný dopad): **${count(set, (v) => v.agenda === "nejasne")}**.`, ``);
}
out.push(`## Role (projednávané tisky)`, ``, `| Role | Návrhů |`, `|---|---:|`);
for (const k of Object.keys(ROLE_LABEL)) out.push(`| ${ROLE_LABEL[k as keyof typeof ROLE_LABEL]} | ${count(live, (v) => v.roles.some((r: any) => r.key === k))} |`);
out.push(``, `## Tisky s nejasným dopadem`, ``);
for (const v of views.filter((x: any) => x.agenda === "nejasne")) out.push(`- ${v.ct}: ${v.law} (${v.faze})`);
out.push(``, `## Všechny tisky`, ``, `| Tisk | Fáze | Název | Typ dopadu | Role (důvod) |`, `|---|---|---|---|---|`);
for (const v of views) out.push(`| ${v.ct} | ${v.faze} | ${v.title.replace(/\|/g, "/")} | ${AGENDA_LABEL[v.agenda as keyof typeof AGENDA_LABEL]}${v.agendaNote ? `: ${v.agendaNote}` : ""} | ${v.roles.map((r: any) => `${ROLE_LABEL[r.key as keyof typeof ROLE_LABEL]} (${r.why})`).join("; ").replace(/\|/g, "/") || "-"} |`);
console.log(out.join("\n"));
