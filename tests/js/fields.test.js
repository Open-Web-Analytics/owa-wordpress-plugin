import { test, expect } from 'vitest';
import { isVisible, visibleFields, visibleSections } from '../../js/lib/fields';

const FIELDS = [
	{ key: 'owaVersion', section: 'general', version: null },
	{ key: 'apiKey', section: 'general', version: '1' },
	{ key: 'appPassword', section: 'general', version: '2' },
	{ key: 'owaEndpoint', section: 'general', version: null },
	{ key: 'siteId', section: 'general', version: null },
	{ key: 'trackClicks', section: 'tracking', version: '1' },
	{ key: 'trackNamedUsers', section: 'tracking', version: null },
	{ key: 'debug', section: 'advanced', version: '1' },
];

const SECTIONS = [ { name: 'general' }, { name: 'tracking' }, { name: 'advanced' } ];

const keys = ( fields ) => fields.map( ( field ) => field.key );

test( 'each version shows its own fields and those of both', () => {
	expect( keys( visibleFields( FIELDS, '1', 'general' ) ) ).toEqual( [ 'owaVersion', 'apiKey', 'owaEndpoint', 'siteId' ] );
	expect( keys( visibleFields( FIELDS, '2', 'general' ) ) ).toEqual( [ 'owaVersion', 'appPassword', 'owaEndpoint', 'siteId' ] );
	expect( keys( visibleFields( FIELDS, '2', 'tracking' ) ) ).toEqual( [ 'trackNamedUsers' ] );
} );

test( 'a tab with nothing to show is left out', () => {
	expect( visibleSections( SECTIONS, FIELDS, '1' ).map( ( s ) => s.name ) ).toEqual( [ 'general', 'tracking', 'advanced' ] );
	expect( visibleSections( SECTIONS, FIELDS, '2' ).map( ( s ) => s.name ) ).toEqual( [ 'general', 'tracking' ] );
} );

test( 'a field the network manages is hidden unless shown read-only', () => {
	expect( isVisible( { key: 'owaEndpoint', managed: true, readonly: false }, '2' ) ).toBe( false );
	expect( isVisible( { key: 'siteId', managed: true, readonly: true }, '2' ) ).toBe( true );
} );
