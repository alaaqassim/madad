<script setup>
import { ref, computed } from 'vue';

// Minimal reactivity proof only. Not part of the competition UI.
const clicks = ref(0);
const status = computed(() =>
    clicks.value === 0 ? 'Vue mounted — click to prove reactivity' : `Reactive: ${clicks.value}`
);
</script>

<template>
    <div class="rounded border p-6">
        <h1 class="text-xl font-semibold">Madad — foundation check</h1>
        <p class="mt-2">{{ status }}</p>
        <button type="button" class="mt-4 rounded border px-3 py-1" @click="clicks++">
            Increment
        </button>
    </div>
</template>
