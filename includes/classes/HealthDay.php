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

use Bitweaver\KernelTools;
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
	 * optional `getDayCellHtml()` dispatch.
	 *
	 * WT uses `healthDaySummaryWT()`'s already-considered headline reading
	 * (lowest AM weight, preferring one with a real body-comp scan) instead of
	 * a plain min/max range across every reading that day — a raw range could
	 * span normal daily fluctuation or multiple same-session re-weighs, not a
	 * meaningful "today's weight" figure.
	 *
	 * BP shows the day's real sys/dia(/pulse) range - "138/85 (68)" for a
	 * single reading, "125–140/78–92 (60–75)" once there's more than one -
	 * via healthDaySummaryBP()/healthFormatBPLine(), not a bare reading
	 * count (fixed 2026-08-24, see HealthDaySummary.php's own docblock for
	 * the slot breakdown this shares its formatting with). A day with zero
	 * BP readings still gets a "No BP records" line, not an omitted row -
	 * the author's own call, so every rendered tile keeps the same line count/
	 * layout rather than BP-free days looking shorter (fixed same day).
	 *
	 * Pulse range comes from RAISEDHR's own cached `hr_min`/`hr_max` (see
	 * RebuildHRDerived.php's healthRebuildDayRaisedHR(), one row per day) —
	 * not a PULSE scan. First fix here read PULSE's own `xkey_ext` per slot
	 * (that slot's real min/max, not `xkey`'s average — the *original* bug,
	 * ranging over slot averages, which is always narrower than the day's
	 * real min/max since a brief spike gets smoothed into its half-hour
	 * average first, and is why it never matched the phone's own figures).
	 * Correct, but redundant to compute fresh on every render: RAISEDHR
	 * already has the same day's true min/max sitting in one row, cached at
	 * rebuild time, cheaper to read than decoding every PULSE slot's json
	 * again for the same figure. Both fixed 2026-08-23.
	 *
	 * Steps is the bottom line, "Step: 8,321, 45m, 320K" via the compact
	 * healthFormatStepsLineCompact() - deliberately not the same, fuller
	 * line the Summary tab shows (that one keeps Activity and field-name
	 * prefixes; this one dropped them, 2026-08-24, to fit one line here).
	 *
	 * Returns '' (renders nothing, falls through to no tile) for a day with
	 * none of WT/BP/RAISEDHR/Steps at all.
	 *
	 * @param  array $pHash  The row from getContentList() — needs content_id/display_url.
	 * @return string
	 */
	public static function getDayCellHtml( array $pHash ): string {
		global $gBitDb;
		$contentId = (int)$pHash['content_id'];

		require_once __DIR__.'/../HealthDaySummary.php';

		$raisedHrData = $gBitDb->getOne(
			"SELECT `data` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'RAISEDHR'",
			[ $contentId ]
		);

		$wt       = healthDaySummaryWT( $contentId );
		$bp       = healthDaySummaryBP( $contentId );
		$raisedHr = $raisedHrData ? json_decode( (string)$raisedHrData, true ) : null;
		$hasRaisedHr = is_array( $raisedHr ) && isset( $raisedHr['hr_min'], $raisedHr['hr_max'] );

		// Steps: compact "Step: 8,321, 45m, 320K" bottom line (no Activity,
		// no field-name prefixes on Mins/Kcal - the author's own trim, 2026-08-24,
		// specifically to keep it to one line on the cell; see
		// healthFormatStepsLineCompact()'s own docblock). Its presence also
		// counts towards whether the tile renders at all - a step-only day
		// (no WT/BP/RAISEDHR) shouldn't be hidden just because none of the
		// *other* three have data.
		$steps     = healthDaySummarySteps( $contentId );
		$stepsLine = healthFormatStepsLineCompact( $steps );

		if( !$wt && !$bp && !$hasRaisedHr && !$stepsLine ) {
			return '';
		}

		$lines = [];

		if( $wt ) {
			$lines[] = sprintf( '%.1fkg', $wt['weight'] );
		}

		// Always a BP line once the tile is rendering at all - "No BP records"
		// rather than just omitting the row, so every tile keeps the same
		// line count/layout instead of shorter tiles on BP-free days. Only a
		// completely empty day (no WT/BP/RAISEDHR at all) skips the tile
		// entirely, per the early return above.
		$lines[] = $bp
			? healthFormatBPLine(
				$bp['systolic']['min'], $bp['systolic']['max'],
				$bp['diastolic']['min'], $bp['diastolic']['max'],
				$bp['pulse']['min'] ?? null, $bp['pulse']['max'] ?? null
			)
			: KernelTools::tra( 'No BP records' );

		if( $hasRaisedHr ) {
			$lines[] = sprintf( '%d–%d bpm', $raisedHr['hr_min'], $raisedHr['hr_max'] );
		}

		if( $stepsLine ) {
			$lines[] = $stepsLine;
		}

		$body = implode( '<br/>', array_map( 'htmlspecialchars', $lines ) );
		$url  = htmlspecialchars( $pHash['display_url'] ?? '#' );
		return "<div class=\"calhealthday\"><a href=\"$url\">$body</a></div>";
	}

	/**
	 * view_day.php — generic per-item xref browser for one day. Overridden
	 * here (LibertyContent's own default just routes through the bare kernel
	 * content_id router) so the calendar day-cell built in getDayCellHtml()
	 * actually has somewhere real to land, rather than falling through to
	 * wherever an unhandled content_type_guid ends up. Deliberately not the
	 * curated day-summary view HealthDaySummary.php is meant for eventually —
	 * see view_day.php's own docblock.
	 */
	public static function getDisplayUrlFromHash( &$pParamHash ) {
		$ret = null;
		if( static::verifyId( $pParamHash['content_id'] ?? null ) ) {
			$ret = HEALTH_PKG_URL.'view_day.php?content_id='.$pParamHash['content_id'];
		}
		return $ret;
	}

	public function getDisplayUrl(): string {
		return static::getDisplayUrlFromHash( $this->mInfo );
	}

	/**
	 * Per-item column titles for xkey/xkey_ext/data — these are raw storage
	 * columns with generic names, not self-describing on their own. Shared by
	 * list_item.php (one item across every day) and view_day.php (every item
	 * for one day) so the two displays can't drift apart. Falls back to the
	 * raw column name for any item not listed here (e.g. a newly added one
	 * not yet given real labels).
	 *
	 * @return array<string, array{0:string,1:string,2:string}>
	 */
	public static function getItemColumnTitles(): array {
		return [
			'WT'        => [ 'Weight (kg)',      'BMI',             'Body Composition' ],
			'BP'        => [ 'Systolic',          'Diastolic',       'Detail' ],
			'PULSE'     => [ 'Average',           'Low/High',        'Minute Detail' ],
			'OXI'       => [ 'SpO2 Average',      'Pulse',           'SpO2 Min/Max' ],
			'TEMP'      => [ 'Temperature (°C)',  'Mode',            '' ],
			'STEPS'     => [ 'Steps',             'Active Mins',     'Active Kcal' ],
			'ENERGY'    => [ 'Energy',            'HRV',             'Detail' ],
			'SLEEP'     => [ 'Sleep Score',       'Duration (mins)', 'Efficiency' ],
			'RESP'      => [ 'Average',           'Low/High',        'Minute Detail' ],
			'STEMP'     => [ 'Average (°C)',      'Low/High',        'Minute Detail' ],
			'HRV'       => [ 'SDNN',              'RMSSD',           'Slot Detail' ],
			'STEPTRACK' => [ 'Total Steps',       'Peak (10 min)',   'Day Track' ],
			'RAISEDHR'  => [ 'Mins >=90bpm',      'Mins >=100bpm',   'Day Detail' ],
			'EXERCISE'  => [ 'Type',              'Duration (mins)', 'Detail' ],
		];
	}
}
