"use client";

type DensityRow = {
  id: number;
  name: string;
  category: string;
  district: string;
  competitors_1km: number;
  competition_level: string;
  phone?: string | null;
  website?: string | null;
};

type DistrictGap = {
  district: string;
  total_businesses: number;
  missing_categories: string[];
  sparse_categories: { category: string; total: number }[];
  message: string;
};

type ContactGap = {
  district: string;
  missing_phone_pct: number;
  missing_website_pct: number;
  message: string;
};

export type MarketInsights = {
  radius_m: number;
  summary: {
    businesses_with_coords: number;
    avg_competitors_1km: number;
    low_competition_count: number;
    district_gap_count: number;
  };
  densest: DensityRow[];
  opportunities: DensityRow[];
  district_gaps: DistrictGap[];
  contact_gaps: ContactGap[];
  messages: string[];
  density_map?: Record<string, number>;
  density_peers?: Record<string, unknown[]>;
};

export default function MarketInsightsPanel({
  data,
  loading,
  onRefresh,
}: {
  data: MarketInsights | null;
  loading?: boolean;
  onRefresh?: () => void;
}) {
  if (loading) {
    return (
      <section className="insight-panel">
        <div className="insight-head">
          <div>
            <div className="setup-eyebrow">Pazar analizi</div>
            <h2>Rakip yoğunluğu & boşluklar</h2>
          </div>
        </div>
        <p className="muted">Gerçek verilerden analiz hesaplanıyor…</p>
      </section>
    );
  }

  if (!data) {
    return null;
  }

  const s = data.summary;

  return (
    <section className="insight-panel">
      <div className="insight-head">
        <div>
          <div className="setup-eyebrow">Pazar analizi · gerçek OSM</div>
          <h2>Rakip yoğunluğu & semt boşlukları</h2>
          <p>
            {s.businesses_with_coords} konumlu işletme · {data.radius_m} m yarıçap · ort.{" "}
            {s.avg_competitors_1km} yakın firma · {s.low_competition_count} düşük yoğunluk noktası
          </p>
        </div>
        {onRefresh && (
          <button type="button" className="btn ghost compact" onClick={onRefresh}>
            Yenile
          </button>
        )}
      </div>

      {data.messages.length > 0 && (
        <div className="insight-messages">
          {data.messages.slice(0, 5).map((m) => (
            <article key={m} className="insight-msg">
              {m}
            </article>
          ))}
        </div>
      )}

      <div className="insight-grid">
        <div className="insight-card">
          <h3>Yoğun bölgeler</h3>
          <p className="insight-hint">Aynı kategoride yakında çok firma var</p>
          <ul className="insight-list">
            {data.densest.slice(0, 6).map((r) => (
              <li key={`d-${r.id}`}>
                <div>
                  <strong>{r.name}</strong>
                  <span>
                    {r.district} · {r.category}
                  </span>
                </div>
                <em className="lvl high">{r.competitors_1km} yakın</em>
              </li>
            ))}
            {data.densest.length === 0 && <li className="empty-li">Veri yok</li>}
          </ul>
        </div>

        <div className="insight-card">
          <h3>Düşük rekabet / fırsat</h3>
          <p className="insight-hint">Yakında aynı kategoride az firma</p>
          <ul className="insight-list">
            {data.opportunities.slice(0, 6).map((r) => (
              <li key={`o-${r.id}`}>
                <div>
                  <strong>{r.name}</strong>
                  <span>
                    {r.district} · {r.category}
                  </span>
                </div>
                <em className="lvl low">{r.competitors_1km} yakın</em>
              </li>
            ))}
            {data.opportunities.length === 0 && <li className="empty-li">Veri yok</li>}
          </ul>
        </div>

        <div className="insight-card">
          <h3>Semtte eksik kategoriler</h3>
          <p className="insight-hint">“Şu semtte şu yok / çok seyrek”</p>
          <ul className="insight-list gaps">
            {data.district_gaps.slice(0, 6).map((g) => (
              <li key={g.district}>
                <div>
                  <strong>{g.district}</strong>
                  <span>{g.message}</span>
                  {g.missing_categories.length > 0 && (
                    <div className="gap-tags">
                      {g.missing_categories.map((c) => (
                        <i key={c}>{c}</i>
                      ))}
                    </div>
                  )}
                </div>
              </li>
            ))}
            {data.district_gaps.length === 0 && <li className="empty-li">Belirgin boşluk yok</li>}
          </ul>
        </div>

        <div className="insight-card">
          <h3>İletişim boşlukları</h3>
          <p className="insight-hint">Telefon / website eksikliği yüksek semtler</p>
          <ul className="insight-list">
            {data.contact_gaps.slice(0, 6).map((c) => (
              <li key={`c-${c.district}`}>
                <div>
                  <strong>{c.district}</strong>
                  <span>{c.message}</span>
                </div>
                <em className="lvl mid">
                  %{c.missing_phone_pct} tel · %{c.missing_website_pct} web
                </em>
              </li>
            ))}
            {data.contact_gaps.length === 0 && <li className="empty-li">Belirgin boşluk yok</li>}
          </ul>
        </div>
      </div>
    </section>
  );
}
