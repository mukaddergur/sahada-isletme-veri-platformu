"use client";

import {
  Bar,
  BarChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import type { DashboardData } from "@/lib/types";

export function Stats({ stats }: { stats: DashboardData["stats"] }) {
  const items = [
    { k: "Toplam işletme", v: stats.total_businesses },
    { k: "Ortalama puan", v: stats.avg_rating || "—" },
    { k: "Ortalama skor", v: stats.avg_ai_score || "—" },
    { k: "Sitesiz firma", v: stats.without_website },
  ];
  return (
    <div className="stats">
      {items.map((i) => (
        <div className="stat" key={i.k}>
          <div className="k">{i.k}</div>
          <div className="v">{i.v}</div>
        </div>
      ))}
    </div>
  );
}

export function DistrictChart({ data }: { data: DashboardData["by_district"] }) {
  const chartData = data.map((d) => ({
    district: d.district || "—",
    total: d.total,
    with_phone: d.with_phone ?? 0,
    with_website: d.with_website ?? 0,
  }));
  return (
    <div style={{ width: "100%", height: 260 }}>
      <ResponsiveContainer>
        <BarChart data={chartData}>
          <CartesianGrid strokeDasharray="3 3" stroke="#e4e0d7" />
          <XAxis dataKey="district" tick={{ fontSize: 12 }} />
          <YAxis allowDecimals={false} />
          <Tooltip />
          <Bar dataKey="total" fill="#0a2744" radius={[6, 6, 0, 0]} name="Toplam" />
          <Bar dataKey="with_phone" fill="#0f766e" radius={[6, 6, 0, 0]} name="Telefon" />
          <Bar dataKey="with_website" fill="#3b82f6" radius={[6, 6, 0, 0]} name="Website" />
        </BarChart>
      </ResponsiveContainer>
    </div>
  );
}

export function CategoryChart({ data }: { data: DashboardData["by_category"] }) {
  const chartData = data.map((d) => ({
    category: d.category || "—",
    total: d.total,
    with_phone: d.with_phone ?? 0,
    with_website: d.with_website ?? 0,
  }));
  return (
    <div style={{ width: "100%", height: 260 }}>
      <ResponsiveContainer>
        <BarChart data={chartData}>
          <CartesianGrid strokeDasharray="3 3" stroke="#e4e0d7" />
          <XAxis dataKey="category" tick={{ fontSize: 12 }} />
          <YAxis allowDecimals={false} />
          <Tooltip />
          <Bar dataKey="total" fill="#0a2744" radius={[6, 6, 0, 0]} name="Toplam" />
          <Bar dataKey="with_phone" fill="#0f766e" radius={[6, 6, 0, 0]} name="Telefon" />
          <Bar dataKey="with_website" fill="#3b82f6" radius={[6, 6, 0, 0]} name="Website" />
        </BarChart>
      </ResponsiveContainer>
    </div>
  );
}
