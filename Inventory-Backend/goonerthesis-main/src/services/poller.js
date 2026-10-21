// Background refresh helpers. They exist so the app does not hammer the
// server (and the database behind it) from tabs nobody is looking at.

// Calls fn() every `ms`, but only while the tab is visible. When the tab
// becomes visible again it refreshes at once if the data is older than `ms`.
// Returns a stop function.
export function startPolling(fn, ms) {
  let last = Date.now();

  const run = async () => {
    last = Date.now();
    try {
      await fn();
    } catch {
      /* callers handle their own errors */
    }
  };

  const timer = setInterval(() => {
    if (!document.hidden) run();
  }, ms);

  const onVisible = () => {
    if (!document.hidden && Date.now() - last >= ms) run();
  };
  document.addEventListener("visibilitychange", onVisible);

  return () => {
    clearInterval(timer);
    document.removeEventListener("visibilitychange", onVisible);
  };
}

// Wraps fn so it runs at most once per `minGap` ms (extra calls are skipped).
export function throttled(fn, minGap) {
  let last = 0;
  return (...args) => {
    const now = Date.now();
    if (now - last < minGap) return undefined;
    last = now;
    return fn(...args);
  };
}
