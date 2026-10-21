/**
 * Network Admin > OWA: one OWA 2.x server and application password for the
 * network, and (when the network configures them) each site's Profile.
 * GET owa-wp/v1/network fetches the Profiles from OWA as it reads the
 * network's sites, so every load shows both as they are now; POST saves.
 */
import { createRoot, useEffect, useState } from '@wordpress/element';
import { Button, FormToggle, Notice, SelectControl, Spinner, TextControl, ToggleControl } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

import { secretHint, secretsPayload } from '../lib/secrets';
import { hasSavedProfile, initialMapping, initialSwitches, isEditing, mappingPayload, profileLabel, profilesFor, savedLabel, switchesPayload } from '../lib/mapping';
import '../settings/style.css';

const NETWORK = '/owa-wp/v1/network';

function SitesTable( { payload, mapping, setMapping, switches, setSwitches, editing, setEditing } ) {
	const fetched = payload.sitesFetchedAt
		? `Observation Profiles last fetched from OWA: ${ new Date( payload.sitesFetchedAt * 1000 ).toISOString().slice( 0, 16 ).replace( 'T', ' ' ) } UTC (${ payload.profiles.length }).`
		: 'No Observation Profiles have been fetched from OWA yet.';

	return (
		<>
			<h2>Sites</h2>
			<p>{ fetched }</p>
			<table className="widefat striped owa-wp-sites">
				<thead>
					<tr>
						<th>Site</th>
						<th>Observation Profile</th>
						{ payload.assignProfiles && <th className="owa-wp-tracking">Tracking</th> }
					</tr>
				</thead>
				<tbody>
					{ payload.blogs.map( ( blog ) => {
						if ( ! payload.assignProfiles ) {
							return (
								<tr key={ blog.id } data-host={ blog.host }>
									<td>{ blog.host }</td>
									<td>{ blog.own || 'not chosen yet' }</td>
								</tr>
							);
						}

						const value = mapping[ blog.id ] ?? '';
						// the switch (and greying) only once the row has a saved Profile
						const saved = hasSavedProfile( blog );
						const enabled = switches[ blog.id ] ?? true;
						const off = saved && ! enabled;
						const edit = isEditing( blog, editing );

						const setValue = ( v ) => setMapping( ( current ) => ( { ...current, [ blog.id ]: v } ) );
						const setEdit = ( on ) => setEditing( ( current ) => ( { ...current, [ blog.id ]: on } ) );

						const options = [
							{ label: 'Select Profile…', value: '' },
							...profilesFor( payload.profiles, blog.baseDomain ).map( ( profile ) => ( { label: profileLabel( profile ), value: profile.id } ) ),
						];

						if ( value && ! payload.profiles.some( ( profile ) => profile.id === value ) ) {
							options.push( { label: `${ value } (not in the list from OWA)`, value } );
						}

						return (
							<tr key={ blog.id } data-host={ blog.host } className={ off ? 'is-disabled' : '' }>
								<td>
									{ blog.host }
									{ blog.saved === null && <span className="owa-wp-badge">New</span> }
								</td>
								<td>
									{ edit ? (
										<div className="owa-wp-profile">
											<SelectControl
												__next40pxDefaultSize
												__nextHasNoMarginBottom
												label={ `Observation Profile for ${ blog.host }` }
												hideLabelFromVision
												value={ value }
												options={ options }
												disabled={ off }
												onChange={ setValue }
											/>
											{ saved && (
												<Button
													variant="link"
													onClick={ () => {
														setValue( blog.saved );
														setEdit( false );
													} }
												>
													Cancel
												</Button>
											) }
										</div>
									) : (
										<div className="owa-wp-profile">
											<span className="owa-wp-saved-profile">{ savedLabel( payload.profiles, blog.saved ) }</span>
											<Button variant="link" disabled={ off } onClick={ () => setEdit( true ) } aria-label={ `Edit the Observation Profile for ${ blog.host }` }>
												Edit
											</Button>
										</div>
									) }
								</td>
								<td className="owa-wp-tracking">
									{ saved && (
										<FormToggle
											aria-label={ `Track ${ blog.host }` }
											checked={ enabled }
											onChange={ () => setSwitches( ( current ) => ( { ...current, [ blog.id ]: ! enabled } ) ) }
										/>
									) }
								</td>
							</tr>
						);
					} ) }
				</tbody>
			</table>
		</>
	);
}

