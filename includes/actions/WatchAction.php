<?php
/**
 * Performs the watch actions on a page
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA
 *
 * @file
 * @ingroup Actions
 */

use MediaWiki\MediaWikiServices;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Permissions\Authority;
use Wikimedia\ParamValidator\TypeDef\ExpiryDef;

/**
 * Page addition to a user's watchlist
 *
 * @ingroup Actions
 */
class WatchAction extends FormAction {

	/** @var bool The value of the $wgWatchlistExpiry configuration variable. */
	protected $watchlistExpiry;

	/** @var string */
	protected $expiryFormFieldName = 'expiry';

	/** @var false|WatchedItem */
	protected $watchedItem = false;

	/**
	 * Only public since 1.21
	 *
	 * @param Page $page
	 * @param IContextSource|null $context
	 */
	public function __construct( Page $page, IContextSource $context = null ) {
		parent::__construct( $page, $context );
		$this->watchlistExpiry = $this->getContext()->getConfig()->get( 'WatchlistExpiry' );
		if ( $this->watchlistExpiry ) {
			// The watchedItem is only used in this action's form if $wgWatchlistExpiry is enabled.
			$this->watchedItem = MediaWikiServices::getInstance()
				->getWatchedItemStore()
				->getWatchedItem( $this->getUser(), $this->getTitle() );
		}
	}

	public function getName() {
		return 'watch';
	}

	public function requiresUnblock() {
		return false;
	}

	protected function getDescription() {
		return '';
	}

	public function onSubmit( $data ) {
		$expiry = $this->getRequest()->getVal( 'wp' . $this->expiryFormFieldName );

		// Even though we're never unwatching here, use WatchlistManager::setWatch() because it also checks for
		// changed expiry.
		return MediaWikiServices::getInstance()->getWatchlistManager()->setWatch(
			true,
			$this->getContext()->getAuthority(),
			$this->getTitle(),
			$expiry
		);
	}

	protected function checkCanExecute( User $user ) {
		// Must be logged in
		if ( $user->isAnon() ) {
			throw new UserNotLoggedIn( 'watchlistanontext', 'watchnologin' );
		}

		parent::checkCanExecute( $user );
	}

	protected function usesOOUI() {
		return true;
	}

	protected function getFormFields() {
		// If watchlist expiry is not enabled, return a simple confirmation message.
		if ( !$this->watchlistExpiry ) {
			return [
				'intro' => [
					'type' => 'info',
					'vertical-label' => true,
					'raw' => true,
					'default' => $this->msg( 'confirm-watch-top' )->parse(),
				],
			];
		}

		// Otherwise, use a select-list of expiries.
		$expiryOptions = static::getExpiryOptions( $this->getContext(), $this->watchedItem );
		return [
			$this->expiryFormFieldName => [
				'type' => 'select',
				'label-message' => 'confirm-watch-label',
				'options' => $expiryOptions['options'],
				'default' => $expiryOptions['default'],
			]
		];
	}

	/**
	 * Get options and default for a watchlist expiry select list. If an expiry time is provided, it
	 * will be added to the top of the list as 'x days left'.
	 *
	 * @since 1.35
	 * @todo Move this somewhere better when it's being used in more than just this action.
	 *
	 * @param MessageLocalizer $msgLocalizer
	 * @param WatchedItem|bool $watchedItem
	 *
	 * @return mixed[] With keys `options` (string[]) and `default` (string).
	 */
	public static function getExpiryOptions( MessageLocalizer $msgLocalizer, $watchedItem ) {
		$expiryOptions = self::getExpiryOptionsFromMessage( $msgLocalizer );
		$default = in_array( 'infinite', $expiryOptions )
			? 'infinite'
			: current( $expiryOptions );
		if ( $watchedItem instanceof WatchedItem && $watchedItem->getExpiry() ) {
			// If it's already being temporarily watched,
			// add the existing expiry as the default option in the dropdown.
			$default = $watchedItem->getExpiry( TS_ISO_8601 );
			$daysLeft = $watchedItem->getExpiryInDaysText( $msgLocalizer, true );
			$expiryOptions = array_merge( [ $daysLeft => $default ], $expiryOptions );
		}
		return [
			'options' => $expiryOptions,
			'default' => $default,
		];
	}

