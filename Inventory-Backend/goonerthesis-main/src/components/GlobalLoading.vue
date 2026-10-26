<script setup>
import { ref, watch, onBeforeUnmount } from "vue";
import { isLoading } from "../services/loading";

// The top bar shows immediately. Pages like the Dashboard, Inventory and
// Pending Requests have no placeholder of their own, so a small "Loading..."
// pill appears too, but only when a load is slow enough to be noticed.
const SHOW_BADGE_AFTER_MS = 600;

const showBadge = ref(false);
let timer = null;

watch(isLoading, (loading) => {
  clearTimeout(timer);
  if (loading) {
    timer = setTimeout(() => (showBadge.value = true), SHOW_BADGE_AFTER_MS);
  } else {
    showBadge.value = false;
  }
});

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
  <div v-if="isLoading" class="gl-bar" role="progressbar" aria-label="Loading">
    <div class="gl-bar-fill"></div>
  </div>

  <div v-if="showBadge" class="gl-badge" role="status" aria-live="polite">
    <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
    Loading...
  </div>
</template>

<style scoped>
.gl-bar {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  height: 4px;
  z-index: 3000;
  overflow: hidden;
  background: rgba(25, 135, 84, 0.2);
}

.gl-bar-fill {
  height: 100%;
  width: 40%;
  background: #198754;
  border-radius: 2px;
  animation: gl-slide 1.1s ease-in-out infinite;
}

@keyframes gl-slide {
  0% {
    margin-left: -40%;
  }
  100% {
    margin-left: 100%;
  }
}

.gl-badge {
  position: fixed;
  top: 14px;
  left: 50%;
  transform: translateX(-50%);
  z-index: 3000;
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 18px;
  border-radius: 999px;
  background: #198754;
  color: #fff;
  font-weight: 600;
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25);
  pointer-events: none;
}
</style>
