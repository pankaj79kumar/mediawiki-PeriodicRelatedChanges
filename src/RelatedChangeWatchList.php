<?php

/*
 * Copyright (C) 2016  Mark A. Hershberger
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
 */

namespace PeriodicRelatedChanges;

use IDatabase;
use ResultWrapper;
use User;
use WikiPage;

class RelatedChangeWatchList extends ResultWrapper {
	protected $user;

	/**
	 * A constructor!
	 *
	 * @param IDatabase|null $dbh optional
	 * @param ResultWrapper $res a DB result.
	 */
	public function __construct( IDatabase $dbh = null, ResultWrapper $res ) {
		parent::__construct( $dbh, $res );
	}

	/**
	 * Return an iterable list of watches for a user
	 *
	 * @param User $user whose related watches to fetch.
	 * @return RelatedChangeWatchList
	 */
	public static function newFromUser( User $user ) {
		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->select( 'periodic_related_change',
							 [ 'wc_page', 'wc_timestamp' ],
							 [ 'wc_user' => $user->getId() ],
							 __METHOD__ );

		return new self( $dbr, $res );
	}

	/**
	 * Returns an array of the current result
	 *
	 * @return array|boolean
	 */
	public function current() {
		$cur = parent::current();
		$res = false;
		if ( $cur !== false ) {
			$res = [ 'page' => WikiPage::newFromID( $cur->wc_page ),
					 'timestamp' => $cur->wc_timestamp ];
		}
		return $res;
	}

	/**
	 * Get a list of changes for a page
	 *
	 * @param WikiPage $page to check
	 * @param int $startTime to check
	 * @param string $style which direction
	 * @return array
	 */
	public function getChangesFor( WikiPage $page, $startTime = 0, $style = "to" ) {
		global $wgHooks;
		$oldHooks = [];
		if ( isset( $wgHooks['ChangesListSpecialPageQuery'] ) ) {
			$oldHooks = $wgHooks['ChangesListSpecialPageQuery'];
		}
		$rcl = new MySpecialRelatedChanges( $page->getTitle()->getText(), $startTime );
		$rcl->linkedTo( false );
		if ( $style === "to" ) {
			$rcl->linkedTo( true );
		}
		$rows = $rcl->getRows();

		if ( count( $oldHooks ) == 0 ) {
			unset( $wgHooks['ChangesListSpecialPageQuery'] );
		} else {
			$wgHooks['ChangesListSpecialPageQuery'] = $oldHooks;
		}

		if ( $rows === false ) {
			return [];
		}

		return $this->collateChanges( $rows );
	}

	/**
	 * Gather the changes per-page.
	 * @param Iterator $watches list of page watches
	 * @return array contains report on pages.
	 */
	protected function collateChanges( \Iterator $watches ) {
		$change = [];

		foreach ( $watches as $watch ) {
			$title = $watch->rc_title;
			$rev = $watch->rc_id;

			if ( !isset( $change[$title] ) ) {
				$change[$title] = [ 'old' => $watch->rc_last_oldid,
									'new' => $watch->rc_this_oldid,
									'ts'  => [ $watch->rc_timestamp ] ];
				continue;
			}
			$change[$title]['ts'][] = $watch->rc_timestamp;
			if ( $change[$title]['old'] > $watch->rc_last_oldid ) {
				$change[$title]['old'] = $watch->rc_last_oldid;
			}
			if ( $change[$title]['new'] < $watch->rc_this_oldid ) {
				$change[$title]['new'] = $watch->rc_this_oldid;
			}
		}

		return $change;
	}

	/**
	 * Send an email with the relevant pages to the user.
	 * @param User $user Optionally specify the user to send this to
	 */
	public function sendEmail( User $user = null ) {
		if ( $this->user === null && $user === null ) {
			throw new MWException( "No user to send to." );
		}
		if ( $user === null ) {
			$user = $this->user;
		}
		$to = $user->getEmail();
	}
}