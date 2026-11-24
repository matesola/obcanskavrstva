// Data z veřejného API Hlídače zákonů (cosepece.cz/api.php). Jen čtení, bez tokenu.
// Stránky se generují staticky a obnovují na pozadí (ISR), takže na cosepece jde pár požadavků za hodinu.

export const API = process.env.COSEPECE_API ?? "https://cosepece.cz/api.php";
export const REVALIDATE = 900; // 15 minut

export type Reason = { klic: string; body: number; popis: string };

export type Review = {
  titulek: string;
  perex: string;
  skupiny: string[];
  shrnuti?: string;
  zavaznost?: number;
};

export type ImpactItem = { citace: string; [k: string]: string };

export type Impact = {
  skupiny: { klic: string; nazev: string }[];
  ucinnost: { text: string; citace: string } | null;
  povinnosti: ImpactItem[];
  pravomoci: ImpactItem[];
  sankce: ImpactItem[];
  rizika: ImpactItem[];
  scenare?: ImpactItem[]; // v nejhorším případě: titulek, kdo, pribeh, pojistka, citace
  pomuze?: ImpactItem[]; // kdo, jak, citace
  pritizi?: ImpactItem[];
  zbystrit?: string;
};

export type Bill = {
  ct: number;
  nazev: string;
  uplny_nazev: string;
  typ_navrhu: string;
  navrhovatel: string;
  faze: string;
  predlozeno: string | null;
  posledni_zmena: string | null;
  skore: number;
  uroven: "major" | "watch" | "normal" | string;
  duvody: Reason[];
  priznaky: string[];
  url: string;
  psp_url: string;
  rozbor?: Review | null; // přibude s PR „dopad na občany“; do té doby chybí
};

export type Vote = {
  id: number;
  datum: string;
  vysledek: string;
  pro: number;
  proti: number;
  zdrzel: number;
  kvorum: number;
  psp_url: string;
};

export type PnResult = {
  cislo: number;
  stav: "prijat" | "castecne" | "zamitnut" | "nehlasovatelny" | "nezaznel" | "ceka" | "neurceno" | string;
};

export type BillDetail = Bill & {
  vysledky_pn?: PnResult[];
  hlasovani: Vote[];
  senat: { popis: string[] } | null;
  hlavni_nalez?: { titulek: string; perex: string; citace: string; zdroj: string; overeno: boolean } | null;
  dopad?: Impact | null;
};

export type Upcoming = {
  pid: string;
  nazev: string;
  resort: string;
  faze: string;
  zmeneno: string;
  pripominky: { od: string; do: string; dni: number; mist: number } | null;
  vlada_projednala: string | null;
  mimo_plan: boolean;
  popis: string;
};

// Výpadek zdroje: chyba se vyhodí, takže Next.js při obnově ponechá poslední úspěšně vygenerovanou stránku
// (ISR) a nezobrazí místo dat chybovou hlášku. Neexistující tisk (404) vrací null.
async function get<T>(query: string): Promise<T | null> {
  const res = await fetch(`${API}${query}`, { next: { revalidate: REVALIDATE } });
  if (res.status === 404) return null;
  if (!res.ok) throw new Error(`Hlídač zákonů neodpověděl (${res.status}) pro ${query}`);
  return (await res.json()) as T;
}

export async function bills(): Promise<{ tisky: Bill[]; aktualizovano: string } | null> {
  return get("?limit=500");
}

export async function bill(ct: number): Promise<BillDetail | null> {
  const data = await get<{ tisk: BillDetail }>(`?tisk=${ct}`);
  return data?.tisk ?? null;
}

export async function upcoming(): Promise<Upcoming[]> {
  // připravované zákony jsou doplněk: jejich výpadek nesmí shodit celou stránku
  try {
    const data = await get<{ pripravovane: Upcoming[] }>("?pripravovane=1");
    return data?.pripravovane ?? [];
  } catch {
    return [];
  }
}
