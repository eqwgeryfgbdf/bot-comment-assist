<?php
/**
 * Build the bot comment text from a template or an AI model.
 *
 * @package BotCommentAssist
 */

namespace BotCommentAssist;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Expands rule templates and optionally asks an OpenAI-compatible API.
 */
class Composer {
	/**
	 * @var Options
	 */
	private $options;

	public function __construct( Options $options ) {
		$this->options = $options;
	}

	/**
	 * @param array<string, mixed> $rule Matched rule.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function compose( \WP_Post $post, array $rule ) {
		$template = $this->expandTemplate( (string) ( $rule['commentTemplate'] ?? '' ), $post );
		$useAi    = (string) ( $rule['useAi'] ?? '' ) === '1';
		$ai       = $this->options->resolvedAi();

		if ( $useAi !== true || $ai['enabled'] !== true ) {
			if ( $template === '' ) {
				return new \WP_Error( 'empty_template', '規則「' . (string) ( $rule['name'] ?? '' ) . '」沒有留言模板，也沒有可用的 AI。' );
			}
			return array(
				'source'             => 'template',
				'needsExternalModel' => false,
				'comment'            => $template,
				'ruleName'           => (string) ( $rule['name'] ?? '' ),
			);
		}

		$generated = $this->askModel( $post, $rule, $template, $ai );
		if ( is_wp_error( $generated ) ) {
			if ( $template !== '' ) {
				error_log( 'Bot Comment Assist: AI failed for post ' . $post->ID . ': ' . $generated->get_error_message() . '; using template.' );
				return array(
					'source'             => 'template-fallback',
					'needsExternalModel' => false,
					'comment'            => $template,
					'ruleName'           => (string) ( $rule['name'] ?? '' ),
					'aiError'            => $generated->get_error_message(),
				);
			}
			return $generated;
		}
		return $generated;
	}

	/**
	 * Compact briefing for an external agent when no API is configured.
	 *
	 * @param array<string, mixed> $rule Matched rule.
	 * @return array<string, mixed>
	 */
	public function briefing( \WP_Post $post, array $rule ): array {
		return array(
			'locale'   => 'zh-TW',
			'playbook' => array(
				'role'      => '站內小幫手，不是假裝成路人的假讀者',
				'length'    => '2–4 句，最多約 120 字',
				'tone'      => '臺灣繁體中文，平實、具體，不要歡呼或洗版',
				'stock'     => '股市文必須標示不構成投資建議',
				'noSpoil'   => '不要複述整篇文章，只補一句有用的觀察或討論起點',
			),
			'id'        => $post->ID,
			'title'     => (string) $post->post_title,
			'permalink' => (string) get_permalink( $post ),
			'excerpt'   => $this->plainExcerpt( $post ),
			'opening'   => $this->opening( $post ),
			'ruleName'  => (string) ( $rule['name'] ?? '' ),
			'template'  => $this->expandTemplate( (string) ( $rule['commentTemplate'] ?? '' ), $post ),
			'extra'     => trim( (string) $this->options->get( 'extraPrompt', '' ) ),
		);
	}

	private function expandTemplate( string $template, \WP_Post $post ): string {
		$template = trim( $template );
		if ( $template === '' ) {
			return '';
		}
		$date = $this->resolveDate( $post );
		$replacements = array(
			'{title}'     => (string) $post->post_title,
			'{excerpt}'   => $this->plainExcerpt( $post ),
			'{date}'      => $date,
			'{permalink}' => (string) get_permalink( $post ),
		);
		$expanded = strtr( $template, $replacements );
		$clean    = trim( wp_strip_all_tags( $expanded ) );
		if ( $clean === '' ) {
			error_log( 'Bot Comment Assist: expanded template was empty for post ' . $post->ID . '.' );
			return '';
		}
		return $clean;
	}

