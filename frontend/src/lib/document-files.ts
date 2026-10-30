import { deflateRawSync } from "node:zlib";

function crc32(bytes: Buffer) {
  let crc = 0xffffffff;
  for (const b of bytes) {
    crc ^= b;
    for (let j = 0; j < 8; j++)
      crc = (crc >>> 1) ^ (crc & 1 ? 0xedb88320 : 0);
  }
  return (crc ^ 0xffffffff) >>> 0;
}
function zip(entries: { name: string; text: string }[]): Buffer {
  const files: Buffer[] = [];
  const directory: Buffer[] = [];
  let offset = 0;
  for (const entry of entries) {
    const name = Buffer.from(entry.name);
    const original = Buffer.from(entry.text, "utf8");
    const payload = deflateRawSync(original);
    const crc = crc32(original);
    const local = Buffer.alloc(30);
    local.writeUInt32LE(0x04034b50, 0);
    local.writeUInt16LE(20, 4);
    local.writeUInt16LE(0x800, 6);
    local.writeUInt16LE(8, 8);
    local.writeUInt32LE(crc, 14);
    local.writeUInt32LE(payload.length, 18);
    local.writeUInt32LE(original.length, 22);
    local.writeUInt16LE(name.length, 26);
    files.push(local, name, payload);
    const central = Buffer.alloc(46);
    central.writeUInt32LE(0x02014b50, 0);
    central.writeUInt16LE(20, 4);
    central.writeUInt16LE(20, 6);
    central.writeUInt16LE(0x800, 8);
    central.writeUInt16LE(8, 10);
    central.writeUInt32LE(crc, 16);
    central.writeUInt32LE(payload.length, 20);
    central.writeUInt32LE(original.length, 24);
    central.writeUInt16LE(name.length, 28);
    central.writeUInt32LE(offset, 42);
    directory.push(central, name);
    offset += local.length + name.length + payload.length;
  }
  const center = Buffer.concat(directory);
  const end = Buffer.alloc(22);
  end.writeUInt32LE(0x06054b50, 0);
  end.writeUInt16LE(entries.length, 8);
  end.writeUInt16LE(entries.length, 10);
  end.writeUInt32LE(center.length, 12);
  end.writeUInt32LE(offset, 16);
  return Buffer.concat([...files, center, end]);
}
const xml = (text: string) => text.replace(/&/g, "&amp;").replace(/</g, "&lt;")
  .replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&apos;");

