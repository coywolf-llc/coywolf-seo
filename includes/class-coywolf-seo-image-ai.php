<?php
/**
 * Image-analysis client.
 *
 * Sends an image or PDF attachment to the active AI provider (vision /
 * document blocks) and turns the response into the four Media Library text
 * fields: alternative text, title, caption, and description. Also lists the
 * provider's available models for the Settings screen and runs the connection
 * test. The HTTP call, key/model resolution, and pricing all come from the
 * provider chosen on the Settings page; everything else (payload building,
 * image re-encoding, the accessibility prompt, field sanitization, and saving)
 * is provider-agnostic and lives here.
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
 * Image-analysis client routed through the active AI provider.
 */
final class Coywolf_SEO_Image_AI {

	/**
	 * Default model id, kept for the Image Text page model list (which adds the
	 * Settings model and this default as always-selectable entries). The active
	 * provider supplies the real default; this mirrors the historical Anthropic
	 * default for that UI fallback only.
	 */
	const DEFAULT_MODEL = 'claude-opus-4-8';
	const MAX_TOKENS    = 1024;

	/**
	 * Rough per-image token estimates for the cost estimator: one downscaled
	 * image (~1,000–1,200 tokens at the "large" size we send), the detailed
	 * accessibility analysis prompt and system text, a short four-field JSON
	 * response, and the capped surrounding-article context window (~500 tokens)
	 * that is included whenever the image is used in a post or page.
	 */
	const EST_INPUT_TOKENS  = 2500;
	const EST_OUTPUT_TOKENS = 250;

