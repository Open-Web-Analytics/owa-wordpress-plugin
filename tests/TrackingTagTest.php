<?php

namespace owaWp\Tests;

use Brain\Monkey\Functions;

class TrackingTagTest extends TestCase {

	private function renderTag( \owaWp_plugin $plugin ): string {

		ob_start();
		$plugin->insertTrackingTag();

		return ob_get_clean();
	}

	private function plugin(): \owaWp_plugin {

		return $this->makePlugin( [
			'owaEndpoint' => 'https://owa.example.com:8443/owa/',
			'siteId'      => 'abc123',
		] );
	}

	public function testTagLoadsTheTrackerFromOwa114sPath(): void {

		$tag = $this->plugin()->getTrackerSnippet( '' );

		$this->assertStringContainsString( "_owa.src = owa_baseUrl + 'public/base/dist/owa.tracker.js';", $tag );
		$this->assertStringNotContainsString( 'owa.tracker-combined-min.js', $tag );
		$this->assertStringContainsString( '<link rel="preconnect" href="//owa.example.com:8443">', $tag );
		$this->assertStringContainsString( 'var owa_baseUrl = "https://owa.example.com:8443/owa/";', $tag );
	}

	public function testBaseUrlCannotCloseTheScriptElement(): void {

		$plugin = $this->makePlugin( [ 'owaEndpoint' => 'https://owa.example.com/owa/?x=</script><script>alert(1)//' ] );

		$tag = $plugin->getTrackerSnippet( '' );

		$this->assertSame( 1, substr_count( $tag, '</script>' ) );
	}

	public function testCmdArgumentsAreJsonEncoded(): void {

		$hostile = "it's \"quoted\" \\ </script><script>alert(1)</script>";

		$cmd = $this->plugin()->makeCmd( 'setPageTitle', $hostile );

		$this->assertStringNotContainsString( '<', $cmd );
		$this->assertSame( [ [ 'setPageTitle', $hostile ] ], $this->pushedCmds( $cmd ) );
	}

	public function testTagCarriesSiteIdPageTypeTitleAndPageView(): void {

		$this->stubConditionals( [ 'is_single' ] );
		Functions\when( 'wp_title' )->justReturn( 'Hello &#8211; world' );

		$cmds = $this->pushedCmds( $this->renderTag( $this->plugin() ) );

		$this->assertContains( [ 'setSiteId', 'abc123' ], $cmds );
		$this->assertContains( [ 'setPageType', 'Post' ], $cmds );
		$this->assertContains( [ 'setPageTitle', "Hello \u{2013} world" ], $cmds, 'Entities are decoded to the text a browser shows.' );
		$this->assertSame( [ 'trackPageView' ], end( $cmds ) );
	}

	public function testEmptyTitleLeavesDocumentTitleAlone(): void {

		// wp_title() on a static front page.
		$this->stubConditionals( [ 'is_page' ] );
		Functions\when( 'wp_title' )->justReturn( '' );

		$names = array_column( $this->pushedCmds( $this->renderTag( $this->plugin() ) ), 0 );

		$this->assertNotContains( 'setPageTitle', $names );
	}

	public function testSearchTitleIsNotDoubleEscaped(): void {

		$this->stubConditionals( [ 'is_search' ] );
		Functions\expect( 'get_search_query' )->once()->with( false )->andReturn( 'cats & dogs' );

		$cmds = $this->pushedCmds( $this->renderTag( $this->plugin() ) );

		$this->assertContains( [ 'setPageTitle', 'Search Results for "cats & dogs"' ], $cmds );
	}

	public function testUserNameIsSentOnlyForLoggedInUsers(): void {

		$this->stubConditionals( [ 'is_single' ] );
		Functions\when( 'wp_title' )->justReturn( 'Post' );

		$plugin = $this->plugin();
		$plugin->setOption( 'trackNamedUsers', true );

		$names = array_column( $this->pushedCmds( $this->renderTag( $plugin ) ), 0 );
		$this->assertNotContains( 'setUserName', $names, 'A visitor who is not logged in has no user name.' );

		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'wp_get_current_user' )->justReturn( (object) [ 'user_login' => 'editor1' ] );

		$this->assertContains( [ 'setUserName', 'editor1' ], $this->pushedCmds( $this->renderTag( $plugin ) ) );
	}

	public function testEachTagStartsWithNoCommandsFromTheLastOne(): void {

		$this->stubConditionals( [ 'is_single' ] );
		Functions\when( 'wp_title' )->justReturn( 'Post' );

		$plugin = $this->plugin();
		$this->renderTag( $plugin );

		$names = array_column( $this->pushedCmds( $this->renderTag( $plugin ) ), 0 );

		$this->assertSame( 1, count( array_keys( $names, 'trackPageView', true ) ) );
	}

	public function testNoTagUntilTheEndpointAndSiteIdAreSet(): void {

		$this->stubConditionals( [ 'is_single' ] );
		Functions\when( 'wp_title' )->justReturn( 'Post' );

		$this->assertSame( '', $this->renderTag( $this->makePlugin() ), 'Freshly activated: nothing configured.' );

		$this->assertSame( '', $this->renderTag( $this->makePlugin( [ 'owaEndpoint' => 'https://owa.example.com/' ] ) ),
			'An endpoint but no site ID.' );

		$this->assertSame( '', $this->renderTag( $this->makePlugin( [ 'siteId' => 'abc123' ] ) ),
			'A site ID but no endpoint: the tracker URL would resolve against the page.' );

		$this->assertStringContainsString( 'owa.tracker.js', $this->renderTag( $this->plugin() ) );
	}

	public function testAdminPagesAreNotTaggedUnlessEnabled(): void {

		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'get_current_screen' )->justReturn( null );

		$this->assertSame( '', $this->renderTag( $this->plugin() ) );
	}

	public function testOwnSettingsScreenIsNeverTagged(): void {

		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'get_current_screen' )->justReturn( (object) [ 'id' => 'owa_page_owa-analytics' ] );

		$plugin = $this->plugin();
		$plugin->setOption( 'trackAdminPages', true );

		$this->assertSame( '', $this->renderTag( $plugin ) );
	}
}
