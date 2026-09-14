<?php
/**
 * Plugin Name: Bot Comment Assist
 * Description: Lets a labeled site bot leave one rule-based comment when a post is published (not when crawlers visit).
 * Version: 1.0.0
 * Requires at least: 6.8
 * Requires PHP: 8.0
 * Author: Roy
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: bot-comment-assist
 *
 * @package BotCommentAssist
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BOT_COMMENT_ASSIST_VERSION', '1.0.0' );
define( 'BOT_COMMENT_ASSIST_FILE', __FILE__ );
define( 'BOT_COMMENT_ASSIST_DIR', plugin_dir_path( __FILE__ ) );
define( 'BOT_COMMENT_ASSIST_CRON_HOOK', 'bot_comment_assist_post' );

require_once BOT_COMMENT_ASSIST_DIR . 'includes/class-options.php';
require_once BOT_COMMENT_ASSIST_DIR . 'includes/class-matcher.php';
require_once BOT_COMMENT_ASSIST_DIR . 'includes/class-composer.php';
require_once BOT_COMMENT_ASSIST_DIR . 'includes/class-poster.php';
require_once BOT_COMMENT_ASSIST_DIR . 'includes/class-hooks.php';
require_once BOT_COMMENT_ASSIST_DIR . 'includes/class-abilities.php';
require_once BOT_COMMENT_ASSIST_DIR . 'includes/class-admin.php';
require_once BOT_COMMENT_ASSIST_DIR . 'includes/class-metabox.php';
require_once BOT_COMMENT_ASSIST_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'BotCommentAssist\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BotCommentAssist\\Plugin', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		\BotCommentAssist\Plugin::instance()->boot();
	}
);
