import assert from 'node:assert/strict';
import test from 'node:test';
import { Usage } from '@openai/agents';
import { assist, candidateHash, CHECKS } from '../src/assistant.mjs';

const initial = { case_key: 'ticket:1', input_hash: 'a'.repeat(64), site_key: 'example.test', subject: 'Return', policy_catalog: [] };
const plan = { clauses: [{ id: 'request_1', text: 'Accepted refund and shipped return', intent: 'refund_review', business_stage: 'after_sales' }],
  intents: ['refund_review'], policy_keys: ['return'], unsupported_requests: [], customer_language: 'en', steps: ['Read the postal document', 'Check return status'] };
const candidate = () => ({
  return_reply: null,
  case_progress: { stage: 'awaiting_return', summary: '客户接受金额并报告已寄回。', waiting_for: 'internal', next_action: '核查收件记录', follow_up_note: '', evidence_event_ids: [1, 2] },
  response_decision: { mode: 'reply', reason: '确认收到退运资料。', evidence_event_ids: [2] },
  answer_units: [{ request_id: 'request_1', business_stage: 'after_sales', business: 'refund_review', action_stage: 'requested',
    points: ['确认已接受金额'], evidence_refs: ['event:2', 'document:attachment:10', 'policy:return@1'], essential_unknowns: ['Return receipt'], internal_followups: ['Check receiving records'] }],
  internal_followups: ['Check receiving records'], missing_facts: ['return.receipt'],
  conclusions: [{ statement: '单据可读到参考编号，但未证实妥投。', evidence_refs: ['document:attachment:10'] }],
  answer_en: 'Thank you for confirming. We have noted the postal reference.',
  answer_customer_language: 'Thank you for confirming. We have noted the postal reference.', answer_zh: '感谢确认，我们已记录邮寄编号。',
});
const call = (name, args = {}) => ({ type: 'function_call', callId: `${name}_${Math.random()}`, name, arguments: JSON.stringify(args), status: 'completed' });
const message = output => ({ type: 'message', role: 'assistant', status: 'completed', content: [{ type: 'output_text', text: JSON.stringify(output) }] });
function scripted(steps) {
  const requests = [];
  return { requests, async getResponse(request) {
    requests.push(request);
    const step = steps[Math.min(requests.length - 1, steps.length - 1)];
    if (step instanceof Error) throw step;
    return { output: typeof step === 'function' ? step(request) : step, usage: new Usage({ requests: 1, inputTokens: 10, outputTokens: 5, totalTokens: 15 }) };
  } };
}
function writer(value = candidate(), repair) {
  return scripted([
    [call('read_conversation')], [call('record_plan', { ...plan, clauses: plan.clauses.map(({ id, ...c }) => c) })],
    [call('read_policy', { key: 'return' }), call('read_order'), call('read_return_playbook'), call('record_return_assessment', { applicable: false, reason: 'Non-return refund', evidence_refs: ['event:2'], conditions: [], customer_position: 'none', position_evidence_refs: [], exception_reason: '' })],
    [call('inspect_document', { document_id: 'attachment:10' }), call('read_return_status')],
    [message(value)], [message(value)], ...(repair ? [[message(repair)]] : []),
  ]);
}
const review = (value, approved = true) => ({ approved, candidate_hash: candidateHash(value), problems: approved ? [] : ['Remove unsupported execution claim'],
  covered_request_ids: ['request_1'], quality_checks: Object.fromEntries(CHECKS.map(k => [k, approved])) });
async function invoke(name) {
  if (name === 'read_return_playbook') return { evidence_refs: ['strategy:return@1.0.0'] };
  if (name === 'record_return_assessment') return { assessment: { applicable: false, reason: 'Non-return refund', stage: null }, evidence_refs: ['assessment:return'] };
  if (name === 'record_plan') return { plan, evidence_refs: [] };
  if (name === 'read_conversation') return { messages: [{ event_id: 2, text: 'I shipped it.' }], evidence_refs: ['event:1', 'event:2'] };
  if (name === 'read_policy') return { policy: { key: 'return', rules: ['Inspect before refund'] }, evidence_refs: ['policy:return@1'] };
  if (name === 'read_order') return { facts: [{ key: 'order.identity_match', value: true }], evidence_refs: ['fact:order.identity_match'] };
  if (name === 'inspect_document') return { pages: [{ carrier: 'USPS', reference_numbers: ['LH123456789US'] }], evidence_refs: ['document:attachment:10'] };
  if (name === 'read_return_status') return { status: 'unavailable', evidence_refs: ['capability:return_status'] };
  throw new Error('unknown_tool');
}

