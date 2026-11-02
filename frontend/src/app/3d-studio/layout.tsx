import type { Metadata } from "next";
export const metadata: Metadata = {
  title: "3D Studio",
  description:
    "Create, edit and export 3D scenes and architecture with OpenMindAI.",
  alternates: { canonical: "/3d-studio" },
  openGraph: {
    title: "OpenMindAI 3D Studio",
    description: "Create and edit 3D scenes and architecture.",
    url: "/3d-studio",
  },
};
export default function Layout({ children }: { children: React.ReactNode }) {
  return children;
}
