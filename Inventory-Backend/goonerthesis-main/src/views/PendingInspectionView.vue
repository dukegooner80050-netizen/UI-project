<script setup>
import { ref, computed, onMounted } from "vue";
import { getPendingInspections, evaluateInspection } from "../services/inspections";
import AlertMessage from "../components/AlertMessage.vue";

const loading = ref(true);
const inspections = ref([]);
const showAlert = ref(false);
const alertType = ref("info");
const alertMessage = ref("");

// STEP 1: quantity-entry modal
const showEvaluate = ref(false);
const evaluatingInspection = ref(null);
const qtyFitForUse = ref(0);
const qtyNeedMaintenance = ref(0);
const qtyDisposal = ref(0);

// STEP 2: confirmation modal
const showConfirm = ref(false);

function errMsg(e) {
  return e?.response?.data?.message || e?.message || "Something went wrong.";
}
function showNotification(type, message) {
  alertType.value = type;
  alertMessage.value = message;
  showAlert.value = true;
}

async function load() {
  loading.value = true;
  try {
    inspections.value = await getPendingInspections();
  } catch (e) {
    showNotification("danger", errMsg(e));
  } finally {
    loading.value = false;
  }
}

onMounted(load);

function formatDate(date) {
  if (!date) return "-";
  return new Date(date).toLocaleString("en-PH", {
    year: "numeric",
    month: "short",
    day: "numeric",
    hour: "numeric",
    minute: "2-digit",
  });
}

// STEP 1 — open the quantity-entry modal for a row
function openEvaluate(inspection) {
  evaluatingInspection.value = inspection;
  qtyFitForUse.value = 0;
  qtyNeedMaintenance.value = 0;
  qtyDisposal.value = 0;
  showEvaluate.value = true;
}

function closeEvaluate() {
  showEvaluate.value = false;
  evaluatingInspection.value = null;
}

const assignedTotal = computed(
  () =>
    (Number(qtyFitForUse.value) || 0) +
    (Number(qtyNeedMaintenance.value) || 0) +
    (Number(qtyDisposal.value) || 0),
);

const remaining = computed(() => {
  if (!evaluatingInspection.value) return 0;
  return Number(evaluatingInspection.value.quantity) - assignedTotal.value;
});

const canReview = computed(
  () => !!evaluatingInspection.value && remaining.value === 0 && assignedTotal.value > 0,
);

// STEP 1 -> STEP 2
function proceedToConfirm() {
  if (!canReview.value) return;
  showEvaluate.value = false;
  showConfirm.value = true;
}

function backToEvaluate() {
  showConfirm.value = false;
  showEvaluate.value = true;
}

function closeConfirm() {
  showConfirm.value = false;
  evaluatingInspection.value = null;
}

// STEP 2 -> SUBMIT
async function confirmInspection() {
  if (!evaluatingInspection.value) return;

  try {
    const result = await evaluateInspection(evaluatingInspection.value.idinspection, {
      fitForUse: Number(qtyFitForUse.value) || 0,
      needMaintenance: Number(qtyNeedMaintenance.value) || 0,
      disposal: Number(qtyDisposal.value) || 0,
    });

    showConfirm.value = false;
    evaluatingInspection.value = null;

    showNotification("success", result?.message || "Inspection completed successfully.");

    await load();
  } catch (e) {
    showConfirm.value = false;
    showNotification("danger", errMsg(e));
  }
}
</script>

