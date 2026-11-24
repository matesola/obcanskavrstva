"use client";

/** Výpadek zdroje u stránky, kterou ještě nemáme uloženou: srozumitelná zpráva místo technické chyby. */
export default function Error({ reset }: { error: Error; reset: () => void }) {
  return (
    <section className="hero">
      <h1>Hlídač zákonů teď <mark>neodpovídá</mark></h1>
      <p className="dry">
        Data bereme z <a href="https://cosepece.cz/">Hlídače zákonů</a> a ten je teď nedostupný. Stránky, které už máme uložené,
        ukazujeme dál v posledním ověřeném stavu; tahle mezi ně zatím nepatří. Zkuste to za chvíli.
      </p>
      <p><button type="button" className="linkish" onClick={() => reset()}>Zkusit znovu</button> · <a href="/">Na přehled</a></p>
    </section>
  );
}
