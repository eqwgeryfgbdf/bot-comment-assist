<?php
/**
 * Match the first enabled comment rule for a post.
 *
 * @package BotCommentAssist
 */

namespace BotCommentAssist;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Category / tag / title matching, same idea as Permalink Rule Assist.
 */
class Matcher {
	/**
	 * @var Options
	 */
	private $options;

	public function __construct( Options $options ) {
		$this->options = $options;
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public function preview( int $postId ) {
		$post = $this->requirePost( $postId );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$matched = $this->matchRule( $post );
		if ( $matched === null ) {
			return new \WP_Error( 'no_rule', '沒有符合的自動留言規則。' );
		}
		return array(
			'id'         => $post->ID,
			'title'      => (string) $post->post_title,
			'status'     => (string) $post->post_status,
			'permalink'  => (string) get_permalink( $post ),
			'ruleName'   => (string) ( $matched['name'] ?? '' ),
			'useAi'      => (string) ( $matched['useAi'] ?? '' ) === '1',
			'template'   => (string) ( $matched['commentTemplate'] ?? '' ),
			'alreadyPosted' => $this->existingCommentId( $post->ID ) > 0,
			'skipped'    => get_post_meta( $post->ID, '_bot_comment_assist_skip', true ) === '1',
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function matchRule( \WP_Post $post ): ?array {
		$categorySlugs = $this->termSlugs( $post->ID, 'category' );
		$tagSlugs      = $this->termSlugs( $post->ID, 'post_tag' );
		$title         = (string) $post->post_title;

		foreach ( $this->options->rules() as $rule ) {
			if ( ! is_array( $rule ) ) {
				error_log( 'Bot Comment Assist: skipped a non-array rule.' );
				continue;
			}
			if ( (string) ( $rule['enabled'] ?? '' ) !== '1' ) {
				continue;
			}
			if ( $this->ruleMatches( $rule, $categorySlugs, $tagSlugs, $title ) ) {
				return $rule;
			}
		}
		return null;
	}

	public function existingCommentId( int $postId ): int {
		$stored = absint( get_post_meta( $postId, '_bot_comment_assist_comment_id', true ) );
		if ( $stored > 0 ) {
			$comment = get_comment( $stored );
			if ( $comment instanceof \WP_Comment && (int) $comment->comment_post_ID === $postId ) {
				return $stored;
			}
			error_log( 'Bot Comment Assist: stored comment ID ' . $stored . ' is missing for post ' . $postId . '.' );
		}

		$comments = get_comments(
			array(
				'post_id'    => $postId,
				'meta_key'   => '_bot_comment_assist',
				'meta_value' => '1',
				'number'     => 1,
				'status'     => 'all',
			)
		);
		if ( ! is_array( $comments ) || $comments === array() ) {
			return 0;
		}
		$comment = $comments[0];
		if ( ! $comment instanceof \WP_Comment ) {
			error_log( 'Bot Comment Assist: existing bot comment query returned a non-comment for post ' . $postId . '.' );
			return 0;
		}
		return (int) $comment->comment_ID;
	}

	/**
	 * @param array<string, mixed> $rule
	 * @param array<int, string>   $categorySlugs
	 * @param array<int, string>   $tagSlugs
	 */
	private function ruleMatches( array $rule, array $categorySlugs, array $tagSlugs, string $title ): bool {
		$categories = $this->csvList( (string) ( $rule['categories'] ?? '' ) );
		$tags       = $this->csvList( (string) ( $rule['tags'] ?? '' ) );
		$titleRegex = trim( (string) ( $rule['titleRegex'] ?? '' ) );

		$hasCondition = $categories !== array() || $tags !== array() || $titleRegex !== '';
		if ( ! $hasCondition ) {
			return true;
		}
		foreach ( $categories as $slug ) {
			if ( in_array( $slug, $categorySlugs, true ) ) {
				return true;
			}
		}
		foreach ( $tags as $slug ) {
			if ( in_array( $slug, $tagSlugs, true ) ) {
				return true;
			}
		}
		if ( $titleRegex !== '' ) {
			$pattern = '/' . $titleRegex . '/u';
			$set     = preg_match( $pattern, $title );
			if ( $set === false ) {
				error_log( 'Bot Comment Assist: invalid titleRegex: ' . $titleRegex );
				return false;
			}
			if ( $set === 1 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return array<int, string>
	 */
	private function termSlugs( int $postId, string $taxonomy ): array {
		if ( $postId <= 0 ) {
			return array();
		}
		$terms = wp_get_post_terms( $postId, $taxonomy, array( 'fields' => 'slugs' ) );
		if ( is_wp_error( $terms ) ) {
			error_log( 'Bot Comment Assist: failed to load ' . $taxonomy . ' for post ' . $postId . ': ' . $terms->get_error_message() );
			return array();
		}
		if ( ! is_array( $terms ) ) {
			return array();
		}
		return array_values( array_map( 'strval', $terms ) );
	}

	/**
	 * @return array<int, string>
	 */
	private function csvList( string $csv ): array {
		$out = array();
		foreach ( explode( ',', $csv ) as $item ) {
			$key = sanitize_key( trim( $item ) );
			if ( $key !== '' ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	/**
	 * @return \WP_Post|\WP_Error
	 */
	public function requirePost( int $postId ) {
		if ( $postId <= 0 ) {
			return new \WP_Error( 'invalid_id', '文章 ID 必須是正整數。' );
		}
		$post = get_post( $postId );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'not_found', '找不到這篇文章。' );
		}
		if ( $post->post_type !== 'post' ) {
			return new \WP_Error( 'wrong_type', '自動留言只處理一般文章，不處理 ID ' . $postId . ' 這個類型。' );
		}
		return $post;
	}
}
