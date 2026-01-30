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
</style>
