"use client";

import { useEffect, useRef } from "react";
import L from "leaflet";
import "leaflet/dist/leaflet.css";

const SEHIRLER = [
  { name: "İstanbul", lat: 41.01, lon: 28.98, color: "#fb923c" },
  { name: "Ankara", lat: 39.93, lon: 32.86, color: "#60a5fa" },
  { name: "İzmir", lat: 38.42, lon: 27.14, color: "#34d399" },
  { name: "Gaziantep", lat: 37.07, lon: 37.38, color: "#fbbf24" },
  { name: "Antalya", lat: 36.9, lon: 30.7, color: "#a78bfa" },
  { name: "Trabzon", lat: 41.0, lon: 39.72, color: "#f472b6" },
];

const ORNEKLER = [
  { ad: "Moda Kahve", yer: "İstanbul · Kadıköy", puan: "★★★★☆", etiket: "Telefon" },
  { ad: "Sahil Restoran", yer: "İzmir · Alsancak", puan: "★★★★★", etiket: "Web sitesi" },
  { ad: "Atölye Eğitim", yer: "Ankara · Çankaya", puan: "★★★☆☆", etiket: "Adres" },
];

export function TurkeyMiniMap({ soft = false }: { soft?: boolean }) {
  const hostRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const host = hostRef.current;
    if (!host) return;

    let map: L.Map | null = null;
    let cancelled = false;
    let sizeTimer = 0;

    const boot = window.setTimeout(() => {
      if (cancelled || !hostRef.current) return;
      host.innerHTML = "";
      const pane = document.createElement("div");
      pane.style.height = "100%";
      pane.style.width = "100%";
      host.appendChild(pane);

      map = L.map(pane, {
        center: [39.1, 35.2],
        zoom: soft ? 5.2 : 5,
        minZoom: 5,
        maxZoom: 7,
        zoomControl: false,
        attributionControl: false,
        dragging: !soft,
        scrollWheelZoom: false,
        doubleClickZoom: false,
        boxZoom: false,
        keyboard: false,
      });

      L.tileLayer(
        soft
          ? "https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png"
          : "https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png",
        { maxZoom: 7 }
      ).addTo(map);

      for (const s of SEHIRLER) {
        L.circleMarker([s.lat, s.lon], {
          radius: soft ? 4 : 7,
          color: "#fff",
          weight: soft ? 1 : 2,
          fillColor: s.color,
          fillOpacity: soft ? 0.55 : 0.95,
        }).addTo(map!);
      }

      sizeTimer = window.setTimeout(() => map?.invalidateSize(), 160);
    }, 50);

    return () => {
      cancelled = true;
      window.clearTimeout(boot);
      window.clearTimeout(sizeTimer);
      if (map) {
        map.remove();
        map = null;
      }
      if (host) host.innerHTML = "";
    };
  }, [soft]);

  return (
    <div
      className={`turkey-mini turkey-mini-live${soft ? " is-soft" : ""}`}
      aria-label="Türkiye haritası"
      aria-hidden={soft || undefined}
    >
      <div ref={hostRef} className="turkey-mini-host" />
      {!soft && <span className="turkey-mini-badge">Türkiye</span>}
    </div>
  );
}

export default function HeroTurkeyVisual() {
  return (
    <div className="land-visual-card">
      <div className="land-visual-map">
        <TurkeyMiniMap />
      </div>
      <div className="land-visual-list">
        {ORNEKLER.map((o) => (
          <div key={o.ad} className="land-visual-row">
            <div>
              <b>{o.ad}</b>
              <em>{o.yer}</em>
            </div>
            <span>
              <i>{o.puan}</i>
              {o.etiket}
            </span>
          </div>
        ))}
      </div>
    </div>
  );
}
