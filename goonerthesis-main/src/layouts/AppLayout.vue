<script setup>
import Sidebar from "../components/Sidebar.vue"
</script>

<template>
  <div class="container-fluid">
    <div class="row">
      <!-- Sidebar -->
      <div class="col-md-2 p-0 sidebar-col">
        <Sidebar />
      </div>

      <!-- Main content -->
      <main class="col-md-10 p-4 main-content">
        <RouterView />
      </main>
    </div>
  </div>
</template>

<style scoped>

.sidebar-col {
  position: relative;
  z-index: 9999;
}

.main-content {
  position: relative;
  background-color: #f4f6f9;
  z-index: 1;
  min-height: 100vh;
}

@media print {
  /* Hide navigation */
  .sidebar,
  .sidebar-scroll {
    display: none !important;
  }

  /* Remove layout offsets caused by sidebar */
  .main-content {
    margin-left: 0 !important;
  }

  /* Hide buttons / controls */
  .no-print {
    display: none !important;
  }
}
</style>
