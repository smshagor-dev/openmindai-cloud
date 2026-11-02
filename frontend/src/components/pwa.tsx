"use client";
import { useEffect, useState } from "react";
type InstallEvent = Event & {
  prompt(): Promise<void>;
  userChoice: Promise<{ outcome: string }>;
};
export default function Pwa() {
  const [install, setInstall] = useState<InstallEvent | null>(null);
  const [update, setUpdate] = useState<ServiceWorker | null>(null);
  useEffect(() => {
    if (!("serviceWorker" in navigator) || !window.isSecureContext) return;
    let mounted = true;
    navigator.serviceWorker
      .register("/sw.js", { scope: "/", updateViaCache: "none" })
      .then((reg) => {
        if (reg.waiting && mounted) setUpdate(reg.waiting);
        reg.addEventListener("updatefound", () => {
          const worker = reg.installing;
          worker?.addEventListener("statechange", () => {
            if (
              worker.state === "installed" &&
              navigator.serviceWorker.controller &&
              mounted
            )
              setUpdate(worker);
          });
        });
      })
      .catch(() => {
        /* Online workspace remains available if registration is blocked. */
      });
    const prompt = (event: Event) => {
      event.preventDefault();
      setInstall(event as InstallEvent);
    };
    const installed = () => setInstall(null);
    window.addEventListener("beforeinstallprompt", prompt);
    window.addEventListener("appinstalled", installed);
    return () => {
      mounted = false;
      window.removeEventListener("beforeinstallprompt", prompt);
      window.removeEventListener("appinstalled", installed);
    };
  }, []);
  if (!install && !update) return null;
  return (
    <aside className="pwa-actions" aria-label="OpenMindAI app">
      {install && (
        <button
          onClick={async () => {
            await install.prompt();
            await install.userChoice;
            setInstall(null);
          }}
        >
          Install OpenMindAI
        </button>
      )}
      {update && (
        <button
          onClick={() => {
            navigator.serviceWorker.addEventListener(
              "controllerchange",
              () => window.location.reload(),
              { once: true },
            );
            update.postMessage({ type: "SKIP_WAITING" });
            setUpdate(null);
          }}
        >
          Update app
        </button>
      )}
    </aside>
  );
}
