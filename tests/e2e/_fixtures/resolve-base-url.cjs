/**
 * Resolve the E2E base URL from the Local site the tester has THIS plugin
 * installed in — never a hardcoded hostname.
 *
 * BuddyNext ships to anyone, so no tester can be bound to one developer's dev
 * URL. A hardcoded default (`http://buddynext-dev.local`) works only on the
 * machine it was written on; everywhere else that host resolves nowhere, so a
 * plain `npx playwright test` navigates to a dead address and each spec times
 * out on page.goto — 30 red lines that look like product defects but are just
 * "the config pointed at a site that isn't here" (cards 10211458045,
 * 10258731805, "product vs harness undetermined").
 *
 * Resolution order, most-specific first — all hostnames come from the tester's
 * own environment, none are baked in:
 *   1. BN_BASE_URL                          — explicit override (CI, non-Local).
 *   2. The Local site this checkout lives in — for a normal install the plugin
 *      dir is under the site's own path; match it. Deterministic: literally the
 *      site the plugin is installed in.
 *   3. A buddynext-named Local site         — the dev symlink case, where the
 *      checkout sits outside any site (symlinked into one or more).
 *   4. The only Local site, if there is exactly one.
 *   5. Nothing — do NOT guess a hostname. Callers require BN_BASE_URL and say so.
 *
 * CommonJS on purpose: playwright.config.ts require()s it through Playwright's
 * loader, and bin/check-journey-run.sh runs it with plain `node` — .cjs is the
 * one module format both execute without a build step.
 *
 * Single source of truth: playwright.config.ts imports resolveBaseUrl(); the
 * journey gate shells `node resolve-base-url.cjs --list` for the same candidate
 * order, then REST-probes each to confirm it is a BuddyNext install.
 */
'use strict';

const { readFileSync, realpathSync } = require( 'node:fs' );
const { homedir } = require( 'node:os' );
const { join, sep } = require( 'node:path' );

/**
 * Local's record of the sites installed on THIS machine. Never throws — a
 * missing/broken sites.json yields [].
 *
 * @return {Array<{name:string,domain:string,path:string}>} Installed sites.
 */
function localSites() {
	try {
		const sitesPath = join( homedir(), 'Library', 'Application Support', 'Local', 'sites.json' );
		const sites = JSON.parse( readFileSync( sitesPath, 'utf8' ) );
		return Object.values( sites )
			.filter( ( s ) => s && s.domain )
			.map( ( s ) => ( { name: s.name || '', domain: s.domain, path: s.path || '' } ) );
	} catch ( e ) {
		return [];
	}
}

/**
 * realpath, or the input path unchanged when it cannot be resolved.
 *
 * @param {string} p Path to resolve.
 * @return {string} Canonical path, or the input.
 */
function realpathOr( p ) {
	try {
		return realpathSync( p );
	} catch ( e ) {
		return p;
	}
}

/**
 * The Local site whose install path contains this checkout, or null. Matches on
 * realpath so a normal (copied) install resolves; a checkout symlinked in from
 * elsewhere (the dev setup) has its real path outside every site and returns
 * null here, falling through to the name/single-site rules.
 *
 * @param {Array<{path:string,domain:string}>} sites Installed sites.
 * @return {?{domain:string}} The owning site, or null.
 */
function siteOwningThisCheckout( sites ) {
	const here = realpathOr( __filename );
	for ( const site of sites ) {
		if ( ! site.path ) {
			continue;
		}
		const root = realpathOr( site.path ) + sep;
		if ( here.startsWith( root ) ) {
			return site;
		}
	}
	return null;
}

/**
 * Ranked base-URL candidates for the journey gate to probe: the owning site
 * first, then buddynext-named sites, then any other Local site. Every entry is
 * a real site on this machine — no hardcoded hosts. Empty when Local has none.
 *
 * @return {string[]} Candidate base URLs, best first.
 */
function siteCandidates() {
	const sites = localSites();
	const owner = siteOwningThisCheckout( sites );
	const rank = ( s ) => {
		if ( owner && s.domain === owner.domain ) {
			return 0;
		}
		return ( s.name + s.domain ).toLowerCase().includes( 'buddynext' ) ? 1 : 2;
	};
	return sites
		.map( ( s ) => [ rank( s ), s.domain ] )
		.sort( ( a, b ) => a[ 0 ] - b[ 0 ] || a[ 1 ].localeCompare( b[ 1 ] ) )
		.map( ( pair ) => 'http://' + pair[ 1 ] );
}

/**
 * The base URL for the Playwright config default, or undefined when it cannot be
 * determined from this machine (callers then require BN_BASE_URL — we never
 * invent a hostname). A best guess: no network probe at config-load; the journey
 * gate does the reachability probe and exports the confirmed value.
 *
 * @return {string|undefined} Base URL, or undefined when undetermined.
 */
function resolveBaseUrl() {
	if ( process.env.BN_BASE_URL ) {
		return process.env.BN_BASE_URL;
	}

	const sites = localSites();
	const owner = siteOwningThisCheckout( sites );
	if ( owner ) {
		return 'http://' + owner.domain;
	}

	const named = sites.filter( ( s ) => ( s.name + s.domain ).toLowerCase().includes( 'buddynext' ) );
	if ( 1 === named.length ) {
		return 'http://' + named[ 0 ].domain;
	}
	if ( 1 === sites.length ) {
		return 'http://' + sites[ 0 ].domain;
	}
	if ( named.length > 1 ) {
		return 'http://' + named.map( ( s ) => s.domain ).sort()[ 0 ];
	}
	return undefined;
}

module.exports = { siteCandidates, resolveBaseUrl };

// CLI: `node resolve-base-url.cjs --list` prints the candidate order (one per
// line) for the shell gate; bare invocation prints the single resolved URL (or
// nothing, when undetermined).
if ( require.main === module ) {
	if ( process.argv.includes( '--list' ) ) {
		const list = siteCandidates();
		if ( list.length ) {
			process.stdout.write( list.join( '\n' ) + '\n' );
		}
	} else {
		const url = resolveBaseUrl();
		if ( url ) {
			process.stdout.write( url + '\n' );
		}
	}
}
