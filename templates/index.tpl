{strip}
<div class="listing health">
	<header>
		<h1>{tr}Health{/tr}</h1>
	</header>

	<section class="body">
		{jstabs}
			{jstab title="General"}
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

				<h3>{tr}Reports{/tr}</h3>
				<form method="get">
					<div class="bitnav">
						<ul class="pagination">
							<li class="bitnav-picker">
								<label for="report_from">{tr}From{/tr}</label>
								<input type="date" name="from" id="report_from" value="{$reportFrom|escape}" onchange="healthReportBumpTo(this.value)" />
								<label for="report_to">{tr}To{/tr}</label>
								<input type="date" name="to" id="report_to" value="{$reportTo|escape}" />
							</li>
						</ul>
					</div>

					<table class="table table-condensed">
						<tbody>
							{foreach $healthReports as $report}
								<tr>
									<td>{$report.title|escape}</td>
									<td>
										<button type="submit" formaction="{$report.url}" class="btn btn-default btn-xs">{tr}View{/tr}</button>
										<button type="submit" formaction="{$report.url}" name="print" value="1" class="btn btn-default btn-xs">{tr}Print{/tr}</button>
									</td>
								</tr>
							{/foreach}
						</tbody>
					</table>
				</form>
			{/jstab}

			{jstab title="HealthForYou"}
				<p>
					{if $healthForYouLast}{tr}Last Download{/tr}: {$healthForYouLast|bit_short_date}{/if}
					<a class="btn btn-default btn-xs" href="{$smarty.const.HEALTH_PKG_URL}import/load_healthforyou.php">{tr}Upload Export{/tr}</a>
				</p>

				{if $healthForYouRows}
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

				{if $healthForYouUploads}
					<h3>{tr}Uploaded exports{/tr}</h3>
					<table class="table table-condensed">
						<thead>
							<tr>
								<th>{tr}File{/tr}</th>
								<th>{tr}Uploaded{/tr}</th>
								<th>{tr}Size{/tr}</th>
							</tr>
						</thead>
						<tbody>
							{foreach $healthForYouUploads as $upload}
								<tr>
									<td>{$upload.name|escape}</td>
									<td>{$upload.mtime|bit_short_datetime}</td>
									<td>{$upload.size|@number_format} {tr}bytes{/tr}</td>
								</tr>
							{/foreach}
						</tbody>
					</table>
				{/if}
			{/jstab}

			{jstab title="Samsung Health"}
				{if $samsungLast}<p>{tr}Last Download{/tr}: {$samsungLast|bit_short_date}</p>{/if}

				{if $samsungRows}
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
			{/jstab}
		{/jstabs}
	</section>
</div>
{/strip}
<script>
// Same convention as report_range.tpl's own copy — changing From bumps To to 6 days later.
function healthReportBumpTo( pFromVal ) {
	if ( !pFromVal ) return;
	var d = new Date( pFromVal + 'T00:00:00' );
	d.setDate( d.getDate() + 6 );
	document.getElementById( 'report_to' ).value = d.toISOString().slice( 0, 10 );
}
</script>
