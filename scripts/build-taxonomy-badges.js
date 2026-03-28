#!/usr/bin/env node
/**
 * Build taxonomy-badges.css
 *
 * Concatenates theme base badge styles with generated color rules
 * from @extrachill/tokens.
 */

const fs = require('fs');
const path = require('path');

const baseStyles = `/*
 * Taxonomy Badges - Base Styling
 *
 * Generic badge styling for taxonomy terms. Specific colors for
 * festivals, locations, venues, and artists provided by plugins.
 *
 * @package ExtraChill
 * @since 2.0.0
 */

/* === Base Badge Styles === */
.taxonomy-badge {
  font-size: var(--font-size-sm);
  padding: var(--spacing-sm);
  border-radius: var(--border-radius-pill);
  font-weight: 500;
  line-height: 1;
  text-decoration: none;
  display: inline-block;
  background-color: var(--background-color);
  color: var(--link-color);
  border: 1px solid var(--border-color);
  transition: background-color 0.2s ease, color 0.2s ease, transform 0.2s ease;
}

.taxonomy-badge:hover {
  background-color: var(--link-color-hover);
  color: var(--button-text-color);
  transform: translateY(-1px);
  text-decoration: none;
}
`;

const tokensCSS = fs.readFileSync(
  path.join(__dirname, '..', 'node_modules', '@extrachill', 'tokens', 'css', 'taxonomy-badges.css'),
  'utf8'
);

const output = baseStyles + '\n' + tokensCSS;
const outPath = path.join(__dirname, '..', 'assets', 'css', 'taxonomy-badges.css');

fs.writeFileSync(outPath, output);
console.log(`Built ${outPath} (${output.split('\n').length} lines)`);
