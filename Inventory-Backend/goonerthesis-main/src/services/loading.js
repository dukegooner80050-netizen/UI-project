import { ref, computed } from "vue";

// Counts API requests that are in flight so the app can show that it is
// busy. Background polling (the notification bell, etc.) passes
// { silent: true } to axios so it never makes the page look like it is
// loading.
const pending = ref(0);

export const isLoading = computed(() => pending.value > 0);

export function loadingStarted(config) {
  if (!config?.silent) pending.value += 1;
}

export function loadingFinished(config) {
  if (!config?.silent) pending.value = Math.max(0, pending.value - 1);
}
