<?php

namespace owaWp\Tests\Integration;

use Brain\Monkey\Filters;
use owaWp\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 2.x mode against a live OWA v2: the Profile's bundle is served where the
 * tag loads it, the Website ID list loads with an application password, and
 * the link to the Tracking Tag screen goes to a real route.
 *
 * Needs OWA_URL, OWA_APP_PASSWORD and OWA_SITE_ID (CI mints the password with
 * cli.php cmd=app-password-create and publishes the bundles first).
 */
#[Group( 'owa2' )]
class OwaV2ServerTest extends TestCase {

	protected function setUp(): void {

		parent::setUp();

		foreach ( [ 'OWA_URL', 'OWA_APP_PASSWORD', 'OWA_SITE_ID' ] as $name ) {

			if ( ! getenv( $name ) ) {

				$this->markTestSkipped( "$name is not set; these tests need a running OWA v2 server." );
			}
		}
	}

	private function plugin( array $options = [] ): \owaWp_plugin {

		// CI serves OWA with php -S over plain http, with app_passwords_require_https off
		if ( str_starts_with( (string) getenv( 'OWA_URL' ), 'http://' ) ) {

			Filters\expectApplied( 'owa_wp_sdk_config' )
				->zeroOrMoreTimes()
				->andReturnUsing( fn( $config ) => $config + [ 'allow_insecure_app_password' => true ] );
		}

		$plugin = $this->makePlugin( $options + [
			'owaVersion'  => '2',
			'owaEndpoint' => getenv( 'OWA_URL' ),
			'appPassword' => getenv( 'OWA_APP_PASSWORD' ),
			'siteId'      => getenv( 'OWA_SITE_ID' ),
		] );

		$plugin->initOwaSdk();

		return $plugin;
	}

	/**
	 * @return array{0: int, 1: string, 2: string} status, headers, body
	 */
	private static function get( string $url ): array {

		$body = file_get_contents( $url, false, stream_context_create( [ 'http' => [ 'ignore_errors' => true, 'follow_location' => 0 ] ] ) );

		preg_match( '#^HTTP/\S+ (\d{3})#', $http_response_header[0], $m );

		return [ (int) $m[1], implode( "\n", $http_response_header ), (string) $body ];
	}

	public function testTheBundleIsServedWhereTheTagLoadsIt(): void {

		$tag = $this->plugin()->getBundleSnippet( '' );

		$this->assertMatchesRegularExpression( '#<script async src="([^"]+)"></script>#', $tag );
		preg_match( '#<script async src="([^"]+)"></script>#', $tag, $m );

		[ $status, $headers, $body ] = self::get( $m[1] );

		$this->assertSame( 200, $status, "GET {$m[1]}" );
		$this->assertMatchesRegularExpression( '#^Content-Type: \S*javascript#mi', $headers );
		$this->assertStringStartsWith( '/* owa-bundle ', $body );
		$this->assertStringContainsString( json_encode( [ 'setSiteId', getenv( 'OWA_SITE_ID' ) ] ), $body,
			'The bundle sets the site ID the plugin chose.' );
	}

	public function testThePickerLoadsWithTheApplicationPassword(): void {

		$plugin = $this->plugin( [ 'siteId' => '' ] );
		$sites = $plugin->getSitesFromOwa();

		$this->assertSame( '', $plugin->getSitesNotice() );

		$this->assertArrayHasKey( getenv( 'OWA_SITE_ID' ), $sites );
		$this->assertStringContainsString( 'wp.example.test', $sites[ getenv( 'OWA_SITE_ID' ) ]['label'] );
	}

	public function testAWrongApplicationPasswordIsReportedUnderThePicker(): void {

		// well formed, so OWA looks it up, and unknown
		$plugin = $this->plugin( [ 'appPassword' => str_repeat( 'Z', 44 ), 'siteId' => 'saved1' ] );
		$sites = $plugin->getSitesFromOwa();

		$this->assertSame( [ 'saved1' ], array_keys( $sites ) );
		$this->assertStringStartsWith( 'Could not load the list of Observation Profiles from OWA: ', $plugin->getSitesNotice() );
	}

	public function testTheTrackingTagLinkIsARealScreen(): void {

		[ $status, $headers ] = self::get( $this->plugin()->getTagSettingsUrl() );

		// signed out: OWA sends the browser to log in and back to this screen
		$this->assertSame( 302, $status );
		$this->assertMatchesRegularExpression( '#^Location: .*base\.loginForm.*sitesInvocation#mi', $headers );
	}
}
