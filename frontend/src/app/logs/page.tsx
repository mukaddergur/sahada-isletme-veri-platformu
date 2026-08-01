"use client";

import { useEffect, useState } from "react";
import AppShell from "@/components/AppShell";
import api from "@/lib/api";
import { useAuth } from "@/lib/auth";

type LogRow = {
  id: number;
  action?: string;
  message?: string;
  level?: string;
  created_at?: string;
  user?: { id: number; name: string };
};

export default function LogsPage() {
  const { hydrated, online, user } = useAuth();
  const [items, setItems] = useState<LogRow[]>([]);
  const [err, setErr] = useState("");

  const canSee = user?.role === "admin" || user?.role === "operator";

  useEffect(() => {
    if (!hydrated || !online || !canSee) return;
    api
      .get("/logs")
      .then((r) => setItems(r.data.data || r.data || []))
      .catch(() => setErr("Loglar yüklenemedi (operator/admin gerekir)."));
  }, [hydrated, online, canSee]);

  return (
    <AppShell>
      <div className="page">
        <div className="card stack">
          <h1 style={{ margin: 0 }}>İşlem logları</h1>
          <p className="muted" style={{ margin: 0 }}>
            Tarama başlangıç/bitiş, export, hatalar ve kullanıcı hareketleri (admin / operatör).
          </p>

          {!canSee && (
            <p className="land-status err">Bu sayfa yalnızca admin ve operatör hesapları içindir.</p>
          )}
          {err && <p className="land-status err">{err}</p>}

          {canSee && (
            <div className="table-wrap">
              <table className="table">
                <thead>
                  <tr>
                    <th>Zaman</th>
                    <th>Kullanıcı</th>
                    <th>Aksiyon</th>
                    <th>Mesaj</th>
                    <th>Seviye</th>
                  </tr>
                </thead>
                <tbody>
                  {items.length === 0 && (
                    <tr>
                      <td colSpan={5} className="muted">
                        Kayıt yok.
                      </td>
                    </tr>
                  )}
                  {items.map((l) => (
                    <tr key={l.id}>
                      <td>{l.created_at ? new Date(l.created_at).toLocaleString("tr-TR") : "—"}</td>
                      <td>{l.user?.name || "—"}</td>
                      <td>{l.action || "—"}</td>
                      <td>{l.message || "—"}</td>
                      <td>{l.level || "info"}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>
    </AppShell>
  );
}
