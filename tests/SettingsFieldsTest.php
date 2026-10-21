<?php

namespace owaWp\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use owaWp\settings\fields\boolean_field;
use owaWp\settings\fields\select;
use owaWp\settings\fields\url;
use owaWp\settings\page;
use owaWp\util;

class SettingsFieldsTest extends TestCase {

	private function endpointField(): url {

		return new url( [ 'id' => 'owaEndpoint', 'label_for' => 'OWA Endpoint', 'endWithSlash' => true ], [] );
	}

	public function testEndpointWithoutASchemeIsRejectedAsSuch(): void {

		Functions\expect( 'add_settings_error' )
			->once()
			->with( 'owaEndpoint', 'owa_wp_owaEndpoint', 'OWA Endpoint URL scheme required. (i.e. http:// or https:// only.)', 'error' );

		$this->assertFalse( $this->endpointField()->isValid( 'owa.example.com/' ) );
	}

	public function testEndpointMustEndWithASlash(): void {

		Functions\expect( 'add_settings_error' )->once();

		$this->assertFalse( $this->endpointField()->isValid( 'https://owa.example.com/owa' ) );
	}

	public function testEndpointWithSchemeAndSlashIsValid(): void {

		Functions\expect( 'add_settings_error' )->never();

		$this->assertTrue( $this->endpointField()->isValid( 'https://owa.example.com/owa/' ) );
	}

	public function testBooleanOutOfRangeIsReportedNotFatal(): void {

		Functions\expect( 'add_settings_error' )->once();

		$field = new boolean_field( [ 'id' => 'enable', 'label_for' => 'Enable OWA' ], [] );

		$this->assertNull( $field->isValid( 5 ) );
	}

	public function testBooleanOffRadioHasTheIdItsLabelPointsAt(): void {

		$field = new boolean_field( [ 'id' => 'enable' ], [ 'enable' => 0 ] );

		ob_start();
		$field->render( $field->getProperties() + [ 'description' => '' ] );
		$out = ob_get_clean();

		$this->assertStringContainsString( '<label for="owa_wp_enable_off"><input class="" name="owa_wp[enable]" id="owa_wp_enable_off"', $out );
	}

	public function testSiteSelectShowsTheListingNotice(): void {

		Filters\expectApplied( 'owa_wp_settings_field_siteId' )
			->andReturn( [ 'aaa' => [ 'label' => 'aaa (one.example.com)', 'siteId' => 'aaa' ] ] );
		Filters\expectApplied( 'owa_wp_settings_field_siteId_notice' )
			->andReturn( 'Could not load the list of websites from OWA: HTTP 403' );

		$field = new select( [ 'id' => 'siteId', 'options' => [] ], [ 'siteId' => 'aaa' ] );

		ob_start();
		$field->render( $field->getProperties() + [ 'description' => 'Pick one.' ] );
		$out = ob_get_clean();

		$this->assertStringContainsString( '<option value="aaa" selected>aaa (one.example.com)</option>', $out );
		$this->assertStringContainsString( '<strong>Could not load the list of websites from OWA: HTTP 403</strong>', $out );
	}

	public function testSettingsPageKeepsItsSections(): void {

		$page = new page( [ 'name' => 'owa-wordpress' ], [] );
		$page->registerSection( [ 'id' => 'general', 'title' => 'General' ] );

		$this->assertSame( [ 'general' ], array_keys( $page->sections ) );
	}

	public function testUtilHooksDefaultToWordPressDefaults(): void {

		$callback = fn() => null;

		Actions\expectAdded( 'admin_init' )->once()->with( $callback, 10, 1 );

		util::addAction( 'admin_init', $callback );
	}
}
