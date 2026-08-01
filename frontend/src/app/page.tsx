"use client";

import { FormEvent, useCallback, useEffect, useMemo, useRef, useState } from "react";
import AnalysisStatsBar from "@/components/AnalysisStatsBar";
import AppShell from "@/components/AppShell";
import DataBreakdownPanel, { buildBreakdown, buildMatrix } from "@/components/DataBreakdownPanel";
import LandingIntro from "@/components/LandingIntro";
import LeadResultsSection, { type RivalPeer } from "@/components/LeadResultsSection";
import MarketInsightsPanel, { type MarketInsights } from "@/components/MarketInsightsPanel";
import ScanMonitorPanel from "@/components/ScanMonitorPanel";
import dynamic from "next/dynamic";
import api from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { googleMapsUrl, hasCoords, openStreetMapUrl } from "@/lib/maps";
import { starScore } from "@/lib/score";
import type { Business } from "@/lib/types";

const HeroShowcase = dynamic(() => import("@/components/HeroShowcase"), { ssr: false });
const LandingTurkeyMap = dynamic(() => import("@/components/LandingTurkeyMap"), { ssr: false });
const TurkeyOverviewMap = dynamic(() => import("@/components/TurkeyOverviewMap"), { ssr: false });

const YAKIN_ORNEKLER = ["Moda", "Nişantaşı", "Karaköy", "Kadıköy", "Beşiktaş", "Cihangir", "Üsküdar", "Ortaköy"];

type ScanLike = {
  id: number;
  status: string;
  progress: number;
  saved_count: number;
  found_count: number;
  failed_count?: number;
  error_message?: string | null;
  duration_seconds?: number | null;
  provider?: string;
  project?: { id: number; name: string };
};

type HealthInfo = {
  worker_likely_down?: boolean;
  queue_pending?: number;
  queue_connection?: string;
  message?: string;
};

function kaynakEtiketi(b: Pick<Business, "place_id" | "source_label" | "data_source">) {
  if (b.source_label) return b.source_label;
  if (b.data_source === "openstreetmap" || b.data_source === "osm") return "OpenStreetMap";
  if (b.data_source === "inventory") return "OSM envanter";
  const placeId = b.place_id;
  if (!placeId) return "—";
  if (placeId.startsWith("osm_")) return "OpenStreetMap";
  if (placeId.startsWith("verified_")) return "Doğrulanmış";
  if (placeId.startsWith("demo_")) return "Örnek";
  return "Harita";
}

function isMapsSearchUrl(url: string) {
  const u = url.toLowerCase();
  return u.includes("/maps/search/") || u.includes("?q=") || u.includes("&q=");
}

function isMapsOnlyViewUrl(url: string) {
  const u = url.toLowerCase();
  return u.includes("/maps/@") && !isMapsSearchUrl(url);
}

