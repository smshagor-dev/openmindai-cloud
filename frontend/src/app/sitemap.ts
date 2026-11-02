import type { MetadataRoute } from "next";
export default function sitemap(): MetadataRoute.Sitemap {
  return ["", "/research-studio", "/3d-studio", "/explore"].map((path) => ({
    url: `https://chat.openmindai.org${path}`,
    changeFrequency: "weekly",
    priority: path ? 0.8 : 1,
  }));
}
