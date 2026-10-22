// Small shared cache for the few GET requests that several parts of the app
// make at the same moment (the bell, the overdue banner and the critical
// stock banner all ask for the same lists). Concurrent callers share ONE
// request, and the answer is reused for a few seconds.
//
// Any successful change to the data (POST / PUT / PATCH / DELETE) clears it,
// so a screen that reloads right after an action never sees old data.
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
