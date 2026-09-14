<?php
/**
 * Queue a bot comment after a post is published.
 *
 * @package BotCommentAssist
 */

namespace BotCommentAssist;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Publishes one bot comment on first publish, never on crawler pageviews.
 */
class Hooks {
	/**
	 * @var Options
	 */
	private $options;

	/**
	 * @var Poster
	 */
	private $poster;

	public function __construct( Options $options, Poster $poster ) {
		$this->options = $options;
		$this->poster  = $poster;
	}

	public function register(): void {
		add_action( 'transition_post_status', array( $this, 'onStatusChange' ), 20, 3 );
		add_action( 'set_object_terms', array( $this, 'onTermsAssigned' ), 20, 4 );
		add_action( BOT_COMMENT_ASSIST_CRON_HOOK, array( $this, 'runQueued' ), 10, 1 );
		add_action( 'shutdown', array( $this, 'flushQueue' ) );
		add_filter( 'comment_class', array( $this, 'commentClass' ), 10, 3 );
		add_filter( 'get_comment_author', array( $this, 'commentAuthorLabel' ), 10, 3 );
	}

	/**
	 * @param mixed $post Post object.
	 */
	public function onStatusChange( string $newStatus, string $oldStatus, $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		if ( $post->post_type !== 'post' ) {
			return;
		}
		if ( $newStatus !== 'publish' || $oldStatus === 'publish' ) {
			return;
		}
		if ( (string) $this->options->get( 'enabled', '1' ) !== '1' ) {
			return;
		}
		if ( (string) $this->options->get( 'autoOnPublish', '1' ) !== '1' ) {
			return;
		}
		$this->queue( (int) $post->ID );
	}

	/**
	 * Queue again if the matching category is attached after publish.
	 *
	 * @param mixed $objectId Object ID.
	 * @param mixed $terms    Unused.
	 * @param mixed $ttIds    Unused.
	 */
	public function onTermsAssigned( $objectId, $terms, $ttIds, string $taxonomy ): void {
		unset( $terms, $ttIds );
		$postId = (int) $objectId;
		if ( $taxonomy !== 'category' || $postId <= 0 ) {
			return;
		}
		if ( (string) $this->options->get( 'enabled', '1' ) !== '1' ) {
			return;
		}
		if ( (string) $this->options->get( 'autoOnPublish', '1' ) !== '1' ) {
			return;
		}
		$post = get_post( $postId );
		if ( ! $post instanceof \WP_Post || $post->post_type !== 'post' || $post->post_status !== 'publish' ) {
			return;
		}
		if ( absint( get_post_meta( $postId, Poster::COMMENT_ID_META, true ) ) > 0 ) {
			return;
		}
		$this->queue( $postId );
	}

	public function queue( int $postId ): void {
		if ( $postId <= 0 ) {
			error_log( 'Bot Comment Assist: refused to queue an invalid post ID.' );
			return;
		}
		if ( get_post_meta( $postId, Poster::SKIP_META, true ) === '1' ) {
			return;
		}
		$lockKey = 'bot_comment_assist_queued_' . $postId;
		if ( get_transient( $lockKey ) ) {
			return;
		}
		$queued = set_transient( $lockKey, '1', 2 * MINUTE_IN_SECONDS );
		if ( false === $queued ) {
			error_log( 'Bot Comment Assist: failed to set queue lock for post ' . $postId );
		}

		$GLOBALS['botCommentAssistPending']   = $GLOBALS['botCommentAssistPending'] ?? array();
		$GLOBALS['botCommentAssistPending'][] = $postId;

		$scheduled = wp_schedule_single_event( time() + 20, BOT_COMMENT_ASSIST_CRON_HOOK, array( $postId ) );
		if ( false === $scheduled && ! wp_next_scheduled( BOT_COMMENT_ASSIST_CRON_HOOK, array( $postId ) ) ) {
			error_log( 'Bot Comment Assist: wp-cron would not schedule post ' . $postId . '; will post on shutdown.' );
		}
	}

	public function flushQueue(): void {
		$pending = $GLOBALS['botCommentAssistPending'] ?? array();
		if ( ! is_array( $pending ) || $pending === array() ) {
			return;
		}
		$GLOBALS['botCommentAssistPending'] = array();
		foreach ( array_unique( array_map( 'intval', $pending ) ) as $postId ) {
			if ( $postId <= 0 ) {
				continue;
			}
			$this->runQueued( $postId );
		}
	}

	public function runQueued( int $postId ): void {
		$result = $this->poster->enterPost( $postId, false, false );
		if ( is_wp_error( $result ) ) {
			error_log( 'Bot Comment Assist: queued comment failed for post ' . $postId . ': ' . $result->get_error_message() );
		}
	}

	/**
	 * @param array<int, string> $classes
	 * @param mixed              $cssClass Unused.
	 * @param mixed              $commentId Comment ID.
	 * @return array<int, string>
	 */
	public function commentClass( array $classes, $cssClass, $commentId ): array {
		unset( $cssClass );
		$commentId = (int) $commentId;
		if ( $commentId <= 0 || get_comment_meta( $commentId, Poster::COMMENT_META, true ) !== '1' ) {
			return $classes;
		}
		$classes[] = 'bot-comment-assist';
		return $classes;
	}

	/**
	 * Keep the author name honest: this is a site assistant, not a reader.
	 *
	 * @param mixed $commentId Comment ID.
	 * @param mixed $comment   Comment object or ID.
	 */
	public function commentAuthorLabel( string $author, $commentId, $comment ): string {
		unset( $comment );
		$commentId = (int) $commentId;
		if ( $commentId <= 0 || get_comment_meta( $commentId, Poster::COMMENT_META, true ) !== '1' ) {
			return $author;
		}
		$label = '（站內助手）';
		if ( $author === '' ) {
			return '站內小幫手' . $label;
		}
		if ( str_contains( $author, '站內助手' ) ) {
			return $author;
		}
		return $author . $label;
	}
}
