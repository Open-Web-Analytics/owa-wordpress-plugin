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
		return 'No Observation Profile';
	}
	const profile = profiles.find( ( p ) => p.id === id );
	return profile ? profile.label : `${ id } (not in the list from OWA)`;
}

/** A row has a saved Profile: it shows it as text with Edit, and a Tracking switch. */
export function hasSavedProfile( blog ) {
	return Boolean( blog.saved );
}

/** A row shows its dropdown until a Profile is saved for it, or after Edit. */
export function isEditing( blog, editing ) {
	return ! hasSavedProfile( blog ) || Boolean( editing[ blog.id ] );
}

/** The switches to send: only the rows that show one; the server keeps the rest. */
export function switchesPayload( blogs, switches ) {
	const out = {};
	for ( const blog of blogs ) {
		if ( hasSavedProfile( blog ) && blog.id in switches ) {
			out[ blog.id ] = switches[ blog.id ];
		}
	}
	return out;
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
