"use client";

import { useEffect, useState } from "react";
import AppShell from "@/components/AppShell";
import api from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { googleMapsUrl, hasCoords, openStreetMapUrl } from "@/lib/maps";
import { ONIZLEME_PANEL, type Business } from "@/lib/types";

export default function TumListe() {
  const { hydrated, online } = useAuth();
  const [items, setItems] = useState<Business[]>([]);
  const [q, setQ] = useState("");

  useEffect(() => {
    if (!hydrated) return;
    if (!online) {
      setItems(ONIZLEME_PANEL.top_rated as Business[]);
      return;
    }
    api
      .get("/businesses", { params: { per_page: 200, q: q || undefined } })
      .then((r) => setItems(r.data.data || []))
      .catch(() => setItems(ONIZLEME_PANEL.top_rated as Business[]));
  }, [hydrated, online, q]);

  return (
    <AppShell>
      <div className="page">
        <div className="card">
          <h1 style={{ marginTop: 0 }}>Tüm liste</h1>
          <p className="muted">Kayıtlı tüm işletmeler — Google Maps / OSM linkleri gerçek koordinatlara gider.</p>
          <input className="field mt-14" placeholder="Firma / semt / kategori ara…" value={q} onChange={(e) => setQ(e.target.value)} />
          <div className="table-wrap mt-14">
            <table className="table">
              <thead>
                <tr>
                  <th>Firma</th>
                  <th>Telefon</th>
                  <th>Website</th>
                  <th>İlçe</th>
                  <th>Harita</th>
                  <th>Skor</th>
                </tr>
              </thead>
              <tbody>
                {items.map((b) => {
                  const gmaps = googleMapsUrl(b);
                  const osm = openStreetMapUrl(b);
                  return (
                    <tr key={b.id}>
                      <td>
                        <strong>
                          <a href={`/businesses/${b.id}`} style={{ color: "inherit", textDecoration: "none" }}>
                            {b.name}
                          </a>
                        </strong>
                      </td>
                      <td>{b.phone ? <a className="phone" href={`tel:${b.phone.replace(/\s/g, "")}`}>{b.phone}</a> : <span className="badge warn">yok</span>}</td>
                      <td>
                        {b.website ? (
                          <a href={b.website.startsWith("http") ? b.website : `https://${b.website}`} target="_blank" rel="noreferrer" style={{ color: "var(--teal)", fontWeight: 700 }}>
                            site
                          </a>
                        ) : (
                          <span className="badge warn">yok</span>
                        )}
                      </td>
                      <td>{b.district || "—"}</td>
                      <td>
                        <div className="map-links">
                          {gmaps ? <a href={gmaps} target="_blank" rel="noreferrer">Google</a> : <span className="badge warn">yok</span>}
                          {osm ? <a href={osm} target="_blank" rel="noreferrer">OSM</a> : null}
                        </div>
                        {hasCoords(b) ? (
                          <div className="firm-meta">{Number(b.latitude).toFixed(5)}, {Number(b.longitude).toFixed(5)}</div>
                        ) : null}
                      </td>
                      <td>{b.ai_score != null ? <span className="badge info">{b.ai_score}</span> : "—"}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </AppShell>
  );
}
