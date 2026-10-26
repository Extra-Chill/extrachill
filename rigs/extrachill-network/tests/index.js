// Support `node --test rigs/extrachill-network/tests` on Node versions that
// resolve an explicit directory as a module rather than discovering its tests.
(async () => {
  await import('./contract.test.mjs');
  await import('./gardner-persona-contract.test.mjs');
  await import('./show-ratings-contract.test.mjs');
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
