import type { MetadataRoute } from "next";
export default function manifest(): MetadataRoute.Manifest {
  return {
    id: "/",
    name: "OpenMindAI Cloud",
    short_name: "OpenMindAI",
    description:
      "Chat, research, write and create 3D scenes in your AI workspace.",
    start_url: "/?source=pwa",
    scope: "/",
    display: "standalone",
    background_color: "#212121",
    theme_color: "#212121",
    lang: "en",
    icons: [
      {
        src: "/icons/icon-192.png",
        sizes: "192x192",
        type: "image/png",
        purpose: "any",
      },
      {
        src: "/icons/icon-512.png",
        sizes: "512x512",
        type: "image/png",
        purpose: "any",
      },
    ],
    shortcuts: [
      { name: "Research Studio", url: "/research-studio" },
      { name: "3D Studio", url: "/3d-studio" },
    ],
  };
}
