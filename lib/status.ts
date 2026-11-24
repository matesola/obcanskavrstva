// Občanský stav návrhu: co je návrh, co je schválené, co vyhlášené. Platnost (vyhlášení ve Sbírce)
// není účinnost (od kdy se podle zákona postupuje) a účinnost neznamená, že každý musí něco udělat.
// Odpověď „Musím už něco udělat?“ nikdy netvrdí víc, než víme.

export type CivicStatus = {
  kind: "navrh" | "senat" | "prezident" | "vyhlaseno" | "konec";
  label: string; // štítek do detailu
  short: string; // jeden řádek na kartu: stav + co z toho plyne
  act: string; // odpověď na „Musím už něco udělat?“
};

const NAVRH = ["Podáno", "Organizační výbor", "U vlády", "Stanovisko vlády", "1. čtení", "2. čtení", "3. čtení"];

export function civicStatus(faze: string): CivicStatus {
  if (NAVRH.includes(faze)) {
    const where = faze.includes("čtení") ? `${faze} ve Sněmovně` : "čeká na projednání ve Sněmovně";
    return { kind: "navrh", label: `Návrh · ${where}`, short: `Návrh · ${faze.includes("čtení") ? faze : "čeká na projednání"} · zatím nic neplatí`, act: "Ne. Zatím jde jen o návrh, nic z toho neplatí." };
  }
  if (["Schváleno Sněmovnou", "V Senátu", "Sněmovna po Senátu"].includes(faze)) {
    const vraceno = faze === "Sněmovna po Senátu";
    return {
      kind: "senat",
      label: vraceno ? "Schváleno Sněmovnou · vráceno ze Senátu" : "Schváleno Sněmovnou · rozhoduje Senát",
      short: vraceno ? "Senát vrátil Sněmovně · zatím neplatí" : "Schválila Sněmovna · rozhoduje Senát · zatím neplatí",
      act: "Ne. Zákon ještě není přijatý.",
    };
  }
  if (["U prezidenta", "Sněmovna po vetu"].includes(faze)) {
    return { kind: "prezident", label: faze === "U prezidenta" ? "Schváleno · čeká na prezidenta" : "Vráceno prezidentem", short: "Čeká na prezidenta · zatím neplatí", act: "Zatím ne. Platit začne až po vyhlášení ve Sbírce zákonů a od data účinnosti." };
  }
  if (faze === "Vyhlášeno") {
    return {
      kind: "vyhlaseno",
      label: "Vyhlášeno ve Sbírce zákonů",
      short: "Vyhlášeno · datum účinnosti ověřte v e-Sbírce",
      act: "Záleží na dvou věcech: od kdy zákon platí (účinnost) a zda se vás změna týká. Datum účinnosti tu zatím nemáme; najdete ho v e-Sbírce. Ani účinný zákon neznamená, že každý musí něco udělat.",
    };
  }
  return { kind: "konec", label: `Návrh skončil · ${faze.toLowerCase()}`, short: `Skončilo · ${faze.toLowerCase()}`, act: "Ne. Návrh nebyl přijat." };
}
