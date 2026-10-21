import { test, expect } from 'vitest';
import { CHANGED, initialMapping, mappingPayload, profileLabel, profilesFor, rowStatus } from '../../js/lib/mapping';

const PROFILES = [
	{ id: 'OWA-io', label: 'PhotoPress – https://photopress.io (OWA-io)', domain: 'https://photopress.io', baseDomain: 'photopress.io', new: false },
	{ id: 'OWA-arch', label: 'Archive – https://archive.peteradamsphoto.com (OWA-arch)', domain: 'https://archive.peteradamsphoto.com', baseDomain: 'peteradamsphoto.com', new: false },
	{ id: 'OWA-www', label: 'PAA – https://www.peteradamsphoto.com (OWA-www)', domain: 'https://www.peteradamsphoto.com', baseDomain: 'peteradamsphoto.com', new: true },
];

test( 'a Profile from the last refresh is marked new', () => {
	expect( profileLabel( PROFILES[ 2 ] ) ).toBe( 'PAA – https://www.peteradamsphoto.com (OWA-www) — new' );
	expect( profileLabel( PROFILES[ 0 ] ) ).toBe( PROFILES[ 0 ].label );
} );

test( 'a site lists the Profiles of its base domain first', () => {
	expect( profilesFor( PROFILES, 'peteradamsphoto.com' ).map( ( p ) => p.id ) ).toEqual( [ 'OWA-arch', 'OWA-www', 'OWA-io' ] );
	expect( profilesFor( PROFILES, 'photopress.io' ).map( ( p ) => p.id ) ).toEqual( [ 'OWA-io', 'OWA-arch', 'OWA-www' ] );
} );

test( 'a row says "changed" once it differs from what was loaded', () => {
	const saved = { id: 1, saved: 'OWA-io', selected: 'OWA-io', status: 'Saved' };
	expect( rowStatus( saved, 'OWA-io' ) ).toBe( 'Saved' );
	expect( rowStatus( saved, '' ) ).toBe( CHANGED );

	const fresh = { id: 3, saved: null, selected: 'OWA-arch', status: 'New site: starts on its exact match, saved when you save' };
	expect( rowStatus( fresh, 'OWA-arch' ) ).toBe( fresh.status );
	expect( rowStatus( fresh, 'OWA-www' ) ).toBe( CHANGED );
} );

test( 'the selects start where the server put them and are sent by site', () => {
	const blogs = [ { id: 1, selected: 'OWA-io' }, { id: 3, selected: '' }, { id: 5, own: 'x' } ];
	const mapping = initialMapping( blogs );
	expect( mapping ).toEqual( { 1: 'OWA-io', 3: '' } );
	expect( mappingPayload( { ...mapping, 3: 'OWA-arch' } ) ).toEqual( { 1: 'OWA-io', 3: 'OWA-arch' } );
} );
