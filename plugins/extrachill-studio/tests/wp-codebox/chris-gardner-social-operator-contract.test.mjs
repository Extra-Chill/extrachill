import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { readFile } from "node:fs/promises";
import test from "node:test";

const directory = new URL("./", import.meta.url);
const operator = JSON.parse(await readFile(new URL("chris-gardner-social-operator.json", directory), "utf8"));
const smoke = JSON.parse(await readFile(new URL("chris-gardner-persona.json", directory), "utf8"));
const stub = await readFile(new URL("chris-gardner-social-operator-provider-stub.php", directory), "utf8");
const deliver = await readFile(new URL("chris-gardner-social-operator-deliver.php", directory), "utf8");
const reload = await readFile(new URL("chris-gardner-social-operator-reload.php", directory), "utf8");

test("smoke and operator coverage remain distinct", () => {
	assert.equal(smoke.metadata.coverageTier, "smoke");
	assert.equal(operator.metadata.coverageTier, "stateful-operator");
	assert.deepEqual(operator.metadata.canonicalIdentityContract, {
		id: "extra-chill-users/chris-gardner",
		version: "1.0.0",
		repository: "Extra-Chill/extrachill-users",
		commit: "627533b541ebdedd7107d543edfef186c07cb48e",
	});
	assert.match(operator.metadata.scenario, /Studio social operations only/);
});

test("released runtime dependencies are pinned, hashed, and ordered", () => {
	const plugins = operator.inputs.extra_plugins;
	assert.deepEqual(plugins.slice(0, 2).map(({ slug }) => slug), ["data-machine", "data-machine-socials"]);
	assert.deepEqual(plugins.slice(2).map(({ slug }) => slug), ["extrachill-analytics", "extrachill-network", "extrachill-api", "extrachill-users"]);
	for (const plugin of plugins) {
		assert.match(plugin.source, /\/releases\/download\/v\d/);
		assert.doesNotMatch(plugin.source, /latest/i);
		assert.match(plugin.sha256, /^[a-f0-9]{64}$/);
		assert.equal(typeof plugin.activate, "boolean");
	}
	assert.equal(plugins[0].activate, true);
	assert.equal(plugins[1].activate, true);
	assert.equal(operator.inputs.runtimeEnv.WP_AGENT_RUNTIME, "1");
	assert.match(JSON.stringify(operator.workflow.steps), /multisite-convert/);
	assert.match(JSON.stringify(operator.workflow.steps), /plugin activate extrachill-users --network/);
});

test("provider boundary is a top-level fail-closed MU-plugin with no fake routes", () => {
	assert.equal(operator.inputs.stagedFiles.length, 1);
	assert.equal(operator.inputs.stagedFiles[0].target, "/wordpress/wp-content/mu-plugins/chris-gardner-social-operator-provider-stub.php");
	assert.match(stub, /pre_http_request/);
	assert.match(stub, /blocked-unexpected/);
	assert.doesNotMatch(stub, /register_rest_route/);
	assert.doesNotMatch(`${stub}\n${deliver}\n${reload}`, /register_rest_route\s*\(/);
	assert.equal(operator.inputs.externalServices.every(({ writes }) => writes === "forbidden"), true);
});

test("provider boundary isolates WordPress background traffic without weakening fail-closed matching", () => {
	const contract = new URL("chris-gardner-social-operator-provider-contract.php", directory);
	const output = execFileSync("php", [contract.pathname], { encoding: "utf8" });
	assert.match(output, /Gardner provider boundary assertions passed/);
});

test("deterministic matrix and bounded adaptive domain oracles are declared", () => {
	const requiredScenarios = ["core-future-and-due-cron", "idempotency-and-stale-tabs", "partial-delivery", "persisted-reload", "safe-retry", "instagram-comments-states"];
	for (const scenario of requiredScenarios) assert.ok(operator.metadata.scenarioMatrix.includes(scenario));
	const campaign = operator.adversarialCampaigns[0];
	assert.equal(campaign.concurrency, 1);
	assert.ok(campaign.budgets.maxCases <= 2);
	assert.ok(campaign.budgets.maxWallTimeMs <= 200000);
	const oracles = campaign.oracles[0].metadata.domainOracles;
	for (const oracle of ["duplicate-effects", "state-loss", "authorization-bypass", "attribution-mismatch", "unexplained-status", "unexpected-network", "unsafe-retry"]) assert.ok(oracles.includes(oracle));
	const exploration = campaign.caseTemplates[0].phases.action[0].args.find((value) => value.startsWith("adaptive-exploration-json="));
	for (const action of ["click", "fill", "select", "submit", "keyboard", "back", "reload", "repeat", "double-submit"]) assert.match(exploration, new RegExp(`\\"${action}\\"`));
});

test("all required capability gaps are stable structured findings", () => {
	for (const id of [
		"GARDNER-STUDIO-MULTIPLATFORM-COMPOSER",
		"GARDNER-STUDIO-SCHEDULING-TIMEZONE",
		"GARDNER-STUDIO-PERSISTENT-QUEUE",
		"GARDNER-STUDIO-PUBLISHED-INVENTORY",
		"GARDNER-SOCIALS-PER-MEDIA-HISTORY",
		"GARDNER-INSTAGRAM-DMS",
		"GARDNER-SOCIAL-ACCOUNT-MANAGEMENT",
		"GARDNER-UNIFIED-SOCIAL-ANALYTICS",
		"GARDNER-SHARE-INITIATOR-ATTRIBUTION",
	]) {
		assert.match(reload, new RegExp(id));
	}
	for (const key of ["severity", "explanation", "backend_primitive", "evidence_ref"]) assert.match(reload, new RegExp(`'${key}'`));
});

test("verified artifacts include all three required ledgers", () => {
	const names = operator.artifacts.typed.map(({ name }) => name);
	assert.ok(names.includes("provider-call-ledger"));
	assert.ok(names.includes("capability-gap-ledger"));
	assert.ok(names.includes("transition-ledger"));
	assert.ok(names.includes("oracle-ledger"));
	assert.ok(names.includes("product-contract-diagnostic"));
	assert.equal(operator.artifacts.typed.every(({ type, parseJson }) => type.startsWith("extrachill-studio/") && parseJson), true);
	assert.deepEqual(operator.artifacts.verify, { enabled: true, strict: true });
});

test("false delegated preparation blocker is removed", () => {
	assert.doesNotMatch(`${deliver}\n${reload}`, /GARDNER-DELEGATED-DELIVERY-PREPARE-BLOCKED/);
	assert.match(reload, /data-machine\/issues\/3359/);
	assert.match(reload, /data-machine-socials\/issues\/247/);
});
