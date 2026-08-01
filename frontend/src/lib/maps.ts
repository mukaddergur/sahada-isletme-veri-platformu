

export function googleMapsUrl(b: {
  name: string;
  latitude?: number | null;
  longitude?: number | null;
  address?: string | null;
  district?: string | null;
  maps_url?: string | null;
}): string | null {
  const lat = Number(b.latitude);
  const lng = Number(b.longitude);
  if (Number.isFinite(lat) && Number.isFinite(lng) && Math.abs(lat) <= 90 && Math.abs(lng) <= 180) {

    return `https://www.google.com/maps/search/?api=1&query=${lat}%2C${lng}`;
  }
  const q = [b.name, b.address, b.district, "İstanbul"].filter(Boolean).join(" ");
  if (!q.trim()) return null;
  return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(q)}`;
}

export function openStreetMapUrl(b: {
  latitude?: number | null;
  longitude?: number | null;
  maps_url?: string | null;
  place_id?: string | null;
}): string | null {
  if (b.maps_url && b.maps_url.includes("openstreetmap.org")) {
    return b.maps_url;
  }
  if (b.place_id?.startsWith("osm_")) {

    const m = b.place_id.match(/^osm_(node|way|relation)_(\d+)$/);
    if (m) return `https://www.openstreetmap.org/${m[1]}/${m[2]}`;
  }
  const lat = Number(b.latitude);
  const lng = Number(b.longitude);
  if (Number.isFinite(lat) && Number.isFinite(lng)) {
    return `https://www.openstreetmap.org/?mlat=${lat}&mlon=${lng}#map=18/${lat}/${lng}`;
  }
  return null;
}

export function hasCoords(b: { latitude?: number | null; longitude?: number | null }): boolean {
  const lat = Number(b.latitude);
  const lng = Number(b.longitude);
  return Number.isFinite(lat) && Number.isFinite(lng) && Math.abs(lat) <= 90 && Math.abs(lng) <= 180;
}
