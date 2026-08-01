"use client";

import { create } from "zustand";
import api from "./api";
import type { User } from "./types";

type AuthState = {
  user: User | null;
  token: string | null;
  hydrated: boolean;
  online: boolean;
  ensureSession: () => Promise<void>;
};

export const useAuth = create<AuthState>((set) => ({
  user: null,
  token: null,
  hydrated: false,
  online: false,
  async ensureSession() {
    const existing = typeof window !== "undefined" ? localStorage.getItem("meridyen_token") : null;
    const legacy = typeof window !== "undefined" ? localStorage.getItem("maplead_token") : null;
    const token = existing || legacy;

    if (token) {
      try {
        const { data } = await api.get("/auth/me");
        localStorage.setItem("meridyen_token", token);
        set({ user: data, token, hydrated: true, online: true });
        return;
      } catch {
        localStorage.removeItem("meridyen_token");
        localStorage.removeItem("maplead_token");
      }
    }

    const email = process.env.NEXT_PUBLIC_SEED_EMAIL;
    const password = process.env.NEXT_PUBLIC_SEED_PASSWORD;
    if (email && password) {
      try {
        const { data } = await api.post("/auth/login", { email, password });
        localStorage.setItem("meridyen_token", data.token);
        set({ user: data.user, token: data.token, hydrated: true, online: true });
        return;
      } catch {
      }
    }

    set({
      user: {
        id: 0,
        name: "Önizleme",
        email: "onizleme@sahada",
        role: "user",
      },
      token: null,
      hydrated: true,
      online: false,
    });
  },
}));
