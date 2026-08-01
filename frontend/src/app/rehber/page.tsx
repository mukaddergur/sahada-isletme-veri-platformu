"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

export default function RehberYonlendir() {
  const router = useRouter();
  useEffect(() => {
    router.replace("/#incele");
  }, [router]);
  return null;
}
