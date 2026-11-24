// Načtení všech tisků a jejich občanského pohledu (sdílí úvod a hledání).
import { bill, bills, type BillDetail } from "./api";
import { view, type BillView } from "./model";
import { covered } from "./situace";

export async function loadAll(): Promise<{ views: BillView[]; aktualizovano: string }> {
  const data = await bills();
  if (!data) throw new Error("Hlídač zákonů nevrátil seznam tisků");
  const all = data.tisky;
  // detail jen u tisků s podklady nebo se závažným postupem (výsledky PN, dopad z Hlídače); cache 15 min
  const want = new Set([...covered(), ...all.filter((b) => b.uroven === "major").map((b) => b.ct)]);
  const details = new Map<number, BillDetail | null>(
    await Promise.all(
      all.filter((b) => want.has(b.ct)).map(async (b) => [b.ct, await bill(b.ct).catch(() => null)] as const),
    ),
  );
  return { views: all.map((b) => view(b, details.get(b.ct))), aktualizovano: data.aktualizovano };
}

/** Pořadí v oddílu: nejdřív návrhy s občanským vysvětlením, pak ty, které se týkají víc rolí, pak nejnovější. */
export function byCivic(a: BillView, b: BillView): number {
  const cov = { plne: 0, castecne: 1, zadne: 2 };
  return cov[a.coverage] - cov[b.coverage] || b.roles.length - a.roles.length || String(b.posledni).localeCompare(String(a.posledni));
}
