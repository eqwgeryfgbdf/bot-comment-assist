<?php
/**
 * Plugin settings and default comment rules.
 *
 * @package BotCommentAssist
 */

namespace BotCommentAssist;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the bot_comment_assist option.
 */
class Options {
	public const OPTION_KEY = 'bot_comment_assist';

	/**
	 * @var array<string, mixed>
	 */
	private $settings = array();

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'       => '1',
			'autoOnPublish' => '1',
			'oncePerPost'   => '1',
			'botName'       => '站內小幫手',
			'botEmail'      => '',
			'botUrl'        => '',
			'botUserId'     => 0,
			'reuseLineAi'   => '1',
			'aiEnabled'     => '1',
			'aiBaseUrl'     => 'https://api.openai.com/v1',
			'aiApiKey'      => '',
			'aiModel'       => 'gpt-4o-mini',
			'extraPrompt'   => '',
			'rules'         => self::defaultRules(),
		);
	}

	/**
	 * First matching rule wins.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function defaultRules(): array {
		return array(
			array(
				'name'             => '台股盤後',
				'enabled'          => '1',
				'categories'       => 'stock-views',
				'tags'             => '',
				'titleRegex'       => '(台股|臺股|盤勢|盤後)',
				'useAi'            => '1',
				'commentTemplate'  => '看完這篇盤後整理，先把重點記在自己的研究筆記裡就好。市場每天都在變，這裡只是個人觀察，不構成任何投資建議。',
			),
			array(
				'name'             => '歌曲推薦',
				'enabled'          => '1',
				'categories'       => 'song-recommendations',
				'tags'             => '',
				'titleRegex'       => '(歌詞賞析|歌曲推薦)',
				'useAi'            => '1',
				'commentTemplate'  => '如果這首歌剛好戳到你，可以對一下自己當下的感受；也歡迎寫下你第一次聽到它的場合。',
			),
			array(
				'name'             => '遊戲伺服器',
				'enabled'          => '1',
				'categories'       => 'game,fps',
				'tags'             => '',
				'titleRegex'       => '',
				'useAi'            => '1',
				'commentTemplate'  => '這篇偏實作紀錄。若你也在搭類似環境，最有用的是對一下自己的設定差異，而不是照抄每一步。',
			),
			array(
				'name'             => '技術部署',
				'enabled'          => '1',
				'categories'       => 'tech,deploy',
				'tags'             => 'local-ai',
				'titleRegex'       => '',
				'useAi'            => '1',
				'commentTemplate'  => '這篇是實際踩過的部署筆記。若你要重做一次，建議先確認硬體與版本，再對照文中的關鍵步驟。',
			),
			array(
				'name'             => '預設留言',
				'enabled'          => '1',
				'categories'       => '',
				'tags'             => '',
				'titleRegex'       => '',
				'useAi'            => '1',
				'commentTemplate'  => '這篇先把重點記下來。若你讀完有不同看法，歡迎直接留言討論。',
			),
		);
	}

	/**
	 * Load settings from the database.
	 */
	public function load(): void {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			error_log( 'Bot Comment Assist: stored settings are not an array; using defaults.' );
			$stored = array();
		}
		$this->settings = array_merge( self::defaults(), $stored );
		if ( ! isset( $stored['rules'] ) || ! is_array( $stored['rules'] ) || $stored['rules'] === array() ) {
			$this->settings['rules'] = self::defaultRules();
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( $this->settings === array() ) {
			$this->load();
		}
		return $this->settings;
	}

	/**
	 * @param mixed $default Fallback.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		$settings = $this->all();
		if ( array_key_exists( $key, $settings ) ) {
			return $settings[ $key ];
		}
		return $default;
	}

	/**
	 * @param array<string, mixed> $settings Sanitized settings.
	 */
	public function save( array $settings ): bool {
		$this->settings = array_merge( self::defaults(), $settings );
		$updated        = update_option( self::OPTION_KEY, $this->settings, false );
		if ( false === $updated && get_option( self::OPTION_KEY ) !== $this->settings ) {
			error_log( 'Bot Comment Assist: failed to save settings.' );
			return false;
		}
		return true;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function rules(): array {
		$rules = $this->get( 'rules', array() );
		if ( ! is_array( $rules ) ) {
			error_log( 'Bot Comment Assist: rules setting is not an array.' );
			return self::defaultRules();
		}
		return $rules;
	}

	/**
	 * Resolved chat API credentials, optionally borrowed from LINE Post Notify.
	 *
	 * @return array{enabled:bool,baseUrl:string,apiKey:string,model:string}
	 */
	public function resolvedAi(): array {
		$settings = $this->all();
		$enabled  = (string) ( $settings['aiEnabled'] ?? '' ) === '1';
		$baseUrl  = trim( (string) ( $settings['aiBaseUrl'] ?? '' ) );
		$apiKey   = trim( (string) ( $settings['aiApiKey'] ?? '' ) );
		$model    = trim( (string) ( $settings['aiModel'] ?? '' ) );

		if ( (string) ( $settings['reuseLineAi'] ?? '' ) === '1' && function_exists( 'linePostNotifyGetSettings' ) ) {
			$line = linePostNotifyGetSettings();
			if ( ! is_array( $line ) ) {
				error_log( 'Bot Comment Assist: LINE settings were not an array; ignored.' );
			} else {
				if ( $apiKey === '' ) {
					$apiKey = trim( (string) ( $line['aiApiKey'] ?? '' ) );
				}
				if ( $baseUrl === '' || $baseUrl === 'https://api.openai.com/v1' ) {
					$lineBase = trim( (string) ( $line['aiBaseUrl'] ?? '' ) );
					if ( $lineBase !== '' ) {
						$baseUrl = $lineBase;
					}
				}
				if ( $model === '' || $model === 'gpt-4o-mini' ) {
					$lineModel = trim( (string) ( $line['aiModel'] ?? '' ) );
					if ( $lineModel !== '' ) {
						$model = $lineModel;
					}
				}
				if ( (string) ( $line['aiEnabled'] ?? '' ) === '1' ) {
					$enabled = true;
				}
			}
		}

		return array(
			'enabled' => $enabled && $apiKey !== '' && $baseUrl !== '' && $model !== '',
			'baseUrl' => $baseUrl,
			'apiKey'  => $apiKey,
			'model'   => $model,
		);
	}

	/**
	 * Public identity used when inserting the bot comment.
	 *
	 * @return array{name:string,email:string,url:string,userId:int}
	 */
	public function botIdentity(): array {
		$name   = trim( (string) $this->get( 'botName', '' ) );
		$email  = trim( (string) $this->get( 'botEmail', '' ) );
		$url    = trim( (string) $this->get( 'botUrl', '' ) );
		$userId = absint( $this->get( 'botUserId', 0 ) );

		if ( $name === '' ) {
			$name = '站內小幫手';
		}
		if ( $email === '' || ! is_email( $email ) ) {
			$host = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( ! is_string( $host ) || $host === '' ) {
				error_log( 'Bot Comment Assist: home_url host is empty; using example.invalid for bot email.' );
				$host = 'example.invalid';
			}
			$email = 'bot@' . $host;
		}
		if ( $userId > 0 ) {
			$user = get_user_by( 'id', $userId );
			if ( ! $user instanceof \WP_User ) {
				error_log( 'Bot Comment Assist: botUserId ' . $userId . ' does not exist; posting as guest.' );
				$userId = 0;
			}
		}

		return array(
			'name'   => $name,
			'email'  => $email,
			'url'    => $url,
			'userId' => $userId,
		);
	}
}
