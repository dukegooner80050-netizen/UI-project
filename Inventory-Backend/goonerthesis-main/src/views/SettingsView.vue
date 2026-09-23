<script setup>
import { ref, onMounted } from "vue";
import {
  getMonthlyRequestLimit,
  updateMonthlyRequestLimit,
} from "../services/settings";

const loading = ref(true);
const saving = ref(false);
const currentLimit = ref(null);
const newLimit = ref(5);

function errMsg(e) {
  return e?.response?.data?.message || e?.message || "Something went wrong.";
}

async function load() {
  loading.value = true;
  try {
    const res = await getMonthlyRequestLimit();
    currentLimit.value = res.monthly_request_limit;
    newLimit.value = res.monthly_request_limit;
  } catch (e) {
    alert(errMsg(e));
  } finally {
    loading.value = false;
  }
}

onMounted(load);

async function save() {
  if (!newLimit.value || newLimit.value < 1) {
    alert("Limit must be at least 1.");
    return;
  }
  saving.value = true;
  try {
    const res = await updateMonthlyRequestLimit(newLimit.value);
    currentLimit.value = res.monthly_request_limit;
    alert("Monthly request limit updated.");
  } catch (e) {
    alert(errMsg(e));
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <div>
    <h3 class="mb-1">Settings</h3>
    <p class="text-muted mb-4">System-wide configuration.</p>

    <div v-if="loading" class="text-center text-muted py-4">Loading...</div>

    <div v-else class="card shadow-sm" style="max-width: 480px">
      <div class="card-body">
        <h6 class="mb-2">Monthly Request Limit</h6>
        <p class="text-muted small">
          Maximum number of requests a cashier or dean can submit per
          calendar month. Currently:
          <strong>{{ currentLimit }}</strong> per month.
        </p>

        <div class="input-group">
          <input
            type="number"
            min="1"
            class="form-control"
            v-model.number="newLimit"
          />
          <button class="btn btn-primary" :disabled="saving" @click="save">
            {{ saving ? "Saving..." : "Save" }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
