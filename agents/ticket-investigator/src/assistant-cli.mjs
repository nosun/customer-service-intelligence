import { createInterface } from 'node:readline';
import { OpenAIProvider } from '@openai/agents';
import { assist } from './assistant.mjs';

const lines = createInterface({ input: process.stdin });
const pending = new Map();
let start;
let started = false;
const initial = new Promise(resolve => { start = resolve; });
lines.on('line', line => {
  try {
    const message = JSON.parse(line);
    if (!started) { started = true; start(message); return; }
    const request = pending.get(message.id);
    if (!request) throw new Error('unknown_protocol_id');
    pending.delete(message.id);
    if (message.error) request.reject(new Error('tool_failed'));
    else request.resolve(message.result);
  } catch { process.exit(1); }
});
let sequence = 0;
const invoke = (name, args) => new Promise((resolve, reject) => {
  const id = ++sequence;
  pending.set(id, { resolve, reject });
  process.stdout.write(`${JSON.stringify({ type: 'tool', id, name, arguments: args })}\n`);
});
try {
  const input = await initial;
  const provider = new OpenAIProvider({ apiKey: process.env.AGENT_API_KEY, baseURL: process.env.AGENT_BASE_URL,
    useResponses: process.env.AGENT_PROVIDER === 'openai' });
  try {
    const result = await assist(input, { model: await provider.getModel(process.env.AGENT_MODEL), modelLabel: process.env.AGENT_MODEL,
      modelSettings: process.env.AGENT_PROVIDER === 'minimax' ? { providerData: { thinking: { type: 'disabled' } } } : {}, invoke,
      onProgress: async checkpoint => { process.stdout.write(`${JSON.stringify({ type: 'checkpoint', checkpoint })}\n`); } });
    process.stdout.write(`${JSON.stringify({ type: 'result', result })}\n`);
  } finally { await provider.close(); }
  lines.close();
  process.stdin.destroy();
} catch (error) {
  const safeCodes = ['tool_call_budget_exceeded', 'required_investigation_not_done', 'unsupported_evidence_reference',
    'request_coverage_incomplete', 'invalid_email_presence', 'review_does_not_match_candidate', 'tool_failed',
    'return_assessment_required', 'return_assessment_invalid', 'return_stage_or_template_mismatch', 'return_reply_requirements_incomplete', 'return_stage_regression', 'unexpected_return_reply', 'return_exception_requires_review'];
  const category = safeCodes.includes(error?.message) ? error.message : error?.name ?? 'Error';
  process.stderr.write(`${JSON.stringify({ error: 'ticket_agent_failed', category })}\n`);
  lines.close();
  process.stdin.destroy();
  process.exitCode = 1;
}
