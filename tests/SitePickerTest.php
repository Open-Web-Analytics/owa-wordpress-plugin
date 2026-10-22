<?php

namespace owaWp\Tests;


class SitePickerTest extends TestCase {

	/**
	 * A plugin whose SDK answers GET /base/v1/sites with $rows (null = failed).
	 */
	private function pluginListing( ?array $rows, array $errors = [], string $saved = '' ): \owaWp_plugin {

		$plugin = $this->makePlugin( [
			'owaEndpoint' => 'https://owa.example.com/',
			'apiKey'      => 'key',
			'authKey'     => 'secret',
			'siteId'      => $saved,
		] );

		$client = new class( $rows, $errors ) {

			public function __construct( private ?array $rows, private array $errors ) {}

			public function listSites(): ?array { return $this->rows; }

			public function getLastErrors(): array { return $this->errors; }
		};

		$plugin->owaSdk = new class( $client ) {

			public function __construct( private object $client ) {}

			public function createSites(): object { return $this->client; }
		};

		return $plugin;
	}

	public function testReadsTheFlatRowsOwa114Returns(): void {

		$plugin = $this->pluginListing( [
			'aaa' => [ 'site_id' => 'aaa', 'domain' => 'https://one.example.com', 'property_name' => 'One' ],
			'bbb' => [ 'site_id' => 'bbb', 'domain' => 'https://two.example.com', 'property_name' => 'Two' ],
		] );

		$this->assertSame( [
			'aaa' => [ 'label' => 'aaa – https://one.example.com', 'siteId' => 'aaa' ],
			'bbb' => [ 'label' => 'bbb – https://two.example.com', 'siteId' => 'bbb' ],
		], $plugin->getSitesFromOwa() );
	}

	public function testStillReadsTheNestedRowsOfServersBefore1101(): void {

		$plugin = $this->pluginListing( [
			[ 'properties' => [ 'site_id' => [ 'value' => 'aaa' ], 'domain' => [ 'value' => 'https://one.example.com' ] ] ],
		] );

		$this->assertSame(
			[ 'aaa' => [ 'label' => 'aaa – https://one.example.com', 'siteId' => 'aaa' ] ],
			$plugin->getSitesFromOwa()
		);
	}

	public function testFailedListingKeepsTheSavedSiteAndSaysWhy(): void {

		$plugin = $this->pluginListing( null, [ 'HTTP 403' ], 'saved1' );

		$list = $plugin->getSitesFromOwa();

		$this->assertSame( [ 'saved1' ], array_keys( $list ), 'Saving the form must not clear the site ID.' );
		$this->assertSame( 'Could not load the list of websites from OWA: HTTP 403', $plugin->getSitesNotice() );
	}

	public function testSavedSiteMissingFromOwaIsStillOffered(): void {

		$plugin = $this->pluginListing( [ [ 'site_id' => 'aaa', 'domain' => 'one.example.com' ] ], [], 'gone' );

		$this->assertSame( [ 'aaa', 'gone' ], array_keys( $plugin->getSitesFromOwa() ) );
	}

	public function testNoCredentialsMeansNoRequest(): void {

		$plugin = $this->pluginListing( null );
		$plugin->setOption( 'authKey', '' );

		$this->assertSame( [], $plugin->getSitesFromOwa() );
		$this->assertSame( '', $plugin->getSitesNotice() );
	}
}
