import test from 'node:test';
import assert from 'node:assert/strict';
import { extractPdfText } from '../src/lib/pdf-text.ts';
import { renderPdf } from '../src/lib/document-files.ts';

test('real generated PDF text can be read back without an AI service', async () => {
  const output = renderPdf('OpenMindAI Tool Test\nThe verification code is OMA-4729.');
  const text = await extractPdfText(new Uint8Array(output));
  assert.match(text, /OMA-4729/);
  assert.match(text, /OpenMindAI Tool Test/);
});
test('invalid PDF bytes are rejected', async () => {
  await assert.rejects(extractPdfText(new TextEncoder().encode('not a PDF')), /valid PDF/);
});
test('oversized PDF input is rejected before parsing', async () => {
  await assert.rejects(extractPdfText(new Uint8Array(8_000_001)), /8 MB/);
});
