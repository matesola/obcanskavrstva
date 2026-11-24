import type { Metadata } from "next";
import { loadAll } from "@/lib/load";
import { ROLE_LABEL } from "@/lib/roles";
import type { SearchItem } from "@/lib/search";
import Search from "@/components/Search";

export const revalidate = 900;
export const metadata: Metadata = { title: "Hledat téma" };

export default async function Hledat() {
  const { views } = await loadAll();
  const items: SearchItem[] = views
    .sort((a, b) => a.step - b.step)
    .map((v) => ({
      ct: v.ct,
      title: v.title,
      meta: `${v.status.label}${v.roles.length ? ` · týká se: ${v.roles.map((r) => ROLE_LABEL[r.key].toLowerCase()).join(", ")}` : ""}`,
      boost: v.coverage === "plne" ? 6 : 0,
      text: [v.law, v.summary ?? "", v.extra, ...v.pomuze.map((w) => `${w.kdo} ${w.jak}`), ...v.pritizi.map((w) => `${w.kdo} ${w.jak}`), ...v.roles.map((r) => ROLE_LABEL[r.key])].join(" "),
    }));
  return (
    <section className="hero hero-small">
      <h1>Hledat <mark>téma</mark></h1>
      <p className="dry">Pište normálně, jak byste se zeptali souseda. Hledáme ve všech {items.length} návrzích tohoto volebního období.</p>
      <Search items={items} />
    </section>
  );
}
