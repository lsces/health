{strip}
<div class="display health">
	<div class="header hidden-print">
		<h1>{tr}Health Report{/tr}</h1>
	</div>
	<div class="body">
		<form method="get" action="{$smarty.const.HEALTH_PKG_URL}report_range.php" class="form-inline hidden-print">
			<div class="form-group">
				<label for="from">{tr}From{/tr}</label>
				<input type="date" class="form-control input-sm" name="from" id="from" value="{$from|escape}" />
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
