/*
 * Shares identical GET requests made at the same moment and reuses the answer for seconds; any change clears
 * it.
 */
const store = new Map(); // key -> { at, promise }

const DEFAULT_TTL_MS = 15000;

export function cached(key, fetcher, ttl = DEFAULT_TTL_MS) {
  const hit = store.get(key);
  if (hit && Date.now() - hit.at < ttl) return hit.promise;

  const promise = Promise.resolve()
    .then(fetcher)
    .catch((e) => {
      // Never keep a failure: the next caller should try again.
      if (store.get(key)?.promise === promise) store.delete(key);
      throw e;
    });

  store.set(key, { at: Date.now(), promise });
  return promise;
}

export function clearSharedCache() {
  store.clear();
}
