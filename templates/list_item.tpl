{strip}
<div class="display health">
	<div class="header">
		<h1>{$page_title|default:"Health Raw Data"|escape}</h1>
	</div>
	<div class="body">
		<form method="get" action="list_item.php">
			<p>
				{foreach $items as $i}
					<label style="margin-right:1em;">
						<input type="radio" name="item" value="{$i.item|escape}"
							{if $i.item eq $selectedItem}checked="checked"{/if}
							onchange="this.form.submit()" />
						{$i.cross_ref_title|escape}
					</label>
				{/foreach}
			</p>
			<noscript><p><input type="submit" value="{tr}Show{/tr}" /></p></noscript>

			<div class="bitnav">
				<ul class="pagination">
					<li><a href="{$smarty.const.HEALTH_PKG_URL}index.php"><span class="bitnav-arrow">&laquo;</span> {tr}Back{/tr}</a></li>
				</ul>

				<ul class="pagination">
					<li class="bitnav-picker">
						<label for="from">{tr}From{/tr}</label>
						<input type="date" name="from" id="from" value="{$from|escape}" onchange="healthListItemBumpTo(this.value)" />
						<label for="to">{tr}To{/tr}</label>
						<input type="date" name="to" id="to" value="{$to|escape}" />
					</li>
					<li class="bitnav-gap"><button type="submit">{tr}Filter{/tr}</button></li>
				</ul>
			</div>
		</form>

		{if $selectedItem}
			{if $rows}
				<table class="table table-condensed">
					<thead>
						<tr>
							<th>{tr}Day{/tr}</th>
							<th>{tr}Time{/tr}</th>
							<th>{$xkeyTitle|escape}</th>
							<th>{$xkeyExtTitle|escape}</th>
							<th>{$dataTitle|escape}</th>
						</tr>
					</thead>
					<tbody>
						{foreach $rows as $r}
						<tr>
							<td>{$r.day_title|escape}</td>
							<td>{$r.time|escape}</td>
							<td>{$r.xkey|escape}</td>
							<td>{$r.xkey_ext|escape}</td>
							<td>
								{if $r.data_summary}
									<details><summary>{$r.data_summary|escape}</summary><pre>{$r.data|escape}</pre></details>
								{else}
									{$r.data|escape}
								{/if}
							</td>
						</tr>
						{/foreach}
					</tbody>
				</table>
				{pagination}
			{else}
				<p>{tr}No rows for this item.{/tr}</p>
			{/if}
		{/if}
	</div>
</div>
{/strip}
<script>
// Same convention as report_range.tpl's healthReportBumpTo() - just a 31-day span
// here rather than 7, since this is a raw-data browse window not a clinical report.
function healthListItemBumpTo( pFromVal ) {
	if ( !pFromVal ) return;
	var d = new Date( pFromVal + 'T00:00:00' );
	d.setDate( d.getDate() + 31 );
	document.getElementById( 'to' ).value = d.toISOString().slice( 0, 10 );
}
</script>
