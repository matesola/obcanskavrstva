# Občanská vrstva

*To se nasmějete, lidové vrstvy.*

Co pro vás chystá Sněmovna, lidskou řečí: koho se zákon týká, odkdy platí, nové povinnosti, pravomoci a sankce a kde je háček. Každé tvrzení s citací ze zákona.

Side projekt nad [Hlídačem zákonů](https://cosepece.cz/) ([FrantisekJanos/cosepece](https://github.com/FrantisekJanos/cosepece)). Data bere z jeho veřejného API (`api.php`), sám nic nepočítá ani neukládá.

## Jak to funguje

- Next.js (App Router), stránky se generují staticky a obnovují na pozadí každých 15 minut (ISR). Na cosepece.cz tak jde pár požadavků za hodinu bez ohledu na návštěvnost; když API vypadne, web jede dál z poslední verze.
- Filtr „Týká se mě“ si výběr pamatuje v prohlížeči (localStorage), bez účtu a e-mailu.
- Pole `rozbor`, `dopad` a `hlavni_nalez` přidává do API PR „dopad na občany“ (větev `dopad-na-obcany` v tomto repu). Dokud ho Hlídač nenasadí, web ukazuje lidsky přepsané názvy, větu z rozboru AI a cestu zákonem; zbytek se doplní sám.

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
