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
	 * Determines if the user is allowed to embed PDFs
	 * Throws an exception that will be put in the wikitext if there is an error.
	 *
	 * @return true
	 */
	private static function handleUser( Parser $parser ): bool {
		$ctx = RequestContext::getMain();
		// check the action which triggered us
		$requestAction = $ctx->getRequest()->getVal( 'action' );
		$revUserName = null;

		if ( $requestAction === null ) {
			$revUserName = $parser->getRevisionUser();
		}

		// RevUser will be null during parser tests
		if ( $revUserName === null && $requestAction === null ) {
			return true;
		}

		if ( $revUserName !== null ) {
			$userFactory = MediaWikiServices::getInstance()->getUserFactory();
			$user = $userFactory->newFromName( $revUserName );
		}

		// depending on the action get the responsible user
		if ( $requestAction === 'edit' || $requestAction === 'submit' ) {
			$user = $ctx->getUser();
		}

		$permManager = MediaWikiServices::getInstance()->getPermissionManager();
		if ( !( $user instanceof UserIdentity &&
				$permManager->userHasRight( $user, 'embed_pdf' )
		) ) {
			$parser->addTrackingCategory( "pdfembed-permission-problem-category" );

			throw new Exception(
				wfMessage( 'embed_pdf_no_permission', wfMessage( 'right-embed_pdf' ) )
			);
		}

		return true;
	}

	/**
	 * Attempt to parse the body of a pdf tag as a url.  Check against deny
	 * (and allow) lists and throws an error if the host does (not) match.
	 *
	 * @param string $url to check
	 * @return array (empty if $url is not a url with a hostname in it)
	 */
	private static function maybeURL( string $url ): array {
		global $wgPDF;

		// parse the given url
		$parsed = parse_url( $url );
		if ( $parsed === false || !isset( $parsed['host'] ) ) {
			return [];
		}
		$host = strtolower( $parsed['host'] );
		$page = intval( $parsed['fragment'] ?? 1 );

		if ( isset( $wgPDF ) ) {
			$deny = array_flip( array_map( 'strtolower', $wgPDF['black'] ?? [] ) );
			$allow = array_flip( array_map( 'strtolower', $wgPDF['white'] ?? [] ) );

			if ( !isset( $allow[ $host ] ) && count( $allow ) > 0 ) {
				throw new Exception( wfMessage( "embed_pdf_domain_not_white", $host ) );
			}

			if ( isset( $deny[ $host ] ) ) {
				throw new Exception( wfMessage( "embed_pdf_domain_black", $host ) );
			}
		}

		return [ $url, $page ];
	}

	/**
	 * Handle's parsing of the tag body that points to an uploaded file
	 *
	 * @param string $name of upload
	 * @return array
	 */
	private static function handleName( string $name ): array {
		$page = 1;
		$title = Title::newfromText( $name, NS_FILE );
		if ( $title === null || $title->getNamespace() !== NS_FILE ) {
			throw new Exception(
				wfMessage( 'embed_pdf_invalid_file_name', $name )
			);
		}

		if ( !$title->exists() ) {
			throw new Exception(
				wfMessage( 'embed_pdf_invalid_file', $title->getDBkey() )
			);
		}

		if ( $title->getFragment() ) {
			$page = intval( $title->getFragment() );
		}

		$repo = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo();
		$file = $repo->newFile( $title->getDBkey() );
		if ( $file === null ) {
			throw new Exception( wfMessage( 'embed_pdf_internal_error' ) );
		}
		$url = $file->getUrl();

		return [ $url, $page ];
	}

	/**
	 * Parse the arguments on the <pdf>
	 *
	 * @param array $args passed in
	 * @return array
	 */
	private static function parseArgs( array $args ): array {
		global $wgPdfEmbed;
		$width = $wgPdfEmbed['width'] ?? null;
		$height = $wgPdfEmbed['height'] ?? null;
		$page = 0;
		$iframe = $wgPdfEmbed['iframe'] ?? false;

		if ( isset( $args['width'] ) ) {
			$width = intval( $args['width'] );
		}

		if ( isset( $args['height'] ) ) {
			$height = intval( $args['height'] );
		}

		if ( isset( $args['iframe'] ) ) {
			$useFrame = strtolower( $args['iframe'] );
			$useFrameVal = abs( intval( $args['iframe'] ) );
			$iframe = $useFrameVal > 0 || $useFrame === "yes" || $useFrame === "true";
		}

		if ( isset( $args['page'] ) ) {
			$page = intval( $args['page'] );
		}

		return [ $height, $width, $page, $iframe ];
	}

	/**
	 * Handle the body of the pdf tag by checking for a url and then, if that fails,
	 * an uploaded file.
	 *
	 * @param string $body to check
	 * @return array
	 */
	private static function handleBody( string $body ): array {
		$parsed = self::maybeURL( $body );
		if ( $parsed === [] ) {
			$parsed = self::handleName( $body );
		}
		return $parsed;
	}

	/**
	 * Generates the PDF object tag.
	 *
	 * @param string $body Body of tag
	 * @param array $args Arguments on the tag.
	 * @param Parser $parser
	 * @return string HTML
	 */
	public static function generateTag( $body, $args, Parser $parser ): string {
		[ $url, $width, $height, $page, $iframe ]
			= [ null, null, null, 1, false ];
		try {
			self::handleUser( $parser );
			$parsedBody = self::handleBody( $body );
			$parsedArgs = self::parseArgs( $args );

			[ $url, $page ] = $parsedBody;
			[ $height, $width, $pageArg, $iframe ] = $parsedArgs;

			if ( $pageArg > 1 ) {
				$page = $pageArg;
			}
		} catch ( Exception $e ) {
			return self::error( $e->getMessage() );
		}
		return self::embed( $url, $width, $height, $page, $iframe );
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
		$pdfUrl = "$url#page=$page";
		# check the embed mode and return a proper HTML element
		if ( $iframe ) {
			return Html::element( 'iframe', [
				'class' => 'pdf-embed',
				'width' => $width,
				'height' => $height,
				'src' => $pdfUrl,
				'style' => 'max-width: 100%;'
			] );
		} else {
			# object mode (default)
			return Html::rawElement( 'object', [
				'class' => 'pdf-embed',
				'width' => $width,
				'height' => $height,
				'data' => $pdfUrl,
				'style' => 'max-width: 100%;',
				'type' => 'application/pdf'
			], Html::element(
				'a',
				[
					'href' => $pdfUrl
				],
				wfMessage( 'pdfembed-load-pdf' )->plain()
			) );
		}
	}

	/**
	 * Returns a standard error message.
	 *
	 * @param string $error Error message to display.
	 * @return string HTML error message.
	 */
	private static function error( $error ) {
		return Xml::span( $error, 'error' );
	}
}
