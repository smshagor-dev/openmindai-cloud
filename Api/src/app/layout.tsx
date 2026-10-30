import type { Metadata } from "next";
import "@fontsource-variable/dm-sans";
import "@fontsource-variable/manrope";
import "./globals.css";
import "./visual-refresh.css";

export const metadata: Metadata = {
  title: "OpenMindAI Developers | Build with OpenMindAI",
  description: "Create API keys, connect coding assistants, explore hosted models, and monitor usage.",
  icons: { icon: "/openmindai-logo.png", apple: "/openmindai-logo.png" },
  robots: { index: true, follow: true }
};

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return <html lang="en"><body>{children}</body></html>;
}
