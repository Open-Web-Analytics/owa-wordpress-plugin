import { test, expect } from 'vitest';
import { fieldText, isVisible, showTagSettingsLink, visibleFields, visibleSections } from '../../js/lib/fields';

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

test( 'the link to OWA\'s tracking tag screen shows only for the saved Website ID', () => {
	const saved = { owaVersion: '2', owaEndpoint: 'https://owa.example.com/', siteId: 'OWA-1', trackNamedUsers: true };
	const payload = { tagSettingsUrl: 'https://owa.example.com/index.php?owa_do=base.sitesInvocation&owa_siteId=OWA-1', values: saved };

	expect( showTagSettingsLink( payload, saved ) ).toBe( true );
	expect( showTagSettingsLink( payload, { ...saved, trackNamedUsers: false } ) ).toBe( true );
	expect( showTagSettingsLink( payload, { ...saved, siteId: 'OWA-2' } ) ).toBe( false );
	expect( showTagSettingsLink( payload, { ...saved, owaVersion: '1' } ) ).toBe( false );
	expect( showTagSettingsLink( payload, { ...saved, owaEndpoint: 'https://other.example.com/' } ) ).toBe( false );
	expect( showTagSettingsLink( { ...payload, tagSettingsUrl: '' }, saved ) ).toBe( false );
} );

test( 'a field takes its 2.x wording only while 2.x is chosen', () => {
	const siteId = { title: 'Website ID', description: 'Pick a website.', v2: { title: 'Observation Profile', description: 'Pick a Profile.' } };
	expect( fieldText( siteId, '1' ) ).toEqual( { title: 'Website ID', description: 'Pick a website.' } );
	expect( fieldText( siteId, '2' ) ).toEqual( { title: 'Observation Profile', description: 'Pick a Profile.' } );
	expect( fieldText( { title: 'OWA Endpoint', description: 'The URL.', v2: [] }, '2' ) ).toEqual( { title: 'OWA Endpoint', description: 'The URL.' } );
} );
