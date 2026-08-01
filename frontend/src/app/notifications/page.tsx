"use client";

import { useEffect, useState } from "react";
import AppShell from "@/components/AppShell";
import api from "@/lib/api";
import { useAuth } from "@/lib/auth";

type Bildirim = { id: number; title: string; message: string; created_at: string };

export default function Bildirimler() {
  const { hydrated, online } = useAuth();
  const [items, setItems] = useState<Bildirim[]>([]);

  useEffect(() => {
    if (!hydrated || !online) return;
    api.get("/notifications").then((r) => setItems(r.data.data || [])).catch(() => undefined);
  }, [hydrated, online]);

  return (
    <AppShell>
      <div className="page">
        <div className="card stack">
          <h1 style={{ margin: 0 }}>Bildirimler</h1>
          <p className="muted" style={{ margin: 0 }}>Tarama başladı / bitti / rapor hazır olayları.</p>
          {items.length === 0 && <div className="muted">Henüz bildirim yok.</div>}
          {items.map((n) => (
            <div key={n.id} style={{ padding: 14, borderRadius: 14, border: "1px solid var(--line)", background: "#fff7f3" }}>
              <strong>{n.title}</strong>
              <div className="muted">{n.message}</div>
            </div>
          ))}
        </div>
      </div>
    </AppShell>
  );
}
