"use strict";

// Secrets never appear in the initial HTML or the DOM until the admin
// deliberately reauthenticates. Revealed values expire from the DOM.
for (const panel of document.querySelectorAll("[data-registered-app]")) {
  const id = panel.querySelector("[data-app-id]");
  const secret = panel.querySelector("[data-app-secret]");
  const copyId = panel.querySelector("[data-copy-app-id]");
  const copySecret = panel.querySelector("[data-copy-app-secret]");
  const hideSecret = panel.querySelector("[data-hide-app-secret]");
  const reveal = panel.querySelector("[data-reveal-app-form]");
  const feedback = panel.querySelector("[data-app-feedback]");
  let expiry = null;

  const notify = (message) => { if (feedback) feedback.textContent = message; };
  const clearSecret = () => {
    if (expiry !== null) window.clearTimeout(expiry);
    expiry = null;
    secret.value = "";
    secret.type = "password";
    copySecret.disabled = true;
    hideSecret.disabled = true;
  };

  const copy = async (value, label) => {
    try {
      if (!value || !navigator.clipboard || !window.isSecureContext) {
        notify("Secure clipboard is unavailable. Use HTTPS and try again.");
        return;
      }
      await navigator.clipboard.writeText(value);
      notify(label + " copied.");
    } catch {
      notify("Clipboard permission denied. Please allow clipboard access.");
    }
  };
  copyId.addEventListener("click", () => copy(id.value, "App ID"));
  copySecret.addEventListener("click", () => copy(secret.value, "Signing secret"));
  hideSecret.addEventListener("click", () => { clearSecret(); notify("Signing secret hidden."); });

  if (reveal) {
    reveal.addEventListener("submit", async (event) => {
      event.preventDefault();
      clearSecret();
      const submit = reveal.querySelector('button[type="submit"]');
      submit.disabled = true;
      notify("Verifying administrator credentials…");
      try {
        const body = new FormData(reveal);
        const response = await fetch(reveal.action, {
          method: "POST",
          body,
          credentials: "same-origin",
          cache: "no-store",
          redirect: "error",
          headers: { "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" }
        });
        if (!response.ok) {
          notify(response.status === 422 ? "Invalid administrator password or two-factor code." :
            "Cannot reveal signing secret. Verify your credentials and try again.");
          return;
        }
        const data = await response.json();
        if (data.app_id !== id.value || typeof data.signing_secret !== "string" || !data.signing_secret) {
          notify("Unexpected credentials response.");
          return;
        }
        secret.value = data.signing_secret;
        secret.type = "text";
        copySecret.disabled = false;
        hideSecret.disabled = false;
        expiry = window.setTimeout(() => {
          clearSecret();
          notify("Signing secret automatically hidden.");
        }, 30000);
        notify("Signing secret revealed for 30 seconds. Copy it to your trusted server only.");
      } catch {
        notify("Cannot reveal the signing secret. Check the connection and retry.");
      } finally {
        reveal.reset();
        submit.disabled = false;
      }
    });
  }

  const deletion = panel.querySelector("[data-delete-app-form]");
  if (deletion) {
    deletion.addEventListener("submit", (event) => {
      if (!window.confirm("Permanently delete this registered application and revoke its signing credentials?")) {
        event.preventDefault();
      } else {
        clearSecret();
      }
    });
  }
  window.addEventListener("pagehide", clearSecret);
}
