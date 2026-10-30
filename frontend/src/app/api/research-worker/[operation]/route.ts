import { Sandbox, Snapshot } from '@vercel/sandbox';
import { researchWorker } from '@/lib/research-worker.mjs';

export const runtime = 'nodejs';
export const dynamic = 'force-dynamic';
export const maxDuration = 300;

const handle = researchWorker({ Sandbox, Snapshot });

export async function GET(request: Request, context: { params: Promise<{ operation: string }> }) {
  return handle(request, (await context.params).operation);
}

export const POST = GET;
