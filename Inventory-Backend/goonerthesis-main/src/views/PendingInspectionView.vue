<script setup>
import { ref, onMounted } from "vue";
import { getPendingInspections, inspectItem } from "../services/inspections";

const loading = ref(true);
const inspections = ref([]);

function errMsg(e) {
  return e?.response?.data?.message || e?.message || "Something went wrong.";
}

async function load() {
  loading.value = true;
  try {
    inspections.value = await getPendingInspections();
  } catch (e) {
    alert(errMsg(e));
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

async function markInspected(inspection, outcome) {
  const verb = outcome === "Good" ? "restock" : "write off as damaged";
  if (
    !confirm(
      `Mark ${inspection.quantity}x "${inspection.item_name}" as ${outcome}? This will ${verb} that quantity.`,
    )
  )
    return;

  try {
    await inspectItem(inspection.idinspection, outcome);
    await load();
  } catch (e) {
    alert(errMsg(e));
  }
}
</script>

<template>
  <div>
    <h3 class="mb-1">Pending Inspection</h3>
    <p class="text-muted mb-4">
      Items returned but not yet checked. Confirm their condition before
      they're added back to available stock.
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
                <th style="width: 220px"></th>
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
                  <div class="d-flex gap-2">
                    <button
                      class="btn btn-sm btn-success"
                      @click="markInspected(i, 'Good')"
                    >
                      Good
                    </button>
                    <button
                      class="btn btn-sm btn-danger"
                      @click="markInspected(i, 'Damaged')"
                    >
                      Damaged
                    </button>
                  </div>
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
  </div>
</template>
