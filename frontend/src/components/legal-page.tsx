import Link from "next/link";
import Markdown from "react-markdown";
import remarkGfm from "remark-gfm";

type Policy = { title: string; body: string; updated_at: string; support_email: string };

export default async function LegalPage({ slug }: { slug: "privacy-policy" | "terms-and-conditions" }) {
  const base = process.env.LARAVEL_API_URL || "https://cloud.openmindai.org/api/v1";
  let policy: Policy | null = null;
  try {
    const response = await fetch(`${base.replace(/\/$/, "")}/legal/${slug}`, {
      cache: "no-store", redirect: "error", signal: AbortSignal.timeout(8000),
      headers: { Accept: "application/json" },
    });
    if (response.ok) policy = await response.json();
  } catch {
    // Keep a retryable public page if the backend is temporarily unavailable.
  }
  const title = policy?.title || (slug === "privacy-policy" ? "Privacy Policy" : "Terms and Conditions");
  return (
    <main className="shared-page">
      <header>
        <Link href="/">OpenMindAI</Link>
        <Link href={slug === "privacy-policy" ? "/terms-and-conditions" : "/privacy-policy"}>
          {slug === "privacy-policy" ? "Terms and Conditions" : "Privacy Policy"}
        </Link>
      </header>
      <h1>{title}</h1>
      {policy ? (
        <>
          <p className="muted">Updated {policy.updated_at.slice(0, 10)}</p>
          <article className="shared-message markdown legal-page-content">
            <Markdown remarkPlugins={[remarkGfm]} skipHtml disallowedElements={["img"]}>{policy.body}</Markdown>
          </article>
          {policy.support_email && <p>Support contact <a href={`mailto:${policy.support_email}`}>{policy.support_email}</a></p>}
        </>
      ) : <p role="alert">This page is temporarily unavailable. Reload to try again.</p>}
    </main>
  );
}
