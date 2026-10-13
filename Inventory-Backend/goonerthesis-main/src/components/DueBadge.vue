<script setup>
import { computed } from "vue";

// Due date / overdue badge for a borrowed line (roadmap #8).
// dueDate: "YYYY-MM-DD" or empty. overdue/daysOverdue come from the server.
const props = defineProps({
  dueDate: { type: String, default: "" },
  overdue: { type: Boolean, default: false },
  daysOverdue: { type: Number, default: 0 },
});

function parseLocal(ymd) {
  const [y, m, d] = String(ymd).slice(0, 10).split("-").map(Number);
  return new Date(y, m - 1, d);
}

const pretty = computed(() =>
  props.dueDate
    ? parseLocal(props.dueDate).toLocaleDateString("en-PH", {
        year: "numeric",
        month: "short",
        day: "numeric",
      })
    : "",
);

// Whole days from today until the due date (0 = due today).
const daysLeft = computed(() => {
  if (!props.dueDate) return null;
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  return Math.round((parseLocal(props.dueDate) - today) / 86400000);
});

const label = computed(() => {
  if (props.overdue) {
    return `Overdue ${props.daysOverdue} day${props.daysOverdue === 1 ? "" : "s"}`;
  }
  if (daysLeft.value === 0) return "Due today";
  if (daysLeft.value === 1) return "Due tomorrow";
  return `Due ${pretty.value}`;
});

const badgeClass = computed(() => {
  if (props.overdue) return "bg-danger";
  if (daysLeft.value !== null && daysLeft.value <= 1) return "bg-warning text-dark";
  return "bg-light text-dark border";
});
</script>

<template>
  <span v-if="!dueDate" class="text-muted small">No due date</span>
  <span v-else class="badge" :class="badgeClass" :title="`Due ${pretty}`">
    {{ label }}
  </span>
</template>
