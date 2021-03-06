<?php
/**
 * Copyright (C) 2016  NicheWork, LLC
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

namespace MediaWiki\Extension\PeriodicRelatedChanges;

use MWException;
use Page;
use ResultWrapper;
use Status;
use Title;
use User;

class Manager {
	protected $collectedChanges = [];

	/**
	 * Get the manager for this
	 *
	 * @return MediaWiki\Extension\PeriodicRelatedChanges\Manager
	 */
	public static function getManager() {
		wfDebugLog( 'PeriodicRelatedChanges::Manager', __METHOD__ );
		return new self();
	}

	/**
	 * Get a RelatedChangeWatcher object
	 * @param User $user the user.
	 * @param Title $title the page name.
	 * @return RelatedChangeWatcher object
	 */
	public function get( User $user, Title $title ) {
		wfDebugLog( 'PeriodicRelatedChanges::Manager', __METHOD__ );
		return RelatedChangeWatcher::newFromUserTitle( $user, $title );
	}

	/**
	 * Make sure user is known and not anon.  Make sure title exists.
	 *
	 * @param User $user the user.
	 * @param Title $title the page name.
	 *
	 * @return Status
	 */
	public function isValidUserTitle( User $user, Title $title ) {
		wfDebugLog( 'PeriodicRelatedChanges::Manager', __METHOD__ );
		if ( $user->isAnon() ) {
			return Status::newFatal(
				"periodic-related-changes-no-anon", $user
			);
		}
		if ( $user->getID() === 0 ) {
			return Status::newFatal(
				"periodic-related-changes-user-not-exist", $user
			);
		}

		// Because we're working with MW before June 2017, we can't use isValid:
		$validTitle = Title::makeTitleSafe(
			$title->getNamespace(), $title->getDBkey()
		);
		if ( $validTitle === null ) {
			return Status::newFatal(
				"periodic-related-changes-title-not-valid", $user
			);
		}

		return Status::newGood();
	}

	/**
	 * See if this is watched
	 *
	 * @param User $user the user.
	 * @param Title $title the page name.
	 *
	 * @return bool
	 */
	public function isWatched( User $user, Title $title ) {
		wfDebugLog( 'PeriodicRelatedChanges::Manager', __METHOD__ );
		$check = $this->isValidUserTitle( $user, $title );
		if ( !$check->isGood() ) {
			return false;
		}

		$watch = new RelatedChangeWatcher( $user, $title );
		if ( !$watch->exists() ) {
			return false;
		}
		return true;
	}

	/**
	 * Remove a watcher.
	 *
	 * @param User $user the user.
	 * @param Title $title the page name.
	 *
	 * @return Status
	 */
	public function removeWatch( User $user, Title $title ) {
		wfDebugLog( 'PeriodicRelatedChanges::Manager', __METHOD__ );
		$check = $this->isValidUserTitle( $user, $title );
		if ( !$check->isGood() ) {
			return $check;
		}

		$watch = new RelatedChangeWatcher( $user, $title );
		if ( !$watch->exists() ) {
			return Status::newFatal(
				"periodic-related-changes-does-not-exist", $title, $user
			);
		}
		return $watch->remove();
	}

	/**
	 * Store a watch for the user.  SQL schema ensures that there can
	 * only be one.
	 *
	 * @param User $user the user
	 * @param Title $title what to watch for related changes.
	 *
	 * @return Status
	 */
	public function addWatch( User $user, Title $title ) {
		wfDebugLog( 'PeriodicRelatedChanges::Manager', __METHOD__ );
		$check = $this->isValidUserTitle( $user, $title );
		if ( !$check->isGood() ) {
			return $check;
		}

		$watch = new RelatedChangeWatcher( $user, $title );
		if ( !$watch->exists() ) {
			return $watch->save();
		}
		return Status::newFatal(
			"periodic-related-changes-already-exists", $title, $user
		);
	}

	/**
	 * Dumplicate the people watching the title
	 *
	 * @param Title $oldTitle original title
	 * @param Title $newTitle title to dupe watches from old title
	 *
	 * @return Status
	 */
	public function duplicateEntries( Title $oldTitle, Title $newTitle ) {
		wfDebugLog( 'PeriodicRelatedChanges::Manager', __METHOD__ );
		return RelatedChangeWatcher::duplicateEntries( $oldTitle, $newTitle );
	}

	/**
	 * Get the a watcher, ensuring that it exists.
	 *
	 * @param User $user the user
	 * @param Title $title what to watch for related changes.
	 *
	 * @return Status|RelatedChangeWatcher
	 */
	protected function getExistingWatcher( User $user, Title $title ) {
		wfDebugLog( 'PeriodicRelatedChanges::Manager', __METHOD__ );
		$check = $this->isValidUserTitle( $user, $title );
		if ( !$check->isGood() ) {
			return $check;
		}

		$watch = new RelatedChangeWatcher( $user, $title );
		if ( !$watch->exists() ) {
			return Status::newFatal(
				"periodic-related-changes-does-not-exist", $title, $user
			);
		}

		return $watch;
	}

	/**
	 * Reset the timestamp
	 *
	 * @param User $user the user
	 * @param Title $title what to watch for related changes.
	 *
	 * @return Status|bool
	 */
	public function resetNotificationTimestamp( User $user, Title $title ) {
		wfDebugLog( 'PeriodicRelatedChanges::Manager', __METHOD__ );
		return $this->updateNotificationTimestamp( $user, $title, null );
	}

	/**
	 * Update the timestamp
	 *
	 * @param User $user the user
	 * @param Title $title what to watch for related changes.
	 * @param string $ts value
	 *
	 * @return Status|bool
	 */
	public function updateNotificationTimestamp(
		User $user, Title $title, $ts
	) {
		wfDebugLog( 'PeriodicRelatedChanges::Manager', __METHOD__ );
		$watch = $this->getExistingWatcher( $user, $title );
		if ( $watch instanceof Status ) {
			return $watch;
		}

		return $watch->setTimestamp( $ts );
	}

	/**
	 * Get this watch's timestamp
	 *
	 * @param User $user the user
	 * @param Title $title what to watch for related changes.
	 *
	 * @return Status|string
	 */
	public function getNotificationTimestamp( User $user, Title $title ) {
		wfDebugLog( 'PeriodicRelatedChanges::Manager', __METHOD__ );
		$watch = $this->getExistingWatcher( $user, $title );
		if ( $watch instanceof Status ) {
			return $watch;
		}

		return $watch->getTimestamp();
	}

	/**
	 * Get related pages
	 *
	 * @param User $user the user
	 * @param Page $page what to watch for related changes.
	 *
	 * @return RelatedChangeWatcher
	 */
	public function getRelatedChangeWatcher( User $user, Page $page ) {
		wfDebugLog( 'PeriodicRelatedChanges::Manager', __METHOD__ );
		return new RelatedChangeWatcher( $user, $page->getTitle() );
	}

	/**
	 * Given a list of individual changes, collect them into a batch
	 * FIXME: should replace this with some SQL queries
	 * @param LinkedRecentChanges $changes to collect
	 */
	public function collectChanges( ResultWrapper $changes ) {
		wfDebugLog( 'PeriodicRelatedChanges::Manager', __METHOD__ );
		foreach ( $changes as $change ) {
			$title = $change->rc_title;
			$user  = $change->rc_user_text;

			if ( !isset( $this->collectedChanges['page'][$title] ) ) {
				$this->collectedChanges['page'][$title]['editors'] = [];
			}

			if ( !isset( $this->collectedChanges['page'][$title][$user] ) ) {
				$this->collectedChanges['page'][$title]['editors'][$user] = 0;
			}

			if ( !isset( $this->collectedChanges['user'][$user] ) ) {
				$this->collectedChanges['user'][$user] = 0;
			}

			$this->collectedChanges['user'][$user]++;
			if (
				!isset( $this->collectedChanges['page'][$title]['oldestId'] ) ||
				$this->collectedChanges['page'][$title]['oldestId']
				> $change->rc_this_oldid
			) {
				$this->collectedChanges['page'][$title]['oldestId']
					= $change->rc_this_oldid;
			}
			$this->collectedChanges['page'][$title]['editors'][$user]++;
		}
	}

	/**
	 * Return an aggreate list of changes
	 * @param RelatedChangeWatcher $changes the list of individual
	 *        changes to aggregate
	 * @return Iterator
	 */
	public function getCollectedChanges( RelatedChangeWatcher $changes ) {
		wfDebugLog( 'PeriodicRelatedChanges::Manager', __METHOD__ );
		$this->collectChanges( $changes->getRelatedChanges() );
		return $this->collectedChanges;
	}

	/**
	 * Return the list of titles currently being watched by the user
	 * @param User $user we want info on
	 * @return Iterator
	 */
	public function getCurrentWatches( User $user ) {
		wfDebugLog( 'PeriodicRelatedChanges::Manager', __METHOD__ );
		return RelatedChangeWatchlist::newFromUser( $user );
	}

	/**
	 * Get a list of pages being watched with the people watching them.
	 * @return ResultWrapper
	 */
	public function getWatchGroups() {
		wfDebugLog( 'PeriodicRelatedChanges::Manager', __METHOD__ );
		return RelatedChangeWatcher::getWatchGroups();
	}

	/**
	 * Get a list of pages that are "related" to this one.
	 * @param Title $title we want info on
	 * @return Iterator
	 */
	public function getRelatedPages( Title $title ) {
		wfDebugLog( 'PeriodicRelatedChanges::Manager', __METHOD__ );
		return RelatedPageList::newFromTitle( $title );
	}

	/**
	 * Get a list of changes that are made on pages "related" to this one.
	 * @param Title $title we want info on
	 * @param string $startTime since this date
	 * @param string $style direction, to or from
	 * @return Iterator
	 */
	public function getRelatedChangesSince(
		Title $title, $startTime = 0, $style = "to"
	) {
		wfDebugLog( 'PeriodicRelatedChanges::Manager', __METHOD__ );
		$rpl = RelatedPageList::newFromTitle( $title );
		return $rpl->getRelatedChangesSince(
			$startTime, $style
		);
	}

	/**
	 * Get a list of users watching this page or relate pages.
	 * @param Title $title we want info on
	 * @return array
	 */
	public function getRelatedChangeWatchers( Title $title ) {
		wfDebugLog( 'PeriodicRelatedChanges::Manager', __METHOD__ );
		return RelatedChangeWatcher::getRelatedChangeWatchers( $title );
	}
}
