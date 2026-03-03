<script setup>
import { ref, onMounted, onBeforeUnmount } from "vue";
import Sidebar from "../components/Sidebar.vue";

const sidebarCollapsed = ref(false);
const mobileOpen = ref(false);

function toggleSidebar() {
  sidebarCollapsed.value = !sidebarCollapsed.value;
}
function toggleMobile() {
  mobileOpen.value = !mobileOpen.value;
}
function closeMobile() {
  mobileOpen.value = false;
}

// close drawer when resizing to desktop
function onResize() {
  if (window.innerWidth >= 768) mobileOpen.value = false;
}

onMounted(() => window.addEventListener("resize", onResize));
onBeforeUnmount(() => window.removeEventListener("resize", onResize));
</script>

<template>
  <div class="app-shell">
    <!-- Mobile topbar -->
    <div class="mobile-topbar d-md-none">
      <button class="menu-btn" @click="toggleMobile">☰</button>
      <div class="fw-bold">C.I.M.S</div>
      <div style="width: 42px"></div>
    </div>

    <!-- overlay (tap to close) -->
    <div
      v-if="mobileOpen"
      class="mobile-overlay d-md-none"
      @click="closeMobile"
    ></div>

    <Sidebar
      :collapsed="sidebarCollapsed"
      :mobile-open="mobileOpen"
      @toggle="toggleSidebar"
      @closeMobile="closeMobile"
    />

    <main class="main-content">
      <RouterView />
    </main>
  </div>
</template>

<style scoped>
.app-shell {
  min-height: 100vh;
  display: flex;
}

/* Mobile top bar */
.mobile-topbar {
  position: sticky;
  top: 0;
  z-index: 2000;
  background: #31ce12;
  color: white;
  padding: 10px 12px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: none;
}

.mobile-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.35);
  z-index: 1500;
}

/* main content */
.main-content {
  flex: 1;
  background-color: #f4f6f9;
  min-height: 100vh;
  padding: 1.5rem;
}

/* Mobile */
@media (max-width: 767.98px) {
  .app-shell {
    display: block;
  }
  .main-content {
    padding: 1rem;
  }
}
.menu-btn {
  background-color: #31ce12;
  color: white;
  border: none;
  width: 42px;
  height: 38px;
  border-radius: 6px;
}

.menu-btn:hover {
  background-color: #28b50f;
}
</style>