test('SDK autonomously investigates, reads documents, drafts and independently reviews the exact final emails', async () => {
  const model = writer();
  const reviewer = scripted([[message(review(candidate()))]]);
  const result = await assist(initial, { model, reviewModel: reviewer, invoke });
  assert.equal(result.status, 'needs_review');
  assert.equal(result.requires_human_review, true);
  assert.equal(result.runs.length, 3);
  assert.equal(result.runs[0].usage.requests, 5);
  assert.deepEqual(result.tool_calls.map(c => c.name), ['read_conversation', 'record_plan', 'read_policy', 'read_order', 'read_return_playbook', 'record_return_assessment', 'inspect_document', 'read_return_status']);
  assert.match(JSON.stringify(reviewer.requests[0].input), /LH123456789US/);
  assert.equal(model.requests[0].tracing, false);
});

test('a repaired email receives a new independent review, and the last exact text is retained', async () => {
  const original = candidate();
  const repaired = { ...original, answer_en: 'Repaired English', answer_customer_language: 'Repaired English', answer_zh: '修订后的邮件' };
  const result = await assist(initial, { model: writer(original, repaired), reviewModel: scripted([[message(review(original, false))], [message(review(repaired))]]), invoke });
  assert.equal(result.reviews.length, 2);
  assert.equal(result.candidate.answer_en, 'Repaired English');
  assert.equal(result.status, 'needs_review');
  assert.equal(result.reviews[1].candidate_hash, candidateHash(repaired));
});

test('one contract repair can fix evidence syntax and still requires independent review', async () => {
  const invalid = candidate(); invalid.conclusions[0].evidence_refs = ['message:2'];
  const fixed = candidate();
  const result = await assist(initial, { model: writer(invalid, fixed), reviewModel: scripted([[message(review(fixed))]]), invoke });
  assert.equal(result.status, 'needs_review');
  assert.equal(result.runs.length, 4);
  assert.equal(result.reviews.length, 1);
  assert.deepEqual(result.candidate.conclusions[0].evidence_refs, fixed.conclusions[0].evidence_refs);
});

test('two failed reviews produce blocked output, never a third repair or silent approval', async () => {
  const value = candidate();
  const result = await assist(initial, { model: writer(value, value), reviewModel: scripted([[message(review(value, false))]]), invoke });
  assert.equal(result.status, 'blocked');
  assert.equal(result.reviews.length, 2);
});

test('missing investigation, fabricated references and incomplete request coverage cannot pass', async () => {
  await assert.rejects(assist(initial, { model: scripted([[message(candidate())]]), invoke }), /required_investigation_not_done/);
  const invented = candidate(); invented.conclusions[0].evidence_refs = ['document:unread'];
  await assert.rejects(assist(initial, { model: writer(invented), invoke }), /unsupported_evidence_reference/);
  const incomplete = candidate(); incomplete.answer_units = [];
  await assert.rejects(assist(initial, { model: writer(incomplete), invoke }), /request_coverage_incomplete/);
});

test('review for another email hash fails; approved with failed quality checks remains blocked', async () => {
  await assert.rejects(assist(initial, { model: writer(), reviewModel: scripted([[message({ ...review(candidate()), candidate_hash: 'wrong' })]]), invoke }), /review_does_not_match_candidate/);
  const verdict = review(candidate()); verdict.quality_checks.factual_accuracy = false;
  const value = candidate();
  const result = await assist(initial, { model: writer(value, value), reviewModel: scripted([[message(verdict)]]), invoke });
  assert.equal(result.status, 'blocked');
});

test('tool errors, unknown tools and model errors propagate as technical failure', async () => {
  await assert.rejects(assist(initial, { model: writer(), invoke: async () => { throw new Error('scope_rejected'); } }), /scope_rejected/);
  await assert.rejects(assist(initial, { model: scripted([[call('send_email')]]), invoke }));
  await assert.rejects(assist(initial, { model: scripted([new Error('provider_down')]), invoke }), /provider_down/);
});

test('no_reply requires empty emails; valid no_reply is reviewed and returns no email', async () => {
  const value = candidate(); value.response_decision.mode = 'no_reply';
  await assert.rejects(assist(initial, { model: writer(value), invoke }), /invalid_email_presence/);
  value.answer_en = ''; value.answer_customer_language = ''; value.answer_zh = '';
  const result = await assist(initial, { model: writer(value), reviewModel: scripted([[message(review(value))]]), invoke });
  assert.equal(result.candidate.answer_en, '');
  assert.equal(result.reviews[0].approved, true);
});

