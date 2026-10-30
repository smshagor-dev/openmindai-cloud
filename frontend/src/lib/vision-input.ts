// Transient image input for the hosted vision model. Browser content never enters
// Laravel's saved chat payload or its checkpoint database.
export type SafeImage = {
  mime: "image/jpeg" | "image/png" | "image/webp";
  base64: string;
};
const MAX_BYTES = 4_000_000;
const MAX_BASE64 = Math.ceil(MAX_BYTES / 3) * 4;

export function parseInlineImage(input: unknown): SafeImage | undefined {
  if (input === undefined || input === null) return undefined;
  if (!input || typeof input !== "object" || Array.isArray(input))
    throw new Error("Invalid image attachment.");
  const {mime, data} = input as {mime?: unknown; data?: unknown};
  if (!["image/jpeg", "image/png", "image/webp"].includes(String(mime)) ||
      typeof data !== "string" || !data || data.length > MAX_BASE64 ||
      !/^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$/.test(data))
    throw new Error("Attach a PNG, JPEG or WebP image up to 4 MB.");
  const raw = Buffer.from(data, "base64");
  if (!raw.length || raw.length > MAX_BYTES || raw.toString("base64") !== data)
    throw new Error("Invalid image attachment.");
  const png = raw.subarray(0, 8).equals(Buffer.from([137,80,78,71,13,10,26,10]));
  const jpeg = raw.length >= 4 && raw[0] === 0xff && raw[1] === 0xd8 &&
    raw[2] === 0xff && raw[raw.length - 2] === 0xff && raw[raw.length - 1] === 0xd9;
  const webp = raw.length >= 12 && raw.toString("ascii", 0, 4) === "RIFF" &&
    raw.toString("ascii", 8, 12) === "WEBP";
  if (!((mime === "image/png" && png) || (mime === "image/jpeg" && jpeg) ||
        (mime === "image/webp" && webp)))
    throw new Error("Image content does not match its file type.");
  return {mime: mime as SafeImage["mime"], base64: data};
}

export function imageMessage(
  text: string,
  image: SafeImage,
): Array<{type: "text"; text: string} | {type: "image_url"; image_url: {url: string}}> {
  return [
    {type: "text", text: text || "Describe this image."},
    {type: "image_url", image_url: {url: "data:" + image.mime + ";base64," + image.base64}},
  ];
}
