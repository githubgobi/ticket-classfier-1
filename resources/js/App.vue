<script setup>
import { ref } from 'vue';
import ClassifyForm from './components/ClassifyForm.vue';
import AskForm from './components/AskForm.vue';

const tabs = [
    { id: 'classify', label: 'Classify' },
    { id: 'ask', label: 'Ask' },
];

const activeTab = ref('classify');
</script>

<template>
    <div class="min-h-screen bg-gray-50 text-gray-900">
        <header class="border-b border-gray-200 bg-white">
            <div class="mx-auto max-w-3xl px-4 py-5 sm:px-6">
                <h1 class="text-lg font-semibold">Ticket Classifier</h1>
                <p class="mt-1 text-sm text-gray-500">
                    Classify a support ticket, or ask a question about the ingested docs.
                </p>
            </div>
        </header>

        <main class="mx-auto max-w-3xl px-4 py-6 sm:px-6">
            <nav class="mb-6 flex gap-1 border-b border-gray-200">
                <button
                    v-for="tab in tabs"
                    :key="tab.id"
                    type="button"
                    class="px-3 py-2 text-sm font-medium transition-colors"
                    :class="activeTab === tab.id ? 'border-b-2 border-gray-900 text-gray-900' : 'text-gray-500 hover:text-gray-700'"
                    @click="activeTab = tab.id"
                >
                    {{ tab.label }}
                </button>
            </nav>

            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:p-6">
                <ClassifyForm v-if="activeTab === 'classify'" />
                <AskForm v-else />
            </div>
        </main>
    </div>
</template>
