"use client";

import { useEffect, useMemo, useState } from "react";
import dynamic from "next/dynamic";
import type { Business } from "@/lib/types";
import { googleMapsUrl, hasCoords, openStreetMapUrl } from "@/lib/maps";
import { starScore, starsLabel } from "@/lib/score";
import { TURKIYE_ILLERI } from "@/lib/cities";

const BusinessMap = dynamic(() => import("@/components/BusinessMap"), { ssr: false });

export type RivalPeer = {
  id: number;
  name: string;
  distance_m: number;
  phone?: string | null;
  address?: string | null;
  district?: string | null;
  website?: string | null;
  latitude?: number | null;
  longitude?: number | null;
};

function formatMesafe(m?: number | null) {
  if (m == null || Number.isNaN(m)) return "—";
  if (m < 1000) return `${Math.round(m)} m`;
  return `${(m / 1000).toFixed(1)} km`;
}

function formatCollectedAt(iso?: string | null) {
  if (!iso) return "—";
  try {
    return new Date(iso).toLocaleString("tr-TR", {
      day: "2-digit",
      month: "2-digit",
      year: "numeric",
      hour: "2-digit",
      minute: "2-digit",
    });
  } catch {
    return "—";
  }
}

type Props = {
  listeHazir: boolean;
  gorunen: Business[];
  ozet: { tel: number; web: number; mail?: number };
  merkezEtiket: string;
  tab: "liste" | "harita";
  setTab: (t: "liste" | "harita") => void;
  q: string;
  setQ: (v: string) => void;
  filtre: string;
  setFiltre: (v: "hepsi" | "dolu" | "telsiz" | "sitesiz" | "skorlu" | "mailsız" | "skor70") => void;
  sehir: string;
  setSehir: (v: string) => void;
  minPuan: number;
  setMinPuan: (v: number) => void;
  densityMap: Record<string, number>;
  densityPeers: Record<string, RivalPeer[]>;
  focusId?: number;
  mapPoints: { id: number; name: string; latitude: number; longitude: number; rating?: number; category?: string; district?: string }[];
  kaynakEtiketi: (b: Pick<Business, "place_id" | "source_label" | "data_source">) => string;
  haritadaGoster: (b: Business) => void;
  excelIndir: () => void;
  projectId: number | null;
};

