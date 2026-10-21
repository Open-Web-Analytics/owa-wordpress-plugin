<?php

namespace owaWp\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

class ServerSideTrackingTest extends TestCase {

	private function readyPlugin( array $options = [] ): \owaWp_plugin {

		return $this->makePlugin( $options + [
			'owaEndpoint' => 'https://owa.example.com/',
			'apiKey'      => 'key',
			'authKey'     => 'secret',
			'siteId'      => 'configured-site',
		] );
	}

	/**
	 * Records what the plugin hands the SDK tracker, with SDK 1.1's types.
	 */
	private function recordingSdk(): object {

		$tracker = new class {

			public array $calls = [];

			public function setSiteId( string $id ): void { $this->calls[] = [ 'setSiteId', $id ]; }

			public function trackAction( string $name, string $group = '', string $label = '' ): bool {
				$this->calls[] = [ 'trackAction', $name, $group, $label ];
				return true;
			}

			public function trackSyndicationFeed( string $format = 'rss', string $subscription = '' ): bool {
				$this->calls[] = [ 'trackSyndicationFeed', $format, $subscription ];
				return true;
			}
		};

		return new class( $tracker ) {

			public function __construct( public object $tracker ) {}

			public function createTracker(): object { return $this->tracker; }
		};
	}

	public function testTrackerUsesTheConfiguredSiteIdWithTheRealSdk(): void {

		$plugin = $this->readyPlugin();
		$plugin->initOwaSdk();

		$tracker = $plugin->getOwaTrackerInstance();

		$this->assertInstanceOf( \OwaSdk\Tracker\TrackerClient::class, $tracker );
		$this->assertSame( 'configured-site', $tracker->getSiteId() );
	}

	public function testFeedRequestSendsTheSubscriptionId(): void {

		Functions\when( 'is_feed' )->justReturn( true );
		Functions\when( 'get_query_var' )->alias( fn( $var ) => [ 'feed' => 'rss2', 'owa_sid' => '42' ][ $var ] ?? '' );

		$plugin = $this->readyPlugin( [ 'trackFeeds' => true ] );
		$plugin->owaSdk = $this->recordingSdk();

		$plugin->trackFeedRequest();

		$this->assertContains( [ 'trackSyndicationFeed', 'rss2', '42' ], $plugin->owaSdk->tracker->calls );
	}

	public function testNewBlogIsTrackedFromWpInitializeSite(): void {

		Actions\expectAdded( 'wp_initialize_site' )->once();
		Actions\expectAdded( 'wpmu_new_blog' )->never();

		$plugin = $this->readyPlugin( [ 'trackAdminActions' => true ] );
		$plugin->defineActionHooks();

		$plugin->owaSdk = $this->recordingSdk();
		$plugin->trackNewBlogAction( (object) [ 'domain' => 'blog.example.com' ] );

		$this->assertSame( [
			[ 'setSiteId', 'configured-site' ],
			[ 'trackAction', 'Blog Created', 'WordPress', 'blog.example.com' ],
		], $plugin->owaSdk->tracker->calls );
	}

	public function testNothingIsSentBeforeASiteIsChosen(): void {

		$plugin = $this->readyPlugin( [ 'siteId' => '' ] );
		$plugin->owaSdk = $this->recordingSdk();

		$this->assertNull( $plugin->getOwaTrackerInstance() );
	}
}
