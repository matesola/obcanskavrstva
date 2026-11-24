import Link from "next/link";

export default function NotFound() {
  return (
    <section className="hero">
      <h1>Tohle tu <mark>není</mark></h1>
      <p className="dry">Tisk s tímhle číslem neznáme. <Link href="/">Zpět na přehled</Link></p>
    </section>
  );
}
