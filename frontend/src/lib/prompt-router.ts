// Server-only task and model router. Never trust a prompt as a provider URL or model ID.
export type RouteKind = "chat" | "image" | "speech" | "video" | "document_create";
export type Route = { kind: RouteKind; model: string; format?: "pdf" | "docx"; webSearch?: boolean; deepResearch?: boolean };
export type RouterConfig = {
  apiKey: string; defaultModel?: string; fastModel?: string;
  reasoningModel?: string; visionModel?: string; classifierModel?: string;
  codeModel?: string; translationModel?: string; mathModel?: string; scienceModel?: string;
};
type Options = {
  fetcher?: typeof fetch; signal?: AbortSignal; hasAttachments?: boolean;
  deepResearch?: boolean; webSearch?: boolean; enableClassifier?: boolean; hasImage?: boolean; allowTools?: boolean;
};
const FAST = "nvidia/nemotron-3.5-lightning-30b-a3b";
const ULTRA = "nvidia/nemotron-3-ultra-550b-a55b";
const VISION = "nvidia/nemotron-3.5-super-vl-120b-a12b";

export function serverRouterConfig(apiKey: string, defaultModel?: string): RouterConfig {
  return {
    apiKey,
    defaultModel: defaultModel || process.env.NVIDIA_MODEL || FAST,
    fastModel: process.env.NVIDIA_FAST_MODEL || process.env.NVIDIA_MODEL || FAST,
    reasoningModel: process.env.NVIDIA_REASONING_MODEL || ULTRA,
    visionModel: process.env.NVIDIA_VISION_MODEL || VISION,
    classifierModel: process.env.NVIDIA_ROUTER_MODEL || process.env.NVIDIA_MODEL || FAST,
  };
}

export function catalogRouterConfig(cfg: RouterConfig, catalog: {slug: string; provider: string}[]): RouterConfig {
  const pick = (slugs: string[], fallback: string) => slugs.map((slug) => catalog.find((model) => model.slug === slug)?.provider).find(Boolean) || fallback;
  const first = catalog[0]?.provider || cfg.defaultModel || FAST;
  const fast = pick(["openmindai-lite", "nvidia-deepseek-v4.1-flash", "openmindai-super"], first);
  const reasoning = pick(["openmindai-ultra", "openmindai-super", "nvidia-nemotron-3-super-120b-a12b", "nvidia-gpt-oss-20b"], fast);
  return {...cfg, defaultModel: fast, fastModel: fast, reasoningModel: reasoning, classifierModel: fast,
    visionModel: pick(["openmindai-super", "nvidia-muse-glimmer-30b", "nvidia-llama-3.2-90b-vision-instruct", "nvidia-llama-3.2-11b-vision-instruct"], reasoning),
    codeModel: pick(["nvidia-laguna-xs-2.1", "openmindai-super", "openmindai-ultra", "nvidia-gemma-4-31b-it"], reasoning),
    translationModel: pick(["nvidia-riva-translate-4b-instruct-v2"], fast),
    mathModel: pick(["nvidia-gpt-oss-20b", "openmindai-ultra"], reasoning),
    scienceModel: pick(["nvidia-ising-calibration-1.5-31b", "nvidia-ising-calibration-1-35b-a3b"], reasoning),
  };
}

