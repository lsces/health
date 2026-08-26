{strip}
<div class="display health">
	<div class="header hidden-print">
		<h1>{tr}Health Report{/tr}</h1>
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
{if $autoPrint}
// Reached via index.php's Reports list Print button (?print=1) — fire the print dialog as soon
// as the page has actually rendered, so it feels like one click from the General tab.
window.addEventListener( 'load', function() { window.print(); } );
{/if}
</script>
