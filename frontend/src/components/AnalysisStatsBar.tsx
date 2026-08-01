"use client";

type AnalysisStats = {
  total_businesses: number;
  cities_count: number;
  review_sum: number;
  accuracy_rate: number;
};

function fmt(n: number) {
  return new Intl.NumberFormat("tr-TR").format(Math.max(0, Math.round(n)));
}

export default function AnalysisStatsBar({
  stats,
  loading,
}: {
  stats: AnalysisStats | null;
  loading?: boolean;
}) {
  const total = stats?.total_businesses ?? 0;
  const cities = stats?.cities_count ?? 0;
  const reviews = stats?.review_sum ?? 0;
  const accuracy = stats?.accuracy_rate ?? 0;

  return (
    <div className={`analysis-bar${loading ? " is-loading" : ""}`} aria-label="Analiz özeti">
      <article>
        <i className="ab-icon shop" aria-hidden>
          <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor">
            <path d="M4.5 8.5 6 4.5h12l1.5 4H4.5z" opacity=".9" />
            <path d="M5 9.5h14v1.2c0 .9-.5 1.6-1.2 1.9V19a1 1 0 0 1-1 1H7.2a1 1 0 0 1-1-1v-6.4A2 2 0 0 1 5 10.7V9.5z" />
            <path d="M10 12.2h4V20h-4z" opacity=".35" />
          </svg>
        </i>
        <strong>{fmt(total)}</strong>
        <span>Toplam İşletme</span>
      </article>
      <article>
        <i className="ab-icon pin" aria-hidden>
          <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor">
            <path d="M12 2.5c-3.6 0-6.5 2.9-6.5 6.5 0 4.9 6.5 12.5 6.5 12.5s6.5-7.6 6.5-12.5c0-3.6-2.9-6.5-6.5-6.5zm0 8.8a2.3 2.3 0 1 1 0-4.6 2.3 2.3 0 0 1 0 4.6z" />
          </svg>
        </i>
        <strong>{fmt(cities)}</strong>
        <span>Şehir</span>
      </article>
      <article>
        <i className="ab-icon chat" aria-hidden>
          <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor">
            <path d="M5.2 4.5h13.6A2.2 2.2 0 0 1 21 6.7v8.1a2.2 2.2 0 0 1-2.2 2.2H9.4L5 20.5v-3.5A2.2 2.2 0 0 1 3 14.8V6.7a2.2 2.2 0 0 1 2.2-2.2z" />
            <circle cx="8.2" cy="10.5" r="1.1" fill="#fff" />
            <circle cx="12" cy="10.5" r="1.1" fill="#fff" />
            <circle cx="15.8" cy="10.5" r="1.1" fill="#fff" />
          </svg>
        </i>
        <strong>{fmt(reviews)}</strong>
        <span>Yorum Analizi</span>
      </article>
      <article>
        <i className="ab-icon chart" aria-hidden>
          <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor">
            <rect x="4.2" y="12.2" width="3.6" height="7.3" rx="1.2" />
            <rect x="10.2" y="7.2" width="3.6" height="12.3" rx="1.2" />
            <rect x="16.2" y="4.5" width="3.6" height="15" rx="1.2" />
          </svg>
        </i>
        <strong>%{fmt(accuracy)}</strong>
        <span>Doğruluk Oranı</span>
      </article>
    </div>
  );
}
