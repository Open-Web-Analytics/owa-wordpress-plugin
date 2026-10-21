/**
 * The network settings page's mapping table: each site's Profile.
 */

export const CHANGED = 'Changed: saved when you save';

/** A Profile's option text; one that appeared in OWA since the last save says so. */
export function profileLabel( profile ) {
	return profile.new ? `${ profile.label } — new` : profile.label;
}

/** The Profiles for one site's select: those sharing its base domain first. */
export function profilesFor( profiles, baseDomain ) {
	return [ ...profiles ].sort( ( a, b ) => {
		const near = ( a.baseDomain === baseDomain ) - ( b.baseDomain === baseDomain );
		return near ? -near : a.domain.localeCompare( b.domain );
	} );
}

export const OFF = 'Tracking off';

/**
 * The status of a site's row for its Profile and switch as they are now:
 * "changed" once either differs from what was loaded; otherwise "tracking
 * off" for a site switched off, or the server's status.
 */
export function rowStatus( blog, value, enabled = blog.enabled ) {
	const loaded = blog.saved === null ? blog.selected : blog.saved;
	if ( value !== loaded || enabled !== blog.enabled ) {
		return CHANGED;
	}
	return blog.enabled === false ? OFF : blog.status;
}

/** The selects' values, as the network route takes them. */
export function mappingPayload( values ) {
	const out = {};
	for ( const [ id, value ] of Object.entries( values ) ) {
		out[ id ] = value;
	}
	return out;
}

/** The switches as they start: each site's saved on/off. */
export function initialSwitches( blogs ) {
	const out = {};
	for ( const blog of blogs ) {
		if ( 'enabled' in blog ) {
			out[ blog.id ] = blog.enabled;
		}
	}
	return out;
}

/** The values the selects start on. */
export function initialMapping( blogs ) {
	const out = {};
	for ( const blog of blogs ) {
		if ( 'selected' in blog ) {
			out[ blog.id ] = blog.selected;
		}
	}
	return out;
}
