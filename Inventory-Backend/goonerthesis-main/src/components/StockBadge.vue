<script setup>
import { computed } from "vue";

// Colour-coded badge for an item's stock level (roadmap #6).
// level: "Out of Stock" | "Critical" | "Minimum" | "Adequate" | "Optimal"
const props = defineProps({
  level: { type: String, default: "" },
});

const badgeClass = computed(() => {
  switch (props.level) {
    case "Out of Stock":
      return "bg-dark";
    case "Critical":
      return "bg-danger";
    case "Minimum":
      return "bg-warning text-dark";
    case "Adequate":
      return "bg-info text-dark";
    case "Optimal":
      return "bg-success";
    case "Deployed":
      return "bg-primary";
    default:
      return "bg-secondary";
  }
});
</script>

<template>
  <span class="badge" :class="badgeClass">{{ level || "-" }}</span>
</template>
