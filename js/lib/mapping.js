/**
 * The network settings page's mapping table: each site's Profile.
 */

export const CHANGED = 'Changed: saved when you save';

/** A Profile's option text; one that came with the last refresh says so. */
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

/**
 * The status of a site's row for the value now selected: the server's status
 * while the row is as it was loaded, "changed" once it is not.
 */
export function rowStatus( blog, value ) {
	const loaded = blog.saved === null ? blog.selected : blog.saved;
	return value === loaded ? blog.status : CHANGED;
}

/** The selects' values, as the network route takes them. */
export function mappingPayload( values ) {
	const out = {};
	for ( const [ id, value ] of Object.entries( values ) ) {
		out[ id ] = value;
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
