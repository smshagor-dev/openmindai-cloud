// Shared by the server router and the chat client: a request for a 3D model
// belongs in 3D Studio, never in the image or video generators. "3D-style"
// images (a 3D logo or illustration) stay image requests.
export const STUDIO_HANDOFF_KEY = "openmind:3d-studio-prompt";

const make = /(?:\b(?:generate|create|make|build|design|model|produce)\b|বানাও|বানিয়ে|তৈরি|banao|toiri koro)/iu;
const asset3d = /(?:\b(?:3d|3-d|three[- ]dimensional)\b[^.\n]{0,40}\b(?:model|mesh|asset|object|character|prop|scene)s?\b|\blow[- ]poly\b|\b(?:fbx|glb|gltf)\b|(?:থ্রিডি|৩ডি)\s*(?:মডেল|model))/iu;
const notMaking = /^(?:how|why|what|when|where|which|explain|tell me|show me how)\b|^(?:কিভাবে|কীভাবে|কেন)|\b(?:code|three\.?js|python|script|shader|function)\b/iu;

export function threeDModelIntent(text: string): boolean {
  const p = text.toLowerCase().replace(/\s+/g, " ").trim();
  return !notMaking.test(p) && make.test(p) && asset3d.test(p);
}
