import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { validateJourneyDocument } from '../run.mjs';

const root = new URL('../', import.meta.url);
const journey = JSON.parse(await readFile(new URL('journeys/show-ratings/journey.json', root), 'utf8'));
const topology = JSON.parse(await readFile(new URL('network-topology.json', root), 'utf8'));
await validateJourneyDocument(journey, 'show-ratings', topology, async () => true);
assert.equal(journey.seed.codeFile, 'seed.php');
assert.equal(journey.grade.codeFile, 'grade.php');
const actions = journey.steps.flatMap(step => JSON.parse(step.args.find(arg => arg.startsWith('steps-json=')).slice(11)));
for (const viewport of ['390x844', '1440x1000']) {
  assert(journey.steps.some(step => step.args.includes(`viewport=${viewport}`)));
}
assert(actions.filter(action => action.kind === 'screenshot').length >= 10);
assert(actions.some(action => action.expression?.includes('getBoundingClientRect')));
assert(actions.some(action => action.expression?.includes("getAttribute('href')")));
assert(actions.some(action => action.assert === true && action.expression?.includes('ratingsSentinel')));
assert(actions.some(action => action.assert === true && action.expression?.includes('[401,403]')));
const grade = await readFile(new URL('journeys/show-ratings/grade.php', root), 'utf8');
for (const expectation of ['browser-persistence-', 'attendee-only-api-gate', 'future-rejected', 'sequential-double-submit', 'change-rating', 'anonymous-primitive-rejected']) {
  assert(grade.includes(expectation), expectation);
}
console.log('show-ratings contract ok');
