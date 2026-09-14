<?php
/**
 * WordPress Abilities / MCP tools.
 *
 * @package BotCommentAssist
 */

namespace BotCommentAssist;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets an AI agent preview or enter a post and leave one comment.
 */
class Abilities {
	/**
	 * @var Poster
	 */
	private $poster;

	public function __construct( Poster $poster ) {
		$this->poster = $poster;
	}

	public function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'registerCategory' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'registerAbilities' ) );
	}

	public function registerCategory(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			'bot-comment',
			array(
				'label'       => 'Bot comments',
				'description' => 'Let a labeled site bot enter a published post and leave one rule-based comment.',
			)
		);
	}

	public function registerAbilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			error_log( 'Bot Comment Assist: wp_register_ability is unavailable; MCP tools were not registered.' );
			return;
		}

		$mcpMeta = array(
			'mcp'          => array( 'public' => true ),
			'show_in_rest' => true,
		);

		wp_register_ability(
			'bot-comment/list-posts',
			array(
				'label'               => 'List posts for bot comments',
				'description'         => 'List recent posts and whether the site bot has already commented.',
				'category'            => 'bot-comment',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'status' => array(
							'type'        => 'string',
							'description' => 'publish, draft, pending, future, or any',
							'default'     => 'publish',
						),
						'limit'  => array(
							'type'        => 'integer',
							'description' => 'How many posts to scan (max 50)',
							'default'     => 10,
						),
					),
				),
				'permission_callback' => static function () {
					return current_user_can( 'moderate_comments' );
				},
				'execute_callback'    => function ( $input ) {
					$status = sanitize_key( (string) ( $input['status'] ?? 'publish' ) );
					$limit  = (int) ( $input['limit'] ?? 10 );
					return $this->poster->listPosts( $status, $limit );
				},
				'meta'                => $mcpMeta,
			)
		);

		wp_register_ability(
			'bot-comment/preview',
			array(
				'label'               => 'Preview bot comment',
				'description'         => 'Show the matching rule and the comment the bot would leave. Does not write anything.',
				'category'            => 'bot-comment',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array(
							'type'        => 'integer',
							'description' => 'Post ID',
						),
					),
					'required'   => array( 'id' ),
				),
				'permission_callback' => static function () {
					return current_user_can( 'moderate_comments' );
				},
				'execute_callback'    => function ( $input ) {
					$postId = (int) ( $input['id'] ?? 0 );
					if ( $postId > 0 && ! current_user_can( 'edit_post', $postId ) ) {
						return new \WP_Error( 'forbidden', '你沒有編輯這篇文章的權限。' );
					}
					return $this->poster->preview( $postId );
				},
				'meta'                => $mcpMeta,
			)
		);

		wp_register_ability(
			'bot-comment/enter-post',
			array(
				'label'               => 'Enter post and comment',
				'description'         => 'Have the labeled site bot enter a published post and leave one comment from the first matching rule. Set dryRun=true to preview. Set force=true to post again even if one already exists.',
				'category'            => 'bot-comment',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'id'     => array( 'type' => 'integer', 'description' => 'Post ID' ),
						'force'  => array( 'type' => 'boolean', 'default' => false ),
						'dryRun' => array( 'type' => 'boolean', 'default' => false ),
					),
					'required'   => array( 'id' ),
				),
				'permission_callback' => static function () {
					return current_user_can( 'moderate_comments' );
				},
				'execute_callback'    => function ( $input ) {
					$postId = (int) ( $input['id'] ?? 0 );
					if ( $postId > 0 && ! current_user_can( 'edit_post', $postId ) ) {
						return new \WP_Error( 'forbidden', '你沒有編輯這篇文章的權限。' );
					}
					return $this->poster->enterPost( $postId, ! empty( $input['force'] ), ! empty( $input['dryRun'] ) );
				},
				'meta'                => $mcpMeta,
			)
		);
	}
}
