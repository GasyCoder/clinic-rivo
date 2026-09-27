import { Extension } from '@tiptap/core';

// TipTap core ships no official font-size extension: this rides on
// TextStyle (already loaded for color/font-family) exactly the way the
// TipTap docs recommend implementing one.
export const FontSize = Extension.create({
    name: 'fontSize',

    addOptions() {
        return { types: ['textStyle'] };
    },

    addGlobalAttributes() {
        return [{
            types: this.options.types,
            attributes: {
                fontSize: {
                    default: null,
                    parseHTML: (element) => element.style.fontSize || null,
                    renderHTML: (attributes) => (attributes.fontSize
                        ? { style: `font-size: ${attributes.fontSize}` }
                        : {}),
                },
            },
        }];
    },

    addCommands() {
        return {
            setFontSize: (size) => ({ chain }) => chain().setMark('textStyle', { fontSize: size }).run(),
            unsetFontSize: () => ({ chain }) => chain()
                .setMark('textStyle', { fontSize: null })
                .removeEmptyTextStyle()
                .run(),
        };
    },
});
