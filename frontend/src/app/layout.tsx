import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "Sahada — İşletme veri toplama ve analiz",
  description: "Google Maps bağlantısından gerçek işletme iletişim bilgisi: filtre, yakın firma analizi, harita ve Excel",
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="tr">
      <body>{children}</body>
    </html>
  );
}
