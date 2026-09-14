<?php
/**
 * Insert or preview the bot comment.
 *
 * @package BotCommentAssist
 */

namespace BotCommentAssist;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes one approved comment per post as the configured bot identity.
 */
class Poster {
	public const COMMENT_META = '_bot_comment_assist';
	public const COMMENT_ID_META = '_bot_comment_assist_comment_id';
	public const SKIP_META = '_bot_comment_assist_skip';
	public const LAST_ERROR_META = '_bot_comment_assist_last_error';

	/**
	 * @var Options
	 */
	private $options;

	/**
	 * @var Matcher
	 */
	private $matcher;

	/**
	 * @var Composer
	 */
	private $composer;

	public function __construct( Options $options, Matcher $matcher, Composer $composer ) {
		$this->options  = $options;
		$this->matcher  = $matcher;
		$this->composer = $composer;
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public function preview( int $postId ) {
		$post = $this->matcher->requirePost( $postId );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$rule = $this->matcher->matchRule( $post );
		if ( $rule === null ) {
			return new \WP_Error( 'no_rule', '沒有符合的自動留言規則。' );
		}
		$composed = $this->composer->compose( $post, $rule );
		if ( is_wp_error( $composed ) ) {
			return $composed;
		}
		$composed['id']            = $post->ID;
		$composed['alreadyPosted'] = $this->matcher->existingCommentId( $post->ID ) > 0;
		$composed['skipped']       = get_post_meta( $post->ID, self::SKIP_META, true ) === '1';
		$composed['identity']      = $this->publicIdentity();
		return $composed;
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public function enterPost( int $postId, bool $force = false, bool $dryRun = false ) {
		$post = $this->matcher->requirePost( $postId );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( (string) $this->options->get( 'enabled', '1' ) !== '1' && $force !== true ) {
			return new \WP_Error( 'disabled', 'Bot Comment Assist 目前已停用。' );
		}
		if ( $post->post_status !== 'publish' ) {
			return new \WP_Error( 'not_published', '文章尚未發佈，不會自動留言。' );
		}
		if ( post_type_supports( $post->post_type, 'comments' ) !== true ) {
			return new \WP_Error( 'no_comments', '這個文章類型不支援留言。' );
		}
		if ( get_post_meta( $postId, self::SKIP_META, true ) === '1' && $force !== true ) {
			return new \WP_Error( 'skipped', '這篇文章已勾選略過自動留言。' );
		}

		$existing = $this->matcher->existingCommentId( $postId );
		if ( $existing > 0 && (string) $this->options->get( 'oncePerPost', '1' ) === '1' && $force !== true ) {
			return array(
				'id'        => $postId,
				'commentId' => $existing,
				'status'    => 'already-posted',
				'message'   => '這篇文章已經有一則站內小幫手留言。',
			);
		}

		$lockKey = 'bot_comment_assist_posting_' . $postId;
		if ( $dryRun !== true && get_transient( $lockKey ) && $force !== true ) {
			return new \WP_Error( 'in_progress', '這篇文章正在寫入助手留言，請稍後再試。' );
		}
		if ( $dryRun !== true ) {
			$setLock = set_transient( $lockKey, '1', MINUTE_IN_SECONDS );
			if ( false === $setLock && get_transient( $lockKey ) !== '1' ) {
				error_log( 'Bot Comment Assist: failed to set posting lock for post ' . $postId );
			}
		}

		$rule = $this->matcher->matchRule( $post );
		if ( $rule === null ) {
			$this->clearLock( $lockKey, $dryRun );
			return new \WP_Error( 'no_rule', '沒有符合的自動留言規則。' );
		}

		$composed = $this->composer->compose( $post, $rule );
		if ( is_wp_error( $composed ) ) {
			$this->clearLock( $lockKey, $dryRun );
			update_post_meta( $postId, self::LAST_ERROR_META, $composed->get_error_message() );
			return $composed;
		}

		$commentText = trim( (string) ( $composed['comment'] ?? '' ) );
		if ( $commentText === '' ) {
			$this->clearLock( $lockKey, $dryRun );
			$error = new \WP_Error( 'empty_comment', '產生的留言是空白的，已中止。' );
			update_post_meta( $postId, self::LAST_ERROR_META, $error->get_error_message() );
			return $error;
		}

		if ( $dryRun === true ) {
			$composed['id']     = $postId;
			$composed['status'] = 'dry-run';
			return $composed;
		}

		$identity = $this->options->botIdentity();
		$data     = array(
			'comment_post_ID'      => $postId,
			'comment_author'       => $identity['name'],
			'comment_author_email' => $identity['email'],
			'comment_author_url'   => $identity['url'],
			'comment_content'      => $commentText,
			'comment_type'         => 'comment',
			'comment_parent'       => 0,
			'user_id'              => $identity['userId'],
			'comment_author_IP'    => '127.0.0.1',
			'comment_agent'        => 'Bot Comment Assist/' . BOT_COMMENT_ASSIST_VERSION,
			'comment_approved'     => 1,
			'comment_date'         => current_time( 'mysql' ),
			'comment_date_gmt'     => current_time( 'mysql', true ),
		);

		$commentId = wp_insert_comment( $data );
		if ( ! is_int( $commentId ) || $commentId <= 0 ) {
			$this->clearLock( $lockKey, false );
			$error = new \WP_Error( 'insert_failed', '寫入留言失敗，WordPress 沒有回傳有效的留言 ID。' );
			error_log( 'Bot Comment Assist: wp_insert_comment failed for post ' . $postId . '.' );
			update_post_meta( $postId, self::LAST_ERROR_META, $error->get_error_message() );
			return $error;
		}

		$metaOk = update_comment_meta( $commentId, self::COMMENT_META, '1' );
		if ( false === $metaOk && get_comment_meta( $commentId, self::COMMENT_META, true ) !== '1' ) {
			error_log( 'Bot Comment Assist: failed to mark comment ' . $commentId . ' as a bot comment.' );
		}
		update_post_meta( $postId, self::COMMENT_ID_META, $commentId );
		delete_post_meta( $postId, self::LAST_ERROR_META );
		$this->clearLock( $lockKey, false );

		return array(
			'id'        => $postId,
			'commentId' => $commentId,
			'status'    => 'posted',
			'source'    => (string) ( $composed['source'] ?? '' ),
			'ruleName'  => (string) ( $composed['ruleName'] ?? '' ),
			'comment'   => $commentText,
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function listPosts( string $status, int $limit ): array {
		if ( ! in_array( $status, array( 'publish', 'draft', 'pending', 'future', 'any' ), true ) ) {
			error_log( 'Bot Comment Assist: invalid list status ' . $status . '.' );
			$status = 'publish';
		}
		$limit = max( 1, min( 50, $limit ) );
		$query = new \WP_Query(
			array(
				'post_type'              => 'post',
				'post_status'            => $status === 'any' ? array( 'publish', 'draft', 'pending', 'future' ) : $status,
				'posts_per_page'         => $limit,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
			)
		);
		$rows = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				error_log( 'Bot Comment Assist: listPosts skipped a non-post result.' );
				continue;
			}
			$rule = $this->matcher->matchRule( $post );
			$rows[] = array(
				'id'            => $post->ID,
				'title'         => (string) $post->post_title,
				'status'        => (string) $post->post_status,
				'permalink'     => (string) get_permalink( $post ),
				'ruleName'      => is_array( $rule ) ? (string) ( $rule['name'] ?? '' ) : '',
				'alreadyPosted' => $this->matcher->existingCommentId( $post->ID ) > 0,
				'skipped'       => get_post_meta( $post->ID, self::SKIP_META, true ) === '1',
			);
		}
		return $rows;
	}

	/**
	 * @return array{name:string,url:string}
	 */
	private function publicIdentity(): array {
		$identity = $this->options->botIdentity();
		return array(
			'name' => $identity['name'],
			'url'  => $identity['url'],
		);
	}

	private function clearLock( string $lockKey, bool $dryRun ): void {
		if ( $dryRun === true ) {
			return;
		}
		$deleted = delete_transient( $lockKey );
		if ( false === $deleted && get_transient( $lockKey ) ) {
			error_log( 'Bot Comment Assist: failed to clear posting lock ' . $lockKey );
		}
	}
}
