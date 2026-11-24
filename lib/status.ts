// Občanský stav návrhu: co je návrh, co je schválené, co vyhlášené. Platnost (vyhlášení ve Sbírce)
// není účinnost (od kdy se podle zákona postupuje). Odpověď „Musím už něco udělat?“ nikdy netvrdí víc,
// než víme: bez data účinnosti řekne, že ho nevíme.

export type CivicStatus = {
  kind: "navrh" | "senat" | "prezident" | "vyhlaseno" | "konec";
  label: string; // krátký štítek na kartu
  act: string; // odpověď na „Musím už něco udělat?“
};

const NAVRH = ["Podáno", "Organizační výbor", "U vlády", "Stanovisko vlády", "1. čtení", "2. čtení", "3. čtení"];

export function civicStatus(faze: string, ucinnost?: string): CivicStatus {
  if (NAVRH.includes(faze)) {
    const where = faze.includes("čtení") ? `${faze} ve Sněmovně` : "čeká na projednání ve Sněmovně";
    return { kind: "navrh", label: `Návrh · ${where}`, act: "Ne. Zatím jde jen o návrh, nic z toho neplatí." };
  }
  if (["Schváleno Sněmovnou", "V Senátu", "Sněmovna po Senátu"].includes(faze)) {
    return {
      kind: "senat",
      label: faze === "Sněmovna po Senátu" ? "Schváleno Sněmovnou · vráceno ze Senátu" : "Schváleno Sněmovnou · rozhoduje Senát",
      act: "Ne. Zákon ještě není přijatý, rozhoduje Senát.",
    };
  }
  if (["U prezidenta", "Sněmovna po vetu"].includes(faze)) {
    return { kind: "prezident", label: faze === "U prezidenta" ? "Schváleno · čeká na prezidenta" : "Vráceno prezidentem", act: "Zatím ne. Platit začne až po vyhlášení ve Sbírce zákonů." };
  }
  if (faze === "Vyhlášeno") {
    return {
      kind: "vyhlaseno",
      label: "Vyhlášeno ve Sbírce zákonů",
      act: ucinnost
        ? `Až od účinnosti: ${ucinnost}. Pokud se vás změna týká, od té doby podle ní postupujete.`
        : "Záleží na datu účinnosti, které tu zatím nemáme. Vyhlášení neznamená, že se podle zákona už postupuje; datum ověříte v e-Sbírce.",
    };
  }
  return { kind: "konec", label: `Návrh skončil · ${faze.toLowerCase()}`, act: "Ne. Návrh nebyl přijat." };
}
