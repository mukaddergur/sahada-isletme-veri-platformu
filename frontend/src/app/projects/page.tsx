"use client";

import { useEffect, useState } from "react";
import AppShell from "@/components/AppShell";
import api from "@/lib/api";
import { useAuth } from "@/lib/auth";
import type { Project } from "@/lib/types";

export default function Gecmis() {
  const { hydrated, online } = useAuth();
  const [projects, setProjects] = useState<Project[]>([]);

  useEffect(() => {
    if (!hydrated || !online) return;
    api.get("/projects").then((r) => setProjects(r.data.data || [])).catch(() => undefined);
  }, [hydrated, online]);

  return (
    <AppShell>
      <div className="page">
        <div className="card">
          <h1 style={{ marginTop: 0 }}>Geçmiş taramalar</h1>
          <p className="muted">Daha önce başlattığın taramalar.</p>
          {!online && <p className="muted">Canlı geçmiş için backend açık olmalı.</p>}
          <div className="table-wrap mt-14">
            <table className="table">
              <thead>
                <tr>
                  <th>Ad</th>
                  <th>Durum</th>
                  <th>Firma</th>
                </tr>
              </thead>
              <tbody>
                {projects.map((p) => (
                  <tr key={p.id}>
                    <td>
                      <strong>{p.name}</strong>
                      <div className="muted" style={{ fontSize: ".8rem" }}>{p.maps_url}</div>
                    </td>
                    <td><span className="badge info">{p.status}</span></td>
                    <td>{p.businesses_count ?? p.total_businesses}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </AppShell>
  );
}
