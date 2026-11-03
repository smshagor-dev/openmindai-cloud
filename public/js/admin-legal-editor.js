"use strict";

for (const form of document.querySelectorAll("[data-legal-editor]")) {
    const source = form.querySelector("[data-legal-body]");
    const fallback = form.querySelector("[data-legal-editor-fallback]");
    if (!source || !fallback) continue;

    const ckeditor = window.CKEDITOR;
    if (!ckeditor?.ClassicEditor || !ckeditor.Markdown) {
        fallback.hidden = false;
        continue;
    }

    const {
        ClassicEditor, Essentials, Paragraph, Heading, Bold, Italic,
        Link, List, BlockQuote, Markdown, SourceEditing, Autoformat, Table, TableToolbar,
    } = ckeditor;
    ClassicEditor.create({
        attachTo: source,
        licenseKey: form.dataset.legalEditor,
        plugins: [
            Essentials, Paragraph, Heading, Bold, Italic, Link, List,
            BlockQuote, Markdown, SourceEditing, Autoformat, Table, TableToolbar,
        ],
        toolbar: [
            "undo", "redo", "|", "heading", "|", "bold", "italic", "link",
            "bulletedList", "numberedList", "blockQuote", "insertTable", "|", "sourceEditing",
        ],
        table: { contentToolbar: ["tableColumn", "tableRow", "mergeTableCells"] },
    }).then(editor => {
        source.required = false;
        form.addEventListener("submit", () => {
            source.value = editor.getData();
        });
    }).catch(() => {
        fallback.hidden = false;
    });
}
