"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { BookOpen, Check, Clipboard, ExternalLink, KeyRound, ShieldCheck } from "lucide-react";

const examplesFor = (origin: string) => [
  {
    title: "cURL",
    language: "shell",
    content: 'export OPENMINDAI_BASE_URL="' + origin + '"\nexport OPENMINDAI_API_KEY="your-developer-key"\n\ncurl "$OPENMINDAI_BASE_URL/chat/completions" \\\n  -H "Authorization: Bearer $OPENMINDAI_API_KEY" \\\n  -H "Content-Type: application/json" \\\n  -d \'{"model":"openmindai-lite","messages":[{"role":"user","content":"Write a Python function"}],"stream":false}\''
  },
  {
    title: "JavaScript / Node.js",
    language: "javascript",
    content: 'const baseURL = process.env.OPENMINDAI_BASE_URL;\nconst apiKey = process.env.OPENMINDAI_API_KEY;\n\nconst response = await fetch(baseURL + "/chat/completions", {\n  method: "POST",\n  headers: {\n    Authorization: "Bearer " + apiKey,\n    "Content-Type": "application/json"\n  },\n  body: JSON.stringify({\n    model: "openmindai-lite",\n    messages: [{ role: "user", content: "Explain this function" }],\n    stream: false\n  })\n});\nif (!response.ok) throw new Error("HTTP " + response.status);\nconst data = await response.json();\nconsole.log(data.choices[0].message.content);'
  },
  {
    title: "Python",
    language: "python",
    content: 'import os\nimport requests\n\nbase_url = os.environ["OPENMINDAI_BASE_URL"]\napi_key = os.environ["OPENMINDAI_API_KEY"]\nresponse = requests.post(\n    base_url + "/chat/completions",\n    headers={"Authorization": f"Bearer {api_key}"},\n    json={\n        "model": "openmindai-lite",\n        "messages": [{"role": "user", "content": "Create a unit test"}],\n        "stream": False,\n    },\n    timeout=120,\n)\nresponse.raise_for_status()\nprint(response.json()["choices"][0]["message"]["content"])'
  }
];
const endpoints = [
  ["GET", "/models", "List OpenMindAI coding agents and their readiness."],
  ["GET", "/catalog", "Get OpenMindAI coding agent capabilities and chat endpoints."],
  ["POST", "/chat/completions", "Generate code, answer questions or request approved tool calls using chat completions."]
];

