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
				<label style="margin-left:1em;">
					<input type="checkbox" name="history" value="y"
						{if $historyRequested}checked="checked"{/if}
						onchange="this.form.submit()" />
					{tr}Show history{/tr}
				</label>
				{if $editRequested}<input type="hidden" name="edit" value="y" />{/if}
			</p>
			<noscript><p><input type="submit" value="{tr}Show{/tr}" /></p></noscript>

			<div class="bitnav bitnav-3col">
				<ul class="pagination">
					<li><a href="{$smarty.const.HEALTH_PKG_URL}index.php"><span class="bitnav-arrow">&laquo;</span> {tr}Back{/tr}</a></li>
				</ul>

				{include file="bitpackage:kernel/bitnav_range_inc.tpl" buttonLabel="Update" bumpDays=31 ownForm=false}
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
							{if $editMode}<th>{tr}Action{/tr}</th>{/if}
						</tr>
					</thead>
					<tbody>
						{foreach $rows as $r}
						<tr{if $r.is_history} style="background-color:#ffe0e0;"{/if}>
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
							{if $editMode}
								<td>
									{if !$r.is_history}
										<form method="post" action="list_item.php" style="display:inline;"
											onsubmit="return confirm('{tr}Archive this row?{/tr}');">
											<input type="hidden" name="farchive" value="1" />
											<input type="hidden" name="xref_id" value="{$r.xref_id|escape}" />
											<input type="hidden" name="content_id" value="{$r.content_id|escape}" />
											<input type="hidden" name="item" value="{$selectedItem|escape}" />
											<input type="hidden" name="from" value="{$from|escape}" />
											<input type="hidden" name="to" value="{$to|escape}" />
											{if $editRequested}<input type="hidden" name="edit" value="y" />{/if}
											{if $historyRequested}<input type="hidden" name="history" value="y" />{/if}
											<button type="submit">{tr}Archive{/tr}</button>
										</form>
									{/if}
								</td>
							{/if}
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