const codeIntent = /(?:\b(?:html|css|javascript|typescript|python|laravel|react|next\.?js|rust|sql|source code|code|debug|refactor|implement|programming|algorithm|api|website|web app|component|function|unit test)\b|কোড|ওয়েবসাইট|ওয়েবসাইট|coding|website design)/iu;
const question = /^(?:how|why|what|when|where|explain|tell me about|show me how|tell me how)\b|^(?:কিভাবে|কীভাবে|কেন|কি ভাবে|kivabe|kibhabe)/iu;
const textTask = /^(?:(?:please|can you|could you)\s+)?(?:summari[sz]e|rewrite|translate|classify|review|analy[sz]e|describe|explain|compare|write (?:a|an|the) (?:script|prompt|guide|speech))\b|^(?:অনুবাদ|ব্যাখ্যা|সারসংক্ষেপ)/iu;
const noMedia = /(?:\b(?:do not|don't|never|without|no need to)\s+(?:(?:generate|create|make|draw|produce|render)\s+)?(?:an?\s+)?(?:image|picture|video|audio|pdf|file)|(?:ছবি|ভিডিও|অডিও|ফাইল).{0,24}(?:করো না|বানিও না|বানাবে না|লাগবে না)|(?:image|chobi|video|audio|file).{0,20}(?:korba na|koro na|lagbe na))/iu;
export function generationNeedsContext(text: string): boolean {
  return /\b(?:search|browse|research|verify|sources|references|citations|previous|earlier|above|attached|our conversation)\b|গবেষণা|সার্চ|যাচাই|তথ্যসূত্র|রেফারেন্স|আগের|উপরের|প্রদত্ত/iu.test(text);
}
export function generationIntent(text: string): {kind: Exclude<RouteKind, "chat">; format?: "pdf" | "docx"} | null {
  const p = text.toLowerCase().trim().replace(/\s+/g, " ");
  if (question.test(p) || textTask.test(p) || noMedia.test(p)) return null;
  const make = /(?:\b(?:generate|create|make|draw|produce|render|design|synthesize|convert|export|save)\b|বানাও|বানিয়ে|বানিয়ে|তৈরি|আঁকো|জেনারেট|banao|banaw|toiri koro|tori koro|make koro|generate koro)/iu;
  const file = /(?:\b(?:pdf|docx?|word document|downloadable document|document(?: file)?)\b|পিডিএফ|ডকুমেন্ট)/iu;
  if (codeIntent.test(p) && !(file.test(p) && !/\b(?:api|function|script|source code|code to|code for|endpoint|library)\b/iu.test(p))) return null;
  if (/\b(?:read (?:this|it|the text) aloud|say (?:this|it) aloud)\b|পড়ে শোনাও|পড়ে শোনাও/iu.test(p)) return {kind: "speech"};
  const give = /(?:\b(?:give|provide|download)\b|দাও|দিন|\bdaw\b|\bdao\b)/iu;
  const visual = /(?:\b(?:image|picture|photo|logo|poster)\b|ছবি|চিত্র|ফটো|লোগো|chobi)/iu;
  if (!(make.test(p) || ((file.test(p) || visual.test(p)) && give.test(p)))) return null;
  if (/\b(?:video|audio|image)\s+(?:script|prompt|guide|tutorial|description|generation|processing|pipeline|workflow|system)\b|\b(?:transcription|speech.to.text|audio.to.text)\b/iu.test(p)) return null;
  if (file.test(p)) return {kind: "document_create", format: /(?:\bpdf\b|পিডিএফ)/iu.test(p) ? "pdf" : "docx"};
  if (/(?:\b(?:video|movie clip|animated clip|animation)\b|ভিডিও|অ্যানিমেশন)/iu.test(p)) return {kind: "video"};
  if (/(?:\b(?:image|picture|illustration|artwork|poster|logo|wallpaper|photo|graphic)\b|ছবি|চিত্র|লোগো|ফটো|chobi)/iu.test(p)) return {kind: "image"};
  if (/(?:\b(?:audio|voiceover|narration|spoken audio|text.to.speech|tts)\b|অডিও|ভয়েস|কণ্ঠ)/iu.test(p)) return {kind: "speech"};
  return null;
}

function chooseChatModel(text: string, cfg: RouterConfig, opt: Options): string {
  const p = text.toLowerCase();
  if (opt.hasImage || /\b(?:analy[sz]e|interpret|describe|inspect|read)\b.{0,65}\b(?:screenshot|diagram|image|photo|picture|chart)\b/iu.test(p) || /(?:ছবি|চিত্র).{0,40}(?:বিশ্লেষণ|ব্যাখ্যা)/u.test(p)) return cfg.visionModel || VISION;
  if (opt.deepResearch) return cfg.reasoningModel || ULTRA;
  if (codeIntent.test(p)) return cfg.codeModel || cfg.reasoningModel || ULTRA;
  if (/^(?:(?:translate|translation|bangla koro|english koro)\b|অনুবাদ)|(?:বাংলায়|বাংলায়|ইংরেজিতে).{0,15}অনুবাদ/iu.test(p)) return cfg.translationModel || cfg.fastModel || FAST;
  if (/\b(?:solve|equation|calculus|integral|theorem|proof|mathematics|algebra|derive)\b|সমীকরণ|প্রমাণ|গণিত/iu.test(p)) return cfg.mathModel || cfg.reasoningModel || ULTRA;
  if (/\b(?:quantum calibration|calibration chart)\b|কোয়ান্টাম ক্যালিব্রেশন/iu.test(p)) return cfg.scienceModel || cfg.reasoningModel || ULTRA;
  if (opt.hasAttachments || /\b(?:architecture|security audit|reasoning|research paper|scientific report|compare|evaluate|review|analy[sz]e)\b|গবেষণা|বিশ্লেষণ/iu.test(p)) return cfg.reasoningModel || ULTRA;
  return cfg.fastModel || cfg.defaultModel || FAST;
}

function researchIntent(text: string, opt: Options): {webSearch?: boolean; deepResearch?: boolean} {
  if (opt.allowTools === false || opt.hasImage) return {};
  if (opt.deepResearch) return {webSearch: true, deepResearch: true};
  if (opt.webSearch) return {webSearch: true};
  if (/\b(?:do not|don't|without|no)\s+(?:web\s+)?(?:search|browsing|browse)|(?:search|browse).{0,12}(?:koro na|korba na)|সার্চ.{0,12}করো না/iu.test(text)) return {};
  if (codeIntent.test(text)) return {};
  if (/\b(?:deep research|literature review|systematic review|research with sources|comprehensive research)\b|বিস্তারিত গবেষণা|গভীর গবেষণা|\bdeep research koro\b/iu.test(text)) return {webSearch: true, deepResearch: true};
  if (/\b(?:search (?:the )?(?:web|internet)|look (?:it |this )?up|browse|latest|current price|today's|news today|verify (?:this|the claim))\b|সর্বশেষ|আজকের|ইন্টারনেটে.{0,20}খুঁজ|\b(?:web search|search koro|latest news|ajker khobor|ajker news)\b/iu.test(text)) return {webSearch: true};
  return {};
}

export async function routePrompt(prompt: string, cfg: RouterConfig, opt: Options = {}): Promise<Route> {
  const text = prompt.slice(0, 16000).trim();
  const tools = researchIntent(text, opt);
  const defaultRoute: Route = {kind: "chat", model: chooseChatModel(text, cfg, {...opt, deepResearch: opt.deepResearch || tools.deepResearch}), ...tools};
  // Images require a vision-capable model; a text-to-image generator cannot edit uploads.
  if (opt.hasImage) return {kind: "chat", model: cfg.visionModel || VISION};
  if (!text) return defaultRoute;
  if (opt.allowTools !== false && !opt.hasAttachments && !defaultRoute.deepResearch && !defaultRoute.webSearch) {
    const media = generationIntent(text);
    if (media) return {...defaultRoute, ...media};
  }
  return defaultRoute;
}
