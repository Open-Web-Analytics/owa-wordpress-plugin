/**
 * OWA > Settings: the site's settings, from GET/POST owa-wp/v1/settings.
 *
 * The fields come from the server (owaWp_plugin::registerOptions()), with
 * the version each belongs to and whether the network manages it, so this
 * page decides only how each type is drawn and which ones show.
 */
import { createRoot, useEffect, useState } from '@wordpress/element';
import {
	Button,
	ExternalLink,
	Notice,
	RadioControl,
	SelectControl,
	Spinner,
	TabPanel,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

import { fieldText, showTagSettingsLink, visibleFields, visibleSections } from '../lib/fields';
import { secretHint, secretsPayload } from '../lib/secrets';
import './style.css';

const SETTINGS = '/owa-wp/v1/settings';
const SITES = '/owa-wp/v1/sites';

function FieldError( { message } ) {
	return message ? <p className="owa-wp-field-error">{ message }</p> : null;
}

function WebsiteId( { field, value, onChange, sites, payload, values } ) {
	const v2 = values.owaVersion === '2';
	const text = fieldText( field, values.owaVersion );
	const options = [ { label: sites.loading ? 'Loading…' : v2 ? 'Select Profile…' : 'Select…', value: '' }, ...sites.choices ];

	return (
		<>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ text.title }
				value={ value }
				options={ options }
				onChange={ onChange }
				disabled={ field.readonly || sites.loading }
				help={ field.readonly ? '' : text.description }
			/>
			{ sites.notice && <p className="owa-wp-field-error">{ sites.notice }</p> }
			{ payload.network.note && <p className="description">{ payload.network.note }</p> }
			{ showTagSettingsLink( payload, values ) && (
				<p>
					<ExternalLink href={ payload.tagSettingsUrl }>Configure this Observation Profile&apos;s tracking tag in OWA</ExternalLink>
				</p>
			) }
		</>
	);
}

function Field( { field, payload, values, setValue, secrets, setSecret, sites, error } ) {
	const value = values[ field.key ];
	const text = fieldText( field, values.owaVersion );
	const common = { label: text.title, help: text.description };

	switch ( field.type ) {
		case 'radio':
			return <RadioControl { ...common } selected={ value } options={ field.options } onChange={ ( v ) => setValue( field.key, v ) } />;

		case 'boolean':
			return <ToggleControl __nextHasNoMarginBottom { ...common } checked={ !! value } onChange={ ( v ) => setValue( field.key, v ) } />;

		case 'secret':
		case 'app_password': {
			const edit = secrets[ field.key ] || {};
			const saved = payload.secrets[ field.key ];
			return (
				<>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						{ ...common }
						type="password"
						autoComplete="off"
						value={ edit.value || '' }
						placeholder={ saved && ! edit.clear ? '••••••••' : '' }
						onChange={ ( v ) => setSecret( field.key, { value: v, clear: false } ) }
					/>
					<p className="owa-wp-secret-hint">
						{ secretHint( saved, edit ) }
						{ saved && ! edit.clear && (
							<Button variant="link" isDestructive onClick={ () => setSecret( field.key, { value: '', clear: true } ) }>
								Remove
							</Button>
						) }
					</p>
				</>
			);
		}

		case 'select':
			return <WebsiteId field={ field } value={ value } onChange={ ( v ) => setValue( field.key, v ) } sites={ sites } payload={ payload } values={ values } />;

		default:
			return (
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					{ ...common }
					type={ field.type === 'url' ? 'url' : 'text' }
					value={ value }
					onChange={ ( v ) => setValue( field.key, v ) }
				/>
			);
	}
}

function SettingsPage() {
	const [ payload, setPayload ] = useState( null );
	const [ values, setValues ] = useState( {} );
	const [ secrets, setSecrets ] = useState( {} );
	const [ sites, setSites ] = useState( { choices: [], notice: '', loading: true } );
	const [ errors, setErrors ] = useState( {} );
	const [ notice, setNotice ] = useState( null );
	const [ saving, setSaving ] = useState( false );

	const loadSites = () => {
		setSites( ( current ) => ( { ...current, loading: true } ) );
		apiFetch( { path: SITES } )
			.then( ( result ) => setSites( { ...result, loading: false } ) )
			.catch( ( error ) => setSites( { choices: [], notice: error.message, loading: false } ) );
	};

	const apply = ( result ) => {
		setPayload( result );
		setValues( result.values );
		setSecrets( {} );
		setErrors( {} );
	};

	useEffect( () => {
		apiFetch( { path: SETTINGS } )
			.then( apply )
			.catch( ( error ) => setNotice( { status: 'error', text: error.message } ) );
		loadSites();
	}, [] );

	const save = () => {
		setSaving( true );
		setNotice( null );
		apiFetch( { path: SETTINGS, method: 'POST', data: { ...values, ...secretsPayload( secrets ) } } )
			.then( ( result ) => {
				apply( result );
				setNotice( { status: 'success', text: 'Settings saved.' } );
				loadSites();
			} )
			.catch( ( error ) => {
				setErrors( error.data?.errors || {} );
				setNotice( { status: 'error', text: error.message } );
			} )
			.finally( () => setSaving( false ) );
	};

	if ( ! payload ) {
		return notice ? <Notice status={ notice.status } isDismissible={ false }>{ notice.text }</Notice> : <Spinner />;
	}

	const version = values.owaVersion || '1';
	const tabs = visibleSections( payload.sections, payload.fields, version ).map( ( section ) => ( {
		name: section.name,
		title: section.title,
		description: section.description,
	} ) );

	const setValue = ( key, value ) => setValues( ( current ) => ( { ...current, [ key ]: value } ) );
	const setSecret = ( key, edit ) => setSecrets( ( current ) => ( { ...current, [ key ]: edit } ) );

	return (
		<div className="owa-wp-settings">
			{ notice && (
				<Notice status={ notice.status } onRemove={ () => setNotice( null ) }>
					{ notice.text }
				</Notice>
			) }
			<p>Settings for integrating WordPress with an existing Open Web Analytics (OWA) server instance.</p>
			<TabPanel tabs={ tabs } key={ tabs.map( ( tab ) => tab.name ).join() }>
				{ ( tab ) => (
					<div className="owa-wp-section">
						<p className="description">{ tab.description }</p>
						{ visibleFields( payload.fields, version, tab.name ).map( ( field ) => (
							<div className="owa-wp-field" key={ field.key }>
								<Field
									field={ field }
									payload={ payload }
									values={ values }
									setValue={ setValue }
									secrets={ secrets }
									setSecret={ setSecret }
									sites={ sites }
								/>
								<FieldError message={ errors[ field.key ] } />
							</div>
						) ) }
					</div>
				) }
			</TabPanel>
			<Button variant="primary" onClick={ save } isBusy={ saving } disabled={ saving }>
				Save Changes
			</Button>
		</div>
	);
}

const root = document.getElementById( 'owa-wp-settings' );

if ( root ) {
	createRoot( root ).render( <SettingsPage /> );
}
