import ResearchStudio from "@/components/research/studio";
import "./research.css";
import "./chat.css";
export async function generateMetadata({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const params = await searchParams;
  return {
    title: "Research Studio",
    description:
      "Research sources, review evidence, write academic manuscripts and download research files with OpenMindAI.",
    alternates: { canonical: "/research-studio" },
    robots: params.project
      ? { index: false, follow: false }
      : { index: true, follow: true },
    openGraph: {
      title: "OpenMindAI Research Studio",
      url: "/research-studio",
      description:
        "Research, evidence and manuscript creation in one workspace.",
    },
  };
}
export const dynamic = "force-dynamic";
export default function ResearchPage() {
  return <ResearchStudio />;
}
