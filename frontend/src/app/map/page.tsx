"use client";

import dynamic from "next/dynamic";
import { useEffect, useMemo, useState } from "react";
import AppShell from "@/components/AppShell";
import api from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { TURKIYE_ILLERI } from "@/lib/cities";
import { ONIZLEME_PANEL, type DashboardData } from "@/lib/types";

const BusinessMap = dynamic(() => import("@/components/BusinessMap"), { ssr: false });
const TurkeyOverviewMap = dynamic(() => import("@/components/TurkeyOverviewMap"), { ssr: false });

type Point = DashboardData["map_points"][number] & { city?: string };

export default function HaritaSayfa() {
  const { hydrated, online } = useAuth();
  const [points, setPoints] = useState<Point[]>(ONIZLEME_PANEL.map_points as Point[]);
  const [sehir, setSehir] = useState("");
  const [focusId, setFocusId] = useState<number | undefined>();

  useEffect(() => {
    if (!hydrated) return;
    if (!online) {
      setPoints(ONIZLEME_PANEL.map_points as Point[]);
      return;
    }
    api
      .get("/dashboard")
      .then((r) => setPoints(r.data.map_points || []))
      .catch(() => setPoints(ONIZLEME_PANEL.map_points as Point[]));
  }, [hydrated, online]);

  const filtered = useMemo(() => {
    if (!sehir) return points;
    const needle = sehir.toLocaleLowerCase("tr-TR");
    return points.filter((p) => {
      const c = `${(p as Point).city || ""} ${p.district || ""}`.toLocaleLowerCase("tr-TR");
      return c.includes(needle);
    });
  }, [points, sehir]);

  const turkeyPoints = useMemo(
    () =>
      filtered.map((p) => ({
        id: p.id,
        name: p.name,
        latitude: p.latitude,
        longitude: p.longitude,
        city: (p as Point).city,
        district: p.district,
        category: p.category,
        rating: p.rating,
      })),
    [filtered]
  );

  return (
    <AppShell>
      <div className="page">
        <div className="card map-page-head">
          <div>
            <h1 style={{ marginTop: 0 }}>Harita</h1>
            <p className="muted" style={{ marginBottom: 0 }}>
              Toplanan işletmeleri şehir filtresiyle inceleyin. Altta Türkiye genel görünümü vardır.
            </p>
          </div>
          <select className="select filter-select" value={sehir} onChange={(e) => setSehir(e.target.value)}>
            <option value="">Tüm şehirler (81 il)</option>
            {TURKIYE_ILLERI.map((c) => (
              <option key={c} value={c}>
                {c}
              </option>
            ))}
          </select>
        </div>

        <div className="card mt-14">
          <div className="map-box">
            {filtered.length ? (
              <BusinessMap points={filtered} focusId={focusId} />
            ) : (
              <div className="empty">Gösterilecek konum yok. Önce Ana sayfadan tarama yapın.</div>
            )}
          </div>
        </div>

        <div className="mt-14">
          <TurkeyOverviewMap points={turkeyPoints} onSelect={setFocusId} />
        </div>
      </div>
    </AppShell>
  );
}
