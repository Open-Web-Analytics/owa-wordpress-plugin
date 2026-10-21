<?php

namespace owaWp\Tests\Integration;

use Brain\Monkey\Filters;
use owaWp\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The two things the plugin needs from a live OWA server: the tracker served
 * at the path the tag loads, and the signed GET /base/v1/sites behind the
 * Website ID picker.
 *
 * CI installs OWA (a release, or the v2 branch built from source) and sets OWA_URL, OWA_API_KEY, OWA_AUTH_KEY and
 * OWA_SITE_ID; without them these tests are skipped.
 */
#[Group( 'owa1' )]
class OwaServerTest extends TestCase {

	protected function setUp(): void {

		parent::setUp();

		foreach ( [ 'OWA_URL', 'OWA_API_KEY', 'OWA_AUTH_KEY', 'OWA_SITE_ID' ] as $name ) {

			if ( ! getenv( $name ) ) {

				$this->markTestSkipped( "$name is not set; these tests need a running OWA server." );
			}
		}
	}

	private function plugin( array $options = [] ): \owaWp_plugin {

		$plugin = $this->makePlugin( $options + [
			'owaEndpoint' => getenv( 'OWA_URL' ),
			'apiKey'      => getenv( 'OWA_API_KEY' ),
			'authKey'     => getenv( 'OWA_AUTH_KEY' ),
			'siteId'      => '',
		] );

		$plugin->initOwaSdk();

		return $plugin;
	}

	public function testTrackerIsServedWhereTheTagLoadsIt(): void {

		$tag = $this->plugin()->getTrackerSnippet( '' );

		$this->assertMatchesRegularExpression( "/_owa\\.src = owa_baseUrl \\+ '([^']+)';/", $tag );
		preg_match( "/_owa\\.src = owa_baseUrl \\+ '([^']+)';/", $tag, $m );

		$url = getenv( 'OWA_URL' ) . $m[1];
		$body = file_get_contents( $url, false, stream_context_create( [ 'http' => [ 'ignore_errors' => true ] ] ) );
		$headers = implode( "\n", $http_response_header );

		$this->assertMatchesRegularExpression( '#^HTTP/\S+ 200#', $http_response_header[0], "GET $url" );
		$this->assertMatchesRegularExpression( '#^Content-Type: \S*javascript#mi', $headers );
		$this->assertStringContainsString( 'owa_cmds', $body, 'The tracker reads the owa_cmds queue the tag fills.' );
	}

	public function testSitePickerListsTheInstallsSite(): void {

		Filters\expectAdded( 'owa_wp_settings_field_siteId_notice' )->never();

		$sites = $this->plugin()->getSitesFromOwa();

		$this->assertArrayHasKey( getenv( 'OWA_SITE_ID' ), $sites );
		$this->assertSame( getenv( 'OWA_SITE_ID' ), $sites[ getenv( 'OWA_SITE_ID' ) ]['siteId'] );
		$this->assertStringContainsString( 'wp.example.test', $sites[ getenv( 'OWA_SITE_ID' ) ]['label'] );
	}

	public function testWrongAuthKeyIsReportedUnderThePicker(): void {

		$notice = null;
		Filters\expectAdded( 'owa_wp_settings_field_siteId_notice' )
			->once()
			->whenHappen( function ( $callback ) use ( &$notice ) { $notice = $callback; } );

		$sites = $this->plugin( [ 'authKey' => 'not-the-auth-key', 'siteId' => 'saved1' ] )->getSitesFromOwa();

		$this->assertSame( [ 'saved1' ], array_keys( $sites ) );
		$this->assertStringStartsWith( 'Could not load the list of websites from OWA: ', $notice() );
	}
}
