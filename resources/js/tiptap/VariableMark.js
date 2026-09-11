import { Mark, mergeAttributes } from '@tiptap/core';

// Applied automatically (and only) when a variable is inserted via the
// "Variables disponibles" panel — never when the Super Admin types
// `{{code}}` by hand — so a glance at the document shows exactly which
// tokens are real, clickable-inserted variables versus plain typed text.
export const VariableMark = Mark.create({
    name: 'variableToken',

    parseHTML() {
        return [{ tag: 'span[data-variable-token]' }];
    },

    renderHTML({ HTMLAttributes }) {
        return ['span', mergeAttributes(HTMLAttributes, { 'data-variable-token': '', class: 'canevas-variable-token' }), 0];
    },
});
