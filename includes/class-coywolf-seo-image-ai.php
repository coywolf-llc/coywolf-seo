<?php
/**
 * Claude API client.
 *
 * Sends an image or PDF attachment to the Anthropic Messages API (vision /
 * document blocks) and turns the response into the four Media Library text
 * fields: alternative text, title, caption, and description. Also lists the
 * account's available models for the Settings screen and runs the connection
 * test.
 *
 * Image payload strategy: the smallest intermediate size that is still
 * detailed enough (large → medium_large → original) is sent base64-encoded;
 * anything unsupported or too big is re-encoded to a bounded JPEG first, so
 * a 20 MB camera original never crosses the wire. PDFs are sent as-is up to
 * a size cap — there is no downscaling path for them.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Claude API client for image analysis.
 */
final class Coywolf_SEO_Image_AI {

	const API_BASE        = 'https://api.anthropic.com';
	const API_VERSION     = '2023-06-01';
	const DEFAULT_MODEL   = 'claude-opus-4-8';
	const MODELS_CACHE    = 'coywolf_seo_image_models';
	const MAX_TOKENS      = 1024;
	const REQUEST_TIMEOUT = 90;

	/**
	 * Rough per-image token estimates for the cost estimator: one downscaled
	 * image (~1,000–1,200 tokens at the "large" size we send), the detailed
	 * accessibility analysis prompt and system text, and a short four-field
	 * JSON response.
	 */
	const EST_INPUT_TOKENS  = 2000;
	const EST_OUTPUT_TOKENS = 250;

	/**
	 * USD per million tokens (input, output) by model-ID prefix. Most
	 * specific prefix wins. Models that match nothing get no estimate
	 * rather than a wrong one.
	 */
	const MODEL_PRICING = array(
		'claude-fable'      => array( 10.0, 50.0 ),
		'claude-opus-4-5'   => array( 5.0, 25.0 ),
		'claude-opus-4-6'   => array( 5.0, 25.0 ),
		'claude-opus-4-7'   => array( 5.0, 25.0 ),
		'claude-opus-4-8'   => array( 5.0, 25.0 ),
		'claude-opus'       => array( 15.0, 75.0 ),
		'claude-3-opus'     => array( 15.0, 75.0 ),
		'claude-sonnet'     => array( 3.0, 15.0 ),
		'claude-3-7-sonnet' => array( 3.0, 15.0 ),
		'claude-3-5-sonnet' => array( 3.0, 15.0 ),
		'claude-haiku'      => array( 1.0, 5.0 ),
		'claude-3-5-haiku'  => array( 0.8, 4.0 ),
		'claude-3-haiku'    => array( 0.25, 1.25 ),
	);

	/**
	 * Pricing for a model ID, by longest matching prefix.
	 *
	 * @param string $model_id Model ID.
	 * @return array|null { input: float, output: float } USD/MTok, or null when unknown.
	 */
	public static function model_pricing( $model_id ) {
		$model_id = (string) $model_id;
		$best     = null;
		$best_len = 0;
		foreach ( self::MODEL_PRICING as $prefix => $price ) {
			if ( 0 === strpos( $model_id, $prefix ) && strlen( $prefix ) > $best_len ) {
				$best     = $price;
				$best_len = strlen( $prefix );
			}
		}
		if ( null === $best ) {
			return null;
		}
		return array(
			'input'  => $best[0],
			'output' => $best[1],
		);
	}

	/**
	 * Rough USD cost per analyzed image for a model.
	 *
	 * @param string $model_id Model ID.
	 * @return float|null
	 */
	public static function estimated_cost_per_image( $model_id ) {
		$pricing = self::model_pricing( $model_id );
		if ( null === $pricing ) {
			return null;
		}
		return ( self::EST_INPUT_TOKENS / 1000000 ) * $pricing['input']
			+ ( self::EST_OUTPUT_TOKENS / 1000000 ) * $pricing['output'];
	}

	/**
	 * Image mimes the API accepts as-is. Anything else is re-encoded to JPEG.
	 */
	const DIRECT_MIMES = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

	/**
	 * Max raw file size sent without re-encoding (the API caps images at
	 * 5 MB after base64; 3.5 MB raw stays safely under it).
	 */
	const MAX_RAW_BYTES = 3670016;

	/**
	 * Max raw PDF size sent for analysis. PDFs can't be downscaled like
	 * images, so anything bigger is skipped — 10 MB stays well under the
	 * API's request cap after base64 and keeps per-file token cost bounded.
	 */
	const MAX_PDF_BYTES = 10485760;

