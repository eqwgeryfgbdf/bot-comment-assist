<?php
/**
 * Plugin bootstrap.
 *
 * @package BotCommentAssist
 */

namespace BotCommentAssist;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires options, matching, commenting, MCP abilities, and admin UI.
 */
class Plugin {
	/**
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * @var Options
	 */
	private $options;

	/**
	 * @return Plugin
	 */
	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function activate(): void {
		$plugin = self::instance();
		$plugin->options->load();
		if ( get_option( Options::OPTION_KEY, null ) === null ) {
			add_option( Options::OPTION_KEY, Options::defaults(), '', false );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( BOT_COMMENT_ASSIST_CRON_HOOK );
	}

	private function __construct() {
		$this->options = new Options();
	}

	public function boot(): void {
		$this->options->load();

		$matcher  = new Matcher( $this->options );
		$composer = new Composer( $this->options );
		$poster   = new Poster( $this->options, $matcher, $composer );

		( new Hooks( $this->options, $poster ) )->register();
		( new Abilities( $poster ) )->register();
		( new Admin( $this->options ) )->register();
		( new Metabox( $matcher, $poster ) )->register();
	}

	public function options(): Options {
		return $this->options;
	}
}