	/**
	 * Parse expiry options message. Fallback to english options
	 * if translated options are invalid or broken
	 *
	 * @param MessageLocalizer $msgLocalizer
	 * @param string|null $lang
	 * @return string[]
	 */
	private static function getExpiryOptionsFromMessage(
		MessageLocalizer $msgLocalizer, ?string $lang = null
	) : array {
		$expiryOptionsMsg = $msgLocalizer->msg( 'watchlist-expiry-options' );
		$optionsText = !$lang ? $expiryOptionsMsg->text() : $expiryOptionsMsg->inLanguage( $lang )->text();
		$options = XmlSelect::parseOptionsMessage(
			$optionsText
		);

		$expiryOptions = [];
		foreach ( $options as $label => $value ) {
			if ( strtotime( $value ) || wfIsInfinity( $value ) ) {
				$expiryOptions[$label] = $value;
			}
		}

		// If message options is invalid try to recover by returning
		// english options (T267611)
		if ( !$expiryOptions && $expiryOptionsMsg->getLanguage()->getCode() !== 'en' ) {
			return self::getExpiryOptionsFromMessage( $msgLocalizer, 'en' );
		}

		return $expiryOptions;
	}

	protected function alterForm( HTMLForm $form ) {
		$msg = $this->watchlistExpiry && $this->watchedItem ? 'updatewatchlist' : 'addwatch';
		$form->setWrapperLegendMsg( $msg );
		$submitMsg = $this->watchlistExpiry ? 'confirm-watch-button-expiry' : 'confirm-watch-button';
		$form->setSubmitTextMsg( $submitMsg );
		$form->setTokenSalt( 'watch' );
	}

	/**
	 * Show one of 8 possible success messages.
	 * The messages are:
	 * 1. addedwatchtext
	 * 2. addedwatchtext-talk
	 * 3. addedwatchindefinitelytext
	 * 4. addedwatchindefinitelytext-talk
	 * 5. addedwatchexpirytext
	 * 6. addedwatchexpirytext-talk
	 * 7. addedwatchexpiryhours
	 * 8. addedwatchexpiryhours-talk
	 */
	public function onSuccess() {
		$msgKey = $this->getTitle()->isTalkPage() ? 'addedwatchtext-talk' : 'addedwatchtext';
		$expiryLabel = null;
		$submittedExpiry = $this->getContext()->getRequest()->getText( 'wp' . $this->expiryFormFieldName );
		if ( $submittedExpiry ) {
			// We can't use $this->watcheditem to get the expiry because it's not been saved at this
			// point in the request and so its values are those from before saving.
			$expiry = ExpiryDef::normalizeExpiry( $submittedExpiry, TS_ISO_8601 );

			// If the expiry label isn't one of the predefined ones in the dropdown, calculate 'x days'.
			$expiryDays = WatchedItem::calculateExpiryInDays( $expiry );
			$defaultLabels = static::getExpiryOptions( $this->getContext(), null )['options'];
			$localizedExpiry = array_search( $submittedExpiry, $defaultLabels );
			$expiryLabel = $expiryDays && $localizedExpiry === false
				? $this->getContext()->msg( 'days', $expiryDays )->text()
				: $localizedExpiry;

			// Determine which message to use, depending on whether this is a talk page or not
			// and whether an expiry was selected.
			$isTalk = $this->getTitle()->isTalkPage();
			if ( wfIsInfinity( $expiry ) ) {
				$msgKey = $isTalk ? 'addedwatchindefinitelytext-talk' : 'addedwatchindefinitelytext';
			} elseif ( $expiryDays > 0 ) {
				$msgKey = $isTalk ? 'addedwatchexpirytext-talk' : 'addedwatchexpirytext';
			} elseif ( $expiryDays < 1 ) {
				$msgKey = $isTalk ? 'addedwatchexpiryhours-talk' : 'addedwatchexpiryhours';
			}
		}
		$this->getOutput()->addWikiMsg( $msgKey, $this->getTitle()->getPrefixedText(), $expiryLabel );
	}

