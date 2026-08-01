"use client";

import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import Link from "next/link";
import AppShell from "@/components/AppShell";
import api from "@/lib/api";
import { useAuth } from "@/lib/auth";
import type { Business } from "@/lib/types";

export default function Detay() {
  const { id } = useParams<{ id: string }>();
  const { hydrated, online } = useAuth();
  const [b, setB] = useState<Business | null>(null);

  useEffect(() => {
    if (!hydrated || !online) return;
    api.get(`/businesses/${id}`).then((r) => setB(r.data)).catch(() => setB(null));
  }, [id, hydrated, online]);

  return (
    <AppShell>
      <div className="page" style={{ width: "min(720px, calc(100% - 32px))", margin: "24px auto 64px" }}>
        <Link className="muted" href="/businesses">
          ← İşletmelere dön
        </Link>
        {!b ? (
          <div className="card mt-14">Yükleniyor veya sunucu kapalı…</div>
        ) : (
          <div className="card mt-14 stack">
            <h1 style={{ margin: 0 }}>{b.name}</h1>
            <p className="muted" style={{ margin: 0 }}>
              {b.category || "İşletme"}
              {b.district ? ` · ${b.district}` : ""}
              {b.address ? ` · ${b.address}` : ""}
            </p>
            <div>Telefon: {b.phone || "—"}</div>
            <div>E-posta: {b.email || "—"}</div>
            <div>Site: {b.website || "—"}</div>
            <div>
              Kaynak: {b.source_label || "—"}
              {b.collected_at ? ` · Çekim: ${new Date(b.collected_at).toLocaleString("tr-TR")}` : ""}
            </div>
            <div>
              Puan: {b.rating ?? "—"} ({b.review_count ?? 0} yorum)
            </div>
            <div>Skor: {b.ai_score ?? "—"}</div>
            {b.ai_analysis?.summary && <p>{b.ai_analysis.summary}</p>}
            {b.maps_url && (
              <a className="btn" href={b.maps_url} target="_blank" rel="noreferrer">
                Haritada aç
              </a>
            )}
          </div>
        )}
      </div>
    </AppShell>
  );
}
