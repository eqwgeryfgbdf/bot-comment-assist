<?php
/**
 * Post editor metabox.
 *
 * @package BotCommentAssist
 */

namespace BotCommentAssist;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Preview, skip, or post a bot comment from the editor.
 */
class Metabox {
	/**
	 * @var Matcher
	 */
	private $matcher;

	/**
	 * @var Poster
	 */
	private $poster;

	public function __construct( Matcher $matcher, Poster $poster ) {
		$this->matcher = $matcher;
		$this->poster  = $poster;
	}

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'addBox' ) );
		add_action( 'save_post_post', array( $this, 'saveSkip' ), 10, 2 );
		add_action( 'admin_post_bot_comment_assist_preview', array( $this, 'handlePreview' ) );
		add_action( 'admin_post_bot_comment_assist_enter', array( $this, 'handleEnter' ) );
	}

	public function addBox(): void {
		add_meta_box(
			'bot-comment-assist',
			'站內小幫手留言',
			array( $this, 'render' ),
			array( 'post' ),
			'side',
			'default'
		);
	}

	public function render( \WP_Post $post ): void {
		$preview   = $this->matcher->preview( $post->ID );
		$commentId = $this->matcher->existingCommentId( $post->ID );
		$skip      = get_post_meta( $post->ID, Poster::SKIP_META, true ) === '1';
		$error     = get_post_meta( $post->ID, Poster::LAST_ERROR_META, true );
		$draft     = get_post_meta( $post->ID, '_bot_comment_assist_preview', true );
		wp_nonce_field( 'bot_comment_skip_' . $post->ID, 'bot_comment_skip_nonce' );
		?>
		<?php if ( isset( $_GET['bot_comment'] ) ) : ?>
			<p class="description">
				<?php
				$flag = sanitize_key( (string) wp_unslash( $_GET['bot_comment'] ) );
				$map  = array(
					'previewed' => '已產生預覽。',
					'posted'    => '小幫手已留言。',
				);
				echo esc_html( $map[ $flag ] ?? '完成。' );
				?>
			</p>
		<?php endif; ?>
		<p>
			<label>
				<input type="checkbox" name="bot_comment_assist_skip" value="1" <?php checked( $skip ); ?>>
				這篇不要自動留言
			</label>
		</p>
		<?php if ( is_wp_error( $preview ) ) : ?>
			<p><?php echo esc_html( $preview->get_error_message() ); ?></p>
		<?php else : ?>
			<p>規則「<?php echo esc_html( (string) $preview['ruleName'] ); ?>」</p>
			<?php if ( $commentId > 0 ) : ?>
				<p>
					<a href="<?php echo esc_url( admin_url( 'comment.php?action=editcomment&c=' . $commentId ) ); ?>">已有助手留言</a>
				</p>
			<?php endif; ?>
			<p>
				<a class="button" href="<?php echo esc_url( $this->actionUrl( 'bot_comment_assist_preview', $post->ID ) ); ?>">預覽留言</a>
			</p>
			<?php if ( $post->post_status === 'publish' ) : ?>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( $this->actionUrl( 'bot_comment_assist_enter', $post->ID ) ); ?>">讓小幫手留言</a>
				</p>
			<?php else : ?>
				<p class="description">發佈後才會自動留言，或你也可以發佈後再按按鈕。</p>
			<?php endif; ?>
		<?php endif; ?>
		<?php if ( is_string( $draft ) && $draft !== '' ) : ?>
			<p><strong>預覽</strong></p>
			<p class="description"><?php echo esc_html( $draft ); ?></p>
		<?php endif; ?>
		<?php if ( is_string( $error ) && $error !== '' ) : ?>
			<p style="color:#d63638"><?php echo esc_html( $error ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * @param mixed $post Post object.
	 */
	public function saveSkip( int $postId, $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $postId ) ) {
			return;
		}
		$nonce = isset( $_POST['bot_comment_skip_nonce'] ) ? (string) wp_unslash( $_POST['bot_comment_skip_nonce'] ) : '';
		if ( $nonce === '' || ! wp_verify_nonce( $nonce, 'bot_comment_skip_' . $postId ) ) {
			return;
		}
		if ( empty( $_POST['bot_comment_assist_skip'] ) ) {
			delete_post_meta( $postId, Poster::SKIP_META );
			return;
		}
		update_post_meta( $postId, Poster::SKIP_META, '1' );
	}

	public function handlePreview(): void {
		$postId = $this->requirePost();
		$result = $this->poster->preview( $postId );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}
		$comment = trim( (string) ( $result['comment'] ?? '' ) );
		if ( $comment === '' ) {
			wp_die( esc_html( '預覽沒有產生留言內容。' ) );
		}
		update_post_meta( $postId, '_bot_comment_assist_preview', $comment );
		$this->redirect( $postId, 'previewed' );
	}

	public function handleEnter(): void {
		$postId = $this->requirePost();
		$result = $this->poster->enterPost( $postId, true, false );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}
		$this->redirect( $postId, 'posted' );
	}

	private function requirePost(): int {
		if ( ! current_user_can( 'moderate_comments' ) ) {
			wp_die( esc_html( '你沒有權限管理留言。' ) );
		}
		$postId = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		check_admin_referer( 'bot_comment_assist_post_' . $postId );
		if ( $postId <= 0 || ! current_user_can( 'edit_post', $postId ) ) {
			wp_die( esc_html( '無效的文章，或你沒有編輯權限。' ) );
		}
		return $postId;
	}

	private function actionUrl( string $action, int $postId ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . rawurlencode( $action ) . '&post_id=' . $postId ),
			'bot_comment_assist_post_' . $postId
		);
	}

	private function redirect( int $postId, string $flag ): void {
		$url = get_edit_post_link( $postId, 'raw' );
		if ( ! is_string( $url ) || $url === '' ) {
			wp_die( esc_html( '無法回到文章編輯頁。' ) );
		}
		wp_safe_redirect( add_query_arg( 'bot_comment', $flag, $url ) );
		exit;
	}
}
