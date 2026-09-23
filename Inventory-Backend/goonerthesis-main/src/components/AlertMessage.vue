<template>
  <Transition name="fade">
  <div
    v-if="visible"
    class="alert-wrapper"
  >
      <div
      :class="[
        'alert',
        alertClass,
        'alert-dismissible',
        'shadow'
      ]"
    >

        <i :class="iconClass" class="me-2 fs-5"></i>

        <div class="flex-grow-1">
          {{ message }}
        </div>

        <button
          type="button"
          class="btn-close"
          @click="close"
        ></button>

      </div>
    </div>
  </Transition>
</template>

<script setup>
import { computed, ref, watch } from "vue";

const props = defineProps({
  show: Boolean,

  message: {
    type: String,
    default: "",
  },

  type: {
    type: String,
    default: "success",
  },

  duration: {
    type: Number,
    default: 5000,
  },
});

const emit = defineEmits(["update:show"]);

const visible = ref(false);

let timer = null;

watch(
  () => props.show,
  (value) => {
    visible.value = value;

    if (timer) {
      clearTimeout(timer);
    }

    if (value) {
      timer = setTimeout(() => {
        close();
      }, props.duration);
    }
  },
  {
    immediate: true,
  }
);

function close() {
  visible.value = false;
  emit("update:show", false);
}

const alertClass = computed(() => {
  switch (props.type) {
    case "success":
      return "alert-success";

    case "danger":
      return "alert-danger";

    case "warning":
      return "alert-warning";

    case "info":
      return "alert-info";

    default:
      return "alert-secondary";
  }
});

const iconClass = computed(() => {
  switch (props.type) {
    case "success":
      return "bi bi-check-circle-fill";

    case "danger":
      return "bi bi-x-circle-fill";

    case "warning":
      return "bi bi-exclamation-triangle-fill";

    case "info":
      return "bi bi-info-circle-fill";

    default:
      return "bi bi-bell-fill";
  }
});
</script>

<style scoped>
.alert-wrapper{
    position:fixed;

    top:20px;
    left: 50%;
    transform: translateX(-50%);


    width:380px;

    z-index:9999;

    pointer-events:none;
}

.alert{
    pointer-events:auto;
}

.fade-enter-active,
.fade-leave-active {
  transition: all .25s ease;
}

.fade-enter-from,
.fade-leave-to {
    opacity: 0;
    transform: translate(-50%, -20px);
}

.fade-enter-to,
.fade-leave-from {
    opacity: 1;
    transform: translate(-50%, 0);
}
</style>