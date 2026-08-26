<?php
/**
 * A single calendar day, food's own version of HealthDay — the day-level anchor
 * that makes a whole day's food selectable on the calendar as one summary tile
 * (kcal/fibre/5AD totals), instead of every FoodAssembly meal cluttering month/
 * week views as its own separate cell. Lives in the health package deliberately
 * (Lester's own call, 2026-08-26) - reuses HealthDay's own proven one-row-per-
 * day pattern rather than food inventing a parallel "day" concept, and sets up
 * combining food's daily totals with health's own (exercise etc) later.
 *
 * Stored as a pure liberty_content record (content_type_guid='foodday'), no
 * companion schema table, title always the ISO date string - same conventions
 * as HealthDay. Deliberately owns none of the actual meal data - a FoodDay row
 * is just a calendar marker; getDayCellHtml() below computes that day's real
 * totals live from FoodAssembly each time, exactly like HealthDay's own WT/BP
 * tile is computed live from other xrefs rather than stored on itself.
 *
 * Row creation is a one-time backfill against *existing* HealthDay rows (see
 * THOUGHTS.txt/session history for 2026-08-26) - confirmed empirically that
 * every day with food data already has a matching HealthDay row, so there is
 * no live creation-trigger here, no findOrCreate() called from anywhere but
 * that one-off backfill.
 *
 * @package health
 */
namespace Bitweaver\Health;

use Bitweaver\KernelTools;
use Bitweaver\Liberty\LibertyContent;
use Bitweaver\Food\FoodComponent;

defined( 'FOODDAY_CONTENT_TYPE_GUID' ) || define( 'FOODDAY_CONTENT_TYPE_GUID', 'foodday' );

#[\AllowDynamicProperties]
class FoodDay extends LibertyContent {

	/**
	 * @param int|null $pDummy      Unused — LibertyBase::getNewObject() (the default
	 *                              factory behind getLibertyObject()/lookup()) always
	 *                              calls `new $class(null, $contentId)`, content_id in
	 *                              the second slot. This param exists purely to match
	 *                              that contract (see HealthDay's own docblock).
	 * @param int|null $pContentId  liberty_content.content_id to load.
	 */
	public function __construct( $pDummy = null, $pContentId = null ) {
		parent::__construct();
		$this->mContentTypeGuid = FOODDAY_CONTENT_TYPE_GUID;
		$this->mContentId = (int)( $pContentId ?? $pDummy );

		$this->registerContentType(
			FOODDAY_CONTENT_TYPE_GUID, [
				'content_type_guid' => FOODDAY_CONTENT_TYPE_GUID,
				'content_name' => 'Food Day',
				'content_name_plural' => 'Food Days',
				'handler_class' => 'FoodDay',
				'handler_package' => 'health',
				'handler_file' => 'FoodDay.php',
				'maintainer_url' => 'https://www.bitweaver.org',
		], );

		// Food's own permissions, not health's - a FoodDay tile is a view onto food
		// data, gated the same way FoodAssembly itself is.
		$this->mViewContentPerm    = 'p_food_view';
		$this->mCreateContentPerm  = 'p_food_create';
		$this->mUpdateContentPerm  = 'p_food_update';
		$this->mAdminContentPerm   = 'p_food_admin';
		$this->mExpungeContentPerm = 'p_food_expunge';
	}

	/**
	 * @param  string $pDate  ISO date, 'YYYY-MM-DD'.
	 * @return int|null  content_id if found, else null.
	 */
	public static function lookupByDate( string $pDate ): ?int {
		global $gBitDb;
		$contentId = $gBitDb->getOne(
			"SELECT `content_id` FROM `".BIT_DB_PREFIX."liberty_content`
				WHERE `content_type_guid` = '".FOODDAY_CONTENT_TYPE_GUID."' AND `title` = ?",
			[ $pDate ]
		);
		return $contentId ? (int)$contentId : null;
	}

