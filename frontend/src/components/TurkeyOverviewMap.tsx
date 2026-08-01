"use client";

import { useEffect, useMemo } from "react";
import { MapContainer, TileLayer, CircleMarker, Popup, useMap } from "react-leaflet";
import "leaflet/dist/leaflet.css";
import { googleMapsUrl } from "@/lib/maps";

type Point = {
  id: number;
  name: string;
  latitude: number;
  longitude: number;
  city?: string;
  district?: string;
  category?: string;
  rating?: number;
};

const TR_CENTER: [number, number] = [39.0, 35.2];
const TR_BOUNDS: [[number, number], [number, number]] = [
  [35.8, 25.8],
  [42.3, 45.0],
];

function FitTurkey({ points }: { points: Point[] }) {
  const map = useMap();
  useEffect(() => {
    if (points.length >= 2) {
      const lats = points.map((p) => p.latitude);
      const lons = points.map((p) => p.longitude);
      map.fitBounds(
        [
          [Math.min(...lats) - 0.15, Math.min(...lons) - 0.15],
          [Math.max(...lats) + 0.15, Math.max(...lons) + 0.15],
        ],
        { padding: [28, 28], maxZoom: 11 }
      );
    } else if (points.length === 1) {
      map.setView([points[0].latitude, points[0].longitude], 10);
    } else {
      map.fitBounds(TR_BOUNDS, { padding: [20, 20] });
    }
  }, [points, map]);
  return null;
}

export default function TurkeyOverviewMap({
  points,
  onSelect,
}: {
  points: Point[];
  onSelect?: (id: number) => void;
}) {
  const byCity = useMemo(() => {
    const m = new Map<string, number>();
    for (const p of points) {
      const c = (p.city || p.district || "Diğer").trim() || "Diğer";
      m.set(c, (m.get(c) || 0) + 1);
    }
    return [...m.entries()].sort((a, b) => b[1] - a[1]).slice(0, 8);
  }, [points]);

  return (
    <section className="turkey-map-section">
      <header className="turkey-map-head">
        <div>
          <div className="setup-eyebrow">Türkiye görünümü</div>
          <h2>Toplanan işletmeler haritada</h2>
          <p>
            {points.length
              ? `${points.length} konum · şehir ve ilçe dağılımı`
              : "Tarama sonrası işletmeler Türkiye genelinde burada görünür."}
          </p>
        </div>
        {byCity.length > 0 && (
          <div className="turkey-city-chips">
            {byCity.map(([city, n]) => (
              <span key={city} className="chip on soft">
                {city} · {n}
              </span>
            ))}
          </div>
        )}
      </header>
      <div className="map-box turkey-map-box">
        <MapContainer
          center={TR_CENTER}
          zoom={6}
          style={{ height: "100%", width: "100%", background: "#d9e6ef" }}
          scrollWheelZoom
        >
          <TileLayer
            attribution="&copy; OpenStreetMap"
            url="https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png"
          />
          <FitTurkey points={points} />
          {points.map((p) => {
            const gmaps = googleMapsUrl(p);
            return (
              <CircleMarker
                key={p.id}
                center={[p.latitude, p.longitude]}
                radius={7}
                eventHandlers={{
                  click: () => onSelect?.(p.id),
                }}
                pathOptions={{
                  color: "#0a2744",
                  fillColor: "#f97316",
                  fillOpacity: 0.9,
                  weight: 1.5,
                }}
              >
                <Popup>
                  <div style={{ minWidth: 160, fontFamily: "IBM Plex Sans, sans-serif" }}>
                    <strong>{p.name}</strong>
                    <div style={{ color: "#5a6b7d", marginTop: 4, fontSize: 12 }}>
                      {[p.city, p.district, p.category].filter(Boolean).join(" · ")}
                    </div>
                    {gmaps ? (
                      <a href={gmaps} target="_blank" rel="noreferrer" style={{ color: "#0f766e", fontSize: 12, fontWeight: 700 }}>
                        Maps’te aç
                      </a>
                    ) : null}
                  </div>
                </Popup>
              </CircleMarker>
            );
          })}
        </MapContainer>
      </div>
    </section>
  );
}
