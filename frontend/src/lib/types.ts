export type User = {
  id: number;
  name: string;
  email: string;
  role: "admin" | "operator" | "user" | "guest";
};

export type Project = {
  id: number;
  name: string;
  description?: string;
  maps_url: string;
  search_query?: string;
  status: string;
  total_businesses: number;
  processed_count: number;
  businesses_count?: number;
  created_at: string;
  settings?: {
    schedule?: {
      enabled?: boolean;
      frequency?: "daily" | "weekly";
      hour?: number;
      last_run_at?: string | null;
    };
  };
};

export type Business = {
  id: number;
  project_id: number;
  name: string;
  place_id?: string;
  data_source?: string | null;
  source_label?: string;
  collected_at?: string | null;
  category?: string;
  address?: string;
  city?: string;
  district?: string;
  neighborhood?: string;
  phone?: string;
  email?: string;
  website?: string;
  maps_url?: string;
  latitude?: number;
  longitude?: number;
  rating?: number;
  review_count?: number;
  photo_count?: number;
  ai_score?: number;
  distance_m?: number | null;
  google_maps_url?: string | null;
  osm_url?: string | null;
  social?: {
    instagram?: string;
    facebook?: string;
    linkedin?: string;
    tiktok?: string;
    youtube?: string;
    twitter?: string;
  };
  ai_analysis?: {
    overall_score: number;
    corporate_score: number;
    seo_score: number;
    digital_marketing_score: number;
    web_quality_score: number;
    potential_score: number;
    digital_maturity?: string;
    estimated_employees?: string;
    summary?: string;
    strengths?: string[];
    weaknesses?: string[];
    opportunities?: string[];
    marketing_suggestions?: string[];
    positive_review_ratio?: number;
  };
  website_analysis?: {
    has_https?: boolean;
    technologies?: string[];
    has_google_analytics?: boolean;
    has_meta_pixel?: boolean;
    seo_score?: number;
    quality_score?: number;
    cms?: string;
  };
};

export type BreakdownStat = {
  category?: string;
  district?: string;
  total: number;
  with_phone?: number;
  with_website?: number;
  with_email?: number;
  with_address?: number;
  with_coords?: number;
  avg_ai_score?: number | null;
};

export type DashboardData = {
  stats: {
    total_businesses: number;
    with_website: number;
    without_website: number;
    with_phone: number;
    missing_phone: number;
    with_email: number;
    missing_email: number;
    with_address?: number;
    with_coords?: number;
    avg_rating: number;
    avg_ai_score: number;
    projects: number;
  };
  by_category: BreakdownStat[];
  by_district: BreakdownStat[];
  district_category?: {
    district: string;
    category: string;
    total: number;
    with_phone: number;
    with_website: number;
  }[];
  market_insights?: {
    radius_m: number;
    summary: {
      businesses_with_coords: number;
      avg_competitors_1km: number;
      low_competition_count: number;
      district_gap_count: number;
    };
    densest: unknown[];
    opportunities: unknown[];
    district_gaps: unknown[];
    contact_gaps: unknown[];
    messages: string[];
    density_map?: Record<string, number>;
    density_peers?: Record<string, unknown[]>;
  };
  top_rated: Business[];
  social_stats: { instagram: number; linkedin: number; facebook: number };
  recent_scans: {
    id: number;
    status: string;
    found_count: number;
    saved_count: number;
    progress: number;
    duration_seconds?: number;
    project?: { id: number; name: string; status: string };
  }[];
  queue: { pending: number; running: number; failed: number; completed: number };
  map_points: {
    id: number;
    name: string;
    latitude: number;
    longitude: number;
    rating?: number;
    category?: string;
    district?: string;
  }[];
};