	/**
	 * Watch or unwatch a page
	 *
	 * @param bool $watch Whether to watch or unwatch the page
	 * @param PageIdentity $pageIdentity Page to watch/unwatch
	 * @param Authority $performer who is watching/unwatching
	 * @param string|null $expiry Optional expiry timestamp in any format acceptable to wfTimestamp(),
	 *   null will not create expiries, or leave them unchanged should they already exist.
	 *
	 * @return Status
	 * @since 1.35 New $expiry parameter.
	 * @since 1.22
	 * @deprecated since 1.37, use WatchlistManager:setWatch() instead.
	 */
	public static function doWatchOrUnwatch(
		$watch,
		PageIdentity $pageIdentity,
		Authority $performer,
		string $expiry = null
	) {
		wfDeprecated( __METHOD__, '1.37' );
		return Status::wrap( MediaWikiServices::getInstance()->getWatchlistManager()->setWatch(
			$watch,
			$performer,
			$pageIdentity,
			$expiry
		) );
	}

	/**
	 * Watch a page
	 * @since 1.22 Returns Status, $checkRights parameter added
	 * @param PageIdentity $pageIdentity Page to watch/unwatch
	 * @param Authority $performer User who is watching/unwatching
	 * @param bool $checkRights Passed through to $user->addWatch()
	 *     Pass User::CHECK_USER_RIGHTS or User::IGNORE_USER_RIGHTS.
	 * @param string|null $expiry Optional expiry timestamp in any format acceptable to wfTimestamp(),
	 *   null will not create expiries, or leave them unchanged should they already exist.
	 * @return Status
	 * @deprecated since 1.37, use WatchlistManager:addWatch() instead.
	 */
	public static function doWatch(
		PageIdentity $pageIdentity,
		Authority $performer,
		$checkRights = User::CHECK_USER_RIGHTS,
		?string $expiry = null
	) {
		wfDeprecated( __METHOD__, '1.37' );
		$watchlistManager = MediaWikiServices::getInstance()->getWatchlistManager();
		if ( $checkRights ) {
			return Status::wrap( $watchlistManager->addWatch(
				$performer,
				$pageIdentity,
				$expiry
			) );
		}
		return Status::wrap( $watchlistManager->addWatchIgnoringRights(
			$performer->getUser(),
			$pageIdentity,
			$expiry
		) );
	}

	/**
	 * Unwatch a page
	 *
	 * @param PageIdentity $pageIdentity Page to watch/unwatch
	 * @param Authority $performer User who is watching/unwatching
	 *
	 * @return Status
	 * @since 1.22 Returns Status
	 * @deprecated since 1.37, use WatchlistManager:removeWatch() instead.
	 */
	public static function doUnwatch( PageIdentity $pageIdentity, Authority $performer ) {
		wfDeprecated( __METHOD__, '1.37' );
		return Status::wrap( MediaWikiServices::getInstance()->getWatchlistManager()->removeWatch(
			$performer,
			$pageIdentity
		) );
	}

	/**
	 * Get token to watch (or unwatch) a page for a user
	 *
	 * @param PageIdentity $page Title object of page to watch
	 * @param User $user User for whom the action is going to be performed
	 * @param string $action Optionally override the action to 'unwatch'
	 * @return string Token
	 * @since 1.18
	 */
	public static function getWatchToken( PageIdentity $page, User $user, $action = 'watch' ) {
		if ( $action != 'unwatch' ) {
			$action = 'watch';
		}
		// This must match ApiWatch and ResourceLoaderUserOptionsModule
		return $user->getEditToken( $action );
	}

	public function doesWrites() {
		return true;
	}
}