	/**
	 * Pricing for a model ID, by longest matching prefix, from the active
	 * provider's price table.
	 *
	 * @param string $model_id Model ID.
	 * @param bool   $batch    Use the discounted Batch-API table instead of the
	 *                         standard (real-time) one.
	 * @return array|null { input: float, output: float } USD/MTok, or null when unknown.
	 */
	public static function model_pricing( $model_id, $batch = false ) {
		$prices = $batch
			? Coywolf_SEO_AI_Providers::current()->batch_prices()
			: Coywolf_SEO_AI_Providers::current()->standard_prices();
		$entry  = Coywolf_SEO_AI_Provider::price_entry( $prices, (string) $model_id );
		if ( null === $entry ) {
			return null;
		}
		return array(
			'input'  => (float) $entry[0],
			'output' => (float) $entry[1],
		);
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
	 * API key for the active provider. The provider's key() honours the master
	 * AI feature gate (returns '' when AI enrichment is off) and resolves the
	 * saved option > wp-config constant > environment variable precedence.
	 *
	 * @return string
	 */
	public function api_key() {
		return Coywolf_SEO_AI_Providers::current()->key();
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
	 * Where the active provider's key comes from, for the Settings indicator.
	 *
	 * @return array {
	 *     @type string $source   Active source: 'saved'|'constant'|'env'|'' (none).
	 *     @type bool   $saved    A key is saved in the database.
	 *     @type bool   $constant The provider's key constant is defined in wp-config.php.
	 *     @type bool   $env      The provider's key constant is set as an environment variable.
	 * }
	 */
	public function key_status() {
		return Coywolf_SEO_AI_Providers::current()->key_status();
	}

	/**
	 * Model to use for image analysis (the active provider's chosen model).
	 *
	 * @return string
	 */
	public function model() {
		return Coywolf_SEO_AI_Providers::current()->model();
	}

	/**
	 * Analyze one image or PDF attachment and return the four text fields.
	 *
	 * @param int    $attachment_id   Attachment ID.
	 * @param string $model           Optional model override for this request
	 *                                (the bulk runner's per-run model picker);
	 *                                empty uses the Settings model.
	 * @param string $article_context Surrounding post/page text (with the image's
	 *                                position marked) for context-aware output.
	 * @return array|WP_Error { alt_text, title, caption, description }
	 */
	public function generate_image_text( $attachment_id, $model = '', $article_context = '' ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'coywolf_seo_no_key',
				__( 'Add your AI provider API key on the Settings page first.', 'coywolf-seo' )
			);
		}

		$payload = $this->attachment_payload( $attachment_id );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$provider  = Coywolf_SEO_AI_Providers::current();
		$use_model = ( is_string( $model ) && preg_match( '/^[a-z0-9._-]+$/i', $model ) && '' !== $model ) ? $model : $provider->model();

		$res = $provider->vision_generate(
			$this->api_key(),
			$use_model,
			$payload,
			$this->system_prompt(),
			$this->prompt( $attachment_id, 'document' === $payload['block'], $article_context ),
			self::MAX_TOKENS
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return $this->parse_fields( (string) ( $res['text'] ?? '' ) );
	}

	/**
	 * The shared system instruction for every image/PDF analysis (real-time or
	 * batch).
	 *
	 * @return string
	 */
	private function system_prompt() {
		return 'You write accessibility-first metadata for files in a WordPress media library, '
			. 'following WCAG-aligned guidance for alternative text, titles, captions, and descriptions. '
			. 'You write for the people who depend on this text — screen reader and braille users, and anyone on a '
			. 'text-only or broken-image fallback — and never for search engines. '
			. 'You respond with raw JSON only: a single JSON object, no markdown fences, no commentary.';
	}

	/**
	 * Turn raw model output into the four sanitized text fields. Shared by the
	 * real-time path and the bulk Batch-API collector.
	 *
	 * @param string $text Raw assistant text (a JSON object).
	 * @return array|WP_Error { alt_text, title, caption, description }
	 */
	public function parse_fields( $text ) {
		if ( '' === trim( (string) $text ) ) {
			// An empty body usually means the model hit its output-token limit
			// before answering (common with reasoning models on complex images).
			return new WP_Error( 'coywolf_seo_api', __( 'The AI service returned an empty response (it may have run out of output tokens). Try again, or pick a different model.', 'coywolf-seo' ) );
		}
		$fields = $this->decode_json( (string) $text );
		if ( ! is_array( $fields ) ) {
			return new WP_Error( 'coywolf_seo_api', __( 'The AI service returned a response that could not be parsed. Try again.', 'coywolf-seo' ) );
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
			return new WP_Error( 'coywolf_seo_api', __( 'The AI service did not return usable image text. Try again.', 'coywolf-seo' ) );
		}
		return $clean;
	}

	/**
	 * Build one Batch-API request line for an attachment's accessibility text,
	 * for the bulk Image Text writer's batch mode. Returns the provider-shaped
	 * request, or WP_Error when the file cannot be prepared (unsupported,
	 * missing, too large) — the caller records that as a per-file failure and
	 * leaves it out of the batch.
	 *
	 * @param Coywolf_SEO_AI_Batch $batch         Facade built with the run model.
	 * @param string               $custom_id     Correlation id (round-trips to results).
	 * @param int                  $attachment_id Attachment ID.
	 * @return array|WP_Error
	 */
	public function build_batch_request( Coywolf_SEO_AI_Batch $batch, $custom_id, $attachment_id, $article_context = '' ) {
		$payload = $this->attachment_payload( $attachment_id );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		return $batch->request_vision(
			$custom_id,
			$this->system_prompt(),
			$payload,
			$this->prompt( $attachment_id, 'document' === $payload['block'], $article_context ),
			self::MAX_TOKENS
		);
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
	 * @param int    $attachment_id   Attachment ID.
	 * @param bool   $is_pdf          Adjust the instructions for a PDF document.
	 * @param string $article_context Surrounding post/page text with the image's
	 *                                position marked, for context-aware alt/caption
	 *                                (empty when the image is not used in content).
	 * @return string
	 */
	private function prompt( $attachment_id, $is_pdf = false, $article_context = '' ) {
		$file   = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$locale = get_locale();

		$extra = trim( (string) Coywolf_SEO_Options::get( 'image_text_instructions' ) );

		$json_contract = "Respond with a JSON object:\n"
			. '{"alt_text": string, "title": string, "caption": string, "description": string}' . "\n\n";

		$article_context = trim( (string) $article_context );
		$image_intro     = '' !== $article_context
			? 'This metadata becomes the default for the file; it appears in the article shown below, so make the alternative text and caption fit how it is used there while still reading well on their own.'
			: 'This metadata becomes the default for every future use of the file, and you are given no specific post context, so it must stand on its own.';

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
			$prompt = 'Analyze the image and write accessibility-first Media Library metadata for it, following the rules below. ' . $image_intro . "\n\n"
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
		if ( '' !== $article_context ) {
			$prompt .= "\n\nThe image appears in the article below. The marker «THE IMAGE BEING DESCRIBED» shows exactly where it sits. Use this only to make the alternative text and caption fit how the image is used here — describe the image itself, and never restate or summarize the article text:\n\n"
				. $article_context;
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
	 * Models selectable for image analysis from the active provider, cached for
	 * 12 hours. The cache is scoped per service so switching providers does not
	 * surface another provider's model list. Falls back to the provider's
	 * curated list when a live fetch is unavailable.
	 *
	 * @return array[] { id, name }
	 */
	public function models() {
		if ( ! $this->is_configured() ) {
			return array();
		}
		$key = $this->api_key();
		return Coywolf_SEO_AI_Providers::cached_models(
			Coywolf_SEO_AI_Providers::MODELS_CACHE_VISION,
			static function () use ( $key ) {
				return Coywolf_SEO_AI_Providers::current()->list_models( $key );
			},
			static function () {
				return Coywolf_SEO_AI_Providers::current()->fallback_models();
			}
		);
	}

	/**
	 * Cheap connection test for the active provider: list its models with the
	 * stored key.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'coywolf_seo_no_key', __( 'No API key is saved yet.', 'coywolf-seo' ) );
		}
		$models = Coywolf_SEO_AI_Providers::current()->list_models( $this->api_key() );
		if ( empty( $models ) ) {
			return new WP_Error( 'coywolf_seo_api', __( 'The AI service did not return any models. Check the API key on the Settings page.', 'coywolf-seo' ) );
		}
		return true;
	}
}