	/**
	 * Longest edge sent to the API. Larger inputs are scaled down — Claude
	 * does the same server-side, so the extra pixels would only cost
	 * bandwidth and tokens.
	 */
	const MAX_EDGE = 1568;

	/**
	 * Stored API key.
	 *
	 * @return string
	 */
	public function api_key() {
		$key = (string) Coywolf_SEO_Options::get( 'ai_api_key' );
		if ( '' !== $key ) {
			return $key;
		}
		if ( defined( 'ANTHROPIC_API_KEY' ) && '' !== (string) ANTHROPIC_API_KEY ) {
			return (string) ANTHROPIC_API_KEY;
		}
		$env = getenv( 'ANTHROPIC_API_KEY' );
		return ( is_string( $env ) && '' !== $env ) ? $env : '';
	}

	/**
	 * Whether a key is configured anywhere (saved, wp-config constant, or env).
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== $this->api_key();
	}

	/**
	 * Where the active API key comes from, for the Settings indicator. Mirrors
	 * api_key()'s precedence: a saved key wins over the wp-config constant,
	 * which wins over the environment variable.
	 *
	 * @return array {
	 *     @type string $source   Active source: 'saved'|'constant'|'env'|'' (none).
	 *     @type bool   $saved    A key is saved in the database.
	 *     @type bool   $constant ANTHROPIC_API_KEY is defined in wp-config.php.
	 *     @type bool   $env      ANTHROPIC_API_KEY is set as an environment variable.
	 * }
	 */
	public function key_status() {
		$saved    = '' !== (string) Coywolf_SEO_Options::get( 'ai_api_key' );
		$constant = defined( 'ANTHROPIC_API_KEY' ) && '' !== (string) ANTHROPIC_API_KEY;
		$env_val  = getenv( 'ANTHROPIC_API_KEY' );
		$env      = is_string( $env_val ) && '' !== $env_val;

		if ( $saved ) {
			$source = 'saved';
		} elseif ( $constant ) {
			$source = 'constant';
		} elseif ( $env ) {
			$source = 'env';
		} else {
			$source = '';
		}

		return array(
			'source'   => $source,
			'saved'    => $saved,
			'constant' => $constant,
			'env'      => $env,
		);
	}

	/**
	 * Model to use for image analysis.
	 *
	 * @return string
	 */
	public function model() {
		$model = (string) Coywolf_SEO_Options::get( 'ai_model' );
		return '' !== $model ? $model : self::DEFAULT_MODEL;
	}

