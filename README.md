# Občanská vrstva

*„To se nasmějete, lidové vrstvy.“*

Co pro vás chystá Sněmovna, lidskou řečí: koho se zákon týká, odkdy platí, nové povinnosti, pravomoci a sankce a kde je háček. Každé tvrzení s citací ze zákona.

Side projekt nad [Hlídačem zákonů](https://cosepece.cz/) ([FrantisekJanos/cosepece](https://github.com/FrantisekJanos/cosepece)). Data bere z jeho veřejného API (`api.php`), sám nic nepočítá ani neukládá.

## Princip

Hlídač zákonů vysvětluje, co se děje v legislativě. Občanská vrstva vysvětluje, proč by nás to mělo zajímat.
Detaily návrhů patří Hlídači, důvod si je rozkliknout patří sem.

- Úroveň 1 (karta): občanský titulek (podmíněně, je to návrh), komu pomůže / přitíží, koho se týká a proč, stav, „Musím něco udělat?“.
- Úroveň 2 (detail `/tisk/[ct]`): co se mění, dnes vs. podle návrhu, dotčení, v nejhorším případě (teoretický scénář s citací), kde to je, kde se ozvat, proč Hlídač upozorňuje, odkazy k ověření.
- Úroveň 3: Hlídač zákonů a psp.cz.

Tři dimenze zvlášť, žádné souhrnné skóre: občanská relevance (role), časová naléhavost (stav, účinnost), legislativní rizikovost (původní hodnocení Hlídače).

Role (`lib/roles.ts`) se určují automaticky pravidly nad názvem, vysvětlením a větou z rozboru Hlídače; u každé je vidět důvod. Hledání (`/hledat`, `lib/search.ts`) bez diakritiky, podle kmene a s občanskými synonymy. „Od poslední návštěvy“ a výběr rolí se ukládají jen v prohlížeči.

## Jak to funguje

- Next.js (App Router), stránky se generují staticky a obnovují na pozadí každých 15 minut (ISR). Na cosepece.cz tak jde pár požadavků za hodinu bez ohledu na návštěvnost; když API vypadne, web jede dál z poslední verze.
- Filtr „Týká se mě“ si výběr pamatuje v prohlížeči (localStorage), bez účtu a e-mailu.
- Pole `rozbor`, `dopad` a `hlavni_nalez` přidává do API PR „dopad na občany“ (větev `dopad-na-obcany` v tomto repu). Dokud ho Hlídač nenasadí, web ukazuje lidsky přepsané názvy, větu z rozboru AI a cestu zákonem; zbytek se doplní sám.

## Koho se týká a V nejhorším případě

U tisků se stupněm Závažné web ukazuje, koho se zákon týká (konkrétní lidé a firmy) a „V nejhorším případě“: co text při doslovném a nejpřísnějším výkladu umožní, s podmínkou (přímo v textu, nebo jen pokud projde pozměňovací návrh), pojistkou a citací.

- Teď z ručně sestavených podkladů v `data/situace.json` (z rozborů na cosepece.cz). `npm run check` ověří, že každá citace je doslovně v detailu tisku na cosepece.cz.
- Až Hlídač nasadí PR `dopad-na-obcany`, přijde „V nejhorším případě“ automaticky v `dopad.scenare` pro každý tisk; ruční podklady mají dál přednost.
- „V nejhorším případě“ popisuje, co text umožňuje, ne co kdo zamýšlí. Žádná čísla ani lhůty, které v textu nejsou.

## Výpadek Hlídače

Když API neodpovídá, Next.js nechá poslední úspěšně vygenerovanou stránku (ISR). Detail, který ještě nebyl vygenerovaný, ukáže srozumitelnou zprávu (`app/error.tsx`). Připravované zákony jsou doplněk a jejich výpadek stránku neshodí.

## Vývoj

```bash
npm install
npm run dev        # http://localhost:3000
npm run build
```

`COSEPECE_API` přepíše adresu API (výchozí `https://cosepece.cz/api.php`).

## Vercel

Import repa, framework Next.js, bez dalšího nastavení.

## Licence

AGPL-3.0, stejně jako Hlídač zákonů.
