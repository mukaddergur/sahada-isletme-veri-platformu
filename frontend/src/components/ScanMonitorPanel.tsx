"use client";

type ScanLive = {
  id?: number;
  status: string;
  progress: number;
  found_count: number;
  saved_count: number;
  failed_count: number;
  error_message?: string | null;
  duration_seconds?: number | null;
  provider?: string;
};

type HealthInfo = {
  worker_likely_down?: boolean;
  queue_pending?: number;
  queue_connection?: string;
  message?: string;
};

export default function ScanMonitorPanel({
  active,
  scan,
  health,
  onCancel,
  cancelling,
}: {
  active: boolean;
  scan: ScanLive | null;
  health: HealthInfo | null;
  onCancel?: () => void;
  cancelling?: boolean;
}) {
  const status = scan?.status || (active ? "queued" : "idle");
  const progress = scan?.progress ?? (active ? 8 : 0);
  const isSync = (health?.queue_connection || "sync") === "sync";
  const warnQueue = !!health?.worker_likely_down && !isSync;
  const canCancel =
    !isSync &&
    !!onCancel &&
    (active || status === "pending" || status === "queued" || status === "running");

  if (!active && !scan && !warnQueue) {
    return null;
  }

  const title =
    status === "completed"
      ? "Tarama tamamlandı"
      : status === "cancelled"
        ? "Tarama iptal edildi"
        : status === "failed"
          ? "Tarama hata verdi"
          : "Taranıyor";

  return (
    <section
      className={`scan-monitor ${status === "failed" ? "bad" : ""} ${status === "completed" ? "ok" : ""} ${status === "cancelled" ? "bad" : ""}`}
    >
      <div className="scan-monitor-head">
        <div>
          <div className="setup-eyebrow">Canlı tarama</div>
          <h3>{title}</h3>
        </div>
        <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
          {canCancel && (
            <button type="button" className="btn compact" onClick={onCancel} disabled={cancelling}>
              {cancelling ? "İptal…" : "İptal"}
            </button>
          )}
        </div>
      </div>

      {warnQueue && (
        <div className="scan-warn">
          {health?.message ||
            "Queue worker kapalı görünüyor. PhpStorm’da: cd backend → php artisan queue:work"}
        </div>
      )}

      {scan?.error_message && <div className="scan-warn">{scan.error_message}</div>}

      <div className="scan-kpis">
        <div>
          <em>Bulunan</em>
          <strong>{scan?.found_count ?? 0}</strong>
        </div>
        <div>
          <em>Kaydedilen</em>
          <strong>{scan?.saved_count ?? 0}</strong>
        </div>
        <div>
          <em>Hatalı</em>
          <strong>{scan?.failed_count ?? 0}</strong>
        </div>
        <div>
          <em>İlerleme</em>
          <strong>%{progress}</strong>
        </div>
      </div>

      <div className="progress land-progress" style={{ width: "100%", marginTop: 12 }}>
        <i style={{ width: `${Math.min(100, progress)}%` }} />
      </div>
    </section>
  );
}