	/**
	 * Find the FoodDay for a given date, creating it if it doesn't exist yet - used
	 * only by the one-off backfill script (see this class's own docblock), not a
	 * live per-request creation path.
	 *
	 * @param  string $pDate  ISO date, 'YYYY-MM-DD'.
	 * @return self
	 */
	public static function findOrCreate( string $pDate ): self {
		$contentId = self::lookupByDate( $pDate );
		if( $contentId ) {
			$day = new static( null, $contentId );
			$day->load();
			return $day;
		}
		$day = new static();
		// event_time is a plain unix timestamp (liberty_content.event_time, BIGINT) -
		// needed for Calendar's own day-bucketing query. Deliberately NOT HealthDay's
		// own midday-UTC convention - a FoodDay tile needs to sort *before* every real
		// meal on the same day (now that event_time sort actually works, see
		// project_liberty_event_time_sort_bug memory), so it renders as a header/
		// summary at the top of the day rather than stuck in the middle of the meal
		// list. Local midnight, converted properly via BitDate (not naive gmmktime)
		// so it stays correctly "before everything" across the BST/GMT boundary too.
		global $gBitSystem;
		$gBitDate = $gBitSystem->mServerTimestamp;
		$localMidnight = strtotime( $pDate.' 00:00:00' );
		$store = [ 'title' => $pDate, 'event_time' => $gBitDate->getUTCFromDisplayDate( $localMidnight ) ];
		$day->store( $store );
		$day->load();
		return $day;
	}

	/**
	 * Load day data into $this->mInfo from liberty_content.
	 *
	 * @return int|null  Row count (> 0) on success, or null if mContentId is not set.
	 */
	public function load() {
		if( $this->verifyId( $this->mContentId ) ) {
			$selectSql = $joinSql = $whereSql = '';
			$bindVars = [];

			$whereSql = " WHERE lc.`content_id` = ? AND lc.`content_type_guid` = '".FOODDAY_CONTENT_TYPE_GUID."'";
			$bindVars[] = $this->mContentId;

			$this->getServicesSql( 'content_load_sql_function', $selectSql, $joinSql, $whereSql, $bindVars, $this );

			$sql = "SELECT lc.* $selectSql
						, uue.`login` AS `modifier_user`, uue.`real_name` AS `modifier_real_name`
						, uuc.`login` AS `creator_user`, uuc.`real_name` AS `creator_real_name`
					FROM `".BIT_DB_PREFIX."liberty_content` lc
						LEFT JOIN `".BIT_DB_PREFIX."users_users` uue ON (uue.`user_id` = lc.`modifier_user_id`)
						LEFT JOIN `".BIT_DB_PREFIX."users_users` uuc ON (uuc.`user_id` = lc.`user_id`) $joinSql
					$whereSql";
			if( $this->mInfo = $this->mDb->getRow( $sql, $bindVars ) ) {
				$this->mContentId       = $this->mInfo['content_id'];
				$this->mContentTypeGuid = $this->mInfo['content_type_guid'];
				$this->mInfo['creator'] = $this->mInfo['creator_real_name'] ?? $this->mInfo['creator_user'];
				$this->mInfo['editor']  = $this->mInfo['modifier_real_name'] ?? $this->mInfo['modifier_user'];
				LibertyContent::load();
			}
			return count( $this->mInfo );
		}
		return null;
	}

	/**
	 * Require a valid ISO date as the title before storing - the only thing a
	 * FoodDay record actually needs.
	 *
	 * @param  array $pParamHash
	 * @return bool
	 */
	public function verifyDayData( array &$pParamHash ): bool {
		if( empty( $pParamHash['title'] ) || !preg_match( '/^\d{4}-\d{2}-\d{2}$/', $pParamHash['title'] ) ) {
			$this->mErrors['title'] = 'A valid ISO date (YYYY-MM-DD) is required.';
		}
		return count( $this->mErrors ) == 0;
	}

	/**
	 * Persist day data inside a transaction via LibertyContent::store().
	 *
	 * @param  array $pParamHash  Data to persist; modified in place.
	 * @return bool
	 */
	public function store( array &$pParamHash ): bool {
		if( $this->verifyDayData( $pParamHash ) ) {
			$this->StartTrans();
			if( LibertyContent::store( $pParamHash ) ) {
				$this->mContentId          = $pParamHash['content_id'];
				$this->mInfo['content_id'] = $this->mContentId;
				$this->CompleteTrans();
			} else {
				$this->mDb->RollbackTrans();
			}
		}
		return count( $this->mErrors ) == 0;
	}

