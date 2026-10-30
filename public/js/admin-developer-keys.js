"use strict";

// The Developer API Key is revealed only on creation, and is never stored
// in localStorage, sessionStorage, the URL or the initial server HTML.
const devForm = document.querySelector("[data-admin-devkey-form]");
if (devForm) {
  const notification = document.querySelector("[data-devkey-message]");
  const created = document.querySelector("[data-devkey-created]");
  const keyField = document.querySelector("[data-devkey-secret]");
  const copyButton = document.querySelector("[data-devkey-copy]");
  const hideButton = document.querySelector("[data-devkey-hide]");
  let timeoutId = null;
  const inform = (message) => { notification.textContent = message; };

  function clearKey() {
    if (timeoutId !== null) window.clearTimeout(timeoutId);
    timeoutId = null;
    keyField.value = "";
    keyField.type = "password";
    created.hidden = true;
  }

  devForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    clearKey();
    const submit = devForm.querySelector('button[type="submit"]');
    submit.disabled = true;
    inform("Creating a personal Developer API Key…");

    try {
      const response = await fetch(devForm.action, {
        method: "POST",
        body: new FormData(devForm),
        credentials: "same-origin",
        cache: "no-store",
        redirect: "error",
        headers: { "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" }
      });
      if (!response.ok) {
        inform(response.status === 422
          ? "Check the name, administrator password, two-factor code or key limit."
          : "Could not create a Developer API Key. Please retry.");
        return;
      }
      const result = await response.json();
      if (!result || typeof result.api_key !== "string" || !result.api_key) {
        inform("Unexpected server response. No key was displayed.");
        return;
      }
      keyField.value = result.api_key;
      keyField.type = "text";
      created.hidden = false;
      timeoutId = window.setTimeout(() => {
        clearKey();
        inform("The one-time API Key has been hidden. Generate another if it was not copied.");
      }, 60000);
      inform("Developer API Key created. Copy it into OpenMind Studio before closing this page. Refresh to see it in the existing keys list.");
    } catch {
      inform("Request failed. Check your connection and try again.");
    } finally {
      devForm.reset();
      submit.disabled = false;
    }
  });

  copyButton.addEventListener("click", async () => {
    if (!keyField.value) return;
    if (!window.isSecureContext || !navigator.clipboard) {
      inform("Clipboard unavailable. This page requires HTTPS to copy securely.");
      return;
    }
    try {
      await navigator.clipboard.writeText(keyField.value);
      inform("Developer API Key copied. Store it in OpenMind Studio's encrypted Cloud settings.");
    } catch {
      inform("Clipboard access denied. Please allow copying in your browser.");
    }
  });

  hideButton.addEventListener("click", () => { clearKey(); inform("Developer API Key hidden."); });
  window.addEventListener("pagehide", clearKey);
  for (const revoke of document.querySelectorAll("[data-devkey-revoke]")) {
    revoke.addEventListener("submit", (event) => {
      if (!window.confirm("Revoke this Developer API Key? Connected desktop apps using it will lose access.")) {
        event.preventDefault();
      } else {
        clearKey();
      }
    });
  }
}
