/**
 * Network Admin > OWA: one OWA 2.x server and application password for the
 * network, and (when the network configures them) each site's Profile.
 * Reads GET owa-wp/v1/network; saves with POST owa-wp/v1/network, and
 * "Refresh site list" posts to owa-wp/v1/network/refresh.
 */
import { createRoot, useEffect, useState } from '@wordpress/element';
import { Button, Notice, SelectControl, Spinner, TextControl, ToggleControl } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

import { secretHint, secretsPayload } from '../lib/secrets';
import { CHANGED, initialMapping, mappingPayload, profileLabel, profilesFor, rowStatus } from '../lib/mapping';
import '../settings/style.css';

const NETWORK = '/owa-wp/v1/network';
const REFRESH = '/owa-wp/v1/network/refresh';

function SitesTable( { payload, mapping, setMapping } ) {
	const fetched = payload.sitesFetchedAt
		? `Profiles last fetched from OWA: ${ new Date( payload.sitesFetchedAt * 1000 ).toISOString().slice( 0, 16 ).replace( 'T', ' ' ) } UTC (${ payload.profiles.length }).`
		: 'Refresh the site list to fetch the Profiles from OWA.';

	return (
		<>
			<h2>Sites</h2>
			<p>{ fetched }</p>
			<table className="widefat striped owa-wp-sites">
				<thead>
					<tr>
						<th>Site</th>
						<th>Website ID</th>
						{ payload.assignProfiles && <th>Status</th> }
					</tr>
				</thead>
				<tbody>
					{ payload.blogs.map( ( blog ) => {
						if ( ! payload.assignProfiles ) {
							return (
								<tr key={ blog.id }>
									<td>{ blog.host }</td>
									<td>{ blog.own || 'not chosen yet' }</td>
								</tr>
							);
						}

						const value = mapping[ blog.id ] ?? '';
						const status = rowStatus( blog, value );
						const options = [
							{ label: '— not tracked —', value: '' },
							...profilesFor( payload.profiles, blog.baseDomain ).map( ( profile ) => ( { label: profileLabel( profile ), value: profile.id } ) ),
						];

						if ( value && ! payload.profiles.some( ( profile ) => profile.id === value ) ) {
							options.push( { label: `${ value } (not in the list from OWA)`, value } );
						}

						return (
							<tr key={ blog.id }>
								<td>{ blog.host }</td>
								<td>
									<SelectControl
										__next40pxDefaultSize
										__nextHasNoMarginBottom
										label={ `Website ID for ${ blog.host }` }
										hideLabelFromVision
										value={ value }
										options={ options }
										onChange={ ( v ) => setMapping( ( current ) => ( { ...current, [ blog.id ]: v } ) ) }
									/>
								</td>
								<td className={ `owa-wp-status${ status === CHANGED ? ' is-changed' : '' }` }>{ status }</td>
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
	const [ errors, setErrors ] = useState( {} );
	const [ notices, setNotices ] = useState( [] );
	const [ busy, setBusy ] = useState( '' );

	const apply = ( result ) => {
		setPayload( result );
		setEndpoint( result.endpoint );
		setAssign( result.assignProfiles );
		setPassword( {} );
		setMapping( initialMapping( result.blogs ) );
	};

	useEffect( () => {
		apiFetch( { path: NETWORK } )
			.then( apply )
			.catch( ( error ) => setNotices( [ { status: 'error', text: error.message } ] ) );
	}, [] );

	const submit = ( refresh ) => {
		const data = {
			owaEndpoint: endpoint,
			assignProfiles: assign,
			...secretsPayload( { appPassword: password } ),
		};

		if ( ! refresh && payload.assignProfiles && assign ) {
			data.assignedSiteIds = mappingPayload( mapping );
		}

		setBusy( refresh ? 'refresh' : 'save' );
		setErrors( {} );
		setNotices( [] );

		apiFetch( { path: refresh ? REFRESH : NETWORK, method: 'POST', data } )
			.then( ( result ) => {
				apply( result );
				const problems = result.errors || {};
				setErrors( problems );
				setNotices( [
					...( result.saved ? [ { status: 'success', text: 'Settings saved.' } ] : [] ),
					...result.info.map( ( text ) => ( { status: 'info', text } ) ),
					...( problems.refresh ? [ { status: 'error', text: problems.refresh } ] : [] ),
					...Object.values( problems.assignedSiteIds || {} ).map( ( text ) => ( { status: 'error', text } ) ),
				] );
			} )
			.catch( ( error ) => {
				setErrors( error.data?.errors || {} );
				setNotices( [ { status: 'error', text: error.message } ] );
			} )
			.finally( () => setBusy( '' ) );
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
					help="An application password from OWA (My Preferences > Application Passwords). Site admins can list the Profiles its OWA user can see, so use a read-only password of a user limited to this network's Properties."
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
					label="Network configures each site's Profile (site admins cannot change it)"
					help={
						assign !== payload.assignProfiles
							? 'Save to switch.'
							: "Each site starts on the Profile whose domain is exactly the site's domain. Change any site below and save."
					}
					checked={ assign }
					onChange={ setAssign }
				/>
			</div>
			{ payload.active && <SitesTable payload={ payload } mapping={ mapping } setMapping={ setMapping } /> }
			<p className="owa-wp-actions">
				<Button variant="primary" onClick={ () => submit( false ) } isBusy={ busy === 'save' } disabled={ !! busy }>
					Save Changes
				</Button>
				{ payload.active && (
					<Button variant="secondary" onClick={ () => submit( true ) } isBusy={ busy === 'refresh' } disabled={ !! busy }>
						Refresh site list
					</Button>
				) }
			</p>
		</div>
	);
}

const root = document.getElementById( 'owa-wp-network' );

if ( root ) {
	createRoot( root ).render( <NetworkPage /> );
}
