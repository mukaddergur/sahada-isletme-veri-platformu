"use client";

import type { Business } from "@/lib/types";
import type { CSSProperties } from "react";

export type BreakdownRow = {
  key: string;
  total: number;
  with_phone: number;
  with_website: number;
  with_email: number;
  with_address: number;
  with_coords: number;
  avg_ai_score: number | null;
};

export type MatrixCell = {
  district: string;
  category: string;
  total: number;
  with_phone: number;
  with_website: number;
};

const PLACE_MOOD: Record<string, { accent: string; soft: string; line: string; tagline: string }> = {
  Kadıköy: { accent: "#b45309", soft: "#fff7ed", line: "#fdba74", tagline: "Moda · çarşı · sahil" },
  Fatih: { accent: "#92400e", soft: "#fef3c7", line: "#fcd34d", tagline: "Tarihi yarımada" },
  Beşiktaş: { accent: "#0369a1", soft: "#e0f2fe", line: "#7dd3fc", tagline: "Boğaz’ın batı yakası" },
  Beyoğlu: { accent: "#9f1239", soft: "#fff1f2", line: "#fb7185", tagline: "Karaköy · İstiklal" },
  Şişli: { accent: "#4338ca", soft: "#eef2ff", line: "#a5b4fc", tagline: "Nişantaşı · merkez" },
  Üsküdar: { accent: "#0f766e", soft: "#f0fdfa", line: "#5eead4", tagline: "Kız kulesi yakası" },
  Sarıyer: { accent: "#166534", soft: "#f0fdf4", line: "#86efac", tagline: "Boğaz’ın kuzeyi" },
  Bakırköy: { accent: "#c2410c", soft: "#fff7ed", line: "#fdba74", tagline: "Sahil · cadde" },
  Maltepe: { accent: "#0e7490", soft: "#ecfeff", line: "#67e8f9", tagline: "Anadolu sahili" },
  Ataşehir: { accent: "#1e3a5f", soft: "#f1f5f9", line: "#94a3b8", tagline: "İş · plaza hattı" },
  Kafe: { accent: "#0f766e", soft: "#f0fdfa", line: "#5eead4", tagline: "Kahve & buluşma" },
  Restoran: { accent: "#b45309", soft: "#fff7ed", line: "#fdba74", tagline: "Sofra & lezzet" },
  "Fast Food": { accent: "#dc2626", soft: "#fef2f2", line: "#fca5a5", tagline: "Hızlı servis" },
  Pastane: { accent: "#a16207", soft: "#fefce8", line: "#fde047", tagline: "Tatlı & fırın" },
};

function moodOf(key: string) {
  return (
    PLACE_MOOD[key] || {
      accent: "#0a2744",
      soft: "#f1f5f9",
      line: "#94a3b8",
      tagline: "İstanbul esnafı",
    }
  );
}

function pct(part: number, total: number) {
  if (!total) return 0;
  return Math.round((part / total) * 100);
}

function qualityLabel(score: number) {
  if (score >= 75) return { text: "Güçlü doluluk", tone: "good" as const };
  if (score >= 50) return { text: "Orta doluluk", tone: "mid" as const };
  return { text: "Geliştirilmeli", tone: "low" as const };
}

export function buildBreakdown(items: Business[], mode: "district" | "category"): BreakdownRow[] {
  const map = new Map<string, BreakdownRow>();
  for (const b of items) {
    const key =
      mode === "district"
        ? b.district?.trim() || "Belirtilmemiş"
        : b.category?.trim() || "Belirtilmemiş";
    const row = map.get(key) || {
      key,
      total: 0,
      with_phone: 0,
      with_website: 0,
      with_email: 0,
      with_address: 0,
      with_coords: 0,
      avg_ai_score: null,
    };
    row.total += 1;
    if (b.phone) row.with_phone += 1;
    if (b.website) row.with_website += 1;
    if (b.email) row.with_email += 1;
    if (b.address) row.with_address += 1;
    if (b.latitude != null && b.longitude != null) row.with_coords += 1;
    map.set(key, row);
  }

  return [...map.values()]
    .map((r) => {
      const scored = items.filter((b) => {
        const k =
          mode === "district"
            ? b.district?.trim() || "Belirtilmemiş"
            : b.category?.trim() || "Belirtilmemiş";
        return k === r.key && b.ai_score != null;
      });
      const avg = scored.length
        ? Math.round(scored.reduce((a, b) => a + (b.ai_score || 0), 0) / scored.length)
        : null;
      return { ...r, avg_ai_score: avg };
    })
    .sort((a, b) => b.total - a.total);
}

export function buildMatrix(items: Business[]): MatrixCell[] {
  const map = new Map<string, MatrixCell>();
  for (const b of items) {
    const district = b.district?.trim() || "Belirtilmemiş";
    const category = b.category?.trim() || "Belirtilmemiş";
    const id = `${district}|||${category}`;
    const row = map.get(id) || { district, category, total: 0, with_phone: 0, with_website: 0 };
    row.total += 1;
    if (b.phone) row.with_phone += 1;
    if (b.website) row.with_website += 1;
    map.set(id, row);
  }
  return [...map.values()].sort(
    (a, b) => a.district.localeCompare(b.district, "tr") || b.total - a.total
  );
}

