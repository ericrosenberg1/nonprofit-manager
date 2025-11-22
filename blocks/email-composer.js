// blocks/email-composer.js
const { __ } = wp.i18n;
const { registerBlockType } = wp.blocks;
const { TextControl, TextareaControl } = wp.components;
const { useBlockProps } = wp.blockEditor;

registerBlockType('nonprofit-manager/email-composer', {
    title: __('Email Composer', 'nonprofit-manager'),
    icon: 'email',
    category: 'widgets',
    attributes: {
        subject: { type: 'string' },
        recipient: { type: 'string' },
        content: { type: 'string' }
    },
    edit: ({ attributes, setAttributes }) => {
        const blockProps = useBlockProps();

        return (
            <div {...blockProps}>
                <TextControl
                    label="Email Subject"
                    value={attributes.subject}
                    onChange={(value) => setAttributes({ subject: value })}
                />
                <TextControl
                    label="Recipient Email"
                    value={attributes.recipient}
                    onChange={(value) => setAttributes({ recipient: value })}
                />
                <TextareaControl
                    label="Email Body"
                    value={attributes.content}
                    onChange={(value) => setAttributes({ content: value })}
                    rows={6}
                />
            </div>
        );
    },
    save: () => null // Server-rendered via PHP
});
