<?php

/**
 * PDFEmbed
 * PDFEmbed Hooks
 *
 * @author		Alexia E. Smith
 * @license		LGPL-3.0-only
 * @package		PDFEmbed
 * @link		https://www.mediawiki.org/wiki/Extension:PDFEmbed
 *
 */

use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserIdentity;

class PDFEmbed {

	/**
	 * Sets up this extensions parser functions.
	 *
	 * @param Parser $parser object passed as a reference.
	 * @return bool true
	 */
	public static function onParserFirstCallInit( Parser $parser ) {
		$parser->setHook( 'pdf', [ __CLASS__, 'generateTag' ] );

		return true;
	}

	/**
	 * disable the cache
	 *
	 * @param Parser $parser
	 */
	public static function disableCache( Parser $parser ) {
		// see https://gerrit.wikimedia.org/r/plugins/gitiles/mediawiki/extensions/MagicNoCache/+/refs/heads/master/src/MagicNoCacheHooks.php
		global $wgOut;
		$parser->getOutput()->updateCacheExpiry( 0 );

		if ( method_exists( $wgOut, 'disableClientCache' ) ) {
			$wgOut->disableClientCache();
		} else {
			$wgOut->enableClientCache( false );
		}
	}

	/**
	 * remove the File: prefix depending on the language or in english default form
	 *
	 * @param string $filename - the filename for which to fix the prefix
	 * @return string - the filename without the File: / Media: or i18n File/Media prefix
	 */
	public static function removeFilePrefix( $filename ) {
		$mwServices = MediaWikiServices::getInstance();

		if ( method_exists( $mwServices, "getContentLanguage" ) ) {
			$contentLang = $mwServices->getContentLanguage();

			# there are four possible prefixes: 'File' and 'Media' in English and in the wiki's language
			$ns_media_lang = $contentLang->getFormattedNsText( NS_MEDIA );
			$ns_file_lang  = $contentLang->getFormattedNsText( NS_FILE );

			if ( method_exists( $mwServices, "getLanguageFactory" ) ) {
				$langFactory = $mwServices->getLanguageFactory();
				$lang = $langFactory->getLanguage( 'en' );
				$ns_media_lang_en = $lang->getFormattedNsText( NS_MEDIA );
				$ns_file_lang_en  = $lang->getFormattedNsText( NS_FILE );
				$filename = preg_replace(
					"/^($ns_media_lang|$ns_file_lang|$ns_media_lang_en|$ns_file_lang_en):/",
					'', $filename
				);
			} else {
				$filename = preg_replace( "/^($ns_media_lang|$ns_file_lang):/", '', $filename );
			}
		}
		return $filename;
	}