export default function OtomasyonSayfasi() {
  const { hydrated, online } = useAuth();
  const [mapsUrl, setMapsUrl] = useState("");
  const [limit, setLimit] = useState(20);
  const [calisiyor, setCalisiyor] = useState(false);
  const [progress, setProgress] = useState(0);
  const [statusText, setStatusText] = useState("");
  const [projectId, setProjectId] = useState<number | null>(null);
  const [items, setItems] = useState<Business[]>([]);
  const [hata, setHata] = useState("");
  const [tab, setTab] = useState<"liste" | "harita">("liste");
  const [filtre, setFiltre] = useState<"hepsi" | "dolu" | "telsiz" | "sitesiz" | "skorlu" | "mailsız" | "skor70">("hepsi");
  const [sehir, setSehir] = useState("");
  const [minPuan, setMinPuan] = useState(0);
  const [q, setQ] = useState("");
  const [focusId, setFocusId] = useState<number | undefined>(undefined);
  const [semt, setSemt] = useState("Moda");
  const [kategori, setKategori] = useState("kafe restoran");
  const [yariCap, setYariCap] = useState(2000);
  const [yakinAraniyor, setYakinAraniyor] = useState(false);
  const [merkezEtiket, setMerkezEtiket] = useState("");
  const [mesafeSirali, setMesafeSirali] = useState(false);
  const [breakMode, setBreakMode] = useState<"district" | "category" | "matrix">("district");
  const [seciliSemt, setSeciliSemt] = useState<string | null>(null);
  const [seciliKategori, setSeciliKategori] = useState<string | null>(null);
  const [kaydedildi, setKaydedildi] = useState(false);
  const [ekstraAcik, setEkstraAcik] = useState(false);
  const [listeHazir, setListeHazir] = useState(false);
  const [insights, setInsights] = useState<MarketInsights | null>(null);
  const [insightsLoading, setInsightsLoading] = useState(false);
  const [densityMap, setDensityMap] = useState<Record<string, number>>({});
  const [densityPeers, setDensityPeers] = useState<Record<string, RivalPeer[]>>({});
  const [liveScan, setLiveScan] = useState<ScanLike | null>(null);
  const liveScanIdRef = useRef<number | undefined>(undefined);
  const [health, setHealth] = useState<HealthInfo | null>(null);
  const [cancelling, setCancelling] = useState(false);
  const [scheduleOn, setScheduleOn] = useState(false);
  const [scheduleBusy, setScheduleBusy] = useState(false);
  const [analysisStats, setAnalysisStats] = useState<{
    total_businesses: number;
    cities_count: number;
    review_sum: number;
    accuracy_rate: number;
  } | null>(null);
  const [analysisLoading, setAnalysisLoading] = useState(true);
  const [panelAcik, setPanelAcik] = useState(false);

  function haritadaGoster(b: Business) {
    setFocusId(b.id);
    setTab("harita");
    const url = b.google_maps_url || googleMapsUrl(b);
    if (url) window.open(url, "_blank", "noopener,noreferrer");
  }

  async function yakinAra(e?: FormEvent) {
    e?.preventDefault();
    setHata("");
    if (!online) {
      setHata("Yakın arama için backend açık olmalı.");
      return;
    }
    if (semt.trim().length < 2) {
      setHata("Semt / mahalle adı girin (örn. Moda, Kadıköy).");
      return;
    }
    setYakinAraniyor(true);
    setStatusText(`“${semt}” konumu çözülüyor, en yakın gerçek işletmeler çekiliyor…`);
    setProgress(15);
    setFiltre("hepsi");
    setSehir("");
    setMinPuan(0);
    setTab("liste");
    setSeciliSemt(null);
    setSeciliKategori(null);
    try {
      const { data } = await api.post("/nearby-search", {
        q: semt.trim(),
        category: kategori,
        limit: Math.min(limit, 40),
        radius_m: yariCap,
      });
      const list: Business[] = (data.businesses || []).map((b: Business) => ({
        ...b,
        distance_m: b.distance_m ?? null,
      }));
      setItems(list);
      setProjectId(data.project?.id ?? null);
      setMerkezEtiket(data.center?.label || semt);
      setMesafeSirali(true);
      setProgress(100);
      setListeHazir(true);
      setStatusText(
        `${data.count} yakın işletme · merkez: ${data.center?.label || semt} · kaynak: OpenStreetMap`
      );
      await loadInsights(data.project?.id ?? null);
      if (list[0]?.id) setFocusId(list[0].id);
    } catch (err: unknown) {
      const msg =
        (err as { response?: { data?: { message?: string } } })?.response?.data?.message ||
        "Yakın arama başarısız.";
      setHata(msg);
      setProgress(0);
    } finally {
      setYakinAraniyor(false);
    }
  }

  const listeyiCek = useCallback(async (pid?: number | null) => {
    if (!online) {
      setItems([]);
      setStatusText("Backend kapalı. Canlı gerçek veri için API’yi açın (port 8000).");
      return;
    }
    const collected: Business[] = [];
    let page = 1;
    let lastPage = 1;
    do {
      const params: Record<string, string | number> = {
        per_page: 200,
        page,
        sort: "ai_score",
        direction: "desc",
      };
      if (pid) params.project_id = pid;
      if (q.trim()) params.q = q.trim();
      const { data } = await api.get("/businesses", { params });
      collected.push(...(data.data || []));
      lastPage = data.meta?.last_page || data.last_page || 1;
      page += 1;
    } while (page <= lastPage && page <= 10);

    setItems(collected.filter((b) => !String(b.place_id || "").startsWith("demo_")));
    if (!pid) {
      const real = collected.filter((b) => !String(b.place_id || "").startsWith("demo_"));
      const tel = real.filter((b) => b.phone).length;
      const web = real.filter((b) => b.website).length;
      setStatusText(
        `${real.length} gerçek OSM işletme · ${tel} telefon · ${web} website · sahte kayıt yok.`
      );
    }
  }, [online, q]);

  useEffect(() => {
    if (!hydrated || !online) return;
    setStatusText("");
    void checkHealth();
  }, [hydrated, online]);

  useEffect(() => {
    if (!hydrated) return;
    if (!online) {
      setAnalysisLoading(false);
      return;
    }
    let cancelled = false;
    (async () => {
      setAnalysisLoading(true);
      try {
        const { data } = await api.get("/dashboard", { timeout: 12000 });
        if (cancelled) return;
        const s = data?.stats || {};
        setAnalysisStats({
          total_businesses: Number(s.total_businesses || 0),
          cities_count: Number(s.cities_count || 0),
          review_sum: Number(s.review_sum || 0),
          accuracy_rate: Number(s.accuracy_rate || 0),
        });
      } catch {
        if (!cancelled) setAnalysisStats(null);
      } finally {
        if (!cancelled) setAnalysisLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [hydrated, online, listeHazir, items.length]);

  useEffect(() => {
    if (!hydrated || !online) return;
    const t = setInterval(() => {
      void checkHealth();
    }, 45000);
    return () => clearInterval(t);
  }, [hydrated, online]);

  async function checkHealth() {
    try {
      const { data } = await api.get("/health", { timeout: 5000 });
      setHealth(data);
      if (data.worker_likely_down && data.queue_connection !== "sync" && calisiyor) {
        setHata(data.message || "Queue worker kapalı olabilir: php artisan queue:work");
      }
    } catch {}
  }

  useEffect(() => {
    if (!ekstraAcik || !online || items.length > 0) return;
    listeyiCek(null).catch(() => undefined);
  }, [ekstraAcik, online, items.length, listeyiCek]);

  async function loadInsights(pid?: number | null) {
    if (!online) return;
    setInsightsLoading(true);
    try {
      const { data } = await api.get("/insights/market", {
        params: {
          radius_m: 1000,
          ...(pid ? { project_id: pid } : {}),
        },
      });
      setInsights(data);
      setDensityMap(data.density_map || {});
      setDensityPeers(data.density_peers || {});
    } catch {} finally {
      setInsightsLoading(false);
    }
  }

  useEffect(() => {
    liveScanIdRef.current = liveScan?.id && liveScan.id > 0 ? liveScan.id : undefined;
  }, [liveScan?.id]);

  useEffect(() => {
    if (!calisiyor || !projectId || !online) return;
    let lastSaved = -1;
    const startedAt = Date.now();
    const timer = setInterval(async () => {
      try {
        if (Date.now() - startedAt > 180000) {
          setCalisiyor(false);
          setHata("Tarama 3 dakikadan uzun sürdü / yanıt gelmedi. Sayfayı yenileyip tekrar Başlat deneyin.");
          return;
        }
        const { data } = await api.get("/scans", { timeout: 10000 });
        const scans: ScanLike[] = data.data || [];
        const sid = liveScanIdRef.current;
        const mine =
          (sid ? scans.find((s) => s.id === sid) : undefined) ||
          scans.find((s) => s.project?.id === projectId);
        if (!mine) return;
        setLiveScan(mine);
        setProgress(Math.max(mine.progress || 0, 12));
        const saved = mine.saved_count || 0;
        if (saved !== lastSaved) {
          lastSaved = saved;
          await listeyiCek(projectId);
        }
        if (mine.status === "completed" || mine.status === "failed" || mine.status === "cancelled") {
          setCalisiyor(false);
          setProgress(mine.status === "completed" ? 100 : mine.progress || 0);
          setFiltre("hepsi");
          if (mine.status === "completed") {
            setListeHazir(true);
            setStatusText(`${mine.saved_count} işletme hazır.`);
            await listeyiCek(projectId);
            await loadInsights(projectId);
            document.getElementById("liste")?.scrollIntoView({ behavior: "smooth", block: "start" });
            setPanelAcik(true);
          } else if (mine.status === "cancelled") {
            setListeHazir((mine.saved_count || 0) > 0);
            setStatusText(`İptal edildi. Kayıtlı: ${mine.saved_count || 0}`);
            await listeyiCek(projectId);
          } else {
            setHata(mine.error_message || "Tarama başarısız.");
            setStatusText("");
            await listeyiCek(null);
          }
        }
      } catch {}
    }, 2000);
    return () => clearInterval(timer);
  }, [calisiyor, projectId, online]);

  function projeAdi() {
    if (mapsUrl.includes("search/")) {
      return decodeURIComponent(mapsUrl.split("search/")[1]?.split(/[/?@]/)[0] || "tarama").replace(/\+/g, " ");
    }
    return "Harita taraması";
  }

  async function kaydetLink() {
    setHata("");
    if (!online) {
      setHata("Kaydetmek için backend açık olmalı.");
      return;
    }
    if (!mapsUrl.trim()) {
      setHata("Önce Google Maps arama linkini yapıştırın.");
      return;
    }
    try {
      const { data } = await api.post("/projects", {
        name: projeAdi(),
        maps_url: mapsUrl,
        start_now: false,
        limit,
      });
      setProjectId(data.id ?? data.project?.id);
      setKaydedildi(true);
      setListeHazir(false);
      setScheduleOn(!!(data.settings?.schedule?.enabled ?? data.project?.settings?.schedule?.enabled));
    setStatusText("Link kaydedildi. Başlat ile taramayı başlatabilirsiniz.");
      setProgress(0);
    } catch {
      setHata("Link kaydedilemedi.");
    }
  }

  async function baslat(e?: FormEvent) {
    e?.preventDefault();
    setHata("");
    if (!online) {
      setHata("Gerçek veri için backend açık olmalı.");
      return;
    }
    if (!mapsUrl.trim()) {
      setHata("Google Maps arama linkini yapıştırın (adım 2–5).");
      return;
    }
    if (isMapsOnlyViewUrl(mapsUrl)) {
      setHata(
        "Bu link sadece harita konumu. Maps’te “kafe kadıköy” gibi ara; sonuç sayfasının URL’sini yapıştır (/maps/search/...)."
      );
      return;
    }
    await checkHealth();
    setCalisiyor(true);
    setPanelAcik(true);
    setListeHazir(false);
    setItems([]);
    setMesafeSirali(false);
    setProgress(10);
    setLiveScan({
      id: 0,
      status: "queued",
      progress: 10,
      found_count: 0,
      saved_count: 0,
      failed_count: 0,
    });
    setStatusText("");
    setTab("liste");
    setFiltre("hepsi");
    try {
      let pid = projectId;
      let scan: ScanLike | null = null;
      if (kaydedildi && projectId) {
        const { data } = await api.post(`/projects/${projectId}/start`, null, { timeout: 180000 });
        scan = data.scan || null;
        if (data.scan) setLiveScan(data.scan);
        if (data.project) setScheduleOn(!!data.project?.settings?.schedule?.enabled);
        pid = projectId;
      } else {
        const { data } = await api.post(
          "/projects",
          {
            name: projeAdi(),
            maps_url: mapsUrl,
            start_now: true,
            limit,
          },
          { timeout: 180000 }
        );
        pid = data.project?.id ?? null;
        setProjectId(pid);
        scan = data.scan || null;
        if (data.scan) setLiveScan(data.scan);
        setScheduleOn(!!data.project?.settings?.schedule?.enabled);
        setKaydedildi(true);
      }

      if (scan && (scan.status === "completed" || (scan.saved_count || 0) > 0)) {
        setCalisiyor(false);
        setProgress(100);
        setListeHazir(true);
        setStatusText(`Listeniz hazır! ${scan.saved_count} gerçek işletme (OSM).`);
        if (pid) {
          await listeyiCek(pid);
          await loadInsights(pid);
        }
        document.getElementById("liste")?.scrollIntoView({ behavior: "smooth", block: "start" });
        setPanelAcik(true);
      } else if (scan?.status === "failed") {
        setCalisiyor(false);
        setHata(scan.error_message || "Tarama başarısız.");
      } else {
        setProgress(18);
      }
      setTimeout(() => void checkHealth(), 2000);
    } catch (err: unknown) {
      const ax = err as { code?: string; response?: { status?: number; data?: { message?: string } }; message?: string };
      if (ax.code === "ECONNABORTED") {
        setHata("İstek zaman aşımına uğradı. Tarama uzun sürdü — tekrar Başlat deneyin.");
      } else if (ax.response?.status === 401) {
        setHata("Oturum süresi doldu. Sayfayı yenileyin (Ctrl+F5).");
      } else if (ax.response?.data?.message) {
        setHata(String(ax.response.data.message));
      } else if (!ax.response) {
        setHata(
          "Tarama yanıt vermedi (OSM isteği uzun sürdü veya PHP zaman aşımı). Backend açıkken tekrar Başlat deneyin; yine olursa backend terminalini yenileyin."
        );
      } else {
        setHata("Başlatılamadı. Backend yanıt verdi ama hata oluştu — tekrar deneyin.");
      }
      setCalisiyor(false);
      setProgress(0);
      setListeHazir(false);
    }
  }

  async function iptalEt() {
    if (!projectId || !online) return;
    setCancelling(true);
    setHata("");
    try {
      const { data } = await api.post(`/projects/${projectId}/cancel`);
      if (data.scan) setLiveScan(data.scan);
      setCalisiyor(false);
      setStatusText("Tarama iptal edildi.");
      await listeyiCek(projectId);
    } catch {
      setHata("İptal edilemedi (aktif tarama yok olabilir).");
    } finally {
      setCancelling(false);
    }
  }

  async function zamanlamaDegistir(enabled: boolean) {
    if (!online) {
      setHata("Zamanlama için backend açık olmalı.");
      return;
    }
    if (!projectId) {
      setHata("Önce Başlat ile proje oluşturun.");
      return;
    }
    setScheduleBusy(true);
    setHata("");
    try {
      const { data } = await api.post(`/projects/${projectId}/schedule`, {
        enabled,
        frequency: "daily",
        hour: 3,
      });
      setScheduleOn(!!data.schedule?.enabled);
      setStatusText(
        enabled
          ? "Günlük zamanlama açıldı (03:00). Backend’de `php artisan schedule:work` çalışmalı."
          : "Zamanlama kapatıldı."
      );
    } catch {
      setHata("Zamanlama kaydedilemedi.");
    } finally {
      setScheduleBusy(false);
    }
  }

  async function excelIndir() {
    if (!online || !projectId) {
      setHata("Excel için önce Başlat ile bir proje oluşturun.");
      return;
    }
    try {
      const res = await api.post(
        "/exports/excel",
        {
          project_id: projectId,
          columns: [
            "name",
            "category",
            "district",
            "phone",
            "email",
            "website",
            "address",
            "rating",
            "review_count",
            "ai_score",
            "instagram",
            "latitude",
            "longitude",
            "maps_url",
            "source_label",
            "collected_at",
          ],
        },
        { responseType: "blob" }
      );
      const a = document.createElement("a");
      a.href = URL.createObjectURL(
        new Blob([res.data], {
          type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        })
      );
      a.download = `sahada_${projectId}.xlsx`;
      a.click();
    } catch {
      setHata("Excel oluşturulamadı.");
    }
  }

  const gorunen = useMemo(() => {
    const qn = q.trim().toLowerCase();
    let list = items.filter((b) => {
      if (qn) {
        const hay = `${b.name} ${b.city || ""} ${b.district || ""} ${b.category || ""} ${b.phone || ""}`.toLowerCase();
        if (!hay.includes(qn)) return false;
      }
      if (sehir) {
        const c = `${b.city || ""} ${b.district || ""} ${b.address || ""}`.toLocaleLowerCase("tr-TR");
        const needle = sehir.toLocaleLowerCase("tr-TR");
        const aliases: Record<string, string[]> = {
          gaziantep: ["gaziantep", "antep"],
          "şanlıurfa": ["şanlıurfa", "sanliurfa", "urfa"],
          "kahramanmaraş": ["kahramanmaraş", "maras", "maraş"],
          "afyonkarahisar": ["afyonkarahisar", "afyon"],
          "içel": ["mersin", "içel"],
          mersin: ["mersin", "içel"],
        };
        const alt = aliases[needle] || [needle];
        if (!alt.some((a) => c.includes(a))) return false;
      }
      if (minPuan > 0 && starScore(b) < minPuan) return false;
      if (seciliSemt) {
        const d = b.district?.trim() || "Belirtilmemiş";
        if (d !== seciliSemt) return false;
      }
      if (seciliKategori) {
        const cat = b.category?.trim() || "Belirtilmemiş";
        if (cat !== seciliKategori) return false;
      }
      if (filtre === "dolu") return !!(b.phone || b.website);
      if (filtre === "telsiz") return !b.phone;
      if (filtre === "sitesiz") return !b.website;
      if (filtre === "mailsız") return !b.email;
      if (filtre === "skorlu" || filtre === "skor70") return (b.ai_score || 0) >= 70;
      return true;
    });
    if (mesafeSirali) {
      list = [...list].sort((a, b) => (a.distance_m ?? 9e9) - (b.distance_m ?? 9e9));
    }
    return list;
  }, [items, filtre, q, mesafeSirali, seciliSemt, seciliKategori, sehir, minPuan]);

  const ozet = useMemo(() => {
    const tel = items.filter((b) => !!b.phone).length;
    const web = items.filter((b) => !!b.website).length;
    const mail = items.filter((b) => !!b.email).length;
    const adres = items.filter((b) => !!b.address).length;
    const konum = items.filter((b) => hasCoords(b)).length;
    const skor = items.length
      ? Math.round(items.reduce((a, b) => a + (b.ai_score || 0), 0) / items.length)
      : 0;
    return { toplam: items.length, tel, web, mail, adres, konum, telsiz: items.length - tel, sitesiz: items.length - web, skor };
  }, [items]);

  const liveAnalysisStats = useMemo(() => {
    if (analysisStats) return analysisStats;
    if (!items.length) {
      return {
        total_businesses: 0,
        cities_count: 0,
        review_sum: 0,
        accuracy_rate: 0,
      };
    }
    const cities = new Set(
      items.map((b) => (b.city || "").trim()).filter(Boolean)
    ).size;
    const reviewSum = items.reduce((a, b) => a + (Number(b.review_count) || 0), 0);
    const slots = items.length * 4;
    const filled =
      items.filter((b) => !!b.phone).length +
      items.filter((b) => !!b.website).length +
      items.filter((b) => !!b.address).length +
      items.filter((b) => hasCoords(b)).length;
    return {
      total_businesses: items.length,
      cities_count: cities,
      review_sum: reviewSum,
      accuracy_rate: slots ? Math.round((100 * filled) / slots) : 0,
    };
  }, [analysisStats, items]);

  const districts = useMemo(() => buildBreakdown(items, "district"), [items]);
  const categories = useMemo(() => buildBreakdown(items, "category"), [items]);
  const matrix = useMemo(() => buildMatrix(items), [items]);

  const mapPoints = useMemo(
    () =>
      gorunen
        .filter((b) => hasCoords(b))
        .map((b) => ({
          id: b.id,
          name: b.name,
          latitude: Number(b.latitude),
          longitude: Number(b.longitude),
          rating: b.rating,
          category: b.category,
          city: b.city,
          district: b.district,
          address: b.address,
          maps_url: b.maps_url,
          place_id: b.place_id,
        })),
    [gorunen]
  );

  const turkeyPoints = useMemo(
    () =>
      items
        .filter((b) => hasCoords(b))
        .map((b) => ({
          id: b.id,
          name: b.name,
          latitude: Number(b.latitude),
          longitude: Number(b.longitude),
          city: b.city,
          district: b.district,
          category: b.category,
          rating: b.rating,
        })),
    [items]
  );

  function kirilimSec(mode: "district" | "category", key: string | null) {
    if (mode === "district") {
      setSeciliSemt(key);
      if (key) setBreakMode("district");
    } else {
      setSeciliKategori(key);
      if (key) setBreakMode("category");
    }
    setFiltre("hepsi");
    setTab("liste");
  }

  const sonucAktif = calisiyor || items.length > 0;

  useEffect(() => {
    if (sonucAktif) {
      setPanelAcik(true);
      window.scrollTo({ top: 0, behavior: "smooth" });
    }
  }, [sonucAktif]);

  function paneliKapat() {
    setPanelAcik(false);
  }

  function paneliAc() {
    setPanelAcik(true);
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  const resultsProps = {
    listeHazir,
    gorunen,
    ozet,
    merkezEtiket,
    tab,
    setTab,
    q,
    setQ,
    filtre,
    setFiltre,
    sehir,
    setSehir,
    minPuan,
    setMinPuan,
    densityMap,
    densityPeers,
    focusId,
    mapPoints,
    kaynakEtiketi,
    haritadaGoster,
    excelIndir,
    projectId,
  };

  const scanPanel = (
    <ScanMonitorPanel
      active={calisiyor}
      scan={liveScan}
      health={health}
      onCancel={iptalEt}
      cancelling={cancelling}
    />
  );

  return (
    <AppShell>
      <div className={`land-page ${sonucAktif ? "has-results" : ""}`}>
        {!panelAcik ? (
          <>
        <section className="land-hero" id="anasayfa">
          <div className="land-hero-split saas">
              <div className="land-hero-copy">
                <div className="land-hero-top">
                  <div className="land-selected">
                    <span className="land-dot" />
                    Harita araması seçildi
                  </div>
                  <a
                    className="land-maps-btn"
                    href="https://www.google.com/maps"
                    target="_blank"
                    rel="noopener noreferrer"
                  >
                    <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden>
                      <path fill="#EA4335" d="M12 2C8.1 2 5 5.1 5 9c0 5.2 7 13 7 13s7-7.8 7-13c0-3.9-3.1-7-7-7z" />
                      <circle fill="#fff" cx="12" cy="9" r="2.4" />
                    </svg>
                    Google Maps
                  </a>
                </div>
                <h1 className="land-headline">
                  <span className="land-headline-line">Haritadan işletme.</span>
                  <span className="land-headline-line accent">Tek tıkla.</span>
                </h1>
                <p className="land-sub land-sub-wide">
                  Google Maps arama bağlantınızı ekleyin; Sahada telefon, web sitesi, sosyal medya, konum ve yapay zekâ
                  destekli analizleri tek işlemde hazırlar.
                </p>

                <ul className="land-feature-row land-feature-stack">
                  <li>
                    <i aria-hidden className="fi-bolt">
                      <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
                        <path d="M13 2 4 14h7l-1 8 10-14h-7l1-6z" />
                      </svg>
                    </i>
                    <div>
                      <strong>Hızlı Tarama</strong>
                      <em>Dakikalar içinde yüzlerce işletme</em>
                    </div>
                  </li>
                  <li>
                    <i aria-hidden className="fi-bars">
                      <svg viewBox="0 0 24 24" width="18" height="18" fill="none">
                        <rect x="4" y="12" width="4" height="8" rx="1.2" fill="#60A5FA" />
                        <rect x="10" y="7" width="4" height="13" rx="1.2" fill="#34D399" />
                        <rect x="16" y="4" width="4" height="16" rx="1.2" fill="#F472B6" />
                      </svg>
                    </i>
                    <div>
                      <strong>Zengin Veri</strong>
                      <em>Telefon, website, sosyal medya</em>
                    </div>
                  </li>
                  <li>
                    <i aria-hidden className="fi-spark">
                      <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#ea580c" strokeWidth="2" strokeLinecap="round">
                        <path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1" />
                        <circle cx="12" cy="12" r="2.2" fill="#ea580c" stroke="none" />
                      </svg>
                    </i>
                    <div>
                      <strong>AI Analizi</strong>
                      <em>SEO, dijital olgunluk ve fırsat skorları</em>
                    </div>
                  </li>
                </ul>

                <form className="land-cta land-cta-strong land-cta-inline" id="arama" onSubmit={baslat}>
                  <span className="land-cta-pin" aria-hidden>
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="2">
                      <path d="M12 21s7-5.2 7-11a7 7 0 1 0-14 0c0 5.8 7 11 7 11z" />
                      <circle cx="12" cy="10" r="2.5" />
                    </svg>
                  </span>
                  <input
                    value={mapsUrl}
                    onChange={(e) => {
                      setMapsUrl(e.target.value);
                      setKaydedildi(false);
                      setListeHazir(false);
                    }}
                    placeholder="Google Maps arama bağlantısını yapıştırın"
                    required
                    aria-label="Google Maps arama bağlantısı"
                  />
                  <button type="submit" disabled={!online || calisiyor}>
                    {calisiyor ? "Başlatılıyor…" : "Başlat"}
                  </button>
                </form>

                <div className="land-hero-actions">
                  <a className="btn ghost land-review-btn" href="#incele">
                    İncele
                  </a>
                  {sonucAktif && (
                    <button type="button" className="btn land-results-open" onClick={paneliAc}>
                      Sonuçlara git{items.length ? ` (${items.length})` : ""}
                    </button>
                  )}
                </div>

                <AnalysisStatsBar stats={liveAnalysisStats} loading={analysisLoading && !analysisStats} />

                <div className="progress land-progress"><i style={{ width: `${progress}%` }} /></div>
                {listeHazir && statusText && <p className="land-status ready">{statusText}</p>}
                {hata && <p className="land-status err">{hata}</p>}
              </div>

              <aside className="land-hero-visual">
                <HeroShowcase />
              </aside>
            </div>

          <LandingTurkeyMap />
          <LandingIntro />
        </section>
          </>
        ) : (
          <section className="results-page" id="liste" aria-label="Tarama sonuçları">
            <header className="results-page-head">
              <div>
                <p className="setup-eyebrow">Tarama</p>
                <h1>Sonuçlar</h1>
              </div>
              <button type="button" className="btn ghost results-page-back" onClick={paneliKapat}>
                ← Ana sayfa
              </button>
            </header>

            <form className="land-cta land-cta-strong results-page-search" onSubmit={baslat}>
              <input
                value={mapsUrl}
                onChange={(e) => {
                  setMapsUrl(e.target.value);
                  setKaydedildi(false);
                  setListeHazir(false);
                }}
                placeholder="Google Maps arama bağlantısını yapıştırın"
                required
                aria-label="Google Maps arama bağlantısı"
              />
              <button type="submit" disabled={!online || calisiyor}>
                {calisiyor ? "Başlatılıyor…" : "Başlat"}
              </button>
            </form>
            <div className="progress land-progress"><i style={{ width: `${progress}%` }} /></div>
            {listeHazir && statusText && <p className="land-status ready">{statusText}</p>}
            {hata && <p className="land-status err">{hata}</p>}
            {scanPanel}
            <LeadResultsSection {...resultsProps} />
            <MarketInsightsPanel
              data={insights}
              loading={insightsLoading}
              onRefresh={() => loadInsights(projectId)}
            />
          </section>
        )}

        <div className="land-main">
          {ekstraAcik && (
            <>
              <DataBreakdownPanel
                totals={ozet}
                districts={districts}
                categories={categories}
                matrix={matrix}
                activeMode={breakMode}
                onModeChange={setBreakMode}
                selectedKey={breakMode === "category" ? seciliKategori : seciliSemt}
                onSelect={kirilimSec}
              />

              {(seciliSemt || seciliKategori) && (
                <div className="chips" style={{ marginBottom: 12 }}>
                  {seciliSemt && (
                    <button type="button" className="chip on" onClick={() => setSeciliSemt(null)}>
                      Semt: {seciliSemt} ×
                    </button>
                  )}
                  {seciliKategori && (
                    <button type="button" className="chip on" onClick={() => setSeciliKategori(null)}>
                      Kategori: {seciliKategori} ×
                    </button>
                  )}
                </div>
              )}

              <section className="ist-section">
                <div className="section-head">
                  <div>
                    <div className="section-num">Ek · Yakın ara</div>
                    <h2>Semt yaz, en yakını bul</h2>
                    <p>Maps linki olmadan semt adı ile canlı OSM araması.</p>
                  </div>
                </div>
                <form onSubmit={yakinAra}>
                  <div className="nearby-grid">
                    <div>
                      <label className="label">Semt / mahalle</label>
                      <input className="field" value={semt} onChange={(e) => setSemt(e.target.value)} required />
                    </div>
                    <div>
                      <label className="label">Kategori</label>
                      <select className="select" value={kategori} onChange={(e) => setKategori(e.target.value)}>
                        <option value="kafe restoran">Kafe ve restoran</option>
                        <option value="kafe">Kafe</option>
                        <option value="restoran">Restoran</option>
                        <option value="düğün salonu">Eğlence</option>
                        <option value="dershane">Eğitim</option>
                        <option value="esnaf">Genel esnaf</option>
                      </select>
                    </div>
                    <div>
                      <label className="label">Yarıçap</label>
                      <select className="select" value={yariCap} onChange={(e) => setYariCap(Number(e.target.value))}>
                        <option value={1000}>1 km</option>
                        <option value={2000}>2 km</option>
                        <option value={3000}>3 km</option>
                      </select>
                    </div>
                  </div>
                  <div className="chips tight" style={{ marginBottom: 8 }}>
                    {YAKIN_ORNEKLER.map((s) => (
                      <button key={s} type="button" className={`chip ${semt === s ? "on" : ""}`} onClick={() => setSemt(s)}>
                        {s}
                      </button>
                    ))}
                  </div>
                  <div className="actions">
                    <button className="btn" disabled={yakinAraniyor || !online || calisiyor}>
                      {yakinAraniyor ? "Aranıyor…" : "En yakını bul"}
                    </button>
                  </div>
                </form>
              </section>
            </>
          )}

          {(sonucAktif || turkeyPoints.length > 0) && (
            <TurkeyOverviewMap
              points={turkeyPoints}
              onSelect={(id) => {
                const b = items.find((x) => x.id === id);
                if (b) haritadaGoster(b);
              }}
            />
          )}
        </div>
    </div>
    </AppShell>
  );
}
