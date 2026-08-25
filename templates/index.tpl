{strip}
<div class="listing health">
	<header>
		<h1>{tr}Health{/tr}</h1>
	</header>

	<section class="body">
		<p>
			{if $dayCount}
				{$dayCount} {tr}days recorded,{/tr} {$dayMinDate|bit_short_date} &ndash; {$dayMaxDate|bit_short_date}.
			{else}
				{tr}No health data imported yet.{/tr}
			{/if}
			<a href="{$smarty.const.CALENDAR_PKG_URL}index.php?content_type_guid[]=healthday">{tr}View in Calendar{/tr}</a>
		</p>

		<form class="form-inline" method="get" action="{$smarty.const.HEALTH_PKG_URL}index.php">
			<div class="form-group">
				<label for="date">{tr}Go to day{/tr}</label>
				<input type="date" class="form-control input-sm" name="date" id="date" value="{$dateNotFound|escape}" />
			</div>
			<button type="submit" class="btn btn-default btn-sm">{tr}Go{/tr}</button>
			{if $dateNotFound}
				<span class="label label-warning">{tr}No data recorded for{/tr} {$dateNotFound|escape}.</span>
			{/if}
		</form>

		{if $healthForYouRows}
			<h2>{tr}HealthForYou{/tr}{if $healthForYouLast} &mdash; {tr}Last Download{/tr}: {$healthForYouLast|bit_short_date}{/if}</h2>
			<table class="table table-striped table-hover">
				<thead>
					<tr>
						<th>{tr}Record type{/tr}</th>
						<th>{tr}Count{/tr}</th>
						<th>{tr}Period covered{/tr}</th>
					</tr>
				</thead>
				<tbody>
					{foreach $healthForYouRows as $row}
						<tr>
							<td>{$row.title|escape}</td>
							<td>{$row.count}</td>
							<td>{if $row.count}{$row.min_date|bit_short_date} &ndash; {$row.max_date|bit_short_date}{/if}</td>
						</tr>
					{/foreach}
				</tbody>
			</table>
		{/if}

		{if $samsungRows}
			<h2>{tr}Samsung Health{/tr}{if $samsungLast} &mdash; {tr}Last Download{/tr}: {$samsungLast|bit_short_date}{/if}</h2>
			<table class="table table-striped table-hover">
				<thead>
					<tr>
						<th>{tr}Record type{/tr}</th>
						<th>{tr}Count{/tr}</th>
						<th>{tr}Period covered{/tr}</th>
					</tr>
				</thead>
				<tbody>
					{foreach $samsungRows as $row}
						<tr>
							<td>{$row.title|escape}</td>
							<td>{$row.count}</td>
							<td>{if $row.count}{$row.min_date|bit_short_date} &ndash; {$row.max_date|bit_short_date}{/if}</td>
						</tr>
					{/foreach}
				</tbody>
			</table>
		{/if}
	</section>
</div>
{/strip}
