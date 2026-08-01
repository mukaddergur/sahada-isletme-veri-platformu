"use client";

import { FormEvent, useState } from "react";
import { useRouter } from "next/navigation";
import AppShell from "@/components/AppShell";
import { useAuth } from "@/lib/auth";

export default function GirisPage() {
  const router = useRouter();
  const { login } = useAuth();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [hata, setHata] = useState("");
  const [bekliyor, setBekliyor] = useState(false);

  async function gonder(e: FormEvent) {
    e.preventDefault();
    setHata("");
    setBekliyor(true);
    try {
      await login(email.trim(), password);
      router.push("/");
    } catch (err: unknown) {
      const ax = err as { response?: { data?: { message?: string; errors?: { email?: string[] } } } };
      const msg =
        ax.response?.data?.errors?.email?.[0] ||
        ax.response?.data?.message ||
        "Giriş başarısız. E-posta ve şifreyi kontrol edin. Backend açık mı?";
      setHata(msg);
    } finally {
      setBekliyor(false);
    }
  }

  return (
    <AppShell>
      <main className="auth-page">
        <section className="auth-card">
          <div className="auth-brand">
            <span className="brand-mark" aria-hidden />
            <span className="auth-brand-name">
              Sahada<span>.</span>
            </span>
          </div>
          <h1>Giriş yap</h1>
          <p className="auth-lead">Hesabınla devam et; tarama ve kayıtlar senin paneline bağlanır.</p>

          <form className="auth-form" onSubmit={gonder}>
            <label>
              <span>E-posta</span>
              <input
                type="email"
                autoComplete="username"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="ornek@mail.com"
                required
              />
            </label>
            <label>
              <span>Şifre</span>
              <input
                type="password"
                autoComplete="current-password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                placeholder="••••••••"
                required
              />
            </label>
            {hata ? <p className="auth-error">{hata}</p> : null}
            <button type="submit" className="auth-btn" disabled={bekliyor}>
              {bekliyor ? "Giriş yapılıyor…" : "Giriş yap"}
            </button>
          </form>
        </section>
      </main>
    </AppShell>
  );
}
