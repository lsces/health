{strip}
<div class="display health">
	<div class="header hidden-print">
		<h1>{tr}Health Report{/tr}</h1>
	</div>
	<div class="body">
		<form method="get" action="{$smarty.const.HEALTH_PKG_URL}report_range.php" class="form-inline hidden-print">
			<div class="form-group">
				<label for="from">{tr}From{/tr}</label>
				<input type="date" class="form-control input-sm" name="from" id="from" value="{$from|escape}" onchange="healthReportBumpTo(this.value)" />
			</div>
			<div class="form-group">
				<label for="to">{tr}To{/tr}</label>
				<input type="date" class="form-control input-sm" name="to" id="to" value="{$to|escape}" />
			</div>
			<button type="submit" class="btn btn-default btn-sm">{tr}Update{/tr}</button>
			<button type="button" class="btn btn-default btn-sm" onclick="window.print()">{tr}Print{/tr}</button>
		</form>

		<h2>{$from|bit_short_date} &ndash; {$to|bit_short_date}</h2>

		{if $rangeBP}
			<p><strong>{tr}Average BP for this period{/tr}:</strong>
				{$rangeBP.systolic}/{$rangeBP.diastolic}{if $rangeBP.pulse} ({$rangeBP.pulse}){/if}
				&mdash; {$rangeBP.count} {if $rangeBP.count eq 1}{tr}reading{/tr}{else}{tr}readings{/tr}{/if}
			</p>
		{/if}

		{if $rows}
			<table class="table table-condensed table-bordered">
				<thead>
					<tr>
						<th>{tr}Date{/tr}</th>
						<th>{tr}Weight (kg){/tr}</th>
						<th>{tr}Pulse Avg{/tr}</th>
						<th>{tr}Pulse Range{/tr}</th>
						<th>{tr}BP Morning{/tr}</th>
						<th>{tr}BP Evening{/tr}</th>
						<th>{tr}HRV{/tr}</th>
					</tr>
				</thead>
				<tbody>
					{foreach $rows as $r}
						<tr>
							<td>{$r.date|bit_short_date}</td>
							<td>{$r.weight|escape}</td>
							<td>{$r.pulse_avg|escape}</td>
							<td>{$r.pulse_range|escape}</td>
							<td>{$r.bp_morning|escape}</td>
							<td>{$r.bp_evening|escape}</td>
							<td>{$r.hrv|escape}</td>
						</tr>
					{/foreach}
				</tbody>
			</table>
		{else}
			<p>{tr}No health data recorded in this date range.{/tr}</p>
		{/if}
	</div>
</div>
{/strip}
<script>
// Changing From bumps To to 6 days later (a 7-day span, matching the
// page's own default) - always overwrites To, on the assumption that
// picking a new From means "start a new week from here", not "keep
// whatever To already had". Plain Date math on the YYYY-MM-DD value an
// <input type="date"> already gives/wants, no library needed.
function healthReportBumpTo( pFromVal ) {
	if ( !pFromVal ) return;
	var d = new Date( pFromVal + 'T00:00:00' );
	d.setDate( d.getDate() + 6 );
	document.getElementById( 'to' ).value = d.toISOString().slice( 0, 10 );
}
</script>
