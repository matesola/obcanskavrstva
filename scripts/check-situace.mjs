// Ověří, že každá citace v data/situace.json je doslovně v detailu tisku na cosepece.cz.
// Spuštění: node scripts/check-situace.mjs   (při chybě skončí kódem 1)
import fs from "node:fs";

const data = JSON.parse(fs.readFileSync(new URL("../data/situace.json", import.meta.url), "utf8"));
const norm = (s) => s.replace(/<[^>]+>/g, " ").replace(/&nbsp;/g, " ").replace(/&quot;/g, '"').replace(/&#0?39;/g, "'")
  .replace(/[„“”"]/g, "").replace(/\s+/g, " ").toLowerCase();
let bad = 0;
for (const [ct, t] of Object.entries(data.tisky)) {
  const html = norm(await (await fetch(`https://cosepece.cz/tisk.php?ct=${ct}`)).text());
  for (const n of t.nejhorsi) {
    for (const q of n.citace) {
      const ok = html.includes(norm(q).replace(/[.…]$/, ""));
      if (!ok) bad++;
      console.log(`${ok ? "OK  " : "CHYBÍ"} ${ct}: ${q.slice(0, 70)}`);
    }
  }
}
process.exit(bad ? 1 : 0);