	/**
	 * @param array<string, mixed>                $rule
	 * @param array{enabled:bool,baseUrl:string,apiKey:string,model:string} $ai
	 * @return array<string, mixed>|\WP_Error
	 */
	private function askModel( \WP_Post $post, array $rule, string $template, array $ai ) {
		$endpoint = $this->endpoint( $ai['baseUrl'] );
		$body     = array(
			'model'           => $ai['model'],
			'temperature'     => 0.4,
			'response_format' => array( 'type' => 'json_object' ),
			'messages'        => array(
				array(
					'role'    => 'system',
					'content' => $this->systemPrompt(),
				),
				array(
					'role'    => 'user',
					'content' => wp_json_encode( $this->briefing( $post, $rule ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				),
			),
		);
		$encoded = wp_json_encode( $body );
		if ( ! is_string( $encoded ) || $encoded === '' ) {
			return new \WP_Error( 'ai_encode', '無法編碼 AI 請求內容。' );
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 60,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $ai['apiKey'],
				),
				'body'    => $encoded,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'ai_http', '無法連線到 AI API：' . $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		if ( $status < 200 || $status >= 300 ) {
			$decoded = json_decode( $raw, true );
			$detail  = is_array( $decoded ) ? (string) ( $decoded['error']['message'] ?? $raw ) : $raw;
			return new \WP_Error( 'ai_api', 'AI API 回傳 HTTP ' . $status . '：' . $detail );
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'ai_parse', 'AI API 回應不是有效 JSON。' );
		}
		$text = trim( (string) ( $decoded['choices'][0]['message']['content'] ?? '' ) );
		if ( $text === '' ) {
			return new \WP_Error( 'ai_empty', 'AI API 沒有回傳內容。' );
		}
		$text = preg_replace( '/^```(?:json)?\s*/u', '', $text ) ?? $text;
		$text = preg_replace( '/\s*```$/u', '', $text ) ?? $text;

		$pack = json_decode( $text, true );
		if ( ! is_array( $pack ) && preg_match( '/\{.*\}/us', $text, $match ) === 1 ) {
			$pack = json_decode( $match[0], true );
		}
		$comment = '';
		if ( is_array( $pack ) ) {
			$comment = trim( wp_strip_all_tags( (string) ( $pack['comment'] ?? '' ) ) );
		}
		if ( $comment === '' ) {
			$comment = trim( wp_strip_all_tags( $text ) );
		}
		if ( $comment === '' ) {
			return new \WP_Error( 'ai_incomplete', 'AI 回傳缺少 comment。' );
		}
		if ( mb_strlen( $comment ) > 400 ) {
			$comment = mb_substr( $comment, 0, 400 );
		}

		return array(
			'source'             => 'model',
			'needsExternalModel' => false,
			'comment'            => $comment,
			'ruleName'           => (string) ( $rule['name'] ?? '' ),
			'model'              => $ai['model'],
			'template'           => $template,
		);
	}

	private function endpoint( string $baseUrl ): string {
		$baseUrl = untrailingslashit( $baseUrl );
		if ( preg_match( '#/chat/completions$#', $baseUrl ) ) {
			return $baseUrl;
		}
		if ( preg_match( '#/v1$#', $baseUrl ) ) {
			return $baseUrl . '/chat/completions';
		}
		return $baseUrl . '/v1/chat/completions';
	}

	private function systemPrompt(): string {
		$extra  = trim( (string) $this->options->get( 'extraPrompt', '' ) );
		$prompt = <<<'TXT'
你是這個臺灣繁體中文部落格的站內小幫手。根據文章 briefing 產出一則可直接發布的留言。

只輸出 JSON，鍵名必須是：comment

規則：
1. 語言使用臺灣繁體中文。
2. 2–4 句，最多約 120 字。平實、具體，不要歡呼、不要洗版、不要假裝成路人讀者。
3. 不要複述整篇文章，只補一句有用的觀察或討論起點。
4. 股市內容必須寫明「不構成投資建議」。
5. 不要放連結、不要 @ 任何人、不要使用 Markdown。
6. 若 briefing 有 template，把它當語氣參考，但不要逐字照抄，除非它已經很合適。
TXT;
		if ( $extra !== '' ) {
			$prompt .= "\n\n額外規則：\n" . $extra;
		}
		return $prompt;
	}

	private function plainExcerpt( \WP_Post $post ): string {
		$excerpt = trim( wp_strip_all_tags( (string) $post->post_excerpt ) );
		if ( $excerpt !== '' ) {
			return $excerpt;
		}
		$plain = trim( wp_strip_all_tags( (string) $post->post_content ) );
		if ( $plain === '' ) {
			return '';
		}
		return wp_trim_words( $plain, 40, '…' );
	}

	private function opening( \WP_Post $post ): string {
		$plain = trim( wp_strip_all_tags( (string) $post->post_content ) );
		if ( $plain === '' ) {
			return '';
		}
		return wp_trim_words( $plain, 80, '…' );
	}

	private function resolveDate( \WP_Post $post ): string {
		$title = (string) $post->post_title;
		$year  = substr( (string) $post->post_date, 0, 4 );
		if ( $year === '' || $year === '0000' ) {
			$year = wp_date( 'Y' );
		}
		if ( preg_match( '/(\d{4})年(\d{1,2})月(\d{1,2})日/u', $title, $match ) === 1 ) {
			return sprintf( '%04d-%02d-%02d', (int) $match[1], (int) $match[2], (int) $match[3] );
		}
		if ( preg_match( '/(\d{1,2})月(\d{1,2})日/u', $title, $match ) === 1 ) {
			return sprintf( '%04d-%02d-%02d', (int) $year, (int) $match[1], (int) $match[2] );
		}
		$timestamp = strtotime( (string) $post->post_date );
		if ( is_int( $timestamp ) && $timestamp > 0 ) {
			return wp_date( 'Y-m-d', $timestamp );
		}
		return wp_date( 'Y-m-d' );
	}
}
