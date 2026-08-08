import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import AskForm from '../AskForm.vue';
import { askQuestion } from '../../api';

vi.mock('../../api', () => ({
    askQuestion: vi.fn(),
}));

async function submit(wrapper, question = 'What status code do I get if the Groq request times out?') {
    await wrapper.find('#question').setValue(question);
    await wrapper.find('form').trigger('submit.prevent');
    await flushPromises();
}

describe('AskForm', () => {
    beforeEach(() => {
        askQuestion.mockReset();
    });

    it('renders the answer and its sources on success', async () => {
        askQuestion.mockResolvedValue({
            answer: '504',
            sources: [
                { source: 'ticket-classifier-api-doc.txt', chunk_index: 4, distance: 0.2703 },
                { source: 'ticket-classifier-api-doc.txt', chunk_index: 2, distance: 0.3564 },
            ],
        });

        const wrapper = mount(AskForm);
        await submit(wrapper);

        expect(wrapper.text()).toContain('504');
        expect(wrapper.text()).toContain('ticket-classifier-api-doc.txt');
        expect(wrapper.text()).toContain('chunk 4');
        expect(wrapper.text()).toContain('distance 0.2703');
    });

    it('does not render a sources section when none are returned', async () => {
        askQuestion.mockResolvedValue({
            answer: "I don't have enough information to answer that.",
            sources: [],
        });

        const wrapper = mount(AskForm);
        await submit(wrapper);

        expect(wrapper.text()).toContain("I don't have enough information to answer that.");
        expect(wrapper.text()).not.toContain('Sources');
    });

    it('shows a field-level error on a 422 validation response', async () => {
        const error = new Error('The question field is required.');
        error.status = 422;
        error.body = { errors: { question: ['The question field is required.'] } };
        askQuestion.mockRejectedValue(error);

        const wrapper = mount(AskForm);
        await wrapper.find('form').trigger('submit.prevent');
        await flushPromises();

        expect(wrapper.text()).toContain('The question field is required.');
    });

    it('shows the backend error message on a service failure', async () => {
        const error = new Error('fallback message');
        error.status = 502;
        error.body = { error: 'The embedding service is unavailable. Please try again later.' };
        askQuestion.mockRejectedValue(error);

        const wrapper = mount(AskForm);
        await submit(wrapper);

        expect(wrapper.text()).toContain('The embedding service is unavailable. Please try again later.');
    });
});
