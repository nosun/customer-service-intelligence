#!/usr/bin/env node
import { createInterface } from 'node:readline';
import { setTimeout } from 'node:timers/promises';

const input = createInterface({ input: process.stdin });
const iterator = input[Symbol.asyncIterator]();
const initial = JSON.parse((await iterator.next()).value);
const mode = initial.fixture_mode;
const send = message => process.stdout.write(`${JSON.stringify(message)}\n`);
if (mode === 'timeout') {
  await setTimeout(10000);
} else if (mode === 'exit') {
  process.stderr.write('SECRET provider diagnostic');
  process.exit(1);
} else if (mode === 'invalid_json') {
  process.stdout.write('invalid\n');
  process.exit(0);
} else if (mode === 'wrong_id') {
  send({ type: 'tool', id: 2, name: 'read_conversation', arguments: {} });
} else {
  const request = JSON.stringify({ type: 'tool', id: 1, name: 'read_conversation', arguments: {} });
  process.stdout.write(request.slice(0, 20));
  await setTimeout(20);
  process.stdout.write(`${request.slice(20)}\n`);
  const response = JSON.parse((await iterator.next()).value);
  if (response.id !== 1 || response.result.checked !== true) process.exit(2);
  send({ type: 'checkpoint', checkpoint: { stage: 'investigated' } });
  send({ type: 'result', result: {
    case_key: initial.case_key,
    input_hash: mode === 'wrong_hash' ? 'wrong' : initial.input_hash,
    status: 'needs_review',
  } });
  if (mode === 'trailing') process.stdout.write('unexpected trailing data');
}
input.close();
