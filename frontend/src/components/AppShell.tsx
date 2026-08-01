"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useEffect, useMemo } from "react";
import { useAuth } from "@/lib/auth";

const baseLinks = [
  { href: "/", label: "Ana sayfa", exact: true },
  { href: "/businesses", label: "İşletmeler", exact: false },
  { href: "/map", label: "Harita", exact: false },
  { href: "/#incele", label: "İncele", exact: false },
];

export default function AppShell({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const { hydrated, ensureSession, online, user } = useAuth();

  useEffect(() => {
    ensureSession();
  }, [ensureSession]);

  useEffect(() => {
    if (!hydrated || online) return;
    const t = setInterval(() => {
      void ensureSession();
    }, 8000);
    return () => clearInterval(t);
  }, [hydrated, online, ensureSession]);

  const links = useMemo(() => {
    const list = [...baseLinks];
    if (user?.role === "admin" || user?.role === "operator") {
      list.push({ href: "/logs", label: "Loglar", exact: false });
    }
    return list;
  }, [user?.role]);

  if (!hydrated) {
    return <div className="boot-screen">Sahada hazırlanıyor…</div>;
  }

  return (
    <div className="shell land-shell">
      <div className="top-float">
        <header className="top glass-nav">
          <Link href="/" className="brand">
            <span className="brand-mark" aria-hidden />
            Sahada<span>.</span>
          </Link>
          <nav className="nav">
            {links.map((l) => {
              const hashLink = l.href.includes("#");
              const on = hashLink
                ? false
                : l.exact
                  ? pathname === l.href
                  : pathname.startsWith(l.href);
              return (
                <Link key={l.href} href={l.href} className={on ? "on" : ""}>
                  {l.label}
                </Link>
              );
            })}
          </nav>
          <div className="top-right">
            <span className={`live ${online ? "ok" : ""}`}>
              <i />
              {online ? "Canlı" : "API kapalı"}
            </span>
            <Link href="/notifications" className="nav-ghost">
              Bildirimler
            </Link>
            <a className="nav-cta" href="/#arama">
              Başlat
            </a>
          </div>
        </header>
      </div>
      {children}
    </div>
  );
}