export default function LeadResultsSection({
  listeHazir,
  gorunen,
  ozet,
  merkezEtiket,
  tab,
  setTab,
  q,
  setQ,
  filtre,
  setFiltre,
  sehir,
  setSehir,
  minPuan,
  setMinPuan,
  densityMap,
  densityPeers,
  focusId,
  mapPoints,
  kaynakEtiketi,
  haritadaGoster,
  excelIndir,
  projectId,
}: Props) {
  const [rivalsFor, setRivalsFor] = useState<{ id: number; name: string } | null>(null);

  useEffect(() => {
    if (!rivalsFor) return;
    function onKey(e: KeyboardEvent) {
      if (e.key === "Escape") setRivalsFor(null);
    }
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [rivalsFor]);

  const peers = rivalsFor ? densityPeers[String(rivalsFor.id)] || [] : [];

  const rivalSummary = useMemo(() => {
    if (!rivalsFor) return null;
    const withPhone = peers.filter((p) => p.phone).length;
    const avg =
      peers.length > 0
        ? Math.round(peers.reduce((a, p) => a + (p.distance_m || 0), 0) / peers.length)
        : 0;
    return { withPhone, avg };
  }, [peers, rivalsFor]);

  function rakipHaritada(peer: RivalPeer) {
    haritadaGoster({
      id: peer.id,
      name: peer.name,
      phone: peer.phone ?? undefined,
      address: peer.address ?? undefined,
      website: peer.website ?? undefined,
      district: peer.district ?? undefined,
      latitude: peer.latitude ?? undefined,
      longitude: peer.longitude ?? undefined,
    } as Business);
    setRivalsFor(null);
  }

  return (
    <section className={`results-sheet land-results ${listeHazir && gorunen.length ? "ready" : ""}`} id="liste">
      <header className="results-head">
        <div>
          <div className="setup-eyebrow">Sonuçlar</div>
          <h2>{listeHazir && gorunen.length ? "Listeniz hazır" : "İşletme listesi"}</h2>
          {gorunen.length > 0 && (
            <p>
              {`${gorunen.length} işletme · ${ozet.tel} telefon · ${ozet.web} website${ozet.mail ? ` · ${ozet.mail} e-posta` : ""}`}
              {merkezEtiket ? ` · ${merkezEtiket}` : ""}
            </p>
          )}
        </div>
        <div className="results-actions">
          <button type="button" className={`tab ${tab === "liste" ? "on" : ""}`} onClick={() => setTab("liste")}>
            Tablo
          </button>
          <button type="button" className={`tab ${tab === "harita" ? "on" : ""}`} onClick={() => setTab("harita")}>
            Harita
          </button>
          <button type="button" className="btn dark compact" onClick={excelIndir} disabled={!projectId || !gorunen.length}>
            Excel
          </button>
        </div>
      </header>

      {tab === "liste" && (
        <>
          <div className="filter-bar">
            <input
              className="field search-mini"
              placeholder="Firma, telefon, adres, website…"
              value={q}
              onChange={(e) => setQ(e.target.value)}
            />
            <select className="select filter-select" value={sehir} onChange={(e) => setSehir(e.target.value)} aria-label="Şehir">
              <option value="">Tüm şehirler (81 il)</option>
              {TURKIYE_ILLERI.map((c) => (
                <option key={c} value={c}>
                  {c}
                </option>
              ))}
            </select>
            <div className="star-filter" role="group" aria-label="Minimum puan">
              <button
                type="button"
                className={`star-clear ${minPuan === 0 ? "on" : ""}`}
                onClick={() => setMinPuan(0)}
              >
                Hepsi
              </button>
              {[1, 2, 3, 4, 5].map((n) => (
                <button
                  key={n}
                  type="button"
                  className={`star-pick ${minPuan >= n ? "on" : ""}`}
                  title={`${n}+ yıldız`}
                  aria-label={`${n} yıldız ve üzeri`}
                  aria-pressed={minPuan === n}
                  onClick={() => setMinPuan(minPuan === n ? 0 : n)}
                >
                  ★
                </button>
              ))}
            </div>
          </div>

          <div className="chips tight results-filters">
            {(
              [
                ["hepsi", "Hepsi"],
                ["dolu", "İletişim dolu"],
                ["telsiz", "Tel yok"],
                ["sitesiz", "Web yok"],
                ["mailsız", "Mail yok"],
                ["skor70", "AI ≥ 70"],
              ] as const
            ).map(([k, label]) => (
              <button key={k} type="button" className={`chip ${filtre === k ? "on" : ""}`} onClick={() => setFiltre(k)}>
                {label}
              </button>
            ))}
          </div>

          <div className="table-wrap lead-table-wrap">
            <table className="table lead-table">
              <thead>
                <tr>
                  <th>Firma</th>
                  <th>Puan</th>
                  <th>Telefon</th>
                  <th>E-posta</th>
                  <th>Website</th>
                  <th>Adres</th>
                  <th>Yakın firma</th>
                  <th>Kaynak</th>
                  <th>Çekim</th>
                  <th>Harita</th>
                </tr>
              </thead>
              <tbody>
                {gorunen.length === 0 && (
                  <tr>
                    <td colSpan={10}>
                      <div className="empty lead-empty">
                        <strong>Henüz işletme yok</strong>
                        <span>Yukarıya Maps URL yapıştırıp Başlat’a basın.</span>
                      </div>
                    </td>
                  </tr>
                )}
                {gorunen.map((b) => {
                  const gmaps = b.google_maps_url || googleMapsUrl(b);
                  const osm = b.osm_url || openStreetMapUrl(b);
                  const rivals = densityMap[String(b.id)];
                  const canOpen = rivals != null && rivals > 0;
                  const stars = starScore(b);
                  return (
                    <tr key={b.id} className={focusId === b.id ? "row-focus" : undefined}>
                      <td>
                        <strong>{b.name}</strong>
                        <div className="firm-meta">
                          {b.category || "İşletme"}
                          {b.city ? ` · ${b.city}` : ""}
                          {b.district ? ` · ${b.district}` : ""}
                          {b.distance_m != null ? ` · ${formatMesafe(b.distance_m)}` : ""}
                        </div>
                      </td>
                      <td>
                        <span className="stars" title={b.rating != null ? `Kaynak puan: ${b.rating}` : "Veri doluluk puanı"}>
                          {starsLabel(stars)}
                        </span>
                      </td>
                      <td>
                        {b.phone ? (
                          <a className="phone" href={`tel:${b.phone.replace(/\s/g, "")}`}>{b.phone}</a>
                        ) : (
                          <span className="badge warn">—</span>
                        )}
                      </td>
                      <td>
                        {b.email ? (
                          <a className="web-link" href={`mailto:${b.email}`}>{b.email}</a>
                        ) : (
                          <span className="badge warn">—</span>
                        )}
                      </td>
                      <td>
                        {b.website ? (
                          <a
                            href={b.website.startsWith("http") ? b.website : `https://${b.website}`}
                            target="_blank"
                            rel="noreferrer"
                            className="web-link"
                          >
                            Aç
                          </a>
                        ) : (
                          <span className="badge warn">—</span>
                        )}
                      </td>
                      <td className="addr-cell">{b.address || "—"}</td>
                      <td>
                        {rivals == null ? (
                          <span className="badge warn">—</span>
                        ) : canOpen ? (
                          <button
                            type="button"
                            className={`badge badge-btn ${rivals <= 2 ? "ok" : rivals <= 5 ? "warn" : "danger-soft"}`}
                            title="Yakındaki benzer firmaları göster"
                            onClick={() => setRivalsFor({ id: b.id, name: b.name })}
                          >
                            {rivals} yakın
                          </button>
                        ) : (
                          <span className="badge ok">{rivals} yakın</span>
                        )}
                      </td>
                      <td>
                        <span className="badge info">{kaynakEtiketi(b)}</span>
                      </td>
                      <td className="addr-cell">{formatCollectedAt(b.collected_at)}</td>
                      <td>
                        <div className="map-links">
                          {gmaps ? <a href={gmaps} target="_blank" rel="noreferrer">Maps</a> : null}
                          {osm ? <a href={osm} target="_blank" rel="noreferrer">OSM</a> : null}
                          {hasCoords(b) ? (
                            <button type="button" className="link-btn" onClick={() => haritadaGoster(b)}>
                              Pin
                            </button>
                          ) : null}
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </>
      )}

      {tab === "harita" && (
        <div className="map-box">
          {mapPoints.length ? (
            <BusinessMap points={mapPoints} focusId={focusId} />
          ) : (
            <div className="empty">Önce Başlat ile işletme toplayın.</div>
          )}
        </div>
      )}

      {rivalsFor && (
        <div className="rivals-backdrop" role="presentation" onClick={() => setRivalsFor(null)}>
          <div
            className="rivals-panel"
            role="dialog"
            aria-modal="true"
            aria-labelledby="rivals-title"
            onClick={(e) => e.stopPropagation()}
          >
            <header className="rivals-head">
              <div>
                <p className="rivals-eyebrow">Yakındaki benzer firmalar · aynı kategori</p>
                <h3 id="rivals-title">{rivalsFor.name}</h3>
                <p className="rivals-sub">
                  {peers.length} firma
                  {rivalSummary ? ` · ort. ${formatMesafe(rivalSummary.avg)} · ${rivalSummary.withPhone} telefonlu` : ""}
                </p>
              </div>
              <button type="button" className="rivals-close" onClick={() => setRivalsFor(null)} aria-label="Kapat">
                ×
              </button>
            </header>
            <div className="rivals-stats">
              <div><strong>{peers.length}</strong><span>firma</span></div>
              <div><strong>{rivalSummary?.withPhone ?? 0}</strong><span>telefon</span></div>
              <div><strong>{formatMesafe(rivalSummary?.avg)}</strong><span>ort. mesafe</span></div>
            </div>
            <ul className="rivals-list">
              {peers.length === 0 && (
                <li className="rivals-empty">Bu işletme için yakında benzer firma listesi yok — fırsat alanı olabilir.</li>
              )}
              {peers.map((p) => (
                <li key={p.id}>
                  <button type="button" className="rivals-item" onClick={() => rakipHaritada(p)}>
                    <span className="rivals-name">{p.name}</span>
                    <span className="rivals-meta">
                      {formatMesafe(p.distance_m)}
                      {p.district ? ` · ${p.district}` : ""}
                      {p.phone ? ` · ${p.phone}` : " · tel yok"}
                    </span>
                    {p.address ? <span className="rivals-addr">{p.address}</span> : null}
                    <span className="rivals-cta">Haritada göster →</span>
                  </button>
                </li>
              ))}
            </ul>
          </div>
        </div>
      )}
    </section>
  );
}