type Props = {
  totals: {
    toplam: number;
    tel: number;
    web: number;
    mail: number;
    adres?: number;
    konum?: number;
    skor?: number;
  };
  districts: BreakdownRow[];
  categories: BreakdownRow[];
  matrix: MatrixCell[];
  activeMode: "district" | "category" | "matrix";
  onModeChange: (m: "district" | "category" | "matrix") => void;
  selectedKey: string | null;
  onSelect: (mode: "district" | "category", key: string | null) => void;
};

export default function DataBreakdownPanel({
  totals,
  districts,
  categories,
  matrix,
  activeMode,
  onModeChange,
  selectedKey,
  onSelect,
}: Props) {
  const rows = activeMode === "category" ? categories : districts;

  return (
    <section className="ist-section" id="panel">
      <div className="section-head">
        <div>
          <div className="section-num">02 · Semt envanteri</div>
          <h2>İstanbul’u semt semt oku</h2>
          <p>
            Her kart bir semtin gerçek OpenStreetMap kayıtlarını özetler. Tıklayınca alttaki liste
            o semte filtrelenir; harita linkleriyle konumu açabilirsiniz.
          </p>
        </div>
        <div className="tabs">
          <button type="button" className={`tab ${activeMode === "district" ? "on" : ""}`} onClick={() => onModeChange("district")}>
            Semtler
          </button>
          <button type="button" className={`tab ${activeMode === "category" ? "on" : ""}`} onClick={() => onModeChange("category")}>
            Kategoriler
          </button>
          <button type="button" className={`tab ${activeMode === "matrix" ? "on" : ""}`} onClick={() => onModeChange("matrix")}>
            Çapraz
          </button>
        </div>
      </div>

      <div className="mini-strip" aria-label="Özet">
        <span><b>{totals.toplam}</b> kayıt</span>
        <span className="dot" />
        <span><b>{districts.length}</b> semt</span>
        <span className="dot" />
        <span><b>{categories.length}</b> kategori</span>
        <span className="dot" />
        <span>tel <b>%{pct(totals.tel, totals.toplam)}</b></span>
        <span className="dot" />
        <span>web <b>%{pct(totals.web, totals.toplam)}</b></span>
      </div>

      {activeMode !== "matrix" ? (
        <div className="place-grid">
          {rows.length === 0 && <div className="empty">Kırılım için kayıt yok.</div>}
          {rows.map((r, idx) => {
            const active = selectedKey === r.key;
            const mood = moodOf(r.key);
            const phonePct = pct(r.with_phone, r.total);
            const webPct = pct(r.with_website, r.total);
            const addrPct = pct(r.with_address, r.total);
            const pinPct = pct(r.with_coords, r.total);
            const quality = Math.round((phonePct + webPct + addrPct + pinPct) / 4);
            const q = qualityLabel(quality);
            return (
              <button
                type="button"
                key={r.key}
                className={`place-card ${active ? "on" : ""}`}
                style={
                  {
                    "--place-accent": mood.accent,
                    "--place-soft": mood.soft,
                    "--place-line": mood.line,
                  } as CSSProperties
                }
                onClick={() => onSelect(activeMode, active ? null : r.key)}
              >
                <div className="place-sky">
                  <div className="place-skyline" aria-hidden />
                  <div className="place-meta">
                    <span className="place-ord">#{idx + 1}</span>
                    <span className={`q-pill ${q.tone}`}>{q.text}</span>
                  </div>
                  <h3>{r.key}</h3>
                  <p className="place-tag">{mood.tagline}</p>
                  <div className="place-count">
                    <strong>{r.total}</strong>
                    <span>işletme</span>
                  </div>
                </div>

                <div className="place-stats">
                  <div>
                    <em>Telefon</em>
                    <strong>{r.with_phone}</strong>
                    <span>%{phonePct}</span>
                    <i style={{ width: `${phonePct}%` }} />
                  </div>
                  <div>
                    <em>Website</em>
                    <strong>{r.with_website}</strong>
                    <span>%{webPct}</span>
                    <i style={{ width: `${webPct}%` }} />
                  </div>
                  <div>
                    <em>Adres</em>
                    <strong>{r.with_address}</strong>
                    <span>%{addrPct}</span>
                    <i style={{ width: `${addrPct}%` }} />
                  </div>
                  <div>
                    <em>Harita</em>
                    <strong>{r.with_coords}</strong>
                    <span>%{pinPct}</span>
                    <i style={{ width: `${pinPct}%` }} />
                  </div>
                </div>

                <div className="place-foot">
                  <span>E-posta {r.with_email}</span>
                  <span>Skor {r.avg_ai_score ?? "—"}</span>
                  <span className="cta">{active ? "Seçili · kaldır" : "Listele →"}</span>
                </div>
              </button>
            );
          })}
        </div>
      ) : (
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th>Semt</th>
                <th>Kategori</th>
                <th>Adet</th>
                <th>Telefon</th>
                <th>Website</th>
              </tr>
            </thead>
            <tbody>
              {matrix.map((m) => (
                <tr key={`${m.district}-${m.category}`}>
                  <td>
                    <button type="button" className="link-btn" onClick={() => { onModeChange("district"); onSelect("district", m.district); }}>
                      {m.district}
                    </button>
                  </td>
                  <td>
                    <button type="button" className="link-btn" onClick={() => { onModeChange("category"); onSelect("category", m.category); }}>
                      {m.category}
                    </button>
                  </td>
                  <td><strong>{m.total}</strong></td>
                  <td>{m.with_phone} <span className="firm-meta">%{pct(m.with_phone, m.total)}</span></td>
                  <td>{m.with_website} <span className="firm-meta">%{pct(m.with_website, m.total)}</span></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}