	/**
	 * Generates the PDF object tag.
	 *
	 * @param string $obj Namespace prefixed article of the PDF file to display.
	 * @param array $args Arguments on the tag.
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @return string HTML
	 */
	public static function generateTag( $obj, $args, Parser $parser, PPFrame $frame ): string {
		global $wgPdfEmbed, $wgRequest, $wgPDF;

		// disable the cache
		self::disableCache( $parser );

		// grab the uri by parsing to html
		$html = $parser->recursiveTagParse( $obj, $frame );

		// check the action which triggered us
		$requestAction = $wgRequest->getVal( 'action' );

		if ( $requestAction === null ) {
			// https://www.mediawiki.org/wiki/Manual:UserFactory.php
			$revUserName = $parser->getRevisionUser();
			if ( empty( $revUserName ) ) {
				return self::error( 'embed_pdf_invalid_user' );
			}

			$userFactory = MediaWikiServices::getInstance()->getUserFactory();
			$user = $userFactory->newFromName( $revUserName );
		}

		// depending on the action get the responsible user
		if ( $requestAction === 'edit' || $requestAction === 'submit' ) {
			$user = RequestContext::getMain()->getUser();
		}

		if ( !( $user instanceof UserIdentity &&
			  MediaWikiServices::getInstance()->getPermissionManager()->userHasRight( $user, 'embed_pdf' )
		) ) {
			$parser->addTrackingCategory( "pdfembed-permission-problem-category" );
			return self::error( 'embed_pdf_no_permission', wfMessage( 'right-embed_pdf' ) );
		}

		// we don't want the html but just the href of the link
		// so we might reverse some of the parsing again by examining the html
		// whether it contains an anchor <a href= ...
		if ( strpos( $html, '<a' ) !== false ) {
			$anchor = new SimpleXMLElement( $html );
			// is there a href element?
			if ( isset( $anchor['href'] ) ) {
				// that's what we want ...
				$html = $anchor['href'];
			}
		}

		if ( array_key_exists( 'width', $args ) ) {
			$widthStr = $parser->recursiveTagParse( $args['width'], $frame );
		} else {
			$widthStr = $wgPdfEmbed['width'];
		}

		if ( array_key_exists( 'height', $args ) ) {
			$heightStr = $parser->recursiveTagParse( $args['height'], $frame );
		} else {
			$heightStr = $wgPdfEmbed['height'];
		}

		if ( array_key_exists( 'page', $args ) ) {
			$page = intval( $parser->recursiveTagParse( $args['page'], $frame ) );
		} else {
			$page = 1;
		}

		if ( !preg_match( '~^\d+~', $widthStr ) ) {
			return self::error( "embed_pdf_invalid_width", $widthStr );
		} elseif ( !preg_match( '~^\d+~', $heightStr ) ) {
			return self::error( "embed_pdf_invalid_height", $heightStr );
		}

		$width = intVal( $widthStr );
		$height = intVal( $heightStr );

		if ( array_key_exists( 'iframe', $args ) ) {
			$iframe = $parser->recursiveTagParse( $args['iframe'], $frame );
		} else {
			$iframe = $wgPdfEmbed['iframe'];
		}

		# if there are no slashes in the name we assume this
		# might be a pointer to a file
		if ( preg_match( '~^([^\/]+\.pdf)(#[0-9]+)?$~', $html, $matches ) ) {
			# re contains the groups
			$filename = $matches[1];
			if ( count( $matches ) == 3 ) {
				$page = $matches[2];
			}

			$filename = self::removeFilePrefix( $filename );
			$pdfFile = MediaWikiServices::getInstance()->getRepoGroup()->findFile( $filename );

			if ( $pdfFile !== false ) {
				$url = $pdfFile->getFullUrl();
				return self::embed( $url, $width, $height, $page, $iframe );
			} else {
				return self::error( 'embed_pdf_invalid_file', $filename );
			}
		} else {
			// parse the given url
			$domain = parse_url( $html );

			// check that the parsing worked and retrieve a valid host
			// no relative urls are allowed ...
			if ( $domain === false || ( !isset( $domain['host'] ) ) ) {
				if ( !isset( $domain['host'] ) ) {
					return self::error( "embed_pdf_invalid_relative_domain", $html );
				}
				return self::error( "embed_pdf_invalid_url", $html );
			}

			if ( isset( $wgPDF ) ) {

				foreach ( $wgPDF['black'] as $x => $y ) {
					$wgPDF['black'][$x] = strtolower( $y );
				}
				foreach ( $wgPDF['white'] as $x => $y ) {
					$wgPDF['white'][$x] = strtolower( $y );
				}

				$host = strtolower( $domain['host'] );
				$whitelisted = false;

				if ( in_array( $host, $wgPDF['white'] ) ) {
					$whitelisted = true;
				}

				if ( $wgPDF['white'] != [] && !$whitelisted ) {
					return self::error( "embed_pdf_domain_not_white", $host );
				}

				if ( !$whitelisted ) {
					if ( in_array( $host, $wgPDF['black'] ) ) {
						return self::error( "embed_pdf_domain_black", $host );
					}
				}
			}

			# check that url is valid
			if ( filter_var( $html, FILTER_VALIDATE_URL, FILTER_FLAG_PATH_REQUIRED ) ) {
				return self::embed( $html, $width, $height, $page, $iframe );
			} else {
				return self::error( 'embed_pdf_invalid_url', $html );
			}
		}
	}

	/**
	 * Returns an HTML node for the given file as string.
	 *
	 * @param string $url URL url to embed.
	 * @param int $width of the iframe.
	 * @param int $height of the iframe.
	 * @param int $page of the pdf file.
	 * @param bool $iframe if an <iframe> should be returned else an <object> is returned
	 * @return string HTML code for iframe.
	 */
	private static function embed( $url, $width, $height, $page, $iframe ) {
		# secure and concatenate the url
		$pdfSafeUrl = htmlentities( $url ) . '#page=' . $page;
		# check the embed mode and return a proper HTML element
		if ( $iframe ) {
			return Html::rawElement( 'iframe', [
				'class' => 'pdf-embed',
				'width' => $width,
				'height' => $height,
				'src' => $pdfSafeUrl,
				'style' => 'max-width: 100%;'
			] );
		} else {
			# object mode (default)
			return Html::rawElement( 'object', [
				'class' => 'pdf-embed',
				'width' => $width,
				'height' => $height,
				'data' => $pdfSafeUrl,
				'style' => 'max-width: 100%;',
				'type' => 'application/pdf'
			], Html::rawElement(
				'a',
				[
					'href' => $pdfSafeUrl
				],
				wfMessage( 'pdfembed-load-pdf' )
			) );
		}
	}

	/**
	 * Returns a standard error message.
	 *
	 * @param string $messageKey Error message key to display.
	 * @param array ...$params any parameters for the error message
	 * @return string HTML error message.
	 */
	private static function error( $messageKey, ...$params ) {
		return Xml::span( wfMessage( $messageKey, $params )->plain(), 'error' );
	}
}
