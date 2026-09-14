<?php
/**
 * Settings screen.
 *
 * @package BotCommentAssist
 */

namespace BotCommentAssist;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin settings under Settings → Bot Comments.
 */
class Admin {
	/**
	 * @var Options
	 */
	private $options;

	public function __construct( Options $options ) {
		$this->options = $options;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addPage' ) );
		add_action( 'admin_post_bot_comment_assist_save', array( $this, 'handleSave' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BOT_COMMENT_ASSIST_FILE ), array( $this, 'actionLinks' ) );
		add_filter( 'manage_posts_columns', array( $this, 'addColumn' ) );
		add_action( 'manage_posts_custom_column', array( $this, 'renderColumn' ), 10, 2 );
	}

	/**
	 * @param array<int, string> $links Plugin links.
	 * @return array<int, string>
	 */
	public function actionLinks( $links ) {
		if ( ! is_array( $links ) ) {
			$links = array();
		}
		$links[] = '<a href="' . esc_url( admin_url( 'options-general.php?page=bot-comment-assist' ) ) . '">設定</a>';
		return $links;
	}

	public function addPage(): void {
		add_options_page(
			'Bot Comments',
			'Bot Comments',
			'manage_options',
			'bot-comment-assist',
			array( $this, 'renderPage' )
		);
	}