function NetworkPage() {
	const [ payload, setPayload ] = useState( null );
	const [ endpoint, setEndpoint ] = useState( '' );
	const [ assign, setAssign ] = useState( false );
	const [ password, setPassword ] = useState( {} );
	const [ mapping, setMapping ] = useState( {} );
	const [ switches, setSwitches ] = useState( {} );
	const [ editing, setEditing ] = useState( {} );
	const [ errors, setErrors ] = useState( {} );
	const [ notices, setNotices ] = useState( [] );
	const [ busy, setBusy ] = useState( false );

	const apply = ( result ) => {
		const problems = result.errors || {};
		setPayload( result );
		setEndpoint( result.endpoint );
		setAssign( result.assignProfiles );
		setPassword( {} );
		setMapping( initialMapping( result.blogs ) );
		setSwitches( initialSwitches( result.blogs ) );
		setEditing( {} );
		setErrors( problems );
		setNotices( [
			...( result.saved ? [ { status: 'success', text: 'Settings saved.' } ] : [] ),
			...( result.info || [] ).map( ( text ) => ( { status: 'info', text } ) ),
			...( problems.fetch ? [ { status: 'error', text: problems.fetch } ] : [] ),
			...Object.values( problems.assignedSiteIds || {} ).map( ( text ) => ( { status: 'error', text } ) ),
		] );
	};

	useEffect( () => {
		apiFetch( { path: NETWORK } )
			.then( apply )
			.catch( ( error ) => setNotices( [ { status: 'error', text: error.message } ] ) );
	}, [] );

	const save = () => {
		const data = {
			owaEndpoint: endpoint,
			assignProfiles: assign,
			...secretsPayload( { appPassword: password } ),
		};

		if ( payload.assignProfiles && assign ) {
			data.assignedSiteIds = mappingPayload( mapping );
			data.enabledSites = switchesPayload( payload.blogs, switches );
		}

		setBusy( true );
		setErrors( {} );
		setNotices( [] );

		apiFetch( { path: NETWORK, method: 'POST', data } )
			.then( apply )
			.catch( ( error ) => {
				setErrors( error.data?.errors || {} );
				setNotices( [ { status: 'error', text: error.message } ] );
			} )
			.finally( () => setBusy( false ) );
	};

	if ( ! payload ) {
		return notices.length ? <Notice status="error" isDismissible={ false }>{ notices[ 0 ].text }</Notice> : <Spinner />;
	}

	return (
		<div className="owa-wp-network">
			{ notices.map( ( notice, i ) => (
				<Notice key={ i } status={ notice.status } isDismissible={ false }>
					{ notice.text }
				</Notice>
			) ) }
			<p>
				Network mode connects every site in this network to one OWA 2.x server with one application password.
				Leave the endpoint or the password empty to let each site be set up on its own.
			</p>
			<div className="owa-wp-field">
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label="OWA Endpoint"
					help="The URL of your OWA 2.x server, ending with a / (e.g. https://owa.example.com/)."
					type="url"
					value={ endpoint }
					onChange={ setEndpoint }
				/>
				{ errors.owaEndpoint && <p className="owa-wp-field-error">{ errors.owaEndpoint }</p> }
			</div>
			<div className="owa-wp-field">
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label="Application Password"
					help="An application password from OWA (My Preferences > Application Passwords). Site admins can list the Observation Profiles its OWA user can see, so use a read-only password of a user limited to this network's Properties."
					type="password"
					autoComplete="off"
					value={ password.value || '' }
					placeholder={ payload.appPasswordSet && ! password.clear ? '••••••••' : '' }
					onChange={ ( v ) => setPassword( { value: v, clear: false } ) }
				/>
				<p className="owa-wp-secret-hint">
					{ secretHint( payload.appPasswordSet, password ) }
					{ payload.appPasswordSet && ! password.clear && (
						<Button variant="link" isDestructive onClick={ () => setPassword( { value: '', clear: true } ) }>
							Remove
						</Button>
					) }
				</p>
				{ errors.appPassword && <p className="owa-wp-field-error">{ errors.appPassword }</p> }
			</div>
			<div className="owa-wp-field">
				<ToggleControl
					__nextHasNoMarginBottom
					label="Network configures each site's Observation Profile (site admins cannot change it)"
					help={
						assign !== payload.assignProfiles
							? 'Save to switch.'
							: "Each site starts on the Observation Profile whose domain is exactly the site's domain. Change any site below and save."
					}
					checked={ assign }
					onChange={ setAssign }
				/>
			</div>
			{ payload.active && (
				<SitesTable
					payload={ payload }
					mapping={ mapping }
					setMapping={ setMapping }
					switches={ switches }
					setSwitches={ setSwitches }
					editing={ editing }
					setEditing={ setEditing }
				/>
			) }
			<p className="owa-wp-actions">
				<Button variant="primary" onClick={ save } isBusy={ busy } disabled={ busy }>
					Save Changes
				</Button>
			</p>
		</div>
	);
}

const root = document.getElementById( 'owa-wp-network' );

if ( root ) {
	createRoot( root ).render( <NetworkPage /> );
}
