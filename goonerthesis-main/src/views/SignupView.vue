<script setup>
import { ref } from "vue"
import { useRouter } from "vue-router"
import { signup } from "../services/auth"

const router = useRouter()

const fullName = ref("")
const username = ref("")
const password = ref("")
const confirmPassword = ref("")
const error = ref("")

function submit() {
  error.value = ""

  if (password.value !== confirmPassword.value) {
    error.value = "Passwords do not match."
    return
  }

  try {
    signupUser({
      name: fullName.value,
      username: username.value,
      password: password.value
    })

    alert("Account created successfully.")
    router.push("/login")
  } catch (e) {
    error.value = String(e.message || e)
  }
}
</script>

<template>
  <div class="auth-bg">
    <div class="card shadow signup-card p-4">
      <div class="text-center mb-3">
        <h3 class="fw-bold">Create Account</h3>
        <p class="text-muted">C.I.M.S Registration</p>
      </div>

      <form @submit.prevent="submit">
        <div class="mb-2">
          <label class="form-label">Full Name</label>
          <input v-model="fullName" class="form-control" required />
        </div>

        <div class="mb-2">
          <label class="form-label">Username</label>
          <input v-model="username" class="form-control" required />
        </div>

        <div class="mb-2">
          <label class="form-label">Password</label>
          <input v-model="password" type="password" class="form-control" required />
        </div>

        <div class="mb-3">
          <label class="form-label">Confirm Password</label>
          <input
            v-model="confirmPassword"
            type="password"
            class="form-control"
            required
          />
        </div>

        <div v-if="error" class="alert alert-danger py-2">
          {{ error }}
        </div>

        <button class="btn btn-primary w-100">Sign Up</button>
      </form>

      <div class="text-center mt-3">
        <small>
          Already have an account?
          <RouterLink to="/login">Login</RouterLink>
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
  background: linear-gradient(135deg, #2563eb, #31ce12);
}
.signup-card {
  width: 100%;
  max-width: 460px;
  border-radius: 14px;
}
</style>
