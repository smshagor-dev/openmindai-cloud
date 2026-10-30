// Local parsing only; no uploads or AI provider calls.
export async function extractPdfText(bytes: Uint8Array): Promise<string> {
  if (!bytes.length || bytes.length > 8_000_000 || new TextDecoder().decode(bytes.subarray(0, 5)) !== "%PDF-")
    throw new Error("Select a valid PDF up to 8 MB.");
  const { getDocumentProxy } = await import("unpdf");
  const pdf = await getDocumentProxy(bytes, { maxImageSize: 16_777_216 });
  try {
    if (pdf.numPages > 50) throw new Error("Attach a PDF with at most 50 pages, or split it into smaller files.");
    const pages: string[] = [];
    let size = 0;
    const started = Date.now();
    for (let number = 1; number <= pdf.numPages; number++) {
      if (Date.now() - started > 15_000) throw new Error("PDF extraction took too long. Try a smaller document.");
      const page = await pdf.getPage(number);
      try {
        const content = await page.getTextContent();
        const text = content.items.map(item => "str" in item ? item.str + (item.hasEOL ? "\n" : " ") : "").join("").trim();
        size += text.length;
        if (size > 250_000) throw new Error("PDF text is too large. Split the document before attaching it.");
        pages.push(text);
      } finally { page.cleanup(); }
    }
    const text = pages.join("\n\n").trim();
    if (!text) throw new Error("This PDF has no readable text. Use OCR for a scanned document, or attach page images.");
    return text;
  } finally { await pdf.loadingTask.destroy(); }
}
