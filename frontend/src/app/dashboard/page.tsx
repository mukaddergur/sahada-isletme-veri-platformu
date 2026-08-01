"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import AppShell from "@/components/AppShell";
import api from "@/lib/api";
import { useAuth } from "@/lib/auth";

type Overview = {
  stats: {
    total_businesses: number;
    with_website: number;
    without_website: number;
    with_phone: number;
    missing_phone: number;
    with_email: number;
    missing_email: number;
    avg_rating: number;
    avg_ai_score: number;
    projects: number;
  };
  by_category: { category: string; total: number }[];
  by_district: { district: string; total: number }[];
  social_stats: { instagram: number; linkedin: number; facebook: number };
  recent_scans: {
    id: number;
    status: string;
    saved_count?: number;
    progress?: number;
    project?: { id: number; name: string };
  }[];
  queue: { pending: number; running: number; failed: number; completed: number };
  top_rated: { id: number; name: string; rating?: number; district?: string; ai_score?: number }[];
};

export default function DashboardPage() {
  const { hydrated, online } = useAuth();
  const [data, setData] = useState<Overview | null>(null);
  const [err, setErr] = useState("");

  useEffect(() => {
    if (!hydrated || !online) return;
    api
      .get("/dashboard")
      .then((r) => setData(r.data))
      .catch(() => setErr("Dashboard yüklenemedi."));
  }, [hydrated, online]);

  const s = data?.stats;

  return (
    <AppShell>
      <div className="page">
        <div className="card stack">
          <div className="results-head" style={{ marginBottom: 0 }}>
            <div>
              <div className="setup-eyebrow">Dashboard</div>
              <h1 style={{ margin: 0 }}>Özet panel</h1>
              <p className="muted" style={{ margin: "6px 0 0" }}>
                Toplam firma, iletişim boşlukları, son taramalar ve kuyruk durumu.
              </p>
            </div>
            <Link href="/#anasayfa" className="btn dark compact">
              Yeni tarama
            </Link>
          </div>

          {err && <p className="land-status err">{err}</p>}
          {!data && !err && <p className="muted">Yükleniyor…</p>}

          {s && (
            <>
              <div className="kpi-row">
                <div className="kpi">
                  <b>{s.total_businesses}</b>
                  <span>Toplam firma</span>
                </div>
                <div className="kpi">
                  <b>{s.with_phone}</b>
                  <span>Telefonlu</span>
                </div>
                <div className="kpi">
                  <b>{s.missing_phone}</b>
                  <span>Tel eksik</span>
                </div>
                <div className="kpi">
                  <b>{s.with_website}</b>
                  <span>Website</span>
                </div>
                <div className="kpi">
                  <b>{s.without_website}</b>
                  <span>Web yok</span>
                </div>
                <div className="kpi">
                  <b>{s.with_email}</b>
                  <span>E-posta</span>
                </div>
                <div className="kpi">
                  <b>{s.avg_ai_score || "—"}</b>
                  <span>Ort. AI skor</span>
                </div>
                <div className="kpi">
                  <b>{s.projects}</b>
                  <span>Proje</span>
                </div>
              </div>

              <div className="grid-3">
                <div className="card stack" style={{ margin: 0 }}>
                  <h3 style={{ margin: 0 }}>Sosyal</h3>
                  <p className="muted" style={{ margin: 0 }}>
                    IG {data.social_stats.instagram} · LI {data.social_stats.linkedin} · FB {data.social_stats.facebook}
                  </p>
                </div>
                <div className="card stack" style={{ margin: 0 }}>
                  <h3 style={{ margin: 0 }}>Kuyruk</h3>
                  <p className="muted" style={{ margin: 0 }}>
                    Bekleyen {data.queue.pending} · Çalışan {data.queue.running} · Başarısız {data.queue.failed} ·
                    Tamam {data.queue.completed}
                  </p>
                </div>
                <div className="card stack" style={{ margin: 0 }}>
                  <h3 style={{ margin: 0 }}>Kategori (üst)</h3>
                  <ul style={{ margin: 0, paddingLeft: 18 }}>
                    {(data.by_category || []).slice(0, 5).map((c) => (
                      <li key={c.category}>
                        {c.category}: {c.total}
                      </li>
                    ))}
                  </ul>
                </div>
              </div>

              <div className="grid-2" style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 16 }}>
                <div>
                  <h3>Son taramalar</h3>
                  <div className="table-wrap">
                    <table className="table">
                      <thead>
                        <tr>
                          <th>Proje</th>
                          <th>Durum</th>
                          <th>Kayıt</th>
                        </tr>
                      </thead>
                      <tbody>
                        {(data.recent_scans || []).map((sc) => (
                          <tr key={sc.id}>
                            <td>{sc.project?.name || `#${sc.id}`}</td>
                            <td>{sc.status}</td>
                            <td>{sc.saved_count ?? 0}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </div>
                <div>
                  <h3>En yüksek puan</h3>
                  <div className="table-wrap">
                    <table className="table">
                      <thead>
                        <tr>
                          <th>Firma</th>
                          <th>Puan</th>
                          <th>AI</th>
                        </tr>
                      </thead>
                      <tbody>
                        {(data.top_rated || []).map((b) => (
                          <tr key={b.id}>
                            <td>{b.name}</td>
                            <td>{b.rating ?? "—"}</td>
                            <td>{b.ai_score ?? "—"}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>

              <div>
                <h3>İlçe dağılımı</h3>
                <div className="chips tight">
                  {(data.by_district || []).slice(0, 12).map((d) => (
                    <span key={d.district} className="chip">
                      {d.district}: {d.total}
                    </span>
                  ))}
                </div>
              </div>
            </>
          )}
        </div>
      </div>
    </AppShell>
  );
}
