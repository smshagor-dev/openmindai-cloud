import assert from "node:assert/strict";
import { test } from "node:test";
import { isPcmWav16kMono } from "../src/lib/wav-validation.ts";

function wav(samples = Buffer.alloc(320)) {
  const raw = Buffer.alloc(44 + samples.length);
  raw.write("RIFF", 0);
  raw.writeUInt32LE(raw.length - 8, 4);
  raw.write("WAVEfmt ", 8);
  raw.writeUInt32LE(16, 16);
  raw.writeUInt16LE(1, 20);
  raw.writeUInt16LE(1, 22);
  raw.writeUInt32LE(16000, 24);
  raw.writeUInt32LE(32000, 28);
  raw.writeUInt16LE(2, 32);
  raw.writeUInt16LE(16, 34);
  raw.write("data", 36);
  raw.writeUInt32LE(samples.length, 40);
  samples.copy(raw, 44);
  return raw;
}
test("valid 16 kHz mono PCM WAV accepted", () => assert.equal(isPcmWav16kMono(wav()), true));
test("wrong RIFF size rejected", () => {
  const raw = wav(); raw.writeUInt32LE(36, 4);
  assert.equal(isPcmWav16kMono(raw), false);
});
test("missing data chunk rejected", () => {
  const raw = wav(); raw.write("LIST", 36);
  assert.equal(isPcmWav16kMono(raw), false);
});
test("truncated data chunk rejected", () => {
  const raw = wav(); raw.writeUInt32LE(2000, 40);
  assert.equal(isPcmWav16kMono(raw), false);
});
test("incorrect byte rate rejected", () => {
  const raw = wav(); raw.writeUInt32LE(12345, 28);
  assert.equal(isPcmWav16kMono(raw), false);
});
test("incorrect block alignment rejected", () => {
  const raw = wav(); raw.writeUInt16LE(4, 32);
  assert.equal(isPcmWav16kMono(raw), false);
});
test("empty data chunk rejected", () => assert.equal(isPcmWav16kMono(wav(Buffer.alloc(0))), false));
test("duplicate data chunk rejected", () => {
  const first = wav();
  const second = Buffer.alloc(10);
  second.write("data", 0); second.writeUInt32LE(2, 4);
  const raw = Buffer.concat([first, second]); raw.writeUInt32LE(raw.length - 8, 4);
  assert.equal(isPcmWav16kMono(raw), false);
});
test("valid WAV with padded metadata chunk accepted", () => {
  const base = wav();
  const chunk = Buffer.alloc(10);
  chunk.write("JUNK", 0); chunk.writeUInt32LE(1, 4);
  const raw = Buffer.concat([base.subarray(0, 36), chunk, base.subarray(36)]);
  raw.writeUInt32LE(raw.length - 8, 4);
  assert.equal(isPcmWav16kMono(raw), true);
});
test("data before format chunk rejected", () => {
  const raw = wav(); raw.write("data", 12); raw.write("fmt ", 36);
  assert.equal(isPcmWav16kMono(raw), false);
});
test("wrong sample rate rejected", () => {
  const raw = wav(); raw.writeUInt32LE(44100, 24);
  assert.equal(isPcmWav16kMono(raw), false);
});
test("wrong bit depth rejected", () => {
  const raw = wav(); raw.writeUInt16LE(24, 34);
  assert.equal(isPcmWav16kMono(raw), false);
});
test("duplicate format chunk rejected", () => {
  const raw = wav();
  const extra = Buffer.from(raw.subarray(12, 36));
  const joined = Buffer.concat([raw.subarray(0, 36), extra, raw.subarray(36)]);
  joined.writeUInt32LE(joined.length - 8, 4);
  assert.equal(isPcmWav16kMono(joined), false);
});
test("unexpected trailing bytes rejected", () => {
  const raw = Buffer.concat([wav(), Buffer.from([1, 2, 3])]);
  raw.writeUInt32LE(raw.length - 8, 4);
  assert.equal(isPcmWav16kMono(raw), false);
});
