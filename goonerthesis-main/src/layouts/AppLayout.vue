<script setup>
import { ref } from "vue"
import Sidebar from "../components/Sidebar.vue"

const sidebarCollapsed = ref(false)

function toggleSidebar() {
  sidebarCollapsed.value = !sidebarCollapsed.value
}
</script>

<template>
  <div class="app-shell" :class="{ collapsed: sidebarCollapsed }">
    <Sidebar :collapsed="sidebarCollapsed" @toggle="toggleSidebar" />

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

/* main content */
.main-content {
  flex: 1;
  background-color: #f4f6f9;
  min-height: 100vh;
  padding: 1.5rem;
}

/* Print rules (keep your behavior) */
@media print {
  .main-content {
    margin-left: 0 !important;
  }
}
</style>
