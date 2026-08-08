<script setup>
import { ref } from 'vue';
import { classifyTicket } from '../api';
import LoadingSpinner from './LoadingSpinner.vue';

const title = ref('');
const description = ref('');

const loading = ref(false);
const result = ref(null);
const fieldErrors = ref({});
const errorMessage = ref('');

const categoryStyles = {
    bug: 'bg-red-100 text-red-800',
    'feature-request': 'bg-blue-100 text-blue-800',
    documentation: 'bg-purple-100 text-purple-800',
    other: 'bg-gray-100 text-gray-800',
};

async function handleSubmit() {
    fieldErrors.value = {};
    errorMessage.value = '';
    result.value = null;
    loading.value = true;

    try {
        result.value = await classifyTicket({ title: title.value, description: description.value });
    } catch (error) {
        if (error.status === 422 && error.body?.errors) {
            fieldErrors.value = error.body.errors;
        } else if (error.status === 429) {
            errorMessage.value = 'Too many requests — please wait a minute and try again.';
        } else {
            errorMessage.value = error.body?.error ?? error.message;
        }
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <div>
        <form class="space-y-4" @submit.prevent="handleSubmit">
            <div>
                <label for="title" class="block text-sm font-medium text-gray-700">Title</label>
                <input
                    id="title"
                    v-model="title"
                    type="text"
                    maxlength="255"
                    class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm transition-colors focus:border-gray-500 focus:ring-1 focus:ring-gray-500 focus:outline-none"
                    placeholder="App crashes on login"
                />
                <p v-if="fieldErrors.title" class="mt-1 text-sm text-red-600">{{ fieldErrors.title[0] }}</p>
            </div>

            <div>
                <label for="description" class="block text-sm font-medium text-gray-700">Description</label>
                <textarea
                    id="description"
                    v-model="description"
                    rows="4"
                    maxlength="5000"
                    class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm transition-colors focus:border-gray-500 focus:ring-1 focus:ring-gray-500 focus:outline-none"
                    placeholder="Tapping Sign in closes the app immediately on iOS 17."
                ></textarea>
                <p v-if="fieldErrors.description" class="mt-1 text-sm text-red-600">{{ fieldErrors.description[0] }}</p>
            </div>

            <button
                type="submit"
                :disabled="loading"
                class="inline-flex items-center gap-2 rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-gray-700 disabled:cursor-not-allowed disabled:opacity-50"
            >
                <LoadingSpinner v-if="loading" />
                {{ loading ? 'Classifying...' : 'Classify' }}
            </button>
        </form>

        <p v-if="errorMessage" class="mt-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">
            {{ errorMessage }}
        </p>

        <div v-if="result" class="mt-6 rounded-md border border-gray-200 p-4">
            <span class="inline-block rounded-full px-3 py-1 text-sm font-medium" :class="categoryStyles[result.category]">
                {{ result.category }}
            </span>
            <p class="mt-2 text-sm text-gray-500">
                Confidence: {{ Math.round(result.confidence * 100) }}%
            </p>
            <p class="mt-2 text-sm text-gray-700">{{ result.reasoning }}</p>
        </div>
    </div>
</template>
