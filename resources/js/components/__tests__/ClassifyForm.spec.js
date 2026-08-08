import { describe, it, expect, vi, beforeEach } from 'vitest';
import { nextTick } from 'vue';
import { mount, flushPromises } from '@vue/test-utils';
import ClassifyForm from '../ClassifyForm.vue';
import { classifyTicket } from '../../api';

vi.mock('../../api', () => ({
    classifyTicket: vi.fn(),
}));

async function submit(wrapper) {
    await wrapper.find('#title').setValue('App crashes on login');
    await wrapper.find('#description').setValue('Tapping Sign in closes the app immediately.');
    await wrapper.find('form').trigger('submit.prevent');
    await flushPromises();
}

describe('ClassifyForm', () => {
    beforeEach(() => {
        classifyTicket.mockReset();
    });

    it('renders the classification result on success', async () => {
        classifyTicket.mockResolvedValue({
            category: 'bug',
            confidence: 0.97,
            reasoning: 'Describes a crash, a concrete broken behavior.',
        });

        const wrapper = mount(ClassifyForm);
        await submit(wrapper);

        expect(wrapper.text()).toContain('bug');
        expect(wrapper.text()).toContain('97%');
        expect(wrapper.text()).toContain('Describes a crash, a concrete broken behavior.');
    });

    it('shows field-level errors on a 422 validation response', async () => {
        const error = new Error('The title field is required.');
        error.status = 422;
        error.body = {
            errors: {
                title: ['The title field is required.'],
                description: ['The description field is required.'],
            },
        };
        classifyTicket.mockRejectedValue(error);

        const wrapper = mount(ClassifyForm);
        await wrapper.find('form').trigger('submit.prevent');
        await flushPromises();

        expect(wrapper.text()).toContain('The title field is required.');
        expect(wrapper.text()).toContain('The description field is required.');
    });

    it('shows a rate-limit message on a 429 response', async () => {
        const error = new Error('Too Many Attempts.');
        error.status = 429;
        error.body = null;
        classifyTicket.mockRejectedValue(error);

        const wrapper = mount(ClassifyForm);
        await submit(wrapper);

        expect(wrapper.text()).toContain('Too many requests');
    });

    it('shows the backend error message on a service failure', async () => {
        const error = new Error('fallback message');
        error.status = 502;
        error.body = { error: 'The classification service is unavailable. Please try again later.' };
        classifyTicket.mockRejectedValue(error);

        const wrapper = mount(ClassifyForm);
        await submit(wrapper);

        expect(wrapper.text()).toContain('The classification service is unavailable. Please try again later.');
    });

    it('disables the submit button and shows a spinner while the request is in flight', async () => {
        let resolvePromise;
        classifyTicket.mockReturnValue(
            new Promise((resolve) => {
                resolvePromise = resolve;
            }),
        );

        const wrapper = mount(ClassifyForm);
        const submitPromise = wrapper.find('form').trigger('submit.prevent');
        await nextTick();

        expect(wrapper.find('button').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('Classifying...');

        resolvePromise({ category: 'other', confidence: 0.5, reasoning: 'x' });
        await submitPromise;
        await flushPromises();

        expect(wrapper.find('button').attributes('disabled')).toBeUndefined();
    });
});
