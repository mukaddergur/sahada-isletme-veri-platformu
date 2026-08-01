"use client";

import { useEffect, useRef } from "react";
import L from "leaflet";
import "leaflet/dist/leaflet.css";

const SEHIRLER = [
  { name: "İstanbul", lat: 41.01, lon: 28.98, color: "#fb923c" },
  { name: "Ankara", lat: 39.93, lon: 32.86, color: "#60a5fa" },
  { name: "İzmir", lat: 38.42, lon: 27.14, color: "#34d399" },
  { name: "Bursa", lat: 40.19, lon: 29.06, color: "#a78bfa" },
  { name: "Antalya", lat: 36.9, lon: 30.7, color: "#f472b6" },
  { name: "Gaziantep", lat: 37.07, lon: 37.38, color: "#fbbf24" },
  { name: "Konya", lat: 37.87, lon: 32.48, color: "#38bdf8" },
  { name: "Trabzon", lat: 41.0, lon: 39.72, color: "#fb7185" },
  { name: "Adana", lat: 37.0, lon: 35.32, color: "#f97316" },
  { name: "Samsun", lat: 41.29, lon: 36.33, color: "#22c55e" },
];

export default function LandingTurkeyMap() {
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
        center: [39.0, 35.2],
        zoom: 6,
        minZoom: 5,
        maxZoom: 14,
        zoomControl: true,
        attributionControl: false,
        scrollWheelZoom: true,
        doubleClickZoom: true,
        boxZoom: true,
        keyboard: false,
      });

      L.tileLayer("https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png", {
        maxZoom: 14,
      }).addTo(map);

      map.fitBounds(
        [
          [35.9, 25.9],
          [42.2, 44.9],
        ],
        { padding: [24, 48] }
      );

      map.on("click", (e) => {
        const nextZoom = Math.min((map?.getZoom() ?? 6) + 2, 14);
        map?.flyTo(e.latlng, nextZoom, { duration: 0.65 });
      });

      for (const s of SEHIRLER) {
        const marker = L.circleMarker([s.lat, s.lon], {
          radius: 8,
          color: "#fff",
          weight: 2,
          fillColor: s.color,
          fillOpacity: 0.92,
        })
          .bindTooltip(s.name, { direction: "top", offset: [0, -8] })
          .addTo(map!);

        marker.on("click", (e) => {
          L.DomEvent.stopPropagation(e);
          map?.flyTo([s.lat, s.lon], 11, { duration: 0.7 });
        });
      }

      sizeTimer = window.setTimeout(() => map?.invalidateSize(), 180);
    }, 40);

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
  }, []);

  return (
    <section className="land-tr-map" aria-label="Türkiye haritası">
      <div className="land-tr-map-frame">
        <div ref={hostRef} className="land-tr-map-host" />
        <div className="land-tr-map-fade" aria-hidden />
        <div className="land-tr-map-caption">
          <strong>Türkiye genelinde tarama</strong>
          <span>Tıklayınca yakınlaşır · şehir noktalarına da basabilirsiniz</span>
        </div>
      </div>
    </section>
  );
}
