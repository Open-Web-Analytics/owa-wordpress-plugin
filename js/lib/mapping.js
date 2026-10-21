/**
 * The network settings page's mapping table: each site's Profile.
 */

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

/** A saved row's Profile, as its cell shows it until Edit is clicked. */
export function savedLabel( profiles, id ) {
	if ( ! id ) {
		return '— no Profile —';
	}
	const profile = profiles.find( ( p ) => p.id === id );
	return profile ? profile.label : `${ id } (not in the list from OWA)`;
}

/** A row shows its dropdown when it is not saved yet, or after Edit. */
export function isEditing( blog, editing ) {
	return blog.saved === null || Boolean( editing[ blog.id ] );
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
