<script setup>
import { useRouter } from "vue-router";
import { logout } from "../services/auth";
import ChangePasswordForm from "../components/ChangePasswordForm.vue";

// Shown on its own (no sidebar) when the AMO has reset this account to a
// temporary password. Nothing else works until a new one is chosen.
const router = useRouter();

function done() {
  router.push("/dashboard");
}

async function signOut() {
  try {
    await logout();
  } catch {
    /* even if the server is unreachable, leave */
  }
  router.push("/login");
}
</script>

<template>
  <div class="auth-bg">
    <div class="card shadow p-4" style="width: 100%; max-width: 420px">
      <h4 class="fw-bold mb-1">Set a new password</h4>
      <p class="text-muted small mb-3">
        The administrator gave you a temporary password. Enter it below as your
        current password, then choose a new one to continue.
      </p>

      <ChangePasswordForm @changed="done" />

      <button type="button" class="btn btn-link btn-sm mt-3 p-0" @click="signOut">
        Log out
      </button>
    </div>
  </div>
</template>

<style scoped>
.auth-bg {
  min-height: 100vh;
  display: grid;
  place-items: center;
  padding: 16px;
  background: linear-gradient(135deg, #31ce12, #2563eb);
}
</style>
