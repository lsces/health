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
				</p>

				<div class="bitnav">
					<ul class="pagination">
						<li class="bitnav-picker">
							<form method="get" action="{$smarty.const.HEALTH_PKG_URL}index.php" id="healthGoToDayForm">
								<label for="date">{tr}Go to day{/tr}</label>
								<input type="date" name="date" id="date" value="{$dateNotFound|escape}" onchange="healthUpdateCalendarLink(this.value)" />
							</form>
						</li>
						<li class="bitnav-gap"><button type="submit" form="healthGoToDayForm">{tr}Day{/tr}</button></li>
						<li class="bitnav-gap"><a id="healthCalendarLink" href="{$smarty.const.CALENDAR_PKG_URL}package_page.php?pkg=health{if $dateNotFound}&amp;todate={$dateNotFound|escape}{/if}">{tr}Calendar{/tr}</a></li>
					</ul>

					<ul class="pagination">
						<li><a href="{$smarty.const.HEALTH_PKG_URL}list_item.php">{tr}Raw Data{/tr}</a></li>
					</ul>
				</div>

				{if $dateNotFound}
					<p><span class="label label-warning">{tr}No data recorded for{/tr} {$dateNotFound|escape}.</span></p>
				{/if}

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
				{if $healthForYouLast}<p>{tr}Last Download{/tr}: {$healthForYouLast|bit_short_date}</p>{/if}

				{if $healthForYouRows}
					<div class="floaticon">
						<a title="{tr}Upload Export{/tr}" href="{$smarty.const.HEALTH_PKG_URL}import/load_healthforyou.php">{biticon ipackage="icons" iname="insert-table" iexplain="Upload Export"}</a>
					</div>
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
					<div class="floaticon">
						<a title="{tr}Upload Export{/tr}" href="{$smarty.const.HEALTH_PKG_URL}import/load_samsung.php">{biticon ipackage="icons" iname="insert-table" iexplain="Upload Export"}</a>
					</div>
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

				{if $samsungUploads}
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
							{foreach $samsungUploads as $upload}
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

// Keeps the Calendar link jumping to whatever date is currently picked, rather than
// always landing on today.
var healthCalendarBaseUrl = '{$smarty.const.CALENDAR_PKG_URL}package_page.php?pkg=health';
function healthUpdateCalendarLink( pDateVal ) {
	if ( !pDateVal ) return;
	document.getElementById( 'healthCalendarLink' ).href = healthCalendarBaseUrl + '&todate=' + pDateVal;
}
</script>
