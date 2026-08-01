"use client";

type Ikon = "tel" | "web" | "ig" | "mail";

const KAYITLAR = [
  {
    ad: "Kafe Moda",
    yer: "Kafe · Kadıköy, İstanbul",
    puan: "4.6",
    yorum: 256,
    skor: 91,
    seviye: "Yüksek",
    renk: "#059669",
    img: "/showcase/kafe-moda.jpg",
    tel: "+902163301122",
    web: "https://www.google.com/maps/search/Kafe+Moda+Kadıköy",
    ig: "https://www.instagram.com/explore/tags/kafemoda/",
    mail: "info@kafemoda.example",
    ikonlar: ["tel", "web", "ig"] as const,
  },
  {
    ad: "Coffee Street",
    yer: "Kafe · Beşiktaş, İstanbul",
    puan: "4.2",
    yorum: 182,
    skor: 77,
    seviye: "Orta",
    renk: "#ea580c",
    img: "/showcase/coffee-street.jpg",
    tel: "+902122441100",
    web: "https://www.google.com/maps/search/Coffee+Street+Beşiktaş",
    ig: "https://www.instagram.com/explore/tags/coffeestreet/",
    mail: "hello@coffeestreet.example",
    ikonlar: ["tel", "web", "ig", "mail"] as const,
  },
  {
    ad: "Mavi Kafe",
    yer: "Kafe · Kadıköy, İstanbul",
    puan: "3.9",
    yorum: 97,
    skor: 63,
    seviye: "Orta",
    renk: "#d97706",
    img: "/showcase/mavi-kafe.jpg",
    tel: "+902163556677",
    web: "https://www.google.com/maps/search/Mavi+Kafe+Kadıköy",
    ig: "https://www.instagram.com/explore/tags/mavikafe/",
    mail: "merhaba@mavikafe.example",
    ikonlar: ["tel", "web", "ig"] as const,
  },
  {
    ad: "Ege Sofra",
    yer: "Restoran · Alsancak, İzmir",
    puan: "4.5",
    yorum: 318,
    skor: 88,
    seviye: "Yüksek",
    renk: "#059669",
    img: "https://images.unsplash.com/photo-1517248135467-4c7edcad34c4?auto=format&fit=crop&w=320&h=320&q=80",
    tel: "+902324455566",
    web: "https://www.google.com/maps/search/Ege+Sofra+Alsancak",
    ig: "https://www.instagram.com/explore/tags/egesofra/",
    mail: "rezervasyon@egesofra.example",
    ikonlar: ["tel", "web", "ig", "mail"] as const,
  },
  {
    ad: "Ankara Atölye",
    yer: "Eğitim · Çankaya, Ankara",
    puan: "4.1",
    yorum: 64,
    skor: 72,
    seviye: "Orta",
    renk: "#ea580c",
    img: "https://images.unsplash.com/photo-1524178232363-1fb2b075b655?auto=format&fit=crop&w=320&h=320&q=80",
    tel: "+903124445566",
    web: "https://www.google.com/maps/search/Ankara+Atölye+Çankaya",
    ig: "https://www.instagram.com/explore/tags/ankaraatolye/",
    mail: "info@ankaraatolye.example",
    ikonlar: ["tel", "web", "ig"] as const,
  },
];

function hrefFor(
  tip: Ikon,
  k: { tel: string; web: string; ig: string; mail: string }
) {
  if (tip === "tel") return `tel:${k.tel}`;
  if (tip === "web") return k.web;
  if (tip === "ig") return k.ig;
  return `mailto:${k.mail}`;
}

function ActionIcon({
  tip,
  href,
}: {
  tip: Ikon;
  href: string;
}) {
  const external = tip === "web" || tip === "ig";
  const label =
    tip === "tel" ? "Telefon" : tip === "web" ? "Web sitesi" : tip === "ig" ? "Instagram" : "E-posta";

  return (
    <a
      className={`he-act ${tip}`}
      href={href}
      title={label}
      aria-label={label}
      {...(external ? { target: "_blank", rel: "noopener noreferrer" } : {})}
    >
      {tip === "tel" && (
        <svg viewBox="0 0 24 24" width="11" height="11" fill="currentColor" aria-hidden>
          <path d="M6.6 10.8c1.4 2.8 3.8 5.1 6.6 6.6l2.2-2.2c.3-.3.7-.4 1.1-.2 1.2.4 2.5.6 3.8.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1C10.6 21 3 13.4 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.3.2 2.6.6 3.8.1.4 0 .8-.3 1.1L6.6 10.8z" />
        </svg>
      )}
      {tip === "web" && (
        <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" strokeWidth="2.2" aria-hidden>
          <circle cx="12" cy="12" r="9" />
          <path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18" />
        </svg>
      )}
      {tip === "ig" && (
        <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" strokeWidth="2.2" aria-hidden>
          <rect x="3" y="3" width="18" height="18" rx="5" />
          <circle cx="12" cy="12" r="4" />
        </svg>
      )}
      {tip === "mail" && (
        <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" strokeWidth="2.2" aria-hidden>
          <rect x="3" y="5" width="18" height="14" rx="2" />
          <path d="m4 7 8 6 8-6" />
        </svg>
      )}
    </a>
  );
}

function ScoreRing({ value, color }: { value: number; color: string }) {
  const r = 20;
  const c = 2 * Math.PI * r;
  const offset = c - (value / 100) * c;
  return (
    <svg className="hs-score-ring" viewBox="0 0 52 52" aria-hidden>
      <circle cx="26" cy="26" r={r} fill="none" stroke="#f1f5f9" strokeWidth="4" />
      <circle
        cx="26"
        cy="26"
        r={r}
        fill="none"
        stroke={color}
        strokeWidth="4"
        strokeLinecap="round"
        strokeDasharray={c}
        strokeDashoffset={offset}
        transform="rotate(-90 26 26)"
      />
      <text x="26" y="30" textAnchor="middle" fontSize="12" fontWeight="750" fill="#1e293b">
        {value}
      </text>
    </svg>
  );
}

function starsFor(puan: string) {
  const n = Math.round(Number(puan));
  return "★".repeat(Math.min(5, Math.max(0, n))) + "☆".repeat(Math.max(0, 5 - n));
}

export default function HeroShowcase() {
  return (
    <div className="hero-engine hero-engine-open he-soft he-cards-only">
      <div className="hero-engine-glow" aria-hidden />

      <div className="he-cards-only-stage">
        <div className="he-cards">
          {KAYITLAR.map((k) => (
            <article key={k.ad} className="he-card he-card-rich">
              <div className="he-thumb he-thumb-photo">
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img src={k.img} alt={k.ad} width={72} height={72} loading="lazy" />
              </div>
              <div className="he-meta">
                <strong>{k.ad}</strong>
                <span className="he-rating">
                  <b>{k.puan}</b> <i>{starsFor(k.puan)}</i> <small>({k.yorum})</small>
                </span>
                <em>{k.yer}</em>
                <div className="he-actions">
                  {k.ikonlar.map((tip) => (
                    <ActionIcon key={tip} tip={tip} href={hrefFor(tip, k)} />
                  ))}
                </div>
              </div>
              <div className="he-score">
                <small>AI SKOR</small>
                <ScoreRing value={k.skor} color={k.renk} />
                <b>
                  Potansiyel: <em style={{ color: k.renk }}>{k.seviye}</em>
                </b>
              </div>
            </article>
          ))}
        </div>
      </div>
    </div>
  );
}
