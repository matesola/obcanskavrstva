import type { Metadata, Viewport } from "next";
import Link from "next/link";
import "@fontsource-variable/archivo/wdth.css";
import "./globals.css";

export const metadata: Metadata = {
  title: { default: "Občanská vrstva", template: "%s · Občanská vrstva" },
  description: "Co pro vás chystá Sněmovna, lidskou řečí. Každé tvrzení s citací ze zákona. Data: Hlídač zákonů (cosepece.cz).",
  openGraph: { locale: "cs_CZ", type: "website", siteName: "Občanská vrstva" },
};

export const viewport: Viewport = { themeColor: "#F4F1EA" };

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="cs">
      <body>
        <header className="top">
          <Link href="/" className="brand">
            Občanská <mark>vrstva</mark>
          </Link>
        </header>
        <main>{children}</main>
        <footer className="foot">
          <p>
            Data: <a href="https://cosepece.cz/">Hlídač zákonů</a> (cosepece.cz) z otevřených dat Poslanecké sněmovny a Úřadu vlády.
            Shrnutí připravuje umělá inteligence a může se mýlit; každé tvrzení má citaci, rozhoduje text zákona.
            Nejde o právní radu.
          </p>
          <p>
            <a href="https://github.com/matesola/obcanskavrstva">Zdrojový kód</a> · <a href="https://matesola.com/">Matesola</a>
          </p>
        </footer>
      </body>
    </html>
  );
}
