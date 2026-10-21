<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from "vue";
import { useRoute, useRouter } from "vue-router";
import { getLowStock } from "../services/restock";
import { getCurrentUser } from "../services/storage";
import { isAdmin } from "../services/session";

// Roadmap #12: tells the admin, on every page, when items have reached
// Critical stock (or run out) and nobody has raised a restock request for
// them yet. The request itself is made from the "Needs Restocking" panel on
// the dashboard. Dismissing hides the banner for this browser session; it
// comes back if the set of items changes.

const route = useRoute();
const router = useRouter();

const items = ref([]);
const expanded = ref(false);
const dismissed = ref(readDismissed());

const admin = computed(() => isAdmin(getCurrentUser()));
const onDashboard = computed(() => route.path === "/dashboard");

function storageKey() {
  return `criticalStockDismissed:${getCurrentUser()?.idUsers ?? "anon"}`;
}

function readDismissed() {
  try {
    return sessionStorage.getItem(storageKey()) || "";
  } catch {
    return "";
  }
}

async function load() {
  dismissed.value = readDismissed();

  if (!admin.value) {
    items.value = [];
    return;
  }

  try {
    const rows = await getLowStock();
    items.value = rows.filter(
      (r) => r.level === "Critical" && !r.active_request_id,
    );
  } catch (e) {
    // An alert banner must never break the page it sits on.
    console.error("Failed to load critical stock:", e);
  }
}

onMounted(() => {
  load();
  // The restock panel fires this after a request is created or changed.
  window.addEventListener("cims:restock-changed", load);
});
onBeforeUnmount(() =>
  window.removeEventListener("cims:restock-changed", load),
);
// Refresh when moving between pages (stock changes elsewhere).
watch(() => route.fullPath, load);

const signature = computed(() =>
  items.value
    .map((i) => i.iditems)
    .sort((a, b) => a - b)
    .join(","),
);

// The dashboard shows its own, richer urgent-stock card, so skip the banner there.
const visible = computed(
  () =>
    items.value.length > 0 &&
    dismissed.value !== signature.value &&
    !onDashboard.value,
);

function dismiss() {
  dismissed.value = signature.value;
  try {
    sessionStorage.setItem(storageKey(), signature.value);
  } catch {
    /* ignore: the banner just reappears next load */
  }
}
</script>

<template>
  <div v-if="visible" class="alert alert-danger shadow-sm mb-3" role="alert">
    <div class="d-flex justify-content-between align-items-start gap-3">
      <div>
        <strong>
          {{ items.length }} item{{ items.length === 1 ? "" : "s" }}
          {{ items.length === 1 ? "has" : "have" }} reached Critical stock.
        </strong>
        <span class="ms-1">No restock request has been made yet.</span>
        <button
          type="button"
          class="btn btn-link btn-sm p-0 ms-2 align-baseline"
          @click="expanded = !expanded"
        >
          {{ expanded ? "Hide details" : "View details" }}
        </button>
        <button
          v-if="!onDashboard"
          type="button"
          class="btn btn-danger btn-sm ms-2"
          @click="router.push('/dashboard')"
        >
          Review restocking
        </button>
      </div>
      <button
        type="button"
        class="btn-close"
        aria-label="Dismiss"
        @click="dismiss"
      ></button>
    </div>

    <div v-if="expanded" class="table-responsive mt-3">
      <table class="table table-sm table-borderless align-middle mb-0 critical-table">
        <thead>
          <tr>
            <th>Item</th>
            <th>Category</th>
            <th>Qty</th>
            <th>Critical at</th>
            <th>Suggested restock</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="i in items" :key="i.iditems">
            <td>{{ i.item_name }}</td>
            <td>{{ i.category }}</td>
            <td>
              <span v-if="i.quantity <= 0" class="badge bg-dark">Out of stock</span>
              <template v-else>{{ i.quantity }}</template>
            </td>
            <td>≤ {{ i.critical_stock }}</td>
            <td>+{{ i.suggested_quantity }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<style scoped>
.critical-table {
  background: transparent;
  --bs-table-bg: transparent;
}
.critical-table th {
  font-size: 0.8rem;
  text-transform: uppercase;
  opacity: 0.75;
}
</style>
