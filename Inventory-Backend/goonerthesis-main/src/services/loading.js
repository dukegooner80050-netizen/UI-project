import { ref, computed } from "vue";

/* Counts in-flight API requests to show the busy state; background polls pass { silent: true }. */
const pending = ref(0);

export const isLoading = computed(() => pending.value > 0);

export function loadingStarted(config) {
  if (!config?.silent) pending.value += 1;
}

export function loadingFinished(config) {
  if (!config?.silent) pending.value = Math.max(0, pending.value - 1);
}