const returnStage = (stage, template_id, requirements) => ({ applicable: true, stage, template_id, reply_requirements: requirements });
function stagedWriter(value, assessment, template = assessment, invalidCount = 0) {
  const recordArgs = { applicable: true, reason: 'Conditions confirmed in reply to prior questions.', evidence_refs: ['event:2'], conditions: [],
    customer_position: 'conditions_confirmed', position_evidence_refs: ['event:2'], exception_reason: '' };
  const model = scripted([
    [call('read_conversation')], [call('record_plan', { ...plan, clauses: plan.clauses.map(({ id, ...c }) => c) })],
    [call('read_policy', { key: 'return' }), call('read_order'), call('inspect_document', { document_id: 'attachment:10' })],
    [call('read_return_playbook'), call('read_site_return_policy')],
    ...Array.from({ length: invalidCount + 1 }, () => [call('record_return_assessment', recordArgs)]),
    [call('read_return_template')], [message(value)],
  ]);
  let attempts = 0;
  const invokeStage = async (name, args) => {
    if (name === 'read_site_return_policy') return { status: 'read', content: 'Current terms', evidence_refs: ['site_policy:return@abc'] };
    if (name === 'record_return_assessment') {
      if (attempts++ < invalidCount) return { status: 'invalid_assessment', error: 'All six conditions required.', evidence_refs: [] };
      return { assessment, evidence_refs: ['assessment:return'] };
    }
    if (name === 'read_return_template') return { ...template, evidence_refs: [`template:${template.template_id}@1.0.0`] };
    return invoke(name, args);
  };
  return { model, invoke: invokeStage };
}

test('confirmed return conditions require the stage-two template and customer proposal before ready review', async () => {
  const assessment = returnStage('offer_options', 'RET-02', ['state_return_option', 'request_customer_choice']);
  const value = candidate();
  value.case_progress.stage = 'awaiting_acceptance';
  value.return_reply = { stage: 'offer_options', template_id: 'RET-02', readiness: 'ready', requirements_covered: assessment.reply_requirements, gaps: [] };
  const staged = stagedWriter(value, assessment);
  const reviewer = scripted([[message(review(value))]]);
  const result = await assist(initial, { ...staged, reviewModel: reviewer });
  assert.equal(result.candidate.return_reply.template_id, 'RET-02');
  assert.match(JSON.stringify(reviewer.requests[0].input), /request_customer_choice/);
  assert.match(JSON.stringify(reviewer.requests[0].input), /case_key/);
  assert.ok(result.tool_calls.some(c => c.name === 'record_return_assessment'));
});

test('generic waiting, missing requirements and wrong return template cannot be a ready stage-two reply', async () => {
  const assessment = returnStage('offer_options', 'RET-02', ['state_return_option', 'request_customer_choice']);
  for (const failure of ['waiting', 'coverage', 'template']) {
    const value = candidate();
    value.case_progress.stage = failure === 'waiting' ? 'awaiting_internal' : 'options_offered';
    value.return_reply = { stage: 'offer_options', template_id: failure === 'template' ? 'RET-01' : 'RET-02', readiness: 'ready',
      requirements_covered: failure === 'coverage' ? [] : assessment.reply_requirements, gaps: [] };
    await assert.rejects(assist(initial, stagedWriter(value, assessment)), /return_stage_regression|return_reply_requirements_incomplete|return_stage_or_template_mismatch/);
  }
});

test('a new exception after agreement cannot become ready even when the stage is preserved', async () => {
  const assessment = { ...returnStage('send_instructions', 'RET-03', ['provide_verified_address']), requires_internal_review: true };
  const value = candidate();
  value.return_reply = { stage: 'send_instructions', template_id: 'RET-03', readiness: 'ready', requirements_covered: assessment.reply_requirements, gaps: [] };
  await assert.rejects(assist(initial, stagedWriter(value, assessment)), /return_exception_requires_review/);
});

test('one assessment input correction is allowed, but repeated invalid assessments stop before writing', async () => {
  const assessment = returnStage('review_refund', 'RET-05', ['state_evidenced_processing_stage']);
  const value = candidate();
  value.return_reply = { stage: 'review_refund', template_id: 'RET-05', readiness: 'internal_review', requirements_covered: assessment.reply_requirements, gaps: ['Warehouse and gateway confirmation unavailable.'] };
  const corrected = await assist(initial, { ...stagedWriter(value, assessment, assessment, 1), reviewModel: scripted([[message(review(value))]]) });
  assert.equal(corrected.tool_calls.filter(c => c.name === 'record_return_assessment').length, 2);
  assert.equal(corrected.candidate.return_reply.stage, 'review_refund');
  await assert.rejects(assist(initial, stagedWriter(value, assessment, assessment, 2)), /return_assessment_invalid/);
});