<template>
  <div>
    <AlertMessage v-model:show="showAlert" :type="alertType" :message="alertMessage" />
    <h3 class="mb-1">Pending Inspection</h3>
    <p class="text-muted mb-4">
      Items returned but not yet checked. Evaluate each one before it's
      added back to available stock.
    </p>

    <div v-if="loading" class="text-center text-muted py-4">Loading...</div>

    <div v-else class="card shadow-sm">
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-striped table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Item</th>
                <th>Qty Returned</th>
                <th>Request #</th>
                <th>Location</th>
                <th>Returned On</th>
                <th style="width: 140px"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="i in inspections" :key="i.idinspection">
                <td>{{ i.item_name }}</td>
                <td>{{ i.quantity }}</td>
                <td>{{ i.requestId }}</td>
                <td>
                  {{ i.location }}
                  <small v-if="i.room" class="text-muted d-block">{{
                    i.room
                  }}</small>
                </td>
                <td>{{ formatDate(i.returned_at) }}</td>
                <td>
                  <button
                    class="btn btn-sm btn-primary"
                    @click="openEvaluate(i)"
                  >
                    Evaluate
                  </button>
                </td>
              </tr>
              <tr v-if="inspections.length === 0">
                <td colspan="6" class="text-center text-muted py-4">
                  Nothing waiting for inspection right now.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- STEP 1: QUANTITY-ENTRY MODAL -->
    <div
      v-if="showEvaluate"
      class="modal fade show d-block"
      tabindex="-1"
      style="background: rgba(0, 0, 0, 0.5);"
    >
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Evaluate Returned Item</h5>
            <button type="button" class="btn-close" @click="closeEvaluate"></button>
          </div>

          <div class="modal-body" v-if="evaluatingInspection">
            <div class="bg-light rounded p-3 mb-3">
              <div><strong>Item:</strong> {{ evaluatingInspection.item_name }}</div>
              <div><strong>Total Returned:</strong> {{ evaluatingInspection.quantity }}</div>
            </div>

            <p class="text-muted small mb-3">
              Split the returned quantity across the outcomes below. The
              three numbers must add up to exactly
              {{ evaluatingInspection.quantity }}.
            </p>

            <div class="mb-3">
              <label class="form-label text-success fw-semibold">Fit for Use</label>
              <input
                type="number"
                min="0"
                :max="evaluatingInspection.quantity"
                class="form-control"
                v-model.number="qtyFitForUse"
              />
            </div>

            <div class="mb-3">
              <label class="form-label text-warning fw-semibold">Need Maintenance</label>
              <input
                type="number"
                min="0"
                :max="evaluatingInspection.quantity"
                class="form-control"
                v-model.number="qtyNeedMaintenance"
              />
            </div>

            <div class="mb-3">
              <label class="form-label text-danger fw-semibold">Disposal</label>
              <input
                type="number"
                min="0"
                :max="evaluatingInspection.quantity"
                class="form-control"
                v-model.number="qtyDisposal"
              />
            </div>

            <div
              class="small fw-semibold"
              :class="remaining === 0 ? 'text-success' : 'text-danger'"
            >
              <span v-if="remaining > 0">{{ remaining }} remaining to assign</span>
              <span v-else-if="remaining < 0">{{ -remaining }} too many assigned</span>
              <span v-else>All {{ evaluatingInspection.quantity }} accounted for</span>
            </div>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" @click="closeEvaluate">
              Cancel
            </button>
            <button
              type="button"
              class="btn btn-primary"
              :disabled="!canReview"
              @click="proceedToConfirm"
            >
              Review
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- STEP 2: CONFIRMATION MODAL -->
    <div
      v-if="showConfirm"
      class="modal fade show d-block"
      tabindex="-1"
      style="background: rgba(0, 0, 0, 0.5);"
    >
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Confirm Inspection</h5>
            <button type="button" class="btn-close" @click="closeConfirm"></button>
          </div>

          <div class="modal-body" v-if="evaluatingInspection">
            <p class="mb-3">
              Confirm this breakdown for
              <strong>{{ evaluatingInspection.item_name }}</strong>
              ({{ evaluatingInspection.quantity }} returned)?
            </p>

            <div class="bg-light rounded p-3">
              <div v-if="qtyFitForUse > 0" class="d-flex justify-content-between mb-1">
                <span class="text-success">Fit for Use (restocked)</span>
                <strong>{{ qtyFitForUse }}</strong>
              </div>
              <div v-if="qtyNeedMaintenance > 0" class="d-flex justify-content-between mb-1">
                <span class="text-warning">Need Maintenance (held)</span>
                <strong>{{ qtyNeedMaintenance }}</strong>
              </div>
              <div v-if="qtyDisposal > 0" class="d-flex justify-content-between">
                <span class="text-danger">Disposal (written off)</span>
                <strong>{{ qtyDisposal }}</strong>
              </div>
            </div>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" @click="backToEvaluate">
              Back
            </button>
            <button type="button" class="btn btn-secondary" @click="closeConfirm">
              Cancel
            </button>
            <button type="button" class="btn btn-primary" @click="confirmInspection">
              Confirm
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
