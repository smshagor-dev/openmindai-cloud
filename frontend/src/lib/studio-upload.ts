export type StudioReference = {
  id: number;
  name: string;
  mime: string;
  bytes: number;
};
const encode = (bytes: Uint8Array) => {
  let binary = "";
  for (let offset = 0; offset < bytes.length; offset += 32768)
    binary += String.fromCharCode(...bytes.subarray(offset, offset + 32768));
  return btoa(binary);
};
export async function prepareStudioUpload(file: File) {
  if (!file.size || file.size > 3_000_000)
    throw new Error("Select a reference file up to 3 MB.");
  const extension = file.name.split(".").pop()?.toLowerCase() || "";
  const bytes = new Uint8Array(await file.arrayBuffer());
  const data = encode(bytes);
  if (["png", "jpg", "jpeg", "webp"].includes(extension)) {
    const bitmap = await createImageBitmap(file);
    try {
      if (bitmap.width * bitmap.height > 16_777_216)
        throw new Error("Use an image up to 16 megapixels.");
      const ratio = Math.min(1, 768 / Math.max(bitmap.width, bitmap.height));
      const canvas = document.createElement("canvas");
      canvas.width = Math.max(1, Math.round(bitmap.width * ratio));
      canvas.height = Math.max(1, Math.round(bitmap.height * ratio));
      const context = canvas.getContext("2d");
      if (!context) throw new Error("Image preparation is unavailable.");
      context.fillStyle = "#ffffff";
      context.fillRect(0, 0, canvas.width, canvas.height);
      context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
      let preview = "";
      for (const quality of [0.85, 0.65, 0.45, 0.25]) {
        preview = canvas.toDataURL("image/jpeg", quality);
        if (preview.length <= 174000) break;
      }
      if (preview.length > 174000)
        throw new Error("Use a simpler or smaller image reference.");
      return { name: file.name, data, image_preview: preview };
    } finally {
      bitmap.close();
    }
  }
  let text = "";
  if (["md", "markdown", "txt", "csv", "json"].includes(extension)) {
    text = new TextDecoder("utf-8", { fatal: true }).decode(bytes);
  } else if (extension === "pdf") {
    const { extractPdfText } = await import("@/lib/pdf-text");
    text = await extractPdfText(bytes);
  } else if (extension === "docx") {
    const { unzipSync, strFromU8 } = await import("fflate");
    const files = unzipSync(bytes, {
      filter: (entry) =>
        entry.name === "word/document.xml" && entry.originalSize <= 8_000_000,
    });
    if (!files["word/document.xml"])
      throw new Error("Invalid or oversized DOCX document.");
    const xml = new DOMParser().parseFromString(
      strFromU8(files["word/document.xml"]),
      "application/xml",
    );
    if (xml.getElementsByTagName("parsererror").length)
      throw new Error("Invalid Word document.");
    text = Array.from(xml.getElementsByTagNameNS("*", "p"))
      .map((p) =>
        Array.from(p.getElementsByTagNameNS("*", "t"))
          .map((t) => t.textContent || "")
          .join(""),
      )
      .join("\n\n");
  } else if (extension === "doc") {
    const response = await fetch("/api/document-preview", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ data }),
    });
    const result = await response.json();
    if (!response.ok)
      throw new Error(result.message || "Cannot read this Word document.");
    text = result.text;
  } else
    throw new Error(
      "Use MD, TXT, CSV, JSON, DOC, DOCX, PDF, PNG, JPEG or WebP.",
    );
  if (!text.trim())
    throw new Error(
      "No readable text was found. Scanned documents need OCR or an image reference.",
    );
  if (text.length > 50000 || new TextEncoder().encode(text).length > 150000)
    throw new Error(
      "Split this document into references of at most 50,000 characters. No text was omitted.",
    );
  return { name: file.name, data, text };
}