export default function Docs() {
  const [origin, setOrigin] = useState("/api/v1/openmindai");
  useEffect(() => { setOrigin(window.location.origin + "/api/v1/openmindai"); }, []);
  const examples = examplesFor(origin);
  const [selected, setSelected] = useState(0);
  const [copied, setCopied] = useState(false);
  async function copy(content: string) {
    try { await navigator.clipboard.writeText(content); setCopied(true); setTimeout(() => setCopied(false), 1800); }
    catch { setCopied(false); }
  }
  return <main className="docs-page">
    <aside className="docs-nav" aria-label="Documentation contents"><span>ON THIS PAGE</span><a href="#introduction">Introduction</a><a href="#authentication">Authentication</a><a href="#quickstart">Quick start</a><a href="#models">Model selection</a><a href="#endpoints">Endpoints</a><a href="#tools">Agent tool calling</a><a href="#streaming">Streaming</a><a href="#usage">Credits and limits</a><a href="#errors">Errors and security</a></aside>
    <article className="docs-main">
      <div className="eyebrow"><BookOpen size={14}/> OPENMINDAI API REFERENCE</div>
      <h1 id="introduction">Start building with OpenMindAI</h1><p className="lead">Connect your application, desktop IDE or backend service through the OpenMindAI Developer Portal API. All developer API requests are authenticated with a private Bearer key.</p>
      <div className="doc-notice"><ShieldCheck size={20}/><p>Developer keys are private. Store them in an environment variable, encrypted credential vault or desktop OS secret store. Never embed keys in browser JavaScript, public repositories or distributed apps.</p></div>
      <section id="authentication"><h2>1. Create your developer account</h2><p>Use <Link href="/register">developer registration</Link> or sign in with an existing OpenMindAI account. Registration, password policy, optional email verification, two-factor authentication and unfamiliar-IP verification follow the Laravel server settings. After signing in, open <Link href="/keys">API keys</Link>, enter a label, confirm your password and supply a 2FA code if enabled.</p><p>The secret appears only once. A key expires after <strong>90 days</strong>, and each account can hold at most <strong>10</strong> developer keys. Revocation is immediate.</p><div className="doc-fact"><KeyRound size={18}/><span><strong>Header:</strong> Authorization: Bearer YOUR_DEVELOPER_KEY</span></div></section>
      <section id="quickstart"><h2>2. Your first API request</h2><p>Base URL: <code>{origin}</code>. This URL belongs to the Next.js Developer Portal, not Laravel. When self-hosting, use your own portal domain. Set <code>OPENMINDAI_BASE_URL</code> and <code>OPENMINDAI_API_KEY</code> on your server before running the example.</p><div className="code-panel"><div className="code-tabs">{examples.map((e, i) => <button key={e.title} type="button" className={selected === i ? "active" : ""} aria-pressed={selected === i} onClick={() => setSelected(i)}>{e.title}</button>)}<button type="button" className="copy-code" aria-label="Copy selected code example" onClick={() => void copy(examples[selected].content)}>{copied ? <Check size={15}/> : <Clipboard size={15}/>} {copied ? "Copied" : "Copy"}</button></div><pre><code>{examples[selected].content}</code></pre></div><p>Chat accepts <code>model</code>, <code>messages</code>, optional <code>tools</code>, <code>stream</code>, <code>max_tokens</code> and <code>temperature</code>. The response follows a chat-completions shape with <code>choices</code> and optional <code>usage</code>.</p></section>
      <section id="models"><h2>3. Choose a model</h2><p>Use <code>GET /models</code> to discover OpenMindAI coding agent identifiers: <code>openmindai-super</code>, <code>openmindai-ultra</code> and <code>openmindai-lite</code>. All are presented under the OpenMindAI brand. <code>GET /catalog</code> lists only coding agents. Availability indicates server configuration, not a guarantee of successful inference.</p><p>For a desktop assistant, set the server URL, paste the generated key into the app's encrypted settings, and select a supported model. Send tool definitions and tool responses using the chat format shown below.</p></section>
      <section id="endpoints"><h2>4. Endpoint reference</h2><div className="endpoint-list">{endpoints.map(([method, path, description]) => <div key={method + path}><span className={"method " + (method === "POST" ? "post" : "get")}>{method}</span><code>{path}</code><p>{description}</p></div>)}</div><p>Developer integration examples use the OpenMindAI coding endpoint. Keep model identifiers and API keys on your trusted application backend or desktop credential store.</p></section>
      <section id="tools"><h2>5. Coding assistants and tool calls</h2><p>Use the chat completion endpoint with <code>tools</code> containing function schemas. Supported requests allow up to 64 declared tools and 64 calls in one assistant message. Returned tool calls have function names, JSON arguments and identifiers. Execute approved tools inside your own application, then send results back as <code>tool</code> messages referencing <code>tool_call_id</code>.</p><div className="code-panel"><pre><code>{'{"model":"openmindai-lite","messages":[{"role":"user","content":"Read README.md"}],"tools":[{"type":"function","function":{"name":"read_file","description":"Read an approved workspace file","parameters":{"type":"object","properties":{"path":{"type":"string"}},"required":["path"]}}}],"stream":false}'}</code></pre></div><p>The model suggests tool calls but does not execute code on your machine. Your desktop integration must enforce file permissions, user approval and safe tool execution.</p></section>
      <section id="streaming"><h2>6. Streaming completions</h2><p>Send <code>"stream": true</code> to receive Server-Sent Events. Read the stream incrementally and stop when the <code>data: [DONE]</code> frame arrives. Reconstruct <code>choices[].delta</code> fields; streaming responses may also contain tool call deltas. Treat an interrupted stream as incomplete.</p></section>
      <section id="usage"><h2>7. Usage and allowances</h2><p>The <Link href="/dashboard">developer dashboard</Link> tracks successful, completed API requests by model and by month. One call equals one application credit. These are <strong>not</strong> provider tokens, prepaid balances or NVIDIA billing credits. Limits are administrator-configured; when no monthly allocation is configured the portal displays “Not set”. Your existing rate limits apply separately.</p></section>
      <section id="errors"><h2>8. Errors and security</h2><div className="error-grid">{[["401", "Invalid, missing or expired bearer token"],["403", "Inactive account or insufficient token scope"],["404", "Model unavailable or unknown endpoint"],["422", "Invalid input or provider schema rejection"],["429", "Rate limit, concurrent request or exhausted allowance"],["502/503", "Provider or backend not available"]].map(([status, detail]) => <div key={status}><strong>{status}</strong><span>{detail}</span></div>)}</div><p>Use TLS for all production traffic. Keep the Laravel <code>NVIDIA_API_KEY</code> private and never pass it to a client. Renew developer keys before expiry, revoke lost keys immediately and avoid retrying streamed writes without checking their completion status.</p></section>
      <div className="docs-cta"><h3>Ready to connect your project?</h3><p>Generate a developer key and send your first request.</p><Link className="button primary" href="/keys">Go to API keys <ExternalLink size={16}/></Link></div>
    </article>
  </main>;
}