export const ONIZLEME_PANEL: DashboardData = {
  stats: {
    total_businesses: 128,
    with_website: 74,
    without_website: 54,
    with_phone: 96,
    missing_phone: 32,
    with_email: 41,
    missing_email: 87,
    avg_rating: 4.3,
    avg_ai_score: 68,
    projects: 3,
  },
  by_category: [
    { category: "Kafe", total: 52 },
    { category: "Restoran", total: 31 },
    { category: "Pastane", total: 18 },
    { category: "Kahve", total: 27 },
  ],
  by_district: [
    { district: "Kadıköy", total: 44 },
    { district: "Beşiktaş", total: 28 },
    { district: "Beyoğlu", total: 25 },
    { district: "Şişli", total: 16 },
    { district: "Üsküdar", total: 15 },
  ],
  top_rated: [
    { id: 1, project_id: 1, name: "Baylan Pastanesi", district: "Kadıköy", category: "Pastane", rating: 4.6, ai_score: 79, review_count: 4200, phone: "+90 216 346 63 50", address: "Mühürdar Cad. Kadıköy", latitude: 40.9903, longitude: 29.0237, website: "https://www.baylanpastanesi.com", maps_url: "https://www.openstreetmap.org" },
    { id: 2, project_id: 1, name: "Petra Roasting Co.", district: "Beşiktaş", category: "Kafe", rating: 4.6, ai_score: 82, review_count: 3100, phone: "+90 212 259 5758", address: "Köyiçi Çıkmazı Beşiktaş", latitude: 41.0428, longitude: 29.0048, website: "https://petra.com.tr" },
    { id: 3, project_id: 1, name: "Kronotrop", district: "Beyoğlu", category: "Kafe", rating: 4.5, ai_score: 77, review_count: 2800, address: "Cihangir", latitude: 41.0315, longitude: 28.9831 },
    { id: 4, project_id: 1, name: "Çiya Sofrası", district: "Kadıköy", category: "Restoran", rating: 4.5, ai_score: 71, review_count: 8900, phone: "+90 216 330 3190", address: "Güneşlibahçe Sok.", latitude: 40.9895, longitude: 29.0251, website: "https://www.ciya.com.tr" },
    { id: 5, project_id: 1, name: "Federal Coffee", district: "Şişli", category: "Kafe", rating: 4.5, ai_score: 80, review_count: 2400, address: "Nişantaşı", latitude: 41.0502, longitude: 28.9941 },
    { id: 6, project_id: 1, name: "Walter's Coffee", district: "Kadıköy", category: "Kafe", rating: 4.5, ai_score: 76, review_count: 1900, address: "Moda", latitude: 40.9848, longitude: 29.0259 },
    { id: 7, project_id: 1, name: "Mandabatmaz", district: "Beyoğlu", category: "Kahve", rating: 4.7, ai_score: 58, review_count: 5600, phone: "+90 212 243 3860", address: "Olivia Geçidi", latitude: 41.0321, longitude: 28.9774 },
    { id: 8, project_id: 1, name: "The House Cafe", district: "Beşiktaş", category: "Kafe", rating: 4.2, ai_score: 74, review_count: 3800, address: "Ortaköy", latitude: 41.0472, longitude: 29.0258, website: "https://www.thehousecafe.com.tr" },
  ],
  social_stats: { instagram: 61, linkedin: 14, facebook: 29 },
  recent_scans: [
    { id: 1, status: "completed", found_count: 50, saved_count: 48, progress: 100, project: { id: 1, name: "Kadıköy kafeleri", status: "completed" } },
    { id: 2, status: "completed", found_count: 40, saved_count: 38, progress: 100, project: { id: 2, name: "Beşiktaş restoran", status: "completed" } },
  ],
  queue: { pending: 0, running: 0, failed: 0, completed: 2 },
  map_points: [
    { id: 1, name: "Baylan Pastanesi", latitude: 40.9903, longitude: 29.0237, rating: 4.6, category: "Pastane", district: "Kadıköy" },
    { id: 2, name: "Petra Roasting Co.", latitude: 41.0428, longitude: 29.0048, rating: 4.6, category: "Kafe", district: "Beşiktaş" },
    { id: 3, name: "Kronotrop", latitude: 41.0315, longitude: 28.9831, rating: 4.5, category: "Kafe", district: "Beyoğlu" },
  ],
};
