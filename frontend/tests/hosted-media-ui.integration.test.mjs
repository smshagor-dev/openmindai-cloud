import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const read = file => readFileSync(new URL("../src/" + file, import.meta.url), "utf8");

test("all hosted media tools are presented without revealing provider keys", () => {
  const router = read("app/api/backend/[...path]/route.ts");
  const studio = read("components/media-dialog.tsx");
  const menu = read("components/welcome-tools-menu.tsx");
  assert.match(router, /hostedMediaRequest/);
  assert.match(router, /media\/transcription/);
  assert.match(router, /media\/document_create/);
  assert.match(router, /media\/video/);
  assert.match(router, /data\.voice_transcription/);
  assert.match(studio, /audioAsWavBase64/);
  assert.match(studio, /Word \(\.docx\)/);
  assert.match(studio, /PDF \(\.pdf\)/);
  assert.match(menu, /document_create/);
  assert.doesNotMatch(menu, /NVIDIA|Nemotron/);
});
test("voice panel uses hosted audio transcription and transcribes before chatting", () => {
  const panel = read("components/voice-panel.tsx");
  const live = read("components/live-conversation.tsx");
  assert.match(panel, /audioAsWavBase64/);
  assert.match(panel, /"media\/transcription"/);
  assert.match(panel, /setTranscript\(result\.result_text\)/);
  assert.doesNotMatch(panel, /i < 180/);
  assert.match(live, /playAudioUntilFinished/);
  assert.match(live, /generatedAbort\.current\?\.abort\(\)/);
  assert.match(live, /Using your browser voice instead/);
  assert.match(live, /hostedVoice/);
  assert.match(live, /"media\/transcription"/);
  assert.match(live, /streamChat\(/);
  assert.match(live, /speechSynthesis\.speak/);
  assert.doesNotMatch(live, /Chat model\s*<select/);
});
test("generated files can be privately downloaded and previewed from media studio", () => {
  const studio = read("components/media-dialog.tsx");
  const library = read("components/unified-library.tsx");
  const api = read("app/api/backend/[...path]/route.ts");
  assert.match(studio, /application\/pdf/);
  assert.match(studio, /wordprocessingml/);
  assert.match(library, /DocumentPreview file=\{preview.file\}/);
  assert.match(read("lib/preview-file.ts"), /application\/pdf/);
  assert.match(library, /"document_create"/);
  const inline = read("components/inline-media.tsx");
  assert.match(inline, /DocumentPreview file=\{documentFile\}/);
  assert.match(inline, /application\/pdf/);
  assert.match(api, /application\/pdf/);
  assert.match(api, /application\/vnd\.openxmlformats-officedocument/);
});
