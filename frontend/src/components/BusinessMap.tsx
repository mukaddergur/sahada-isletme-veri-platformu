"use client";

import { useEffect } from "react";
import { MapContainer, TileLayer, CircleMarker, Popup, useMap } from "react-leaflet";
import "leaflet/dist/leaflet.css";
import { googleMapsUrl, openStreetMapUrl } from "@/lib/maps";

type Point = {
  id: number;
  name: string;
  latitude: number;
  longitude: number;
  rating?: number;
  category?: string;
  district?: string;
  address?: string;
  maps_url?: string;
  place_id?: string;
};

function Focus({ points, focusId }: { points: Point[]; focusId?: number }) {
  const map = useMap();
  useEffect(() => {
    const target = points.find((p) => p.id === focusId) || points[0];
    if (!target) return;
    map.flyTo([target.latitude, target.longitude], focusId ? 16 : 12, { duration: 0.65 });
  }, [focusId, points, map]);
  return null;
}

export default function BusinessMap({
  points,
  focusId,
}: {
  points: Point[];
  focusId?: number;
}) {
  const center: [number, number] =
    points.length > 0 ? [points[0].latitude, points[0].longitude] : [41.02, 29.0];

  return (
    <div style={{ height: "100%", minHeight: 420, width: "100%" }}>
      <MapContainer
        center={center}
        zoom={12}
        style={{ height: "100%", width: "100%", background: "#d9e6ef" }}
        scrollWheelZoom
      >
        <TileLayer
          attribution='&copy; OpenStreetMap'
          url="https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png"
        />
        <Focus points={points} focusId={focusId} />
        {points.map((p) => {
          const aktif = focusId === p.id;
          const gmaps = googleMapsUrl(p);
          const osm = openStreetMapUrl(p);
          return (
            <CircleMarker
              key={p.id}
              center={[p.latitude, p.longitude]}
              radius={aktif ? 12 : 7}
              eventHandlers={{
                click: () => {
                  if (gmaps) window.open(gmaps, "_blank", "noopener,noreferrer");
                },
              }}
              pathOptions={{
                color: aktif ? "#0a2744" : "#0f766e",
                fillColor: aktif ? "#f59e0b" : "#14b8a6",
                fillOpacity: 0.92,
                weight: aktif ? 3 : 1.5,
              }}
            >
              <Popup>
                <div style={{ minWidth: 190, fontFamily: "IBM Plex Sans, sans-serif" }}>
                  <strong style={{ fontSize: 14 }}>{p.name}</strong>
                  <div style={{ color: "#5a6b7d", marginTop: 4, fontSize: 12 }}>
                    {p.district || "Türkiye"} · {p.category || "İşletme"}
                    {p.rating != null ? ` · ★ ${Number(p.rating).toFixed(1)}` : ""}
                  </div>
                  {p.address ? (
                    <div style={{ color: "#5a6b7d", marginTop: 2, fontSize: 12 }}>{p.address}</div>
                  ) : null}
                  <div style={{ color: "#5a6b7d", marginTop: 2, fontSize: 12 }}>
                    {p.latitude.toFixed(5)}, {p.longitude.toFixed(5)}
                  </div>
                  <div style={{ display: "flex", gap: 10, marginTop: 8, fontSize: 12, fontWeight: 700 }}>
                    {gmaps ? (
                      <a href={gmaps} target="_blank" rel="noreferrer" style={{ color: "#0f766e" }}>
                        Google Maps’te aç
                      </a>
                    ) : null}
                    {osm ? (
                      <a href={osm} target="_blank" rel="noreferrer" style={{ color: "#0a2744" }}>
                        OSM
                      </a>
                    ) : null}
                  </div>
                </div>
              </Popup>
            </CircleMarker>
          );
        })}
      </MapContainer>
    </div>
  );
}
