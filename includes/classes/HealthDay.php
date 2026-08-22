<?php
/**
 * A single calendar day — the anchor content_id that Health's per-metric xref
 * items (WT/PULSE/OXI/BP/TEMP, see this package's own MANUAL.md) attach to.
 *
 * Stored as a pure liberty_content record (content_type_guid='healthday'), no
 * companion schema table — content_id is the only identifier. Title is always
 * the ISO date string ('YYYY-MM-DD'); importers go in via findOrCreate(), not
 * lookup() by content_id, since a date is all they ever know ahead of time.
 *
 * @package health
 */
namespace Bitweaver\Health;

use Bitweaver\Liberty\LibertyContent;

defined( 'HEALTHDAY_CONTENT_TYPE_GUID' ) || define( 'HEALTHDAY_CONTENT_TYPE_GUID', 'healthday' );

#[\AllowDynamicProperties]
class HealthDay extends LibertyContent {

	/**
	 * @param int|null $pDummy      Unused — LibertyBase::getNewObject() (the default
	 *                              factory behind getLibertyObject()/lookup()) always
	 *                              calls `new $class(null, $contentId)`, content_id in
	 *                              the second slot. This param exists purely to match
	 *                              that contract (see FoodComponent's own docblock).
	 * @param int|null $pContentId  liberty_content.content_id to load.
	 */
	public function __construct( $pDummy = null, $pContentId = null ) {
		parent::__construct();
		$this->mContentTypeGuid = HEALTHDAY_CONTENT_TYPE_GUID;
		$this->mContentId = (int)( $pContentId ?? $pDummy );

		$this->registerContentType(
			HEALTHDAY_CONTENT_TYPE_GUID, [
				'content_type_guid' => HEALTHDAY_CONTENT_TYPE_GUID,
				'content_name' => 'Day',
				'content_name_plural' => 'Days',
				'handler_class' => 'HealthDay',
				'handler_package' => 'health',
				'handler_file' => 'HealthDay.php',
				'maintainer_url' => 'https://www.bitweaver.org',
		], );

		$this->mViewContentPerm    = 'p_health_view';
		$this->mCreateContentPerm  = 'p_health_create';
		$this->mUpdateContentPerm  = 'p_health_update';
		$this->mAdminContentPerm   = 'p_health_admin';
		$this->mExpungeContentPerm = 'p_health_expunge';
	}

	/**
	 * @param  array $pLookupHash  Must contain 'content_id'.
	 * @return static|null         Loaded object, or null if not found.
	 */
	public static function lookup( $pLookupHash ) {
		$ret = null;
		$lookupContentId = null;
		if( !empty( $pLookupHash['content_id'] ) && is_numeric( $pLookupHash['content_id'] ) ) {
			$lookupContentId = (int)$pLookupHash['content_id'];
		}
		if( static::verifyId( $lookupContentId ) ) {
			$ret = static::getLibertyObject( $lookupContentId, HEALTHDAY_CONTENT_TYPE_GUID );
		}
		return $ret;
	}

	/**
	 * Find the content_id for a given calendar date, by title.
	 *
	 * @param  string $pDate  ISO date, 'YYYY-MM-DD'.
	 * @return int|null       content_id if a Day already exists for that date, else null.
	 */
	public static function lookupByDate( string $pDate ): ?int {
		global $gBitDb;
		$contentId = $gBitDb->getOne(
			"SELECT `content_id` FROM `".BIT_DB_PREFIX."liberty_content`
				WHERE `content_type_guid` = '".HEALTHDAY_CONTENT_TYPE_GUID."' AND `title` = ?",
			[ $pDate ]
		);
		return $contentId ? (int)$contentId : null;
	}

	/**
	 * Find the Day for a given date, creating it if it doesn't exist yet — the
	 * normal entry point for importers attaching a WT/PULSE/OXI/BP/TEMP xref row
	 * to "whatever day this reading falls on."
	 *
	 * @param  string $pDate  ISO date, 'YYYY-MM-DD'.
	 * @return static         Loaded (or newly-created) Day object.
	 */
	public static function findOrCreate( string $pDate ): self {
		$contentId = self::lookupByDate( $pDate );
		if( $contentId ) {
			$day = new static( null, $contentId );
			$day->load();
			return $day;
		}
		$day = new static();
		// event_time is a plain unix timestamp (liberty_content.event_time, BIGINT) —
		// needed for Calendar's own day-bucketing query (LibertyContent::getContentList()
		// sorts/filters by it); midday UTC avoids any BST/GMT edge-of-day ambiguity.
		[ $y, $m, $d ] = array_map( 'intval', explode( '-', $pDate ) );
		$store = [ 'title' => $pDate, 'event_time' => gmmktime( 12, 0, 0, $m, $d, $y ) ];
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

			$whereSql = " WHERE lc.`content_id` = ? AND lc.`content_type_guid` = '".HEALTHDAY_CONTENT_TYPE_GUID."'";
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
	 * Require a valid ISO date as the title before storing — the only thing a
	 * Day record actually needs.
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
			[ $this->mContentId, HEALTHDAY_CONTENT_TYPE_GUID ]
		);
	}

	/**
	 * Calendar day-grid cell content — see `LibertyContent::getContentList()`'s
	 * optional `getDayCellHtml()` dispatch. First-cut placeholder: plain
	 * min/max/count across the day's raw WT/BP/PULSE rows, not the considered
	 * "pick the real headline reading" day-summary logic still on the todo
	 * list (lowest-AM-weight-preferring-valid-scan for WT, etc.) — good enough
	 * to prove the calendar hook works against real imported data, not the
	 * final rollup. Returns '' (renders nothing, falls through to no tile) for
	 * a day with none of these three items at all.
	 *
	 * @param  array $pHash  The row from getContentList() — needs content_id/display_url.
	 * @return string
	 */
	public static function getDayCellHtml( array $pHash ): string {
		global $gBitDb;
		$contentId = (int)$pHash['content_id'];

		$wt = $gBitDb->getRow(
			"SELECT MIN(CAST(`xkey` AS DOUBLE PRECISION)) AS lo, MAX(CAST(`xkey` AS DOUBLE PRECISION)) AS hi, COUNT(*) AS n
				FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'WT'",
			[ $contentId ]
		);
		$bpCount = (int)$gBitDb->getOne(
			"SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'BP'",
			[ $contentId ]
		);
		$pulse = $gBitDb->getRow(
			"SELECT MIN(CAST(`xkey` AS DOUBLE PRECISION)) AS lo, MAX(CAST(`xkey` AS DOUBLE PRECISION)) AS hi
				FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'PULSE'",
			[ $contentId ]
		);

		$lines = [];
		if( !empty( $wt['n'] ) ) {
			$lines[] = $wt['lo'] == $wt['hi']
				? sprintf( '%.1fkg', $wt['lo'] )
				: sprintf( '%.1f–%.1fkg', $wt['lo'], $wt['hi'] );
		}
		if( $bpCount ) {
			$lines[] = $bpCount === 1 ? '1 BP' : "$bpCount BP";
		}
		if( !empty( $pulse['lo'] ) ) {
			$lines[] = sprintf( '%d–%d bpm', $pulse['lo'], $pulse['hi'] );
		}

		if( !$lines ) {
			return '';
		}

		$body = implode( '<br/>', array_map( 'htmlspecialchars', $lines ) );
		$url  = htmlspecialchars( $pHash['display_url'] ?? '#' );
		return "<div class=\"calhealthday\"><a href=\"$url\">$body</a></div>";
	}
}
