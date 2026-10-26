import { OpenAIProvider } from '@openai/agents';
import { investigate } from './investigator.mjs';

try {
  let raw = '';
  for await (const chunk of process.stdin) {
    raw += chunk;
    if (Buffer.byteLength(raw) > 1_000_000) throw new Error('input_too_large');
  }
  if (!process.env.AGENT_API_KEY || !process.env.AGENT_BASE_URL || !process.env.AGENT_MODEL) {
    throw new Error('provider_not_configured');
  }
  const provider = new OpenAIProvider({
    apiKey: process.env.AGENT_API_KEY, baseURL: process.env.AGENT_BASE_URL, useResponses: false,
  });
  try {
    const report = await investigate(JSON.parse(raw), {
      model: await provider.getModel(process.env.AGENT_MODEL), modelLabel: process.env.AGENT_MODEL,
      modelSettings: process.env.AGENT_PROVIDER === 'minimax'
        ? { providerData: { thinking: { type: 'disabled' } } } : {},
    });
    process.stdout.write(`${JSON.stringify(report)}\n`);
  } finally {
    await provider.close();
  }
} catch (error) {
  // Provider errors may include prompts or credentials. Only emit a diagnostic category.
  process.stderr.write(`${JSON.stringify({ error: 'investigation_failed', category: error?.name ?? 'Error' })}\n`);
  process.exitCode = 1;
}
