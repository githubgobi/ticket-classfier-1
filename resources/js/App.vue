<script setup>
import { ref } from 'vue';
import ClassifyForm from './components/ClassifyForm.vue';

const activeTab = ref('classify');
</script>

<template>
    <div class="min-h-screen bg-gray-50 text-gray-900">
        <header class="border-b border-gray-200 bg-white">
            <div class="mx-auto max-w-3xl px-4 py-4">
                <h1 class="text-lg font-semibold">Ticket Classifier</h1>
            </div>
        </header>

        <main class="mx-auto max-w-3xl px-4 py-6">
            <nav class="mb-6 flex gap-2 border-b border-gray-200">
                <button
                    type="button"
                    class="px-3 py-2 text-sm font-medium"
                    :class="activeTab === 'classify' ? 'border-b-2 border-gray-900 text-gray-900' : 'text-gray-500'"
                    @click="activeTab = 'classify'"
                >
                    Classify
                </button>
                <button
                    type="button"
                    class="px-3 py-2 text-sm font-medium"
                    :class="activeTab === 'ask' ? 'border-b-2 border-gray-900 text-gray-900' : 'text-gray-500'"
                    @click="activeTab = 'ask'"
                >
                    Ask
                </button>
            </nav>

            <ClassifyForm v-if="activeTab === 'classify'" />
            <p v-else class="text-sm text-gray-500">
                Ask screen — coming in Phase 3.
            </p>
        </main>
    </div>
</template>
