"use client";
import { useState } from "react";
import { Globe } from "lucide-react";

export function siteHost(url?: string | null): string {
  try {
    return url ? new URL(url).hostname.replace(/^www\./, "") : "";
  } catch {
    return "";
  }
}

// Website favicon via DuckDuckGo's icon service (no cookies or referrer sent);
// falls back to a globe when a site has no icon.
export default function SiteIcon({ url, size = 16, className = "" }: { url?: string | null; size?: number; className?: string }) {
  const host = siteHost(url);
  const [failed, setFailed] = useState(false);
  if (!host || failed) return <Globe size={size} className={"site-icon " + className} aria-hidden="true" />;
  return (
    // eslint-disable-next-line @next/next/no-img-element
    <img
      src={`https://icons.duckduckgo.com/ip3/${encodeURIComponent(host)}.ico`}
      width={size}
      height={size}
      alt=""
      loading="lazy"
      referrerPolicy="no-referrer"
      className={"site-icon " + className}
      onError={() => setFailed(true)}
    />
  );
}
