<?php

namespace owaWp\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

/**
 * Unit tests run without WordPress: Brain Monkey supplies the hook API and
 * each test stubs the template tags it depends on.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {

		parent::setUp();
		Monkey\setUp();

		Functions\stubEscapeFunctions();
		Functions\stubTranslationFunctions();

		Functions\stubs( [
			'plugin_dir_path'          => fn( $file ) => dirname( $file ) . '/',
			'register_activation_hook' => null,
			'wp_doing_cron'            => false,
			'esc_url_raw'              => fn( $url ) => $url,
			'wp_parse_url'             => fn( $url, $component = -1 ) => parse_url( $url, $component ),
			'wp_strip_all_tags'        => fn( $text ) => trim( strip_tags( $text ) ),
			'wp_kses'                  => fn( $html ) => $html,
			'is_user_logged_in'        => false,
			'is_admin'                 => false,
			'is_preview'               => false,
			'is_customize_preview'     => false,
			'add_settings_section'     => null,
			'is_multisite'             => false,
		] );

		require_once dirname( __DIR__ ) . '/owa-plugin.php';
	}

	protected function tearDown(): void {

		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A plugin instance with the given options set, as initOptions() would
	 * leave them after reading the owa_wp option.
	 */
	protected function makePlugin( array $options = [] ): \owaWp_plugin {

		$plugin = new \owaWp_plugin();

		foreach ( $options as $key => $value ) {

			$plugin->setOption( $key, $value );
		}

		return $plugin;
	}

	/**
	 * Every WordPress conditional getPageType() asks, false unless named.
	 */
	protected function stubConditionals( array $true = [] ): void {

		$tags = [ 'is_home', 'is_attachment', 'is_page', 'is_single', 'is_feed', 'is_author',
			'is_category', 'is_search', 'is_month', 'is_day', 'is_year', 'is_time', 'is_tag',
			'is_tax', 'is_archive' ];

		foreach ( $tags as $tag ) {

			Functions\when( $tag )->justReturn( in_array( $tag, $true, true ) );
		}
	}

	/**
	 * The owa_cmds.push() arguments in a tag, decoded, in order.
	 */
	protected function pushedCmds( string $tag ): array {

		preg_match_all( '/^owa_cmds\.push\((.*)\);\s*$/m', $tag, $m );

		return array_map( fn( $json ) => json_decode( $json, true ), $m[1] );
	}
}
