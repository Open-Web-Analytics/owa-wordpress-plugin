/**
 * Which fields and tabs the site settings page shows.
 *
 * A field tagged with a server version ("1" or "2") shows only while that
 * version is chosen. A field the network manages is hidden, unless it is shown
 * read-only (the Website ID the network assigned).
 */
export function isVisible( field, version ) {
	if ( field.managed && ! field.readonly ) {
		return false;
	}
	return ! field.version || field.version === version;
}

export function visibleFields( fields, version, section ) {
	return fields.filter( ( field ) => isVisible( field, version ) && ( ! section || field.section === section ) );
}

/** A field's title and description for the chosen version: 2.x has its own wording for some. */
export function fieldText( field, version ) {
	const v2 = version === '2' ? field.v2 || {} : {};
	return { title: v2.title || field.title, description: v2.description || field.description };
}

/**
 * What is left to set up, as the server found it in the saved settings,
 * worded for the version chosen on the page; none while tracking is off.
 */
export function setupNotices( payload, values ) {
	if ( ! Number( values.enable ) ) {
		return [];
	}
	return ( payload.notices || {} )[ values.owaVersion === '2' ? '2' : '1' ] || [];
}

/** The tabs that have something to show. */
export function visibleSections( sections, fields, version ) {
	return sections.filter( ( section ) => visibleFields( fields, version, section.name ).length > 0 );
}

/**
 * The link to the Website ID's tracking tag screen in OWA shows only for what
 * is saved: the server builds it from the saved settings, so it is hidden
 * while the version, the endpoint or the Website ID differs from them.
 */
export function showTagSettingsLink( payload, values ) {
	return Boolean( payload.tagSettingsUrl ) &&
		[ 'owaVersion', 'owaEndpoint', 'siteId' ].every( ( key ) => values[ key ] === payload.values[ key ] );
}

/**
 * The Website ID picker shows once OWA has returned its list with the saved
 * credentials, and stays up while the list is fetched again after a save. A
 * Website ID the network assigned is shown read-only whatever OWA says.
 */
export function showPicker( field, sites ) {
	return Boolean( field.readonly || sites.loaded );
}
