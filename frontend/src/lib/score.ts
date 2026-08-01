import type { Business } from "./types";

export function starScore(b: Pick<Business, "rating" | "phone" | "website" | "email" | "address" | "latitude" | "longitude">): number {
  if (b.rating != null && Number(b.rating) > 0) {
    return Math.max(1, Math.min(5, Math.round(Number(b.rating))));
  }
  let s = 1;
  if (b.phone) s += 1;
  if (b.website) s += 1;
  if (b.email) s += 1;
  if (b.address || (b.latitude != null && b.longitude != null)) s += 1;
  return Math.min(5, s);
}

export function starsLabel(n: number): string {
  return "★".repeat(n) + "☆".repeat(Math.max(0, 5 - n));
}
