{strip}
<div class="display health">
	<div class="header hidden-print">
		<h1>{tr}BP Detail Report{/tr}</h1>
	</div>
	<div class="body">
		{include file="bitpackage:health/report_nav_inc.tpl"}

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
							<td>{foreach $r.bp_morning as $line}{$line|escape}<br />{/foreach}</td>
							<td>{foreach $r.bp_evening as $line}{$line|escape}<br />{/foreach}</td>
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
// Same convention as report_range.tpl's own copy.
function healthReportBumpTo( pFromVal ) {
	if ( !pFromVal ) return;
	var d = new Date( pFromVal + 'T00:00:00' );
	d.setDate( d.getDate() + 6 );
	document.getElementById( 'to' ).value = d.toISOString().slice( 0, 10 );
}

{if $autoPrint}
window.addEventListener( 'load', function() { window.print(); } );
{/if}
</script>
