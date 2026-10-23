import { test, expect } from '../_fixtures/auth.fixture';

/**
 * J-127 every space control sits inside an Interactivity region.
 *
 * Covers: cap-group-content-into-spaces
 * Roles: admin
 *
 * The Interactivity API binds directives only inside an element carrying
 * `data-wp-interactive`. A `data-wp-on--click` outside every region renders,
 * looks right and does nothing: no request, no error, no feedback.
 *
 * That shipped on the space Members tab, which rendered the shared space header
 * without the region every other tab wrapped it in, so Join, Request to join,
 * Leave, Report and the notification popover were all dead there (and only
 * there). The header now owns its region; this asserts the invariant on every
 * tab instead of trusting each page template to remember it.
 *
 * Tabs are DISCOVERED from the space's own nav, so a tab added later is covered
 * without anyone adding it here.
 */
test.describe( 'spaces / directive regions contract', () => {
	test( 'J-127 no space tab renders a data-wp-on directive outside a region', async ( { authenticatedPage: page } ) => {
		await page.goto( '/spaces/' );
		const spaceHref = await page.evaluate( () => {
			const links = [ ...document.querySelectorAll<HTMLAnchorElement>( 'a[href*="/spaces/"]' ) ];
			const match = links
				.map( ( a ) => new URL( a.href ) )
				.find( ( u ) => /^\/spaces\/[^/]+\/$/.test( u.pathname ) );
			return match ? match.pathname : '';
		} );
		expect( spaceHref, 'the spaces directory lists at least one space' ).not.toBe( '' );

		await page.goto( spaceHref );
		const tabs = await page.evaluate( ( base ) => {
			const out = new Set<string>( [ base ] );
			document.querySelectorAll<HTMLAnchorElement>( 'a[href]' ).forEach( ( a ) => {
				const u = new URL( a.href );
				if ( u.origin === location.origin && u.pathname.startsWith( base ) ) {
					out.add( u.pathname + u.search );
				}
			} );
			return [ ...out ];
		}, spaceHref );
		expect( tabs.length, 'the space nav exposes its tabs' ).toBeGreaterThan( 1 );

		const failures: string[] = [];
		for ( const tab of tabs ) {
			await page.goto( tab );
			const orphans = await page.evaluate( () =>
				[ ...document.querySelectorAll( '*' ) ]
					.filter( ( el ) => [ ...el.attributes ].some( ( a ) => a.name.startsWith( 'data-wp-on' ) ) )
					.filter( ( el ) => ! el.closest( '[data-wp-interactive]' ) )
					.map( ( el ) => el.outerHTML.slice( 0, 140 ) )
			);
			orphans.forEach( ( html ) => failures.push( `${ tab }: ${ html }` ) );
		}

		expect( failures, 'controls rendered outside any Interactivity region are dead' ).toEqual( [] );
	} );
} );
