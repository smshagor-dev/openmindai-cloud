import type { MetadataRoute } from "next";
export default function robots(): MetadataRoute.Robots {
  return {
    rules: {
      userAgent: "*",
      allow: ["/", "/research-studio", "/3d-studio", "/explore"],
      disallow: [
        "/api/",
        "/c/",
        "/share/",
        "/reset-password",
        "/confirm-email",
        "/social-sign-in",
        "/offline.html",
        "/*?project=",
        "/*&project=",
      ],
    },
    sitemap: "https://chat.openmindai.org/sitemap.xml",
  };
}