	/**
	 * @return bool TRUE when mContentId refers to a real liberty_content row of
	 *              this content type — not just an id that looks syntactically valid.
	 */
	public function isValid() {
		if( !@$this->verifyId( $this->mContentId ) ) {
			return false;
		}
		return (bool)$this->mDb->getOne(
			"SELECT 1 FROM `".BIT_DB_PREFIX."liberty_content` WHERE `content_id` = ? AND `content_type_guid` = ?",
			[ $this->mContentId, FOODDAY_CONTENT_TYPE_GUID ]
		);
	}

	/**
	 * Calendar day-grid cell content — see LibertyContent::getContentList()'s
	 * optional getDayCellHtml() dispatch (same hook FoodAssembly's own per-meal
	 * tile and HealthDay's own WT/BP tile use). Computes the day's real kcal/
	 * fibre/5AD totals live by summing every FoodAssembly meal on this date -
	 * nothing stored on the FoodDay row itself, same "compute fresh, store
	 * nothing" pattern as HealthDay's own tile.
	 *
	 * @param  array $pHash  Row from getContentList() — title (ISO date) and
	 *                       display_url are what's used here.
	 * @return string  Empty if this date has no food logged at all (shouldn't
	 *                 happen given the backfill only creates rows for dates that
	 *                 already have food data, but matches every other type's
	 *                 own empty-tile convention).
	 */
	public static function getDayCellHtml( array $pHash ): string {
		global $gBitDb, $gBitSystem;
		$gBitDate = $gBitSystem->mServerTimestamp;

		$isoDate = $pHash['title'] ?? '';
		if( !preg_match( '/^\d{4}-\d{2}-\d{2}$/', $isoDate ) ) {
			return '';
		}

		$localDayStart = strtotime( $isoDate.' 00:00:00' );
		$utcDayStart = $gBitDate->getUTCFromDisplayDate( $localDayStart );
		$utcDayEnd   = $gBitDate->getUTCFromDisplayDate( $localDayStart + 86400 );

		$assemblyIds = $gBitDb->getCol(
			"SELECT `content_id` FROM `".BIT_DB_PREFIX."liberty_content`
				WHERE `content_type_guid` = 'foodassembly' AND `event_time` >= ? AND `event_time` < ?",
			[ $utcDayStart, $utcDayEnd ]
		);
		if( !$assemblyIds ) {
			return '';
		}

		$totalRaw = array_fill_keys( array_keys( FoodComponent::NUTRITION_SUMMARY_FIELDS ), 0.0 );
		foreach( $assemblyIds as $assemblyId ) {
			$assembly = new \Bitweaver\Food\FoodAssembly( null, (int)$assemblyId );
			$data = $assembly->getItemsWithNutrition();
			$totalRaw = FoodComponent::sumNutrition( $totalRaw, $data['totalRaw'] );
		}
		$totals = FoodComponent::formatNutrition( $totalRaw );

		$body = htmlspecialchars( 'Day: '.$totals['CAL'].' · '.$totals['FIBR'].' fibre · '.$totals['5AD'].' 5AD' );
		$url  = htmlspecialchars( $pHash['display_url'] ?? '#' );
		return "<div class=\"calfoodday\"><a href=\"$url\">$body</a></div>";
	}

	/**
	 * Overrides LibertyContent's default 'edit.php' fallback - Health has more
	 * than one content type, same reason HealthDay/FoodAssembly/FoodComponent
	 * all override this. Links into food's own day view (calendar's own, not a
	 * food-package page - food has no view_day.php of its own).
	 */
	public static function getDisplayUrlFromHash( &$pParamHash ) {
		$ret = null;
		if( static::verifyId( $pParamHash['content_id'] ?? null ) && !empty( $pParamHash['title'] ) ) {
			$ret = CALENDAR_PKG_URL.'package_page.php?pkg=food&view_mode=day&todate='.$pParamHash['title'];
		}
		return $ret;
	}

	public function getDisplayUrl(): string {
		$hash = [ 'content_id' => $this->mContentId, 'title' => $this->getTitle() ];
		return static::getDisplayUrlFromHash( $hash ) ?? '';
	}
}