	public function handleSave(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( '你沒有權限修改這些設定。' ) );
		}
		check_admin_referer( 'bot_comment_assist_save' );

		$current = $this->options->all();
		$posted  = wp_unslash( $_POST );
		$apiKey  = isset( $posted['aiApiKey'] ) ? (string) $posted['aiApiKey'] : '';
		if ( $apiKey === '' ) {
			$apiKey = (string) ( $current['aiApiKey'] ?? '' );
		}

		$botEmail = sanitize_email( (string) ( $posted['botEmail'] ?? '' ) );
		if ( $botEmail !== '' && ! is_email( $botEmail ) ) {
			wp_die( esc_html( 'Bot 電子郵件格式不正確。' ) );
		}

		$saved = $this->options->save(
			array(
				'enabled'       => empty( $posted['enabled'] ) ? '' : '1',
				'autoOnPublish' => empty( $posted['autoOnPublish'] ) ? '' : '1',
				'oncePerPost'   => empty( $posted['oncePerPost'] ) ? '' : '1',
				'botName'       => sanitize_text_field( (string) ( $posted['botName'] ?? '' ) ),
				'botEmail'      => $botEmail,
				'botUrl'        => esc_url_raw( (string) ( $posted['botUrl'] ?? '' ) ),
				'botUserId'     => absint( $posted['botUserId'] ?? 0 ),
				'reuseLineAi'   => empty( $posted['reuseLineAi'] ) ? '' : '1',
				'aiEnabled'     => empty( $posted['aiEnabled'] ) ? '' : '1',
				'aiBaseUrl'     => esc_url_raw( (string) ( $posted['aiBaseUrl'] ?? '' ) ),
				'aiApiKey'      => $apiKey,
				'aiModel'       => sanitize_text_field( (string) ( $posted['aiModel'] ?? '' ) ),
				'extraPrompt'   => sanitize_textarea_field( (string) ( $posted['extraPrompt'] ?? '' ) ),
				'rules'         => $this->sanitizeRules( $posted['rules'] ?? array() ),
			)
		);
		if ( $saved !== true ) {
			wp_die( esc_html( '儲存設定失敗，請再試一次。' ) );
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=bot-comment-assist&saved=1' ) );
		exit;
	}

	public function renderPage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( '你沒有權限查看這個頁面。' ) );
		}
		$settings = $this->options->all();
		$ai       = $this->options->resolvedAi();
		$rules    = $this->options->rules();
		while ( count( $rules ) < 6 ) {
			$rules[] = array(
				'name'            => '',
				'enabled'         => '',
				'categories'      => '',
				'tags'            => '',
				'titleRegex'      => '',
				'useAi'           => '1',
				'commentTemplate' => '',
			);
		}
		?>
		<div class="wrap">
			<h1>Bot Comments</h1>
			<p>發佈文章後，站內小幫手依第一條符合的規則留一則留言。Googlebot、GPTBot 這類爬蟲進來<strong>不會</strong>觸發留言，避免洗版與假互動。</p>
			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>設定已儲存。</p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'bot_comment_assist_save' ); ?>
				<input type="hidden" name="action" value="bot_comment_assist_save">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">啟用</th>
						<td>
							<label>
								<input type="checkbox" name="enabled" value="1" <?php checked( (string) $settings['enabled'] === '1' ); ?>>
								啟用站內小幫手留言
							</label>
							<br>
							<label>
								<input type="checkbox" name="autoOnPublish" value="1" <?php checked( (string) $settings['autoOnPublish'] === '1' ); ?>>
								文章第一次發佈時自動留言
							</label>
							<br>
							<label>
								<input type="checkbox" name="oncePerPost" value="1" <?php checked( (string) $settings['oncePerPost'] === '1' ); ?>>
								每篇文章最多一則（除非手動強制）
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="botName">顯示名稱</label></th>
						<td>
							<input class="regular-text" type="text" id="botName" name="botName" value="<?php echo esc_attr( (string) $settings['botName'] ); ?>">
							<p class="description">前台會標成「（站內助手）」，避免被當成路人讀者。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="botEmail">電子郵件</label></th>
						<td>
							<input class="regular-text" type="email" id="botEmail" name="botEmail" value="<?php echo esc_attr( (string) $settings['botEmail'] ); ?>" placeholder="留空則用 bot@網站網域">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="botUrl">網站</label></th>
						<td><input class="regular-text" type="url" id="botUrl" name="botUrl" value="<?php echo esc_attr( (string) $settings['botUrl'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="botUserId">WordPress 使用者 ID</label></th>
						<td>
							<input type="number" id="botUserId" name="botUserId" min="0" value="<?php echo esc_attr( (string) (int) $settings['botUserId'] ); ?>">
							<p class="description">0 表示以訪客身分留言。若填專用帳號 ID，留言會掛在那個使用者上。</p>
						</td>
					</tr>
					<tr>
						<th scope="row">AI 來源</th>
						<td>
							<label>
								<input type="checkbox" name="aiEnabled" value="1" <?php checked( (string) $settings['aiEnabled'] === '1' ); ?>>
								啟用 OpenAI 相容 API 依規則改寫留言
							</label>
							<br>
							<label>
								<input type="checkbox" name="reuseLineAi" value="1" <?php checked( (string) $settings['reuseLineAi'] === '1' ); ?>>
								若本外掛沒填金鑰，沿用「LINE 發文通知」的 AI 設定
							</label>
							<p class="description">沒有 API 時會改用規則裡的留言模板；也可用 MCP 工具 <code>bot-comment/preview</code> 與 <code>bot-comment/enter-post</code>。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aiBaseUrl">API 網址</label></th>
						<td><input class="regular-text" type="url" id="aiBaseUrl" name="aiBaseUrl" value="<?php echo esc_attr( (string) $settings['aiBaseUrl'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="aiApiKey">API Key</label></th>
						<td>
							<input class="regular-text" type="password" id="aiApiKey" name="aiApiKey" value="" autocomplete="off" placeholder="<?php echo (string) $settings['aiApiKey'] !== '' ? '已儲存，留空則不更改' : ''; ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aiModel">模型</label></th>
						<td><input class="regular-text" type="text" id="aiModel" name="aiModel" value="<?php echo esc_attr( (string) $settings['aiModel'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="extraPrompt">額外規則</label></th>
						<td>
							<textarea class="large-text" rows="4" id="extraPrompt" name="extraPrompt"><?php echo esc_textarea( (string) $settings['extraPrompt'] ); ?></textarea>
						</td>
					</tr>
				</table>
				<h2>規則（由上而下，第一條符合的生效）</h2>
				<p class="description">可用代碼：<code>{title}</code> <code>{excerpt}</code> <code>{date}</code> <code>{permalink}</code>。勾選「用 AI」時，模板當語氣參考；AI 失敗則退回模板原文。</p>
				<table class="widefat striped">
					<thead>
						<tr>
							<th>啟用</th>
							<th>名稱</th>
							<th>分類 slug</th>
							<th>標籤 slug</th>
							<th>標題正則</th>
							<th>用 AI</th>
							<th>留言模板</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rules as $index => $rule ) : ?>
							<?php if ( ! is_array( $rule ) ) : ?>
								<?php continue; ?>
							<?php endif; ?>
							<tr>
								<td>
									<input type="checkbox" name="rules[<?php echo esc_attr( (string) $index ); ?>][enabled]" value="1" <?php checked( (string) ( $rule['enabled'] ?? '' ) === '1' ); ?>>
								</td>
								<td><input type="text" class="regular-text" name="rules[<?php echo esc_attr( (string) $index ); ?>][name]" value="<?php echo esc_attr( (string) ( $rule['name'] ?? '' ) ); ?>"></td>
								<td><input type="text" class="regular-text" name="rules[<?php echo esc_attr( (string) $index ); ?>][categories]" value="<?php echo esc_attr( (string) ( $rule['categories'] ?? '' ) ); ?>" placeholder="stock-views"></td>
								<td><input type="text" class="regular-text" name="rules[<?php echo esc_attr( (string) $index ); ?>][tags]" value="<?php echo esc_attr( (string) ( $rule['tags'] ?? '' ) ); ?>" placeholder="local-ai"></td>
								<td><input type="text" class="regular-text" name="rules[<?php echo esc_attr( (string) $index ); ?>][titleRegex]" value="<?php echo esc_attr( (string) ( $rule['titleRegex'] ?? '' ) ); ?>" placeholder="(台股|盤勢)"></td>
								<td>
									<input type="checkbox" name="rules[<?php echo esc_attr( (string) $index ); ?>][useAi]" value="1" <?php checked( (string) ( $rule['useAi'] ?? '' ) === '1' ); ?>>
								</td>
								<td>
									<textarea rows="3" class="large-text" name="rules[<?php echo esc_attr( (string) $index ); ?>][commentTemplate]"><?php echo esc_textarea( (string) ( $rule['commentTemplate'] ?? '' ) ); ?></textarea>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php submit_button( '儲存設定' ); ?>
			</form>
			<h2>目前狀態</h2>
			<p><?php echo $ai['enabled'] ? esc_html( 'API 已就緒，模型：' . $ai['model'] ) : esc_html( '尚未就緒：會改用規則模板，或由 Cursor MCP 預覽後再 enter-post。' ); ?></p>
			<h2>給 AI 的工具</h2>
			<ol>
				<li><code>bot-comment/list-posts</code> 看哪些文章還沒有助手留言</li>
				<li><code>bot-comment/preview</code> 預覽規則與留言內容</li>
				<li><code>bot-comment/enter-post</code> 讓小幫手進入已發佈文章並留言（可 dryRun / force）</li>
			</ol>
		</div>
		<?php
	}

	/**
	 * @param mixed $raw Posted rules.
	 * @return array<int, array<string, mixed>>
	 */
	private function sanitizeRules( $raw ): array {
		if ( ! is_array( $raw ) ) {
			error_log( 'Bot Comment Assist: posted rules were not an array.' );
			return Options::defaultRules();
		}
		$rules = array();
		foreach ( $raw as $rule ) {
			if ( ! is_array( $rule ) ) {
				error_log( 'Bot Comment Assist: skipped a non-array posted rule.' );
				continue;
			}
			$name     = sanitize_text_field( (string) ( $rule['name'] ?? '' ) );
			$template = sanitize_textarea_field( (string) ( $rule['commentTemplate'] ?? '' ) );
			if ( $name === '' && $template === '' ) {
				continue;
			}
			$titleRegex = (string) ( $rule['titleRegex'] ?? '' );
			if ( $titleRegex !== '' && @preg_match( '/' . $titleRegex . '/u', '' ) === false ) {
				error_log( 'Bot Comment Assist: rejected invalid titleRegex: ' . $titleRegex );
				$titleRegex = '';
			}
			$rules[] = array(
				'name'            => $name,
				'enabled'         => empty( $rule['enabled'] ) ? '' : '1',
				'categories'      => sanitize_text_field( (string) ( $rule['categories'] ?? '' ) ),
				'tags'            => sanitize_text_field( (string) ( $rule['tags'] ?? '' ) ),
				'titleRegex'      => sanitize_text_field( $titleRegex ),
				'useAi'           => empty( $rule['useAi'] ) ? '' : '1',
				'commentTemplate' => $template,
			);
		}
		if ( $rules === array() ) {
			error_log( 'Bot Comment Assist: no usable rules posted; keeping defaults.' );
			return Options::defaultRules();
		}
		return $rules;
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public function addColumn( array $columns ): array {
		$columns['bot_comment_assist'] = '助手留言';
		return $columns;
	}

	public function renderColumn( string $column, int $postId ): void {
		if ( $column !== 'bot_comment_assist' ) {
			return;
		}
		if ( get_post_meta( $postId, Poster::SKIP_META, true ) === '1' ) {
			echo '<span class="description">略過</span>';
			return;
		}
		$commentId = absint( get_post_meta( $postId, Poster::COMMENT_ID_META, true ) );
		if ( $commentId > 0 ) {
			echo '<a href="' . esc_url( admin_url( 'comment.php?action=editcomment&c=' . $commentId ) ) . '">已留言</a>';
			return;
		}
		$error = get_post_meta( $postId, Poster::LAST_ERROR_META, true );
		if ( is_string( $error ) && $error !== '' ) {
			echo '<span style="color:#d63638" title="' . esc_attr( $error ) . '">失敗</span>';
			return;
		}
		echo '<span class="description">尚未</span>';
	}
}
