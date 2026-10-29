import type { Metadata } from "next";
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
export const metadata: Metadata = {
  title: "OpenMindAI | Your AI Workspace",
  icons: { icon: "/openmindai-logo.png", apple: "/openmindai-logo.png" },
  description:
    "Chat, write, research, and build with OpenMindAI.",
};
export default function RootLayout({
  children,
}: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="en">
      <body>{children}</body>
    </html>
  );
}
