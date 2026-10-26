<script setup>
import { ref, computed } from "vue";
import { changePassword } from "../services/auth";
import { getErrorMessage } from "../services/errors";

// Used on the Settings page and on the forced "set a new password" page.
const emit = defineEmits(["changed"]);

const current = ref("");
const next = ref("");
const confirm = ref("");
const working = ref(false);
const error = ref("");
const done = ref(false);

const tooShort = computed(() => next.value.length > 0 && next.value.length < 8);
const mismatch = computed(() => confirm.value.length > 0 && next.value !== confirm.value);
const canSubmit = computed(
  () =>
    !working.value &&
    current.value &&
    next.value.length >= 8 &&
    next.value === confirm.value,
);

async function submit() {
  if (!canSubmit.value) return;
  error.value = "";
  done.value = false;
  working.value = true;
  try {
    await changePassword({
      current_password: current.value,
      password: next.value,
      password_confirmation: confirm.value,
    });
    current.value = "";
    next.value = "";
    confirm.value = "";
    done.value = true;
    emit("changed");
  } catch (e) {
    error.value = getErrorMessage(e);
  } finally {
    working.value = false;
  }
}
</script>

<template>
  <form @submit.prevent="submit" novalidate>
    <div v-if="error" class="alert alert-danger py-2">{{ error }}</div>
    <div v-if="done" class="alert alert-success py-2">
      Your password was changed. Other devices were signed out.
    </div>

    <div class="mb-3">
      <label class="form-label" for="cp-current">Current password</label>
      <input
        id="cp-current"
        name="current-password"
        v-model="current"
        type="password"
        class="form-control"
        autocomplete="current-password"
        :disabled="working"
      />
    </div>

    <div class="mb-3">
      <label class="form-label" for="cp-new">New password</label>
      <input
        id="cp-new"
        name="new-password"
        v-model="next"
        type="password"
        class="form-control"
        :class="{ 'is-invalid': tooShort }"
        autocomplete="new-password"
        :disabled="working"
      />
      <div class="form-text" :class="{ 'text-danger': tooShort }">
        At least 8 characters.
      </div>
    </div>

    <div class="mb-3">
      <label class="form-label" for="cp-confirm">Confirm new password</label>
      <input
        id="cp-confirm"
        name="confirm-new-password"
        v-model="confirm"
        type="password"
        class="form-control"
        :class="{ 'is-invalid': mismatch }"
        autocomplete="new-password"
        :disabled="working"
      />
      <div v-if="mismatch" class="invalid-feedback">Passwords do not match.</div>
    </div>

    <button type="submit" class="btn btn-primary" :disabled="!canSubmit">
      {{ working ? "Saving..." : "Change Password" }}
    </button>
  </form>
</template>
