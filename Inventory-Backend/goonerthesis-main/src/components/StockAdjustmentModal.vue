<script setup>
import { ref, watch } from "vue";
import { adjustStock } from "../services/items";

const props = defineProps({
  show: { type: Boolean, default: false },
  itemId: { type: [Number, String], default: null },
  itemName: { type: String, default: "" },
  available: { type: Number, default: 0 },
});
const emit = defineEmits(["close", "done"]);

const REASONS = ["Missing", "Lost", "Stolen", "Other"];

const qty = ref(1);
const reason = ref("Missing");
const notes = ref("");
const error = ref("");
const confirming = ref(false);
const saving = ref(false);

watch(
  () => props.show,
  (open) => {
    if (open) {
      qty.value = 1;
      reason.value = "Missing";
      notes.value = "";
      error.value = "";
      confirming.value = false;
      saving.value = false;
    }
  },
);

function validate() {
  const n = Number(qty.value);
  if (!Number.isInteger(n) || n < 1) return "Quantity must be at least 1.";
  if (n > props.available)
    return `Only ${props.available} available. You can't write off more than the available stock.`;
  if (reason.value === "Other" && !notes.value.trim())
    return "Please describe what happened (required for 'Other').";
  return "";
}

function next() {
  error.value = validate();
  if (!error.value) confirming.value = true;
}

async function submit() {
  saving.value = true;
  error.value = "";
  try {
    await adjustStock(
      props.itemId,
      Number(qty.value),
      reason.value,
      notes.value.trim(),
    );
    emit("done");
    emit("close");
  } catch (e) {
    confirming.value = false;
    error.value =
      e.response?.data?.message || e.message || "Unable to adjust stock.";
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <div v-if="show" class="adj-backdrop">
    <div class="adj-modal">
      <div class="adj-header">
        <h5 class="mb-0">Report Missing: {{ itemName }}</h5>
        <button class="btn-close" @click="emit('close')"></button>
      </div>

      <div class="adj-body">
        <template v-if="!confirming">
          <div class="mb-3">
            <label class="form-label">Available stock</label>
            <input class="form-control" :value="available" disabled />
          </div>

          <div class="mb-3">
            <label class="form-label">Quantity missing</label>
            <input
              type="number"
              min="1"
              :max="available"
              class="form-control"
              v-model="qty"
            />
          </div>

          <div class="mb-3">
            <label class="form-label">Reason</label>
            <select class="form-select" v-model="reason">
              <option v-for="r in REASONS" :key="r" :value="r">{{ r }}</option>
            </select>
          </div>

          <div class="mb-1">
            <label class="form-label">
              Notes
              <span v-if="reason !== 'Other'" class="text-muted">(optional)</span>
            </label>
            <textarea
              class="form-control"
              rows="2"
              maxlength="255"
              v-model="notes"
              placeholder="What happened? e.g. not found during inventory count"
            ></textarea>
          </div>
        </template>

        <div v-else class="alert alert-warning mb-0">
          <h6 class="mb-2">Confirm stock adjustment</h6>
          Remove <strong>{{ qty }}</strong> of <strong>{{ itemName }}</strong>
          from stock as <strong>{{ reason }}</strong>?
          <div class="small mt-2">
            Stock will go from {{ available }} to
            {{ available - Number(qty) }}. This is recorded in the Activity Logs.
          </div>
        </div>

        <div v-if="error" class="alert alert-danger py-2 mt-3 mb-0">
          {{ error }}
        </div>
      </div>

      <div class="adj-footer">
        <button
          class="btn btn-secondary"
          :disabled="saving"
          @click="confirming ? (confirming = false) : emit('close')"
        >
          {{ confirming ? "Back" : "Cancel" }}
        </button>
        <button v-if="!confirming" class="btn btn-primary" @click="next">
          Continue
        </button>
        <button
          v-else
          class="btn btn-danger"
          :disabled="saving"
          @click="submit"
        >
          {{ saving ? "Saving..." : "Confirm" }}
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.adj-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.35);
  display: grid;
  place-items: center;
  z-index: 2100;
}
.adj-modal {
  background: #fff;
  width: 100%;
  max-width: 480px;
  border-radius: 12px;
  overflow: hidden;
}
.adj-header,
.adj-footer {
  padding: 12px 16px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.adj-body {
  padding: 16px;
}
</style>
