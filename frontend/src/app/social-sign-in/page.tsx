import SocialSignIn from "@/components/social-sign-in";

export const metadata = {
  title: "Finish signing in · OpenMindAI",
  robots: { index: false, follow: false },
  referrer: "no-referrer" as const,
};

export default async function Page({
  searchParams,
}: { searchParams: Promise<Record<string, string | string[] | undefined>> }) {
  const params = await searchParams;
  const value = (key: string) => typeof params[key] === "string" ? params[key] : "";
  return <SocialSignIn provider={value("provider")} initialStep={value("step")} initialError={value("error")} linked={value("linked") === "1"} />;
}
