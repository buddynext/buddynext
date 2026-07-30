import { test, expect } from '../_fixtures/auth.fixture';

/**
 * The admin table layout contract.
 *
 * Every data table in the BuddyNext admin obeys one rule, whichever markup
 * shape it uses (dropped straight into a settings body, or wrapped in
 * `.bn-table-wrap__scroll` / `.bn-table-scroll`):
 *
 *   1. It stays a REAL table box. `display: block` on a <table> makes it stop
 *      generating a table box - the rows are then laid out by an anonymous
 *      table wrapper that shrink-wraps to content while the outer block is
 *      stretched by width/min-width. Two boxes, two widths.
 *   2. Its rows FILL it. The border, background and rounded corners are painted
 *      by the table box; the header band and row separators are painted by the
 *      rows. When those disagree the table renders visibly cut off partway
 *      across the card.
 *   3. When it is too wide to fit, SOMETHING scrolls. A table clipped by an
 *      ancestor's overflow:hidden with no scroll container leaves columns
 *      permanently unreachable.
 *
 * ── Why this test exists ────────────────────────────────────────────────────
 *
 * All three failures have shipped, and none of them is visible to the static
 * gates. `bin/ux-audit.sh` checks tokens, inline styles and banned dialogs; it
 * passed clean on every one of these:
 *
 *   - Rows at 713px inside a 980px table on Bulk Moderation, because the scroll
 *     fix set display:block + width:max-content on the table itself. Produces
 *     NO overflow, so an overflow-based check reports the screen as healthy.
 *   - Columns crushed to 52px each at narrow widths by WordPress's .fixed
 *     helper (table-layout:fixed divides the container and never overflows), so
 *     "Content" rendered one letter per line. Again no overflow, again clean.
 *   - A sticky actions column pinned to a non-scrolling table box instead of
 *     the wrapper, so it silently scrolled out of reach.
 *
 * Each was found by eye, after shipping. This asserts the invariant instead.
 *
 * The screen list is DISCOVERED from the admin navigation rather than hardcoded,
 * so a tab added later is covered without anyone remembering to add it here -
 * the previous fixes were each verified against the handful of screens the
 * author happened to be looking at, which is how a fix for four screens
 * regressed a fifth.
 */

/** Widths to audit. Below 783px WordPress core stacks list tables into labelled
 *  rows and the contract does not apply, so the narrowest case sits just above
 *  that boundary - the band where the table must still be a table. */
const WIDTHS = [
	{ label: 'desktop', width: 1440, height: 900 },
	{ label: 'tablet', width: 820, height: 1180 },
];

/** Tables that are layout scaffolding, not data grids. */
const IGNORED_CLASSES = [ 'form-table', 'screen-reader-text', 'widefat-noborder' ];

interface TableReport {
	screen: string;
	selectorHint: string;
	display: string;
	tableWidth: number;
	rowWidth: number;
	fills: boolean;
	overflows: boolean;
	hasScrollHost: boolean;
}

/**
 * Measure every data table on the current page against the contract.
 */
async function auditTables( page: import('@playwright/test').Page, screen: string ): Promise<TableReport[]> {
	return page.evaluate(
		( { screen, ignored } ) => {
			const out: TableReport[] = [];

			document.querySelectorAll( 'table' ).forEach( ( table ) => {
				if ( ignored.some( ( c: string ) => table.classList.contains( c ) ) ) {
					return;
				}

				const row = table.querySelector( 'thead tr' ) || table.querySelector( 'tr' );
				if ( ! row ) {
					return; // No rows rendered (empty state) - nothing to measure.
				}

				const tableWidth = Math.round( table.getBoundingClientRect().width );
				const rowWidth = Math.round( row.getBoundingClientRect().width );
				if ( tableWidth === 0 ) {
					return; // Hidden tab panel.
				}

				// Does anything between the table and the viewport actually scroll?
				let hasScrollHost = false;
				let node: HTMLElement | null = table as HTMLElement;
				while ( node && node !== document.body ) {
					const overflowX = getComputedStyle( node ).overflowX;
					if ( ( overflowX === 'auto' || overflowX === 'scroll' ) && node.scrollWidth > node.clientWidth ) {
						hasScrollHost = true;
						break;
					}
					node = node.parentElement;
				}

				const parent = table.parentElement;
				const overflows = !! parent && table.scrollWidth > parent.clientWidth + 1;

				out.push( {
					screen,
					selectorHint: table.className || '(no class)',
					display: getComputedStyle( table ).display,
					tableWidth,
					rowWidth,
					// 4px of tolerance absorbs sub-pixel rounding and the 1px border.
					fills: rowWidth >= tableWidth - 4,
					overflows,
					hasScrollHost,
				} );
			} );

			return out;
		},
		{ screen, ignored: IGNORED_CLASSES }
	);
}

test.describe( 'admin / table layout contract', () => {
	// Layout auditing across every admin tab at two widths is inherently slower
	// than a single-assertion spec.
	test.slow();

	for ( const viewport of WIDTHS ) {
		test( `every admin table is a real table with full-width rows @ ${ viewport.label }`, async (
			{ authenticatedPage: page },
			testInfo
		) => {
			// This spec sets its own viewports (see WIDTHS), so running it under
			// all three configured projects would repeat identical work three
			// times. Pin it to one project and let WIDTHS own the width matrix.
			test.skip(
				testInfo.project.name !== 'desktop',
				'sets its own viewports - runs once, under the desktop project'
			);

			await page.setViewportSize( { width: viewport.width, height: viewport.height } );

			// Discover the admin surface instead of hardcoding it.
			await page.goto( '/wp-admin/admin.php?page=buddynext' );

			const hrefs = await page.evaluate( () => {
				const seen = new Set< string >();
				document
					.querySelectorAll< HTMLAnchorElement >( 'a[href*="page=buddynext"]' )
					.forEach( ( a ) => {
						// Strip anything that would mutate state or re-run an action.
						const url = new URL( a.href, location.origin );
						if ( url.searchParams.has( 'action' ) || url.searchParams.has( '_wpnonce' ) ) {
							return;
						}
						seen.add( url.pathname + url.search );
					} );
				return [ ...seen ];
			} );

			expect( hrefs.length, 'admin navigation should expose BuddyNext screens to audit' ).toBeGreaterThan( 0 );

			const failures: TableReport[] = [];
			const audited: TableReport[] = [];

			for ( const href of hrefs ) {
				await page.goto( href );
				const reports = await auditTables( page, href );
				audited.push( ...reports );

				for ( const report of reports ) {
					// 1. Never a block box.
					// 2. Rows fill the table.
					// 3. If it overflows, something scrolls.
					if (
						report.display === 'block' ||
						! report.fills ||
						( report.overflows && ! report.hasScrollHost )
					) {
						failures.push( report );
					}
				}
			}

			expect( audited.length, 'no admin tables were measured - the discovery step found nothing' ).toBeGreaterThan( 0 );

			expect(
				failures,
				`Admin table contract violated:\n${ failures
					.map(
						( f ) =>
							`  ${ f.screen }\n    table.${ f.selectorHint }\n` +
							`    display=${ f.display } table=${ f.tableWidth }px row=${ f.rowWidth }px` +
							`${ f.fills ? '' : '  <- ROWS DO NOT FILL (table renders cut off partway across)' }` +
							`${ f.display === 'block' ? '  <- display:block stops it being a table box' : '' }` +
							`${ f.overflows && ! f.hasScrollHost ? '  <- overflows with no scroll host (columns unreachable)' : '' }`
					)
					.join( '\n' ) }`
			).toEqual( [] );
		} );
	}
} );
