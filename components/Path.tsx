import { STEPS, nextStep, stepIndex } from "@/lib/text";

/** Cesta zákonem: Návrh, 1. až 3. čtení, Senát, Prezident, Platí. */
export default function Path({ faze, labels = false }: { faze: string; labels?: boolean }) {
  const idx = stepIndex(faze);
  const stopped = idx === -1;
  return (
    <div aria-label={`Fáze: ${faze}`}>
      <div className={`path${stopped ? " stopped" : ""}`} aria-hidden="true">
        {STEPS.map((s, i) => (
          <span key={s} className={i < idx ? "done" : i === idx ? "now" : ""} />
        ))}
      </div>
      {labels && (
        <div className="path-labels" aria-hidden="true">
          {STEPS.map((s, i) => (i === idx ? <b key={s}>{s}</b> : <span key={s}>{s}</span>))}
        </div>
      )}
      <p className="next">{nextStep(faze)}</p>
    </div>
  );
}
