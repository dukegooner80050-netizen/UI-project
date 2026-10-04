<script setup>
import { ref } from "vue";
import { useRouter } from "vue-router";
import axios from "../axios";
import AlertMessage from "../components/AlertMessage.vue";

const router = useRouter();

const fullName = ref("");
const username = ref("");
const password = ref("");
const confirmPassword = ref("");
const showAlert = ref(false);
const alertType = ref("success");
const alertMessage = ref("");

async function submit() {
  if (password.value !== confirmPassword.value) {
    alertType.value = "danger";
    alertMessage.value = "Passwords do not match.";
    showAlert.value = true;
    return;
  }

  try {
    await axios.post("/register", {
      full_name: fullName.value,
      username: username.value,
      password: password.value,
    });

    alertType.value = "success";
    alertMessage.value = "Account created successfully.";
    showAlert.value = true;

    // Wait a bit so the user sees it
    setTimeout(() => {
      router.push("/login");
    }, 1500);
  } catch (e) {
    if (e.response?.data?.message) {
      alertType.value = "danger";
      alertMessage.value = e.response.data.message;
      showAlert.value = true;
    } else if (e.response?.data?.errors) {
      alertType.value = "danger";
      alertMessage.value = Object.values(e.response.data.errors)
        .flat()
        .join(", ");
      showAlert.value = true;
    } else {
      alertType.value = "danger";
      alertMessage.value = "Something went wrong.";
      showAlert.value = true;
    }
  }
}
</script>

<template>
  <div class="auth-bg">
    <div class="card shadow signup-card p-4">
      <AlertMessage
        v-model:show="showAlert"
        :type="alertType"
        :message="alertMessage"
      />
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
          <input v-model="password" type="password" class="form-control" required/>
        </div>

        <div class="mb-3">
          <label class="form-label">Confirm Password</label>
          <input v-model="confirmPassword" type="password" class="form-control" required/>
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
