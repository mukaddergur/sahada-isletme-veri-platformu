"use client";

import { useState } from "react";

const KATEGORILER = [
  { t: "Kafe ve pastane", d: "Kahve dükkânları ve pastaneler" },
  { t: "Restoran", d: "Yemek ve hızlı servis işletmeleri" },
  { t: "Eğlence", d: "Düğün, davet ve organizasyon mekanları" },
  { t: "Eğitim", d: "Dershane, kurs ve kolej aramaları" },
  { t: "Güzellik", d: "Kuaför, spa ve berber" },
  { t: "Otel ve market", d: "Konaklama ve perakende" },
];

const REHBER = [
  { n: "01", t: "Google Maps’te arayın", d: "Hedef şehir ve sektörü yazın. Örnek: Gaziantep eğlence, İzmir eğitim." },
  { n: "02", t: "Sonuç bağlantısını kopyalayın", d: "Adres çubuğundaki arama sonuçları bağlantısını alın." },
  { n: "03", t: "Sahada’ya yapıştırın", d: "Ana sayfadaki alana bağlantıyı koyup Başlat’a basın." },
  { n: "04", t: "Listeyi filtreleyin", d: "Şehir, puan ve iletişim durumuna göre daraltın; Excel indirin." },
  { n: "05", t: "Yakın firmaları görün", d: "Tablodaki yakın firma sayısına tıklayın; aynı türdeki komşu işletmeleri inceleyin." },
  { n: "06", t: "Haritada bakın", d: "Tablo ve harita sekmeleriyle konum dağılımını kontrol edin." },
];

const DEGERLENDIRME = [
  {
    t: "Yıldız puanı",
    d: "Kaynak puanı varsa onu kullanır. Yoksa telefon, web sitesi, e-posta ve adres doluluğundan üretilir.",
    stars: true,
  },
  {
    t: "Yakındaki benzer firmalar",
    d: "Tarama bitince tabloda görünür. Tıklayınca aynı kategorideki yakın işletmeleri mesafe ve telefonla görürsünüz.",
  },
  {
    t: "Şehir filtresi",
    d: "Türkiye’nin 81 ili listelenir; sonuçları ile göre daraltırsınız.",
  },
];

export default function LandingIntro() {
  const [acik, setAcik] = useState<number | null>(0);

  return (
    <section className="land-intro" id="incele" aria-label="Kullanım rehberi">
      <div className="land-intro-block" id="rehber">
        <p className="setup-eyebrow">Kullanım rehberi</p>
        <h2>Sahada nasıl kullanılır?</h2>
        <div className="land-guide-grid">
          {REHBER.map((a) => (
            <article key={a.n} className="land-guide-card">
              <span>{a.n}</span>
              <h3>{a.t}</h3>
              <p>{a.d}</p>
            </article>
          ))}
        </div>
      </div>

      <div className="land-intro-block">
        <p className="setup-eyebrow">Kategoriler</p>
        <h2>Hangi işletmeleri toplayabilirsiniz?</h2>
        <div className="land-cat-grid">
          {KATEGORILER.map((k) => (
            <article key={k.t} className="land-cat-card">
              <h3>{k.t}</h3>
              <p>{k.d}</p>
            </article>
          ))}
        </div>
      </div>

      <div className="land-intro-block">
        <p className="setup-eyebrow">Değerlendirme</p>
        <h2>Puan ve yakın firmalar</h2>
        <div className="land-accordion">
          {DEGERLENDIRME.map((item, i) => {
            const open = acik === i;
            return (
              <div key={item.t} className={`land-acc-item ${open ? "open" : ""}`}>
                <button
                  type="button"
                  className="land-acc-btn"
                  aria-expanded={open}
                  onClick={() => setAcik(open ? null : i)}
                >
                  <span className="land-acc-title">
                    {item.stars ? <span className="land-acc-stars" aria-hidden>★★★★★</span> : null}
                    {item.t}
                  </span>
                  <i aria-hidden>{open ? "−" : "+"}</i>
                </button>
                {open && <p className="land-acc-body">{item.d}</p>}
              </div>
            );
          })}
        </div>
      </div>
    </section>
  );
}
