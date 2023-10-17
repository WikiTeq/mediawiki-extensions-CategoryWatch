<?php
/**
 * Hooks for CategoryWatch extension
 *
 * Copyright (C) 2017, 2018  NicheWork, LLC
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @author Mark A. Hershberger <mah@nichework.com>
 */
namespace CategoryWatch;

use Category;
use EchoEvent;
use ExtensionRegistry;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\PageIdentity;
use Title;
use User;
use WikiPage;

class Hook {
	/**
	 * Explain bundling
	 *
	 * @param EchoEvent $event to bundle
	 * @param string &$bundleString to use
	 */
	public static function onEchoGetBundleRules( EchoEvent $event, &$bundleString ) {
		switch ( $event->getType() ) {
			case 'categorywatch-add':
			case 'categorywatch-remove':
				$bundleString = 'categorywatch';
				break;
		}
	}

	/**
	 * Define the CategoryWatch notifications
	 *
	 * @param array &$notifications assoc array of notification types
	 * @param array &$notificationCategories assoc array describing
	 *        categories
	 * @param array &$icons assoc array of icons we define
	 */
	public static function onBeforeCreateEchoEvent(
		array &$notifications, array &$notificationCategories, array &$icons
	) {
		$icons['categorywatch']['path'] = 'CategoryWatch/assets/catwatch.svg';

		$notifications['categorywatch-add'] = [
			'bundle' => [
				'web' => true,
				'email' => true,
				'expandable' => true,
			],
			'title-message' => 'categorywatch-add-title',
			'category' => 'categorywatch',
			'group' => 'neutral',
			'user-locators' => [ 'CategoryWatch\\Hook::userLocater' ],
			'user-filters' => [ 'CategoryWatch\\Hook::userFilter' ],
			'presentation-model' => 'CategoryWatch\\EchoEventPresentationModel',
		];

		$notifications['categorywatch-remove'] = [
			'bundle' => [
				'web' => true,
				'email' => true,
				'expandable' => true,
			],
			'title-message' => 'categorywatch-remove-title',
			'category' => 'categorywatch',
			'group' => 'neutral',
			'user-locators' => [ 'CategoryWatch\\Hook::userLocater' ],
			'user-filters' => [ 'CategoryWatch\\Hook::userFilter' ],
			'presentation-model' => 'CategoryWatch\\EchoEventPresentationModel',
		];

		$notificationCategories['categorywatch'] = [
			'priority' => 2,
			'tooltip' => 'echo-pref-tooltip-categorywatch'
		];
	}

	/**
	 * Hook for page being added to a category.
	 *
	 * @param Category $cat that page is being add to
	 * @param WikiPage $page that is being added
	 */
	public static function onCategoryAfterPageAdded(
		Category $cat, WikiPage $page
	) {
		$mwServices = MediaWikiServices::getInstance();
		$store = $mwServices->getWatchedItemStore();
		$catPage = $cat->getPage();

		if ( !$store->countWatchers( $catPage ) ) {
			# Nobody watches the category
			return;
		}

		if ( ExtensionRegistry::getInstance()->getAllThings()['Echo'] ?? false ) {
			$revisionLockup = $mwServices->getRevisionLookup();
			$revision = $revisionLockup->getRevisionByTitle( $page );
			$revId = $revision ? $revision->getId() : null;
			# Send a notification!
			EchoEvent::create( [
				'type' => 'categorywatch-add',
				'title' => Title::castFromPageIdentity( $cat->getPage() ),
				'agent' => $revision->getUser(),
				'extra' => [
					'pageid' => $page->getId(),
					'revid' => $revId,
				],
			] );
		}
	}

	/**
	 * Preferences for catwatch
	 *
	 * @param User $user User whose preferences are being modified
	 * @param array &$preferences Preferences description array, to be fed to an HTMLForm object
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/GetPreferences
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public static function onGetPreferences( User $user, array &$preferences ) {
		$preferences['categorywatch-page-watch'] = [
			'type' => 'toggle',
			'label-message' => 'categorywatch-page-watch-pref',
			'section' => 'watchlist/advancedwatchlist'
		];
	}

	/**
	 * Hook for page being taken out of a category.
	 *
	 * @param Category $cat that page is being removed from
	 * @param WikiPage $page that is being removed
	 * @param int $pageID Page ID that this happened in. (not given pre 1.27ish)
	 * @see https://www.mediawiki.org/wiki/Special:MyLanguage/Manual:Hooks/CategoryAfterPageRemoved
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public static function onCategoryAfterPageRemoved(
		Category $cat, WikiPage $page, $pageID = 0
	) {
		$mwServices = MediaWikiServices::getInstance();
		$store = $mwServices->getWatchedItemStore();
		$catPage = $cat->getPage();

		if ( !$store->countWatchers( $catPage ) ) {
			# Nobody watches the category
			return;
		}

		if ( ExtensionRegistry::getInstance()->getAllThings()['Echo'] ?? false ) {
			$revisionLockup = $mwServices->getRevisionLookup();
			$revision = $revisionLockup->getRevisionByTitle( $page );
			$revId = $revision ? $revision->getId() : null;
			# Send a notification!
			EchoEvent::create( [
				'type' => 'categorywatch-remove',
				'title' => Title::castFromPageIdentity( $cat->getPage() ),
				'agent' => $revision->getUser(),
				'extra' => [
					'pageid' => $page->getId(),
					'revid' => $revId,
				],
			] );
		}
	}

	/**
	 * Find the watchers for a title
	 *
	 * @param PageIdentity $target to check
	 *
	 * @return User[]
	 */
	private static function getWatchers( PageIdentity $target ): array {
		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getMaintenanceConnectionRef( DB_REPLICA );
		$return = $dbr->selectFieldValues(
			'watchlist',
			'wl_user',
			[
				'wl_namespace' => $target->getNamespace(),
				'wl_title' => $target->getDBkey(),
			],
			__METHOD__
		);

		$userFactory = MediaWikiServices::getInstance()->getUserFactory();
		$userOptionLookup = MediaWikiServices::getInstance()->getUserOptionsLookup();
		$return = array_map( static function ( $userID ) use ( $userFactory, $userOptionLookup ) {
			$user = $userFactory->newFromId( $userID );
			if ( $userOptionLookup->getOption( $user, 'categorywatch-page-watch' ) ) {
				return $user;
			}
			return null;
		}, $return );
		return array_filter( $return );
	}

	/**
	 * Get users that should be notified for this event.
	 *
	 * @param EchoEvent $event to be looked at
	 * @return array
	 */
	public static function userLocater( EchoEvent $event ): array {
		return self::getWatchers( $event->getTitle() );
	}

	/**
	 * Filter out the person performing the action
	 *
	 * @param EchoEvent $event to be looked at
	 * @return array
	 */
	public static function userFilter( EchoEvent $event ): array {
		return [ $event->getAgent() ];
	}
}
