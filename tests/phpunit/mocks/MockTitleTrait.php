<?php

use PHPUnit\Framework\MockObject\MockObject;

trait MockTitleTrait {

	/** @var int */
	private $pageIdCounter = 0;

	/**
	 * @param string $text
	 * @param array $props Additional properties to set. Supported keys:
	 *        - id: int
	 *        - namespace: int
	 *        - fragment: string
	 *        - interwiki: string
	 *        - redirect: bool
	 *        - language: Language
	 *        - contentModel: string
	 *        - revision: int
	 *
	 * @return Title|MockObject
	 */
	private function makeMockTitle( $text, array $props = [] ) {
		$id = $props['id'] ?? ++$this->pageIdCounter;
		$ns = $props['namespace'] ?? 0;
		$nsName = $ns ? "ns$ns:" : '';

		$preText = $text;
		$text = preg_replace( '/^[\w ]*?:/', '', $text );

		// If no namespace prefix was given, add one if needed.
		if ( $preText == $text && $ns ) {
			$preText = $nsName . $text;
		}

		/** @var Title|MockObject $title */
		$title = $this->createMock( Title::class );

		$title->method( 'getText' )->willReturn( str_replace( '_', ' ', $text ) );
		$title->method( 'getDBkey' )->willReturn( str_replace( ' ', '_', $text ) );

		$title->method( 'getPrefixedText' )->willReturn( str_replace( '_', ' ', $preText ) );
		$title->method( 'getPrefixedDBkey' )->willReturn( str_replace( ' ', '_', $preText ) );

		$title->method( 'getArticleID' )->willReturn( $id );
		$title->method( 'getId' )->willReturn( $id );
		$title->method( 'getNamespace' )->willReturn( $ns );
		$title->method( 'getFragment' )->willReturn( $props['fragment'] ?? '' );
		$title->method( 'hasFragment' )->willReturn( !empty( $props['fragment'] ) );
		$title->method( 'getInterwiki' )->willReturn( $props['interwiki'] ?? '' );
		$title->method( 'exists' )->willReturn( $id > 0 );
		$title->method( 'isRedirect' )->willReturn( $props['redirect'] ?? false );
		$title->method( 'getTouched' )->willReturn( $id ? '20200101223344' : false );

		// TODO getPageLanguage should return a Language object, 'qqx' is a string
		$title->method( 'getPageLanguage' )->willReturn( $props['language'] ?? 'qqx' );
		$title->method( 'getContentModel' )
			->willReturn( $props['contentModel'] ?? CONTENT_MODEL_WIKITEXT );
		$title->method( 'getRestrictions' )->willReturn( [] );
		$title->method( 'getTitleProtection' )->willReturn( false );
		$title->method( 'canExist' )
			->willReturn( $ns >= 0 && empty( $props['interwiki'] ) && $text !== '' );
		$title->method( 'getWikiId' )->willReturn( Title::LOCAL );
		if ( isset( $props['revision'] ) ) {
			$title->method( 'getLatestRevId' )->willReturn( $props['revision'] );
		} else {
			$title->method( 'getLatestRevId' )->willReturn( $id === 0 ? 0 : 43 );
		}
		$title->method( 'isContentPage' )->willReturn( true );
		$title->method( 'isSamePageAs' )->willReturnCallback( static function ( $other ) use ( $id ) {
			return $other && $id === $other->getArticleId();
		} );
		$title->method( 'isSameLinkAs' )->willReturnCallback( static function ( $other ) use ( $ns, $text ) {
			return $other && $text === $other->getDBkey() && $ns === $other->getNamespace();
		} );
		$title->method( 'equals' )->willReturnCallback( static function ( $other ) use ( $preText ) {
			return $other->getNamespace() ? 'ns' . $other->getNamespace() . ':' : '' . $other->getDBkey() ===
				str_replace( ' ', '_', $preText );
		} );
		$title->method( '__toString' )->willReturn( "MockTitle:{$preText}" );

		return $title;
	}
}
