import PageLoading from "@/components/page-loading";
import Pwa from "@/components/pwa";
import type { Viewport, Metadata } from "next";
import "@fontsource-variable/dm-sans";
import "@fontsource-variable/manrope";
import "@fontsource/noto-serif-bengali";
import "@fontsource/noto-naskh-arabic";
import "@fontsource/noto-serif-devanagari";
import "@fontsource/noto-serif-sc";
import "./globals.css";
import "./chat-theme.css";
import "./motion.css";
import "./responsive.css";
import "./workspace-welcome.css";
export const viewport: Viewport = {
  themeColor: "#212121",
  width: "device-width",
  initialScale: 1,
};
export const metadata: Metadata = {
  metadataBase: new URL("https://chat.openmindai.org"),
  title: {
    default: "OpenMindAI Cloud | Chat, Research & 3D Studio",
    template: "%s | OpenMindAI",
  },
  description:
    "Chat with AI, research cited sources, write manuscripts and create 3D scenes with OpenMindAI Cloud.",
  applicationName: "OpenMindAI Cloud",
  alternates: { canonical: "/" },
  manifest: "/manifest.webmanifest",
  icons: { icon: "/icons/icon-192.png", apple: "/icons/icon-192.png" },
  appleWebApp: {
    capable: true,
    statusBarStyle: "default",
    title: "OpenMindAI",
  },
  openGraph: {
    type: "website",
    locale: "en_US",
    siteName: "OpenMindAI",
    title: "OpenMindAI Cloud",
    description: "Your workspace for AI chat, cited research and 3D creation.",
    url: "/",
    images: [
      {
        url: "/icons/icon-512.png",
        width: 512,
        height: 512,
        alt: "OpenMindAI",
      },
    ],
  },
  twitter: {
    card: "summary",
    title: "OpenMindAI Cloud",
    description: "AI chat, cited research and 3D creation.",
    images: ["/icons/icon-512.png"],
  },
  robots: { index: true, follow: true },
};
export default function RootLayout({
  children,
}: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="en">
      <body>
        <PageLoading initial />
        {children}
        <Pwa />
        <script
          type="application/ld+json"
          dangerouslySetInnerHTML={{
            __html: JSON.stringify({
              "@context": "https://schema.org",
              "@type": "WebApplication",
              name: "OpenMindAI Cloud",
              url: "https://chat.openmindai.org",
              applicationCategory: "ProductivityApplication",
              operatingSystem: "Web",
              description:
                "AI chat, cited research, manuscript editing and 3D creation.",
              creator: {
                "@type": "Organization",
                name: "OpenMindAI Technologies Ltd",
                url: "https://openmindai.org",
              },
            }),
          }}
        />
      </body>
    </html>
  );
}
