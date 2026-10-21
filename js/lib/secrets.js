/**
 * Secrets (keys and passwords) are write-only: the page knows only whether
 * one is saved. An edit is { value, clear }: untouched is not sent, so the
 * server keeps what it has; "clear" sends ''; a typed value replaces it.
 */
export function secretsPayload( edits ) {
	const out = {};
	for ( const [ key, edit ] of Object.entries( edits ) ) {
		if ( edit.clear ) {
			out[ key ] = '';
		} else if ( edit.value ) {
			out[ key ] = edit.value;
		}
	}
	return out;
}

/** The hint under a secret field. */
export function secretHint( saved, edit = {} ) {
	if ( edit.clear ) {
		return 'Will be removed when you save.';
	}
	if ( edit.value ) {
		return 'Will replace the saved one when you save.';
	}
	return saved ? 'Saved. Enter a new one to replace it.' : '';
}
