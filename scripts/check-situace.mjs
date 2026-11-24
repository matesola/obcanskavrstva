// Hlídání synchronu s Hlídačem zákonů (cosepece.cz). Spouští se denně v GitHub Actions i ručně: npm run check
//  1. každá citace v data/situace.json je doslovně v detailu tisku na cosepece.cz,
//  2. fáze tisku se od sepsání podkladů nezměnila (jinak je potřeba podklady projít),
//  3. každý tisk se stupněm Závažné má podklady, nebo je má přímo Hlídač (dopad.scenare),
//  4. výsledky pozměňovacích návrhů, na kterých podklady stojí (jen informace, web je vyhodnocuje sám).
// Chyba v bodech 1 až 3 = exit 1 (GitHub pošle e-mail o neúspěšném běhu).
import fs from "node:fs";

const API = process.env.COSEPECE_API ?? "https://cosepece.cz/api.php";
const data = JSON.parse(fs.readFileSync(new URL("../data/situace.json", import.meta.url), "utf8"));
const norm = (s) => s.replace(/<[^>]+>/g, " ").replace(/&nbsp;/g, " ").replace(/&quot;/g, '"').replace(/&#0?39;/g, "'")
  .replace(/[„“”"]/g, "").replace(/\s+/g, " ").toLowerCase();
const json = async (q) => (await fetch(`${API}${q}`)).json();
const problems = [];
const info = [];

for (const [ct, t] of Object.entries(data.tisky)) {
  const html = norm(await (await fetch(`https://cosepece.cz/tisk.php?ct=${ct}`)).text());
  for (const n of t.nejhorsi) {
    for (const q of n.citace) {
      if (!html.includes(norm(q).replace(/[.…]$/, ""))) problems.push(`tisk ${ct}: citace už v textu není: „${q.slice(0, 80)}“`);
    }
  }
  const detail = (await json(`?tisk=${ct}`)).tisk;
  if (!detail) { problems.push(`tisk ${ct}: Hlídač ho nevrací`); continue; }
  if (t.faze && detail.faze !== t.faze) problems.push(`tisk ${ct}: fáze ${t.faze} → ${detail.faze}, projít podklady a upravit "faze"`);
  if ((detail.dopad?.scenare ?? []).length > 0) info.push(`tisk ${ct}: Hlídač už vydává vlastní scénáře, ruční podklady se nepoužijí`);
  const nums = [...JSON.stringify(t).matchAll(/PN č\. ([\d ,a]+)/g)].flatMap((m) => m[1].match(/\d{3,5}/g) ?? []).map(Number);
  for (const n of new Set(nums)) {
    const r = (detail.vysledky_pn ?? []).find((x) => x.cislo === n);
    if (r && r.stav !== "ceka") info.push(`tisk ${ct}: PN č. ${n} = ${r.stav}`);
  }
}

const list = (await json("?limit=500")).tisky ?? [];
for (const b of list.filter((x) => x.uroven === "major" && !data.tisky[String(x.ct)] && x.faze !== "Vyhlášeno")) {
  const detail = (await json(`?tisk=${b.ct}`)).tisk;
  if ((detail?.dopad?.scenare ?? []).length === 0 && detail?.rozbor_textu?.posouzeno > 0) {
    problems.push(`tisk ${b.ct} (${b.nazev}): nově Závažné, chybí podklady Koho a V nejhorším případě`);
  }
}

for (const l of info) console.log("INFO ", l);
for (const l of problems) console.log("CHYBA", l);
console.log(problems.length ? `\n${problems.length} problémů` : "\nVšechno v synchru s Hlídačem zákonů.");
process.exit(problems.length ? 1 : 0);
