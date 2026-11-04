import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Connect OpenMindAI Mobile",
  robots: { index: false, follow: false },
  referrer: "no-referrer",
};

type Props = { searchParams: Promise<{ challenge?: string; provider?: string; connected?: string }> };

export default async function MobileSignIn({ searchParams }: Props) {
  const { challenge, provider, connected } = await searchParams;
  const valid = typeof challenge === "string" && /^[a-f0-9]{64}$/.test(challenge) &&
    (provider === "google" || provider === "facebook");
  return (
    <main className="shared-page">
      <h1>Connect OpenMindAI Mobile</h1>
      {connected === "1" ? (
        <p>Return to the OpenMindAI app to finish signing in.</p>
      ) : valid ? (
        <form className="form-stack" method="post" action={"/api/auth/social/" + provider + "/start"}>
          <p>Continue only if this code matches the one displayed in your OpenMindAI app.</p>
          <strong>{challenge.slice(0, 8).toUpperCase()}</strong>
          <input type="hidden" name="mobile_challenge" value={challenge} />
          <button className="primary-button" type="submit">
            Continue with {provider === "google" ? "Google" : "Facebook"}
          </button>
        </form>
      ) : (
        <p>Start a new sign-in from the OpenMindAI app.</p>
      )}
    </main>
  );
}
