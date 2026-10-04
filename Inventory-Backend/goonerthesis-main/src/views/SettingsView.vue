<script setup>
import { ref, onMounted } from "vue";
import {
  getMonthlyRequestLimit,
  updateMonthlyRequestLimit,
} from "../services/settings";
import { getTheme, setTheme } from "../services/theme";
import AlertMessage from "../components/AlertMessage.vue";

const loading = ref(true);
const saving = ref(false);
const currentLimit = ref(null);
const newLimit = ref(5);

// APPEARANCE (light/dark mode)

const theme = ref(getTheme());

function chooseTheme(value) {
  if (theme.value === value) return;
  theme.value = value;
  setTheme(value);
  notify("success", value === "dark" ? "Dark mode enabled." : "Light mode enabled.");
}

const showAlert = ref(false);
const alertType = ref("success");
const alertMessage = ref("");

const showConfirm = ref(false);

function notify(type, message) {
  alertType.value = type;
  alertMessage.value = message;
  showAlert.value = true;
}

function errMsg(e) {
  return (
    e?.response?.data?.message ||
    e?.message ||
    "Something went wrong."
  );
}

async function load() {
  loading.value = true;

  try {
    const res = await getMonthlyRequestLimit();

    currentLimit.value = res.monthly_request_limit;
    newLimit.value = res.monthly_request_limit;
  } catch (e) {
    notify("danger", errMsg(e));
  } finally {
    loading.value = false;
  }
}

onMounted(load);

function openConfirm() {
  if (!newLimit.value || newLimit.value < 1) {
    notify("warning", "Limit must be at least 1.");
    return;
  }

  /*
   * If the user enters the same value that is already saved,
   * there is nothing to change.
   */
  if (Number(newLimit.value) === Number(currentLimit.value)) {
    notify(
      "info",
      "The monthly request limit is already set to this value."
    );
    return;
  }

  showConfirm.value = true;
}

function closeConfirm() {
  if (saving.value) {
    return;
  }

  showConfirm.value = false;
}

async function confirmSave() {
  if (!newLimit.value || newLimit.value < 1) {
    showConfirm.value = false;
    notify("warning", "Limit must be at least 1.");
    return;
  }

  saving.value = true;

  try {
    const res = await updateMonthlyRequestLimit(newLimit.value);

    currentLimit.value = res.monthly_request_limit;

    showConfirm.value = false;

    notify(
      "success",
      "Monthly request limit updated successfully."
    );
  } catch (e) {
    showConfirm.value = false;

    notify("danger", errMsg(e));
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <div>
    <!-- ALERT MESSAGE -->
    <AlertMessage
      v-model:show="showAlert"
      :type="alertType"
      :message="alertMessage"
    />

    <h3 class="mb-1">Settings</h3>

    <p class="text-muted mb-4">
      System-wide configuration.
    </p>

    <!-- APPEARANCE -->
    <div class="card shadow-sm mb-3" style="max-width: 480px">
      <div class="card-body">

        <h6 class="mb-2">
          Appearance
        </h6>

        <p class="text-muted small">
          Choose how CIMS looks on this device.
        </p>

        <div class="d-flex gap-2">
          <button
            type="button"
            class="btn"
            :class="theme === 'light' ? 'btn-primary' : 'btn-outline-primary'"
            @click="chooseTheme('light')"
          >
            <i class="bi bi-sun-fill me-1"></i> Light Mode
          </button>

          <button
            type="button"
            class="btn"
            :class="theme === 'dark' ? 'btn-primary' : 'btn-outline-primary'"
            @click="chooseTheme('dark')"
          >
            <i class="bi bi-moon-stars-fill me-1"></i> Night Mode
          </button>
        </div>

      </div>
    </div>

    <div
      v-if="loading"
      class="text-center text-muted py-4"
    >
      Loading...
    </div>

    <div
      v-else
      class="card shadow-sm"
      style="max-width: 480px"
    >
      <div class="card-body">

        <h6 class="mb-2">
          Monthly Request Limit
        </h6>

        <p class="text-muted small">
          Maximum number of requests a cashier or dean can submit per
          calendar month. Currently:
          <strong>{{ currentLimit }}</strong>
          per month.
        </p>

        <div class="input-group">
          <input
            type="number"
            min="1"
            class="form-control"
            v-model.number="newLimit"
            :disabled="saving"
          />

          <button
            class="btn btn-primary"
            :disabled="saving"
            @click="openConfirm"
          >
            Save
          </button>
        </div>

      </div>
    </div>

    <!-- CONFIRMATION MODAL -->
    <div
      v-if="showConfirm"
      class="modal fade show d-block"
      tabindex="-1"
      style="background: rgba(0, 0, 0, 0.5);"
    >
      <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

          <div class="modal-header">

            <h5 class="modal-title">
              Confirm Change
            </h5>

            <button
              type="button"
              class="btn-close"
              @click="closeConfirm"
              :disabled="saving"
            ></button>

          </div>

          <div class="modal-body">

            <p class="mb-3">
              Are you sure you want to change the monthly request limit?
            </p>

            <div class="bg-light rounded p-3">

              <div class="d-flex justify-content-between mb-2">
                <span class="text-muted">
                  Current limit
                </span>

                <strong>
                  {{ currentLimit }}
                </strong>
              </div>

              <div class="d-flex justify-content-between">
                <span class="text-muted">
                  New limit
                </span>

                <strong>
                  {{ newLimit }}
                </strong>
              </div>

            </div>

            <p class="text-muted small mt-3 mb-0">
              This setting applies system-wide to the monthly request
              limit for cashiers and deans.
            </p>

          </div>

          <div class="modal-footer">

            <button
              type="button"
              class="btn btn-secondary"
              @click="closeConfirm"
              :disabled="saving"
            >
              Cancel
            </button>

            <button
              type="button"
              class="btn btn-primary"
              @click="confirmSave"
              :disabled="saving"
            >
              {{
                saving
                  ? "Saving..."
                  : "Confirm Change"
              }}
            </button>

          </div>

        </div>

      </div>
    </div>

  </div>
</template>