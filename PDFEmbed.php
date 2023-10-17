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

/**
 * @psalm-api
 */
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
	 * Generates the PDF object tag.
	 *
	 * @param string $body Body of tag
	 * @param array $args Arguments on the tag.
	 * @param Parser $parser
	 * @return string HTML
	 */
	public static function generateTag( $body, $args, Parser $parser ): string {
		try {
			self::handleUser( $parser );
			$parsedBody = self::handleBody( $body );
			$parsedArgs = self::parseArgs( $args );

			[ $url, $page ] = $parsedBody;
			[ $height, $width, $pageArg, $iframe ] = $parsedArgs;

			if ( $pageArg > 1 ) {
				$page = $pageArg;
			}
			return self::embed( $url, $width, $height, $page, $iframe );
		} catch ( Exception $e ) {
			return self::error( $e->getMessage() );
		}
	}

	/**
	 * Determines if the user is allowed to embed PDFs
	 * Throws an exception that will be put in the wikitext if there is an error.
	 *
	 * @return void
	 */
	private static function handleUser( Parser $parser ): void {
		$ctx = RequestContext::getMain();
		// check the action which triggered us
		$requestAction = $ctx->getRequest()->getVal( 'action' );
		$revUserName = null;
		$user = null;

		if ( $requestAction === null ) {
			$revUserName = $parser->getRevisionUser();
		}

		// RevUser will be null during parser tests
		if ( $revUserName === null && $requestAction === null ) {
			return;
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
				wfMessage( 'embed_pdf_no_permission', wfMessage( 'right-embed_pdf' ) )->plain()
			);
		}
	}

	/**
	 * Handle the body of the pdf tag by checking for a url and then, if that fails,
	 * an uploaded file.
	 *
	 * @param string $body to check
	 * @return array
	 * @psalm-return list{ 0: string, 1: int }
	 */
	private static function handleBody( string $body ): array {
		$parsed = self::maybeURL( $body );
		if ( $parsed === null ) {
			$parsed = self::handleName( $body );
		}
		return $parsed;
	}

	/**
	 * Parse the arguments on the <pdf>
	 *
	 * @param array $arg passed in
	 * @psalm-param $arg array(
	 *     width: int,
	 *     height: int,
	 *     page: int,
	 *     iframe: mixed
	 * }
	 * @return array
	 * @psalm-return list{ int|null, int|null, int, bool }
	 */
	private static function parseArgs( array $arg ): array {
		/** @var array {
		 *     width: int,
		 *     height: int,
		 *     page: int,
		 *     iframe: mixed
		 * }
		 */
		global $wgPdfEmbed;
		/** @var string|null */
		$width = $arg['width'] ?? $wgPdfEmbed['width'] ?? null;
		/** @var string|null */
		$height = $arg['height'] ?? $wgPdfEmbed['height'] ?? null;
		/** @var string|int|null */
		$page = $arg['page'] ?? $wgPdfEmbed['page'] ?? 1;
		/** @var string|null */
		$iframe = $arg['iframe'] ?? $wgPdfEmbed['iframe'] ?? null;

		if ( $width !== null ) {
			$width = intval( $width );
		}

		if ( $height !== null ) {
			$height = intval( $height );
		}

		if ( $iframe !== null ) {
			$useFrame = strtolower( $iframe );
			$useFrameVal = abs( intval( $iframe ) );
			$iframe = $useFrameVal > 0 || $useFrame === "yes" || $useFrame === "true";
		}

		if ( $iframe === null ) {
			$iframe = false;
		}

		if ( !is_int( $page ) ) {
			$page = intval( $page );
		}

		return [ $height, $width, $page, $iframe ];
	}

	/**
	 * Returns an HTML node for the given file as string.
	 *
	 * @param string $url URL url to embed.
	 * @param ?int $width of the iframe.
	 * @param ?int $height of the iframe.
	 * @param ?int $page of the pdf file.
	 * @param bool $iframe if an <iframe> should be returned else an <object> is returned
	 * @return string HTML code for iframe.
	 */
	private static function embed(
		string $url, ?int $width, ?int $height, ?int $page, bool $iframe
	) {
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
	private static function error( string $error ): string {
		return Xml::span( $error, 'error' );
	}

	/**
	 * Attempt to parse the body of a pdf tag as a url.  Check against deny
	 * (and allow) lists and throws an error if the host does (not) match.
	 *
	 * @param string $url to check
	 * @return ?array
	 * @psalm-return ?list{ string, int }
	 */
	private static function maybeURL( string $url ): ?array {
		// parse the given url
		$parsed = parse_url( $url );
		if ( $parsed === false || !isset( $parsed['host'] ) ) {
			return null;
		}
		$host = strtolower( $parsed['host'] );
		$page = intval( $parsed['fragment'] ?? 1 );

		if ( self::isHostDenied( $host ) ) {
			throw new Exception(
				wfMessage( "embed_pdf_domain_black", $host )->plain()
			);
		}

		if ( !self::isHostAllowed( $host ) ) {
			throw new Exception(
				wfMessage( "embed_pdf_domain_not_white", $host )->plain()
			);
		}

		if ( $page === 0 ) {
			$page = 1;
		}
		return [ $url, $page ];
	}

	/**
	 * Handle's parsing of the tag body that points to an uploaded file
	 *
	 * @param string $name of upload
	 * @return array
	 * @psalm-return list{ string, int }
	 */
	private static function handleName( string $name ): array {
		$page = 1;
		$title = Title::newfromText( $name, NS_FILE );
		if ( $title === null || $title->getNamespace() !== NS_FILE ) {
			throw new Exception(
				wfMessage( 'embed_pdf_invalid_file_name', $name )->plain()
			);
		}

		if ( !$title->exists() ) {
			throw new Exception(
				wfMessage( 'embed_pdf_invalid_file', $title->getDBkey() )->plain()
			);
		}

		if ( $title->getFragment() ) {
			$page = intval( $title->getFragment() );
		}

		$repo = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo();
		$file = $repo->newFile( $title->getDBkey() );
		if ( $file === null ) {
			throw new Exception( wfMessage( 'embed_pdf_internal_error' )->plain() );
		}
		$url = $file->getUrl();

		if ( $page === 0 ) {
			$page = 1;
		}
		return [ $url, $page ];
	}

	/**
	 * Retrieve a host list from the configuration.
	 *
	 * @param string $type of list to get
	 * @return array
	 */
	private static function getHostList( string $type ): array {
		/** @var ?array {
		 *     white: string[],
		 *     black: string[]
		 * }
		 */
		global $wgPDF;

		$ret = [];
		if ( !is_array( $wgPDF ) ) {
			return $ret;
		}
		/**
		 * We're checking user input here.
		 *
		 * @psalm-suppress RedundantConditionGivenDocblockType
		 */
		if ( is_array( $wgPDF[$type] ) ) {
			$ret = $wgPDF[$type];
		}
		if ( count( $ret ) > 0 ) {
			$ret = array_flip( array_map( 'strtolower', $ret ) );
		}
		return $ret;
	}

	/**
	 * Check if host is on blocked list.
	 *
	 * @param string $host to check
	 * @return bool
	 */
	private static function isHostDenied( string $host ): bool {
		$deny = self::getHostList( "black" );

		if ( isset( $deny[ $host ] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if host is on allowed list.
	 *
	 * @param string $host to check
	 * @return bool
	 */
	private static function isHostAllowed( string $host ): bool {
		$allow = self::getHostList( "white" );

		if ( !isset( $allow[ $host ] ) && count( $allow ) > 0 ) {
			return false;
		}

		return true;
	}
}
