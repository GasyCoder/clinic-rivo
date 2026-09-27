import { Extension } from '@tiptap/core';

// Line-height, spacing before/after and indentation all end up as inline
// CSS on the same paragraph/heading element. A single raw `style` attribute
// (rather than one attribute per property) sidesteps a real TipTap gotcha:
// several attributes each returning their own `{ style: ... }` from
// renderHTML do not merge — the last one silently wins. Read/parse/write is
// done once, from the toolbar helpers in the Editor page.
export const BlockStyle = Extension.create({
    name: 'blockStyle',

    addOptions() {
        return { types: ['paragraph', 'heading'] };
    },

    addGlobalAttributes() {
        return [{
            types: this.options.types,
            attributes: {
                style: {
                    default: null,
                    parseHTML: (element) => element.getAttribute('style'),
                    renderHTML: (attributes) => (attributes.style ? { style: attributes.style } : {}),
                },
            },
        }];
    },
});

/** @return {Record<string, string>} */
export function parseStyle(style) {
    return Object.fromEntries(
        (style ?? '')
            .split(';')
            .map((declaration) => declaration.split(':').map((part) => part?.trim()))
            .filter(([property, value]) => property && value),
    );
}

/** @param {Record<string, string>} declarations */
export function stringifyStyle(declarations) {
    return Object.entries(declarations)
        .filter(([, value]) => value !== null && value !== undefined && value !== '')
        .map(([property, value]) => `${property}: ${value}`)
        .join('; ');
}
