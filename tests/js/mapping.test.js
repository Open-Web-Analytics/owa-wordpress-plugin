import { test, expect } from 'vitest';
import { hasSavedProfile, initialMapping, initialSwitches, isEditing, mappingPayload, switchesPayload, profileLabel, profilesFor, savedLabel } from '../../js/lib/mapping';

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

test( 'a saved row shows its Profile as text', () => {
	expect( savedLabel( PROFILES, 'OWA-arch' ) ).toBe( PROFILES[ 1 ].label );
	expect( savedLabel( PROFILES, '' ) ).toBe( '— no Profile —' );
	expect( savedLabel( PROFILES, 'OWA-gone' ) ).toBe( 'OWA-gone (not in the list from OWA)' );
} );

test( 'a row shows its dropdown until a Profile is saved for it, or after Edit', () => {
	expect( isEditing( { id: 3, saved: null }, {} ) ).toBe( true );
	expect( isEditing( { id: 3, saved: '' }, {} ) ).toBe( true );
	expect( isEditing( { id: 1, saved: 'OWA-io' }, {} ) ).toBe( false );
	expect( isEditing( { id: 1, saved: 'OWA-io' }, { 1: true } ) ).toBe( true );
} );

test( 'only a row with a saved Profile has a switch, and only those are sent', () => {
	const blogs = [ { id: 1, saved: 'OWA-io' }, { id: 3, saved: '' }, { id: 5, saved: null } ];
	expect( blogs.map( hasSavedProfile ) ).toEqual( [ true, false, false ] );
	expect( switchesPayload( blogs, { 1: false, 3: true, 5: true } ) ).toEqual( { 1: false } );
} );

test( 'the selects start where the server put them and are sent by site', () => {
	const blogs = [ { id: 1, selected: 'OWA-io' }, { id: 3, selected: '' }, { id: 5, own: 'x' } ];
	const mapping = initialMapping( blogs );
	expect( mapping ).toEqual( { 1: 'OWA-io', 3: '' } );
	expect( mappingPayload( { ...mapping, 3: 'OWA-arch' } ) ).toEqual( { 1: 'OWA-io', 3: 'OWA-arch' } );
} );

test( 'the switches start where they were saved', () => {
	expect( initialSwitches( [ { id: 1, enabled: true }, { id: 3, enabled: false }, { id: 5, own: '' } ] ) ).toEqual( { 1: true, 3: false } );
} );