	/**
	 * Analyze one image or PDF attachment and return the four text fields.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $model         Optional model override for this request
	 *                              (the bulk runner's per-run model picker);
	 *                              empty uses the Settings model.
	 * @return array|WP_Error { alt_text, title, caption, description }
	 */
	public function generate_image_text( $attachment_id, $model = '' ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'coywolf_seo_no_key',
				__( 'Add your Claude API key on the Settings page first.', 'coywolf-seo' )
			);
		}

		$payload = $this->attachment_payload( $attachment_id );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$model = is_string( $model ) && preg_match( '/^[a-z0-9._-]+$/i', $model ) ? $model : '';

		$system = 'You write accessibility-first metadata for files in a WordPress media library, '
			. 'following WCAG-aligned guidance for alternative text, titles, captions, and descriptions. '
			. 'You write for the people who depend on this text — screen reader and braille users, and anyone on a '
			. 'text-only or broken-image fallback — and never for search engines. '
			. 'You respond with raw JSON only: a single JSON object, no markdown fences, no commentary.';

		$body = array(
			'model'      => '' !== $model ? $model : $this->model(),
			'max_tokens' => self::MAX_TOKENS,
			'system'     => $system,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type'   => $payload['block'],
							'source' => array(
								'type'       => 'base64',
								'media_type' => $payload['media_type'],
								'data'       => $payload['data'],
							),
						),
						array(
							'type' => 'text',
							'text' => $this->prompt( $attachment_id, 'document' === $payload['block'] ),
						),
					),
				),
			),
		);

		$response = wp_remote_post(
			self::API_BASE . '/v1/messages',
			array(
				'timeout'    => self::REQUEST_TIMEOUT,
				'user-agent' => $this->user_agent(),
				'headers'    => array(
					'x-api-key'         => $this->api_key(),
					'anthropic-version' => self::API_VERSION,
					'content-type'      => 'application/json',
				),
				'body'       => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'coywolf_seo_api', $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'coywolf_seo_api', $this->api_error( $response ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$text = '';
		foreach ( (array) ( isset( $data['content'] ) ? $data['content'] : array() ) as $block ) {
			if ( is_array( $block ) && isset( $block['type'] ) && 'text' === $block['type'] ) {
				$text .= (string) $block['text'];
			}
		}

		$fields = $this->decode_json( $text );
		if ( ! is_array( $fields ) ) {
			return new WP_Error( 'coywolf_seo_api', __( 'Claude returned a response that could not be parsed. Try again.', 'coywolf-seo' ) );
		}

		$clean = array();
		foreach ( array( 'alt_text', 'title', 'caption', 'description' ) as $key ) {
			$value = isset( $fields[ $key ] ) && is_scalar( $fields[ $key ] ) ? (string) $fields[ $key ] : '';
			$value = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $value ) ) );
			if ( 'description' === $key || 'caption' === $key ) {
				$clean[ $key ] = sanitize_textarea_field( $value );
			} else {
				$clean[ $key ] = sanitize_text_field( $value );
			}
		}
		if ( '' === $clean['alt_text'] && '' === $clean['title'] ) {
			return new WP_Error( 'coywolf_seo_api', __( 'Claude did not return usable image text. Try again.', 'coywolf-seo' ) );
		}
		return $clean;
	}

	/**
	 * Write generated texts to the attachment.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $texts         { alt_text, title, caption, description }.
	 * @param array $fields        Field keys to write.
	 * @param bool  $overwrite     Replace non-empty values too.
	 * @return string[] Field keys actually written.
	 */
	public function save_image_text( $attachment_id, array $texts, array $fields, $overwrite ) {
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return array();
		}

		$written = array();
		$update  = array();

		// Alt text only exists for images — PDFs and other documents have no
		// alt field anywhere in WordPress, so writing the meta would be noise.
		if ( in_array( 'alt_text', $fields, true ) && '' !== $texts['alt_text'] && wp_attachment_is_image( $attachment ) ) {
			$current = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
			if ( $overwrite || '' === trim( $current ) ) {
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', $texts['alt_text'] );
				$written[] = 'alt_text';
			}
		}
		if ( in_array( 'title', $fields, true ) && '' !== $texts['title'] ) {
			// A title equal to the filename stub counts as empty — it is the
			// upload default, not something a human wrote.
			$stub    = sanitize_title( pathinfo( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ), PATHINFO_FILENAME ) );
			$is_stub = sanitize_title( $attachment->post_title ) === $stub;
			if ( $overwrite || '' === trim( $attachment->post_title ) || $is_stub ) {
				$update['post_title'] = $texts['title'];
				$written[]            = 'title';
			}
		}
		if ( in_array( 'caption', $fields, true ) && '' !== $texts['caption'] ) {
			if ( $overwrite || '' === trim( $attachment->post_excerpt ) ) {
				$update['post_excerpt'] = $texts['caption'];
				$written[]              = 'caption';
			}
		}
		if ( in_array( 'description', $fields, true ) && '' !== $texts['description'] ) {
			if ( $overwrite || '' === trim( $attachment->post_content ) ) {
				$update['post_content'] = $texts['description'];
				$written[]              = 'description';
			}
		}

		if ( ! empty( $update ) ) {
			$update['ID'] = $attachment_id;
			wp_update_post( wp_slash( $update ) );
		}
		return $written;
	}

	/**
	 * The accessibility-first analysis instruction sent alongside the image
	 * or PDF. Ports the project's WordPress image-accessibility guidance
	 * (WCAG-aligned) and is used for every analysis path.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $is_pdf        Adjust the instructions for a PDF document.
	 * @return string
	 */
	private function prompt( $attachment_id, $is_pdf = false ) {
		$file   = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$locale = get_locale();

		$extra = trim( (string) Coywolf_SEO_Options::get( 'image_text_instructions' ) );

		$json_contract = "Respond with a JSON object:\n"
			. '{"alt_text": string, "title": string, "caption": string, "description": string}' . "\n\n";

		if ( $is_pdf ) {
			$prompt = "Analyze the attached PDF document and write accessibility-first Media Library metadata for it, following the rules below. A PDF is a document, not an image: WordPress stores no alt attribute for it, so write for a reader deciding whether to open it and put the substance in the description.\n\n"
				. $json_contract
				. "Fill only the fields this document warrants; return an empty string for any field that is not warranted.\n"
				. "- alt_text: a short, plain summary of what the document is. (It is not saved as an alt attribute, but keep it accurate.)\n"
				. "- title: a short, human-readable document title of three to eight words in title case — never the filename, never keywords.\n"
				. "- caption: one informative sentence for a link to the document; return an empty string if it needs none.\n"
				. "- description: a clear summary of the document's content and purpose; for a data-heavy document, include the key figures as a plain-text list.\n"
				. "- Expand abbreviations, units, and addresses in full, and spell out acronyms unless this audience knows them better. Use proper typographic curly quotes and apostrophes (not straight ones) when quoting text.\n"
				. '- Write every field in the language of locale "' . $locale . "\".\n"
				. "- Plain text only: no HTML and no markdown. Write for human readers; never write for SEO.\n";
		} else {
			$prompt = "Analyze the image and write accessibility-first Media Library metadata for it, following the rules below. This metadata becomes the default for every future use of the file, and you are given no specific post context, so it must stand on its own.\n\n"
				. $json_contract
				. "Write alt_text only when the image warrants it (an empty string for a purely decorative image); always provide a title, a caption, and a description.\n\n"
				. "First, silently decide what kind of image this is, then write accordingly:\n"
				. "- Decorative (a divider, texture, background flourish, or an icon that only repeats nearby text): return an empty alt_text. Do not invent text and do not write \"decorative image\". Still give it a useful title.\n"
				. "- Functional (the image acts as a button or link): alt_text states the action or destination, not the artwork — a magnifying-glass search icon is \"Search\", not \"Magnifying glass\".\n"
				. "- Text-bearing (a sign, quote card, screenshot, or graphic whose text is the point): transcribe the text verbatim in alt_text. If the visual design of the text is the point, describe that design as well.\n"
				. "- Complex (a chart, infographic, diagram, or data-dense screenshot): alt_text is one sentence giving the takeaway; put the full walk-through, including the underlying data as a plain-text list, in the description.\n"
				. "- Informative (any other image that carries meaning): alt_text describes what matters.\n\n"
				. "alt_text — becomes the alt attribute:\n"
				. "- Front-load the core subject and action, then add secondary details in descending importance, so a reader who stops after the first clause still has the essential picture.\n"
				. "- Write full sentences in sentence case, ending with a period.\n"
				. "- Default to one concise sentence (around 125 characters). Expand only when the image itself is the content — a portfolio or photography piece — where composition, light, wardrobe, and expression matter.\n"
				. "- Never begin with \"Image of\", \"Photo of\", or \"Graphic of\". Name the medium only when it matters, e.g. \"Watercolor painting of…\" or \"Screenshot of…\".\n"
				. "- Expand anything normally abbreviated: spell out units, measurements, and addresses (\"5GB\" becomes \"5 gigabytes\"; \"100lbs\" becomes \"100 pounds\"; \"8 ft 2 in\" becomes \"8 feet 2 inches\"; \"123 Main St.\" becomes \"123 Main Street\"). Spell out acronyms unless this audience knows the acronym better. Use no pronunciation hacks.\n"
				. "- Use proper typographic curly quotes and apostrophes (not straight ones) when quoting text that appears in the image.\n"
				. "- Do not repeat what the title or caption says; alt_text and caption are read together, so write them as complements.\n\n"
				. "title — an internal Media Library label (WordPress does not output it on inline images):\n"
				. "- A short, human-readable identifier of three to eight words in title case, e.g. \"Barista Pouring Latte Art\".\n"
				. "- Never a filename, never keywords, and never a copy of alt_text.\n\n"
				. "caption — visible to everyone, shown as the figure caption:\n"
				. "- Always write one concise, informative sentence suitable to display beneath the image.\n"
				. "- Add editorial context a sighted reader would value when you can infer it (names, place, date, or why it matters); otherwise a clear descriptive sentence is fine.\n"
				. "- Phrase it differently from alt_text — they are read together, so complement rather than repeat it.\n\n"
				. "description — shown on attachment pages:\n"
				. "- Always write two to three sentences describing the image in more detail.\n"
				. "- For a complex image (chart, infographic, diagram, or dense screenshot), make it a full walk-through that includes the underlying data as a plain-text list.\n\n"
				. '- Write every field in the language of locale "' . $locale . "\".\n"
				. "- Plain text only: no HTML and no markdown. Write for human readers; never write for SEO.\n";
		}

		$context = array();
		if ( '' !== $file ) {
			$context[] = 'filename "' . wp_basename( $file ) . '"';
		}
		$site = trim( wp_strip_all_tags( (string) get_bloginfo( 'name' ) ) );
		if ( '' !== $site ) {
			$context[] = 'site "' . $site . '"';
		}
		if ( ! empty( $context ) ) {
			$prompt .= "\nContext that may help (ignore it if misleading): " . implode( ', ', $context ) . '.';
		}
		if ( '' !== $extra ) {
			$prompt .= "\n\nAdditional site-specific instructions:\n" . $extra;
		}
		return $prompt;
	}

	/**
	 * Build the base64 payload for an attachment: an `image` content block
	 * for raster images, a `document` content block for PDFs.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|WP_Error { block, media_type, data }
	 */
	private function attachment_payload( $attachment_id ) {
		$mime = (string) get_post_mime_type( $attachment_id );

		if ( 'application/pdf' === $mime ) {
			$main = get_attached_file( $attachment_id );
			if ( ! $main || ! file_exists( $main ) ) {
				return new WP_Error( 'coywolf_seo_missing_file', __( 'The PDF file is missing on disk.', 'coywolf-seo' ) );
			}
			if ( (int) filesize( $main ) > self::MAX_PDF_BYTES ) {
				return new WP_Error( 'coywolf_seo_unsupported', __( 'This PDF is larger than 10 MB, so it was skipped.', 'coywolf-seo' ) );
			}
			$payload = $this->encode_file( $main, 'application/pdf' );
			if ( is_wp_error( $payload ) ) {
				return $payload;
			}
			$payload['block'] = 'document';
			return $payload;
		}

		if ( 0 !== strpos( $mime, 'image/' ) || 'image/svg+xml' === $mime ) {
			return new WP_Error( 'coywolf_seo_unsupported', __( 'This attachment is not a raster image or a PDF.', 'coywolf-seo' ) );
		}

		$main = get_attached_file( $attachment_id );
		if ( ! $main || ! file_exists( $main ) ) {
			return new WP_Error( 'coywolf_seo_missing_file', __( 'The image file is missing on disk.', 'coywolf-seo' ) );
		}

		// Prefer a bounded intermediate size — much smaller, same content.
		$candidate = '';
		$meta      = wp_get_attachment_metadata( $attachment_id );
		foreach ( array( 'large', 'medium_large' ) as $size ) {
			if ( ! empty( $meta['sizes'][ $size ]['file'] ) ) {
				$path = path_join( dirname( $main ), $meta['sizes'][ $size ]['file'] );
				if ( file_exists( $path ) ) {
					$candidate = $path;
					break;
				}
			}
		}
		if ( '' === $candidate ) {
			$candidate = $main;
		}

		$candidate_mime = wp_check_filetype( $candidate )['type'];
		$candidate_mime = $candidate_mime ? $candidate_mime : $mime;
		$size_ok        = (int) filesize( $candidate ) <= self::MAX_RAW_BYTES;
		$mime_ok        = in_array( $candidate_mime, self::DIRECT_MIMES, true );

		list( $width, $height ) = array_pad( array_values( (array) wp_getimagesize( $candidate ) ), 2, 0 );
		$edge_ok                = max( (int) $width, (int) $height ) <= self::MAX_EDGE * 2;

		$payload = $size_ok && $mime_ok && $edge_ok
			? $this->encode_file( $candidate, $candidate_mime )
			: $this->reencode( $candidate );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		$payload['block'] = 'image';
		return $payload;
	}

	/**
	 * Read and base64-encode a file.
	 *
	 * @param string $path Path on disk.
	 * @param string $mime Mime type.
	 * @return array|WP_Error
	 */
	private function encode_file( $path, $mime ) {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		global $wp_filesystem;
		$contents = $wp_filesystem ? $wp_filesystem->get_contents( $path ) : false;
		if ( ! is_string( $contents ) || '' === $contents ) {
			return new WP_Error( 'coywolf_seo_read', __( 'The image file could not be read.', 'coywolf-seo' ) );
		}
		return array(
			'media_type' => $mime,
			'data'       => base64_encode( $contents ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- API payload encoding, not obfuscation.
		);
	}

	/**
	 * Re-encode an image to a bounded JPEG the API accepts.
	 *
	 * @param string $path Source path.
	 * @return array|WP_Error
	 */
	private function reencode( $path ) {
		// wp_tempnam() lives in wp-admin/includes/file.php, which is not loaded
		// on the front end or REST requests (where the bulk runner and the
		// per-image generate endpoint execute).
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return new WP_Error( 'coywolf_seo_unsupported', __( 'This image format cannot be converted for analysis on this server.', 'coywolf-seo' ) );
		}
		$editor->resize( self::MAX_EDGE, self::MAX_EDGE );
		$editor->set_quality( 82 );

		$temp  = wp_tempnam( 'coywolf-seo-it-ai.jpg' );
		$saved = $editor->save( $temp, 'image/jpeg' );
		if ( is_wp_error( $saved ) ) {
			wp_delete_file( $temp );
			return $saved;
		}
		$result = $this->encode_file( $saved['path'], 'image/jpeg' );
		wp_delete_file( $saved['path'] );
		if ( $saved['path'] !== $temp ) {
			wp_delete_file( $temp );
		}
		return $result;
	}

	/**
	 * Decode a JSON payload from model output, tolerating markdown fences
	 * and leading prose.
	 *
	 * @param string $text Model output.
	 * @return mixed|null
	 */
	private function decode_json( $text ) {
		$text  = trim( (string) $text );
		$text  = preg_replace( '/^```(?:json)?\s*/i', '', $text );
		$text  = preg_replace( '/\s*```$/', '', (string) $text );
		$start = strpbrk( $text, '{[' );
		if ( false === $start ) {
			return null;
		}
		return json_decode( $start, true );
	}

	/**
	 * Claude models available to the stored key, cached for 12 hours.
	 *
	 * @return array[] { id, name }
	 */
	public function models() {
		if ( ! $this->is_configured() ) {
			return array();
		}
		$cached = get_transient( self::MODELS_CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$response = wp_remote_get(
			self::API_BASE . '/v1/models?limit=100',
			array(
				'timeout'    => 8,
				'user-agent' => $this->user_agent(),
				'headers'    => array(
					'x-api-key'         => $this->api_key(),
					'anthropic-version' => self::API_VERSION,
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		$models = array();
		foreach ( (array) ( isset( $body['data'] ) ? $body['data'] : array() ) as $row ) {
			if ( is_array( $row ) && ! empty( $row['id'] ) && 0 === strpos( (string) $row['id'], 'claude-' ) ) {
				$models[] = array(
					'id'   => (string) $row['id'],
					'name' => ! empty( $row['display_name'] ) ? (string) $row['display_name'] : (string) $row['id'],
				);
			}
		}
		if ( ! empty( $models ) ) {
			set_transient( self::MODELS_CACHE, $models, 12 * HOUR_IN_SECONDS );
		}
		return $models;
	}

	/**
	 * Cheap connection test: list models with the stored key.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'coywolf_seo_no_key', __( 'No API key is saved yet.', 'coywolf-seo' ) );
		}
		$response = wp_remote_get(
			self::API_BASE . '/v1/models?limit=1',
			array(
				'timeout'    => 10,
				'user-agent' => $this->user_agent(),
				'headers'    => array(
					'x-api-key'         => $this->api_key(),
					'anthropic-version' => self::API_VERSION,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'coywolf_seo_api', $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( 'coywolf_seo_api', $this->api_error( $response ) );
		}
		return true;
	}

	/**
	 * Drop the cached model list (called when the key changes).
	 */
	public function flush_models_cache() {
		delete_transient( self::MODELS_CACHE );
	}

	/**
	 * Extract a readable error message from an Anthropic error response.
	 *
	 * @param array $response wp_remote_* response.
	 * @return string
	 */
	private function api_error( $response ) {
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$msg  = '';
		if ( is_array( $body ) && isset( $body['error']['message'] ) ) {
			$msg = (string) $body['error']['message'];
		}
		if ( '' === $msg ) {
			/* translators: %d: HTTP status code */
			return sprintf( __( 'The Claude API returned HTTP %d.', 'coywolf-seo' ), $code );
		}
		if ( 401 === $code ) {
			/* translators: %s: API error message */
			return sprintf( __( 'Invalid API key (%s). Check the key on the Settings page.', 'coywolf-seo' ), $msg );
		}
		return $msg;
	}

	/**
	 * Identify the site in outbound requests.
	 *
	 * @return string
	 */
	private function user_agent() {
		return 'CoywolfSEO/' . Coywolf_SEO::VERSION . '; ' . home_url();
	}
}