export function renderDocx(text: string): Buffer {
  if (!text.trim() || text.length > 100000) throw new Error("Document content is invalid.");
  const paras = text.split(/\r?\n/).map(line => {
    const trimmed = line.trim();
    const heading = trimmed.startsWith("# ");
    const body = heading ? trimmed.slice(2) : trimmed;
    const style = heading ? '<w:pPr><w:pStyle w:val="Heading1"/></w:pPr>' : "";
    return '<w:p>' + style + '<w:r><w:t xml:space="preserve">' + xml(body) + '</w:t></w:r></w:p>';
  }).join("");
  return zip([
    { name: "[Content_Types].xml", text: '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>' },
    { name: "_rels/.rels", text: '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>' },
    { name: "word/_rels/document.xml.rels", text: '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>' },
    { name: "word/styles.xml", text: '<?xml version="1.0" encoding="UTF-8"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:rPr><w:b/><w:sz w:val="32"/></w:rPr></w:style></w:styles>' },
    { name: "word/document.xml", text: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' + paras + '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1440" w:bottom="1440" w:left="1440" w:right="1440"/></w:sectPr></w:body></w:document>' },
  ]);
}
// The built-in Helvetica font uses WinAnsi (Windows-1252), not UTF-8.
// Convert its supported punctuation to actual single-byte codes rather
// than truncating Unicode code points into the wrong glyph.
const winAnsiPunctuation: Record<number, number> = {
  0x20ac: 0x80, 0x201a: 0x82, 0x0192: 0x83, 0x201e: 0x84,
  0x2026: 0x85, 0x2020: 0x86, 0x2021: 0x87, 0x02c6: 0x88,
  0x2030: 0x89, 0x0160: 0x8a, 0x2039: 0x8b, 0x0152: 0x8c,
  0x017d: 0x8e, 0x2018: 0x91, 0x2019: 0x92, 0x201c: 0x93,
  0x201d: 0x94, 0x2022: 0x95, 0x2013: 0x96, 0x2014: 0x97,
  0x02dc: 0x98, 0x2122: 0x99, 0x0161: 0x9a, 0x203a: 0x9b,
  0x0153: 0x9c, 0x017e: 0x9e, 0x0178: 0x9f,
};
function normalizePdf(text: string) {
  let normalized = "";
  for (const ch of text) {
    const point = ch.codePointAt(0)!;
    if (point === 9 || point === 10 || point === 13 ||
        (point >= 0x20 && point <= 0x7e) ||
        (point >= 0xa0 && point <= 0xff)) {
      normalized += ch;
      continue;
    }
    const encoded = winAnsiPunctuation[point];
    if (encoded !== undefined) {
      normalized += String.fromCharCode(encoded);
      continue;
    }
    // Do not produce a PDF with silently corrupt Bangla/Cyrillic/other
    // unsupported characters. Word export preserves the full Unicode text.
    throw new Error("Choose DOCX for non-Latin characters; this PDF font does not support them.");
  }
  return normalized;
}
const pdfEscape = (s: string) => s.replace(/\\/g, "\\\\").replace(/\(/g, "\\(").replace(/\)/g, "\\)");
export function renderPdf(text: string): Buffer {
  const source = normalizePdf(text);
  if (!source.trim() || source.length > 100000) throw new Error("Document is empty or too large.");
  const lines: string[] = [];
  for (const paragraph of source.split(/\r?\n/)) {
    const words = paragraph.trim().split(/\s+/).filter(Boolean);
    let line = "";
    for (const word of words) {
      let remaining = word;
      while (remaining.length) {
        const spacer = line ? " " : "";
        const free = 87 - line.length - spacer.length;
        if (free <= 0 || (remaining.length > free && line)) {
          lines.push(line);
          line = "";
          continue;
        }
        if (remaining.length <= free) {
          line += spacer + remaining;
          break;
        }
        // Very long URLs/words need wrapping as well as ordinary sentences.
        lines.push(remaining.slice(0, 87));
        remaining = remaining.slice(87);
      }
    }
    lines.push(line);
  }
  const pages: string[][] = [];
  while (lines.length) pages.push(lines.splice(0, 55));
  const objects: string[] = [
    "<< /Type /Catalog /Pages 2 0 R >>",
    "",
    "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>",
  ];
  const kids: number[] = [];
  for (const content of pages) {
    const pageId = objects.length + 1;
    const streamId = pageId + 1;
    kids.push(pageId);
    objects.push("<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents " + streamId + " 0 R >>");
    const linesText = content.map((x, i) => "1 0 0 1 48 " + (750 - i * 13) + " Tm (" + pdfEscape(x) + ") Tj").join("\n");
    const stream = Buffer.from("BT /F1 10 Tf\n" + linesText + "\nET", "latin1");
    objects.push("<< /Length " + stream.length + " >>\nstream\n" + stream.toString("latin1") + "\nendstream");
  }
  objects[1] = "<< /Type /Pages /Kids [" + kids.map(id => id + " 0 R").join(" ") + "] /Count " + pages.length + " >>";
  let output = "%PDF-1.4\n";
  const offsets = [0];
  for (let i = 0; i < objects.length; i++) {
    offsets.push(Buffer.byteLength(output, "latin1"));
    output += (i + 1) + " 0 obj\n" + objects[i] + "\nendobj\n";
  }
  const start = Buffer.byteLength(output, "latin1");
  output += "xref\n0 " + (objects.length + 1) + "\n0000000000 65535 f \n";
  for (const off of offsets.slice(1)) output += String(off).padStart(10, "0") + " 00000 n \n";
  output += "trailer\n<< /Size " + (objects.length + 1) + " /Root 1 0 R >>\nstartxref\n" + start + "\n%%EOF";
  return Buffer.from(output, "latin1");
}
