import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { Usage } from '@openai/agents';
import { investigate, validateInput } from '../src/investigator.mjs';

async function snapshot() {
  const input = JSON.parse(await readFile(new URL('../../../resources/data/agent-logistics-delivered-synthetic.json', import.meta.url)));
  input.input_hash = 'a'.repeat(64);
  input.captured_at = new Date().toISOString();
  input.facts.forEach(f => {
    f.captured_at = input.captured_at;
    f.fresh_until = new Date(Date.now() + 600_000).toISOString();
  });
  return input;
}

const report = () => ({
  summary: '轨迹显示签收，客户提出未收到，需进一步调查。',
  conclusions: [{ statement: '轨迹显示签收。', evidence_refs: ['fact:shipment.logistics_status'] }],
  missing_facts: ['shipment.proof_of_delivery'],
  recommended_next_steps: [{ statement: '由客服核查签收证明。', evidence_refs: ['policy:synthetic_delivery_dispute@1'] }],
});
const call = (name, args) => ({
  type: 'function_call', callId: `call_${name}_${JSON.stringify(args)}`, name,
  arguments: JSON.stringify(args), status: 'completed',
});
const message = output => ({
  type: 'message', role: 'assistant', status: 'completed',
  content: [{ type: 'output_text', text: JSON.stringify(output) }],
});
function modelWith(steps) {
  const requests = [];
  return { requests, async getResponse(request) {
    requests.push(request);
    const step = steps[Math.min(requests.length - 1, steps.length - 1)];
    if (step instanceof Error) throw step;
    return { output: step, usage: new Usage({ requests: 1, inputTokens: 10, outputTokens: 5, totalTokens: 15 }) };
  } };
}
function normalModel(output = report()) {
  return modelWith([
    [call('read_facts', { group: 'shipment' })],
    [call('read_facts', { group: 'order' })],
    [call('read_policy', { key: 'synthetic_delivery_dispute' })],
    [message(output)],
  ]);
}

test('real SDK Runner uses selected tools over several turns and keeps evidence out of the first prompt', async () => {
  const input = await snapshot();
  const model = normalModel();
  const result = await investigate(input, { model });
  assert.equal(result.status, 'needs_review');
  assert.equal(result.requires_human_review, true);
  assert.equal(model.requests.length, 4);
  assert.equal(result.usage.requests, 4);
  assert.deepEqual(result.tool_calls.map(c => c.name), ['read_facts', 'read_facts', 'read_policy']);
  assert.doesNotMatch(JSON.stringify(model.requests[0].input), /Synthetic Carrier|A delivered scan does not prove/);
  assert.match(JSON.stringify(model.requests[1].input), /Synthetic Carrier/);
  assert.equal(model.requests[0].tracing, false);
});

test('identity mismatch, missing identity, lookup failure and safety flags stop before any model call', async () => {
  for (const variant of ['mismatch', 'missing', 'not_found', 'flag']) {
    const input = await snapshot();
    if (variant === 'mismatch') input.facts.find(f => f.key === 'order.identity_match').value = false;
    if (variant === 'missing') input.facts = input.facts.filter(f => f.key !== 'order.identity_match');
    if (variant === 'not_found') input.facts.find(f => f.key === 'order.found').value = false;
    if (variant === 'flag') input.safety_flags = ['lookup_failed'];
    const model = normalModel();
    assert.equal((await investigate(input, { model })).status, 'blocked');
    assert.equal(model.requests.length, 0);
  }
});

test('missing verified policy blocks without a model request', async () => {
  const input = await snapshot();
  input.policies = [];
  const model = normalModel();
  assert.equal((await investigate(input, { model })).status, 'blocked');
  assert.equal(model.requests.length, 0);
});

test('private, unknown, stale, future, duplicate and wrong-policy evidence is rejected', async () => {
  for (const variant of ['private', 'unknown', 'expired', 'future', 'duplicate', 'observed', 'other_intent']) {
    const input = await snapshot();
    if (variant === 'private') input.facts[0].sensitivity = 'private';
    if (variant === 'unknown') input.facts[0].key = 'customer.email';
    if (variant === 'expired') input.facts[0].fresh_until = new Date(Date.now() - 1000).toISOString();
    if (variant === 'future') input.facts[0].captured_at = new Date(Date.now() + 1000).toISOString();
    if (variant === 'duplicate') input.facts.push(input.facts[0]);
    if (variant === 'observed') input.policies[0].status = 'observed';
    if (variant === 'other_intent') input.policies[0].bound_intents = ['refund_review'];
    assert.throws(() => validateInput(input), undefined, variant);
  }
});

test('unknown policy and invented tool cannot access other capabilities', async () => {
  for (const output of [call('read_policy', { key: 'another_site_policy' }), call('cancel_order', { orderId: 42 }), call('read_facts', { group: 'refund' })]) {
    await assert.rejects(investigate(await snapshot(), { model: modelWith([[output]]) }));
  }
});

test('a final answer without mandatory evidence retrieval is rejected', async () => {
  await assert.rejects(investigate(await snapshot(), { model: modelWith([[message(report())]]) }), /required_investigation_tools_not_used/);
});

test('invented, unread and wrong-version evidence references are rejected', async () => {
  for (const ref of ['fact:shipment.proof_of_delivery', 'fact:order.status', 'policy:synthetic_delivery_dispute@2']) {
    const output = report();
    output.conclusions[0].evidence_refs = [ref];
    await assert.rejects(investigate(await snapshot(), { model: normalModel(output) }), /unsupported_evidence_reference/);
  }
});

test('claims without citations and empty conclusions cannot pass', async () => {
  const output = report();
  output.recommended_next_steps[0].evidence_refs = [];
  await assert.rejects(investigate(await snapshot(), { model: normalModel(output) }), /unsupported_evidence_reference/);
  output.conclusions = [];
  await assert.rejects(investigate(await snapshot(), { model: normalModel(output) }), /empty_investigation/);
});

test('six tool call budget stops a model that requests seven calls in one turn', async () => {
  const calls = Array.from({ length: 7 }, (_, i) => ({ ...call('read_facts', { group: 'shipment' }), callId: `call_${i}` }));
  await assert.rejects(investigate(await snapshot(), { model: modelWith([calls]) }), /tool_call_budget_exceeded/);
});

test('max turns and model failures propagate as failed runs', async () => {
  await assert.rejects(investigate(await snapshot(), { model: normalModel(), maxTurns: 1 }));
  await assert.rejects(investigate(await snapshot(), { model: modelWith([new Error('model_unavailable')]) }), /model_unavailable/);
});

test('facts expiring while a model is running invalidate its result', async () => {
  const input = await snapshot();
  const model = normalModel();
  const original = model.getResponse.bind(model);
  const originalNow = Date.now;
  model.getResponse = async request => {
    const response = await original(request);
    if (model.requests.length === 4) {
      // Parsed input is copied by Zod; advance the clock after retrieval.
      Date.now = () => originalNow() + 700_000;
    }
    return response;
  };
  try {
    await assert.rejects(investigate(input, { model }), /stale_or_future_evidence/);
  } finally {
    Date.now = originalNow;
  }
});
