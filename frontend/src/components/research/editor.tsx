"use client";
import { useEditor, EditorContent } from "@tiptap/react";
import StarterKit from "@tiptap/starter-kit";
import Heading from "@tiptap/extension-heading";
import {
  Table,
  TableRow,
  TableHeader,
  TableCell,
} from "@tiptap/extension-table";
import { Node, mergeAttributes, JSONContent } from "@tiptap/core";
import { useEffect, useRef, useState } from "react";
import katex from "katex";
import "katex/dist/katex.min.css";
import { api, Row, citation, bibliography, loadStyle } from "@/lib/research";
const AcademicHeading = Heading.extend({
  addAttributes() {
    return { ...this.parent?.(), id: { default: null } };
  },
});
const AcademicTable = Table.extend({
  addAttributes() {
    return {
      ...this.parent?.(),
      id: { default: null },
      caption: { default: "" },
    };
  },
});
const Citation = Node.create({
  name: "citation",
  inline: true,
  group: "inline",
  atom: true,
  addAttributes() {
    return { sourceId: { default: null } };
  },
  parseHTML() {
    return [{ tag: "span[data-citation]" }];
  },
  renderHTML({ HTMLAttributes }) {
    return [
      "span",
      mergeAttributes(HTMLAttributes, {
        "data-citation": HTMLAttributes.sourceId,
        class: "rs-citation",
      }),
      "[source " + HTMLAttributes.sourceId + "]",
    ];
  },
});
const MathNode = Node.create({
  name: "math",
  inline: true,
  group: "inline",
  atom: true,
  addAttributes() {
    return { latex: { default: "" }, id: { default: null } };
  },
  parseHTML() {
    return [{ tag: "span[data-equation]" }];
  },
  renderHTML({ HTMLAttributes }) {
    return [
      "span",
      mergeAttributes(HTMLAttributes, { "data-equation": "true" }),
      HTMLAttributes.latex,
    ];
  },
  addNodeView() {
    return ({ node, editor, getPos }) => {
      const dom = document.createElement("span");
      dom.className = "rs-equation";
      dom.innerHTML = katex.renderToString(node.attrs.latex, {
        throwOnError: false,
        trust: false,
        strict: "warn",
      });
      const number = document.createElement("small");
      dom.appendChild(number);
      const refresh = () => {
        let index = 0;
        editor.state.doc.descendants((n, position) => {
          if (n.type.name === "math") {
            index++;
            if (n.attrs.id === node.attrs.id)
              number.textContent = " (" + index + ")";
          }
        });
      };
      refresh();
      editor.on("transaction", refresh);
      return {
        dom,
        destroy() {
          editor.off("transaction", refresh);
        },
      };
    };
  },
});
const Footnote = Node.create({
  name: "footnote",
  inline: true,
  group: "inline",
  atom: true,
  addAttributes() {
    return { text: { default: "" } };
  },
  parseHTML() {
    return [{ tag: "sup[data-footnote]" }];
  },
  renderHTML({ HTMLAttributes }) {
    return [
      "sup",
      mergeAttributes(HTMLAttributes, {
        "data-footnote": "true",
        title: HTMLAttributes.text,
      }),
      "†",
    ];
  },
});
const Figure = Node.create({
  name: "figure",
  group: "block",
  atom: true,
  addOptions() {
    return { projectId: 0 };
  },
  addAttributes() {
    return {
      artifactId: { default: null },
      caption: { default: "" },
      id: { default: "" },
    };
  },
  parseHTML() {
    return [{ tag: "figure[data-artifact]" }];
  },
  renderHTML({ HTMLAttributes }) {
    return [
      "figure",
      mergeAttributes(HTMLAttributes, {
        "data-artifact": HTMLAttributes.artifactId,
      }),
      [
        "img",
        {
          src:
            "/api/backend/research/projects/" +
            this.options.projectId +
            "/artifacts/" +
            HTMLAttributes.artifactId +
            "/download",
          alt: HTMLAttributes.caption || "Research figure",
        },
      ],
      ["figcaption", {}, HTMLAttributes.caption || "Figure"],
    ];
  },
});
const CrossReference = Node.create({
  name: "crossReference",
  inline: true,
  group: "inline",
  atom: true,
  addAttributes() {
    return { target: { default: "" } };
  },
  parseHTML() {
    return [{ tag: "span[data-cross-reference]" }];
  },
  renderHTML({ HTMLAttributes }) {
    return [
      "span",
      mergeAttributes(HTMLAttributes, {
        "data-cross-reference": HTMLAttributes.target,
      }),
      "Reference",
    ];
  },
  addNodeView() {
    return ({ node, editor }) => {
      const dom = document.createElement("span");
      dom.className = "rs-citation";
      const refresh = () => {
        const counts: Record<string, number> = {};
        let text = "Unresolved reference";
        editor.state.doc.descendants((n) => {
          const type = n.type.name;
          counts[type] = (counts[type] || 0) + 1;
          if (n.attrs.id === node.attrs.target)
            text =
              type === "heading"
                ? n.textContent
                : ({ figure: "Figure", table: "Table", math: "Equation" }[
                    type as "figure"
                  ] || "Reference") +
                  " " +
                  counts[type];
        });
        dom.textContent = text;
      };
      refresh();
      editor.on("transaction", refresh);
      return {
        dom,
        destroy() {
          editor.off("transaction", refresh);
        },
      };
    };
  },
});
type Props = {
  projectId: number;
  document: Row;
  sources: Row[];
  artifacts: Row[];
  style: string;
  readonly: boolean;
  onSaved: (d: Row) => void;
  onDirty: (dirty: boolean) => void;
  onSelection: (text: string) => void;
};
export default function AcademicEditor({
  projectId,
  document: doc,
  sources,
  artifacts,
  style,
  readonly,
  onSaved,
  onDirty,
  onSelection,
}: Props) {
  const [editTick, setEditTick] = useState(0);
  const [status, setStatus] = useState("Saved");
  const [dirty, setDirty] = useState(false);
  const [search, setSearch] = useState("");
  const [source, setSource] = useState("");
  const [latex, setLatex] = useState("E=mc^2");
  const [note, setNote] = useState("");
  const [caption, setCaption] = useState("");
  const [figure, setFigure] = useState("");
  const [target, setTarget] = useState("");
  const [bib, setBib] = useState("");
  const [styleReady, setStyleReady] = useState(false);
  const [outline, setOutline] = useState<Row[]>([]);
  const version = useRef(doc.version);
  const dirtyRef = useRef(false);
  const saving = useRef(false);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const docRef = useRef(doc);
  docRef.current = doc;
  const handlers = useRef({ onSaved, onDirty, onSelection });
  handlers.current = { onSaved, onDirty, onSelection };
  const editor = useEditor({
    immediatelyRender: false,
    extensions: [
      StarterKit.configure({ heading: false }),
      AcademicHeading,
      AcademicTable.configure({ resizable: true }),
      TableRow,
      TableCell,
      TableHeader,
      Citation,
      MathNode,
      Footnote,
      Figure.configure({ projectId }),
      CrossReference,
    ],
    content: doc.content as JSONContent,
    editable: !readonly,
    editorProps: {
      attributes: {
        role: "textbox",
        "aria-multiline": "true",
        "aria-label": "Academic manuscript",
        class: "rs-prose",
        dir: "auto",
      },
    },
    onUpdate: ({ editor }) => {
      const transaction = editor.state.tr;
      let normalize = false;
      editor.state.doc.descendants((node, pos) => {
        if (
          ["heading", "table", "figure", "math"].includes(node.type.name) &&
          !node.attrs.id
        ) {
          transaction.setNodeMarkup(pos, undefined, {
            ...node.attrs,
            id: node.type.name + "-" + crypto.randomUUID(),
          });
          normalize = true;
        }
      });
      if (normalize) {
        editor.view.dispatch(transaction.setMeta("addToHistory", false));
        return;
      }
      dirtyRef.current = true;
      setDirty(true);
      setEditTick((n) => n + 1);
      setStatus("Unsaved");
      handlers.current.onDirty(true);
    },
    onSelectionUpdate: ({ editor }) => {
      handlers.current.onSelection(
        editor.state.doc.textBetween(
          editor.state.selection.from,
          editor.state.selection.to,
          " ",
          (node) =>
            node.type.name === "citation"
              ? "[@s" + node.attrs.sourceId + "]"
              : node.type.name === "math"
                ? "$" + node.attrs.latex + "$"
                : node.type.name === "footnote"
                  ? "[footnote:" + node.attrs.text + "]"
                  : "",
        ),
      );
    },
  });
  const save = async () => {
    if (!editor || !dirtyRef.current || saving.current || readonly) return;
    saving.current = true;
    setStatus("Saving…");
    const snapshot = editor.getJSON();
    try {
      const d = await api(`projects/${projectId}/document`, "PUT", {
        content: snapshot,
        version: version.current,
      });
      version.current = d.version;
      handlers.current.onSaved(d);
      if (JSON.stringify(editor.getJSON()) === JSON.stringify(snapshot)) {
        dirtyRef.current = false;
        setDirty(false);
        handlers.current.onDirty(false);
        setStatus("Saved");
      } else setStatus("Unsaved");
    } catch (e) {
      setStatus((e as Error).message);
    } finally {
      saving.current = false;
    }
  };
  const saveRef = useRef(save);
  saveRef.current = save;
  useEffect(() => {
    if (!editor) return;
    if (dirty) {
      timer.current = setTimeout(() => void saveRef.current(), 1600);
    }
    return () => {
      if (timer.current) clearTimeout(timer.current);
    };
  }, [dirty, editor, editTick]);
  useEffect(() => {
    if (!editor || doc.version === version.current) return;
    if (dirtyRef.current) {
      setStatus(
        "A newer revision exists. Your local draft is preserved. Back it up, then review History.",
      );
      return;
    }
    editor.commands.setContent(doc.content, { emitUpdate: false });
    version.current = doc.version;
  }, [doc.version, doc.content, editor]);
  useEffect(() => {
    const warn = (e: BeforeUnloadEvent) => {
      if (dirtyRef.current) {
        e.preventDefault();
      }
    };
    window.addEventListener("beforeunload", warn);
    return () => window.removeEventListener("beforeunload", warn);
  }, []);
  useEffect(() => {
    if (!editor) return;
    const update = () => {
      const out: Row[] = [];
      editor.state.doc.descendants((n, pos) => {
        if (["heading", "table", "figure", "math"].includes(n.type.name))
          out.push({
            text:
              n.type.name === "heading"
                ? n.textContent
                : (n.type.name === "math" ? "Equation" : n.type.name) +
                  ": " +
                  (n.attrs.caption || n.attrs.latex || ""),
            pos,
            level: n.attrs.level,
            id: n.attrs.id,
          });
      });
      setOutline(out);
    };
    update();
    editor.on("update", update);
    return () => {
      editor.off("update", update);
    };
  }, [editor]);
  useEffect(() => {
    let active = true;
    setStyleReady(false);
    loadStyle(style)
      .then(() => {
        if (active) {
          setStyleReady(true);
        }
      })
      .catch(() => {
        if (active) setBib("Citation style unavailable.");
      });
    return () => {
      active = false;
    };
  }, [style]);
  useEffect(() => {
    if (!editor || !styleReady) return;
    const update = () => {
      const ids = new Set<number>();
      editor.state.doc.descendants((n) => {
        if (n.type.name === "citation") ids.add(Number(n.attrs.sourceId));
      });
      try {
        const ordered = [...ids]
          .map((id) => sources.find((s) => s.id === id))
          .filter((s): s is Row => Boolean(s));
        setBib(
          ids.size
            ? bibliography(ordered, style)
            : "Insert citations to build the bibliography.",
        );
        editor.view.dom
          .querySelectorAll<HTMLElement>("[data-citation]")
          .forEach((el) => {
            const s = sources.find(
              (s) => String(s.id) === el.getAttribute("data-citation"),
            );
            if (s) el.textContent = citation(s, style, ordered);
          });
      } catch {
        setBib("Bibliography requires valid source metadata.");
      }
    };
    update();
    editor.on("update", update);
    return () => {
      editor.off("update", update);
    };
  }, [sources, style, styleReady, editor]);
  const download = () => {
    if (!editor) return;
    const blob = new Blob([JSON.stringify(editor.getJSON(), null, 2)], {
      type: "application/json",
    });
    const url = URL.createObjectURL(blob);
    const a = window.document.createElement("a");
    a.href = url;
    a.download = `research-${projectId}-draft.json`;
    a.click();
    setTimeout(() => URL.revokeObjectURL(url), 2000);
  };
  if (!editor) return <div role="status">Loading academic editor…</div>;
  const button = (label: string, action: () => void) => (
    <button type="button" key={label} disabled={readonly} onClick={action}>
      {label}
    </button>
  );
  return (
    <section className="rs-editor" aria-label="Academic editor">
      <div className="rs-toolbar">
        {button("Bold", () => editor.chain().focus().toggleBold().run())}
        {button("Italic", () => editor.chain().focus().toggleItalic().run())}
        {[1, 2, 3].map((level) =>
          button("H" + level, () =>
            editor
              .chain()
              .focus()
              .toggleHeading({ level: level as 1 | 2 | 3 })
              .run(),
          ),
        )}
        {button("List", () => editor.chain().focus().toggleBulletList().run())}
        {button("Table", () =>
          editor
            .chain()
            .focus()
            .insertTable({ rows: 3, cols: 3, withHeaderRow: true })
            .run(),
        )}
        {button("+ Row", () => editor.chain().focus().addRowAfter().run())}
        {button("+ Column", () =>
          editor.chain().focus().addColumnAfter().run(),
        )}
        {button("Undo", () => editor.chain().focus().undo().run())}
        {button("Redo", () => editor.chain().focus().redo().run())}
        {button("Save", () => void save())}
        <button onClick={download}>Back up local draft</button>
      </div>
      <p className="rs-save" role="status">
        {status} · Version {version.current}
      </p>
      <details className="rs-elements">
        <summary>Outline, search and academic elements</summary>
        <div className="rs-outline">
          {outline.map((h, i) => (
            <button
              key={i}
              onClick={() =>
                editor
                  .chain()
                  .focus()
                  .setTextSelection(h.pos + 1)
                  .scrollIntoView()
                  .run()
              }
            >
              {h.text || "Untitled section"}
            </button>
          ))}
        </div>
        <label>
          Find in document
          <input value={search} onChange={(e) => setSearch(e.target.value)} />
        </label>
        <button
          onClick={() => {
            let found = false;
            editor.state.doc.descendants((n, pos) => {
              if (!found && n.isText) {
                const i = (n.text || "")
                  .toLowerCase()
                  .indexOf(search.toLowerCase());
                if (search && i >= 0) {
                  found = true;
                  editor
                    .chain()
                    .focus()
                    .setTextSelection({
                      from: pos + i,
                      to: pos + i + search.length,
                    })
                    .scrollIntoView()
                    .run();
                }
              }
            });
          }}
        >
          Find
        </button>
        <label>
          Citation
          <select value={source} onChange={(e) => setSource(e.target.value)}>
            <option value="">Choose reference</option>
            {sources.map((s) => (
              <option key={s.id} value={s.id}>
                {s.title}
              </option>
            ))}
          </select>
        </label>
        {button("Insert citation", () => {
          if (source)
            editor
              .chain()
              .focus()
              .insertContent({
                type: "citation",
                attrs: { sourceId: Number(source) },
              })
              .run();
        })}
        <label>
          Equation (LaTeX)
          <input value={latex} onChange={(e) => setLatex(e.target.value)} />
        </label>
        {button("Insert equation", () =>
          editor
            .chain()
            .focus()
            .insertContent({ type: "math", attrs: { latex } })
            .run(),
        )}
        <label>
          Footnote
          <input value={note} onChange={(e) => setNote(e.target.value)} />
        </label>
        {button("Insert footnote", () =>
          editor
            .chain()
            .focus()
            .insertContent({ type: "footnote", attrs: { text: note } })
            .run(),
        )}
        <label>
          Uploaded figure
          <select value={figure} onChange={(e) => setFigure(e.target.value)}>
            <option value="">Choose figure</option>
            {artifacts
              .filter((a) => a.kind === "figure")
              .map((a) => (
                <option key={a.id} value={a.id}>
                  {a.name}
                </option>
              ))}
          </select>
        </label>
        <input
          aria-label="Figure caption"
          value={caption}
          onChange={(e) => setCaption(e.target.value)}
        />
        {button("Insert figure", () => {
          if (figure)
            editor
              .chain()
              .focus()
              .insertContent({
                type: "figure",
                attrs: {
                  artifactId: Number(figure),
                  caption,
                  id: "figure-" + crypto.randomUUID(),
                },
              })
              .run();
        })}
        <label>
          Cross-reference target
          <select value={target} onChange={(e) => setTarget(e.target.value)}>
            <option value="">Choose a document target</option>
            {outline
              .filter((h) => h.id)
              .map((h, i) => (
                <option key={i} value={h.id}>
                  {h.text || "Untitled section"}
                </option>
              ))}
          </select>
        </label>
        {button(
          "Insert cross-reference",
          () =>
            target &&
            editor
              .chain()
              .focus()
              .insertContent({ type: "crossReference", attrs: { target } })
              .run(),
        )}
      </details>
      <EditorContent editor={editor} />
      <section className="rs-bibliography">
        <h3>References</h3>
        <div style={{ whiteSpace: "pre-wrap" }}>{bib}</div>
      </section>
    </section>
  );
}
