{strip}
<div class="display health">
	<div class="header hidden-print">
		<h1>{tr}Monthly Health Report{/tr}</h1>
	</div>
	<div class="body">
		{include file="bitpackage:health/report_nav_inc.tpl" bumpDays=365}

		<h2>{$from|bit_short_date} &ndash; {$to|bit_short_date}</h2>

		{if $rows}
			<h3>{tr}Pulse and HRV{/tr}</h3>
			<table class="table table-condensed table-bordered">
				<thead>
					<tr>
						<th>{tr}Month{/tr}</th>
						<th>{tr}Days with watch data{/tr}</th>
						<th>{tr}Days at {$floorBpm} bpm floor{/tr}</th>
						<th>{tr}% of days{/tr}</th>
						<th>{tr}Half-hour slots at floor{/tr}</th>
						<th>{tr}Pulse avg{/tr}</th>
						<th>{tr}Night pulse avg{/tr}</th>
						<th>{tr}Mean daily peak{/tr}</th>
						<th>{tr}Highest peak{/tr}</th>
						<th>{tr}Days reaching 130{/tr}</th>
						<th>{tr}Mins/day at 100+{/tr}</th>
						<th>{tr}HRV SDNN (median){/tr}</th>
					</tr>
				</thead>
				<tbody>
					{foreach $rows as $r}
						<tr>
							<td>{$r.month|escape}</td>
							<td>{$r.days|escape}</td>
							<td>{$r.floor_days|escape}</td>
							<td>{$r.floor_pct|escape}</td>
							<td>{$r.floor_slots|escape}</td>
							<td>{$r.pulse|escape}</td>
							<td>{$r.night|escape}</td>
							<td>{$r.peak|escape}</td>
							<td>{$r.peak_max|escape}</td>
							<td>{$r.days130|escape}</td>
							<td>{$r.mins100|escape}</td>
							<td>{$r.sdnn|escape}</td>
						</tr>
					{/foreach}
				</tbody>
			</table>

			<h3>{tr}Blood pressure, overnight SpO2, weight and steps{/tr}</h3>
			<table class="table table-condensed table-bordered">
				<thead>
					<tr>
						<th>{tr}Month{/tr}</th>
						<th>{tr}Cuff readings{/tr}</th>
						<th>{tr}Cuff BP avg{/tr}</th>
						<th>{tr}Cuff pulse avg{/tr}</th>
						<th>{tr}Nights with SpO2 (all sessions combined){/tr}</th>
						<th>{tr}Nights with a break of 20+ mins{/tr}</th>
						<th>{tr}Mean break (mins){/tr}</th>
						<th>{tr}Mins under 90% per night{/tr}</th>
						<th>{tr}Mean lowest SpO2{/tr}</th>
						<th>{tr}Mean mins measured{/tr}</th>
						<th>{tr}% of measured time under 90%{/tr}</th>
						<th>{tr}Weight (kg){/tr}</th>
						<th>{tr}Steps/day{/tr}</th>
					</tr>
				</thead>
				<tbody>
					{foreach $rows as $r}
						<tr>
							<td>{$r.month|escape}</td>
							<td>{$r.cuff_n|escape}</td>
							<td>{$r.cuff_bp|escape}</td>
							<td>{$r.cuff_pulse|escape}</td>
							<td>{$r.desat_n|escape}</td>
							<td>{$r.gap_nights|escape}</td>
							<td>{$r.gap_mean|escape}</td>
							<td>{$r.desat_90|escape}</td>
							<td>{$r.desat_low|escape}</td>
							<td>{$r.desat_cov|escape}</td>
							<td>{$r.desat_pct|escape}</td>
							<td>{$r.weight|escape}</td>
							<td>{$r.steps|escape}</td>
						</tr>
					{/foreach}
				</tbody>
			</table>

			<p><small>
				{tr}Floor day: any half-hour slot where the watch's lowest reading was {$floorBpm} bpm or below &mdash; it cannot measure lower, so this means &ldquo;{$floorBpm} or less&rdquo;. Only days with at least {$minSlots} recorded slots are counted, so a day the watch was mostly off the wrist doesn't count as a day without a floor.{/tr}
				{tr}Night pulse: slots between midnight and 06:00. Daily peak: the highest pulse reached that day. HRV is the median of the half-hour SDNN values; an irregular rhythm inflates it, so read it as a direction, not a level.{/tr}
				{tr}Nights with a break: the watch recorded the night as two or more sleep sessions with at least 20 minutes between them, starting overnight (23:00&ndash;07:00). It marks a night that may have been broken, not a measure of sleep; the watch&rsquo;s own awake and sleep-score figures are deliberately left out. Floor slots count every half-hour slot at the floor, which almost all fall between 22:00 and 07:00.{/tr}
				{tr}Cuff BP is cuff readings only. Overnight SpO2 counts only the time the watch actually measured: with low &ldquo;mins measured&rdquo; the minutes under 90% understate the night, so compare months using the percentage of measured time, not the raw minutes.{/tr}
			</small></p>
		{else}
			<p>{tr}No health data recorded in this date range.{/tr}</p>
		{/if}
	</div>
</div>
{/strip}
<script>
{if $autoPrint}
// Reached via index.php's Reports list Print button (?print=1) — fire the print dialog as soon
// as the page has actually rendered.
window.addEventListener( 'load', function() { window.print(); } );
{/if}
</script>
