(() => {
  function init() {
    document.querySelectorAll('input[type="password"]').forEach(input => {
      if (input.closest('[data-password-toggle]')) return;
      const wrapper = document.createElement('span');
      wrapper.setAttribute('data-password-toggle', '');
      wrapper.style.cssText = 'position:relative;display:block;width:100%';
      input.parentNode.insertBefore(wrapper, input);
      wrapper.appendChild(input);
      input.style.paddingRight = '48px';
      const button = document.createElement('button');
      button.type = 'button';
      button.setAttribute('aria-label', 'Show password');
      button.setAttribute('aria-pressed', 'false');
      button.style.cssText = 'position:absolute;right:10px;top:50%;transform:translateY(-50%);border:0;background:transparent;cursor:pointer;padding:5px;display:grid;place-items:center;color:inherit';
      function render(visible) {
        input.type = visible ? 'text' : 'password';
        button.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
        button.setAttribute('aria-pressed', String(visible));
        button.innerHTML = visible
          ? '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.6 5.1A11 11 0 0 1 12 5c5 0 9 4 10 7a14 14 0 0 1-3.2 4.4M6.2 6.2C3.8 7.5 2.5 9.8 2 12c1 3 5 7 10 7 1.5 0 3-.4 4.2-1.1M9.9 9.9a3 3 0 0 0 4.2 4.2M3 3l18 18"/></svg>'
          : '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>';
      }
      render(false);
      button.addEventListener('click', () => {
        const start = input.selectionStart, end = input.selectionEnd;
        render(input.type === 'password');
        input.focus({ preventScroll: true });
        try { input.setSelectionRange(start, end); } catch {}
      });
      wrapper.appendChild(button);
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
