export default function SocialProviderButtons({
  providers,
  intent = "sign-in",
}: {
  providers: string[];
  intent?: "sign-in" | "link";
}) {
  return (
    <div className="form-stack">
      {["google", "facebook"].filter(provider => providers.includes(provider)).map(provider => (
        <form key={provider} action={`/api/auth/social/${provider}/start`} method="post">
          <input type="hidden" name="intent" value={intent} />
          <button className={intent === "sign-in" && provider === "google" ? "primary-button" : "secondary-button"} type="submit">
            {intent === "link" ? "Link" : "Continue with"} {provider === "google" ? "Google" : "Facebook"}
          </button>
        </form>
      ))}
    </div>
  );
}
