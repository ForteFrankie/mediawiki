<?php

namespace MediaWiki\Rest\Handler;

use MediaFileTrait;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Page\PageLookup;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use RepoGroup;
use User;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * Handler class for Core REST API endpoints that perform operations on revisions
 */
class MediaLinksHandler extends SimpleHandler {
	use MediaFileTrait;

	/** int The maximum number of media links to return */
	private const MAX_NUM_LINKS = 100;

	/** @var ILoadBalancer */
	private $loadBalancer;

	/** @var RepoGroup */
	private $repoGroup;

	/** @var PageLookup */
	private $pageLookup;

	/**
	 * @var ExistingPageRecord|false|null
	 */
	private $page = false;

	/**
	 * @param ILoadBalancer $loadBalancer
	 * @param RepoGroup $repoGroup
	 * @param PageLookup $pageLookup
	 */
	public function __construct(
		ILoadBalancer $loadBalancer,
		RepoGroup $repoGroup,
		PageLookup $pageLookup
	) {
		$this->loadBalancer = $loadBalancer;
		$this->repoGroup = $repoGroup;
		$this->pageLookup = $pageLookup;
	}

	/**
	 * @return ExistingPageRecord|null
	 */
	private function getPage(): ?ExistingPageRecord {
		if ( $this->page === false ) {
			$this->page = $this->pageLookup->getExistingPageByText(
					$this->getValidatedParams()['title']
				);
		}
		return $this->page;
	}

	/**
	 * @param string $title
	 * @return Response
	 * @throws LocalizedHttpException
	 */
	public function run( $title ) {
		$page = $this->getPage();
		if ( !$page ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'rest-nonexistent-title' )->plaintextParams( $title ),
				404
			);
		}

		if ( !$this->getAuthority()->authorizeRead( 'read', $page ) ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'rest-permission-denied-title' )->plaintextParams( $title ),
				403
			);
		}

		// @todo: add continuation if too many links are found
		$results = $this->getDbResults( $page->getId() );
		if ( count( $results ) > self::MAX_NUM_LINKS ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'rest-media-too-many-links' )
					->plaintextParams( $title )
					->numParams( self::MAX_NUM_LINKS ),
				500
			);
		}
		$response = $this->processDbResults( $results );
		return $this->getResponseFactory()->createJson( $response );
	}

	/**
	 * @param int $pageId the id of the page to load media links for
	 * @return array the results
	 */
	private function getDbResults( int $pageId ) {
		$dbr = $this->loadBalancer->getConnectionRef( DB_REPLICA );
		return $dbr->selectFieldValues(
			'imagelinks',
			'il_to',
			[ 'il_from' => $pageId ],
			__METHOD__,
			[
				'ORDER BY' => 'il_to',
				'LIMIT' => self::MAX_NUM_LINKS + 1,
			]
		);
	}

	/**
	 * @param array $results database results, or an empty array if none
	 * @return array response data
	 */
	private function processDbResults( $results ) {
		// Using "private" here means an equivalent of the Action API's "anon-public-user-private"
		// caching model would be necessary, if caching is ever added to this endpoint.
		// TODO: make RepoGroup::findFiles take Authority
		$user = User::newFromIdentity( $this->getAuthority()->getUser() );
		$findTitles = array_map( static function ( $title ) use ( $user ) {
			return [
				'title' => $title,
				'private' => $user,
			];
		}, $results );

		$files = $this->repoGroup->findFiles( $findTitles );
		list( $maxWidth, $maxHeight ) = self::getImageLimitsFromOption(
			$this->getAuthority()->getUser(),
			'imagesize'
		);
		$transforms = [
			'preferred' => [
				'maxWidth' => $maxWidth,
				'maxHeight' => $maxHeight,
			]
		];
		$response = [];
		foreach ( $files as $file ) {
			$response[] = $this->getFileInfo( $file, $user, $transforms );
		}

		$response = [
			'files' => $response
		];

		return $response;
	}

	public function needsWriteAccess() {
		return false;
	}

	public function getParamSettings() {
		return [
			'title' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}

	/**
	 * @return string|null
	 * @throws LocalizedHttpException
	 */
	protected function getETag(): ?string {
		$page = $this->getPage();
		if ( !$page ) {
			return null;
		}

		// XXX: use hash of the rendered HTML?
		return '"' . $page->getLatest() . '@' . wfTimestamp( TS_MW, $page->getTouched() ) . '"';
	}

	/**
	 * @return string|null
	 * @throws LocalizedHttpException
	 */
	protected function getLastModified(): ?string {
		$page = $this->getPage();
		if ( !$page ) {
			return null;
		}

		return $page->getTouched();
	}

	/**
	 * @return bool
	 */
	protected function hasRepresentation() {
		return (bool)$this->getPage();
	}
}
