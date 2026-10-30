// Validate normalized microphone audio before reserving a transcription task.
export function isPcmWav16kMono(raw: Buffer): boolean {
  if (!Buffer.isBuffer(raw) || raw.length < 44 ||
      raw.toString("ascii", 0, 4) !== "RIFF" ||
      raw.toString("ascii", 8, 12) !== "WAVE" ||
      raw.readUInt32LE(4) !== raw.length - 8) return false;

  let offset = 12;
  let formatSeen = false;
  let dataSeen = false;
  while (offset + 8 <= raw.length) {
    const id = raw.toString("ascii", offset, offset + 4);
    const size = raw.readUInt32LE(offset + 4);
    const start = offset + 8;
    const end = start + size;
    if (end > raw.length) return false;
    if (id === "fmt ") {
      if (formatSeen || dataSeen || size < 16 ||
          raw.readUInt16LE(start) !== 1 ||
          raw.readUInt16LE(start + 2) !== 1 ||
          raw.readUInt32LE(start + 4) !== 16000 ||
          raw.readUInt32LE(start + 8) !== 32000 ||
          raw.readUInt16LE(start + 12) !== 2 ||
          raw.readUInt16LE(start + 14) !== 16) return false;
      formatSeen = true;
    } else if (id === "data") {
      if (!formatSeen || dataSeen || size === 0 || size % 2 !== 0) return false;
      dataSeen = true;
    }
    offset = end + (size % 2);
    if (offset > raw.length) return false;
  }
  return offset === raw.length && formatSeen && dataSeen;
}
