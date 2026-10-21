import { test, expect } from 'vitest';
import { secretHint, secretsPayload } from '../../js/lib/secrets';

test( 'only edited secrets are sent: a new value, or empty to remove', () => {
	expect( secretsPayload( {
		apiKey: { value: '', clear: false },
		authKey: { value: '', clear: true },
		appPassword: { value: 'AbCdEfGhIjKl0123456789abcdefghijABCDEFGHIJ01', clear: false },
	} ) ).toEqual( { authKey: '', appPassword: 'AbCdEfGhIjKl0123456789abcdefghijABCDEFGHIJ01' } );
} );

test( 'the hint says what saving will do', () => {
	expect( secretHint( true ) ).toBe( 'Saved. Enter a new one to replace it.' );
	expect( secretHint( false ) ).toBe( '' );
	expect( secretHint( true, { value: 'x' } ) ).toBe( 'Will replace the saved one when you save.' );
	expect( secretHint( true, { clear: true } ) ).toBe( 'Will be removed when you save.' );
} );
