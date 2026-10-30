// Server-controlled model capabilities. Request understanding stays in the native
// tool conversation; this module never classifies user text with keywords.
export type AutomaticModel = {
  slug: string; provider: string; name: string;
  lastTestStatus?: string | null;
};
const failed = new Set(['failed', 'unavailable', 'unreachable', 'invalid_request', 'invalid_response', 'access_denied', 'rate_limited', 'retired', 'disabled', 'setup_required', 'pending']);
export function automaticModels<T extends AutomaticModel>(models: T[]): T[] {
  return models.filter(model => !model.lastTestStatus || !failed.has(model.lastTestStatus));
}
function preferred<T extends AutomaticModel>(models: T[], slugs: string[]): T | undefined {
  for (const candidates of [models.filter(model => model.lastTestStatus === 'success'), models]) {
    for (const slug of slugs) {
      const found = candidates.find(model => model.slug === slug);
      if (found) return found;
    }
  }
  return undefined;
}
export function automaticCoordinator<T extends AutomaticModel>(models: T[]): T | undefined {
  // Super answers first (~4-5s) and writes correct Bangla/Banglish; Lite is fast
  // but produced garbled Bengali in live tests, so it is only a last resort.
  return preferred(automaticModels(models), ['openmindai-super', 'openmindai-ultra', 'nvidia-gpt-oss-20b', 'nvidia-step-5-preview', 'openmindai-lite']);
}
export function automaticWebCoordinator<T extends AutomaticModel>(models: T[]): T | undefined {
  // Selected search favors the verified reasoning model; ordinary Auto chat
  // keeps its existing coordinator. Failed or untested Ultra is never promoted.
  return automaticModels(models).find(model => model.slug === 'openmindai-ultra' && model.lastTestStatus === 'success')
    || automaticCoordinator(models);
}
export function automaticReasoner<T extends AutomaticModel>(models: T[]): T | undefined {
  return preferred(automaticModels(models), ['openmindai-ultra', 'nvidia-step-5-preview', 'nvidia-gpt-oss-20b', 'openmindai-lite']);
}
const capabilities: Record<string,string> = {
  'openmindai-super': 'General conversation, writing, summaries and multilingual answers including Bangla. Answer simple requests directly.',
  'openmindai-ultra': 'Complex reasoning, detailed analysis, architecture, research synthesis and difficult coding.',
  'nvidia-laguna-xs-2.1': 'Coding specialist: implementation, debugging, refactoring, tests and code review.',
  'nvidia-gpt-oss-20b': 'Mathematics, logic, proofs and general reasoning.',
  'nvidia-step-5-preview': 'General reasoning and complex coding tasks.',
  'nvidia-muse-glimmer-30b': 'General text conversation and writing.',
  'nvidia-nemotron-3-nano-omni-30b-a3b-reasoning': 'Text reasoning. Image inputs use the separate vision route.',
  'nvidia-ising-calibration-1.5-31b': 'Quantum-device calibration only; never select for general scientific or medical questions.',
};
export function automaticSpecialists<T extends AutomaticModel>(models: T[], coordinator: string) {
  return automaticModels(models).filter(model => model.provider !== coordinator && capabilities[model.slug])
    .map(model => ({...model, capabilities:capabilities[model.slug]}));
}
