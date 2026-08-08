<script setup>
import { ref } from 'vue';
import { askQuestion } from '../api';
import LoadingSpinner from './LoadingSpinner.vue';

const question = ref('');

const loading = ref(false);
const result = ref(null);
const fieldErrors = ref({});
const errorMessage = ref('');

async function handleSubmit() {
    fieldErrors.value = {};
    errorMessage.value = '';
    result.value = null;
    loading.value = true;

    try {
        result.value = await askQuestion({ question: question.value });
    } catch (error) {
        if (error.status === 422 && error.body?.errors) {
            fieldErrors.value = error.body.errors;
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
                <label for="question" class="block text-sm font-medium text-gray-700">Question</label>
                <textarea
                    id="question"
                    v-model="question"
                    rows="3"
                    maxlength="2000"
                    class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm transition-colors focus:border-gray-500 focus:ring-1 focus:ring-gray-500 focus:outline-none"
                    placeholder="What status code do I get if the Groq request times out?"
                ></textarea>
                <p v-if="fieldErrors.question" class="mt-1 text-sm text-red-600">{{ fieldErrors.question[0] }}</p>
            </div>

            <button
                type="submit"
                :disabled="loading"
                class="inline-flex items-center gap-2 rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-gray-700 disabled:cursor-not-allowed disabled:opacity-50"
            >
                <LoadingSpinner v-if="loading" />
                {{ loading ? 'Asking...' : 'Ask' }}
            </button>
        </form>

        <p v-if="errorMessage" class="mt-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">
            {{ errorMessage }}
        </p>

        <div v-if="result" class="mt-6 rounded-md border border-gray-200 p-4">
            <p class="text-sm text-gray-900">{{ result.answer }}</p>

            <div v-if="result.sources.length" class="mt-4 border-t border-gray-100 pt-3">
                <p class="text-xs font-medium tracking-wide text-gray-400 uppercase">Sources</p>
                <ul class="mt-2 space-y-1">
                    <li
                        v-for="(source, index) in result.sources"
                        :key="`${source.source}-${source.chunk_index}`"
                        class="flex justify-between text-xs text-gray-500"
                    >
                        <span>{{ index + 1 }}. {{ source.source }} (chunk {{ source.chunk_index }})</span>
                        <span>distance {{ source.distance }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>
