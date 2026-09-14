<?php
/**
 * Remove plugin settings and stored preview meta.
 *
 * @package BotCommentAssist
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$deleted = delete_option( 'bot_comment_assist' );
if ( false === $deleted && get_option( 'bot_comment_assist', null ) !== null ) {
	error_log( 'Bot Comment Assist: uninstall could not delete settings.' );
}

$previewDeleted = delete_post_meta_by_key( '_bot_comment_assist_preview' );
if ( false === $previewDeleted ) {
	error_log( 'Bot Comment Assist: uninstall could not delete preview meta.' );
}

$errorDeleted = delete_post_meta_by_key( '_bot_comment_assist_last_error' );
if ( false === $errorDeleted ) {
	error_log( 'Bot Comment Assist: uninstall could not delete last-error meta.' );
}
