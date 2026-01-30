<script setup>
import { ref } from "vue"
import { useRouter } from "vue-router"
import { login } from "../services/auth"

const router = useRouter()

const username = ref("")
const password = ref("")
const error = ref("")

function submit() {
  error.value = ""

  try {
    login({ username: username.value, password: password.value })
    router.push("/dashboard")
  } catch (e) {
    error.value = "Invalid username or password."
  }
}
</script>

<template>
  <div class="auth-bg">
    <div class="card shadow login-card p-4">
      <div class="text-center mb-3">
        <h3 class="fw-bold">C.I.M.S</h3>
        <p class="text-muted">School Inventory Management System</p>
      </div>

      <form @submit.prevent="submit">
        <div class="mb-3">
          <label class="form-label">Username</label>
          <input v-model="username" class="form-control" required />
        </div>

        <div class="mb-3">
          <label class="form-label">Password</label>
          <input v-model="password" type="password" class="form-control" required />
        </div>

        <div v-if="error" class="alert alert-danger py-2">
          {{ error }}
        </div>

        <button class="btn btn-success w-100">Login</button>
      </form>

      <div class="text-center mt-3">
        <small>
          Don’t have an account?
          <RouterLink to="/signup">Sign Up</RouterLink>
        </small>
      </div>
    </div>
  </div>
</template>

<style scoped>
.auth-bg {
  min-height: 100vh;
  display: grid;
  place-items: center;
  background: linear-gradient(135deg, #31ce12, #2563eb);
}
.login-card {
  width: 100%;
  max-width: 420px;
  border-radius: 14px;
}
</style>
